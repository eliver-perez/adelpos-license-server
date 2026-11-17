<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\{Actor, LicenseService};
use AdelPos\License\Domain\{ApiProblem, OfflineRequest, Problem, Protocol, Uuid};
use AdelPos\License\Tests\{ContractSchemas, FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class OfflineTest extends TestCase
{
	private MySqlFixture $fixture;
	private App $app;
	private FrozenClock $clock;
	private Actor $actor;
	private array $license;
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
		$keys->generate('offline-test', 'license');
		$keys->activate('offline-test', 'Isolated test');
		$this->actor = new Actor(
			$this->app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
		$this->license = $this->issue();
		$this->device = $this->device();
	}
	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}
	private function issue(string $type = 'perpetual'): array
	{
		$customer = $this->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Offline tests',
			'contact_name' => 'Test',
			'email' => 'offline@example.test',
			'state' => 'active',
		]);
		return $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $customer['id'],
			'product_id' => 'adel_pos',
			...MySqlFixture::terms($type),
			'features' => ['prueba_a', 'prueba_e'],
			'reason' => 'Offline tests',
		]);
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
	private function envelope(
		string $action = 'activate',
		?array $activation = null,
		array $changes = [],
		?array $device = null,
	): string {
		$device ??= $this->device;
		$payload = [
			'action' => $action,
			'request_id' => Uuid::create(),
			'created_at' => '1999-01-01T00:00:00Z',
			'product_id' => 'adel_pos',
			'server_id' => $device['id'],
			'installation_public_key' => $device['public'],
			'app_version' => '0.12.0',
		];
		if ($activation === null) {
			// El código comercial es opcional y solo viaja al activar.
			$payload['license_key'] = $this->license['secret'];
		} else {
			$payload += ['license_id' => $activation['license_id'], 'activation_id' => $activation['activation_id']];
		}
		$encoded = Protocol::encode(Protocol::json(array_replace($payload, $changes)));
		return Protocol::json([
			'schema_version' => '1.0',
			'payload_b64u' => $encoded,
			'signature_b64u' => Protocol::encode(
				sodium_crypto_sign_detached("ADELPOS-LICREQ-V1\n" . $encoded, $device['secret']),
			),
		]);
	}
	private function import(string $envelope): string
	{
		return $this->app->offline->import($this->actor, Uuid::create(), $envelope)['id'];
	}
	private function input(array $extra = []): array
	{
		return array_replace(
			[
				'license_id' => $this->license['id'],
				'version' => $this->app->read->license($this->license['id'])['row_version'],
				'renewal_mode' => 'current',
				'reason' => 'Explicit offline approval',
			],
			$extra,
		);
	}
	private function approve(string $id, array $extra = [], ?string $operationId = null): array
	{
		return $this->app->offline->decide(
			$this->actor,
			$operationId ?? Uuid::create(),
			$id,
			'approved',
			$this->input($extra),
		);
	}
	private function payload(array $result): array
	{
		$jws = $this->app->read->revision($result['license_id'], $result['revision_id'])['license_jws'];
		[$header, $body, $signature] = explode('.', $jws);
		$public = $this->app->db
			->execute("SELECT public_key FROM signing_keys WHERE kid='offline-test'")
			->fetchColumn();
		self::assertTrue(
			sodium_crypto_sign_verify_detached(Protocol::decode($signature, 64), $header . '.' . $body, $public),
		);
		$content = sodium_base642bin($body, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		// El archivo .lic lleva el mismo contenido que la API en línea, y cumple el esquema publicado.
		self::assertNull(ContractSchemas::licenseErrors($content));
		return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	}
	private function activate(): array
	{
		return $this->payload($this->approve($this->import($this->envelope())));
	}
	private function rowCount(string $table): int
	{
		return (int) $this->app->db->execute('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
	}

	private function onlineRequest(
		?string $requestId = null,
		string $action = 'activate',
		?string $activationId = null,
	): array {
		$challenge = json_decode(
			$this->app->activations->challenge([
				'action' => $action,
				'product_id' => 'adel_pos',
				'server_id' => $this->device['id'],
				'activation_id' => $activationId,
			])->body,
			true,
		);
		$input = [
			'request_id' => $requestId ?? Uuid::create(),
			'product_id' => 'adel_pos',
			'server_id' => $this->device['id'],
			'challenge_id' => $challenge['challenge_id'],
			'app_version' => 'test',
		];
		if ($action === 'activate') {
			$input += [
				'license_key' => $this->license['secret'],
				'installation_public_key' => $this->device['public'],
			];
		} else {
			$input['activation_id'] = $activationId;
		}
		$input['proof'] = Protocol::encode(
			sodium_crypto_sign_detached(
				Protocol::proofMessage($action, $input, $challenge['nonce']),
				$this->device['secret'],
			),
		);
		return $input;
	}

	public function testImportIsPendingEncryptedAndDuplicateFormattingIsHarmless(): void
	{
		$envelope = $this->envelope();
		$id = $this->import($envelope);
		self::assertSame('pending', $this->app->read->offline($id)['status']);
		self::assertSame(0, $this->rowCount('activations'));
		$row = $this->app->db->one('SELECT * FROM offline_requests');
		self::assertSame(
			$envelope,
			$this->app->crypto->decryptEvidence($row['evidence_encrypted'], 'offline:v1:' . $id),
		);
		self::assertSame(
			$id,
			$this->import(json_encode(array_reverse(json_decode($envelope, true), true), JSON_PRETTY_PRINT)),
		);
		self::assertSame(1, $this->rowCount('offline_requests'));
		foreach (
			[
				'offline_requests' => 'projection_json',
				'license_requests' => 'proof_message',
				'audit_events' => 'payload_json',
				'admin_operations' => 'result_json',
			]
			as $table => $column
		) {
			foreach ($this->app->db->all('SELECT ' . $column . ' AS content FROM ' . $table) as $record) {
				self::assertStringNotContainsString($this->license['secret'], $record['content'] ?? '');
			}
		}
		$result = $this->approve($id);
		$payload = $this->payload($result);
		self::assertSame(1, $payload['license_revision']);
		self::assertSame('active', $payload['license_status']);
		self::assertNull($payload['status_reason']);
		// El primer periodo empieza al aprobar la solicitud (contrato, sección 5.2).
		self::assertSame('2026-09-25T12:00:00.000000Z', $payload['period_starts_at']);
		self::assertSame('2027-03-25T12:00:00.000000Z', $payload['updates_until']);
		self::assertSame($id, $this->import($envelope));
		self::assertSame(1, $this->rowCount('license_revisions'));
		self::assertSame('approved', $this->app->read->offline($id)['status']);
	}

	public function testModifiedSignedBytesConflictAndInvalidSignatureReservesNothing(): void
	{
		$envelope = $this->envelope();
		$payload = OfflineRequest::verify($envelope)['payload'];
		$this->import($envelope);
		try {
			$this->import(
				$this->envelope(
					changes: ['request_id' => $payload['request_id'], 'created_at' => '2000-01-01T00:00:00Z'],
				),
			);
			self::fail('Changed request accepted');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$outer = json_decode($this->envelope(), true);
		$outer['signature_b64u'] = Protocol::encode(str_repeat("\0", 64));
		try {
			$this->import(Protocol::json($outer));
			self::fail('Bad proof accepted');
		} catch (Problem $error) {
			self::assertSame(401, $error->status);
		}
		self::assertSame(1, $this->rowCount('license_requests'));
	}

	public function testApprovalIsIdempotentAndOppositeOrSecondDecisionCannotChangeIt(): void
	{
		$id = $this->import($this->envelope());
		$operation = Uuid::create();
		$first = $this->approve($id, [], $operation);
		$replay = $this->approve($id, [], $operation);
		self::assertTrue($replay['replayed']);
		self::assertSame($first['revision_id'], $replay['revision_id']);
		foreach (['approved', 'rejected'] as $decision) {
			try {
				$this->app->offline->decide($this->actor, Uuid::create(), $id, $decision, $this->input());
				self::fail('Second decision accepted');
			} catch (Problem $error) {
				self::assertSame(409, $error->status);
			}
		}
		self::assertSame(1, $this->rowCount('license_revisions'));
		self::assertSame(1, $this->rowCount('offline_decisions'));
	}

	public function testRejectCreatesNoActivationAndCannotBeApprovedLater(): void
	{
		$id = $this->import($this->envelope());
		$this->app->offline->decide($this->actor, Uuid::create(), $id, 'rejected', [
			'reason' => 'No commercial authorization',
		]);
		self::assertSame('rejected', $this->app->read->offline($id)['status']);
		self::assertSame(0, $this->rowCount('license_revisions'));
		$this->expectException(Problem::class);
		$this->approve($id);
	}

	public function testRenewDeliversCurrentRightsOrRecordsARenewalWithItsPayment(): void
	{
		$this->license = $this->issue('subscription');
		$active = $this->activate();
		$id = $this->import($this->envelope('renew', $active));
		// Sin monto no se registra la renovación, y la solicitud sigue pendiente.
		try {
			$this->approve($id, ['renewal_mode' => 'extend', 'reference' => 'OFFLINE-PURCHASE']);
			self::fail('Renewal recorded without its payment');
		} catch (Problem $error) {
			self::assertSame('Escribe el monto cobrado.', $error->getMessage());
		}
		self::assertSame('pending', $this->app->read->offline($id)['status']);
		self::assertSame(1, $this->rowCount('license_revisions'));
		// Días después se aprueba: «renew» entrega la revisión vigente, sin firmar otra.
		$this->clock->instant = $this->clock->instant->modify('+4 days');
		$result = $this->approve($id);
		$renewed = $this->payload($result);
		self::assertSame($active, $renewed);
		self::assertSame(1, $renewed['license_revision']);
		self::assertSame('2026-10-25T12:00:00.000000Z', $renewed['expires_at']);
		self::assertSame(5, $renewed['grace_days']);
		self::assertSame(1, $this->rowCount('license_revisions'));
		self::assertSame(1, (int) $this->app->read->license($this->license['id'])['revision_counter']);
		// La decisión apunta a la revisión que ya existía: es el mismo archivo .lic.
		self::assertSame(
			$this->app->db->one('SELECT BIN_TO_UUID(revision_id) AS id FROM license_revisions')['id'],
			$result['revision_id'],
		);
		self::assertSame(1, (int) $this->app->read->license($this->license['id'])['row_version']);
		// Ningún pago además del de alta: entregar los derechos vigentes no es una renovación.
		self::assertSame(
			0,
			(int) $this->app->db
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept<>'issue'")
				->fetchColumn(),
		);
		// Otra solicitud «renew»: el propietario registra ahí mismo la renovación con su pago (§5.3), y la
		// computadora recibe la revisión nueva de su misma activación.
		$second = $this->import($this->envelope('renew', $active));
		$result = $this->approve($second, [
			'renewal_mode' => 'extend',
			'billing_period' => 'quarterly',
			'amount' => '2400',
			'reference' => 'OFFLINE-PURCHASE',
		]);
		$extended = $this->payload($result);
		self::assertSame(
			[2, $active['activation_id'], '2026-10-25T12:00:00.000000Z', '2027-01-25T12:00:00.000000Z', 'quarterly'],
			[
				$extended['license_revision'],
				$extended['activation_id'],
				$extended['period_starts_at'],
				$extended['expires_at'],
				$extended['billing_period'],
			],
		);
		$payment = $this->app->db->one("SELECT * FROM license_payments WHERE concept='renewal'");
		self::assertSame(
			['quarterly', '2400.00', 'OFFLINE-PURCHASE'],
			[$payment['term'], $payment['amount'], $payment['reference']],
		);
		// Una solicitud de otra acción no puede registrar una renovación.
		$deactivate = $this->import($this->envelope('deactivate', $active));
		try {
			$this->approve($deactivate, [
				'renewal_mode' => 'extend',
				'amount' => '2400',
				'accept_offline_limit' => '1',
			]);
			self::fail('A deactivation recorded a renewal');
		} catch (Problem $error) {
			self::assertStringContainsString('Solo una solicitud de renovación', $error->getMessage());
		}
		// Si la computadora ya liberó la licencia, su solicitud «renew» recibe su cierre, pero no registra una
		// renovación: esa se registra desde el detalle de la licencia.
		$this->approve($deactivate, ['accept_offline_limit' => '1']);
		$late = $this->import($this->envelope('renew', $active));
		try {
			$this->approve($late, ['renewal_mode' => 'extend', 'amount' => '2400']);
			self::fail('A renewal was delivered to a computer that released the license');
		} catch (Problem $error) {
			self::assertStringContainsString('ya no tiene la licencia', $error->getMessage());
		}
		self::assertSame('deactivated', $this->payload($this->approve($late))['status_reason']);
	}

	public function testPerpetualRenewUsesCurrentRightsWithoutGrantingMaintenance(): void
	{
		$active = $this->activate();
		$id = $this->import($this->envelope('renew', $active));
		$renewed = $this->payload($this->approve($id));
		self::assertNull($renewed['expires_at']);
		self::assertSame($active, $renewed);
		self::assertSame(1, $this->rowCount('license_revisions'));
		// Si los derechos cambiaron después, otra solicitud recibe la revisión nueva.
		$this->app->licenses->change($this->actor, Uuid::create(), $this->license['id'], 'features', [
			'version' => 1,
			'features' => ['prueba_d'],
			'reason' => 'Cambio de módulos',
		]);
		$current = $this->payload($this->approve($this->import($this->envelope('renew', $active)), ['version' => 2]));
		self::assertSame([2, ['prueba_d' => true]], [$current['license_revision'], $current['features']]);
		self::assertSame(2, $this->rowCount('license_revisions'));
	}

	public function testSignedDeactivationReleasesSlotAndLeavesTerminalRevision(): void
	{
		$active = $this->activate();
		$id = $this->import($this->envelope('deactivate', $active));
		try {
			$this->approve($id);
			self::fail('Release without exceptional acknowledgement');
		} catch (Problem $error) {
			self::assertSame(422, $error->status);
		}
		$terminal = $this->payload($this->approve($id, ['accept_offline_limit' => '1']));
		self::assertSame('revoked', $terminal['license_status']);
		self::assertSame('deactivated', $terminal['status_reason']);
		self::assertSame(2, $terminal['license_revision']);
		self::assertSame('issued', $this->app->read->license($this->license['id'])['commercial_status']);
		$this->device = $this->device();
		$next = $this->activate();
		self::assertNotSame($active['activation_id'], $next['activation_id']);
		self::assertSame(3, $next['license_revision']);
		self::assertSame(
			2,
			(int) $this->app->db
				->execute(
					'SELECT r.license_revision
						FROM activations a
						JOIN license_revisions r ON r.revision_id=a.current_revision_id
						WHERE a.activation_id=?',
					[Uuid::bytes($active['activation_id'])],
				)
				->fetchColumn(),
		);
	}

	public function testBoundIdentityChecksAllSignedFieldsAndRechecksAtApproval(): void
	{
		$active = $this->activate();
		foreach (
			[
				'server_id' => Uuid::create(),
				'license_id' => Uuid::create(),
				'activation_id' => Uuid::create(),
			]
			as $field => $value
		) {
			try {
				$this->import($this->envelope('renew', $active, [$field => $value]));
				self::fail('Wrong identity accepted: ' . $field);
			} catch (Problem $error) {
				self::assertContains($error->status, [409, 422]);
			}
		}
		try {
			$this->import($this->envelope('renew', $active, ['server_id' => $this->device['id']], $this->device()));
			self::fail('Different key accepted');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$pending = $this->import($this->envelope('renew', $active));
		$this->app->offline->release($this->actor, Uuid::create(), $this->license['id'], [
			'activation_id' => $active['activation_id'],
			'version' => 1,
			'reason' => 'Damaged device',
			'accept_offline_limit' => '1',
		]);
		// La activación ya terminó: su solicitud recibe la revisión de cierre, y no se firma otra.
		$closing = $this->payload($this->approve($pending));
		self::assertSame(
			[$active['activation_id'], 'revoked', 'transferred', 2],
			[
				$closing['activation_id'],
				$closing['license_status'],
				$closing['status_reason'],
				$closing['license_revision'],
			],
		);
		self::assertSame(2, $this->rowCount('license_revisions'));
		self::assertSame('approved', $this->app->read->offline($pending)['status']);
		self::assertSame('transferred', $this->app->db->one('SELECT state FROM activations')['state']);
	}

	public function testTheSameInstallationActivatingAgainReceivesItsCurrentRevision(): void
	{
		$active = $this->activate();
		$license = $this->app->db->one('SELECT * FROM licenses');
		$this->clock->instant = $this->clock->instant->modify('+7 days');
		// La misma computadora (misma identidad y misma llave) pide activar otra vez.
		$again = $this->payload($this->approve($this->import($this->envelope(changes: ['app_version' => '0.13.0']))));
		self::assertSame($active, $again);
		self::assertSame(1, $this->rowCount('activations'));
		self::assertSame(1, $this->rowCount('license_revisions'));
		self::assertSame(0, $this->rowCount('license_transfers'));
		$after = $this->app->db->one('SELECT * FROM licenses');
		foreach (
			['period_starts_at', 'updates_until', 'anchor_day', 'anchor_local_time', 'revision_counter']
			as $field
		) {
			self::assertSame($license[$field], $after[$field], $field);
		}
		// Queda registrada la versión de AD-EL POS que firmó la última solicitud.
		self::assertSame('0.13.0', $this->app->db->one('SELECT app_version FROM activations')['app_version']);
		// No es una transferencia: no se puede forzar contra sí misma.
		$forced = $this->import($this->envelope());
		try {
			$this->approve($forced, ['force_activation_id' => $active['activation_id'], 'accept_offline_limit' => '1']);
			self::fail('Transfer to the same installation accepted');
		} catch (Problem $error) {
			self::assertSame('El destino debe ser otra instalación.', $error->getMessage());
		}
		// La misma identidad con otra llave es otra instalación: necesita la transferencia del propietario.
		$impostor = $this->device();
		$impostor['id'] = $this->device['id'];
		$other = $this->import($this->envelope(device: $impostor));
		try {
			$this->approve($other);
			self::fail('Another key received the license');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$transferred = $this->payload(
			$this->approve($other, ['force_activation_id' => $active['activation_id'], 'accept_offline_limit' => '1']),
		);
		self::assertSame(
			[3, $impostor['public']],
			[$transferred['license_revision'], $transferred['installation_public_key']],
		);
	}

	public function testThePeriodStartsWhenTheRequestIsApprovedAndTheStoredCopyNeverChanges(): void
	{
		$this->license = $this->issue('subscription');
		$envelope = $this->envelope(changes: ['created_at' => '2026-09-01T08:00:00.5Z', 'app_version' => '0.12.3']);
		$id = $this->import($envelope);
		// Recibir la solicitud no concede nada: la licencia sigue sin fechas.
		$license = $this->app->db->one('SELECT * FROM licenses WHERE license_id=?', [
			Uuid::bytes($this->license['id']),
		]);
		self::assertNull($license['period_starts_at']);
		self::assertSame(0, $this->rowCount('activations'));
		// Se aprueba tres días después, a las 18:30:15 UTC (12:30:15 en America/Monterrey).
		$this->clock->instant = new \DateTimeImmutable('2026-09-28T18:30:15.250000Z');
		$payload = $this->payload($this->approve($id));
		self::assertSame(
			[
				'issued_at' => '2026-09-28T18:30:15.250000Z',
				'period_starts_at' => '2026-09-28T18:30:15.250000Z',
				'expires_at' => '2026-10-28T18:30:15.250000Z',
				'updates_until' => '2026-10-28T18:30:15.250000Z',
			],
			array_intersect_key($payload, [
				'issued_at' => 1,
				'period_starts_at' => 1,
				'expires_at' => 1,
				'updates_until' => 1,
			]),
		);
		$license = $this->app->db->one('SELECT * FROM licenses WHERE license_id=?', [
			Uuid::bytes($this->license['id']),
		]);
		self::assertSame([28, '12:30:15.250000'], [(int) $license['anchor_day'], $license['anchor_local_time']]);
		$activation = $this->app->db->one('SELECT * FROM activations');
		self::assertSame(
			['2026-09-28 18:30:15.250000', '0.12.3', Uuid::bytes($this->device['id'])],
			[$activation['activated_at'], $activation['app_version'], $activation['server_id']],
		);
		// El original se conserva cifrado y sin cambios, byte por byte, con su código comercial dentro.
		$stored = $this->app->db->one('SELECT evidence_encrypted,projection_json FROM offline_requests');
		self::assertSame(
			$envelope,
			$this->app->crypto->decryptEvidence($stored['evidence_encrypted'], 'offline:v1:' . $id),
		);
		self::assertStringNotContainsString($this->license['secret'], $stored['projection_json']);
		self::assertStringNotContainsString($envelope, $stored['evidence_encrypted']);
	}

	public function testRequestsOfTheReplacedFormatAreRejected(): void
	{
		$sign = function (array $payload, string $prefix): string {
			$encoded = Protocol::encode(Protocol::json($payload));
			return Protocol::json([
				'schema_version' => '1.0',
				'payload_b64u' => $encoded,
				'signature_b64u' => Protocol::encode(
					sodium_crypto_sign_detached($prefix . $encoded, $this->device['secret']),
				),
			]);
		};
		$current = [
			'action' => 'activate',
			'request_id' => Uuid::create(),
			'created_at' => '2026-09-25T12:00:00Z',
			'product_id' => 'adel_pos',
			'server_id' => $this->device['id'],
			'installation_public_key' => $this->device['public'],
			'app_version' => '0.12.0',
		];
		$fingerprint = ['fingerprint_version' => '1', 'fingerprint_hash' => 'sha256:' . str_repeat('b', 64)];
		$previous = array_diff_key($current, ['server_id' => 1, 'app_version' => 1]) + [
			'installation_id' => $this->device['id'],
			...$fingerprint,
		];
		$cases = [
			'solicitud anterior completa' => [$sign($previous, "LICREQ-V1\n"), 400],
			'contenido anterior con el prefijo nuevo' => [$sign($previous, "ADELPOS-LICREQ-V1\n"), 400],
			'contenido nuevo con huella' => [$sign($current + $fingerprint, "ADELPOS-LICREQ-V1\n"), 400],
			'contenido nuevo sin versión de la aplicación' => [
				$sign(array_diff_key($current, ['app_version' => 1]), "ADELPOS-LICREQ-V1\n"),
				400,
			],
			'contenido nuevo firmado con el prefijo anterior' => [$sign($current, "LICREQ-V1\n"), 401],
			'otra versión del sobre' => [
				str_replace('"schema_version":"1.0"', '"schema_version":"2.0"', $sign($current, "ADELPOS-LICREQ-V1\n")),
				400,
			],
		];
		foreach ($cases as $label => [$envelope, $status]) {
			try {
				$this->import($envelope);
				self::fail('Accepted: ' . $label);
			} catch (Problem $error) {
				self::assertSame($status, $error->status, $label);
			}
		}
		// Nada quedó reservado ni guardado.
		self::assertSame(0, $this->rowCount('license_requests'));
		self::assertSame(0, $this->rowCount('offline_requests'));
		// La misma solicitud, con el formato del contrato, sí se recibe.
		self::assertNotSame('', $this->import($sign($current, "ADELPOS-LICREQ-V1\n")));
	}

	public function testRenewAndDeactivateOfARevokedLicenseDeliverItsClosingRevision(): void
	{
		$active = $this->activate();
		$this->app->licenses->change($this->actor, Uuid::create(), $this->license['id'], 'revoke', [
			'version' => 1,
			'reason' => 'Revocación comercial',
		]);
		self::assertSame(2, $this->rowCount('license_revisions'));
		$version = (int) $this->app->read->license($this->license['id'])['row_version'];
		// La computadora, sin internet, todavía no sabe que su licencia fue revocada.
		foreach (['renew' => [], 'deactivate' => ['accept_offline_limit' => '1']] as $action => $extra) {
			$closing = $this->payload(
				$this->approve($this->import($this->envelope($action, $active)), ['version' => $version] + $extra),
			);
			self::assertSame(
				[$active['activation_id'], 'revoked', 'revoked', 2],
				[
					$closing['activation_id'],
					$closing['license_status'],
					$closing['status_reason'],
					$closing['license_revision'],
				],
				$action,
			);
		}
		// No se firmó nada más y la activación conserva su estado.
		self::assertSame(2, $this->rowCount('license_revisions'));
		self::assertSame('revoked', $this->app->db->one('SELECT state FROM activations')['state']);
		// Activar una licencia revocada sigue sin ser posible.
		$this->device = $this->device();
		try {
			$this->approve($this->import($this->envelope()), ['version' => $version]);
			self::fail('Revoked license activated');
		} catch (Problem $error) {
			self::assertSame('La licencia no está disponible para este producto.', $error->getMessage());
		}
	}

	public function testForcedTransferIsAtomicAndRecordsBothInstallations(): void
	{
		$old = $this->activate();
		$this->device = $this->device();
		$id = $this->import($this->envelope());
		try {
			$this->approve($id);
			self::fail('Occupied slot accepted');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$input = ['force_activation_id' => $old['activation_id'], 'accept_offline_limit' => '1'];
		$new = $this->payload($this->approve($id, $input));
		self::assertSame(3, $new['license_revision']);
		self::assertNotSame($old['activation_id'], $new['activation_id']);
		// La computadora anterior queda como transferida, y su revisión de cierre lo dice.
		self::assertSame(
			['2', 'revoked', 'transferred'],
			array_map(
				'strval',
				array_values(
					$this->app->db->one(
						'SELECT license_revision,license_status,status_reason
							FROM license_revisions
							WHERE activation_id=?
							ORDER BY license_revision DESC LIMIT 1',
						[Uuid::bytes($old['activation_id'])],
					),
				),
			),
		);
		self::assertSame($old['period_starts_at'], $new['period_starts_at']);
		self::assertSame(
			'transferred',
			$this->app->db
				->execute('SELECT state FROM activations WHERE activation_id=?', [Uuid::bytes($old['activation_id'])])
				->fetchColumn(),
		);
		$transfer = $this->app->db->one('SELECT * FROM license_transfers');
		self::assertSame(Uuid::bytes($old['activation_id']), $transfer['outgoing_activation_id']);
		self::assertSame(Uuid::bytes($new['activation_id']), $transfer['incoming_activation_id']);
		// La transferencia sin internet que autoriza el propietario no queda pendiente (etapa 4, Fase 1).
		self::assertSame(
			['owner', 'confirmed', 'owner', $transfer['created_at'], null],
			[
				$transfer['via'],
				$transfer['state'],
				$transfer['confirmation'],
				$transfer['confirmed_at'],
				$transfer['pending_license_id'],
			],
		);
		self::assertSame('issued', $this->app->read->license($this->license['id'])['commercial_status']);
	}

	public function testFailureAfterOutgoingRetirementRollsBackWholeTransfer(): void
	{
		$old = $this->activate();
		$this->device = $this->device();
		$id = $this->import($this->envelope());
		// Fail publication of the destination after the old terminal JWS has been persisted.
		$this->fixture->owner->execute(
			"CREATE TRIGGER test_fail_destination BEFORE INSERT ON license_revisions
				FOR EACH ROW
				BEGIN
					IF NEW.license_revision=3 THEN
						SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated injected failure';
					END IF;
				END",
		);
		try {
			$this->approve($id, ['force_activation_id' => $old['activation_id'], 'accept_offline_limit' => '1']);
			self::fail('Failure not injected');
		} catch (\PDOException) {
			self::assertSame(1, $this->rowCount('license_revisions'));
		}
		self::assertSame('active', $this->app->db->execute('SELECT state FROM activations')->fetchColumn());
		self::assertSame(1, $this->rowCount('activations'));
		self::assertSame(0, $this->rowCount('license_transfers'));
		self::assertSame('pending', $this->app->read->offline($id)['status']);
		$this->fixture->owner->execute('DROP TRIGGER test_fail_destination');
		self::assertSame(
			3,
			$this->payload(
				$this->approve($id, ['force_activation_id' => $old['activation_id'], 'accept_offline_limit' => '1']),
			)['license_revision'],
		);
	}

	public function testEvidenceTamperingAndSqlMutationAreRejected(): void
	{
		$id = $this->import($this->envelope());
		foreach (
			['offline_requests' => 'id', 'offline_decisions' => 'offline_id', 'license_transfers' => 'id']
			as $table => $key
		) {
			foreach (
				[
					'UPDATE ' . $table . ' SET ' . $key . '=' . $key . ' WHERE 1=0',
					'DELETE FROM ' . $table . ' WHERE 1=0',
				]
				as $sql
			) {
				try {
					$this->app->db->execute($sql);
					self::fail('Immutable data writable');
				} catch (\PDOException $error) {
					self::assertContains((int) $error->errorInfo[1], [1142, 1143]);
				}
			}
		}
		$this->fixture->owner->execute('UPDATE offline_requests SET evidence_encrypted=?', [
			base64_encode(str_repeat('x', 80)),
		]);
		try {
			$this->approve($id);
			self::fail('Tampered evidence accepted');
		} catch (\RuntimeException $error) {
			self::assertSame('Evidence authentication failed.', $error->getMessage());
		}
		self::assertSame(0, $this->rowCount('activations'));
		self::assertSame(0, $this->rowCount('offline_decisions'));
	}

	public function testViewerAndOperatorCannotForceTransferAndAcknowledgementIsRequired(): void
	{
		$old = $this->activate();
		$this->device = $this->device();
		$id = $this->import($this->envelope());
		$operator = $this->app->admins->create($this->actor, Uuid::create(), [
			'login' => 'operator@example.test',
			'name' => 'Operator',
			'role' => 'operator',
			'new_password' => 'Fixture-Operator-Only-2026!',
		]);
		$viewer = $this->app->admins->create($this->actor, Uuid::create(), [
			'login' => 'viewer@example.test',
			'name' => 'Viewer',
			'role' => 'viewer',
			'new_password' => 'Fixture-Viewer-Only-2026!',
		]);
		$actor = new Actor($operator['id'], 'operator');
		try {
			$this->app->offline->decide(
				$actor,
				Uuid::create(),
				$id,
				'approved',
				$this->input(['force_activation_id' => $old['activation_id'], 'accept_offline_limit' => '1']),
			);
			self::fail('Operator forced transfer');
		} catch (Problem $error) {
			self::assertSame(403, $error->status);
		}
		try {
			$this->app->offline->import(new Actor($viewer['id'], 'viewer'), Uuid::create(), $this->envelope());
			self::fail('Viewer imported');
		} catch (Problem $error) {
			self::assertSame(403, $error->status);
		}
		try {
			$this->approve($id, ['force_activation_id' => $old['activation_id']]);
			self::fail('Missing acknowledgement accepted');
		} catch (Problem $error) {
			self::assertSame(422, $error->status);
		}
		self::assertSame(1, $this->rowCount('activations'));
		// Current database role wins even if an old Actor claims superadmin.
		$this->expectException(Problem::class);
		$this->app->offline->decide(
			new Actor($operator['id'], 'superadmin'),
			Uuid::create(),
			$id,
			'approved',
			$this->input(['force_activation_id' => $old['activation_id'], 'accept_offline_limit' => '1']),
		);
	}

	public function testOnlineAndOfflineShareRequestIdNamespaceInBothDirections(): void
	{
		$envelope = $this->envelope();
		$requestId = OfflineRequest::verify($envelope)['payload']['request_id'];
		$id = $this->import($envelope);
		foreach ([false, true] as $completed) {
			if ($completed) {
				$this->approve($id);
			}
			try {
				$this->app->activations->execute('activate', $this->onlineRequest($requestId));
				self::fail('Cross-channel ID accepted');
			} catch (ApiProblem $error) {
				self::assertSame(['REQUEST_CONFLICT', 409], [$error->errorCode, $error->status]);
			}
		}
		// Otra computadora intenta activar en línea la licencia ocupada: negativa que queda guardada.
		$this->device = $this->device();
		$input = $this->onlineRequest();
		self::assertSame(409, $this->app->activations->execute('activate', $input)->status);
		try {
			$this->import($this->envelope(changes: ['request_id' => $input['request_id']]));
			self::fail('Online ID imported');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		self::assertSame(1, $this->rowCount('activations'));
		self::assertSame(1, $this->rowCount('offline_requests'));
	}

	public function testForcedReleaseKeepsTerminalRevisionAvailableToAuthenticatedRefresh(): void
	{
		$old = $this->activate();
		$input = [
			'activation_id' => $old['activation_id'],
			'version' => 1,
			'reason' => 'Unrecoverable equipment',
			'accept_offline_limit' => '1',
		];
		$operation = Uuid::create();
		$this->app->offline->release($this->actor, $operation, $this->license['id'], $input);
		self::assertTrue(
			$this->app->offline->release($this->actor, $operation, $this->license['id'], $input)['replayed'],
		);
		$response = $this->app->activations->execute(
			'refresh',
			$this->onlineRequest(null, 'refresh', $old['activation_id']),
		);
		self::assertSame(200, $response->status);
		$jws = json_decode($response->body, true)['license_jws'];
		$payload = json_decode(
			sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
			true,
		);
		self::assertSame('revoked', $payload['license_status']);
		// Retirar la licencia de una computadora desde el panel se firma como transferencia, aunque no haya destino.
		self::assertSame('transferred', $payload['status_reason']);
		self::assertSame(2, $payload['license_revision']);
		self::assertNull(
			$this->app->db->one('SELECT incoming_activation_id FROM license_transfers')['incoming_activation_id'],
		);
		// Recuperar la plaza es una transferencia del propietario: nace confirmada (etapa 4, Fase 1).
		self::assertSame(
			['owner', 'confirmed', 'owner', Uuid::bytes($this->actor->id), 'Unrecoverable equipment', 1],
			array_values(
				$this->app->db->one(
					'SELECT via,state,confirmation,admin_id,reason,offline_limit_accepted FROM license_transfers',
				),
			),
		);
		self::assertSame('issued', $this->app->read->license($this->license['id'])['commercial_status']);
	}

	public function testCommercialChangesRequireReviewAndRevocationBlocksApproval(): void
	{
		$id = $this->import($this->envelope());
		$stale = $this->input();
		$this->app->licenses->change($this->actor, Uuid::create(), $this->license['id'], 'features', [
			'version' => 1,
			'features' => ['prueba_d'],
			'reason' => 'Commercial change',
		]);
		try {
			$this->app->offline->decide($this->actor, Uuid::create(), $id, 'approved', $stale);
			self::fail('Stale terms approved');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$this->app->licenses->change($this->actor, Uuid::create(), $this->license['id'], 'revoke', [
			'version' => 2,
			'reason' => 'Commercial revocation',
		]);
		try {
			$this->approve($id);
			self::fail('Revoked license approved');
		} catch (Problem $error) {
			self::assertSame(422, $error->status);
		}
		self::assertSame('pending', $this->app->read->offline($id)['status']);
		self::assertSame(0, $this->rowCount('activations'));
	}

	public function testMissingSignerAndAuditFailureLeaveRequestPending(): void
	{
		$id = $this->import($this->envelope());
		$key = $this->app->db->one("SELECT private_ref FROM signing_keys WHERE kid='offline-test'");
		$path = $this->fixture->values['SIGNING_KEY_DIR'] . '/' . $key['private_ref'];
		rename($path, $path . '.unavailable');
		try {
			$this->approve($id);
			self::fail('Missing signer accepted');
		} catch (\RuntimeException) {
			self::assertSame(0, $this->rowCount('license_revisions'));
		} finally {
			rename($path . '.unavailable', $path);
		}
		$this->fixture->owner->execute(
			"CREATE TRIGGER test_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000'
				SET MESSAGE_TEXT='Isolated audit failure'",
		);
		try {
			$this->approve($id);
			self::fail('Missing audit accepted');
		} catch (\PDOException) {
			self::assertSame(0, $this->rowCount('activations'));
		}
		self::assertSame('pending', $this->app->read->offline($id)['status']);
		self::assertSame(0, $this->rowCount('offline_decisions'));
		$this->fixture->owner->execute('DROP TRIGGER test_fail_audit');
		self::assertSame(1, $this->payload($this->approve($id))['license_revision']);
	}

	public function testConcurrentApprovalsCannotApplyTwice(): void
	{
		$this->race(false);
	}
	public function testOnlineAndOfflineRaceCannotOccupyTwoSlots(): void
	{
		$this->race(true);
	}
	private function race(bool $online): void
	{
		$id = $this->import($this->envelope());
		$job = [
			'kind' => 'offline',
			'config' => $this->fixture->values,
			'actor' => $this->actor->id,
			'operation' => Uuid::create(),
			'id' => $id,
			'input' => $this->input(),
		];
		if ($online) {
			// La activación en línea llega desde otra computadora: solo una de las dos puede quedarse con la licencia.
			$this->device = $this->device();
		}
		$jobs = [
			$job,
			$online
				? ['kind' => 'online', 'config' => $this->fixture->values, 'input' => $this->onlineRequest()]
				: array_replace($job, ['operation' => Uuid::create()]),
		];
		$owner = $this->fixture->owner;
		$files = [];
		$workers = [];
		$owner->pdo->beginTransaction();
		$owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [
			Uuid::bytes($this->license['id']),
		]);
		try {
			foreach ($jobs as $job) {
				$file = tempnam(sys_get_temp_dir(), 'adelpos-license-offline-race-');
				chmod($file, 0600);
				$files[] = $file;
				file_put_contents($file, Protocol::json($job));
				$process = proc_open(
					[PHP_BINARY, dirname(__DIR__) . '/offline-worker.php', $file],
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
			self::assertSame([200, 409], $statuses);
			self::assertSame(1, $this->rowCount('activations'));
			self::assertSame(1, $this->rowCount('license_revisions'));
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
