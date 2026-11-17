<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{ApiResponse, Protocol, Uuid};

/**
 * Escenario de las pruebas de transferencia (etapa 4): un servidor con su llave de firma, un
 * superadministrador, un cliente, y computadoras principales sintéticas que activan, transfieren,
 * consultan y se desactivan por la API en línea.
 */
trait TransferScenario
{
	/** Las licencias se activan a las 06:00:00.123456 de Monterrey: los microsegundos deben sobrevivir. */
	private const START = '2026-09-25T12:00:00.123456Z';

	private MySqlFixture $fixture;
	private App $app;
	private FrozenClock $clock;
	private Actor $actor;
	private string $customer;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable(self::START));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('transfer-test-key', 'license');
		$keys->activate('transfer-test-key', 'Isolated test setup');
		$this->actor = new Actor(
			$this->app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
		$this->customer = $this->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Restaurante sintético',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
		])['id'];
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	/** @return array{id: string, secret: string} */
	private function issue(string $type = 'perpetual'): array
	{
		return $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms($type),
		]);
	}

	/** Una computadora principal sintética: su identidad y su llave de licencias. */
	private function computer(): array
	{
		$pair = sodium_crypto_sign_keypair();
		return [
			'id' => Uuid::create(),
			'public' => Protocol::encode(sodium_crypto_sign_publickey($pair)),
			'secret' => sodium_crypto_sign_secretkey($pair),
		];
	}

	/** Petición firmada de la API: activar o transferir con el código, o consultar y desactivar una activación. */
	private function request(
		string $action,
		array $computer,
		?string $licenseKey = null,
		?string $activation = null,
		string $product = 'adel_pos',
	): array {
		$challenge = json_decode(
			$this->app->activations->challenge([
				'action' => $action,
				'product_id' => $product,
				'server_id' => $computer['id'],
				'activation_id' => $activation,
			])->body,
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$input = [
			'request_id' => Uuid::create(),
			'product_id' => $product,
			'server_id' => $computer['id'],
			'challenge_id' => $challenge['challenge_id'],
			'app_version' => '1.4.0',
		];
		if (in_array($action, Protocol::CLAIMS, true)) {
			$input += ['license_key' => $licenseKey, 'installation_public_key' => $computer['public']];
		} else {
			$input['activation_id'] = $activation;
		}
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(
				Protocol::proofMessage($action, $input, $challenge['nonce']),
				$computer['secret'],
			),
		);
		return $input;
	}

	private function call(string $action, array $computer, ?string $key = null, ?string $activation = null): ApiResponse
	{
		return $this->app->activations->execute($action, $this->request($action, $computer, $key, $activation));
	}

	/** Contenido firmado de una respuesta exitosa, comprobado contra los archivos publicados del contrato. */
	private static function content(ApiResponse $response): array
	{
		self::assertSame(200, $response->status, $response->body);
		$shape = isset(json_decode($response->body, true)['status']) ? 'DeactivationResponse' : 'LicenseResponse';
		self::assertNull(ContractSchemas::componentErrors($shape, $response->body));
		$jws = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['license_jws'];
		$json = sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		self::assertNull(ContractSchemas::licenseErrors($json));
		return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	}

	private static function error(ApiResponse $response): array
	{
		self::assertNull(ContractSchemas::componentErrors('Error', $response->body));
		return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['error'];
	}

	/** Fechas y derechos de la licencia tal como están guardados. */
	private function rights(string $licenseId): array
	{
		return $this->fixture->owner->one(
			'SELECT license_type,billing_period,trial_duration,updates_months,grace_days,plan_id,plan_name,plan_custom,
					cash_registers,order_takers,kitchen_displays,period_starts_at,expires_at,updates_until,anchor_day,
					anchor_local_time,period_payment_id,row_version,commercial_status
				FROM licenses
				WHERE license_id=?',
			[Uuid::bytes($licenseId)],
		);
	}

	private function rows(string $table, string $where = '1=1', array $parameters = []): int
	{
		return (int) $this->fixture->owner
			->execute('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $parameters)
			->fetchColumn();
	}
}
