<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Credit, Input, LicenseTerms, Money, Problem, Uuid};
use AdelPos\License\Infrastructure\{Clock, Database};

/**
 * Ciclo comercial de una licencia después de emitirla (etapa 3): renovación de una suscripción,
 * conversión de una prueba, cambio de plan, mantenimiento de una perpetua y edición del periodo
 * inicial de una licencia sin activar.
 *
 * Toda operación:
 * - es idempotente por su identificador de operación;
 * - exige la versión vigente de la licencia (row_version);
 * - registra en una sola transacción el pago, el cambio de la licencia, la revisión firmada y la
 *   auditoría, o no registra nada;
 * - firma una revisión nueva solo si cambió algo del contenido firmado. Las computadoras en línea
 *   la reciben al consultar; las que no tienen internet, con «renew» o con la descarga del .lic.
 *
 * Cada operación tiene una vista previa que calcula lo mismo sin escribir nada: el panel la
 * muestra antes de confirmar, con las fechas nuevas y el monto sugerido.
 */
final class CommercialService
{
	public const ROLES = ['superadmin', 'operator'];

	public function __construct(
		private Database $db,
		private Operations $operations,
		private Clock $clock,
		private RevisionPublisher $publisher,
		private Payments $payments,
		private LicenseHistory $history,
		private ScheduledChanges $scheduled,
		private PaidPeriods $paidPeriods,
	) {}

	/**
	 * Datos de una renovación tal como llegan del formulario: la versión de la licencia, la
	 * duración (vacía conserva la actual), la fecha de pago, el monto y su motivo, la referencia y
	 * una nota opcional para el historial.
	 */
	public static function renewalInput(array $input): array
	{
		$period = Input::text($input, 'billing_period', 16, false);
		if ($period !== '' && !in_array($period, LicenseTerms::BILLING_PERIODS, true)) {
			throw new Problem('Elige la duración de la renovación: mensual, trimestral, semestral o anual.');
		}
		return [
			'version' => (int) ($input['version'] ?? 0),
			'billing_period' => $period,
			'reference' => Input::text($input, 'reference', 190, false),
			'note' => Input::text($input, 'reason', 500, false),
		] + Payments::input($input);
	}

	/**
	 * Renueva una suscripción (contrato §5.3). La licencia conserva sus números (decisión 9): solo
	 * cambian sus fechas y, si se elige otra, su duración. Firma una revisión nueva.
	 */
	public function renew(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$request = self::renewalInput($input);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.renew',
			[$licenseId, $request],
			function () use ($actor, $operationId, $licenseId, $request): array {
				$license = $this->lock($licenseId, $request['version']);
				return $this->applyRenewal($actor, $operationId, $license, $request);
			},
		);
	}

	/** Vista previa de renew(): las fechas nuevas y el monto sugerido, sin escribir nada. */
	public function quoteRenewal(string $licenseId, array $input): array
	{
		return $this->planRenewal($this->read($licenseId), self::renewalInput($input));
	}

	/**
	 * Registra la renovación dentro de una transacción abierta, con la licencia ya bloqueada. La usa
	 * también la aprobación de una solicitud «renew» sin internet, en su misma transacción.
	 */
	public function applyRenewal(Actor $actor, string $operationId, array $license, array $request): array
	{
		// Si ya llegó el instante de un cambio programado y la tarea todavía no corrió, la renovación lo
		// aplica en su misma revisión: no se firma aparte (etapa 3, Fase 7).
		$due = $this->scheduled->applyDue($license, $actor, false);
		$license = $due['license'];
		$plan = $this->planRenewal($license, $request);
		if ($request['amount'] === null) {
			throw new Problem('Escribe el monto cobrado.');
		}
		$dates = $plan['dates'];
		$this->db->execute(
			'UPDATE licenses
				SET billing_period=?,period_starts_at=?,expires_at=?,updates_until=?,anchor_day=?,
					anchor_local_time=?,row_version=row_version+1,updated_at=?
				WHERE license_id=?',
			[
				$plan['billing_period'],
				CalendarPeriod::sql($dates['period_starts_at']),
				CalendarPeriod::sql($dates['expires_at']),
				CalendarPeriod::sql($dates['updates_until']),
				$dates['anchor_day'],
				$dates['anchor_local_time'],
				$this->clock->sql(),
				$license['license_id'],
			],
		);
		$paymentId = $this->payments->record($actor, $operationId, $license['license_id'], [
			'concept' => 'renewal',
			'term' => $plan['billing_period'],
			'plan_id' => $license['plan_id'],
			'paid_at' => $plan['paid_at'],
			'amount' => $request['amount'],
			'catalog' => $plan['catalog'],
			'credit' => 0,
			'reason' => $request['reason'],
			'reference' => $request['reference'],
			'covers' => [$dates['period_starts_at'], $dates['expires_at']],
		]);
		$this->db->execute('UPDATE licenses SET period_payment_id=? WHERE license_id=?', [
			$paymentId,
			$license['license_id'],
		]);
		$signed = $this->publisher->commercialChange($license['license_id'], false);
		$id = Uuid::text($license['license_id']);
		$this->history->record($id, $actor->id, 'license.renewed', $request['note'], $request['reference'], [
			'paid_at' => CalendarPeriod::utc($plan['paid_at']),
			'previous_expires_at' => CalendarPeriod::utc(new \DateTimeImmutable($license['expires_at'], self::utc())),
			'period_starts_at' => CalendarPeriod::utc($dates['period_starts_at']),
			'expires_at' => CalendarPeriod::utc($dates['expires_at']),
			'billing_period' => $plan['billing_period'],
			'in_time' => $dates['in_time'],
			'scheduled_change_applied' => $due['applied'] === null ? null : Uuid::text($due['applied']['change_id']),
			'revisions_signed' => $signed,
		]);
		return [
			'id' => $id,
			'expires_at' => CalendarPeriod::utc($dates['expires_at']),
			'in_time' => $dates['in_time'],
			'revisions_signed' => $signed,
		];
	}

	/**
	 * Lo que una renovación haría con esta licencia: comprueba que se puede renovar, lee la fecha
	 * de pago en la zona del cliente y calcula las fechas nuevas y el precio sugerido.
	 */
	private function planRenewal(array $license, array $request): array
	{
		if ($license['commercial_status'] !== 'issued') {
			throw new Problem('Una licencia revocada no se puede renovar.');
		}
		if ($license['license_type'] === 'perpetual') {
			throw new Problem(
				'Una licencia perpetua no se renueva porque no vence. Sus actualizaciones se extienden con el mantenimiento.',
			);
		}
		if ($license['license_type'] === 'trial') {
			throw new Problem('Una prueba no se renueva: conviértela en una licencia de pago.');
		}
		if ($license['period_starts_at'] === null) {
			throw new Problem(
				'Una suscripción sin activar todavía no se puede renovar: sus fechas empiezan al activarla. ' .
					'Puedes editar su periodo inicial.',
			);
		}
		$zone = $this->zone($license);
		$paidAt = $this->payments->paidAt($request['paid_at'], $zone, $license['license_id']);
		$period = $request['billing_period'] !== '' ? $request['billing_period'] : $license['billing_period'];
		$dates = CalendarPeriod::renewal(
			new \DateTimeImmutable($license['expires_at'], self::utc()),
			(int) $license['grace_days'],
			$paidAt,
			LicenseTerms::BILLING_MONTHS[$period],
			$zone,
			(int) $license['anchor_day'],
			$license['anchor_local_time'],
		);
		// Se sugiere el precio del plan para la duración elegida. Si hay un cambio programado, el periodo
		// renovado ya es del plan nuevo: se sugiere su precio. Una licencia personalizada no tiene precio.
		$pending = $this->db->one(
			"SELECT plan_id,plan_custom FROM license_scheduled_changes WHERE license_id=? AND state='pending'",
			[$license['license_id']],
		);
		$pricedPlan = $pending['plan_id'] ?? $license['plan_id'];
		$pricedCustom = (bool) ($pending['plan_custom'] ?? $license['plan_custom']);
		$catalog = null;
		if (!$pricedCustom) {
			$catalog = LicenseTerms::catalogPrice($this->plan($pricedPlan), $period);
		}
		return [
			'license' => $license,
			'time_zone' => $zone,
			'paid_at' => $paidAt,
			'billing_period' => $period,
			'dates' => $dates,
			'catalog' => $catalog,
			'suggested' => $catalog,
		];
	}

	/**
	 * Convierte una prueba en licencia de pago (contrato §5.4 y decisión 5 del propietario): a una
	 * suscripción con cualquier periodo o a una perpetua con 0, 6 o 12 meses de actualizaciones, con
	 * cualquier plan o cupos personalizados. Se puede convertir durante la prueba o después de vencida,
	 * sin bonificación.
	 *
	 * Si la prueba ya está activada, el periodo nuevo empieza en la fecha de pago y los días de prueba
	 * restantes no se acumulan; se firma una revisión nueva para la misma activación, sin reinstalar.
	 * Si no está activada, solo cambian sus condiciones: las fechas se fijan al activarla.
	 */
	public function convert(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$request = self::conversionInput($input);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.convert',
			[$licenseId, $request],
			function () use ($actor, $operationId, $licenseId, $request): array {
				$license = $this->lock($licenseId, $request['version'], $request['target']['plan_id'], $plan);
				$conversion = $this->planConversion($license, $plan, $request);
				if ($request['amount'] === null) {
					throw new Problem('Escribe el monto cobrado.');
				}
				$terms = $request['terms'];
				$target = $conversion['target'];
				$period = $conversion['period'];
				$this->db->execute(
					'UPDATE licenses
						SET license_type=?,billing_period=?,trial_duration=NULL,updates_months=?,grace_days=?,
							plan_id=?,plan_name=?,plan_custom=?,cash_registers=?,order_takers=?,kitchen_displays=?,
							period_starts_at=?,expires_at=?,updates_until=?,anchor_day=?,anchor_local_time=?,
							row_version=row_version+1,updated_at=?
						WHERE license_id=?',
					[
						$terms['license_type'],
						$terms['billing_period'],
						$terms['updates_months'],
						LicenseTerms::graceDays($terms['license_type']),
						$target['plan']['plan_id'],
						$target['plan']['display_name'],
						$target['custom'] ? 1 : 0,
						$target['limits']['cash_registers'],
						$target['limits']['order_takers'],
						$target['limits']['kitchen_displays'],
						self::sqlOrNull($period['period_starts_at'] ?? null),
						self::sqlOrNull($period['expires_at'] ?? null),
						self::sqlOrNull($period['updates_until'] ?? null),
						$period['anchor_day'] ?? null,
						$period['anchor_local_time'] ?? null,
						$this->clock->sql(),
						$license['license_id'],
					],
				);
				$covers = null;
				if (($period['expires_at'] ?? null) !== null) {
					$covers = [$period['period_starts_at'], $period['expires_at']];
				}
				$paymentId = $this->payments->record($actor, $operationId, $license['license_id'], [
					'concept' => 'conversion',
					'term' => LicenseTerms::term($terms),
					'plan_id' => $target['plan']['plan_id'],
					'paid_at' => $conversion['paid_at'],
					'amount' => $request['amount'],
					'catalog' => $conversion['catalog'],
					'credit' => 0,
					'reason' => $request['reason'],
					'reference' => $request['reference'],
					'covers' => $covers,
				]);
				$this->db->execute('UPDATE licenses SET period_payment_id=? WHERE license_id=?', [
					$paymentId,
					$license['license_id'],
				]);
				$signed = $this->publisher->commercialChange($license['license_id'], false);
				$id = Uuid::text($license['license_id']);
				$this->history->record($id, $actor->id, 'license.converted', $request['note'], $request['reference'], [
					'paid_at' => CalendarPeriod::utc($conversion['paid_at']),
					'license_type' => $terms['license_type'],
					'billing_period' => $terms['billing_period'],
					'updates_months' => $terms['updates_months'],
					'plan_id' => $target['plan']['plan_id'],
					'custom' => $target['custom'],
					'activated' => $period !== [],
					'revisions_signed' => $signed,
				]);
				return ['id' => $id, 'revisions_signed' => $signed];
			},
		);
	}

	/** Vista previa de convert(): condiciones, fechas si ya está activada y monto sugerido. */
	public function quoteConversion(string $licenseId, array $input): array
	{
		$request = self::conversionInput($input);
		$plan =
			$this->db->one('SELECT * FROM plans WHERE plan_id=?', [$request['target']['plan_id']]) ??
			throw new Problem('El plan elegido no existe en el catálogo.');
		return $this->planConversion($this->read($licenseId), $plan, $request);
	}

	/** Lo que haría una conversión: la prueba, su destino, el periodo nuevo y el precio sugerido. */
	private function planConversion(array $license, array $plan, array $request): array
	{
		if ($license['commercial_status'] !== 'issued') {
			throw new Problem('Una licencia revocada no se puede convertir.');
		}
		if ($license['license_type'] !== 'trial') {
			throw new Problem('Solo una prueba se convierte en licencia de pago. Esta licencia ya es de pago.');
		}
		$target = $this->target($request['target'], $plan);
		$zone = $this->zone($license);
		$paidAt = $this->payments->paidAt($request['paid_at'], $zone, $license['license_id']);
		$period = [];
		if ($license['period_starts_at'] !== null) {
			// La conversión se paga durante la prueba o después: nunca antes de que empezara.
			if (CalendarPeriod::sql($paidAt) < $license['period_starts_at']) {
				throw new Problem('La fecha de pago es anterior al inicio de la prueba.');
			}
			// El periodo nuevo empieza en la fecha de pago, con su día y su hora como ancla (§5.4).
			$period = CalendarPeriod::firstPeriod($request['terms'], $paidAt, $zone);
		}
		// Una conversión no tiene bonificación: lo sugerido es el precio del catálogo. Una licencia
		// personalizada no tiene precio.
		$catalog = $target['custom'] ? null : LicenseTerms::catalogPrice($plan, LicenseTerms::term($request['terms']));
		return [
			'license' => $license,
			'time_zone' => $zone,
			'paid_at' => $paidAt,
			'terms' => $request['terms'],
			'target' => $target,
			'period' => $period,
			'catalog' => $catalog,
			'suggested' => $catalog,
		];
	}

	/** Datos de un cambio de plan: destino, duración del periodo nuevo, fecha de pago, monto y notas. */
	public static function planChangeInput(array $input): array
	{
		$period = Input::text($input, 'billing_period', 16, false);
		if ($period !== '' && !in_array($period, LicenseTerms::BILLING_PERIODS, true)) {
			throw new Problem('Elige la duración del periodo: mensual, trimestral, semestral o anual.');
		}
		return [
			'version' => (int) ($input['version'] ?? 0),
			'target' => self::targetInput($input),
			'billing_period' => $period,
			'reference' => Input::text($input, 'reference', 190, false),
			'note' => Input::text($input, 'reason', 500, false),
		] + Payments::input($input);
	}

	/**
	 * Cambia el plan o los cupos de una licencia activada (decisiones 6, 7 y 8 del propietario). El
	 * cambio se compara cupo por cupo con los números vigentes:
	 * - prueba: cambio libre, inmediato y sin cobro; sus fechas no cambian;
	 * - perpetua: solo sube, pagando la diferencia de los precios perpetuos; aplica de inmediato;
	 * - suscripción vigente con algún aumento: periodo nuevo desde la fecha de pago, con bonificación
	 *   por lo no consumido de todos los periodos pagados que todavía no terminan (PaidPeriods); los
	 *   aumentos aplican de inmediato y las reducciones se programan para el vencimiento anterior;
	 * - suscripción vigente sin aumentos: el cambio se programa para el vencimiento, sin cobro;
	 * - suscripción pagada después del vencimiento: es una renovación con plan nuevo, sin bonificación.
	 */
	public function changePlan(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$request = self::planChangeInput($input);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.change_plan',
			[$licenseId, $request],
			function () use ($actor, $operationId, $licenseId, $request): array {
				$license = $this->lock($licenseId, $request['version'], $request['target']['plan_id'], $plan);
				// Un cambio programado que ya llegó a su instante se aplica primero: el cambio nuevo se
				// compara con los números vigentes.
				$license = $this->scheduled->applyDue($license, $actor)['license'];
				$change = $this->planPlanChange($license, $plan, $request, $this->clock->now());
				return $this->applyPlanChange($actor, $operationId, $change, $request);
			},
		);
	}

	/**
	 * Vista previa de changePlan(): la clase de cambio, la bonificación, el monto sugerido y lo que se
	 * programa. Si hay un cambio programado que ya llegó a su instante, lo da por aplicado.
	 */
	public function quotePlanChange(string $licenseId, array $input): array
	{
		$request = self::planChangeInput($input);
		$plan =
			$this->db->one('SELECT * FROM plans WHERE plan_id=?', [$request['target']['plan_id']]) ??
			throw new Problem('El plan elegido no existe en el catálogo.');
		$license = $this->read($licenseId);
		$due = $this->db->one(
			"SELECT * FROM license_scheduled_changes WHERE license_id=? AND state='pending' AND effective_at<=?",
			[$license['license_id'], $this->clock->sql()],
		);
		if ($due !== null) {
			$license = array_replace($license, [
				'plan_id' => $due['plan_id'],
				'plan_custom' => $due['plan_custom'],
				'cash_registers' => $due['cash_registers'],
				'order_takers' => $due['order_takers'],
				'kitchen_displays' => $due['kitchen_displays'],
			]);
		}
		return $this->planPlanChange($license, $plan, $request, $this->clock->now());
	}

	/** Cancela el cambio programado de una licencia. Queda en la auditoría y en el historial. */
	public function cancelScheduledChange(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$version = (int) ($input['version'] ?? 0);
		$note = Input::text($input, 'reason', 500, false);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.cancel_scheduled_change',
			[$licenseId, $version, $note],
			function () use ($actor, $licenseId, $version, $note): array {
				$license = $this->lock($licenseId, $version);
				$cancelled =
					$this->scheduled->resolve($license['license_id'], 'cancelled', $actor) ??
					throw new Problem('La licencia no tiene un cambio programado.', 409);
				$this->db->execute('UPDATE licenses SET row_version=row_version+1,updated_at=? WHERE license_id=?', [
					$this->clock->sql(),
					$license['license_id'],
				]);
				$this->history->record($licenseId, $actor->id, 'license.scheduled_change_cancelled', $note, '', [
					'change_id' => Uuid::text($cancelled['change_id']),
					'plan_id' => $cancelled['plan_id'],
					'effective_at' => CalendarPeriod::utc(
						new \DateTimeImmutable($cancelled['effective_at'], self::utc()),
					),
				]);
				return ['id' => $licenseId];
			},
		);
	}

	/** Lo que haría un cambio de plan con esta licencia. No escribe nada. */
	private function planPlanChange(array $license, array $plan, array $request, \DateTimeImmutable $now): array
	{
		if ($license['commercial_status'] !== 'issued') {
			throw new Problem('Una licencia revocada no puede cambiar de plan.');
		}
		$type = $license['license_type'];
		if ($type !== 'trial' && $license['period_starts_at'] === null) {
			throw new Problem(
				'Una licencia sin activar no tiene periodo pagado: cambia su plan editando su periodo inicial.',
			);
		}
		$target = $this->target($request['target'], $plan);
		$up = [];
		$down = [];
		foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
			$current = (int) $license[$limit];
			if ($target['limits'][$limit] > $current) {
				$up[] = $limit;
			} elseif ($target['limits'][$limit] < $current) {
				$down[] = $limit;
			}
		}
		$samePlan = $plan['plan_id'] === $license['plan_id'] && $target['custom'] === (bool) $license['plan_custom'];
		if ($up === [] && $down === [] && $samePlan) {
			throw new Problem('La licencia ya tiene ese plan y esos cupos.');
		}
		$zone = $this->zone($license);
		$change = [
			'license' => $license,
			'time_zone' => $zone,
			'target' => $target,
			'up' => $up,
			'down' => $down,
			'immediate' => $target['limits'],
			'paid_at' => null,
			'billing_period' => null,
			'period' => null,
			'scheduled_at' => null,
			'catalog' => null,
			'credit' => 0,
			'credit_basis' => null,
			'suggested' => null,
			'charges' => true,
		];
		if ($type === 'trial') {
			// Cambiar de plan durante la prueba es libre, sin cobro, y no cambia sus fechas.
			return ['kind' => 'trial', 'charges' => false] + $change;
		}
		if ($type === 'perpetual') {
			if ($down !== []) {
				throw new Problem(
					$up === []
						? 'Una perpetua no puede bajar de plan ni reducir cupos.'
						: 'Una perpetua solo puede subir de plan: este cambio sube unos cupos y baja otros.',
				);
			}
			if ($up === []) {
				throw new Problem('Una perpetua solo puede subir de plan: este cambio no sube ningún cupo.');
			}
			// Se paga la diferencia entre los dos precios perpetuos. Sin alguno de los dos, o con una
			// licencia personalizada, el monto se escribe a mano.
			$change['paid_at'] = $this->payments->paidAt($request['paid_at'], $zone, $license['license_id']);
			$current = $license['plan_custom']
				? null
				: LicenseTerms::catalogPrice($this->plan($license['plan_id']), 'perpetual');
			$new = $target['custom'] ? null : LicenseTerms::catalogPrice($plan, 'perpetual');
			if ($current !== null && $new !== null) {
				$change['catalog'] = $new;
				$change['credit'] = $current;
				$change['credit_basis'] = ['source' => 'perpetual_price', 'amount' => $current];
				$change['suggested'] = max(0, $new - $current);
			}
			return ['kind' => 'perpetual'] + $change;
		}
		$expiresAt = new \DateTimeImmutable($license['expires_at'], self::utc());
		if ($up === [] && $now < $expiresAt) {
			// Solo reducción (o el mismo número con otro plan): entra en vigor al vencer lo pagado, sin cobro.
			return ['kind' => 'reduction', 'charges' => false, 'scheduled_at' => $expiresAt] + $change;
		}
		$paidAt = $this->payments->paidAt($request['paid_at'], $zone, $license['license_id']);
		$period = $request['billing_period'] !== '' ? $request['billing_period'] : $license['billing_period'];
		$change['paid_at'] = $paidAt;
		$change['billing_period'] = $period;
		$catalog = $target['custom'] ? null : LicenseTerms::catalogPrice($plan, $period);
		if ($paidAt >= $expiresAt || $up === []) {
			// Pagó después del vencimiento: es una renovación con plan nuevo. Sin bonificación, sin
			// reducciones programadas, y el periodo nuevo trae los números nuevos.
			$change['period'] = CalendarPeriod::renewal(
				$expiresAt,
				(int) $license['grace_days'],
				$paidAt,
				LicenseTerms::BILLING_MONTHS[$period],
				$zone,
				(int) $license['anchor_day'],
				$license['anchor_local_time'],
			);
			$change['catalog'] = $catalog;
			$change['suggested'] = $catalog;
			return ['kind' => 'renewal'] + $change;
		}
		// Aumento o cambio mixto de una suscripción vigente: el periodo nuevo empieza en la fecha de
		// pago, con su ancla. Los aumentos aplican ya; las reducciones, al vencimiento anterior.
		$change['period'] = CalendarPeriod::firstPeriod(
			['license_type' => 'subscription', 'billing_period' => $period],
			$paidAt,
			$zone,
		);
		foreach ($down as $limit) {
			$change['immediate'][$limit] = (int) $license[$limit];
		}
		if ($down !== []) {
			$change['scheduled_at'] = $expiresAt;
		}
		// Bonificación: lo no consumido de todos los periodos pagados que todavía no terminan, sobre su
		// valor completo (decisión 3 del propietario para la etapa 4).
		$periods = $this->paidPeriods->live($license, $paidAt);
		if ($periods !== null) {
			$credit = Credit::total($periods, $paidAt);
			if ($credit['credit'] > Money::MAX_CENTS) {
				throw new Problem('La bonificación supera el monto máximo que se puede registrar: $99,999,999.99 MXN.');
			}
			$parts = [];
			foreach ($periods as $position => $period) {
				$parts[] = $period + $credit['parts'][$position];
			}
			$change['credit'] = $credit['credit'];
			$change['credit_basis'] = [
				'source' => in_array('catalog', array_column($parts, 'source'), true) ? 'catalog' : 'payment',
				'parts' => $parts,
			];
			if ($catalog !== null) {
				$change['catalog'] = $catalog;
				$change['suggested'] = max(0, $catalog - $credit['credit']);
			}
		}
		return ['kind' => 'increase'] + $change;
	}

	/** Escribe el cambio de plan que calculó planPlanChange(). */
	private function applyPlanChange(Actor $actor, string $operationId, array $change, array $request): array
	{
		$license = $change['license'];
		$target = $change['target'];
		$id = Uuid::text($license['license_id']);
		if ($change['charges'] && $request['amount'] === null) {
			throw new Problem('Escribe el monto cobrado.');
		}
		if (!$change['charges'] && ($request['amount'] !== null || $request['reason'] !== '')) {
			throw new Problem(
				$change['kind'] === 'trial'
					? 'Cambiar de plan durante la prueba no tiene cobro: deja el monto vacío.'
					: 'Una reducción no se cobra al programarla: el cobro llega con la renovación. Deja el monto vacío.',
			);
		}
		$scheduled = null;
		if ($change['kind'] === 'reduction') {
			$scheduled = $this->scheduled->schedule(
				$actor,
				$operationId,
				$license,
				$change['scheduled_at'],
				$target,
				$request['note'],
			);
			$this->db->execute('UPDATE licenses SET row_version=row_version+1,updated_at=? WHERE license_id=?', [
				$this->clock->sql(),
				$license['license_id'],
			]);
			$this->history->record(
				$id,
				$actor->id,
				'license.plan_change_scheduled',
				$request['note'],
				$request['reference'],
				[
					'change_id' => $scheduled['change_id'],
					'replaced' =>
						$scheduled['replaced'] === null ? null : Uuid::text($scheduled['replaced']['change_id']),
					'plan_id' => $target['plan']['plan_id'],
					'limits' => $target['limits'],
					'effective_at' => CalendarPeriod::utc($change['scheduled_at']),
				],
			);
			return [
				'id' => $id,
				'kind' => 'reduction',
				'revisions_signed' => 0,
				'scheduled_at' => CalendarPeriod::utc($change['scheduled_at']),
			];
		}
		// Los cupos de ahora, con el plan nuevo. En un cambio mixto, los que bajan conservan el número
		// mayor hasta el vencimiento anterior.
		$assignments = 'plan_id=?,plan_name=?,plan_custom=?,cash_registers=?,order_takers=?,kitchen_displays=?';
		$parameters = [
			$target['plan']['plan_id'],
			$target['plan']['display_name'],
			$target['custom'] ? 1 : 0,
			$change['immediate']['cash_registers'],
			$change['immediate']['order_takers'],
			$change['immediate']['kitchen_displays'],
		];
		$period = $change['period'];
		if ($period !== null) {
			$assignments .=
				',billing_period=?,period_starts_at=?,expires_at=?,updates_until=?,anchor_day=?,anchor_local_time=?';
			array_push(
				$parameters,
				$change['billing_period'],
				CalendarPeriod::sql($period['period_starts_at']),
				CalendarPeriod::sql($period['expires_at']),
				CalendarPeriod::sql($period['updates_until']),
				$period['anchor_day'],
				$period['anchor_local_time'],
			);
		}
		$this->db->execute(
			'UPDATE licenses SET ' . $assignments . ',row_version=row_version+1,updated_at=? WHERE license_id=?',
			[...$parameters, $this->clock->sql(), $license['license_id']],
		);
		$paymentId = null;
		if ($change['charges']) {
			$paymentId = $this->payments->record($actor, $operationId, $license['license_id'], [
				'concept' => $change['kind'] === 'renewal' ? 'renewal' : 'plan_change',
				'term' => $license['license_type'] === 'perpetual' ? 'perpetual' : $change['billing_period'],
				'plan_id' => $target['plan']['plan_id'],
				'paid_at' => $change['paid_at'],
				'amount' => $request['amount'],
				'catalog' => $change['catalog'],
				'credit' => $change['credit'],
				'reason' => $request['reason'],
				'reference' => $request['reference'],
				'covers' => $period === null ? null : [$period['period_starts_at'], $period['expires_at']],
			]);
		}
		if ($period !== null) {
			// El pago paga el periodo nuevo: con su monto se calculará una bonificación futura.
			$this->db->execute('UPDATE licenses SET period_payment_id=? WHERE license_id=?', [
				$paymentId,
				$license['license_id'],
			]);
		}
		// Las reducciones de un cambio mixto se programan para el vencimiento anterior. Cualquier otro
		// cambio programado queda sustituido por este.
		if ($change['scheduled_at'] !== null) {
			$scheduled = $this->scheduled->schedule(
				$actor,
				$operationId,
				$license,
				$change['scheduled_at'],
				$target,
				$request['note'],
			);
		} else {
			$this->scheduled->resolve($license['license_id'], 'replaced', $actor);
		}
		$signed = $this->publisher->commercialChange($license['license_id'], false);
		$this->history->record(
			$id,
			$actor->id,
			$change['kind'] === 'renewal' ? 'license.renewed' : 'license.plan_changed',
			$request['note'],
			$request['reference'],
			[
				'kind' => $change['kind'],
				'plan_id' => $target['plan']['plan_id'],
				'custom' => $target['custom'],
				'increases' => $change['up'],
				'reductions' => $change['down'],
				'limits' => $change['immediate'],
				'paid_at' => $change['paid_at'] === null ? null : CalendarPeriod::utc($change['paid_at']),
				'credit' => Money::toSql($change['credit']),
				'credit_basis' => $change['credit_basis']['source'] ?? null,
				'credit_parts' => self::creditParts($change['credit_basis']),
				'scheduled_change_id' => $scheduled['change_id'] ?? null,
				'scheduled_at' =>
					$change['scheduled_at'] === null ? null : CalendarPeriod::utc($change['scheduled_at']),
				'revisions_signed' => $signed,
			],
		);
		return [
			'id' => $id,
			'kind' => $change['kind'],
			'revisions_signed' => $signed,
			'scheduled_at' => $change['scheduled_at'] === null ? null : CalendarPeriod::utc($change['scheduled_at']),
		];
	}

	/** Datos de una compra de mantenimiento: la versión, la fecha de pago, el monto y las notas. */
	public static function maintenanceInput(array $input): array
	{
		return [
			'version' => (int) ($input['version'] ?? 0),
			'reference' => Input::text($input, 'reference', 190, false),
			'note' => Input::text($input, 'reason', 500, false),
		] + Payments::input($input);
	}

	/**
	 * Compra de mantenimiento de una perpetua activada (contrato §5.5 y decisión 4 del propietario):
	 * 12 meses más de actualizaciones, desde la fecha posterior entre la fecha de pago y el fin
	 * vigente. Sugiere el precio de mantenimiento anual del plan y firma una revisión nueva.
	 */
	public function buyMaintenance(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$request = self::maintenanceInput($input);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.maintenance',
			[$licenseId, $request],
			function () use ($actor, $operationId, $licenseId, $request): array {
				$license = $this->lock($licenseId, $request['version']);
				$plan = $this->planMaintenance($license, $request);
				if ($request['amount'] === null) {
					throw new Problem('Escribe el monto cobrado.');
				}
				$dates = $plan['dates'];
				$this->db->execute(
					'UPDATE licenses
						SET updates_until=?,anchor_day=?,anchor_local_time=?,row_version=row_version+1,updated_at=?
						WHERE license_id=?',
					[
						CalendarPeriod::sql($dates['updates_until']),
						$dates['anchor_day'],
						$dates['anchor_local_time'],
						$this->clock->sql(),
						$license['license_id'],
					],
				);
				$this->payments->record($actor, $operationId, $license['license_id'], [
					'concept' => 'maintenance',
					'term' => 'maintenance',
					'plan_id' => $license['plan_id'],
					'paid_at' => $plan['paid_at'],
					'amount' => $request['amount'],
					'catalog' => $plan['catalog'],
					'credit' => 0,
					'reason' => $request['reason'],
					'reference' => $request['reference'],
					'covers' => [$dates['updates_from'], $dates['updates_until']],
				]);
				$signed = $this->publisher->commercialChange($license['license_id'], false);
				$id = Uuid::text($license['license_id']);
				$this->history->record(
					$id,
					$actor->id,
					'license.maintenance_bought',
					$request['note'],
					$request['reference'],
					[
						'paid_at' => CalendarPeriod::utc($plan['paid_at']),
						'previous_updates_until' => CalendarPeriod::utc(
							new \DateTimeImmutable($license['updates_until'], self::utc()),
						),
						'updates_until' => CalendarPeriod::utc($dates['updates_until']),
						'from_payment' => $dates['from_payment'],
						'revisions_signed' => $signed,
					],
				);
				return [
					'id' => $id,
					'updates_until' => CalendarPeriod::utc($dates['updates_until']),
					'revisions_signed' => $signed,
				];
			},
		);
	}

	/** Vista previa de buyMaintenance(): el fin nuevo de las actualizaciones y el monto sugerido. */
	public function quoteMaintenance(string $licenseId, array $input): array
	{
		return $this->planMaintenance($this->read($licenseId), self::maintenanceInput($input));
	}

	private function planMaintenance(array $license, array $request): array
	{
		if ($license['commercial_status'] !== 'issued') {
			throw new Problem('Una licencia revocada no puede comprar mantenimiento.');
		}
		if ($license['license_type'] !== 'perpetual') {
			throw new Problem(
				'El mantenimiento es solo de las licencias perpetuas: en una suscripción o una prueba, las ' .
					'actualizaciones terminan con su vencimiento.',
			);
		}
		if ($license['period_starts_at'] === null) {
			throw new Problem(
				'Una perpetua sin activar todavía no necesita mantenimiento: sus actualizaciones cuentan desde la activación.',
			);
		}
		$zone = $this->zone($license);
		$paidAt = $this->payments->paidAt($request['paid_at'], $zone, $license['license_id']);
		$dates = CalendarPeriod::maintenance(
			new \DateTimeImmutable($license['updates_until'], self::utc()),
			$paidAt,
			$zone,
			(int) $license['anchor_day'],
			$license['anchor_local_time'],
		);
		$catalog = null;
		if (!$license['plan_custom']) {
			$catalog = LicenseTerms::catalogPrice($this->plan($license['plan_id']), 'maintenance');
		}
		return [
			'license' => $license,
			'time_zone' => $zone,
			'paid_at' => $paidAt,
			'dates' => $dates,
			'catalog' => $catalog,
			'suggested' => $catalog,
		];
	}

	/** Condiciones nuevas del periodo inicial: tipo con su condición, y plan y cupos. */
	public static function initialTermsInput(array $input): array
	{
		return [
			'version' => (int) ($input['version'] ?? 0),
			'terms' => LicenseTerms::fromInput($input),
			'target' => self::targetInput($input),
			'note' => Input::text($input, 'reason', 500, false),
		];
	}

	/**
	 * Edita el periodo inicial de una licencia sin activar (contrato §5.2 y aclaración n.º 11):
	 * el tipo, el periodo o la duración, los meses de actualizaciones, el plan y los cupos. No hay
	 * fechas que mover ni revisiones que firmar: todo se fija al activarla. No registra un pago.
	 */
	public function editInitialTerms(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$request = self::initialTermsInput($input);
		return $this->operations->run(
			$actor,
			self::ROLES,
			$operationId,
			'license.initial_terms',
			[$licenseId, $request],
			function () use ($actor, $licenseId, $request): array {
				$license = $this->lock($licenseId, $request['version'], $request['target']['plan_id'], $plan);
				if ($license['commercial_status'] !== 'issued') {
					throw new Problem('Una licencia revocada no se puede editar.');
				}
				if ($license['period_starts_at'] !== null) {
					throw new Problem(
						'La licencia ya está activada: su periodo inicial ya empezó. Usa la renovación, la conversión ' .
							'o el cambio de plan.',
					);
				}
				$terms = $request['terms'];
				$target = $this->target($request['target'], $plan);
				$before = [
					'license_type' => $license['license_type'],
					'billing_period' => $license['billing_period'],
					'trial_duration' => $license['trial_duration'],
					'updates_months' => $license['updates_months'] === null ? null : (int) $license['updates_months'],
					'plan_id' => $license['plan_id'],
					'plan_custom' => (bool) $license['plan_custom'],
				];
				$after = $terms + ['plan_id' => $target['plan']['plan_id'], 'plan_custom' => $target['custom']];
				foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
					$before[$limit] = (int) $license[$limit];
					$after[$limit] = $target['limits'][$limit];
				}
				if ($before === $after) {
					throw new Problem('El periodo inicial no cambió.');
				}
				$this->db->execute(
					'UPDATE licenses
						SET license_type=?,billing_period=?,trial_duration=?,updates_months=?,grace_days=?,plan_id=?,
							plan_name=?,plan_custom=?,cash_registers=?,order_takers=?,kitchen_displays=?,
							row_version=row_version+1,updated_at=?
						WHERE license_id=?',
					[
						$terms['license_type'],
						$terms['billing_period'],
						$terms['trial_duration'],
						$terms['updates_months'],
						LicenseTerms::graceDays($terms['license_type']),
						$target['plan']['plan_id'],
						$target['plan']['display_name'],
						$target['custom'] ? 1 : 0,
						$target['limits']['cash_registers'],
						$target['limits']['order_takers'],
						$target['limits']['kitchen_displays'],
						$this->clock->sql(),
						$license['license_id'],
					],
				);
				$changes = [];
				foreach ($after as $field => $value) {
					if ($before[$field] !== $value) {
						$changes[$field] = ['before' => $before[$field], 'after' => $value];
					}
				}
				$this->history->record($licenseId, $actor->id, 'license.initial_terms_edited', $request['note'], '', [
					'changes' => $changes,
				]);
				return ['id' => $licenseId];
			},
		);
	}

	/**
	 * Bloquea la licencia para un cambio comercial: primero su producto, después el plan de destino
	 * si lo hay y por último la licencia, en el mismo orden que la emisión y el editor de planes.
	 * Exige la versión que vio el formulario. Devuelve la licencia; el plan queda en $plan.
	 */
	private function lock(string $licenseId, int $version, ?string $planId = null, ?array &$plan = null): array
	{
		$bytes = Uuid::bytes($licenseId);
		$productId = $this->db->execute('SELECT product_id FROM licenses WHERE license_id=?', [$bytes])->fetchColumn();
		if ($productId === false) {
			throw new Problem('No se encontró la licencia.', 404);
		}
		$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$productId]);
		if ($planId !== null) {
			$plan =
				$this->db->one('SELECT * FROM plans WHERE plan_id=? FOR SHARE', [$planId]) ??
				throw new Problem('El plan elegido no existe en el catálogo.');
		}
		$license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$bytes]);
		if ((int) $license['row_version'] !== $version) {
			throw new Problem('Otro operador cambió la licencia. Recarga su detalle.', 409);
		}
		return $license;
	}

	/** La licencia sin bloquear, para una vista previa. */
	private function read(string $licenseId): array
	{
		return $this->db->one('SELECT * FROM licenses WHERE license_id=?', [Uuid::bytes($licenseId)]) ??
			throw new Problem('No se encontró la licencia.', 404);
	}

	/** Zona horaria del cliente de la licencia: en ella se leen la fecha de pago y se calculan las fechas. */
	private function zone(array $license): string
	{
		$zone = $this->db
			->execute('SELECT time_zone FROM customers WHERE id=?', [$license['customer_id']])
			->fetchColumn();
		if (!is_string($zone)) {
			throw new \RuntimeException('License without customer.');
		}
		return $zone;
	}

	/** Un plan del catálogo, con sus precios. Para sugerir un monto no hace falta bloquearlo. */
	private function plan(string $planId): array
	{
		return $this->db->one('SELECT * FROM plans WHERE plan_id=?', [$planId]) ??
			throw new \RuntimeException('License plan not found.');
	}

	/**
	 * Datos del plan y los cupos de destino: el plan llena los tres cupos, y un número distinto del
	 * plan deja la licencia personalizada. Elegir un plan del catálogo con sus números quita la marca.
	 */
	private static function targetInput(array $input): array
	{
		$planId = Input::text($input, 'plan_id', 64, false);
		if ($planId === '') {
			throw new Problem('Elige el plan de la licencia.');
		}
		$limits = [];
		foreach (LicenseTerms::LIMITS as $key => $label) {
			$limits[$key] =
				($input[$key] ?? '') === '' ? null : Input::integer($input, $key, 0, LicenseTerms::MAX_LIMIT, $label);
		}
		return ['plan_id' => $planId, 'limits' => $limits];
	}

	/** Plan de destino ya bloqueado y los cupos que resultan; dice si la licencia queda personalizada. */
	private function target(array $target, array $plan): array
	{
		if ($plan['state'] !== 'active') {
			throw new Problem('El plan elegido está archivado y ya no se ofrece.');
		}
		$limits = [];
		$custom = false;
		foreach (array_keys(LicenseTerms::LIMITS) as $key) {
			$limits[$key] = $target['limits'][$key] ?? (int) $plan[$key];
			$custom = $custom || $limits[$key] !== (int) $plan[$key];
		}
		return ['plan' => $plan, 'limits' => $limits, 'custom' => $custom];
	}

	/** Condiciones de destino de una conversión: suscripción con su periodo, o perpetua con sus meses. */
	public static function conversionInput(array $input): array
	{
		$type = Input::text($input, 'license_type', 16, false);
		if (!in_array($type, ['subscription', 'perpetual'], true)) {
			throw new Problem('Una prueba se convierte a una suscripción o a una perpetua.');
		}
		$terms = [
			'license_type' => $type,
			'billing_period' => null,
			'trial_duration' => null,
			'updates_months' => null,
		];
		if ($type === 'subscription') {
			$period = Input::text($input, 'billing_period', 16, false);
			if (!in_array($period, LicenseTerms::BILLING_PERIODS, true)) {
				throw new Problem('Elige el periodo de la suscripción: mensual, trimestral, semestral o anual.');
			}
			$terms['billing_period'] = $period;
		} else {
			$months = Input::text($input, 'updates_months', 2, false);
			$months = $months === '' ? (string) LicenseTerms::DEFAULT_UPDATES_MONTHS : $months;
			if (!in_array($months, array_map('strval', LicenseTerms::UPDATES_MONTHS), true)) {
				throw new Problem('Elige los meses de actualizaciones de la licencia perpetua: 0, 6 o 12.');
			}
			$terms['updates_months'] = (int) $months;
		}
		return [
			'version' => (int) ($input['version'] ?? 0),
			'terms' => $terms,
			'target' => self::targetInput($input),
			'reference' => Input::text($input, 'reference', 190, false),
			'note' => Input::text($input, 'reason', 500, false),
		] + Payments::input($input);
	}

	/** Partes de la bonificación para el historial: cada periodo, su valor y lo que aportó. */
	private static function creditParts(?array $basis): array
	{
		$parts = [];
		foreach ($basis['parts'] ?? [] as $part) {
			$parts[] = [
				'payment_id' => $part['payment_id'],
				'source' => $part['source'],
				'value' => Money::toSql($part['value']),
				'starts_at' => CalendarPeriod::utc($part['starts_at']),
				'ends_at' => CalendarPeriod::utc($part['ends_at']),
				'remaining_seconds' => $part['remaining_seconds'],
				'period_seconds' => $part['period_seconds'],
				'credit' => Money::toSql($part['credit']),
			];
		}
		return $parts;
	}

	private static function sqlOrNull(?\DateTimeImmutable $instant): ?string
	{
		return $instant === null ? null : CalendarPeriod::sql($instant);
	}

	private static function utc(): \DateTimeZone
	{
		return new \DateTimeZone('UTC');
	}
}
