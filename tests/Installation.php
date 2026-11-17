<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use AdelPos\License\App;
use AdelPos\License\Domain\{Protocol, Uuid};

/**
 * Una instalación sintética de AD-EL POS para las pruebas del ciclo comercial: activa una
 * licencia en línea con su código, consulta su revisión vigente y lee el contenido firmado.
 */
final class Installation
{
	public readonly string $serverId;
	public readonly string $publicKey;
	private string $secretKey;
	public ?string $activationId = null;

	public function __construct(private App $app)
	{
		$pair = sodium_crypto_sign_keypair();
		$this->serverId = Uuid::create();
		$this->publicKey = Protocol::encode(sodium_crypto_sign_publickey($pair));
		$this->secretKey = sodium_crypto_sign_secretkey($pair);
	}

	/** Activa en línea con el código de la licencia y devuelve el contenido firmado. */
	public function activate(string $licenseKey): array
	{
		$response = $this->app->activations->execute(
			'activate',
			$this->request('activate', [
				'license_key' => $licenseKey,
				'installation_public_key' => $this->publicKey,
			]),
		);
		$content = self::content($response->status, $response->body);
		$this->activationId = $content['activation_id'];
		return $content;
	}

	/** Consulta en línea la revisión vigente de su activación. */
	public function refresh(): array
	{
		$response = $this->app->activations->execute(
			'refresh',
			$this->request('refresh', ['activation_id' => $this->activationId]),
		);
		return self::content($response->status, $response->body);
	}

	/** Contenido firmado de la revisión vigente de una licencia, tal como lo guarda el servidor. */
	public static function signed(App $app, string $licenseId): array
	{
		$row = $app->db->one(
			'SELECT r.payload_json
				FROM license_revisions r
				WHERE r.license_id=?
				ORDER BY r.license_revision DESC
				LIMIT 1',
			[Uuid::bytes($licenseId)],
		);
		return $row === null ? [] : json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
	}

	/** Número de revisiones firmadas de una licencia. */
	public static function revisions(App $app, string $licenseId): int
	{
		return (int) $app->db
			->execute('SELECT COUNT(*) FROM license_revisions WHERE license_id=?', [Uuid::bytes($licenseId)])
			->fetchColumn();
	}

	private function request(string $action, array $fields): array
	{
		$challenge = json_decode(
			$this->app->activations->challenge([
				'action' => $action,
				'product_id' => 'adel_pos',
				'server_id' => $this->serverId,
				'activation_id' => $fields['activation_id'] ?? null,
			])->body,
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$input =
			[
				'request_id' => Uuid::create(),
				'product_id' => 'adel_pos',
				'server_id' => $this->serverId,
				'challenge_id' => $challenge['challenge_id'],
				'app_version' => '1.0.0',
			] + $fields;
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(Protocol::proofMessage($action, $input, $challenge['nonce']), $this->secretKey),
		);
		return $input;
	}

	private static function content(int $status, string $body): array
	{
		if ($status !== 200) {
			throw new \RuntimeException('Unexpected API status ' . $status . ': ' . $body);
		}
		$jws = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['license_jws'];
		return json_decode(
			sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
	}
}
