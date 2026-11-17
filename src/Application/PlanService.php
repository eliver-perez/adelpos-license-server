<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{Input, LicenseTerms, Money, Problem, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Editor del catálogo de planes (etapa 3, Fase 2; contrato §11 y decisión 9 del propietario).
 *
 * Solo el superadministrador crea, edita y archiva planes. Al guardar cupos nuevos elige si el
 * cambio aplica solo a las licencias nuevas o también a las existentes que siguen el plan. Si
 * elige las existentes, solo se aplican los aumentos, cupo por cupo: una reducción del catálogo
 * nunca alcanza a una licencia existente, ni siquiera al renovar (propuesta V1.1 n.º 21).
 *
 * La propagación es de todo o nada: el plan, las licencias, sus revisiones firmadas y la
 * auditoría se confirman en una sola transacción. Si algo falla a la mitad (por ejemplo, la
 * llave de firma no está disponible), no cambia nada y la misma operación se puede reintentar.
 */
final class PlanService
{
	public const STATES = ['active', 'archived'];

	public const APPLY_TO = ['new', 'existing'];

	public function __construct(
		private Database $db,
		private Operations $operations,
		private Audit $audit,
		private Clock $clock,
		private RevisionPublisher $publisher,
	) {}

	/** Crea un plan. No hay licencias que lo sigan, así que no hay nada que propagar. */
	public function create(Actor $actor, string $operationId, array $input): array
	{
		$planId = Input::identifier(Input::text($input, 'plan_id', 64));
		$values = self::values($input);
		return $this->operations->run(
			$actor,
			['superadmin'],
			$operationId,
			'plan.create',
			[$planId, $values],
			function () use ($actor, $planId, $values): array {
				if ($this->db->one('SELECT plan_id FROM plans WHERE plan_id=? FOR UPDATE', [$planId])) {
					throw new Problem('Ya existe un plan con ese identificador.', 409);
				}
				$now = $this->clock->sql();
				$this->db->execute(
					'INSERT INTO plans(plan_id,display_name,cash_registers,order_takers,kitchen_displays,
							price_monthly,price_quarterly,price_semiannual,price_annual,price_perpetual,
							price_maintenance,state,row_version,created_at,updated_at)
						VALUES (?,?,?,?,?,?,?,?,?,?,?,\'active\',1,?,?)',
					[$planId, ...array_values(self::columns($values)), $now, $now],
				);
				$this->audit->append($actor->id, 'plan.created', 'plan', $planId, ['values' => self::audited($values)]);
				return ['id' => $planId, 'licenses_updated' => 0, 'revisions_signed' => 0];
			},
		);
	}

	/**
	 * Edita el nombre, los cupos y los precios de un plan. Con «existing», aplica los aumentos de
	 * cupos a las licencias existentes que siguen el plan y firma una revisión para cada activación
	 * vigente. Devuelve cuántas licencias se actualizaron y cuántas revisiones se firmaron.
	 */
	public function update(Actor $actor, string $operationId, string $planId, array $input): array
	{
		$values = self::values($input);
		$applyTo = Input::choice($input, 'apply_to', self::APPLY_TO);
		$reason = Input::text($input, 'reason', 500, false);
		$version = (int) ($input['version'] ?? 0);
		return $this->operations->run(
			$actor,
			['superadmin'],
			$operationId,
			'plan.update',
			[$planId, $values, $applyTo, $reason, $version],
			function () use ($actor, $planId, $values, $applyTo, $reason, $version): array {
				// El producto primero, como en toda operación que toca licencias; después el plan.
				$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', ['adel_pos']);
				$plan =
					$this->db->one('SELECT * FROM plans WHERE plan_id=? FOR UPDATE', [$planId]) ??
					throw new Problem('No se encontró el plan.', 404);
				if ((int) $plan['row_version'] !== $version) {
					throw new Problem('Otro administrador cambió el plan. Recarga su detalle.', 409);
				}
				$changes = self::changes($plan, $values);
				if ($changes === []) {
					throw new Problem('El plan no cambió.');
				}
				$this->db->execute(
					'UPDATE plans
						SET display_name=?,cash_registers=?,order_takers=?,kitchen_displays=?,price_monthly=?,
							price_quarterly=?,price_semiannual=?,price_annual=?,price_perpetual=?,
							price_maintenance=?,row_version=row_version+1,updated_at=?
						WHERE plan_id=?',
					[...array_values(self::columns($values)), $this->clock->sql(), $planId],
				);
				// Solo los cupos que subieron en el catálogo, con su número nuevo.
				$increases = [];
				foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
					if ($values[$limit] > (int) $plan[$limit]) {
						$increases[$limit] = $values[$limit];
					}
				}
				$result = ['licenses_updated' => 0, 'revisions_signed' => 0];
				if ($applyTo === 'existing' && $increases !== []) {
					$result = $this->propagate($actor, $planId, $values['display_name'], $increases);
				}
				$this->audit->append(
					$actor->id,
					'plan.updated',
					'plan',
					$planId,
					[
						'changes' => $changes,
						'apply_to' => $applyTo,
						'increases' => $increases,
						'version' => $version + 1,
					] + $result,
					$reason,
				);
				return ['id' => $planId] + $result;
			},
		);
	}

	/** Archiva un plan o lo vuelve a ofrecer. Un plan en uso no se borra: se archiva. */
	public function setState(Actor $actor, string $operationId, string $planId, array $input): array
	{
		$state = Input::choice($input, 'state', self::STATES);
		$reason = Input::text($input, 'reason', 500, false);
		$version = (int) ($input['version'] ?? 0);
		return $this->operations->run(
			$actor,
			['superadmin'],
			$operationId,
			'plan.state',
			[$planId, $state, $reason, $version],
			function () use ($actor, $planId, $state, $reason, $version): array {
				$plan =
					$this->db->one('SELECT * FROM plans WHERE plan_id=? FOR UPDATE', [$planId]) ??
					throw new Problem('No se encontró el plan.', 404);
				if ((int) $plan['row_version'] !== $version) {
					throw new Problem('Otro administrador cambió el plan. Recarga su detalle.', 409);
				}
				if ($plan['state'] === $state) {
					throw new Problem($state === 'archived' ? 'El plan ya está archivado.' : 'El plan ya se ofrece.');
				}
				$this->db->execute('UPDATE plans SET state=?,row_version=row_version+1,updated_at=? WHERE plan_id=?', [
					$state,
					$this->clock->sql(),
					$planId,
				]);
				$this->audit->append(
					$actor->id,
					$state === 'archived' ? 'plan.archived' : 'plan.restored',
					'plan',
					$planId,
					['state' => ['before' => $plan['state'], 'after' => $state], 'version' => $version + 1],
					$reason,
				);
				return ['id' => $planId, 'licenses_updated' => 0, 'revisions_signed' => 0];
			},
		);
	}

	/**
	 * Aplica los aumentos a las licencias que siguen el plan: no personalizadas ni revocadas;
	 * perpetuas, licencias sin activar, y suscripciones y pruebas vigentes o en tolerancia.
	 * Una licencia sin activar toma los números nuevos y los firma al activarse.
	 */
	private function propagate(Actor $actor, string $planId, string $planName, array $increases): array
	{
		$now = $this->clock->sql();
		$licenses = $this->db->all(
			"SELECT *
				FROM licenses
				WHERE plan_id=?
					AND plan_custom=0
					AND commercial_status='issued'
					AND (
						license_type='perpetual'
						OR period_starts_at IS NULL
						OR DATE_ADD(expires_at,INTERVAL grace_days*24 HOUR)>?
					)
				ORDER BY license_id
				FOR UPDATE",
			[$planId, $now],
		);
		$updated = 0;
		$signed = 0;
		foreach ($licenses as $license) {
			$assignments = [];
			$parameters = [];
			foreach ($increases as $limit => $count) {
				if ($count > (int) $license[$limit]) {
					$assignments[] = $limit . '=?';
					$parameters[] = $count;
				}
			}
			if ($assignments === []) {
				continue;
			}
			$this->db->execute(
				'UPDATE licenses
					SET ' .
					implode(',', $assignments) .
					',row_version=row_version+1,updated_at=?
					WHERE license_id=?',
				[...$parameters, $now, $license['license_id']],
			);
			++$updated;
			$signed += $this->publisher->commercialChange($license['license_id'], false);
			$this->recordLicense($actor, $license['license_id'], $planId, $planName);
		}
		return ['licenses_updated' => $updated, 'revisions_signed' => $signed];
	}

	private function recordLicense(Actor $actor, string $licenseId, string $planId, string $planName): void
	{
		$license = $this->db->one(
			'SELECT license_type,plan_id,plan_name,plan_custom,cash_registers,order_takers,kitchen_displays,
					period_starts_at,expires_at,updates_until,row_version
				FROM licenses
				WHERE license_id=?',
			[$licenseId],
		);
		$this->db->execute(
			'INSERT INTO license_changes(id,license_id,version,action,admin_id,snapshot_json,reason,created_at)
				VALUES (?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes(Uuid::create()),
				$licenseId,
				(int) $license['row_version'],
				'license.plan_propagated',
				Uuid::bytes($actor->id),
				json_encode($license, JSON_THROW_ON_ERROR),
				'Aumento del catálogo: ' . $planName . ' (' . $planId . ')',
				$this->clock->sql(),
			],
		);
	}

	/** Nombre, cupos y precios del formulario, validados. */
	private static function values(array $input): array
	{
		$values = ['display_name' => Input::text($input, 'display_name', 190)];
		foreach (LicenseTerms::LIMITS as $limit => $label) {
			$values[$limit] = Input::integer($input, $limit, 0, LicenseTerms::MAX_LIMIT, $label);
		}
		foreach (LicenseTerms::PRICES as $term => $column) {
			$values[$column] = Money::optional(
				$input[$column] ?? '',
				'El precio ' . mb_strtolower(LicenseTerms::TERM_LABELS[$term]),
			);
		}
		return $values;
	}

	/** Valores en el orden de las columnas, con los precios como DECIMAL. */
	private static function columns(array $values): array
	{
		$columns = [];
		foreach ($values as $name => $value) {
			$columns[$name] = str_starts_with($name, 'price_') && $value !== null ? Money::toSql($value) : $value;
		}
		return $columns;
	}

	/** Valores para la auditoría: los precios como texto. */
	private static function audited(array $values): array
	{
		return self::columns($values);
	}

	/** Cada campo que cambió, con su valor anterior y el nuevo. */
	private static function changes(array $plan, array $values): array
	{
		$changes = [];
		foreach (self::columns($values) as $name => $after) {
			$before = $plan[$name];
			if (in_array($name, array_keys(LicenseTerms::LIMITS), true)) {
				$before = (int) $before;
			}
			if ($before !== $after) {
				$changes[$name] = ['before' => $before, 'after' => $after];
			}
		}
		return $changes;
	}
}
