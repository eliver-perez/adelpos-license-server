<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Tests\{Concurrency, FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Renovación de suscripciones (etapa 3, Fase 3; contrato §5.3) con reloj congelado: regla de la
 * tolerancia, día ancla, duración, pago registrado, revisión firmada para la misma activación y
 * rechazos.
 */
final class RenewalTest extends TestCase
{
	private MySqlFixture $fixture;
	private App $app;
	private FrozenClock $clock;
	private Actor $actor;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		// Las licencias de estas pruebas se activan el 2 de febrero de 2027 a las 10:00 de Monterrey.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-02-02T16:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('renewal-test-key', 'license');
		$keys->activate('renewal-test-key', 'Isolated test setup');
		$this->actor = new Actor(
			$this->app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
		$this->fixture->owner->execute(
			"UPDATE plans SET price_monthly='799.00',price_annual='7990.00' WHERE plan_id='elemental'",
		);
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	/**
	 * Emite y activa una suscripción mensual del plan elemental. Devuelve la licencia y la instalación.
	 * @return array{0: string, 1: Installation}
	 */
	private function subscription(array $input = [], string $zone = 'America/Monterrey'): array
	{
		$customer = $this->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Restaurante sintético',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
			'time_zone' => $zone,
		])['id'];
		$issued = $this->app->licenses->issue(
			$this->actor,
			Uuid::create(),
			array_replace(
				[
					'customer_id' => $customer,
					'product_id' => 'adel_pos',
					...MySqlFixture::terms('subscription'),
					'amount' => '799',
				],
				$input,
			),
		);
		$installation = new Installation($this->app);
		$installation->activate($issued['secret']);
		return [$issued['id'], $installation];
	}

	private function license(string $id): array
	{
		return $this->fixture->owner->one('SELECT * FROM licenses WHERE license_id=?', [Uuid::bytes($id)]);
	}

	private function renew(string $id, array $input = [], ?string $operation = null): array
	{
		return $this->app->commercial->renew(
			$this->actor,
			$operation ?? Uuid::create(),
			$id,
			$input + ['version' => (int) $this->license($id)['row_version'], 'amount' => '799'],
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

	public function testTheFourExamplesOfTheContractAndTheExactEndOfTheTolerance(): void
	{
		// Vence el 2 de marzo a las 10:00 (16:00 UTC). Cada caso paga en otro momento.
		$cases = [
			'antes de vencer' => ['2027-03-01T16:00:00Z', '2027-04-02T16:00:00.000000Z', '2027-03-02T16:00:00.000000Z'],
			'dentro de la tolerancia' => [
				'2027-03-05T16:00:00Z',
				'2027-04-02T16:00:00.000000Z',
				'2027-03-02T16:00:00.000000Z',
			],
			'un microsegundo antes del límite' => [
				'2027-03-07T15:59:59.999999Z',
				'2027-04-02T16:00:00.000000Z',
				'2027-03-02T16:00:00.000000Z',
			],
			'justo en el límite, que es exclusivo' => [
				'2027-03-07T16:00:00Z',
				'2027-04-07T16:00:00.000000Z',
				'2027-03-07T16:00:00.000000Z',
			],
			'mucho después' => ['2027-03-20T16:00:00Z', '2027-04-20T16:00:00.000000Z', '2027-03-20T16:00:00.000000Z'],
		];
		foreach ($cases as $name => [$paid, $expires, $starts]) {
			$this->clock->instant = new \DateTimeImmutable('2027-02-02T16:00:00Z');
			[$license, $installation] = $this->subscription();
			$this->clock->instant = new \DateTimeImmutable($paid);
			$result = $this->renew($license);
			self::assertSame([$expires, 1], [$result['expires_at'], $result['revisions_signed']], $name);
			// La computadora en línea recibe la revisión nueva para su misma activación.
			$content = $installation->refresh();
			self::assertSame(
				[2, $installation->activationId, $starts, $expires, $expires, 'monthly'],
				[
					$content['license_revision'],
					$content['activation_id'],
					$content['period_starts_at'],
					$content['expires_at'],
					$content['updates_until'],
					$content['billing_period'],
				],
				$name,
			);
		}
	}

	public function testTheRenewalKeepsTheCountsCanChangeTheDurationAndRecordsItsPayment(): void
	{
		[$license, $installation] = $this->subscription(['cash_registers' => '5']);
		$this->clock->instant = new \DateTimeImmutable('2027-02-25T18:00:00Z');
		// Una licencia personalizada no tiene precio: el monto se escribe a mano, sin motivo.
		$this->renew($license, ['billing_period' => 'annual', 'amount' => '9500', 'reference' => 'RENOV-1']);
		$row = $this->license($license);
		self::assertSame(
			['annual', 5, 3, 3, 1, '2028-03-02 16:00:00.000000', '2027-03-02 16:00:00.000000'],
			[
				$row['billing_period'],
				(int) $row['cash_registers'],
				(int) $row['order_takers'],
				(int) $row['kitchen_displays'],
				(int) $row['plan_custom'],
				$row['expires_at'],
				$row['period_starts_at'],
			],
		);
		$content = $installation->refresh();
		self::assertSame(['cash_registers' => 5, 'order_takers' => 3, 'kitchen_displays' => 3], $content['limits']);
		self::assertSame('annual', $content['billing_period']);
		$payment = $this->fixture->owner->one(
			"SELECT * FROM license_payments WHERE license_id=? AND concept='renewal'",
			[Uuid::bytes($license)],
		);
		self::assertSame(
			[
				'annual',
				'2027-02-25 18:00:00.000000',
				'9500.00',
				null,
				'RENOV-1',
				'2027-03-02 16:00:00.000000',
				'2028-03-02 16:00:00.000000',
			],
			[
				$payment['term'],
				$payment['paid_at'],
				$payment['amount'],
				$payment['catalog_amount'],
				$payment['reference'],
				$payment['covers_from'],
				$payment['covers_until'],
			],
		);
		// El pago de la renovación es ahora el que cubre el periodo vigente.
		self::assertSame($payment['payment_id'], $row['period_payment_id']);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_changes WHERE action='license.renewed'")
				->fetchColumn(),
		);
	}

	public function testTheRenewalSuggestsTheCatalogPriceForTheChosenDuration(): void
	{
		[$license] = $this->subscription();
		$this->clock->instant = new \DateTimeImmutable('2027-02-25T18:00:00Z');
		$quote = $this->app->commercial->quoteRenewal($license, ['billing_period' => 'annual']);
		self::assertSame(
			[799000, '2028-03-02T16:00:00.000000Z'],
			[$quote['suggested'], \AdelPos\License\Domain\CalendarPeriod::utc($quote['dates']['expires_at'])],
		);
		$this->rejected(
			'distinto del sugerido ($7,990.00 MXN)',
			fn() => $this->renew($license, ['billing_period' => 'annual']),
		);
		$this->renew($license, [
			'billing_period' => 'annual',
			'amount' => '7500',
			'amount_reason' => 'Promoción anual',
		]);
		$payment = $this->fixture->owner->one("SELECT * FROM license_payments WHERE concept='renewal'");
		self::assertSame(
			['7500.00', '7990.00', 'Promoción anual'],
			[$payment['amount'], $payment['suggested_amount'], $payment['override_reason']],
		);
		// La vista previa no escribe nada.
		self::assertSame(
			2,
			(int) $this->fixture->owner->execute('SELECT COUNT(*) FROM license_payments')->fetchColumn(),
		);
	}

	public function testChainedRenewalsKeepTheAnchorDay31(): void
	{
		// Activada el 31 de enero a las 09:30 de Monterrey: 28 feb → 31 mar → 30 abr → 31 may.
		$this->clock->instant = new \DateTimeImmutable('2027-01-31T15:30:00Z');
		[$license, $installation] = $this->subscription();
		self::assertSame('2027-02-28T15:30:00.000000Z', $installation->refresh()['expires_at']);
		$found = [];
		// Cada renovación se paga el día 20, antes de vencer: el periodo nuevo empieza en el vencimiento.
		foreach (['2027-02-20', '2027-03-20', '2027-04-20', '2027-05-20'] as $day) {
			$this->clock->instant = new \DateTimeImmutable($day . 'T18:00:00Z');
			$this->renew($license);
			$found[] = $installation->refresh()['expires_at'];
		}
		self::assertSame(
			[
				'2027-03-31T15:30:00.000000Z',
				'2027-04-30T15:30:00.000000Z',
				'2027-05-31T15:30:00.000000Z',
				'2027-06-30T15:30:00.000000Z',
			],
			$found,
		);
		$row = $this->license($license);
		self::assertSame([31, '09:30:00.000000'], [(int) $row['anchor_day'], $row['anchor_local_time']]);
		self::assertSame(5, $installation->refresh()['license_revision']);
	}

	public function testARenewalInTheCustomerTimeZoneAcrossADaylightSavingChange(): void
	{
		// Activada el 2 de marzo de 2027 a las 10:00 de Tijuana (UTC−8): vence el 2 de abril a las 10:00
		// (UTC−7). La fecha de pago se escribe en la hora local del cliente.
		$this->clock->instant = new \DateTimeImmutable('2027-03-02T18:00:00Z');
		[$license, $installation] = $this->subscription([], 'America/Tijuana');
		$this->clock->instant = new \DateTimeImmutable('2027-04-10T00:00:00Z');
		$result = $this->renew($license, ['paid_at' => '2027-04-06T09:00']);
		self::assertSame('2027-05-02T17:00:00.000000Z', $result['expires_at']);
		self::assertTrue($result['in_time']);
		self::assertSame(
			'2027-04-06 16:00:00.000000',
			$this->fixture->owner->one("SELECT paid_at FROM license_payments WHERE concept='renewal'")['paid_at'],
		);
		// Pagó después del límite (7 de abril a las 10:00, hora de verano): empieza a la hora local del pago.
		$this->clock->instant = new \DateTimeImmutable('2027-06-01T00:00:00Z');
		$late = $this->renew($license, ['paid_at' => '2027-05-20T08:15']);
		self::assertFalse($late['in_time']);
		self::assertSame('2027-06-20T15:15:00.000000Z', $late['expires_at']);
		self::assertSame('2027-05-20T15:15:00.000000Z', $installation->refresh()['period_starts_at']);
	}

	public function testWhatCannotBeRenewed(): void
	{
		$customer = $this->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Otro restaurante',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
		])['id'];
		$issue = fn(string $type) => $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms($type, 'premium'),
		]);
		$perpetual = $issue('perpetual');
		(new Installation($this->app))->activate($perpetual['secret']);
		$trial = $issue('trial');
		(new Installation($this->app))->activate($trial['secret']);
		$unactivated = $issue('subscription');
		[$revoked] = $this->subscription();
		$this->app->licenses->change($this->actor, Uuid::create(), $revoked, 'revoke', [
			'version' => (int) $this->license($revoked)['row_version'],
			'reason' => 'Prueba de revocación',
		]);
		$this->rejected('perpetua no se renueva', fn() => $this->renew($perpetual['id']));
		$this->rejected('Una prueba no se renueva', fn() => $this->renew($trial['id']));
		$this->rejected('sin activar todavía no se puede renovar', fn() => $this->renew($unactivated['id']));
		$this->rejected('revocada no se puede renovar', fn() => $this->renew($revoked));
		// Una fecha de pago futura, o anterior al último pago de la licencia, tampoco.
		[$license] = $this->subscription();
		$this->rejected('no puede ser posterior', fn() => $this->renew($license, ['paid_at' => '2027-02-03T10:00']));
		$this->rejected('anterior al último pago', fn() => $this->renew($license, ['paid_at' => '2027-02-01T10:00']));
		$this->rejected('Escribe el monto cobrado', fn() => $this->renew($license, ['amount' => '']));
		// Ninguno de esos intentos dejó un pago ni una revisión.
		self::assertSame(
			0,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='renewal'")
				->fetchColumn(),
		);
		// Y la cuenta de consulta no puede renovar.
		$viewer = $this->app->admins->create($this->actor, Uuid::create(), [
			'login' => 'viewer@example.test',
			'name' => 'Consulta',
			'role' => 'viewer',
			'new_password' => 'Fixture-Password-Only-2026!',
		]);
		try {
			$this->app->commercial->renew(new Actor($viewer['id'], 'viewer'), Uuid::create(), $license, [
				'version' => 1,
				'amount' => '799',
			]);
			self::fail('A viewer renewed a license');
		} catch (Problem $error) {
			self::assertSame(403, $error->status);
		}
	}

	public function testAnIdenticalRetryRenewsOnceAndTwoOperatorsAtOnceRenewOnce(): void
	{
		[$license, $installation] = $this->subscription();
		$this->clock->instant = new \DateTimeImmutable('2027-02-25T18:00:00Z');
		$operation = Uuid::create();
		$first = $this->renew($license, [], $operation);
		$again = $this->app->commercial->renew($this->actor, $operation, $license, ['version' => 1, 'amount' => '799']);
		self::assertSame(
			[false, true, $first['expires_at']],
			[$first['replayed'], $again['replayed'], $again['expires_at']],
		);
		self::assertSame('2027-04-02T16:00:00.000000Z', $installation->refresh()['expires_at']);
		// Dos operadores con el mismo formulario: la licencia está en la versión 2 y los dos la vieron así.
		$version = (int) $this->license($license)['row_version'];
		$job = fn() => [
			'service' => 'commercial',
			'method' => 'renew',
			'operation' => Uuid::create(),
			'arguments' => [$license, ['version' => $version, 'amount' => '799']],
		];
		$results = Concurrency::race($this->fixture, $this->actor->id, $license, '2027-02-26T18:00:00Z', [
			$job(),
			$job(),
		]);
		$renewed = array_values(array_filter($results, fn(array $result): bool => isset($result['result'])));
		$refused = array_values(array_filter($results, fn(array $result): bool => isset($result['status'])));
		self::assertCount(1, $renewed);
		self::assertCount(1, $refused);
		self::assertSame(409, $refused[0]['status']);
		self::assertSame('2027-05-02T16:00:00.000000Z', $renewed[0]['result']['expires_at']);
		self::assertSame(3, $installation->refresh()['license_revision']);
		self::assertSame(
			2,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='renewal'")
				->fetchColumn(),
		);
	}
}
