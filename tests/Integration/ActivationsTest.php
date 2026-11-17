<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\{Actor, LicenseService};
use AdelPos\License\Domain\{ApiProblem, ApiResponse, LicenseContent, Problem, Protocol, Uuid};
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class ActivationsTest extends TestCase
{
	private MySqlFixture $fixture;
	private App $app;
	private FrozenClock $clock;
	private Actor $actor;
	private array $issued;
	private array $device;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('random-test-a', 'license');
		$keys->activate('random-test-a', 'Isolated test setup');
		$id = $this->app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!');
		$this->actor = new Actor($id, 'superadmin');
		$this->issued = $this->issue();
		$this->device = $this->device();
	}
	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}
	/** Emite una licencia; $license y $customer sustituyen los datos por omisión del caso. */
	private function issue(string $type = 'perpetual', array $license = [], array $customer = []): array
	{
		$customer = $this->app->catalog->saveCustomer(
			$this->actor,
			Uuid::create(),
			null,
			array_replace(
				['name' => 'API test', 'contact_name' => 'Test', 'email' => 'api@example.test', 'state' => 'active'],
				$customer,
			),
		);
		return $this->app->licenses->issue(
			$this->actor,
			Uuid::create(),
			array_replace(
				[
					'customer_id' => $customer['id'],
					'product_id' => 'adel_pos',
					...MySqlFixture::terms($type),
					'features' => ['prueba_b', 'prueba_a', 'prueba_e'],
					'reason' => 'Protocol test',
				],
				$license,
			),
		);
	}
	private function device(): array
	{
		$pair = sodium_crypto_sign_keypair();
		return [
			'id' => Uuid::create(),
			'public' => Protocol::encode(sodium_crypto_sign_publickey($pair)),
			'secret' => sodium_crypto_sign_secretkey($pair),
		];
	}
	private function request(string $action = 'activate', ?array $device = null, ?string $activation = null): array
	{
		$device ??= $this->device;
		$challenge = json_decode(
			$this->app->activations->challenge([
				'action' => $action,
				'product_id' => 'adel_pos',
				'installation_id' => $device['id'],
				'activation_id' => $activation,
			])->body,
			true,
		);
		$input = [
			'request_id' => Uuid::create(),
			'product_id' => 'adel_pos',
			'installation_id' => $device['id'],
			'challenge_id' => $challenge['challenge_id'],
			'app_version' => '1.0.0',
		];
		if ($action === 'activate') {
			$input += [
				'license_key' => $this->issued['secret'],
				'installation_public_key' => $device['public'],
				'fingerprint_version' => '1',
				'fingerprint_hash' => 'sha256:' . str_repeat('a', 64),
			];
		} else {
			$input['activation_id'] = $activation;
		}
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(
				Protocol::proofMessage($action, $input, $challenge['nonce']),
				$device['secret'],
			),
		);
		return $input;
	}
	private function payload(ApiResponse $response): array
	{
		self::assertSame(200, $response->status, $response->body);
		$jws = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['license_jws'];
		[$header, $body, $signature] = explode('.', $jws);
		$headerJson = sodium_base642bin($header, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		$decoded = json_decode($headerJson, true);
		// Encabezado exacto del contrato, sección 3: mismos campos, mismo orden y nada más.
		self::assertSame('{"alg":"EdDSA","kid":"' . $decoded['kid'] . '","typ":"adelpos-lic+jws"}', $headerJson);
		$key = $this->app->db->one('SELECT public_key,environment FROM signing_keys WHERE kid=?', [$decoded['kid']]);
		self::assertTrue(
			sodium_crypto_sign_verify_detached(
				Protocol::decode($signature, 64),
				$header . '.' . $body,
				$key['public_key'],
			),
		);
		$content = json_decode($this->contentJson($response), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(LicenseContent::FIELDS, array_keys($content));
		// El entorno firmado es el de la llave que firma.
		self::assertSame($key['environment'], $content['environment']);
		return $content;
	}
	/** JSON firmado, tal como viaja dentro del JWS. */
	private function contentJson(ApiResponse $response): string
	{
		$jws = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['license_jws'];
		return sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	}
	private function activate(): array
	{
		return $this->payload($this->app->activations->execute('activate', $this->request()));
	}

	public function testNewCodeNormalizesCaseAndSeparatorsWithoutChangingIdempotentResponse(): void
	{
		$input = $this->request();
		$input['license_key'] = strtolower(str_replace('-', '', $this->issued['secret']));
		$response = $this->app->activations->execute('activate', $input);
		$this->payload($response);
		$input['license_key'] = $this->issued['secret'];
		self::assertSame($response->body, $this->app->activations->execute('activate', $input)->body);
		self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
	}

	public function testOldAndMalformedCodesCannotActivateAndCredentialAttemptsAreLimited(): void
	{
		foreach (['ADELPOS-' . str_repeat('A', 43), 'K7I-9R3X-6TVP-8CHD'] as $code) {
			$input = $this->request();
			$input['license_key'] = $code;
			self::assertSame(404, $this->app->activations->execute('activate', $input)->status);
		}
		self::assertSame(0, (int) $this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
		for ($i = 0; $i < 10; ++$i) {
			$response = $this->app->activations->execute('activate', $this->request());
			self::assertContains($response->status, [200, 409]);
		}
		try {
			$this->app->activations->execute('activate', $this->request());
			self::fail('Credential throttle bypassed');
		} catch (\AdelPos\License\Domain\Problem $e) {
			self::assertSame(429, $e->status);
		}
	}

	public function testPerpetualContentMatchesTheContractExactlyAndRefreshReusesSignedBytes(): void
	{
		$response = $this->app->activations->execute('activate', $this->request());
		$payload = $this->payload($response);
		// 12:00 UTC son las 06:00 en America/Monterrey: seis meses de calendario a la misma hora local.
		self::assertSame(
			[
				'schema_version' => '1.0',
				'product_id' => 'adel_pos',
				'environment' => 'test',
				'license_id' => $this->issued['id'],
				'activation_id' => $payload['activation_id'],
				'server_id' => $this->device['id'],
				'installation_public_key' => $this->device['public'],
				'licensee_name' => 'API test',
				'license_type' => 'perpetual',
				'billing_period' => null,
				'license_status' => 'active',
				'status_reason' => null,
				'license_revision' => 1,
				'issued_at' => '2026-09-25T12:00:00.000000Z',
				'period_starts_at' => '2026-09-25T12:00:00.000000Z',
				'expires_at' => null,
				'grace_days' => 0,
				'updates_until' => '2027-03-25T12:00:00.000000Z',
				'plan' => ['plan_id' => 'elemental', 'name' => 'Plan elemental', 'custom' => false],
				'limits' => ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3],
				// Solo los módulos habilitados, como «clave → true».
				'features' => ['prueba_a' => true, 'prueba_b' => true, 'prueba_e' => true],
			],
			$payload,
		);
		self::assertMatchesRegularExpression(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
			$payload['activation_id'],
		);
		// La licencia guarda su periodo y su ancla: día 25, 06:00 hora local.
		$license = $this->app->db->one('SELECT * FROM licenses');
		self::assertSame('2026-09-25 12:00:00.000000', $license['period_starts_at']);
		self::assertNull($license['expires_at']);
		self::assertSame('2027-03-25 12:00:00.000000', $license['updates_until']);
		self::assertSame(25, (int) $license['anchor_day']);
		self::assertSame('06:00:00.000000', $license['anchor_local_time']);
		self::assertSame('1.0.0', $this->app->db->one('SELECT app_version FROM activations')['app_version']);
		$this->clock->instant = $this->clock->instant->modify('+1 day');
		$refreshed = $this->app->activations->execute(
			'refresh',
			$this->request('refresh', null, $payload['activation_id']),
		);
		self::assertSame(
			json_decode($response->body, true)['license_jws'],
			json_decode($refreshed->body, true)['license_jws'],
		);
		self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
	}
	public function testSubscriptionTrialAndCustomContentMatchTheContractExactly(): void
	{
		$common = [
			'schema_version' => '1.0',
			'product_id' => 'adel_pos',
			'environment' => 'test',
		];
		$activated = [
			'license_status' => 'active',
			'status_reason' => null,
			'license_revision' => 1,
		];

		// Suscripción mensual: vence un mes de calendario después, con 5 días de tolerancia.
		$this->issued = $this->issue('subscription');
		$this->device = $this->device();
		$subscription = $this->activate();
		self::assertSame(
			[
				...$common,
				'license_id' => $this->issued['id'],
				'activation_id' => $subscription['activation_id'],
				'server_id' => $this->device['id'],
				'installation_public_key' => $this->device['public'],
				'licensee_name' => 'API test',
				'license_type' => 'subscription',
				'billing_period' => 'monthly',
				...$activated,
				'issued_at' => '2026-09-25T12:00:00.000000Z',
				'period_starts_at' => '2026-09-25T12:00:00.000000Z',
				'expires_at' => '2026-10-25T12:00:00.000000Z',
				'grace_days' => 5,
				'updates_until' => '2026-10-25T12:00:00.000000Z',
				'plan' => ['plan_id' => 'elemental', 'name' => 'Plan elemental', 'custom' => false],
				'limits' => ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3],
				'features' => ['prueba_a' => true, 'prueba_b' => true, 'prueba_e' => true],
			],
			$subscription,
		);

		// Prueba de 15 días: 15 días de calendario, sin tolerancia y sin periodo de cobro.
		$this->issued = $this->issue('trial', ['plan_id' => 'premium']);
		$this->device = $this->device();
		$trial = $this->activate();
		self::assertSame(
			[
				...$common,
				'license_id' => $this->issued['id'],
				'activation_id' => $trial['activation_id'],
				'server_id' => $this->device['id'],
				'installation_public_key' => $this->device['public'],
				'licensee_name' => 'API test',
				'license_type' => 'trial',
				'billing_period' => null,
				...$activated,
				'issued_at' => '2026-09-25T12:00:00.000000Z',
				'period_starts_at' => '2026-09-25T12:00:00.000000Z',
				'expires_at' => '2026-10-10T12:00:00.000000Z',
				'grace_days' => 0,
				'updates_until' => '2026-10-10T12:00:00.000000Z',
				'plan' => ['plan_id' => 'premium', 'name' => 'Plan premium', 'custom' => false],
				'limits' => ['cash_registers' => 5, 'order_takers' => 10, 'kitchen_displays' => 5],
				'features' => ['prueba_a' => true, 'prueba_b' => true, 'prueba_e' => true],
			],
			$trial,
		);

		// Personalizada, con razón social, sin módulos y en otra zona horaria. En America/Tijuana el
		// 31 de octubre a las 12:00 UTC son las 05:00; un mes después ya terminó el horario de verano
		// y noviembre no tiene día 31: vence el 30 a las 05:00 locales, que son las 13:00 UTC.
		$this->clock->instant = new \DateTimeImmutable('2026-10-31T12:00:00.123456Z');
		$this->issued = $this->issue(
			'subscription',
			['plan_id' => 'basico', 'cash_registers' => '2', 'features' => []],
			['legal_name' => 'Razón Social de Pruebas, S.A. de C.V.', 'time_zone' => 'America/Tijuana'],
		);
		$this->device = $this->device();
		$response = $this->app->activations->execute('activate', $this->request());
		$custom = $this->payload($response);
		self::assertSame(
			[
				...$common,
				'license_id' => $this->issued['id'],
				'activation_id' => $custom['activation_id'],
				'server_id' => $this->device['id'],
				'installation_public_key' => $this->device['public'],
				'licensee_name' => 'Razón Social de Pruebas, S.A. de C.V.',
				'license_type' => 'subscription',
				'billing_period' => 'monthly',
				...$activated,
				'issued_at' => '2026-10-31T12:00:00.123456Z',
				'period_starts_at' => '2026-10-31T12:00:00.123456Z',
				'expires_at' => '2026-11-30T13:00:00.123456Z',
				'grace_days' => 5,
				'updates_until' => '2026-11-30T13:00:00.123456Z',
				'plan' => ['plan_id' => 'basico', 'name' => 'Plan básico', 'custom' => true],
				'limits' => ['cash_registers' => 2, 'order_takers' => 0, 'kitchen_displays' => 0],
				'features' => [],
			],
			$custom,
		);
		// Sin módulos, «features» sigue siendo un objeto JSON, y los textos van sin secuencias de escape.
		$json = $this->contentJson($response);
		self::assertStringEndsWith(',"features":{}}', $json);
		self::assertStringContainsString('"licensee_name":"Razón Social de Pruebas, S.A. de C.V."', $json);
		self::assertStringContainsString('"name":"Plan básico"', $json);
		self::assertSame(
			$json,
			$this->app->db->one('SELECT payload_json FROM license_revisions ORDER BY issued_at DESC LIMIT 1')[
				'payload_json'
			],
		);
	}

	public function testInvalidContentIsNotSignedAndLeavesNoChanges(): void
	{
		// Un nombre con un carácter de control no cumple el contrato. Solo se puede sembrar por fuera del panel.
		$break = fn() => $this->fixture->owner->execute("UPDATE customers SET display_name=CONCAT('API',CHAR(1))");
		$repair = fn() => $this->fixture->owner->execute("UPDATE customers SET display_name='API test'");
		$count = fn(string $table): int => (int) $this->app->db->execute("SELECT COUNT(*) FROM $table")->fetchColumn();
		$input = $this->request();
		$break();
		try {
			$this->app->activations->execute('activate', $input);
			self::fail('Invalid content was signed');
		} catch (\UnexpectedValueException $e) {
			// El mensaje nombra el campo, nunca su valor.
			self::assertSame('El contenido de la licencia no cumple el contrato: licensee_name.', $e->getMessage());
		}
		foreach (['activations', 'license_revisions', 'license_requests'] as $table) {
			self::assertSame(0, $count($table));
		}
		$license = $this->app->db->one('SELECT * FROM licenses');
		self::assertSame(0, (int) $license['revision_counter']);
		// El primer periodo tampoco empezó: se revierte junto con la activación.
		self::assertNull($license['period_starts_at']);
		self::assertNull($license['anchor_day']);
		self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);

		// Con el dato corregido, la misma solicitud activa.
		$repair();
		self::assertSame(1, $this->payload($this->app->activations->execute('activate', $input))['license_revision']);

		// Un cambio comercial que no se puede firmar tampoco deja rastro.
		$break();
		foreach (['features' => ['features' => ['prueba_d']], 'revoke' => []] as $operation => $data) {
			try {
				$this->app->licenses->change(
					$this->actor,
					Uuid::create(),
					$this->issued['id'],
					$operation,
					['version' => 1, 'reason' => 'No debe firmarse'] + $data,
				);
				self::fail('Invalid content was signed: ' . $operation);
			} catch (\UnexpectedValueException) {
			}
		}
		$license = $this->app->db->one('SELECT * FROM licenses');
		self::assertSame(1, (int) $license['row_version']);
		self::assertSame('issued', $license['commercial_status']);
		self::assertSame(1, (int) $license['revision_counter']);
		self::assertSame(1, $count('license_revisions'));
		self::assertSame('active', $this->app->db->one('SELECT state FROM activations')['state']);
	}

	public function testARevisionIsActiveOnlyWhileLicenseAndActivationAreBothInForce(): void
	{
		$first = $this->activate();
		$publish = fn(): array => $this->app->db->transaction(
			fn(): array => $this->app->publisher->publish(
				Uuid::bytes($this->issued['id']),
				Uuid::bytes($first['activation_id']),
			),
		);
		$content = fn(array $revision): array => json_decode(
			sodium_base642bin(explode('.', $revision['jws'])[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$second = $content($publish());
		self::assertSame(
			['active', null, 2],
			[$second['license_status'], $second['status_reason'], $second['license_revision']],
		);
		// Estado que el panel nunca deja así: la licencia revocada con su activación todavía vigente.
		// Aun entonces, lo firmado dice «revoked»: basta con que una de las dos no esté vigente.
		$this->fixture->owner->execute("UPDATE licenses SET commercial_status='revoked'");
		$third = $content($publish());
		self::assertSame(
			['revoked', 'revoked', 3],
			[$third['license_status'], $third['status_reason'], $third['license_revision']],
		);
	}

	public function testIdenticalReplaySurvivesChallengeCleanupAndCredentialRetirement(): void
	{
		$input = $this->request();
		$original = $this->app->activations->execute('activate', $input);
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'reissue', [
			'version' => 1,
			'reason' => 'Replace credential',
		]);
		$this->fixture->owner->execute('DELETE FROM activation_challenges');
		$this->clock->instant = $this->clock->instant->modify('+2 days');
		self::assertSame(
			$original->body,
			$this->app->activations->execute('activate', array_reverse($input, true))->body,
		);
		$input['app_version'] = 'changed';
		try {
			$this->app->activations->execute('activate', $input);
			self::fail('Changed retry accepted');
		} catch (ApiProblem $e) {
			self::assertSame(409, $e->status);
		}
		foreach (
			[
				'license_requests' => 'response_body',
				'license_revisions' => 'payload_json',
				'audit_events' => 'payload_json',
			]
			as $table => $column
		) {
			foreach ($this->app->db->all("SELECT $column AS content FROM $table") as $row) {
				self::assertStringNotContainsString($this->issued['secret'], $row['content']);
			}
		}
	}
	public function testChallengeExpiresAtExactBoundaryAndInvalidProofDoesNotConsumeIt(): void
	{
		$input = $this->request();
		$invalid = $input;
		$invalid['proof'] = Protocol::encode(str_repeat("\0", 64));
		try {
			$this->app->activations->execute('activate', $invalid);
			self::fail('Invalid proof accepted');
		} catch (ApiProblem $e) {
			self::assertSame('INVALID_PROOF', $e->errorCode);
		}
		self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
		$this->clock->instant = $this->clock->instant->modify('+5 minutes');
		try {
			$this->app->activations->execute('activate', $input);
			self::fail('Expired challenge accepted');
		} catch (ApiProblem $e) {
			self::assertSame(401, $e->status);
		}
		self::assertSame(0, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
	}
	public function testBoundKeyAndChallengeActionCannotBeSubstituted(): void
	{
		$payload = $this->activate();
		$input = $this->request('refresh', null, $payload['activation_id']);
		$challenge = $this->app->db->one('SELECT nonce FROM activation_challenges WHERE challenge_id=?', [
			Uuid::bytes($input['challenge_id']),
		]);
		$attacker = $this->device();
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(
				Protocol::proofMessage('refresh', $input, $challenge['nonce']),
				$attacker['secret'],
			),
		);
		try {
			$this->app->activations->execute('refresh', $input);
			self::fail('Wrong key accepted');
		} catch (ApiProblem $e) {
			self::assertSame(401, $e->status);
		}
		$input = $this->request('refresh', null, $payload['activation_id']);
		try {
			$this->app->activations->execute('deactivate', $input);
			self::fail('Wrong action accepted');
		} catch (ApiProblem $e) {
			self::assertSame(401, $e->status);
		}
		self::assertSame('active', $this->app->db->execute('SELECT state FROM activations')->fetchColumn());
	}
	public function testCommercialRefusalIsPersistedAndDoesNotConsumeAnotherRequest(): void
	{
		$this->activate();
		$other = $this->device();
		$input = $this->request('activate', $other);
		$response = $this->app->activations->execute('activate', $input);
		self::assertSame(409, $response->status);
		self::assertSame('ACTIVATION_LIMIT', json_decode($response->body, true)['error']['code']);
		self::assertSame($response->body, $this->app->activations->execute('activate', $input)->body);
		$input['request_id'] = Uuid::create();
		try {
			$this->app->activations->execute('activate', $input);
			self::fail('Reused challenge accepted');
		} catch (ApiProblem $e) {
			self::assertSame(401, $e->status);
		}
		self::assertSame(2, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
	}
	public function testUnknownKeyIsUniformAndArchivedWithoutTheSecret(): void
	{
		$input = $this->request();
		$input['license_key'] = 'unrecognized-commercial-key';
		$response = $this->app->activations->execute('activate', $input);
		self::assertSame(404, $response->status);
		self::assertSame('LICENSE_NOT_FOUND', json_decode($response->body, true)['error']['code']);
		self::assertSame($response->body, $this->app->activations->execute('activate', $input)->body);
		self::assertStringNotContainsString(
			$input['license_key'],
			Protocol::json(
				$this->app->db->all('SELECT response_body,proof_message,input_digest FROM license_requests'),
			),
		);
	}
	public function testDeactivationReleasesSlotAndOldIdentityKeepsTerminalRevision(): void
	{
		$first = $this->activate();
		$input = $this->request('deactivate', null, $first['activation_id']);
		$ack = $this->app->activations->execute('deactivate', $input);
		$data = json_decode($ack->body, true);
		self::assertSame(['activation_id', 'deactivated_at', 'status'], array_keys($data));
		self::assertSame('deactivated', $data['status']);
		self::assertSame('issued', $this->app->read->license($this->issued['id'])['commercial_status']);
		$other = $this->device();
		// Días después, en otra computadora.
		$this->clock->instant = $this->clock->instant->modify('+3 days');
		$next = $this->payload($this->app->activations->execute('activate', $this->request('activate', $other)));
		self::assertNotSame($first['activation_id'], $next['activation_id']);
		// El contador de revisiones es de la licencia, no de la activación: 1, 2 (cierre) y 3.
		self::assertSame(3, $next['license_revision']);
		// Las fechas también son de la licencia: la segunda computadora conserva el primer periodo.
		self::assertSame($first['period_starts_at'], $next['period_starts_at']);
		self::assertSame($first['updates_until'], $next['updates_until']);
		self::assertSame($ack->body, $this->app->activations->execute('deactivate', $input)->body);
		self::assertSame(
			$ack->body,
			$this->app->activations->execute('deactivate', $this->request('deactivate', null, $first['activation_id']))
				->body,
		);
		$old = $this->payload(
			$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
		);
		self::assertSame('revoked', $old['license_status']);
		self::assertSame('deactivated', $old['status_reason']);
		self::assertSame(2, $old['license_revision']);
		self::assertSame(
			1,
			(int) $this->app->db->execute("SELECT COUNT(*) FROM activations WHERE state='active'")->fetchColumn(),
		);
		self::assertSame(
			[['1', 'active', null], ['2', 'revoked', 'deactivated'], ['3', 'active', null]],
			array_map(
				fn(array $row) => [(string) $row['license_revision'], $row['license_status'], $row['status_reason']],
				$this->app->db->all(
					'SELECT license_revision,license_status,status_reason FROM license_revisions ORDER BY license_revision',
				),
			),
		);
	}
	public function testAdministrativeRightsAndRevocationPublishAtomically(): void
	{
		$first = $this->activate();
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'features', [
			'version' => 1,
			'features' => ['prueba_b', 'prueba_a'],
			'reason' => 'First module change',
		]);
		$current = $this->payload(
			$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
		);
		self::assertSame(2, $current['license_revision']);
		self::assertNull($current['expires_at']);
		self::assertSame(['prueba_a' => true, 'prueba_b' => true], $current['features']);
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'features', [
			'version' => 2,
			'features' => ['prueba_b'],
			'reason' => 'Module change',
		]);
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'revoke', [
			'version' => 3,
			'reason' => 'Commercial revocation',
		]);
		$revoked = $this->payload(
			$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
		);
		self::assertSame('revoked', $revoked['license_status']);
		self::assertSame('revoked', $revoked['status_reason']);
		self::assertSame(4, $revoked['license_revision']);
		self::assertSame(['prueba_b' => true], $revoked['features']);
		self::assertSame('revoked', $this->app->db->one('SELECT state FROM activations')['state']);
		self::assertSame(
			403,
			$this->app->activations->execute('activate', $this->request('activate', $this->device()))->status,
		);
		self::assertGreaterThan(0, $this->app->audit->verify());
	}
	public function testSubscriptionRefreshedLaterKeepsItsRevisionAndSlotAndRenewalIsNotAvailableYet(): void
	{
		$this->issued = $this->issue('subscription');
		$first = $this->activate();
		$this->clock->instant = new \DateTimeImmutable('2026-11-01T00:00:00Z');
		$refresh = fn() => $this->payload(
			$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
		);
		$current = $refresh();
		self::assertSame($first, $current);
		self::assertSame(5, $current['grace_days']);
		self::assertSame('active', $current['license_status']);
		self::assertSame(
			409,
			$this->app->activations->execute('activate', $this->request('activate', $this->device()))->status,
		);
		// The renewal of the previous engine is gone; the contract rules arrive in stage 3.
		try {
			$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'renew', [
				'version' => 1,
				'until' => '2026-12-01T00:00',
				'reason' => 'Purchased renewal',
			]);
			self::fail('Renewal accepted');
		} catch (Problem $e) {
			self::assertSame(LicenseService::PENDING['renew'], $e->getMessage());
		}
		self::assertSame($first, $refresh());
		self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
	}
	public function testMissingSigningFileRollsBackActivationAndCommercialChange(): void
	{
		$key = $this->app->db->one('SELECT private_ref FROM signing_keys WHERE kid=\'random-test-a\'');
		$path = $this->fixture->values['SIGNING_KEY_DIR'] . '/' . $key['private_ref'];
		$input = $this->request();
		rename($path, $path . '.unavailable');
		try {
			try {
				$this->app->activations->execute('activate', $input);
				self::fail('Missing signer accepted');
			} catch (\RuntimeException) {
			}
			self::assertSame(0, (int) $this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
			self::assertSame(0, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
			self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
		} finally {
			rename($path . '.unavailable', $path);
		}
		$this->app->activations->execute('activate', $input);
		rename($path, $path . '.unavailable');
		try {
			try {
				$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'features', [
					'version' => 1,
					'features' => ['prueba_d'],
					'reason' => 'Must roll back',
				]);
				self::fail('Unsigned change accepted');
			} catch (\RuntimeException $e) {
				self::assertSame('License signing material unavailable.', $e->getMessage());
			}
			$license = $this->app->read->license($this->issued['id']);
			self::assertSame(1, (int) $license['row_version']);
			self::assertSame(
				['prueba_a', 'prueba_b', 'prueba_e'],
				array_column(
					array_filter($license['features'], fn($feature) => (bool) $feature['enabled']),
					'capability_key',
				),
			);
			self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
		} finally {
			rename($path . '.unavailable', $path);
		}
	}
	public function testDatabaseEnforcesOneSlotAndPreventsIdentityOrRevisionEdits(): void
	{
		$this->activate();
		$activation = $this->app->db->one('SELECT * FROM activations');
		try {
			$this->fixture->owner->execute(
				"INSERT INTO activations(activation_id,license_id,product_id,server_id,installation_public_key,
						state,activated_at)
					VALUES (?,?,?,?,?,'active',?)",
				[
					Uuid::bytes(Uuid::create()),
					$activation['license_id'],
					$activation['product_id'],
					$activation['server_id'],
					$activation['installation_public_key'],
					$this->clock->sql(),
				],
			);
			self::fail('Duplicate active slot accepted');
		} catch (\PDOException $e) {
			self::assertSame(1062, (int) $e->errorInfo[1]);
		}
		foreach (
			[
				'UPDATE activations SET installation_public_key=installation_public_key WHERE 1=0',
				'UPDATE license_revisions SET license_jws=license_jws WHERE 1=0',
				'DELETE FROM license_revisions WHERE 1=0',
			]
			as $sql
		) {
			try {
				$this->app->db->execute($sql);
				self::fail('Immutable data can be changed');
			} catch (\PDOException $e) {
				self::assertContains((int) $e->errorInfo[1], [1142, 1143]);
			}
		}
	}
	public function testRotationPreservesOldJwsAndSeparatesManifestPurpose(): void
	{
		$first = $this->activate();
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('random-manifest', 'manifest');
		$keys->activate('random-manifest', 'Manifest isolation');
		self::assertSame(
			$first,
			$this->payload(
				$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
			),
		);
		$keys->generate('random-test-b', 'license');
		$keys->activate('random-test-b', 'Trust distributed in isolated test');
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'features', [
			'version' => 1,
			'features' => ['prueba_d'],
			'reason' => 'New rights with new signer',
		]);
		$this->payload(
			$this->app->activations->execute('refresh', $this->request('refresh', null, $first['activation_id'])),
		);
		self::assertSame(
			['random-test-a', 'random-test-b'],
			array_column($this->app->db->all('SELECT kid FROM license_revisions ORDER BY license_revision'), 'kid'),
		);
		self::assertSame(
			'verify_only',
			$this->app->db->execute('SELECT state FROM signing_keys WHERE kid=\'random-test-a\'')->fetchColumn(),
		);
	}
	public function testUppercaseUuidBytesAreUsedInProofWithoutNormalization(): void
	{
		$device = $this->device;
		$device['id'] = strtoupper($device['id']);
		$input = $this->request('activate', $device);
		$nonce = $this->app->db
			->execute('SELECT nonce FROM activation_challenges WHERE challenge_id=?', [
				Uuid::bytes($input['challenge_id']),
			])
			->fetchColumn();
		$input['challenge_id'] = strtoupper($input['challenge_id']);
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(Protocol::proofMessage('activate', $input, $nonce), $device['secret']),
		);
		self::assertSame(
			strtolower($device['id']),
			$this->payload($this->app->activations->execute('activate', $input))['server_id'],
		);
	}
	public function testAuditFailureAfterSigningRollsBackEveryProtocolEffect(): void
	{
		$input = $this->request();
		$this->fixture->owner->execute('UPDATE audit_heads SET last_sequence=0');
		try {
			$this->app->activations->execute('activate', $input);
			self::fail('Partial effect committed');
		} catch (\PDOException) {
		}
		foreach (['activations', 'license_revisions', 'license_requests'] as $table) {
			self::assertSame(0, (int) $this->app->db->execute("SELECT COUNT(*) FROM $table")->fetchColumn());
		}
		self::assertSame(0, (int) $this->app->db->execute('SELECT revision_counter FROM licenses')->fetchColumn());
		self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
	}
	public function testSavingUnchangedModulesDoesNotCreateAnotherSignedRevision(): void
	{
		$this->activate();
		$this->app->licenses->change($this->actor, Uuid::create(), $this->issued['id'], 'features', [
			'version' => 1,
			'features' => ['prueba_b', 'prueba_a', 'prueba_e'],
			'reason' => 'Review without a rights change',
		]);
		self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
	}
	public function testTwoProcessesWithDistinctRequestsCannotOccupyTwoSlots(): void
	{
		$this->race(false);
	}
	public function testTwoProcessesWithIdenticalRequestReturnIdenticalBytes(): void
	{
		$this->race(true);
	}
	private function race(bool $duplicate): void
	{
		$first = $this->request();
		$inputs = [$first, $duplicate ? $first : $this->request('activate', $this->device())];
		$workers = [];
		$files = [];
		$owner = $this->fixture->owner;
		$owner->pdo->beginTransaction();
		$owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [
			Uuid::bytes($this->issued['id']),
		]);
		try {
			foreach ($inputs as $input) {
				$file = tempnam(sys_get_temp_dir(), 'adelpos-license-api-race-');
				chmod($file, 0600);
				$files[] = $file;
				file_put_contents($file, Protocol::json(['config' => $this->fixture->values, 'input' => $input]));
				$process = proc_open(
					[PHP_BINARY, dirname(__DIR__) . '/activation-worker.php', $file],
					[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
					$pipes,
				);
				self::assertIsResource($process);
				$workers[] = [$process, $pipes];
			}
			$deadline = microtime(true) + 10;
			do {
				$waiting = (int) $owner
					->execute(
						'SELECT COUNT(*)
							FROM performance_schema.data_lock_waits w
							JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
							WHERE l.OBJECT_SCHEMA=?',
						[$this->fixture->database],
					)
					->fetchColumn();
				if ($waiting >= 2) {
					break;
				}
				usleep(10000);
			} while (microtime(true) < $deadline);
			self::assertGreaterThanOrEqual(2, $waiting);
			$owner->pdo->commit();
			$results = [];
			foreach ($workers as [$process, $pipes]) {
				$output = stream_get_contents($pipes[1]);
				$error = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				self::assertSame(0, proc_close($process), $error);
				$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
			}
			$statuses = array_column($results, 'status');
			sort($statuses);
			self::assertSame($duplicate ? [200, 200] : [200, 409], $statuses);
			if ($duplicate) {
				self::assertSame($results[0]['body'], $results[1]['body']);
			}
			self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
			self::assertSame(1, (int) $this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
		} finally {
			if ($owner->pdo->inTransaction()) {
				$owner->pdo->rollBack();
			}
			foreach ($files as $file) {
				unlink($file);
			}
		}
	}
}
