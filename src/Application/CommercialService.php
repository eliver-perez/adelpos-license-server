<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Input, LicenseTerms, Money, Problem, Uuid};
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
		// Se sugiere el precio del plan para la duración elegida. Una licencia personalizada no tiene precio.
		$catalog = null;
		if (!$license['plan_custom']) {
			$catalog = LicenseTerms::catalogPrice($this->plan($license['plan_id']), $period);
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

	private static function sqlOrNull(?\DateTimeImmutable $instant): ?string
	{
		return $instant === null ? null : CalendarPeriod::sql($instant);
	}

	private static function utc(): \DateTimeZone
	{
		return new \DateTimeZone('UTC');
	}
}
