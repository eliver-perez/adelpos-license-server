<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{ApiProblem, Input, OfflineRequest, Problem, Protocol, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Crypto, Database};

final class OfflineService
{
	public function __construct(
		private Database $db,
		private Operations $operations,
		private Audit $audit,
		private Clock $clock,
		private Crypto $crypto,
		private RevisionPublisher $publisher,
		private LicenseActivator $activator,
		private CommercialService $commercial,
		private Transfers $transfers,
	) {}

	public function import(Actor $actor, string $operationId, string $envelope): array
	{
		$actor->requireRole(['superadmin', 'operator']);
		$verified = $this->verify($envelope);
		$payload = $verified['payload'];
		$digest = $this->digest($verified);
		return $this->operations->run(
			$actor,
			['superadmin', 'operator'],
			$operationId,
			'offline.import',
			[$digest],
			function () use ($actor, $envelope, $verified, $payload, $digest): array {
				$requestId = Uuid::bytes($payload['request_id']);
				// No proof_message plaintext: the signed segment may contain a credential.
				$this->db->execute(
					"INSERT INTO license_requests(product_id,request_id,channel,action,input_digest,
							server_id,activation_id,public_key,proof_message,created_at)
						VALUES (?,?,'offline',?,?,?,?,?,'',?)
						ON DUPLICATE KEY UPDATE request_id=request_id",
					[
						$payload['product_id'],
						$requestId,
						$payload['action'],
						$digest,
						Uuid::bytes($payload['server_id']),
						isset($payload['activation_id']) ? Uuid::bytes($payload['activation_id']) : null,
						$verified['public'],
						$this->clock->sql(),
					],
				);
				$record = $this->db->one(
					'SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE',
					[$payload['product_id'], $requestId],
				);
				if (
					$record['channel'] !== 'offline' ||
					(int) $record['digest_key_version'] !== 1 ||
					!hash_equals($record['input_digest'], $digest)
				) {
					throw new Problem('El identificador ya pertenece a otra solicitud o canal.', 409);
				}
				$existing = $this->db->one('SELECT id FROM offline_requests WHERE product_id=? AND request_id=?', [
					$payload['product_id'],
					$requestId,
				]);
				if ($existing) {
					return ['id' => Uuid::text($existing['id'])];
				}
				$this->lockProduct($payload['product_id']);
				if ($payload['action'] !== 'activate') {
					// Solo se recibe la solicitud de una instalación que esta licencia conoce.
					$license = $this->lockLicense($payload['license_id'], $payload['product_id'], false);
					$this->boundActivation($payload, $license);
				}
				$id = Uuid::create();
				$encrypted = $this->crypto->encryptEvidence($envelope, 'offline:v1:' . $id);
				$this->db->execute(
					'INSERT INTO offline_requests(id,product_id,request_id,evidence_encrypted,
							projection_json,imported_by,imported_at)
						VALUES (?,?,?,?,?,?,?)',
					[
						Uuid::bytes($id),
						$payload['product_id'],
						$requestId,
						$encrypted,
						Protocol::json($payload),
						Uuid::bytes($actor->id),
						$this->clock->sql(),
					],
				);
				$this->audit->append($actor->id, 'offline.imported', 'offline_request', $id, [
					'product_id' => $payload['product_id'],
					'request_id' => $payload['request_id'],
					'action' => $payload['action'],
				]);
				return ['id' => $id];
			},
		);
	}

	public function decide(Actor $actor, string $operationId, string $id, string $decision, array $input): array
	{
		if (!in_array($decision, ['approved', 'rejected'], true)) {
			throw new Problem('Decisión no válida.');
		}
		$reason = Input::text($input, 'reason', 500);
		$licenseId = $decision === 'approved' ? Input::text($input, 'license_id', 36) : '';
		$version = $decision === 'approved' ? (int) ($input['version'] ?? 0) : 0;
		// Al aprobar un «renew», el propietario puede registrar ahí mismo la renovación con su pago
		// (contrato §5.3). Si no, la solicitud recibe los derechos vigentes, tal como están firmados.
		$renewal = null;
		if ($decision === 'approved' && Input::choice($input, 'renewal_mode', ['current', 'extend']) === 'extend') {
			$renewal = CommercialService::renewalInput(['version' => $version] + $input);
		}
		$outgoing = $decision === 'approved' ? Input::text($input, 'force_activation_id', 36, false) : '';
		$accepted = ($input['accept_offline_limit'] ?? '') === '1';
		$projection =
			$this->db->one('SELECT projection_json FROM offline_requests WHERE id=?', [Uuid::bytes($id)]) ??
			throw new Problem('No se encontró la solicitud.', 404);
		$deactivating =
			$decision === 'approved' &&
			(json_decode($projection['projection_json'], true, 16, JSON_THROW_ON_ERROR)['action'] ?? '') ===
				'deactivate';
		if (($outgoing !== '' || $deactivating) && !$accepted) {
			throw new Problem(
				'Confirma el límite de la revocación sin internet: la firma no demuestra el borrado de copias del equipo anterior.',
			);
		}
		$roles = $outgoing === '' && !$deactivating ? ['superadmin', 'operator'] : ['superadmin'];
		return $this->operations->run(
			$actor,
			$roles,
			$operationId,
			'offline.' . $decision,
			[$id, $reason, $licenseId, $version, $outgoing, $accepted, $renewal],
			function () use (
				$actor,
				$operationId,
				$id,
				$decision,
				$reason,
				$licenseId,
				$version,
				$outgoing,
				$renewal,
			): array {
				$offline =
					$this->db->one('SELECT * FROM offline_requests WHERE id=?', [Uuid::bytes($id)]) ??
					throw new Problem('No se encontró la solicitud.', 404);
				$request = $this->db->one(
					'SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE',
					[$offline['product_id'], $offline['request_id']],
				);
				if ($request['completed_at'] !== null) {
					throw new Problem('La solicitud ya fue decidida. Consulta su resultado.', 409);
				}
				if ($decision === 'rejected') {
					$result = $this->complete($actor, $offline, $decision, $reason, null, null);
					$this->audit->append($actor->id, 'offline.rejected', 'offline_request', $id, [], $reason);
					return $result;
				}
				$plaintext = $this->crypto->decryptEvidence(
					$offline['evidence_encrypted'],
					'offline:v1:' . Uuid::text($offline['id']),
				);
				try {
					$verified = $this->verify($plaintext);
				} finally {
					sodium_memzero($plaintext);
				}
				if (
					(int) $offline['encryption_version'] !== 1 ||
					$request['channel'] !== 'offline' ||
					(int) $request['digest_key_version'] !== 1 ||
					!hash_equals($request['input_digest'], $this->digest($verified))
				) {
					throw new \RuntimeException('Offline evidence does not match reservation.');
				}
				$payload = $verified['payload'];
				$this->lockProduct($payload['product_id']);
				if (
					$payload['action'] !== 'activate' &&
					Uuid::bytes($licenseId) !== Uuid::bytes($payload['license_id'])
				) {
					throw new Problem('La licencia debe coincidir con la solicitud firmada.');
				}
				// Activar exige una licencia vigente. «renew» y «deactivate» también se atienden si la
				// licencia fue revocada: la computadora recibe su revisión de cierre.
				$license = $this->lockLicense($licenseId, $payload['product_id'], $payload['action'] === 'activate');
				if ((int) $license['row_version'] !== $version) {
					throw new Problem('Los derechos cambiaron. Revisa nuevamente la licencia.', 409);
				}
				if ($outgoing !== '' && $payload['action'] !== 'activate') {
					throw new Problem('La transferencia requiere una activación de destino.');
				}
				if ($renewal !== null && $payload['action'] !== 'renew') {
					throw new Problem('Solo una solicitud de renovación puede registrar una renovación.');
				}
				$confirmed = null;
				if ($payload['action'] === 'activate') {
					$active = $this->db->one(
						"SELECT *
							FROM activations
							WHERE license_id=? AND state='active'
							ORDER BY activation_id FOR UPDATE",
						[$license['license_id']],
					);
					// La misma instalación es la misma identidad con la misma llave, igual que en la API en línea.
					$sameInstallation =
						$active &&
						$active['server_id'] === Uuid::bytes($payload['server_id']) &&
						hash_equals($active['installation_public_key'], $verified['public']);
					if ($outgoing !== '') {
						if (!$active || $active['activation_id'] !== Uuid::bytes($outgoing)) {
							throw new Problem('La instalación de origen cambió. Revisa la transferencia.', 409);
						}
						if ($sameInstallation) {
							throw new Problem('El destino debe ser otra instalación.');
						}
						$this->retire($license['license_id'], $active['activation_id'], true);
					} elseif ($active && !$sameInstallation) {
						// Una licencia vale para una sola computadora principal a la vez (contrato, sección 1).
						throw new Problem(
							'La licencia ya tiene una instalación activa. Desactívala o solicita transferencia.',
							409,
						);
					}
					if ($outgoing === '' && $sameInstallation) {
						// Esta computadora ya tiene la licencia: recibe su revisión vigente, sin otra activación ni otras fechas.
						$activationId = $active['activation_id'];
						$this->recordVersion($active, $payload);
						$revision = $this->currentRevision($active);
					} else {
						// El primer periodo de la licencia empieza en este momento, el de la aprobación.
						$activation = $this->activator->activate(
							$license,
							Uuid::bytes($payload['server_id']),
							$verified['public'],
							$payload['app_version'],
						);
						$activationId = $activation['activation_id'];
						$revision = $activation['revision'];
					}
				} else {
					$activation = $this->boundActivation($payload, $license);
					$activationId = $activation['activation_id'];
					$this->recordVersion($activation, $payload);
					// La solicitud firmada demuestra que es la computadora anterior, y recibe su cierre: confirma la
					// transferencia por autoservicio pendiente que salió de ella (aclaración n.º 34 del contrato).
					$confirmed = $this->transfers->confirm($activation, 'offline_' . $payload['action']);
					if ($renewal !== null) {
						// La renovación es de la licencia, pero se entrega a esta computadora: debe seguir activa.
						if ($activation['state'] !== 'active') {
							throw new Problem(
								'La computadora de esta solicitud ya no tiene la licencia. Registra la renovación desde ' .
									'el detalle de la licencia.',
							);
						}
						$this->commercial->applyRenewal($actor, $operationId, $license, $renewal);
						// La renovación firmó una revisión nueva para esta activación: es la que se entrega.
						$activation = $this->boundActivation($payload, $license);
					}
					if ($payload['action'] === 'deactivate' && $activation['state'] === 'active') {
						// Libera la licencia y firma la revisión de cierre «deactivated».
						$revision = $this->retire($license['license_id'], $activationId, false);
					} else {
						// «renew» entrega la revisión vigente, sin firmar otra. Si la activación ya terminó,
						// la revisión vigente es la de cierre, y eso es lo que recibe.
						$revision = $this->currentRevision($activation);
					}
				}
				$ownerTransfer = null;
				if ($outgoing !== '') {
					// No consume la transferencia por autoservicio, pero cuenta para la alerta de 12 meses.
					$ownerTransfer = $this->transfers->recordOwner(
						$actor,
						$license['license_id'],
						Uuid::bytes($outgoing),
						$activationId,
						$reason,
					);
				}
				$result = $this->complete(
					$actor,
					$offline,
					$decision,
					$reason,
					$license['license_id'],
					Uuid::bytes($revision['revision_id']),
				);
				// Audit head is always the last lock; every earlier effect still rolls back on audit failure.
				if ($confirmed !== null) {
					$this->transfers->auditConfirmation($actor->id, $confirmed);
				}
				$this->audit->append(
					$actor->id,
					$outgoing === '' ? 'offline.approved' : 'offline.transferred',
					'offline_request',
					$id,
					[
						'license_id' => Uuid::text($license['license_id']),
						'activation_id' => Uuid::text($activationId),
						'revision' => $revision['revision'],
						'action' => $payload['action'],
						'forced' => $outgoing !== '',
						'frequent_alert' => $ownerTransfer['alert'] ?? null,
					],
					$reason,
				);
				return $result;
			},
		);
	}

	/**
	 * Retira la licencia de una computadora desde el panel, sin computadora de destino.
	 * Quien llama por HTTP ya confirmó su contraseña y su código; aquí se vuelven a comprobar
	 * el rol y el estado vigente.
	 */
	public function release(Actor $actor, string $operationId, string $licenseId, array $input): array
	{
		$reason = Input::text($input, 'reason', 500);
		$activationId = Input::text($input, 'activation_id', 36);
		$version = (int) ($input['version'] ?? 0);
		if (($input['accept_offline_limit'] ?? '') !== '1') {
			throw new Problem('Confirma el límite de la revocación sin internet.');
		}
		return $this->operations->run(
			$actor,
			['superadmin'],
			$operationId,
			'license.force_release',
			[$licenseId, $activationId, $version, $reason, true],
			function () use ($actor, $licenseId, $activationId, $version, $reason): array {
				$candidate =
					$this->db->one('SELECT product_id FROM licenses WHERE license_id=?', [Uuid::bytes($licenseId)]) ??
					throw new Problem('No se encontró la licencia.', 404);
				$this->lockProduct($candidate['product_id']);
				$license = $this->lockLicense($licenseId, $candidate['product_id']);
				if ((int) $license['row_version'] !== $version) {
					throw new Problem('Los derechos cambiaron. Recarga la licencia.', 409);
				}
				$activation = $this->db->one(
					'SELECT state FROM activations WHERE license_id=? AND activation_id=? FOR UPDATE',
					[$license['license_id'], Uuid::bytes($activationId)],
				);
				if (!$activation || $activation['state'] !== 'active') {
					throw new Problem('La instalación ya no está activa.', 409);
				}
				$revision = $this->retire($license['license_id'], Uuid::bytes($activationId), true);
				// No consume la transferencia por autoservicio, pero cuenta para la alerta de 12 meses.
				$ownerTransfer = $this->transfers->recordOwner(
					$actor,
					$license['license_id'],
					Uuid::bytes($activationId),
					null,
					$reason,
				);
				$this->audit->append(
					$actor->id,
					'license.force_release',
					'license',
					$licenseId,
					[
						'activation_id' => $activationId,
						'revision' => $revision['revision'],
						'offline_limit_accepted' => true,
						'frequent_alert' => $ownerTransfer['alert'],
					],
					$reason,
				);
				return ['id' => $licenseId];
			},
		);
	}

	private function lockProduct(string $id): void
	{
		if (!$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$id])) {
			throw new Problem('El producto de la solicitud no está registrado.');
		}
	}

	/** Bloquea la licencia del producto. Para activar debe estar vigente, no revocada. */
	private function lockLicense(string $id, string $product, bool $mustBeIssued = true): array
	{
		$license = $this->db->one('SELECT * FROM licenses WHERE license_id=? AND product_id=? FOR UPDATE', [
			Uuid::bytes($id),
			$product,
		]);
		if (!$license || ($mustBeIssued && $license['commercial_status'] !== 'issued')) {
			throw new Problem('La licencia no está disponible para este producto.');
		}
		return $license;
	}

	/** Revisión vigente de una activación, con la misma forma que devuelve RevisionPublisher::publish(). */
	private function currentRevision(array $activation): array
	{
		$revision = $this->db->one(
			'SELECT revision_id,license_revision,license_jws
				FROM license_revisions
				WHERE revision_id=? AND activation_id=?',
			[$activation['current_revision_id'], $activation['activation_id']],
		);
		if (!$revision) {
			throw new \RuntimeException('Missing current revision.');
		}
		return [
			'revision' => (int) $revision['license_revision'],
			'revision_id' => Uuid::text($revision['revision_id']),
			'jws' => $revision['license_jws'],
		];
	}

	/** Guarda la versión de AD-EL POS que firmó la última solicitud de la activación. */
	private function recordVersion(array $activation, array $payload): void
	{
		if ($activation['app_version'] !== $payload['app_version']) {
			$this->db->execute('UPDATE activations SET app_version=? WHERE activation_id=?', [
				$payload['app_version'],
				$activation['activation_id'],
			]);
		}
	}

	private function boundActivation(array $payload, array $license): array
	{
		$activation = $this->db->one('SELECT * FROM activations WHERE activation_id=? AND license_id=? FOR UPDATE', [
			Uuid::bytes($payload['activation_id']),
			$license['license_id'],
		]);
		// No importa si la activación sigue vigente: una que ya terminó recibe su revisión de cierre.
		if (
			!$activation ||
			$activation['product_id'] !== $payload['product_id'] ||
			$activation['server_id'] !== Uuid::bytes($payload['server_id']) ||
			!hash_equals(
				$activation['installation_public_key'],
				Protocol::decode($payload['installation_public_key'], 32),
			)
		) {
			throw new Problem('La identidad firmada no coincide con una instalación de esta licencia.', 409);
		}
		return $activation;
	}

	/**
	 * Termina una activación y firma su revisión de cierre. «Forzada» es la que el propietario
	 * retira desde el panel, con o sin computadora de destino: se firma como «transferred».
	 */
	private function retire(string $licenseId, string $activationId, bool $forced): array
	{
		$this->db->execute('UPDATE activations SET state=?,ended_at=? WHERE activation_id=?', [
			$forced ? 'transferred' : 'deactivated',
			$this->clock->sql(),
			$activationId,
		]);
		return $this->publisher->publish($licenseId, $activationId);
	}

	private function complete(
		Actor $actor,
		array $offline,
		string $decision,
		string $reason,
		?string $licenseId,
		?string $revisionId,
	): array {
		$result = [
			'id' => Uuid::text($offline['id']),
			'decision' => $decision,
			'license_id' => $licenseId === null ? null : Uuid::text($licenseId),
			'revision_id' => $revisionId === null ? null : Uuid::text($revisionId),
		];
		$this->db->execute(
			'INSERT INTO offline_decisions(offline_id,decision,license_id,revision_id,admin_id,
					reason,decided_at)
				VALUES (?,?,?,?,?,?,?)',
			[$offline['id'], $decision, $licenseId, $revisionId, Uuid::bytes($actor->id), $reason, $this->clock->sql()],
		);
		$this->db->execute(
			'UPDATE license_requests
				SET response_status=200,response_body=?,completed_at=?
				WHERE product_id=? AND request_id=?',
			[Protocol::json($result), $this->clock->sql(), $offline['product_id'], $offline['request_id']],
		);
		return $result;
	}

	private function verify(string $envelope): array
	{
		try {
			return OfflineRequest::verify($envelope);
		} catch (ApiProblem $error) {
			throw new Problem($error->getMessage(), $error->status);
		}
	}
	private function digest(array $verified): string
	{
		return $this->crypto->digest(
			'OPERATION_KEY',
			'protocol-v1:offline:' . $verified['payload']['action'] . "\n" . $verified['digest_material'],
		);
	}
}
