<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\Uuid;
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Registro de las transferencias de una licencia (contrato §8.4; etapa 4). Una transferencia lleva la
 * licencia de una activación a otra, o libera la plaza, y queda en «license_transfers» con su vía:
 * - «owner»: la hace el propietario desde el panel, con computadora de destino (aprobando la
 *   solicitud sin internet de la nueva) o para recuperar la plaza de un equipo averiado. Nace
 *   confirmada y nunca consume la transferencia por autoservicio;
 * - «self_service»: la pide AD-EL POS por la API (contrato §8.4) y queda pendiente hasta que la
 *   computadora anterior la confirma. Mientras está pendiente, otra transferencia por autoservicio
 *   de la misma licencia recibe TRANSFER_LIMIT.
 *
 * Transferir nunca cambia las fechas de la licencia: son de la licencia, no de la activación.
 */
final class Transfers
{
	public function __construct(
		private Database $db,
		private Clock $clock,
		private Audit $audit,
		private RevisionPublisher $publisher,
		private LicenseActivator $activator,
	) {}

	/** La transferencia por autoservicio pendiente de la licencia, bloqueada, o null. */
	public function pending(string $licenseId): ?array
	{
		return $this->db->one('SELECT * FROM license_transfers WHERE pending_license_id=? FOR UPDATE', [$licenseId]);
	}

	/**
	 * Transferencia por autoservicio (contrato §8.4, regla 3), dentro de la transacción de la petición,
	 * con el producto, la licencia y la activación de origen ya bloqueados y sin otra pendiente:
	 * - la activación anterior pasa a «transferred» y se firma su revisión de cierre revoked/transferred;
	 * - se crea la activación nueva y se firma su revisión «active», con las mismas fechas, cupos, plan
	 *   y módulos de la licencia;
	 * - se registra la transferencia como «self_service», pendiente de confirmar;
	 * - se audita al final, porque la cabeza de la auditoría es el último bloqueo.
	 *
	 * @return array{transfer_id: string, activation_id: string, revision: array{revision: int, revision_id: string, jws: string}}
	 */
	public function selfService(
		array $license,
		array $outgoing,
		string $serverId,
		string $publicKey,
		?string $appVersion,
	): array {
		if ($license['period_starts_at'] === null || $outgoing['state'] !== 'active') {
			throw new \LogicException('Only an activated license with an active installation can be transferred.');
		}
		$this->db->execute("UPDATE activations SET state='transferred',ended_at=? WHERE activation_id=?", [
			$this->clock->sql(),
			$outgoing['activation_id'],
		]);
		$closing = $this->publisher->publish($license['license_id'], $outgoing['activation_id']);
		$incoming = $this->activator->attach($license, $serverId, $publicKey, $appVersion);
		$id = Uuid::create();
		$this->db->execute(
			"INSERT INTO license_transfers(id,license_id,outgoing_activation_id,incoming_activation_id,via,
					state,created_at)
				VALUES (?,?,?,?,'self_service','pending',?)",
			[
				Uuid::bytes($id),
				$license['license_id'],
				$outgoing['activation_id'],
				$incoming['activation_id'],
				$this->clock->sql(),
			],
		);
		$this->audit->append(null, 'license.transferred', 'license', Uuid::text($license['license_id']), [
			'transfer_id' => $id,
			'via' => 'self_service',
			'outgoing_activation_id' => Uuid::text($outgoing['activation_id']),
			'outgoing_server_id' => Uuid::text($outgoing['server_id']),
			'closing_revision' => $closing['revision'],
			'incoming_activation_id' => Uuid::text($incoming['activation_id']),
			'incoming_server_id' => Uuid::text($serverId),
			'revision' => $incoming['revision']['revision'],
		]);
		return ['transfer_id' => $id] + $incoming;
	}

	/**
	 * Registra una transferencia del propietario, ya confirmada. Quien llama ya retiró la activación de
	 * origen y, si hay destino, creó la nueva, dentro de la misma transacción.
	 */
	public function recordOwner(
		Actor $actor,
		string $licenseId,
		string $outgoing,
		?string $incoming,
		string $reason,
	): string {
		$id = Uuid::bytes(Uuid::create());
		$now = $this->clock->sql();
		$this->db->execute(
			"INSERT INTO license_transfers(id,license_id,outgoing_activation_id,incoming_activation_id,via,
					admin_id,reason,offline_limit_accepted,state,confirmed_at,confirmation,created_at)
				VALUES (?,?,?,?,'owner',?,?,1,'confirmed',?,'owner',?)",
			[$id, $licenseId, $outgoing, $incoming, Uuid::bytes($actor->id), $reason, $now, $now],
		);
		return $id;
	}
}
