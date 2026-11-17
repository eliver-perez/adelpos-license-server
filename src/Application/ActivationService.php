<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{ActivationCode, ApiProblem, ApiResponse, Protocol, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Crypto, Database, RateLimiter};

final class ActivationService
{
	public function __construct(
		private Database $db,
		private Clock $clock,
		private Crypto $crypto,
		private Audit $audit,
		private RevisionPublisher $publisher,
		private LicenseActivator $activator,
		private RateLimiter $rate,
		private Transfers $transfers,
		private Alerts $alerts,
	) {}

	public function challenge(array $input): ApiResponse
	{
		$input = Protocol::input('challenge', $input);
		$id = Uuid::create();
		$nonce = Crypto::token();
		$expires = $this->clock->now()->modify('+5 minutes')->format('Y-m-d H:i:s.u');
		$this->db->execute(
			'INSERT INTO activation_challenges(challenge_id,action,product_id,server_id,activation_id,
					nonce,created_at,expires_at)
				VALUES (?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes($id),
				$input['action'],
				$input['product_id'],
				Uuid::bytes($input['server_id']),
				$input['activation_id'] === null ? null : Uuid::bytes($input['activation_id']),
				$nonce,
				$this->clock->sql(),
				$expires,
			],
		);
		return ApiResponse::data(['challenge_id' => $id, 'nonce' => $nonce, 'expires_at' => Protocol::utc($expires)]);
	}

	public function execute(string $action, array $input): ApiResponse
	{
		$input = Protocol::input($action, $input);
		// Activar y transferir presentan el código comercial: comparten el límite de intentos por código.
		$claims = in_array($action, Protocol::CLAIMS, true);
		if ($claims) {
			$normalized = ActivationCode::normalize($input['license_key']);
			if ($normalized !== null) {
				$input['license_key'] = $normalized;
			}
			$this->rate->consume('api-code', $input['product_id'] . ':' . $input['license_key'], 10, 900);
		}
		$requestBytes = Uuid::bytes($input['request_id']);
		$digest = $this->crypto->digest(
			'OPERATION_KEY',
			'protocol-v1:online:' . $action . "\n" . Protocol::json($input),
		);
		$saved = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=?', [
			$input['product_id'],
			$requestBytes,
		]);
		if ($saved && $saved['channel'] === 'online' && $saved['response_body'] !== null) {
			$this->authenticateSaved($saved, $input, $action, $digest);
			$this->limitIdentity($input, $saved['public_key']);
			return new ApiResponse((int) $saved['response_status'], $saved['response_body']);
		}
		// Verify possession before reserving idempotency or taking business locks.
		$challenge = $this->db->one('SELECT * FROM activation_challenges WHERE challenge_id=?', [
			Uuid::bytes($input['challenge_id']),
		]);
		$this->matchChallenge($challenge, $input, $action, false);
		$public = $claims
			? Protocol::decode($input['installation_public_key'], 32)
			: $this->identity($input)['installation_public_key'];
		$message = Protocol::proofMessage($action, $input, $challenge['nonce']);
		$this->verify($input['proof'], $message, $public);
		$this->limitIdentity($input, $public);

		return $this->db->transaction(function () use (
			$action,
			$input,
			$requestBytes,
			$digest,
			$public,
			$message,
		): ApiResponse {
			$this->db->execute(
				"INSERT INTO license_requests(product_id,request_id,channel,action,input_digest,
						server_id,activation_id,public_key,proof_message,created_at)
					VALUES (?,?,'online',?,?,?,?,?,?,?)
					ON DUPLICATE KEY UPDATE request_id=request_id",
				[
					$input['product_id'],
					$requestBytes,
					$action,
					$digest,
					Uuid::bytes($input['server_id']),
					isset($input['activation_id']) ? Uuid::bytes($input['activation_id']) : null,
					$public,
					$message,
					$this->clock->sql(),
				],
			);
			$record = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE', [
				$input['product_id'],
				$requestBytes,
			]);
			$this->authenticateSaved($record, $input, $action, $digest);
			if ($record['response_body'] !== null) {
				return new ApiResponse((int) $record['response_status'], $record['response_body']);
			}
			$challenge = $this->db->one('SELECT * FROM activation_challenges WHERE challenge_id=? FOR UPDATE', [
				Uuid::bytes($input['challenge_id']),
			]);
			$this->matchChallenge($challenge, $input, $action, true);
			$this->verify($input['proof'], Protocol::proofMessage($action, $input, $challenge['nonce']), $public);

			$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$input['product_id']]);
			$response = match ($action) {
				'activate' => $this->activate($input),
				'transfer' => $this->transfer($input),
				default => $this->existing($action, $input, $public),
			};
			// Authenticated commercial refusals also consume their challenge and
			// persist their response. Infrastructure/proof failures roll back.
			$this->db->execute(
				'UPDATE activation_challenges SET consumed_at=?,consumed_request_id=? WHERE challenge_id=?',
				[$this->clock->sql(), $requestBytes, Uuid::bytes($input['challenge_id'])],
			);
			$this->db->execute(
				'UPDATE license_requests
					SET response_status=?,response_body=?,completed_at=?
					WHERE product_id=? AND request_id=?',
				[$response->status, $response->body, $this->clock->sql(), $input['product_id'], $requestBytes],
			);
			$this->audit->append(null, 'activation.' . $action, 'request', Uuid::text($requestBytes), [
				'product_id' => $input['product_id'],
				'http_status' => $response->status,
			]);
			return $response;
		}, 2);
	}

	/**
	 * Licencia del código comercial, bloqueada. Un código inexistente, retirado o de otro producto
	 * recibe siempre la misma respuesta (contrato §8.7). Una licencia revocada responde REVOKED, aunque
	 * su código haya quedado retirado al revocarla.
	 */
	private function licenseForCode(array $input): array|ApiResponse
	{
		if (ActivationCode::normalize($input['license_key']) === null) {
			return $this->failure('LICENSE_NOT_FOUND', $input);
		}
		$digest = $this->crypto->digest('CREDENTIAL_KEY', $input['license_key']);
		$candidate = $this->db->one(
			'SELECT c.license_id
				FROM license_credentials c
				JOIN licenses l ON l.license_id=c.license_id
				WHERE c.key_digest=? AND c.digest_key_version=1 AND l.product_id=?',
			[$digest, $input['product_id']],
		);
		if (!$candidate) {
			return $this->failure('LICENSE_NOT_FOUND', $input);
		}
		$license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$candidate['license_id']]);
		$credential = $this->db->one(
			'SELECT state FROM license_credentials WHERE key_digest=? AND digest_key_version=1 AND license_id=?',
			[$digest, $license['license_id']],
		);
		if ($license['commercial_status'] === 'revoked') {
			return $this->failure('REVOKED', $input);
		}
		if (!$credential || $credential['state'] !== 'active') {
			return $this->failure('LICENSE_NOT_FOUND', $input);
		}
		return $license;
	}

	/** La activación vigente de la licencia, bloqueada, o null. Una licencia tiene a lo sumo una. */
	private function activeInstallation(array $license): ?array
	{
		return $this->db->one("SELECT * FROM activations WHERE license_id=? AND state='active' FOR UPDATE", [
			$license['license_id'],
		]);
	}

	/** La misma instalación es la misma identidad con la misma llave (contrato §8.3; propuesta V1.1 n.º 20). */
	private static function sameInstallation(array $active, array $input): bool
	{
		return $active['server_id'] === Uuid::bytes($input['server_id']) &&
			hash_equals($active['installation_public_key'], Protocol::decode($input['installation_public_key'], 32));
	}

	private function activate(array $input): ApiResponse
	{
		$license = $this->licenseForCode($input);
		if ($license instanceof ApiResponse) {
			return $license;
		}
		// Una licencia vale para una sola computadora principal a la vez (contrato, sección 1).
		$active = $this->activeInstallation($license);
		if (!$active) {
			$activation = $this->activator->activate(
				$license,
				Uuid::bytes($input['server_id']),
				Protocol::decode($input['installation_public_key'], 32),
				$input['app_version'],
			);
			return $this->licenseResponse($activation['revision']['jws'], $input);
		}
		// La misma instalación vuelve a recibir su revisión vigente: no se crea otra activación ni cambian las fechas.
		if (self::sameInstallation($active, $input)) {
			$this->recordVersion($active, $input);
			return $this->licenseResponse($this->currentJws($active), $input);
		}
		// Contrato §8.3: la transferencia por autoservicio está disponible si la licencia no está revocada (aquí
		// nunca lo está) y no tiene otra transferencia por autoservicio pendiente de confirmar.
		return $this->failure('ACTIVATION_LIMIT', $input, [
			'transfer_available' => $this->transfers->pending($license['license_id']) === null,
		]);
	}

	/**
	 * Transferencia por autoservicio (contrato §8.4). Las reglas se aplican en este orden:
	 * 1. código inexistente, retirado o de otro producto: LICENSE_NOT_FOUND;
	 * 2. licencia revocada: REVOKED;
	 * 3. la misma instalación ya está activa: su revisión vigente, como al volver a activar;
	 * 4. sin activación vigente: una activación normal, que no cuenta como transferencia (propuesta
	 *    V1.1 n.º 33);
	 * 5. con una transferencia por autoservicio pendiente: TRANSFER_LIMIT y la alerta del panel;
	 * 6. si no, la transferencia, en esta misma transacción.
	 * Transferir nunca cambia las fechas de la licencia (decisión 5 del propietario para la etapa 4).
	 */
	private function transfer(array $input): ApiResponse
	{
		$license = $this->licenseForCode($input);
		if ($license instanceof ApiResponse) {
			return $license;
		}
		$serverId = Uuid::bytes($input['server_id']);
		$publicKey = Protocol::decode($input['installation_public_key'], 32);
		$active = $this->activeInstallation($license);
		if ($active && self::sameInstallation($active, $input)) {
			$this->recordVersion($active, $input);
			return $this->licenseResponse($this->currentJws($active), $input);
		}
		if (!$active) {
			$activation = $this->activator->activate($license, $serverId, $publicKey, $input['app_version']);
			return $this->licenseResponse($activation['revision']['jws'], $input);
		}
		$pending = $this->transfers->pending($license['license_id']);
		if ($pending !== null) {
			// La negativa se guarda como respuesta, igual que las demás negativas comerciales, y consume el desafío.
			$alert = $this->alerts->transferBlocked($license['license_id'], $pending, [
				'server_id' => $input['server_id'],
				'app_version' => $input['app_version'],
			]);
			$this->audit->append(null, 'license.transfer_blocked', 'license', Uuid::text($license['license_id']), [
				'pending_transfer_id' => Uuid::text($pending['id']),
				'server_id' => $input['server_id'],
				'alert_id' => $alert['alert_id'],
				'alert_opened' => $alert['opened'],
				'attempts' => $alert['attempts'],
			]);
			return $this->failure('TRANSFER_LIMIT', $input);
		}
		$transfer = $this->transfers->selfService($license, $active, $serverId, $publicKey, $input['app_version']);
		return $this->licenseResponse($transfer['revision']['jws'], $input);
	}

	/** «refresh» y «deactivate»: operaciones de una activación que ya existe. */
	private function existing(string $action, array $input, string $public): ApiResponse
	{
		$candidate = $this->identity($input);
		$this->db->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [$candidate['license_id']]);
		$activation = $this->identity($input, true);
		if (!hash_equals($public, $activation['installation_public_key'])) {
			throw new ApiProblem('INVALID_PROOF');
		}
		$this->recordVersion($activation, $input);
		if ($action === 'refresh') {
			// La revisión vigente de la activación. Si ya terminó, es su revisión de cierre.
			return $this->licenseResponse($this->currentJws($activation), $input);
		}
		if ($activation['state'] === 'active') {
			$activation['ended_at'] = $this->clock->sql();
			$this->db->execute("UPDATE activations SET state='deactivated',ended_at=? WHERE activation_id=?", [
				$activation['ended_at'],
				$activation['activation_id'],
			]);
			$jws = $this->publisher->publish($activation['license_id'], $activation['activation_id'])['jws'];
		} else {
			// Ya había terminado, por el motivo que sea: se entrega su revisión de cierre, sin firmar otra.
			$jws = $this->currentJws($activation);
		}
		return ApiResponse::data([
			'activation_id' => Uuid::text($activation['activation_id']),
			'deactivated_at' => Protocol::utc($activation['ended_at']),
			'status' => 'deactivated',
			'license_jws' => $jws,
			'server_time' => Protocol::utc($this->clock->sql()),
			'request_id' => $input['request_id'],
		]);
	}

	/** JWS de la revisión vigente de una activación. */
	private function currentJws(array $activation): string
	{
		$revision = $this->db->one(
			'SELECT license_jws FROM license_revisions WHERE revision_id=? AND activation_id=?',
			[$activation['current_revision_id'], $activation['activation_id']],
		);
		return $revision['license_jws'] ?? throw new \RuntimeException('Missing current revision.');
	}

	/** Guarda la versión de AD-EL POS que hizo la última operación autenticada de la activación. */
	private function recordVersion(array $activation, array $input): void
	{
		if ($activation['app_version'] !== $input['app_version']) {
			$this->db->execute('UPDATE activations SET app_version=? WHERE activation_id=?', [
				$input['app_version'],
				$activation['activation_id'],
			]);
		}
	}

	private function identity(array $input, bool $lock = false): array
	{
		$record = $this->db->one(
			'SELECT * FROM activations WHERE activation_id=? AND product_id=? AND server_id=?' .
				($lock ? ' FOR UPDATE' : ''),
			[Uuid::bytes($input['activation_id']), $input['product_id'], Uuid::bytes($input['server_id'])],
		);
		return $record ?? throw new ApiProblem('INVALID_PROOF');
	}

	private function matchChallenge(?array $challenge, array $input, string $action, bool $checkTime): void
	{
		if (
			!$challenge ||
			$challenge['action'] !== $action ||
			$challenge['product_id'] !== $input['product_id'] ||
			$challenge['server_id'] !== Uuid::bytes($input['server_id']) ||
			$challenge['activation_id'] !==
				(isset($input['activation_id']) ? Uuid::bytes($input['activation_id']) : null)
		) {
			throw new ApiProblem('INVALID_PROOF');
		}
		if ($checkTime && ($challenge['expires_at'] <= $this->clock->sql() || $challenge['consumed_at'] !== null)) {
			throw new ApiProblem('INVALID_PROOF');
		}
	}

	private function authenticateSaved(array $record, array $input, string $action, string $digest): void
	{
		// Cross-channel attempts reach here only after their own online proof was verified.
		if ($record['channel'] !== 'online') {
			throw new ApiProblem('REQUEST_CONFLICT');
		}
		$this->verify($input['proof'], $record['proof_message'], $record['public_key']);
		if (
			$record['channel'] !== 'online' ||
			$record['action'] !== $action ||
			(int) $record['digest_key_version'] !== 1 ||
			!hash_equals($record['input_digest'], $digest)
		) {
			throw new ApiProblem('REQUEST_CONFLICT');
		}
	}

	private function verify(string $proof, string $message, string $public): void
	{
		if (!sodium_crypto_sign_verify_detached(Protocol::decode($proof, 64), $message, $public)) {
			throw new ApiProblem('INVALID_PROOF');
		}
	}
	private function limitIdentity(array $input, string $public): void
	{
		$this->rate->consume('api-identity', $input['product_id'] . ':' . bin2hex($public), 30, 60);
	}
	/** Negativa comercial: se responde y se guarda, a diferencia de los errores que revierten la operación. */
	private function failure(string $code, array $input, array $details = []): ApiResponse
	{
		return ApiResponse::error(new ApiProblem($code, null, $details), $input['request_id']);
	}
	private function licenseResponse(string $jws, array $input): ApiResponse
	{
		return ApiResponse::data([
			'license_jws' => $jws,
			'server_time' => Protocol::utc($this->clock->sql()),
			'request_id' => $input['request_id'],
		]);
	}
}
