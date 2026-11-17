<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Solicitud sin internet «.licreq» (contrato V1.0, sección 9.1). */
final class OfflineRequest
{
	public const ACTIONS = ['activate', 'renew', 'deactivate'];

	/** Primera línea del mensaje que firma la instalación con su llave de licencias. */
	public const SIGNATURE_PREFIX = "ADELPOS-LICREQ-V1\n";

	/** Campos del contenido que lleva toda solicitud. */
	private const FIELDS = [
		'action',
		'request_id',
		'created_at',
		'product_id',
		'server_id',
		'installation_public_key',
		'app_version',
	];

	/** Instante en UTC terminado en «Z», con 0 a 9 decimales. */
	private const INSTANT = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?Z\z/';

	/**
	 * Comprueba el sobre, el contenido y la firma de una solicitud.
	 *
	 * @return array{payload: array<string, string>, public: string, digest_material: string}
	 *     el contenido sin el código comercial, la llave pública en binario y el texto con
	 *     el que se reconoce un reintento de la misma solicitud
	 */
	public static function verify(string $envelope): array
	{
		$outer = StrictJson::object($envelope, 65536);
		$keys = array_keys($outer);
		sort($keys);
		if ($keys !== ['payload_b64u', 'schema_version', 'signature_b64u']) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		if ($outer['schema_version'] !== '1.0') {
			throw new ApiProblem('INCOMPATIBLE_SCHEMA');
		}
		if (!is_string($outer['payload_b64u']) || !is_string($outer['signature_b64u'])) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		try {
			$bytes = sodium_base642bin($outer['payload_b64u'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		} catch (\Throwable) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		if (Protocol::encode($bytes) !== $outer['payload_b64u']) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		$payload = StrictJson::object($bytes, 49152);
		self::assertContent($payload);
		$public = Protocol::decode($payload['installation_public_key'], 32);
		// Se firma el segmento tal como llegó: no se normaliza nada antes de verificar.
		$signed = sodium_crypto_sign_verify_detached(
			Protocol::decode($outer['signature_b64u'], 64),
			self::SIGNATURE_PREFIX . $outer['payload_b64u'],
			$public,
		);
		if (!$signed) {
			throw new ApiProblem('INVALID_PROOF');
		}
		// El código comercial solo queda en el original cifrado.
		unset($payload['license_key']);
		return [
			'payload' => $payload,
			'public' => $public,
			'digest_material' => Protocol::json([
				'schema_version' => '1.0',
				'payload_b64u' => $outer['payload_b64u'],
				'signature_b64u' => $outer['signature_b64u'],
			]),
		];
	}

	/** Campos exactos de cada acción y formato de cada uno. */
	private static function assertContent(array $payload): void
	{
		$action = $payload['action'] ?? null;
		if (!in_array($action, self::ACTIONS, true)) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		$expected = self::FIELDS;
		if ($action !== 'activate') {
			$expected = [...$expected, 'license_id', 'activation_id'];
		} elseif (array_key_exists('license_key', $payload)) {
			// Opcional, y solo al activar: el propietario puede asignar la licencia sin él.
			$expected[] = 'license_key';
		}
		$received = array_keys($payload);
		sort($received);
		sort($expected);
		if ($received !== $expected) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		foreach ($payload as $value) {
			if (
				!is_string($value) ||
				$value === '' ||
				strlen($value) > 256 ||
				!mb_check_encoding($value, 'UTF-8') ||
				preg_match('/[\x00-\x1f\x7f]/', $value)
			) {
				throw new ApiProblem('INVALID_REQUEST');
			}
		}
		Protocol::uuid($payload['request_id']);
		Protocol::serverId($payload['server_id']);
		if ($action !== 'activate') {
			Protocol::uuid($payload['license_id']);
			Protocol::uuid($payload['activation_id']);
		}
		if (
			!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $payload['product_id']) ||
			strlen($payload['app_version']) > 100 ||
			!self::instant($payload['created_at'])
		) {
			throw new ApiProblem('INVALID_REQUEST');
		}
	}

	/** Valida la fecha sin convertirla y sin confiar en el reloj del equipo que la escribió. */
	private static function instant(string $value): bool
	{
		if (preg_match(self::INSTANT, $value, $date) !== 1) {
			return false;
		}
		return checkdate((int) $date[2], (int) $date[3], (int) $date[1]) &&
			(int) $date[4] <= 23 &&
			(int) $date[5] <= 59 &&
			(int) $date[6] <= 59;
	}
}
