<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\Uuid;
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Historial comercial de una licencia: una instantánea en «license_changes» por cada cambio, con
 * su versión, y el evento de auditoría de la operación. Se escribe dentro de la transacción del
 * cambio, después de actualizar la licencia.
 */
final class LicenseHistory
{
	public function __construct(private Database $db, private Audit $audit, private Clock $clock) {}

	/**
	 * Guarda la instantánea de la licencia y, si $audited, el evento de auditoría $action.
	 * $adminId es quien autorizó el cambio. Con $bySystem, la auditoría lo atribuye al sistema: es
	 * la tarea programada que aplica un cambio que un administrador dejó programado.
	 */
	public function record(
		string $licenseId,
		string $adminId,
		string $action,
		string $reason,
		string $reference,
		array $metadata = [],
		bool $audited = true,
		bool $bySystem = false,
	): void {
		$bytes = Uuid::bytes($licenseId);
		$license = $this->db->one(
			'SELECT product_id,license_type,billing_period,trial_duration,updates_months,commercial_status,
					grace_days,plan_id,plan_name,plan_custom,cash_registers,order_takers,kitchen_displays,
					period_starts_at,expires_at,updates_until,row_version
				FROM licenses
				WHERE license_id=?',
			[$bytes],
		);
		$license['features'] = $this->db->all(
			'SELECT capability_key,enabled FROM license_features WHERE license_id=? ORDER BY capability_key',
			[$bytes],
		);
		$license['commercial_reference'] = $reference;
		$this->db->execute(
			'INSERT INTO license_changes(id,license_id,version,action,admin_id,snapshot_json,
					reason,created_at)
				VALUES (?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes(Uuid::create()),
				$bytes,
				(int) $license['row_version'],
				$action,
				Uuid::bytes($adminId),
				json_encode($license, JSON_THROW_ON_ERROR),
				$reason,
				$this->clock->sql(),
			],
		);
		if ($audited) {
			$this->audit->append(
				$bySystem ? null : $adminId,
				$action,
				'license',
				$licenseId,
				['version' => (int) $license['row_version']] + $metadata,
				$reason,
			);
		}
	}
}
