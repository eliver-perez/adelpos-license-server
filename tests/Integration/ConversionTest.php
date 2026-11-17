<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Tests\{FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Conversión de una prueba en licencia de pago (etapa 3, Fase 4; contrato §5.4 y decisión 5 del
 * propietario): a cualquier tipo y plan, desde la fecha de pago, sin bonificación y para la misma
 * activación.
 */
final class ConversionTest extends TestCase
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
		// Las pruebas de 15 días se activan el 1 de marzo de 2027 a las 10:00 de Monterrey y vencen el 16.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-03-01T16:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('conversion-test-key', 'license');
		$keys->activate('conversion-test-key', 'Isolated test setup');
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
		$this->fixture->owner->execute(
			"UPDATE plans SET price_monthly='1290.00',price_perpetual='25000.00' WHERE plan_id='premium'",
		);
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	/**
	 * Emite una prueba de 15 días del plan básico y, si se pide, la activa. Su pago de alta (0) lleva la
	 * fecha del día anterior, para que la regla «no antes del inicio de la prueba» se vea sola.
	 */
	private function trial(bool $activate = true): array
	{
		$issued = $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('trial', 'basico'),
			'paid_at' => '2027-02-28T10:00',
		]);
		$installation = null;
		if ($activate) {
			$installation = new Installation($this->app);
			$installation->activate($issued['secret']);
		}
		return ['id' => $issued['id'], 'secret' => $issued['secret'], 'installation' => $installation];
	}

	private function license(string $id): array
	{
		return $this->fixture->owner->one('SELECT * FROM licenses WHERE license_id=?', [Uuid::bytes($id)]);
	}

	private function convert(string $id, array $input): array
	{
		return $this->app->commercial->convert(
			$this->actor,
			Uuid::create(),
			$id,
			$input + ['version' => (int) $this->license($id)['row_version']],
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

	public function testAnActivatedTrialBecomesASubscriptionFromThePaymentDateForTheSameActivation(): void
	{
		$trial = $this->trial();
		$first = $trial['installation']->refresh();
		self::assertSame(
			['trial', '2027-03-16T16:00:00.000000Z', 0],
			[$first['license_type'], $first['expires_at'], $first['grace_days']],
		);
		// Paga el 5 de marzo a las 12:30, durante la prueba: los días restantes no se acumulan.
		$this->clock->instant = new \DateTimeImmutable('2027-03-05T18:30:00Z');
		// La vista previa calcula lo mismo sin escribir nada.
		$quote = $this->app->commercial->quoteConversion($trial['id'], [
			'license_type' => 'subscription',
			'billing_period' => 'monthly',
			'plan_id' => 'premium',
		]);
		self::assertSame(
			[129000, '2027-04-05T18:30:00.000000Z', false],
			[
				$quote['suggested'],
				\AdelPos\License\Domain\CalendarPeriod::utc($quote['period']['expires_at']),
				$quote['target']['custom'],
			],
		);
		// Con cupos propios en un plan con precio, la licencia queda personalizada y sin precio de catálogo.
		$custom = $this->app->commercial->quoteConversion($trial['id'], [
			'license_type' => 'subscription',
			'billing_period' => 'monthly',
			'plan_id' => 'premium',
			'cash_registers' => '6',
		]);
		self::assertSame(
			[null, true, 6],
			[$custom['suggested'], $custom['target']['custom'], $custom['target']['limits']['cash_registers']],
		);
		// Una perpetua sin meses elegidos lleva 6 meses de actualizaciones.
		$perpetual = $this->app->commercial->quoteConversion($trial['id'], [
			'license_type' => 'perpetual',
			'plan_id' => 'premium',
		]);
		self::assertSame([6, 2500000], [$perpetual['terms']['updates_months'], $perpetual['suggested']]);
		self::assertSame(
			1,
			(int) $this->fixture->owner->execute('SELECT COUNT(*) FROM license_payments')->fetchColumn(),
		);
		$result = $this->convert($trial['id'], [
			'license_type' => 'subscription',
			'billing_period' => 'monthly',
			'plan_id' => 'premium',
			'amount' => '1290',
		]);
		self::assertSame(1, $result['revisions_signed']);
		$content = $trial['installation']->refresh();
		self::assertSame(
			[
				'subscription',
				'monthly',
				2,
				$first['activation_id'],
				'2027-03-05T18:30:00.000000Z',
				'2027-04-05T18:30:00.000000Z',
				'2027-04-05T18:30:00.000000Z',
				5,
				['plan_id' => 'premium', 'name' => 'Plan premium', 'custom' => false],
				['cash_registers' => 5, 'order_takers' => 10, 'kitchen_displays' => 5],
			],
			[
				$content['license_type'],
				$content['billing_period'],
				$content['license_revision'],
				$content['activation_id'],
				$content['period_starts_at'],
				$content['expires_at'],
				$content['updates_until'],
				$content['grace_days'],
				$content['plan'],
				$content['limits'],
			],
		);
		$row = $this->license($trial['id']);
		self::assertSame(
			[5, '12:30:00.000000', null],
			[(int) $row['anchor_day'], $row['anchor_local_time'], $row['trial_duration']],
		);
		// El pago es de conversión, sin bonificación, con el precio del catálogo y el periodo que cubre.
		$payment = $this->fixture->owner->one("SELECT * FROM license_payments WHERE concept='conversion'");
		self::assertSame(
			[
				'monthly',
				'premium',
				'1290.00',
				'1290.00',
				'0.00',
				'2027-03-05 18:30:00.000000',
				'2027-04-05 18:30:00.000000',
			],
			[
				$payment['term'],
				$payment['plan_id'],
				$payment['amount'],
				$payment['catalog_amount'],
				$payment['credit_amount'],
				$payment['covers_from'],
				$payment['covers_until'],
			],
		);
		self::assertSame($payment['payment_id'], $row['period_payment_id']);
		// La computadora no reinstala: la misma activación sigue vigente y es la única.
		self::assertSame(1, (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
	}

	public function testATrialBecomesAPerpetualWithZeroSixOrTwelveMonthsOfUpdatesFromThePaymentDate(): void
	{
		$cases = [
			'0' => '2027-03-20T16:00:00.000000Z',
			'6' => '2027-09-20T16:00:00.000000Z',
			'12' => '2028-03-20T16:00:00.000000Z',
		];
		foreach ($cases as $months => $updates) {
			$this->clock->instant = new \DateTimeImmutable('2027-03-01T16:00:00Z');
			$trial = $this->trial();
			// Paga el 20 de marzo, con la prueba ya vencida (vencía el 16).
			$this->clock->instant = new \DateTimeImmutable('2027-03-20T16:00:00Z');
			$this->convert($trial['id'], [
				'license_type' => 'perpetual',
				'updates_months' => (string) $months,
				'plan_id' => 'premium',
				'amount' => '25000',
			]);
			$content = $trial['installation']->refresh();
			self::assertSame(
				['perpetual', null, null, 0, '2027-03-20T16:00:00.000000Z', $updates, 2],
				[
					$content['license_type'],
					$content['billing_period'],
					$content['expires_at'],
					$content['grace_days'],
					$content['period_starts_at'],
					$content['updates_until'],
					$content['license_revision'],
				],
				$months . ' meses',
			);
		}
		self::assertSame(
			3,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='conversion' AND term='perpetual'")
				->fetchColumn(),
		);
	}

	public function testAnUnactivatedTrialChangesItsTermsAndItsDatesStartWhenActivated(): void
	{
		$trial = $this->trial(false);
		$result = $this->convert($trial['id'], [
			'license_type' => 'subscription',
			'billing_period' => 'quarterly',
			'plan_id' => 'elemental',
			'order_takers' => '6',
			'amount' => '3000',
		]);
		self::assertSame(0, $result['revisions_signed']);
		$row = $this->license($trial['id']);
		self::assertSame(
			['subscription', 'quarterly', null, 5, 'elemental', 1, 3, 6, 3, null, null],
			[
				$row['license_type'],
				$row['billing_period'],
				$row['trial_duration'],
				(int) $row['grace_days'],
				$row['plan_id'],
				(int) $row['plan_custom'],
				(int) $row['cash_registers'],
				(int) $row['order_takers'],
				(int) $row['kitchen_displays'],
				$row['period_starts_at'],
				$row['expires_at'],
			],
		);
		// Una personalizada no tiene precio de catálogo, y el pago no cubre fechas todavía.
		$payment = $this->fixture->owner->one("SELECT * FROM license_payments WHERE concept='conversion'");
		self::assertSame(
			[null, null, 'quarterly'],
			[$payment['catalog_amount'], $payment['covers_from'], $payment['term']],
		);
		// Al activar, las fechas se fijan como en cualquier licencia.
		$this->clock->instant = new \DateTimeImmutable('2027-03-10T16:00:00Z');
		$content = (new Installation($this->app))->activate($trial['secret']);
		// Una prueba sin activar también se convierte en perpetua; sus actualizaciones cuentan desde la activación.
		$perpetual = $this->trial(false);
		$this->convert($perpetual['id'], [
			'license_type' => 'perpetual',
			'updates_months' => '12',
			'plan_id' => 'premium',
			'amount' => '25000',
		]);
		$this->clock->instant = new \DateTimeImmutable('2027-03-10T16:00:00Z');
		$activated = (new Installation($this->app))->activate($perpetual['secret']);
		self::assertSame(
			['perpetual', null, '2027-03-10T16:00:00.000000Z', '2028-03-10T16:00:00.000000Z', 0],
			[
				$activated['license_type'],
				$activated['expires_at'],
				$activated['period_starts_at'],
				$activated['updates_until'],
				$activated['grace_days'],
			],
		);
		self::assertSame(
			['subscription', 'quarterly', '2027-03-10T16:00:00.000000Z', '2027-06-10T16:00:00.000000Z', 1, true],
			[
				$content['license_type'],
				$content['billing_period'],
				$content['period_starts_at'],
				$content['expires_at'],
				$content['license_revision'],
				$content['plan']['custom'],
			],
		);
	}

	public function testWhatCannotBeConverted(): void
	{
		$subscription = $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('subscription'),
		]);
		$input = [
			'license_type' => 'subscription',
			'billing_period' => 'monthly',
			'plan_id' => 'elemental',
			'amount' => '0',
		];
		$this->rejected('Solo una prueba se convierte', fn() => $this->convert($subscription['id'], $input));
		$trial = $this->trial();
		$this->rejected(
			'a una suscripción o a una perpetua',
			fn() => $this->convert($trial['id'], ['license_type' => 'trial'] + $input),
		);
		$this->rejected('Elige el plan', fn() => $this->convert($trial['id'], ['plan_id' => ''] + $input));
		$this->rejected(
			'no existe en el catálogo',
			fn() => $this->convert($trial['id'], ['plan_id' => 'inexistente'] + $input),
		);
		$this->fixture->owner->execute("UPDATE plans SET state='archived' WHERE plan_id='elemental'");
		$this->rejected('archivado', fn() => $this->convert($trial['id'], $input));
		$this->fixture->owner->execute("UPDATE plans SET state='active' WHERE plan_id='elemental'");
		$this->rejected(
			'anterior al inicio de la prueba',
			fn() => $this->convert($trial['id'], ['paid_at' => '2027-03-01T09:00'] + $input),
		);
		$this->rejected('Escribe el monto cobrado', fn() => $this->convert($trial['id'], ['amount' => ''] + $input));
		$this->rejected('entre 0 y 999', fn() => $this->convert($trial['id'], ['cash_registers' => '1000'] + $input));
		$this->app->licenses->change($this->actor, Uuid::create(), $trial['id'], 'revoke', [
			'version' => (int) $this->license($trial['id'])['row_version'],
			'reason' => 'Prueba de revocación',
		]);
		$this->rejected('revocada', fn() => $this->convert($trial['id'], $input));
		self::assertSame(
			0,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='conversion'")
				->fetchColumn(),
		);
	}
}
