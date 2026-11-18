<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{CalendarPeriod, Problem, Uuid};
use AdelPos\License\Tests\{FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Mantenimiento de la perpetua y edición del periodo inicial (etapa 3, Fase 6; contrato §5.2 y §5.5,
 * decisión 4 del propietario y aclaración n.º 11 del contrato).
 */
final class MaintenanceTest extends TestCase
{
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
		// Las perpetuas se activan el 1 de marzo de 2027 a las 10:00 de Monterrey; con 6 meses, sus
		// actualizaciones terminan el 1 de septiembre.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-03-01T16:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('maintenance-test-key', 'license');
		$keys->activate('maintenance-test-key', 'Isolated test setup');
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
		$this->fixture->owner->execute("UPDATE plans SET price_maintenance='3000.00' WHERE plan_id='elemental'");
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function issue(string $type, array $input = []): array
	{
		return $this->app->licenses->issue(
			$this->actor,
			Uuid::create(),
			array_replace(
				['customer_id' => $this->customer, 'product_id' => 'adel_pos', ...MySqlFixture::terms($type)],
				$input,
			),
		);
	}

	/** @return array{0: string, 1: Installation} */
	private function activated(string $type, array $input = []): array
	{
		$issued = $this->issue($type, $input);
		$installation = new Installation($this->app);
		$installation->activate($issued['secret']);
		return [$issued['id'], $installation];
	}

	private function license(string $id): array
	{
		return $this->fixture->owner->one('SELECT * FROM licenses WHERE license_id=?', [Uuid::bytes($id)]);
	}

	private function maintenance(string $id, array $input = []): array
	{
		return $this->app->commercial->buyMaintenance(
			$this->actor,
			Uuid::create(),
			$id,
			$input + ['version' => (int) $this->license($id)['row_version'], 'amount' => '3000'],
		);
	}

	private function rejected(string $message, callable $operation): void
	{
		try {
			$operation();
			self::fail('Accepted: ' . $message);
		} catch (Problem $error) {
			self::assertStringContainsString($message, $error->getMessage());
		}
	}

	public function testMaintenanceExtendsTheUpdatesFromTheLaterOfThePaymentAndTheirCurrentEnd(): void
	{
		[$license, $installation] = $this->activated('perpetual');
		self::assertSame('2027-09-01T16:00:00.000000Z', $installation->refresh()['updates_until']);
		// Antes de que terminen: los 12 meses se suman al fin actual, con su ancla.
		$this->clock->instant = new \DateTimeImmutable('2027-08-10T20:45:00Z');
		$quote = $this->app->commercial->quoteMaintenance($license, []);
		self::assertSame([300000, false], [$quote['suggested'], $quote['dates']['from_payment']]);
		$result = $this->maintenance($license, ['reference' => 'MANT-2027']);
		self::assertSame(['2028-09-01T16:00:00.000000Z', 1], [$result['updates_until'], $result['revisions_signed']]);
		$content = $installation->refresh();
		self::assertSame(
			[2, $installation->activationId, null, '2028-09-01T16:00:00.000000Z'],
			[
				$content['license_revision'],
				$content['activation_id'],
				$content['expires_at'],
				$content['updates_until'],
			],
		);
		// Encadenado: la segunda compra se suma al fin nuevo.
		$this->clock->instant = new \DateTimeImmutable('2027-12-01T16:00:00Z');
		self::assertSame('2029-09-01T16:00:00.000000Z', $this->maintenance($license)['updates_until']);
		// El pago es de mantenimiento, con el plazo de 12 meses que cubre.
		$payments = $this->fixture->owner->all(
			"SELECT * FROM license_payments WHERE concept='maintenance' ORDER BY paid_at",
		);
		self::assertSame(
			[
				[
					'maintenance',
					'3000.00',
					'3000.00',
					'2027-09-01 16:00:00.000000',
					'2028-09-01 16:00:00.000000',
					'MANT-2027',
				],
				['maintenance', '3000.00', '3000.00', '2028-09-01 16:00:00.000000', '2029-09-01 16:00:00.000000', ''],
			],
			array_map(
				fn(array $payment): array => [
					$payment['term'],
					$payment['amount'],
					$payment['catalog_amount'],
					$payment['covers_from'],
					$payment['covers_until'],
					$payment['reference'],
				],
				$payments,
			),
		);
		// El mantenimiento no cambia el pago del periodo de la licencia.
		self::assertSame(
			'issue',
			$this->fixture->owner->one(
				'SELECT p.concept FROM licenses l JOIN license_payments p ON p.payment_id=l.period_payment_id WHERE l.license_id=?',
				[Uuid::bytes($license)],
			)['concept'],
		);
	}

	public function testAfterTheUpdatesEndedTheTwelveMonthsCountFromThePaymentDate(): void
	{
		[$license, $installation] = $this->activated('perpetual', ['updates_months' => '0']);
		// Con 0 meses, las actualizaciones terminaron en la activación misma.
		self::assertSame('2027-03-01T16:00:00.000000Z', $installation->refresh()['updates_until']);
		$this->clock->instant = new \DateTimeImmutable('2027-11-20T21:15:00Z');
		$result = $this->maintenance($license);
		self::assertSame('2028-11-20T21:15:00.000000Z', $result['updates_until']);
		$row = $this->license($license);
		self::assertSame([20, '15:15:00.000000'], [(int) $row['anchor_day'], $row['anchor_local_time']]);
		// Una personalizada no tiene precio de mantenimiento: el monto se escribe a mano, sin motivo.
		[$custom] = $this->activated('perpetual', ['cash_registers' => '9']);
		self::assertNull($this->app->commercial->quoteMaintenance($custom, [])['suggested']);
		$this->maintenance($custom, ['amount' => '4500']);
		self::assertSame(
			[null, '4500.00'],
			array_values(
				$this->fixture->owner->one(
					"SELECT catalog_amount,amount FROM license_payments WHERE concept='maintenance' AND license_id=?",
					[Uuid::bytes($custom)],
				),
			),
		);
	}

	public function testMaintenanceIsOnlyForActivatedPerpetualsThatAreNotRevoked(): void
	{
		[$subscription] = $this->activated('subscription');
		[$trial] = $this->activated('trial');
		$unactivated = $this->issue('perpetual');
		[$revoked] = $this->activated('perpetual');
		$this->app->licenses->change($this->actor, Uuid::create(), $revoked, 'revoke', [
			'version' => (int) $this->license($revoked)['row_version'],
			'reason' => 'Prueba de revocación',
		]);
		$this->rejected('solo de las licencias perpetuas', fn() => $this->maintenance($subscription));
		$this->rejected('solo de las licencias perpetuas', fn() => $this->maintenance($trial));
		$this->rejected(
			'sin activar todavía no necesita mantenimiento',
			fn() => $this->maintenance($unactivated['id']),
		);
		$this->rejected('revocada no puede comprar mantenimiento', fn() => $this->maintenance($revoked));
		[$license] = $this->activated('perpetual');
		$this->rejected('Escribe el monto cobrado', fn() => $this->maintenance($license, ['amount' => '']));
		self::assertSame(
			0,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='maintenance'")
				->fetchColumn(),
		);
	}

	public function testTheInitialPeriodOfALicenseThatIsNotActivatedCanBeEdited(): void
	{
		$issued = $this->issue('subscription');
		$edit = fn(array $input) => $this->app->commercial->editInitialTerms(
			$this->actor,
			Uuid::create(),
			$issued['id'],
			$input + ['version' => (int) $this->license($issued['id'])['row_version']],
		);
		// De suscripción mensual del elemental a perpetua de 12 meses del premium con una caja más.
		$edit([
			'license_type' => 'perpetual',
			'updates_months' => '12',
			'plan_id' => 'premium',
			'cash_registers' => '6',
		]);
		$row = $this->license($issued['id']);
		self::assertSame(
			['perpetual', null, null, 12, 0, 'premium', 'Plan premium', 1, 6, 10, 5, null],
			[
				$row['license_type'],
				$row['billing_period'],
				$row['trial_duration'],
				(int) $row['updates_months'],
				(int) $row['grace_days'],
				$row['plan_id'],
				$row['plan_name'],
				(int) $row['plan_custom'],
				(int) $row['cash_registers'],
				(int) $row['order_takers'],
				(int) $row['kitchen_displays'],
				$row['period_starts_at'],
			],
		);
		// A prueba de 3 meses del básico, sin personalizar. No registra pagos.
		$edit(['license_type' => 'trial', 'trial_duration' => '3m', 'plan_id' => 'basico']);
		self::assertSame(
			['trial', '3m', 0],
			[
				$this->license($issued['id'])['license_type'],
				$this->license($issued['id'])['trial_duration'],
				(int) $this->license($issued['id'])['plan_custom'],
			],
		);
		self::assertSame(
			1,
			(int) $this->fixture->owner->execute('SELECT COUNT(*) FROM license_payments')->fetchColumn(),
		);
		$changes = json_decode(
			$this->fixture->owner->one(
				"SELECT payload_json FROM audit_events WHERE operation='license.initial_terms_edited' ORDER BY sequence DESC LIMIT 1",
			)['payload_json'],
			true,
		)['metadata']['changes'];
		self::assertSame(['before' => 'perpetual', 'after' => 'trial'], $changes['license_type']);
		self::assertSame(['before' => 6, 'after' => 1], $changes['cash_registers']);
		// Al activar, las fechas se fijan con las condiciones nuevas.
		$this->clock->instant = new \DateTimeImmutable('2027-03-10T16:00:00Z');
		$content = (new Installation($this->app))->activate($issued['secret']);
		self::assertSame(
			['trial', '2027-06-10T16:00:00.000000Z', 'basico'],
			[$content['license_type'], $content['expires_at'], $content['plan']['plan_id']],
		);
		// Ya activada, no se edita.
		$this->rejected(
			'ya está activada',
			fn() => $edit(['license_type' => 'trial', 'trial_duration' => '1m', 'plan_id' => 'basico']),
		);
		$other = $this->issue('perpetual');
		$editOther = fn(array $input) => $this->app->commercial->editInitialTerms(
			$this->actor,
			Uuid::create(),
			$other['id'],
			$input + ['version' => 1],
		);
		$this->rejected(
			'no cambió',
			fn() => $editOther(['license_type' => 'perpetual', 'updates_months' => '6', 'plan_id' => 'elemental']),
		);
		$this->rejected(
			'solo aplica a las suscripciones',
			fn() => $editOther([
				'license_type' => 'perpetual',
				'billing_period' => 'monthly',
				'plan_id' => 'elemental',
			]),
		);
		$this->fixture->owner->execute("UPDATE plans SET state='archived' WHERE plan_id='premium'");
		$this->rejected('archivado', fn() => $editOther(['license_type' => 'perpetual', 'plan_id' => 'premium']));
		$this->app->licenses->change($this->actor, Uuid::create(), $other['id'], 'revoke', [
			'version' => 1,
			'reason' => 'Prueba',
		]);
		$this->rejected(
			'revocada',
			fn() => $this->app->commercial->editInitialTerms($this->actor, Uuid::create(), $other['id'], [
				'version' => 2,
				'license_type' => 'trial',
				'trial_duration' => '1m',
				'plan_id' => 'basico',
			]),
		);
	}

	public function testTheDateEngineForMaintenance(): void
	{
		$zone = 'America/Monterrey';
		$until = new \DateTimeImmutable('2027-09-01T16:00:00Z');
		$before = CalendarPeriod::maintenance(
			$until,
			new \DateTimeImmutable('2027-08-31T00:00:00Z'),
			$zone,
			1,
			'10:00:00.000000',
		);
		self::assertSame(
			['2027-09-01T16:00:00.000000Z', '2028-09-01T16:00:00.000000Z', false],
			[
				CalendarPeriod::utc($before['updates_from']),
				CalendarPeriod::utc($before['updates_until']),
				$before['from_payment'],
			],
		);
		// Un pago justo en el fin de las actualizaciones cuenta desde ese fin.
		self::assertFalse(CalendarPeriod::maintenance($until, $until, $zone, 1, '10:00:00.000000')['from_payment']);
		$after = CalendarPeriod::maintenance($until, $until->modify('+1 microsecond'), $zone, 1, '10:00:00.000000');
		self::assertTrue($after['from_payment']);
		self::assertSame('2028-09-01T16:00:00.000001Z', CalendarPeriod::utc($after['updates_until']));
		// Día ancla 29 de febrero: al año siguiente, el último día de febrero.
		$leap = CalendarPeriod::maintenance(
			new \DateTimeImmutable('2028-02-29T16:00:00Z'),
			new \DateTimeImmutable('2028-01-01T00:00:00Z'),
			$zone,
			29,
			'10:00:00.000000',
		);
		self::assertSame('2029-02-28T16:00:00.000000Z', CalendarPeriod::utc($leap['updates_until']));
	}
}
