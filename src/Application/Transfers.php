<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\Uuid;
use AdelPos\License\Infrastructure\{Clock, Database};

/**
 * Registro de las transferencias de una licencia (contrato §8.4; etapa 4). Una transferencia lleva la
 * licencia de una activación a otra, o libera la plaza, y queda en «license_transfers» con su vía:
 * - «owner»: la hace el propietario desde el panel, con computadora de destino (aprobando la
 *   solicitud sin internet de la nueva) o para recuperar la plaza de un equipo averiado. Nace
 *   confirmada y nunca consume la transferencia por autoservicio;
 * - «self_service»: la pide AD-EL POS por la API (Fase 2) y queda pendiente hasta que la computadora
 *   anterior la confirma (Fase 3).
 *
 * Transferir nunca cambia las fechas de la licencia: son de la licencia, no de la activación.
 */
final class Transfers
{
	public function __construct(private Database $db, private Clock $clock) {}

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
