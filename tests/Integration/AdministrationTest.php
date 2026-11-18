<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Infrastructure\Migrator;
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class AdministrationTest extends TestCase
{
	private MySqlFixture $fixture;
	private FrozenClock $clock;
	private Actor $actor;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Set TEST_MYSQL_DSN for an isolated MySQL 8 instance.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$id = $this->fixture->app->auth->bootstrap(
			'admin@example.test',
			'Administrador de prueba',
			'Fixture-Password-Only-2026!',
		);
		$this->actor = new Actor($id, 'superadmin', 'Administrador');
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function customer(): string
	{
		return $this->fixture->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Cliente de prueba',
			'legal_name' => '',
			'contact_name' => 'Contacto',
			'email' => 'contacto@example.test',
			'phone' => '',
			'state' => 'active',
		])['id'];
	}

	private function issueInput(string $type = 'perpetual'): array
	{
		return [
			'customer_id' => $this->customer(),
			'product_id' => 'adel_pos',
			...MySqlFixture::terms($type),
			'features' => ['prueba_b', 'prueba_c', 'prueba_a', 'prueba_e'],
			'reason' => 'Compra de prueba',
			'reference' => 'TEST-001',
		];
	}

	public function testMigrationIsRepeatableAndBootstrapIsSingleUse(): void
	{
		self::assertSame(
			[],
			(new Migrator($this->fixture->owner, dirname(__DIR__, 2) . '/database/migrations'))->migrate(),
		);
		self::assertSame('AD-EL POS', $this->fixture->app->read->product('adel_pos')['display_name']);
		$this->expectException(Problem::class);
		$this->fixture->app->auth->bootstrap('other@example.test', 'Otro', 'Fixture-Password-Only-2026!');
	}

	public function testOnlyAdelPosLicensesCanBeIssued(): void
	{
		$app = $this->fixture->app;
		// El catálogo admite otros productos, pero el contrato fija el producto de la licencia firmada.
		$app->catalog->saveProduct($this->actor, Uuid::create(), null, [
			'product_id' => 'otro_producto',
			'name' => 'Otro producto',
			'description' => 'Producto sintético',
			'state' => 'active',
		]);
		try {
			$app->licenses->issue(
				$this->actor,
				Uuid::create(),
				array_replace($this->issueInput(), ['product_id' => 'otro_producto', 'features' => []]),
			);
			self::fail('Issued a license of another product');
		} catch (Problem $error) {
			self::assertSame('Este servidor solo emite licencias de AD-EL POS.', $error->getMessage());
		}
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
	}

	public function testPerpetualIsIssuedWithoutDatesAndKeyIsNeverArchived(): void
	{
		$app = $this->fixture->app;
		$result = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput());
		$license = $app->read->license($result['id']);
		// No dates until the license is activated (contract, section 5.2).
		foreach (['period_starts_at', 'expires_at', 'anchor_day', 'anchor_local_time', 'updates_until'] as $field) {
			self::assertNull($license[$field], $field);
		}
		self::assertSame(6, (int) $license['updates_months']);
		self::assertSame(0, (int) $license['grace_days']);
		self::assertSame(0, (int) $license['revision_counter']);
		self::assertMatchesRegularExpression('/\A[2-9A-HJKMNP-Z]{3}(?:-[2-9A-HJKMNP-Z]{4}){3}\z/', $result['secret']);
		$snapshot = json_decode($license['history'][0]['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('TEST-001', $snapshot['commercial_reference']);
		self::assertSame(
			['perpetual', 6, 'elemental', 3],
			[$snapshot['license_type'], $snapshot['updates_months'], $snapshot['plan_id'], $snapshot['cash_registers']],
		);
		$stored = $app->db->one('SELECT * FROM license_credentials WHERE license_id=?', [Uuid::bytes($result['id'])]);
		self::assertSame($app->crypto->digest('CREDENTIAL_KEY', $result['secret']), $stored['key_digest']);
		foreach (
			[
				'admin_operations' => 'result_json',
				'license_changes' => 'snapshot_json',
				'audit_events' => 'payload_json',
			]
			as $table => $column
		) {
			foreach ($app->db->all("SELECT $column AS content FROM $table") as $row) {
				self::assertStringNotContainsString($result['secret'], $row['content']);
			}
		}
		self::assertGreaterThanOrEqual(3, $app->audit->verify());
	}

	public function testEveryLicenseTypeIsIssuedWithItsOwnTermsAndTheCountsOfItsPlan(): void
	{
		$app = $this->fixture->app;
		// Expected: type, billing period, trial duration, updates months, grace days, plan, plan name, custom,
		// cash registers, order takers, kitchen displays.
		$cases = [
			'perpetual, default updates' => [
				['license_type' => 'perpetual', 'plan_id' => 'basico'],
				['perpetual', null, null, 6, 0, 'basico', 'Plan básico', 0, 1, 0, 0],
			],
			'perpetual without updates' => [
				['license_type' => 'perpetual', 'updates_months' => '0', 'plan_id' => 'premium'],
				['perpetual', null, null, 0, 0, 'premium', 'Plan premium', 0, 5, 10, 5],
			],
			'perpetual, 6 months' => [
				['license_type' => 'perpetual', 'updates_months' => '6', 'plan_id' => 'elemental'],
				['perpetual', null, null, 6, 0, 'elemental', 'Plan elemental', 0, 3, 3, 3],
			],
			'perpetual, 12 months' => [
				['license_type' => 'perpetual', 'updates_months' => 12, 'plan_id' => 'elemental'],
				['perpetual', null, null, 12, 0, 'elemental', 'Plan elemental', 0, 3, 3, 3],
			],
			'monthly subscription' => [
				['license_type' => 'subscription', 'billing_period' => 'monthly', 'plan_id' => 'basico'],
				['subscription', 'monthly', null, null, 5, 'basico', 'Plan básico', 0, 1, 0, 0],
			],
			'quarterly subscription' => [
				['license_type' => 'subscription', 'billing_period' => 'quarterly', 'plan_id' => 'elemental'],
				['subscription', 'quarterly', null, null, 5, 'elemental', 'Plan elemental', 0, 3, 3, 3],
			],
			'semiannual subscription' => [
				['license_type' => 'subscription', 'billing_period' => 'semiannual', 'plan_id' => 'premium'],
				['subscription', 'semiannual', null, null, 5, 'premium', 'Plan premium', 0, 5, 10, 5],
			],
			'annual subscription' => [
				['license_type' => 'subscription', 'billing_period' => 'annual', 'plan_id' => 'premium'],
				['subscription', 'annual', null, null, 5, 'premium', 'Plan premium', 0, 5, 10, 5],
			],
			'15-day trial' => [
				['license_type' => 'trial', 'trial_duration' => '15d', 'plan_id' => 'premium'],
				['trial', null, '15d', null, 0, 'premium', 'Plan premium', 0, 5, 10, 5],
			],
			'1-month trial' => [
				['license_type' => 'trial', 'trial_duration' => '1m', 'plan_id' => 'elemental'],
				['trial', null, '1m', null, 0, 'elemental', 'Plan elemental', 0, 3, 3, 3],
			],
			'3-month trial' => [
				['license_type' => 'trial', 'trial_duration' => '3m', 'plan_id' => 'basico'],
				['trial', null, '3m', null, 0, 'basico', 'Plan básico', 0, 1, 0, 0],
			],
		];
		$customer = $this->customer();
		foreach ($cases as $name => [$terms, $expected]) {
			$issued = $app->licenses->issue(
				$this->actor,
				Uuid::create(),
				['customer_id' => $customer, 'product_id' => 'adel_pos', 'amount' => '0'] + $terms,
			);
			$row = $this->fixture->owner->one(
				'SELECT license_type,billing_period,trial_duration,updates_months,grace_days,
						plan_id,plan_name,plan_custom,cash_registers,order_takers,kitchen_displays,
						period_starts_at,expires_at,anchor_day,anchor_local_time,updates_until
					FROM licenses
					WHERE license_id=?',
				[Uuid::bytes($issued['id'])],
			);
			self::assertSame(
				$expected,
				array_map(
					static fn($value) => is_numeric($value) ? (int) $value : $value,
					array_slice(array_values($row), 0, 11),
				),
				$name,
			);
			self::assertSame(
				[null, null, null, null, null],
				array_slice(array_values($row), 11),
				$name . ': starts when activated',
			);
			self::assertMatchesRegularExpression(
				'/\A[2-9A-HJKMNP-Z]{3}(?:-[2-9A-HJKMNP-Z]{4}){3}\z/',
				$issued['secret'],
				$name,
			);
		}
		self::assertSame(count($cases), (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
		// Cada licencia tiene su pago de alta, y es el que paga su primer periodo, que empieza al activar.
		self::assertSame(
			count($cases),
			(int) $app->db
				->execute(
					"SELECT COUNT(*)
						FROM licenses l
						JOIN license_payments p ON p.payment_id=l.period_payment_id AND p.license_id=l.license_id
						WHERE p.concept='issue' AND p.amount=0 AND p.covers_from IS NULL",
				)
				->fetchColumn(),
		);
	}

	public function testChangingAnyCountOfThePlanMakesTheLicenseCustom(): void
	{
		$app = $this->fixture->app;
		$base = [
			'customer_id' => $this->customer(),
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('subscription', 'elemental'),
		];
		$counts = fn(array $issued): array => array_map(
			'intval',
			array_values(
				$this->fixture->owner->one(
					'SELECT plan_custom,cash_registers,order_takers,kitchen_displays FROM licenses WHERE license_id=?',
					[Uuid::bytes($issued['id'])],
				),
			),
		);
		// The numbers of the plan written out explicitly are still the plan.
		self::assertSame(
			[0, 3, 3, 3],
			$counts(
				$app->licenses->issue(
					$this->actor,
					Uuid::create(),
					$base + ['cash_registers' => '3', 'order_takers' => '03', 'kitchen_displays' => 3],
				),
			),
		);
		$custom = $app->licenses->issue($this->actor, Uuid::create(), $base + ['order_takers' => '7']);
		self::assertSame([1, 3, 7, 3], $counts($custom));
		self::assertSame(
			[1, 0, 0, 999],
			$counts(
				$app->licenses->issue(
					$this->actor,
					Uuid::create(),
					$base + ['cash_registers' => 0, 'order_takers' => '0', 'kitchen_displays' => '999'],
				),
			),
		);
		// A custom license keeps the plan it started from, and its issuance record keeps the signed numbers.
		$license = $app->read->license($custom['id']);
		self::assertSame(['elemental', 'Plan elemental'], [$license['plan_id'], $license['plan_name']]);
		$snapshot = json_decode($license['history'][0]['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(
			['subscription', 'monthly', 1, 7],
			[
				$snapshot['license_type'],
				$snapshot['billing_period'],
				(int) $snapshot['plan_custom'],
				(int) $snapshot['order_takers'],
			],
		);
		// Editing the catalog later does not change a license that was already issued.
		$this->fixture->owner->execute(
			"UPDATE plans SET display_name='Plan renombrado',cash_registers=9 WHERE plan_id='elemental'",
		);
		self::assertSame([1, 3, 7, 3], $counts($custom));
		self::assertSame('Plan elemental', $app->read->license($custom['id'])['plan_name']);
	}

	public function testIssuanceRejectsTermsOfAnotherTypeUnknownPlansAndCountsOutOfRange(): void
	{
		$app = $this->fixture->app;
		$base = [
			'customer_id' => $this->customer(),
			'product_id' => 'adel_pos',
			'license_type' => 'perpetual',
			'plan_id' => 'basico',
		];
		$invalid = [
			'no type' => ['license_type' => ''],
			'unknown type' => ['license_type' => 'lifetime'],
			'subscription without period' => ['license_type' => 'subscription'],
			'unknown period' => ['license_type' => 'subscription', 'billing_period' => 'weekly'],
			'trial without duration' => ['license_type' => 'trial'],
			'unknown duration' => ['license_type' => 'trial', 'trial_duration' => '30d'],
			'updates outside 0, 6 or 12' => ['updates_months' => '3'],
			'negative updates' => ['updates_months' => '-6'],
			'billing period on a perpetual' => ['billing_period' => 'monthly'],
			'trial duration on a perpetual' => ['trial_duration' => '15d'],
			'updates months on a subscription' => [
				'license_type' => 'subscription',
				'billing_period' => 'monthly',
				'updates_months' => '6',
			],
			'trial duration on a subscription' => [
				'license_type' => 'subscription',
				'billing_period' => 'monthly',
				'trial_duration' => '15d',
			],
			'billing period on a trial' => [
				'license_type' => 'trial',
				'trial_duration' => '1m',
				'billing_period' => 'annual',
			],
			'updates months on a trial' => [
				'license_type' => 'trial',
				'trial_duration' => '1m',
				'updates_months' => '0',
			],
			'terms sent as a list' => ['updates_months' => ['6']],
			'no plan' => ['plan_id' => ''],
			'unknown plan' => ['plan_id' => 'inexistente'],
			'negative count' => ['cash_registers' => '-1'],
			'count above 999' => ['order_takers' => '1000'],
			'fractional count' => ['kitchen_displays' => '1.5'],
			'count in words' => ['kitchen_displays' => 'diez'],
			'count sent as a list' => ['cash_registers' => ['1']],
		];
		foreach ($invalid as $name => $changes) {
			try {
				$app->licenses->issue($this->actor, Uuid::create(), array_replace($base, $changes));
				self::fail('Accepted: ' . $name);
			} catch (Problem $error) {
				self::assertContains($error->status, [422], $name);
				self::assertNotSame('', $error->getMessage());
			}
		}
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
		// The messages name the field in the administrator's words.
		try {
			$app->licenses->issue($this->actor, Uuid::create(), array_replace($base, ['order_takers' => '1000']));
		} catch (Problem $error) {
			self::assertSame('Comanderos debe ser un número entero entre 0 y 999.', $error->getMessage());
		}
		try {
			$app->licenses->issue($this->actor, Uuid::create(), array_replace($base, ['billing_period' => 'monthly']));
		} catch (Problem $error) {
			self::assertSame('El periodo de cobro solo aplica a las suscripciones.', $error->getMessage());
		}
	}

	public function testCustomerTimeZoneDefaultsToMonterreyAndMustBeAnIanaZone(): void
	{
		$app = $this->fixture->app;
		$base = [
			'name' => 'Cliente de prueba',
			'legal_name' => '',
			'contact_name' => 'Contacto',
			'email' => '',
			'phone' => '',
			'state' => 'active',
		];
		self::assertSame('America/Monterrey', $app->read->customer($this->customer())['time_zone']);
		$id = $app->catalog->saveCustomer(
			$this->actor,
			Uuid::create(),
			null,
			$base + ['time_zone' => 'America/Tijuana'],
		)['id'];
		self::assertSame('America/Tijuana', $app->read->customer($id)['time_zone']);
		// Saving without a zone keeps the one the customer had.
		$app->catalog->saveCustomer($this->actor, Uuid::create(), $id, $base + ['version' => 1]);
		self::assertSame('America/Tijuana', $app->read->customer($id)['time_zone']);
		$app->catalog->saveCustomer($this->actor, Uuid::create(), $id, $base + ['version' => 2, 'time_zone' => 'UTC']);
		self::assertSame('UTC', $app->read->customer($id)['time_zone']);
		foreach (
			['Monterrey', 'America/Monterey', 'america/monterrey', 'US/Pacific', 'GMT-6', '-06:00', 'CST']
			as $zone
		) {
			try {
				$app->catalog->saveCustomer($this->actor, Uuid::create(), null, $base + ['time_zone' => $zone]);
				self::fail('Accepted time zone: ' . $zone);
			} catch (Problem $error) {
				self::assertStringContainsString('zona horaria', $error->getMessage());
			}
		}
		self::assertSame(2, (int) $app->db->execute('SELECT COUNT(*) FROM customers')->fetchColumn());
		try {
			$this->fixture->owner->execute("UPDATE customers SET time_zone=''");
			self::fail('Empty time zone stored');
		} catch (\PDOException $error) {
			self::assertSame(3819, (int) $error->errorInfo[1]);
		}
	}

	public function testDuplicateIssuanceAndConflictingRequest(): void
	{
		$app = $this->fixture->app;
		$operation = Uuid::create();
		$input = $this->issueInput();
		$first = $app->licenses->issue($this->actor, $operation, $input);
		$retry = $app->licenses->issue($this->actor, $operation, $input);
		self::assertSame($first['id'], $retry['id']);
		self::assertTrue($retry['replayed']);
		self::assertArrayNotHasKey('secret', $retry);
		self::assertSame(1, (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
		$input['reason'] = 'Altered';
		$this->expectException(Problem::class);
		$app->licenses->issue($this->actor, $operation, $input);
	}

	public function testMfaEnrollmentReplayRecoveryAndSessionRotation(): void
	{
		$app = $this->fixture->app;
		$anonymous = $app->sessions->create();
		$pending = $app->auth->login('admin@example.test', 'Fixture-Password-Only-2026!', '127.0.0.1', $anonymous);
		self::assertNull($app->sessions->load($anonymous));
		$record = $app->sessions->load($pending);
		$secret = $app->crypto->decrypt($record['enrollment_secret'], 'mfa:' . $this->actor->id);
		$code = \OTPHP\TOTP::createFromSecret($secret, $this->clock)->at($this->clock->now()->getTimestamp());
		$result = $app->auth->verifyMfa($pending, $code, '127.0.0.1');
		self::assertCount(8, $result['recovery_codes']);
		self::assertNull($app->sessions->load($pending));
		self::assertSame('authenticated', $app->sessions->load($result['token'])['stage']);
		$pending2 = $app->auth->login(
			'admin@example.test',
			'Fixture-Password-Only-2026!',
			'127.0.0.1',
			$app->sessions->create(),
		);
		try {
			$app->auth->verifyMfa($pending2, $code, '127.0.0.1');
			self::fail('Reused TOTP accepted');
		} catch (Problem $error) {
			self::assertSame(401, $error->status);
		}
		$recovery = $result['recovery_codes'][0];
		$app->auth->verifyMfa($pending2, $recovery, '127.0.0.1');
		$pending3 = $app->auth->login(
			'admin@example.test',
			'Fixture-Password-Only-2026!',
			'127.0.0.1',
			$app->sessions->create(),
		);
		$this->expectException(Problem::class);
		$app->auth->verifyMfa($pending3, $recovery, '127.0.0.1');
	}

	public function testTheCommercialCycleLeftTheGenericChangeOfTheLicense(): void
	{
		$app = $this->fixture->app;
		$subscription = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput('subscription'));
		$perpetual = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput());
		$trial = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput('trial'));
		// La renovación y el mantenimiento del motor anterior ya no son cambios genéricos de la licencia: los
		// registra CommercialService, con su pago y sus fechas (etapa 3, fases 3 y 6).
		foreach ([$subscription, $perpetual, $trial] as $issued) {
			foreach (['renew', 'maintenance'] as $action) {
				try {
					$app->licenses->change($this->actor, Uuid::create(), $issued['id'], $action, [
						'version' => 1,
						'until' => '2027-09-25T12:00',
						'reason' => 'Compra',
						'reference' => 'TEST-002',
					]);
					self::fail('The generic change accepted: ' . $action);
				} catch (Problem $error) {
					self::assertSame(404, $error->status, $action);
				}
			}
			$license = $app->read->license($issued['id']);
			self::assertSame(1, (int) $license['row_version']);
			self::assertCount(1, $license['history']);
			self::assertNull($license['expires_at']);
			self::assertNull($license['updates_until']);
		}
		// Solo los tres pagos de alta: ninguna renovación ni compra de mantenimiento.
		self::assertSame(
			[['issue', 3]],
			array_map(
				static fn(array $row): array => [$row['concept'], (int) $row['total']],
				$app->db->all('SELECT concept,COUNT(*) AS total FROM license_payments GROUP BY concept'),
			),
		);
		self::assertSame(
			0,
			(int) $app->db
				->execute(
					"SELECT COUNT(*) FROM admin_operations WHERE action IN ('license.renew','license.maintenance')",
				)
				->fetchColumn(),
		);
		self::assertSame(
			0,
			(int) $app->db
				->execute(
					"SELECT COUNT(*) FROM audit_events WHERE operation IN ('license.renew','license.maintenance')",
				)
				->fetchColumn(),
		);
	}

	public function testInvalidDependenciesRollbackWithoutKeyOrLicense(): void
	{
		$app = $this->fixture->app;
		$input = $this->issueInput();
		$input['features'] = ['prueba_e'];
		try {
			$app->licenses->issue($this->actor, Uuid::create(), $input);
			self::fail('Invalid rights accepted');
		} catch (Problem) {
		}
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
		self::assertSame(2, $app->audit->verify());
	}

	public function testStaleRightsFormAndActivatedRightsAreRejected(): void
	{
		$app = $this->fixture->app;
		$issued = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput());
		try {
			$app->licenses->change($this->actor, Uuid::create(), $issued['id'], 'features', [
				'version' => 0,
				'features' => [],
				'reason' => 'Old form',
			]);
			self::fail('Stale accepted');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		$this->fixture->owner->execute('UPDATE licenses SET revision_counter=1 WHERE license_id=?', [
			Uuid::bytes($issued['id']),
		]);
		$this->expectException(Problem::class);
		$app->licenses->change($this->actor, Uuid::create(), $issued['id'], 'features', [
			'version' => 1,
			'features' => [],
			'reason' => 'Needs signed revision',
		]);
	}

	public function testViewerCannotMutateEvenWithForgedActorRole(): void
	{
		$app = $this->fixture->app;
		$user = $app->admins->create($this->actor, Uuid::create(), [
			'login' => 'viewer@example.test',
			'name' => 'Consulta',
			'role' => 'viewer',
			'new_password' => 'Fixture-Password-Only-2026!',
		]);
		$forged = new Actor($user['id'], 'superadmin');
		$input = $this->issueInput();
		$this->expectException(Problem::class);
		$app->licenses->issue($forged, Uuid::create(), $input);
	}

	public function testLastSuperadminCannotBeDisabled(): void
	{
		$this->expectException(Problem::class);
		$this->fixture->app->admins->update($this->actor, Uuid::create(), $this->actor->id, [
			'role' => 'viewer',
			'state' => 'disabled',
			'reason' => 'Test last admin',
		]);
	}

	public function testApplicationCannotEditAppendOnlyHistory(): void
	{
		foreach (
			[
				'UPDATE audit_events SET operation=operation WHERE 1=0',
				'DELETE FROM audit_events WHERE 1=0',
				'UPDATE license_changes SET action=action WHERE 1=0',
			]
			as $sql
		) {
			try {
				$this->fixture->app->db->execute($sql);
				self::fail('Privilege allowed: ' . $sql);
			} catch (\PDOException $error) {
				self::assertSame(1142, (int) $error->errorInfo[1]);
			}
		}
	}

	public function testAuditFailureRollsBackRightsCredentialAndOperation(): void
	{
		$app = $this->fixture->app;
		$input = $this->issueInput();
		$this->fixture->owner->execute('UPDATE audit_heads SET last_sequence=0');
		try {
			$app->licenses->issue($this->actor, Uuid::create(), $input);
			self::fail('Audit collision should fail');
		} catch (\PDOException) {
		}
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
	}

	public function testUniqueCredentialAndCommercialChecksExistInDatabase(): void
	{
		$app = $this->fixture->app;
		$issued = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput());
		try {
			$this->fixture->owner->execute('UPDATE licenses SET grace_days=15 WHERE license_id=?', [
				Uuid::bytes($issued['id']),
			]);
			self::fail('Invalid perpetual term accepted');
		} catch (\PDOException $error) {
			self::assertSame(3819, (int) $error->errorInfo[1]);
		}
		$credential = $app->db->one('SELECT * FROM license_credentials LIMIT 1');
		// Every required column is supplied, so only the single-active-credential rule can reject it.
		try {
			$this->fixture->owner->execute(
				'INSERT INTO license_credentials(credential_id,license_id,key_digest,state,
						created_at,credential_encrypted,code_format)
					VALUES (?,?,?,\'active\',?,?,\'3-4-4-4\')',
				[
					Uuid::bytes(Uuid::create()),
					$credential['license_id'],
					str_repeat('f', 64),
					$this->clock->sql(),
					$credential['credential_encrypted'],
				],
			);
			self::fail('Second active credential accepted');
		} catch (\PDOException $error) {
			self::assertSame(1062, (int) $error->errorInfo[1]);
		}
		try {
			$this->fixture->owner->execute(
				'INSERT INTO license_credentials(credential_id,license_id,key_digest,state,created_at)
					VALUES (?,?,?,\'retired\',?)',
				[Uuid::bytes(Uuid::create()), $credential['license_id'], str_repeat('e', 64), $this->clock->sql()],
			);
			self::fail('Credential without its protected copy accepted');
		} catch (\PDOException $error) {
			self::assertSame(1364, (int) $error->errorInfo[1]);
		}
	}

	public function testSearchCannotInjectSqlAndSnapshotIsConsistent(): void
	{
		$app = $this->fixture->app;
		$this->customer();
		$result = $app->panel->customers(['q' => "' OR 1=1 --"], 1);
		self::assertSame(0, $result['total']);
		// Los comodines de LIKE también se buscan como texto.
		self::assertSame(0, $app->panel->customers(['q' => '%'], 1)['total']);
		self::assertSame(1, $app->panel->customers([], 1)['total']);
	}

	public function testTwoProcessesSendingTheSameChangeCommitOnlyOnce(): void
	{
		$app = $this->fixture->app;
		$issued = $app->licenses->issue($this->actor, Uuid::create(), $this->issueInput('subscription'));
		$input = ['version' => 1, 'features' => ['prueba_b'], 'reason' => 'Concurrent change', 'reference' => 'RACE'];
		$file = tempnam(sys_get_temp_dir(), 'adelpos-license-race-');
		chmod($file, 0600);
		file_put_contents(
			$file,
			json_encode(
				[
					'config' => $this->fixture->values,
					'actor' => $this->actor->id,
					'operation' => Uuid::create(),
					'license' => $issued['id'],
					'action' => 'features',
					'input' => $input,
				],
				JSON_THROW_ON_ERROR,
			),
		);
		$workers = [];
		$this->fixture->owner->pdo->beginTransaction();
		$this->fixture->owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [
			Uuid::bytes($issued['id']),
		]);
		try {
			for ($index = 0; $index < 2; ++$index) {
				$process = proc_open(
					[PHP_BINARY, dirname(__DIR__) . '/race-worker.php', $file],
					[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
					$pipes,
				);
				self::assertIsResource($process);
				$workers[] = [$process, $pipes];
			}
			// Observe two real lock waits: the first worker on the license and
			// the duplicate on the operation row owned by the first worker.
			$deadline = microtime(true) + 10;
			do {
				$waiting = (int) $this->fixture->owner
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
			self::assertGreaterThanOrEqual(
				2,
				$waiting,
				'Both workers must reach database lock barriers before release.',
			);
			$this->fixture->owner->pdo->commit();
			$results = [];
			foreach ($workers as [$process, $pipes]) {
				$output = stream_get_contents($pipes[1]);
				$errors = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				self::assertSame(0, proc_close($process), $errors);
				$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
			}
			self::assertSame($results[0]['id'], $results[1]['id']);
			self::assertNotSame($results[0]['replayed'], $results[1]['replayed']);
			$license = $app->read->license($issued['id']);
			self::assertCount(2, $license['history']);
			self::assertSame(2, (int) $license['row_version']);
			self::assertSame(
				['prueba_b'],
				array_column(
					array_filter($license['features'], fn($feature) => (bool) $feature['enabled']),
					'capability_key',
				),
			);
		} finally {
			if ($this->fixture->owner->pdo->inTransaction()) {
				$this->fixture->owner->pdo->rollBack();
			}
			unlink($file);
		}
	}
}
