<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Precios del catálogo y registro de pagos (etapa 3, Fase 1): precios opcionales en MXN, monto de
 * alta sugerido y modificable con motivo, y un historial de pagos de solo inserción.
 */
final class PaymentsTest extends TestCase
{
	private MySqlFixture $fixture;
	private FrozenClock $clock;
	private Actor $actor;
	private string $customer;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$app = $this->fixture->app;
		$this->actor = new Actor(
			$app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
		$this->customer = $app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Restaurante sintético',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
			'time_zone' => 'America/Tijuana',
		])['id'];
		$this->fixture->owner->execute(
			"UPDATE plans
				SET price_monthly='799.00',price_annual='7990.00',price_perpetual='12000.00'
				WHERE plan_id='elemental'",
		);
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function issue(string $type, array $input = [], ?string $operation = null): array
	{
		return $this->fixture->app->licenses->issue(
			$this->actor,
			$operation ?? Uuid::create(),
			array_replace(
				['customer_id' => $this->customer, 'product_id' => 'adel_pos', ...MySqlFixture::terms($type)],
				$input,
			),
		);
	}

	/** El pago de alta de una licencia, con los montos como texto, tal como los guarda la base. */
	private function payment(string $license): array
	{
		$payments = $this->fixture->owner->all(
			'SELECT p.concept,p.term,p.plan_id,p.paid_at,p.currency,p.amount,p.catalog_amount,p.credit_amount,
					p.suggested_amount,p.override_reason,p.reference,p.covers_from,p.covers_until,
					l.period_payment_id=p.payment_id AS pays_the_period
				FROM license_payments p
				JOIN licenses l ON l.license_id=p.license_id
				WHERE p.license_id=?',
			[Uuid::bytes($license)],
		);
		self::assertCount(1, $payments);
		$payments[0]['pays_the_period'] = (int) $payments[0]['pays_the_period'];
		return $payments[0];
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

	private function rows(string $table): int
	{
		return (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
	}

	/** Runs one statement as the owner and rolls it back; returns the MySQL error number, or 0 when accepted. */
	private function attempt(string $sql, array $parameters = []): int
	{
		$owner = $this->fixture->owner;
		$owner->pdo->beginTransaction();
		try {
			$owner->execute($sql, $parameters);
			return 0;
		} catch (\PDOException $error) {
			return (int) $error->errorInfo[1];
		} finally {
			$owner->pdo->rollBack();
		}
	}

	public function testPricesAreOptionalAndNeverNegative(): void
	{
		$columns = [
			'price_monthly',
			'price_quarterly',
			'price_semiannual',
			'price_annual',
			'price_perpetual',
			'price_maintenance',
		];
		foreach ($columns as $column) {
			foreach (['NULL', "'0.00'", "'1500.50'", "'99999999.99'"] as $value) {
				self::assertSame(0, $this->attempt("UPDATE plans SET $column=$value WHERE plan_id='basico'"), $column);
			}
			self::assertSame(3819, $this->attempt("UPDATE plans SET $column='-0.01' WHERE plan_id='basico'"), $column);
		}
		// El catálogo inicial no trae precios: todos son opcionales.
		$prices = $this->fixture->owner->one(
			"SELECT price_monthly,price_quarterly,price_semiannual,price_annual,price_perpetual,price_maintenance
				FROM plans
				WHERE plan_id='premium'",
		);
		self::assertSame(array_fill_keys($columns, null), $prices);
	}

	public function testIssuanceSuggestsTheCatalogPriceAndTheAmountCanBeChangedWithAReason(): void
	{
		// The suggested price of the plan and the term is charged as it is.
		$monthly = $this->issue('subscription', ['amount' => '799', 'reference' => 'PEDIDO-1']);
		self::assertSame(
			[
				'concept' => 'issue',
				'term' => 'monthly',
				'plan_id' => 'elemental',
				'paid_at' => '2026-09-25 12:00:00.000000',
				'currency' => 'MXN',
				'amount' => '799.00',
				'catalog_amount' => '799.00',
				'credit_amount' => '0.00',
				'suggested_amount' => '799.00',
				'override_reason' => null,
				'reference' => 'PEDIDO-1',
				// Las fechas del primer periodo se fijan al activar.
				'covers_from' => null,
				'covers_until' => null,
				'pays_the_period' => 1,
			],
			$this->payment($monthly['id']),
		);
		// Another amount needs a reason, and nothing is written without it.
		$before = [$this->rows('licenses'), $this->rows('license_payments'), $this->rows('admin_operations')];
		$this->rejected(
			'distinto del sugerido ($7,990.00 MXN). Escribe el motivo',
			fn() => $this->issue('subscription', ['billing_period' => 'annual', 'amount' => '7000']),
		);
		self::assertSame($before, [
			$this->rows('licenses'),
			$this->rows('license_payments'),
			$this->rows('admin_operations'),
		]);
		$annual = $this->issue('subscription', [
			'billing_period' => 'annual',
			'amount' => '7000.5',
			'amount_reason' => 'Descuento por pago anual',
		]);
		$payment = $this->payment($annual['id']);
		self::assertSame(
			['annual', '7000.50', '7990.00', '7990.00', 'Descuento por pago anual'],
			[
				$payment['term'],
				$payment['amount'],
				$payment['catalog_amount'],
				$payment['suggested_amount'],
				$payment['override_reason'],
			],
		);
		// A reason without a changed amount is not silently dropped.
		$this->rejected(
			'el motivo solo se escribe cuando lo cambias',
			fn() => $this->issue('subscription', ['amount' => '799.00', 'amount_reason' => 'Sin cambio']),
		);
		// The perpetual price, a term without price and a custom license: the amount is written by hand.
		$perpetual = $this->payment($this->issue('perpetual', ['amount' => '12000'])['id']);
		self::assertSame(
			['perpetual', '12000.00', '12000.00'],
			[$perpetual['term'], $perpetual['amount'], $perpetual['catalog_amount']],
		);
		foreach (
			[
				'sin precio' => ['billing_period' => 'quarterly', 'amount' => '2100'],
				'personalizada' => ['cash_registers' => '4', 'amount' => '950'],
			]
			as $case => $input
		) {
			$payment = $this->payment($this->issue('subscription', $input)['id']);
			self::assertSame(
				[null, null, null],
				[$payment['catalog_amount'], $payment['suggested_amount'], $payment['override_reason']],
				$case,
			);
			$this->rejected(
				'No hay monto sugerido',
				fn() => $this->issue('subscription', $input + ['amount_reason' => 'Nota']),
			);
		}
		// Una prueba no tiene costo: su monto es 0, se escriba o no.
		foreach (['', '0', '0.00'] as $amount) {
			$trial = $this->payment($this->issue('trial', ['amount' => $amount])['id']);
			self::assertSame(
				['trial', '0.00', '0.00', '0.00'],
				[$trial['term'], $trial['amount'], $trial['catalog_amount'], $trial['suggested_amount']],
			);
		}
		$this->rejected('no tiene costo', fn() => $this->issue('trial', ['amount' => '10']));
		$this->rejected('Escribe el monto cobrado.', fn() => $this->issue('perpetual', ['amount' => '']));
		$this->rejected('no puede ser negativo', fn() => $this->issue('perpetual', ['amount' => '-1']));
	}

	public function testThePaymentDateIsCapturedInTheCustomerTimeZoneAndCannotBeInTheFuture(): void
	{
		$paidAt = fn(string $value): string => $this->payment(
			$this->issue('perpetual', ['paid_at' => $value, 'amount' => '12000'])['id'],
		)['paid_at'];
		// Tijuana va 7 horas detrás de UTC en septiembre; sin segundos, y con ellos.
		self::assertSame('2026-09-25 11:30:00.000000', $paidAt('2026-09-25T04:30'));
		self::assertSame('2026-09-24 07:15:42.000000', $paidAt('2026-09-24T00:15:42'));
		// El instante exacto que fija la pantalla de revisión conserva sus microsegundos.
		self::assertSame('2026-09-25 11:59:59.123456', $paidAt('2026-09-25T11:59:59.123456Z'));
		self::assertSame('2026-09-25 12:00:00.000000', $paidAt('2026-09-25T12:00:00Z'));
		// Vacía, es este momento.
		self::assertSame('2026-09-25 12:00:00.000000', $paidAt(''));
		foreach (['2026-09-25T05:01', '2026-09-25T12:00:00.000001Z', '2027-01-01T00:00'] as $future) {
			$this->rejected(
				'no puede ser posterior',
				fn() => $this->issue('perpetual', ['paid_at' => $future, 'amount' => '12000']),
			);
		}
		foreach (['2026-02-30T10:00', '2026-09-25 10:00', '2026-09-25T24:00', '25/09/2026 10:00', 'ayer'] as $bad) {
			$this->rejected(
				'Escribe la fecha de pago',
				fn() => $this->issue('perpetual', ['paid_at' => $bad, 'amount' => '12000']),
			);
		}
	}

	public function testPaymentsAreAppendOnlyAndTheDatabaseKeepsTheirRules(): void
	{
		$license = $this->issue('subscription', ['amount' => '799']);
		$id = $this->fixture->owner->one('SELECT payment_id FROM license_payments')['payment_id'];
		// La cuenta de la aplicación solo lee e inserta pagos.
		foreach (['UPDATE license_payments SET amount=0 WHERE 1=0', 'DELETE FROM license_payments WHERE 1=0'] as $sql) {
			try {
				$this->fixture->app->db->execute($sql);
				self::fail('Privilege allowed: ' . $sql);
			} catch (\PDOException $error) {
				self::assertSame(1142, (int) $error->errorInfo[1], $sql);
			}
		}
		// Las reglas se cumplen aunque un escritor se salte la aplicación.
		$rejected = [
			// Lo sugerido es el catálogo menos la bonificación, nunca menos de 0.
			"suggested_amount='700.00'",
			'catalog_amount=NULL',
			"credit_amount='100.00'",
			// Un monto distinto del sugerido exige motivo, y un motivo exige un monto distinto.
			"amount='700.00'",
			"override_reason='Nota'",
			"amount='700.00',override_reason=''",
			"currency='USD'",
			"amount='-1.00'",
			// Negativos que cumplen las demás reglas: solo la de montos no negativos los detiene.
			"amount='-1.00',override_reason='Devolución'",
			"credit_amount='-1.00',suggested_amount='800.00',amount='800.00'",
			"catalog_amount='-5.00',suggested_amount='0.00',amount='0.00'",
			"concept='regalo'",
			"concept='maintenance'",
			"term='maintenance'",
			"term='weekly'",
			"term='trial'",
			"covers_from='2026-09-25 12:00:00'",
			"covers_from='2026-10-25 12:00:00',covers_until='2026-09-25 12:00:00'",
		];
		foreach ($rejected as $assignment) {
			self::assertSame(
				3819,
				$this->attempt("UPDATE license_payments SET $assignment WHERE payment_id=?", [$id]),
				$assignment,
			);
		}
		foreach (
			[
				"amount='700.00',override_reason='Descuento'",
				"catalog_amount='900.00',credit_amount='150.00',suggested_amount='750.00',amount='750.00'",
				"catalog_amount='100.00',credit_amount='150.00',suggested_amount='0.00',amount='0.00'",
				"catalog_amount=NULL,suggested_amount=NULL,amount='5.00'",
				"covers_from='2026-09-25 12:00:00',covers_until='2026-10-25 12:00:00'",
				"concept='maintenance',term='maintenance'",
				"concept='renewal',term='annual'",
			]
			as $assignment
		) {
			self::assertSame(
				0,
				$this->attempt("UPDATE license_payments SET $assignment WHERE payment_id=?", [$id]),
				$assignment,
			);
		}
		// El pago que cubre el periodo debe ser de la misma licencia.
		$other = $this->issue('perpetual', ['amount' => '12000']);
		self::assertSame(
			1452,
			$this->attempt('UPDATE licenses SET period_payment_id=? WHERE license_id=?', [
				$id,
				Uuid::bytes($other['id']),
			]),
		);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute('SELECT COUNT(*) FROM licenses WHERE period_payment_id=? AND license_id=?', [
					$id,
					Uuid::bytes($license['id']),
				])
				->fetchColumn(),
		);
	}

	public function testTheIssuanceAndItsPaymentAreIdempotentAndEveryPaymentIsAudited(): void
	{
		$app = $this->fixture->app;
		$operation = Uuid::create();
		$input = ['amount' => '800', 'amount_reason' => 'Ajuste comercial'];
		$first = $this->issue('subscription', $input, $operation);
		$again = $this->issue('subscription', $input, $operation);
		self::assertFalse($first['replayed']);
		self::assertTrue($again['replayed']);
		self::assertSame($first['id'], $again['id']);
		self::assertSame([1, 1], [$this->rows('licenses'), $this->rows('license_payments')]);
		try {
			$this->issue('subscription', ['amount' => '801', 'amount_reason' => 'Ajuste comercial'], $operation);
			self::fail('The same operation was reused for another payment');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		self::assertSame(1, $this->rows('license_payments'));
		// El pago queda en la auditoría con sus montos, ligado a su operación.
		$events = $app->db->all(
			"SELECT target_id,payload_json,actor_id FROM audit_events WHERE operation='payment.recorded'",
		);
		self::assertCount(1, $events);
		$event = json_decode($events[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($first['id'], $events[0]['target_id']);
		self::assertSame(Uuid::bytes($this->actor->id), $events[0]['actor_id']);
		self::assertSame('Ajuste comercial', $event['reason']);
		$paymentId = $this->fixture->owner->one('SELECT BIN_TO_UUID(payment_id) AS id FROM license_payments')['id'];
		self::assertSame(
			[
				'payment_id' => $paymentId,
				'concept' => 'issue',
				'term' => 'monthly',
				'plan_id' => 'elemental',
				'paid_at' => '2026-09-25T12:00:00.000000Z',
				'currency' => 'MXN',
				'amount' => '800.00',
				'catalog_amount' => '799.00',
				'credit_amount' => '0.00',
				'suggested_amount' => '799.00',
				'overridden' => true,
			],
			$event['metadata'],
		);
		self::assertSame(
			Uuid::bytes($operation),
			$this->fixture->owner->one('SELECT operation_id FROM license_payments')['operation_id'],
		);
		self::assertGreaterThan(2, $app->audit->verify());
		// Si la auditoría falla, tampoco queda el pago.
		$this->fixture->owner->execute('UPDATE audit_heads SET last_sequence=0');
		try {
			$this->issue('perpetual', ['amount' => '12000']);
			self::fail('Audit collision should fail');
		} catch (\PDOException) {
		}
		self::assertSame([1, 1], [$this->rows('licenses'), $this->rows('license_payments')]);
	}
}
