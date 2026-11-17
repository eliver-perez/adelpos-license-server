<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{Input, Problem, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Registro de las transferencias de una licencia (contrato §8.4; etapa 4). Una transferencia lleva la
 * licencia de una activación a otra, o libera la plaza, y queda en «license_transfers» con su vía:
 * - «owner»: la hace el propietario desde el panel, con computadora de destino (aprobando la
 *   solicitud sin internet de la nueva) o para recuperar la plaza de un equipo averiado. Nace
 *   confirmada y nunca consume la transferencia por autoservicio;
 * - «self_service»: la pide AD-EL POS por la API (contrato §8.4) y queda pendiente hasta que la
 *   computadora anterior demuestra con su llave que es ella y recibe su revisión de cierre: en línea
 *   con «refresh» o «deactivate», o sin internet con la aprobación de su «renew» o «deactivate»
 *   (propuesta V1.1 n.º 34). Si nunca va a volver, el superadministrador libera el contador a mano
 *   (propuesta V1.1 n.º 35). Mientras está pendiente, otra transferencia por autoservicio de la
 *   misma licencia recibe TRANSFER_LIMIT.
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
		private Operations $operations,
		private AuthService $auth,
	) {}

	/** Formas en que la computadora anterior confirma, según la operación con la que se autenticó. */
	public const CONFIRMATIONS = ['refresh', 'deactivate', 'offline_renew', 'offline_deactivate'];

	/**
	 * La computadora anterior se autenticó con su llave y recibe su revisión de cierre: si su activación
	 * es el origen de la transferencia por autoservicio pendiente, la confirma y el contador vuelve a
	 * 0. Es idempotente: sin una pendiente que salga de esa activación, no cambia nada. Quien llama ya
	 * bloqueó la licencia y la activación, y audita al final de su transacción.
	 *
	 * @return ?array la transferencia confirmada, o null si no había nada que confirmar
	 */
	public function confirm(array $activation, string $form): ?array
	{
		if (!in_array($form, self::CONFIRMATIONS, true)) {
			throw new \InvalidArgumentException('Unknown confirmation.');
		}
		// Solo una activación que salió por transferencia es el origen de una pendiente.
		$pending = $this->db->one(
			"SELECT * FROM license_transfers WHERE outgoing_activation_id=? AND state='pending' FOR UPDATE",
			[$activation['activation_id']],
		);
		if ($pending === null) {
			return null;
		}
		$this->db->execute(
			"UPDATE license_transfers SET state='confirmed',confirmed_at=?,confirmation=? WHERE id=? AND state='pending'",
			[$this->clock->sql(), $form, $pending['id']],
		);
		return ['confirmation' => $form] + $pending;
	}

	/** Evento de auditoría de una confirmación, para que quien confirmó lo escriba al final de su transacción. */
	public function auditConfirmation(?string $actorId, array $transfer): void
	{
		$this->audit->append($actorId, 'license.transfer_confirmed', 'license', Uuid::text($transfer['license_id']), [
			'transfer_id' => Uuid::text($transfer['id']),
			'confirmation' => $transfer['confirmation'],
			'outgoing_activation_id' => Uuid::text($transfer['outgoing_activation_id']),
		]);
	}

	/**
	 * Liberación manual del contador (propuesta V1.1 n.º 35): para cuando la computadora anterior nunca va a
	 * volver a conectarse, por ejemplo porque se dañó. Solo el superadministrador, con un motivo obligatorio
	 * y confirmando de nuevo su contraseña y un código de autenticación. La transferencia queda confirmada
	 * con la forma «manual», quién la liberó y el motivo, en la auditoría.
	 *
	 * La computadora anterior no se entera: si vuelve a conectarse, recibe su revisión de cierre.
	 */
	public function release(
		Actor $actor,
		string $operationId,
		string $licenseId,
		array $input,
		string $password,
		string $code,
	): array {
		$actor->requireRole(['superadmin']);
		$transferId = Input::text($input, 'transfer_id', 36);
		$reason = Input::text($input, 'reason', 500);
		$this->auth->reauthenticate($actor, $password, $code);
		return $this->operations->run(
			$actor,
			['superadmin'],
			$operationId,
			'license.transfer_release',
			[$licenseId, $transferId, $reason],
			function () use ($actor, $licenseId, $transferId, $reason): array {
				$candidate =
					$this->db->one('SELECT product_id FROM licenses WHERE license_id=?', [Uuid::bytes($licenseId)]) ??
					throw new Problem('No se encontró la licencia.', 404);
				$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [
					$candidate['product_id'],
				]);
				$this->db->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [
					Uuid::bytes($licenseId),
				]);
				$transfer =
					$this->db->one('SELECT * FROM license_transfers WHERE id=? AND license_id=? FOR UPDATE', [
						Uuid::bytes($transferId),
						Uuid::bytes($licenseId),
					]) ?? throw new Problem('No se encontró la transferencia.', 404);
				if ($transfer['state'] !== 'pending') {
					throw new Problem('La transferencia ya está confirmada: no hay nada que liberar.', 409);
				}
				$this->db->execute(
					"UPDATE license_transfers
						SET state='confirmed',confirmed_at=?,confirmation='manual',released_by=?,release_reason=?
						WHERE id=?",
					[$this->clock->sql(), Uuid::bytes($actor->id), $reason, $transfer['id']],
				);
				$this->audit->append(
					$actor->id,
					'license.transfer_released',
					'license',
					$licenseId,
					[
						'transfer_id' => Uuid::text($transfer['id']),
						'outgoing_activation_id' => Uuid::text($transfer['outgoing_activation_id']),
						'incoming_activation_id' => Uuid::text($transfer['incoming_activation_id']),
					],
					$reason,
				);
				return ['id' => $licenseId];
			},
		);
	}

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
