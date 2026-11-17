<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

final class Protocol
{
	public static function json(mixed $value): string
	{
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
	public static function encode(string $bytes): string
	{
		return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	}
	public static function decode(string $value, int $size): string
	{
		try {
			if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
				throw new \RuntimeException();
			}
			$bytes = sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
			if (strlen($bytes) !== $size || self::encode($bytes) !== $value) {
				throw new \RuntimeException();
			}
			return $bytes;
		} catch (\Throwable) {
			throw new ApiProblem('INVALID_REQUEST');
		}
	}

	public static function uuid(mixed $value): string
	{
		if (
			!is_string($value) ||
			!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value)
		) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		// Se conservan los bytes recibidos; los UUID se comparan en binario.
		return $value;
	}

	/** Identidad de la instalación: UUID versión 4 y solo en minúsculas (contrato, sección 2). */
	public static function serverId(mixed $value): string
	{
		if (
			!is_string($value) ||
			!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value)
		) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		return $value;
	}

	/** Acciones que admite un desafío (contrato, sección 8.1). */
	public const CHALLENGE_ACTIONS = ['activate', 'transfer', 'refresh', 'deactivate'];

	/** Acciones que presentan el código comercial y la llave de la instalación, sin activación previa. */
	public const CLAIMS = ['activate', 'transfer'];

	/** Campos exactos de cada petición (contrato, secciones 8.1, 8.3, 8.4, 8.5 y 8.6). */
	public const REQUEST_FIELDS = [
		'challenge' => ['action', 'product_id', 'server_id', 'activation_id'],
		'activate' => [
			'request_id',
			'product_id',
			'license_key',
			'server_id',
			'installation_public_key',
			'challenge_id',
			'proof',
			'app_version',
		],
		// Transferir recibe los mismos campos que activar (contrato, sección 8.4).
		'transfer' => [
			'request_id',
			'product_id',
			'license_key',
			'server_id',
			'installation_public_key',
			'challenge_id',
			'proof',
			'app_version',
		],
		'refresh' => ['request_id', 'product_id', 'server_id', 'activation_id', 'challenge_id', 'proof', 'app_version'],
		'deactivate' => [
			'request_id',
			'product_id',
			'server_id',
			'activation_id',
			'challenge_id',
			'proof',
			'app_version',
		],
	];

	/**
	 * Valida una petición de la API: ni campos faltantes ni sobrantes, y cada uno con su formato.
	 * Devuelve los campos ordenados por nombre, que es la forma con la que se compara un reintento.
	 */
	public static function input(string $action, array $input): array
	{
		$expected = self::REQUEST_FIELDS[$action] ?? throw new ApiProblem('INVALID_REQUEST');
		$received = array_keys($input);
		sort($received);
		sort($expected);
		if ($received !== $expected) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		foreach ($input as $field => $value) {
			// El único valor que puede no ser texto: el desafío para activar o transferir no lleva activación.
			if ($action === 'challenge' && $field === 'activation_id' && $value === null) {
				continue;
			}
			if (
				!is_string($value) ||
				$value === '' ||
				strlen($value) > 512 ||
				!mb_check_encoding($value, 'UTF-8') ||
				preg_match('/[\x00-\x1f\x7f]/', $value)
			) {
				throw new ApiProblem('INVALID_REQUEST');
			}
		}
		if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $input['product_id'])) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		self::serverId($input['server_id']);
		if ($action === 'challenge') {
			self::challengeInput($input);
		} else {
			self::operationInput($action, $input);
		}
		ksort($input);
		return $input;
	}

	private static function challengeInput(array $input): void
	{
		if (!in_array($input['action'], self::CHALLENGE_ACTIONS, true)) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		if (!in_array($input['action'], self::CLAIMS, true)) {
			self::uuid($input['activation_id']);
		} elseif ($input['activation_id'] !== null) {
			throw new ApiProblem('INVALID_REQUEST');
		}
	}

	private static function operationInput(string $action, array $input): void
	{
		self::uuid($input['request_id']);
		self::uuid($input['challenge_id']);
		self::decode($input['proof'], 64);
		if (strlen($input['app_version']) > 100) {
			throw new ApiProblem('INVALID_REQUEST');
		}
		if (!in_array($action, self::CLAIMS, true)) {
			self::uuid($input['activation_id']);
			return;
		}
		self::decode($input['installation_public_key'], 32);
		if (strlen($input['license_key']) > 256) {
			throw new ApiProblem('INVALID_REQUEST');
		}
	}

	/** Mensaje que firma la instalación para probar que tiene su llave (contrato, sección 8.2). */
	public static function proofMessage(string $action, array $input, string $nonce): string
	{
		return implode("\n", [
			'ADELPOS-LIC-V1',
			$action,
			$input['challenge_id'],
			$nonce,
			$input['product_id'],
			$input['server_id'],
			$input['activation_id'] ?? '-',
		]);
	}

	public static function utc(?string $sql): ?string
	{
		return $sql === null
			? null
			: (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
	}
}
