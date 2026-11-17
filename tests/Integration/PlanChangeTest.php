<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Tests\{Concurrency, FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Cambio de plan con bonificación (etapa 3, Fase 5; decisiones 6, 7 y 8 del propietario): aumentos
 * con periodo nuevo y bonificación exacta, reducciones programadas, perpetuas que solo suben,
 * pruebas sin cobro y pagos después del vencimiento tratados como renovación.
 */
final class PlanChangeTest extends TestCase
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
		// Las licencias se activan el 1 de marzo de 2027 a las 10:00 de Monterrey: la mensual vence el 1 de abril.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-03-01T16:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('plan-change-test-key', 'license');
		$keys->activate('plan-change-test-key', 'Isolated test setup');
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
		$prices = [
			'basico' => ['400.00', '4000.00', '8000.00'],
			'elemental' => ['900.00', '9000.00', '15000.00'],
			'premium' => ['1500.00', '15000.00', '25000.00'],
		];
		foreach ($prices as $plan => [$monthly, $annual, $perpetual]) {
			$this->fixture->owner->execute(
				'UPDATE plans SET price_monthly=?,price_annual=?,price_perpetual=? WHERE plan_id=?',
				[$monthly, $annual, $perpetual, $plan],
			);
		}
		// Un plan que sube cajas y comanderos y baja KDS respecto del elemental (3, 3, 3).
		$this->app->plans->create($this->actor, Uuid::create(), [
			'plan_id' => 'barra',
			'display_name' => 'Plan barra',
			'cash_registers' => '5',
			'order_takers' => '10',
			'kitchen_displays' => '1',
			'price_monthly' => '1300',
		]);
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	/** Emite y activa una licencia; devuelve su identificador y la instalación. */
	private function activated(string $type, array $input = [], string $plan = 'elemental'): array
	{
		$issued = $this->app->licenses->issue(
			$this->actor,
			Uuid::create(),
			array_replace(
				['customer_id' => $this->customer, 'product_id' => 'adel_pos', ...MySqlFixture::terms($type, $plan)],
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

	private function change(string $id, array $input, ?string $operation = null): array
	{
		return $this->app->commercial->changePlan(
			$this->actor,
			$operation ?? Uuid::create(),
			$id,
			$input + ['version' => (int) $this->license($id)['row_version']],
		);
	}

	private function limits(string $id): array
	{
		$row = $this->license($id);
		return [(int) $row['cash_registers'], (int) $row['order_takers'], (int) $row['kitchen_displays']];
	}

	private function lastPayment(string $id): array
	{
		return $this->fixture->owner->one(
			'SELECT * FROM license_payments WHERE license_id=? ORDER BY created_at DESC,paid_at DESC LIMIT 1',
			[Uuid::bytes($id)],
		);
	}

	private function pending(string $id): ?array
	{
		return $this->fixture->owner->one(
			"SELECT * FROM license_scheduled_changes WHERE license_id=? AND state='pending'",
			[Uuid::bytes($id)],
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

	public function testAnIncreaseStartsANewPeriodAtThePaymentDateWithTheExactCredit(): void
	{
		// Pagó 850.00 en el alta, con descuento. El 16 de marzo sube a premium: faltaban 16 de 31 días.
		[$license, $installation] = $this->activated('subscription', [
			'amount' => '850',
			'amount_reason' => 'Descuento de apertura',
		]);
		// Otra, del mismo día, que hará de licencia emitida antes de la etapa 3: sin pago del periodo.
		[$older] = $this->activated('subscription', ['amount' => '900']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame(
			['increase', 43871, 150000, 106129, 16 * 86400, 'payment'],
			[
				$quote['kind'],
				$quote['credit'],
				$quote['catalog'],
				$quote['suggested'],
				$quote['credit_basis']['parts'][0]['remaining_seconds'],
				$quote['credit_basis']['source'],
			],
		);
		$result = $this->change($license, ['plan_id' => 'premium', 'amount' => '1061.29']);
		self::assertSame(
			['increase', 1, null],
			[$result['kind'], $result['revisions_signed'], $result['scheduled_at']],
		);
		$content = $installation->refresh();
		self::assertSame(
			[
				2,
				$installation->activationId,
				'2027-03-16T16:00:00.000000Z',
				'2027-04-16T16:00:00.000000Z',
				['plan_id' => 'premium', 'name' => 'Plan premium', 'custom' => false],
				['cash_registers' => 5, 'order_takers' => 10, 'kitchen_displays' => 5],
			],
			[
				$content['license_revision'],
				$content['activation_id'],
				$content['period_starts_at'],
				$content['expires_at'],
				$content['plan'],
				$content['limits'],
			],
		);
		$payment = $this->lastPayment($license);
		self::assertSame(
			['plan_change', 'monthly', '1061.29', '1500.00', '438.71', '1061.29', null, '2027-04-16 16:00:00.000000'],
			[
				$payment['concept'],
				$payment['term'],
				$payment['amount'],
				$payment['catalog_amount'],
				$payment['credit_amount'],
				$payment['suggested_amount'],
				$payment['override_reason'],
				$payment['covers_until'],
			],
		);
		self::assertSame($payment['payment_id'], $this->license($license)['period_payment_id']);
		// Sin pago del periodo (emitida antes de la etapa 3), se usa el precio del catálogo del plan y el periodo.
		$this->fixture->owner->execute('UPDATE licenses SET period_payment_id=NULL WHERE license_id=?', [
			Uuid::bytes($older),
		]);
		$quote = $this->app->commercial->quotePlanChange($older, ['plan_id' => 'premium']);
		self::assertSame(
			[46452, 103548, 'catalog'],
			[$quote['credit'], $quote['suggested'], $quote['credit_basis']['source']],
		);
		// El operador puede cobrar otro monto, con su motivo.
		$this->rejected(
			'distinto del sugerido ($1,035.48 MXN)',
			fn() => $this->change($older, ['plan_id' => 'premium', 'amount' => '1000']),
		);
		$this->change($older, ['plan_id' => 'premium', 'amount' => '1000', 'amount_reason' => 'Redondeo comercial']);
		self::assertSame(
			['1000.00', '1035.48', '464.52', 'Redondeo comercial'],
			[
				$this->lastPayment($older)['amount'],
				$this->lastPayment($older)['suggested_amount'],
				$this->lastPayment($older)['credit_amount'],
				$this->lastPayment($older)['override_reason'],
			],
		);
	}

	/** Renueva una suscripción con su monto (y el motivo si no es el sugerido), en la fecha del reloj. */
	private function renew(string $id, string $amount, string $reason = ''): void
	{
		$this->app->commercial->renew($this->actor, Uuid::create(), $id, [
			'version' => (int) $this->license($id)['row_version'],
			'amount' => $amount,
			'amount_reason' => $reason,
		]);
	}

	/** Bonificación, sugerido y cada parte (valor, segundos no consumidos y lo que aporta) de una vista previa. */
	private static function credit(array $quote): array
	{
		$parts = [];
		foreach ($quote['credit_basis']['parts'] as $part) {
			$parts[] = [$part['concept'], $part['value'], $part['remaining_seconds'], $part['credit']];
		}
		return [$quote['credit'], $quote['suggested'], $parts];
	}

	public function testAnAdvanceRenewalAddsTheRestOfTheCurrentPeriodAndTheWholePrepaidOne(): void
	{
		// Elemental mensual de 900.00 del 1 de marzo al 1 de abril. El 20 de marzo renueva por adelantado
		// (900.00): abril-mayo. El 28 de marzo sube a premium (1,500.00): faltan 4 de 31 días del periodo en
		// curso (116.13) y el mes pagado por adelantado no ha empezado (900.00). Bonificación: 1,016.13.
		[$license, $installation] = $this->activated('subscription', ['amount' => '900']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-20T16:00:00Z');
		$this->renew($license, '900');
		$this->clock->instant = new \DateTimeImmutable('2027-03-28T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame(
			[101613, 48387, [['issue', 90000, 4 * 86400, 11613], ['renewal', 90000, 30 * 86400, 90000]]],
			self::credit($quote),
		);
		self::assertSame('payment', $quote['credit_basis']['source']);
		$this->change($license, ['plan_id' => 'premium', 'amount' => '483.87']);
		$payment = $this->lastPayment($license);
		self::assertSame(
			['483.87', '1016.13', '483.87', '2027-03-28 16:00:00.000000', '2027-04-28 16:00:00.000000'],
			[
				$payment['amount'],
				$payment['credit_amount'],
				$payment['suggested_amount'],
				$payment['covers_from'],
				$payment['covers_until'],
			],
		);
		// El periodo nuevo empieza en la fecha de pago: el mes pagado por adelantado quedó dentro de la bonificación.
		self::assertSame('2027-04-28T16:00:00.000000Z', $installation->refresh()['expires_at']);
	}

	public function testASecondIncreaseValuesThePeriodAtWhatWasChargedPlusTheCreditItUsed(): void
	{
		// Básico mensual de 400.00 desde el 1 de marzo. El 16 de marzo sube al elemental: faltan 16 de 31 días
		// (206.45) y cobra 693.55. Ese periodo, del 16 de marzo al 16 de abril, vale 693.55 + 206.45 = 900.00.
		[$license] = $this->activated('subscription', ['amount' => '400'], 'basico');
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$first = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'elemental']);
		self::assertSame([20645, 69355, [['issue', 40000, 16 * 86400, 20645]]], self::credit($first));
		$this->change($license, ['plan_id' => 'elemental', 'amount' => '693.55']);
		// El 1 de abril sube a premium: faltan 15 de 31 días del periodo de 900.00: 435.48. Con solo lo
		// cobrado (693.55) habría sido 335.59.
		$this->clock->instant = new \DateTimeImmutable('2027-04-01T16:00:00Z');
		$second = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame([43548, 106452, [['plan_change', 90000, 15 * 86400, 43548]]], self::credit($second));
		self::assertSame(
			[69355, 20645],
			[$second['credit_basis']['parts'][0]['amount'], $second['credit_basis']['parts'][0]['applied_credit']],
		);
		$this->change($license, ['plan_id' => 'premium', 'amount' => '1064.52']);
		self::assertSame(
			['1064.52', '435.48'],
			[$this->lastPayment($license)['amount'], $this->lastPayment($license)['credit_amount']],
		);
	}

	public function testAnAdvanceRenewalOverAPeriodPaidWithCreditAddsBoth(): void
	{
		// Básico de 400.00 el 1 de marzo; el 16 de marzo sube al elemental (cobra 693.55, el periodo vale 900.00
		// hasta el 16 de abril). El 8 de abril renueva por adelantado (900.00): 16 de abril al 16 de mayo. El 10 de
		// abril sube a premium: faltan 6 de 31 días del periodo pagado con bonificación (174.19) y el mes renovado
		// cuenta completo (900.00). Bonificación: 1,074.19.
		[$license] = $this->activated('subscription', ['amount' => '400'], 'basico');
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$this->change($license, ['plan_id' => 'elemental', 'amount' => '693.55']);
		$this->clock->instant = new \DateTimeImmutable('2027-04-08T16:00:00Z');
		$this->renew($license, '900');
		self::assertSame('2027-04-16 16:00:00.000000', $this->license($license)['period_starts_at']);
		$this->clock->instant = new \DateTimeImmutable('2027-04-10T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame(
			[107419, 42581, [['plan_change', 90000, 6 * 86400, 17419], ['renewal', 90000, 30 * 86400, 90000]]],
			self::credit($quote),
		);
		// Un pago registrado después pero fechado antes de la renovación no se acepta: los pagos van en orden.
		$this->rejected(
			'anterior al último pago registrado',
			fn() => $this->app->commercial->quotePlanChange($license, [
				'plan_id' => 'premium',
				'paid_at' => '2027-04-01T10:00',
			]),
		);
		// Pagado el 20 de abril, ya empezó el mes renovado: el periodo pagado con bonificación terminó.
		$this->clock->instant = new \DateTimeImmutable('2027-04-20T16:00:00Z');
		$later = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame([[['renewal', 90000, 26 * 86400, 78000]]], [self::credit($later)[2]]);
	}

	public function testUnusualChainsOfPaidPeriods(): void
	{
		// Pagado antes de activar: el periodo en curso empieza después del pago y cuenta completo, sin buscar
		// periodos anteriores (el alta no es una renovación).
		$issued = $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('subscription'),
			'amount' => '900',
		]);
		$this->clock->instant = new \DateTimeImmutable('2027-03-05T16:00:00Z');
		(new Installation($this->app))->activate($issued['secret']);
		$quote = $this->app->commercial->quotePlanChange($issued['id'], [
			'plan_id' => 'premium',
			'paid_at' => '2027-03-03T10:00',
		]);
		self::assertSame([90000, 60000, [['issue', 90000, 31 * 86400, 90000]]], self::credit($quote));
		// Un periodo anterior sin pago registrado (licencia emitida antes de la etapa 3, con una renovación
		// anticipada después): no se sabe cuánto valía, así que no hay sugerencia.
		[$license] = $this->activated('subscription', ['amount' => '900']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-20T16:00:00Z');
		$this->renew($license, '900');
		$this->fixture->owner->execute("DELETE FROM license_payments WHERE license_id=? AND concept='issue'", [
			Uuid::bytes($license),
		]);
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame([0, null, null], [$quote['credit'], $quote['suggested'], $quote['credit_basis']]);
		// Una bonificación mayor que el monto máximo no se puede registrar.
		[$large] = $this->activated('subscription', ['amount' => '99999999.99', 'amount_reason' => 'Caso límite']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-21T16:00:00Z');
		$this->renew($large, '99999999.99', 'Caso límite');
		$this->rejected(
			'supera el monto máximo',
			fn() => $this->app->commercial->quotePlanChange($large, ['plan_id' => 'premium']),
		);
	}

	public function testACreditLargerThanTheNewPriceChargesZero(): void
	{
		// Anual del elemental, pagada en 9,000.00. A los 15 días sube a premium mensual: la bonificación
		// (8,631.15) es mayor que el precio nuevo (1,500.00), y el monto sugerido es 0.
		[$license] = $this->activated('subscription', ['billing_period' => 'annual', 'amount' => '9000']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($license, [
			'plan_id' => 'premium',
			'billing_period' => 'monthly',
		]);
		self::assertSame([863115, 150000, 0], [$quote['credit'], $quote['catalog'], $quote['suggested']]);
		$this->change($license, ['plan_id' => 'premium', 'billing_period' => 'monthly', 'amount' => '0']);
		$payment = $this->lastPayment($license);
		self::assertSame(
			['0.00', '0.00', '8631.15'],
			[$payment['amount'], $payment['suggested_amount'], $payment['credit_amount']],
		);
		self::assertSame(
			['monthly', '2027-04-16 16:00:00.000000'],
			[$this->license($license)['billing_period'], $this->license($license)['expires_at']],
		);
	}

	public function testAMixedChangeRaisesNowAndSchedulesTheReductionForThePreviousExpiry(): void
	{
		[$license, $installation] = $this->activated('subscription', ['amount' => '900']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		// Elemental (3, 3, 3) a barra (5, 10, 1): suben cajas y comanderos, baja KDS.
		$result = $this->change($license, ['plan_id' => 'barra', 'amount' => '835.48']);
		self::assertSame(['increase', '2027-04-01T16:00:00.000000Z'], [$result['kind'], $result['scheduled_at']]);
		// Hasta el vencimiento anterior, la licencia conserva el número mayor de KDS.
		self::assertSame([5, 10, 3], $this->limits($license));
		$content = $installation->refresh();
		self::assertSame(['cash_registers' => 5, 'order_takers' => 10, 'kitchen_displays' => 3], $content['limits']);
		self::assertSame(
			['barra', '2027-04-16T16:00:00.000000Z'],
			[$content['plan']['plan_id'], $content['expires_at']],
		);
		$pending = $this->pending($license);
		self::assertSame(
			['2027-04-01 16:00:00.000000', 'barra', 0, 5, 10, 1],
			[
				$pending['effective_at'],
				$pending['plan_id'],
				(int) $pending['plan_custom'],
				(int) $pending['cash_registers'],
				(int) $pending['order_takers'],
				(int) $pending['kitchen_displays'],
			],
		);
		// La bonificación es la misma que en un aumento puro: 1,300.00 − 464.52.
		self::assertSame(
			['835.48', '464.52'],
			[$this->lastPayment($license)['amount'], $this->lastPayment($license)['credit_amount']],
		);
		// Un aumento del catálogo del plan de destino también sube lo programado.
		$this->app->plans->update($this->actor, Uuid::create(), 'barra', [
			'display_name' => 'Plan barra',
			'cash_registers' => '5',
			'order_takers' => '10',
			'kitchen_displays' => '2',
			'price_monthly' => '1300',
			'version' => 1,
			'apply_to' => 'existing',
		]);
		self::assertSame(2, (int) $this->pending($license)['kitchen_displays']);
		self::assertSame([5, 10, 3], $this->limits($license));
		// Pasó el instante y la tarea no corrió: un cambio de plan aplica primero la reducción pendiente y se
		// compara con (5, 10, 2). Volver al elemental (3, 3, 3) sube KDS y baja cajas y comanderos: es mixto.
		$this->clock->instant = new \DateTimeImmutable('2027-04-02T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'elemental']);
		self::assertSame(
			['increase', ['kitchen_displays'], ['cash_registers', 'order_takers']],
			[$quote['kind'], $quote['up'], $quote['down']],
		);
		$this->change($license, [
			'plan_id' => 'elemental',
			'amount' => \AdelPos\License\Domain\Money::toSql($quote['suggested']),
		]);
		self::assertSame([5, 10, 3], $this->limits($license));
		self::assertSame(
			['applied', 'pending'],
			array_column(
				$this->fixture->owner->all('SELECT state FROM license_scheduled_changes ORDER BY created_at,state'),
				'state',
			),
		);
		self::assertSame(
			['elemental', '2027-04-16 16:00:00.000000'],
			[$this->pending($license)['plan_id'], $this->pending($license)['effective_at']],
		);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_changes WHERE action='license.scheduled_change_applied'")
				->fetchColumn(),
		);
	}

	public function testAPureReductionIsScheduledWithoutChargeCancelledAndReplaced(): void
	{
		[$license, $installation] = $this->activated('subscription', ['amount' => '1500'], 'premium');
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$result = $this->change($license, ['plan_id' => 'basico']);
		self::assertSame(
			['reduction', 0, '2027-04-01T16:00:00.000000Z'],
			[$result['kind'], $result['revisions_signed'], $result['scheduled_at']],
		);
		// No se cobra, no se firma y los números no cambian hasta el vencimiento.
		self::assertSame([5, 10, 5], $this->limits($license));
		self::assertSame(1, $installation->refresh()['license_revision']);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute('SELECT COUNT(*) FROM license_payments WHERE license_id=?', [Uuid::bytes($license)])
				->fetchColumn(),
		);
		self::assertSame('basico', $this->pending($license)['plan_id']);
		$this->rejected(
			'no se cobra al programarla',
			fn() => $this->change($license, ['plan_id' => 'elemental', 'amount' => '900']),
		);
		// Uno nuevo sustituye al anterior.
		$this->change($license, ['plan_id' => 'elemental']);
		self::assertSame('elemental', $this->pending($license)['plan_id']);
		self::assertSame(
			['replaced', 'pending'],
			array_column(
				$this->fixture->owner->all('SELECT state FROM license_scheduled_changes ORDER BY created_at,plan_id'),
				'state',
			),
		);
		// Se cancela, queda en el historial, y no se puede cancelar dos veces.
		$this->app->commercial->cancelScheduledChange($this->actor, Uuid::create(), $license, [
			'version' => (int) $this->license($license)['row_version'],
			'reason' => 'El cliente se queda en premium',
		]);
		self::assertNull($this->pending($license));
		try {
			$this->app->commercial->cancelScheduledChange($this->actor, Uuid::create(), $license, [
				'version' => (int) $this->license($license)['row_version'],
			]);
			self::fail('Cancelled twice');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		self::assertSame(
			['license.plan_change_scheduled', 'license.plan_change_scheduled', 'license.scheduled_change_cancelled'],
			array_column(
				$this->fixture->owner->all(
					"SELECT action FROM license_changes WHERE action LIKE '%scheduled%' ORDER BY version",
				),
				'action',
			),
		);
		// La base admite un solo cambio pendiente por licencia.
		$change = $this->fixture->owner->one('SELECT * FROM license_scheduled_changes LIMIT 1');
		try {
			$this->fixture->owner->execute(
				"UPDATE license_scheduled_changes SET state='pending',resolved_at=NULL,resolved_by=NULL WHERE license_id=?",
				[$change['license_id']],
			);
			self::fail('Two pending changes for one license');
		} catch (\PDOException $error) {
			self::assertSame(1062, (int) $error->errorInfo[1]);
		}
	}

	public function testAPaymentAfterTheExpiryIsARenewalWithTheNewPlan(): void
	{
		// Vence el 1 de abril. El 3 de abril, en tolerancia, baja a básico pagando: es una renovación con
		// plan nuevo, desde el vencimiento, con los números nuevos y sin bonificación ni nada programado.
		[$license, $installation] = $this->activated('subscription', ['amount' => '900']);
		$this->clock->instant = new \DateTimeImmutable('2027-04-03T16:00:00Z');
		$result = $this->change($license, ['plan_id' => 'basico', 'amount' => '400']);
		self::assertSame(['renewal', null], [$result['kind'], $result['scheduled_at']]);
		$content = $installation->refresh();
		self::assertSame(
			['2027-04-01T16:00:00.000000Z', '2027-05-01T16:00:00.000000Z', 'basico'],
			[$content['period_starts_at'], $content['expires_at'], $content['plan']['plan_id']],
		);
		self::assertSame([1, 0, 0], $this->limits($license));
		self::assertSame(
			['renewal', '0.00', '400.00'],
			[
				$this->lastPayment($license)['concept'],
				$this->lastPayment($license)['credit_amount'],
				$this->lastPayment($license)['suggested_amount'],
			],
		);
		self::assertNull($this->pending($license));
		// Un aumento pagado después de la tolerancia también es una renovación: desde la fecha de pago.
		$this->clock->instant = new \DateTimeImmutable('2027-05-20T16:00:00Z');
		$result = $this->change($license, ['plan_id' => 'premium', 'amount' => '1500']);
		self::assertSame('renewal', $result['kind']);
		self::assertSame(
			['2027-05-20T16:00:00.000000Z', '2027-06-20T16:00:00.000000Z'],
			[$installation->refresh()['period_starts_at'], $installation->refresh()['expires_at']],
		);
	}

	public function testAPerpetualOnlyGoesUpPayingTheDifference(): void
	{
		[$license, $installation] = $this->activated('perpetual', ['amount' => '15000']);
		$updates = $installation->refresh()['updates_until'];
		$this->clock->instant = new \DateTimeImmutable('2027-06-01T16:00:00Z');
		$this->rejected(
			'no puede bajar de plan',
			fn() => $this->change($license, ['plan_id' => 'basico', 'amount' => '0']),
		);
		$this->rejected(
			'sube unos cupos y baja otros',
			fn() => $this->change($license, ['plan_id' => 'barra', 'amount' => '0']),
		);
		$quote = $this->app->commercial->quotePlanChange($license, ['plan_id' => 'premium']);
		self::assertSame(
			['perpetual', 2500000, 1500000, 1000000],
			[$quote['kind'], $quote['catalog'], $quote['credit'], $quote['suggested']],
		);
		$this->change($license, ['plan_id' => 'premium', 'amount' => '10000']);
		$content = $installation->refresh();
		self::assertSame(
			[2, 'premium', null, $updates],
			[
				$content['license_revision'],
				$content['plan']['plan_id'],
				$content['expires_at'],
				$content['updates_until'],
			],
		);
		self::assertSame(
			['plan_change', 'perpetual', '10000.00', null],
			[
				$this->lastPayment($license)['concept'],
				$this->lastPayment($license)['term'],
				$this->lastPayment($license)['amount'],
				$this->lastPayment($license)['covers_from'],
			],
		);
		// Otro plan con los mismos números no es una subida.
		$this->fixture->owner->execute(
			"INSERT INTO plans(plan_id,display_name,cash_registers,order_takers,kitchen_displays,created_at,updated_at)
				VALUES ('gemelo','Plan gemelo',5,10,5,NOW(6),NOW(6))",
		);
		$this->rejected(
			'no sube ningún cupo',
			fn() => $this->change($license, ['plan_id' => 'gemelo', 'amount' => '0']),
		);
	}

	public function testATrialChangesPlanFreelyAndACustomLicenseNeedsAHandWrittenAmount(): void
	{
		[$trial, $installation] = $this->activated('trial', [], 'basico');
		$before = $installation->refresh();
		$this->rejected(
			'durante la prueba no tiene cobro',
			fn() => $this->change($trial, ['plan_id' => 'premium', 'amount' => '10']),
		);
		$this->change($trial, ['plan_id' => 'premium']);
		$after = $installation->refresh();
		self::assertSame(
			[$before['expires_at'], $before['period_starts_at'], 'premium', 2],
			[$after['expires_at'], $after['period_starts_at'], $after['plan']['plan_id'], $after['license_revision']],
		);
		// También baja, libre e inmediato.
		$this->change($trial, ['plan_id' => 'basico']);
		self::assertSame([1, 0, 0], $this->limits($trial));
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute('SELECT COUNT(*) FROM license_payments WHERE license_id=?', [Uuid::bytes($trial)])
				->fetchColumn(),
		);
		// Una personalizada: su destino personalizado no tiene precio y el monto se escribe a mano. Elegir un plan
		// del catálogo con sus números le quita la marca.
		[$custom] = $this->activated('subscription', ['cash_registers' => '4', 'amount' => '1000']);
		$this->clock->instant = new \DateTimeImmutable('2027-03-16T16:00:00Z');
		$quote = $this->app->commercial->quotePlanChange($custom, ['plan_id' => 'premium', 'kitchen_displays' => '6']);
		self::assertSame([null, true], [$quote['suggested'], $quote['target']['custom']]);
		$quote = $this->app->commercial->quotePlanChange($custom, ['plan_id' => 'premium']);
		self::assertSame(false, $quote['target']['custom']);
		self::assertSame(['payment', 51613], [$quote['credit_basis']['source'], $quote['credit']]);
		$this->change($custom, ['plan_id' => 'premium', 'amount' => '983.87']);
		self::assertSame(
			[0, 'premium'],
			[(int) $this->license($custom)['plan_custom'], $this->license($custom)['plan_id']],
		);
	}

	public function testWhatCannotChangePlanAndTwoOperatorsAtOnce(): void
	{
		$unactivated = $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('subscription'),
			'amount' => '900',
		]);
		$this->rejected(
			'editando su periodo inicial',
			fn() => $this->change($unactivated['id'], ['plan_id' => 'premium', 'amount' => '0']),
		);
		[$license, $installation] = $this->activated('subscription', ['amount' => '900']);
		$this->rejected('ya tiene ese plan y esos cupos', fn() => $this->change($license, ['plan_id' => 'elemental']));
		$this->fixture->owner->execute("UPDATE plans SET state='archived' WHERE plan_id='premium'");
		$this->rejected('archivado', fn() => $this->change($license, ['plan_id' => 'premium', 'amount' => '0']));
		$this->fixture->owner->execute("UPDATE plans SET state='active' WHERE plan_id='premium'");
		$this->rejected('Escribe el monto cobrado', fn() => $this->change($license, ['plan_id' => 'premium']));
		// Dos operadores con el mismo formulario: uno cambia el plan y el otro recibe 409.
		$version = (int) $this->license($license)['row_version'];
		$job = fn() => [
			'service' => 'commercial',
			'method' => 'changePlan',
			'operation' => Uuid::create(),
			// Al empezar el periodo, la bonificación es todo lo pagado: 1,500.00 − 900.00.
			'arguments' => [$license, ['version' => $version, 'plan_id' => 'premium', 'amount' => '600']],
		];
		$results = Concurrency::race($this->fixture, $this->actor->id, $license, '2027-03-01T16:00:00Z', [
			$job(),
			$job(),
		]);
		self::assertCount(1, array_filter($results, fn(array $result): bool => isset($result['result'])));
		self::assertSame(
			[409],
			array_values(array_column(array_filter($results, fn(array $r): bool => isset($r['status'])), 'status')),
		);
		self::assertSame(2, $installation->refresh()['license_revision']);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_payments WHERE concept='plan_change'")
				->fetchColumn(),
		);
		$this->app->licenses->change($this->actor, Uuid::create(), $license, 'revoke', [
			'version' => (int) $this->license($license)['row_version'],
			'reason' => 'Prueba de revocación',
		]);
		$this->rejected('revocada', fn() => $this->change($license, ['plan_id' => 'basico']));
	}
}
