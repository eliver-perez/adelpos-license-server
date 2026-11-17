<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Uuid};
use AdelPos\License\Infrastructure\{Clock, Database};

/**
 * Alta de una instalación en una licencia. Es el mismo paso para la activación en línea y para
 * la aprobación de una solicitud sin internet, así que las dos firman exactamente lo mismo.
 */
final class LicenseActivator
{
	public function __construct(private Database $db, private Clock $clock, private RevisionPublisher $publisher) {}

	/**
	 * Crea la activación, fija el primer periodo de la licencia si todavía no empieza y firma
	 * la primera revisión. Quien llama ya bloqueó el producto y la licencia, y comprobó que
	 * la licencia no tiene otra instalación activa.
	 *
	 * @param array $license fila bloqueada de «licenses»
	 * @param string $serverId identidad de la instalación, en binario
	 * @param string $publicKey llave pública de licencias de la instalación, en binario
	 * @return array{activation_id: string, revision: array{revision: int, revision_id: string, jws: string}}
	 */
	public function activate(array $license, string $serverId, string $publicKey, ?string $appVersion): array
	{
		// Un solo instante para todo: el inicio del periodo es el momento de la activación.
		$now = $this->clock->now();
		$this->startFirstPeriod($license, $now);
		return $this->attach($license, $serverId, $publicKey, $appVersion, $now);
	}

	/**
	 * Crea la activación y firma su primera revisión con las fechas que la licencia ya tiene. Es lo que
	 * hace una transferencia: transferir nunca reinicia, extiende ni modifica las fechas de la licencia,
	 * porque son de la licencia y no de la activación (decisión 5 del propietario para la etapa 4).
	 *
	 * @return array{activation_id: string, revision: array{revision: int, revision_id: string, jws: string}}
	 */
	public function attach(
		array $license,
		string $serverId,
		string $publicKey,
		?string $appVersion,
		?\DateTimeImmutable $now = null,
	): array {
		$now ??= $this->clock->now();
		$activationId = Uuid::bytes(Uuid::create());
		$this->db->execute(
			"INSERT INTO activations(activation_id,license_id,product_id,server_id,
					installation_public_key,app_version,state,activated_at)
				VALUES (?,?,?,?,?,?,'active',?)",
			[
				$activationId,
				$license['license_id'],
				$license['product_id'],
				$serverId,
				$publicKey,
				$appVersion,
				CalendarPeriod::sql($now),
			],
		);
		return [
			'activation_id' => $activationId,
			'revision' => $this->publisher->publish($license['license_id'], $activationId),
		];
	}

	/**
	 * Contrato, sección 5.2: el primer periodo empieza cuando el servidor confirma la primera
	 * activación, o cuando se aprueba la primera solicitud sin internet. Se calcula en la zona
	 * horaria del cliente y se guarda con su día y hora ancla.
	 */
	private function startFirstPeriod(array $license, \DateTimeImmutable $now): void
	{
		if ($license['period_starts_at'] !== null) {
			// Las fechas son de la licencia: al activarse en otra computadora conserva las suyas.
			return;
		}
		$customer = $this->db->one('SELECT time_zone FROM customers WHERE id=?', [$license['customer_id']]);
		if (!$customer) {
			throw new \RuntimeException('License without customer.');
		}
		$period = CalendarPeriod::firstPeriod($license, $now, $customer['time_zone']);
		$this->db->execute(
			'UPDATE licenses
				SET period_starts_at=?,expires_at=?,updates_until=?,anchor_day=?,anchor_local_time=?
				WHERE license_id=?',
			[
				CalendarPeriod::sql($period['period_starts_at']),
				$period['expires_at'] === null ? null : CalendarPeriod::sql($period['expires_at']),
				CalendarPeriod::sql($period['updates_until']),
				$period['anchor_day'],
				$period['anchor_local_time'],
				$license['license_id'],
			],
		);
	}
}
