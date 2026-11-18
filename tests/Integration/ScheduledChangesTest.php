<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\Uuid;
use AdelPos\License\Tests\{Concurrency, FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Tarea programada de reducciones (etapa 3, Fase 7): aplica los cambios programados al llegar su
 * instante, una sola vez, y se combina con la renovación.
 */
final class ScheduledChangesTest extends TestCase
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
		// Suscripción mensual premium activada el 1 de marzo de 2027 a las 10:00 de Monterrey: vence el 1 de abril.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-03-01T16:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('scheduled-test-key', 'license');
		$keys->activate('scheduled-test-key', 'Isolated test setup');
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
		$this->fixture->owner->execute("UPDATE plans SET price_monthly='400.00' WHERE plan_id='basico'");
		$this->fixture->owner->execute("UPDATE plans SET price_monthly='1500.00' WHERE plan_id='premium'");
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	/**
	 * Suscripción premium activada con el reloj actual, con una reducción a básico programada desde
	 * $changeAt para el vencimiento.
	 */
	private function scheduled(string $changeAt = '2027-03-10T16:00:00Z'): array
	{
		$issued = $this->app->licenses->issue($this->actor, Uuid::create(), [
			'customer_id' => $this->customer,
			'product_id' => 'adel_pos',
			...MySqlFixture::terms('subscription', 'premium'),
			'amount' => '1500',
		]);
		$installation = new Installation($this->app);
		$installation->activate($issued['secret']);
		$this->clock->instant = new \DateTimeImmutable($changeAt);
		$this->app->commercial->changePlan($this->actor, Uuid::create(), $issued['id'], [
			'version' => (int) $this->license($issued['id'])['row_version'],
			'plan_id' => 'basico',
		]);
		return [$issued['id'], $installation];
	}

	private function license(string $id): array
	{
		return $this->fixture->owner->one('SELECT * FROM licenses WHERE license_id=?', [Uuid::bytes($id)]);
	}

	private function limits(string $id): array
	{
		$row = $this->license($id);
		return [(int) $row['cash_registers'], (int) $row['order_takers'], (int) $row['kitchen_displays']];
	}

	private function state(string $id): string
	{
		return $this->fixture->owner->one(
			'SELECT state FROM license_scheduled_changes WHERE license_id=? ORDER BY created_at DESC LIMIT 1',
			[Uuid::bytes($id)],
		)['state'];
	}

	private function renew(string $id, string $amount): array
	{
		return $this->app->commercial->renew($this->actor, Uuid::create(), $id, [
			'version' => (int) $this->license($id)['row_version'],
			'amount' => $amount,
		]);
	}

	public function testTheTaskAppliesTheChangeOnlyWhenItsInstantArrives(): void
	{
		[$license, $installation] = $this->scheduled();
		// Un microsegundo antes: nada.
		$this->clock->instant = new \DateTimeImmutable('2027-04-01T15:59:59.999999Z');
		self::assertSame(
			['applied' => 0, 'revisions_signed' => 0, 'skipped' => 0],
			$this->app->scheduledChanges->applyAllDue(),
		);
		self::assertSame([5, 10, 5], $this->limits($license));
		self::assertSame('pending', $this->state($license));
		// Justo en el instante: se aplica y se firma una revisión para la activación vigente.
		$this->clock->instant = new \DateTimeImmutable('2027-04-01T16:00:00Z');
		self::assertSame(
			['applied' => 1, 'revisions_signed' => 1, 'skipped' => 0],
			$this->app->scheduledChanges->applyAllDue(),
		);
		self::assertSame([1, 0, 0], $this->limits($license));
		self::assertSame('applied', $this->state($license));
		$content = $installation->refresh();
		self::assertSame(
			[2, 'basico', ['cash_registers' => 1, 'order_takers' => 0, 'kitchen_displays' => 0]],
			[$content['license_revision'], $content['plan']['plan_id'], $content['limits']],
		);
		// Después, otra ejecución no vuelve a aplicarlo.
		$this->clock->instant = new \DateTimeImmutable('2027-04-02T16:00:00Z');
		self::assertSame(
			['applied' => 0, 'revisions_signed' => 0, 'skipped' => 0],
			$this->app->scheduledChanges->applyAllDue(),
		);
		self::assertSame(2, $installation->refresh()['license_revision']);
		// La auditoría lo atribuye al sistema, y el historial a quien lo programó.
		$event = $this->fixture->owner->one(
			"SELECT actor_id,payload_json FROM audit_events WHERE operation='license.scheduled_change_applied'",
		);
		self::assertNull($event['actor_id']);
		self::assertSame(
			Uuid::bytes($this->actor->id),
			$this->fixture->owner->one(
				"SELECT admin_id FROM license_changes WHERE action='license.scheduled_change_applied'",
			)['admin_id'],
		);
		self::assertSame(
			3,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM audit_events WHERE operation='maintenance.scheduled_changes'")
				->fetchColumn(),
		);
	}

	public function testTwoSimultaneousRunsApplyTheChangeOnce(): void
	{
		[$license, $installation] = $this->scheduled();
		$job = fn() => [
			'service' => 'scheduledChanges',
			'method' => 'applyAllDue',
			'operation' => null,
			'arguments' => [],
		];
		$results = Concurrency::race($this->fixture, $this->actor->id, $license, '2027-04-01T16:00:00Z', [
			$job(),
			$job(),
		]);
		$applied = array_sum(array_map(fn(array $run): int => $run['result']['applied'], $results));
		$skipped = array_sum(array_map(fn(array $run): int => $run['result']['skipped'], $results));
		self::assertSame([1, 1], [$applied, $skipped]);
		self::assertSame([1, 0, 0], $this->limits($license));
		self::assertSame(2, $installation->refresh()['license_revision']);
		self::assertSame(
			1,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_changes WHERE action='license.scheduled_change_applied'")
				->fetchColumn(),
		);
	}

	public function testARenewalBeforeTheInstantKeepsTheLargerNumbersUntilTheTaskRuns(): void
	{
		[$license, $installation] = $this->scheduled();
		// Renueva el 25 de marzo: la renovación sugiere el precio del plan nuevo y firma el periodo extendido
		// con los números mayores.
		$this->clock->instant = new \DateTimeImmutable('2027-03-25T16:00:00Z');
		self::assertSame(40000, $this->app->commercial->quoteRenewal($license, [])['suggested']);
		$this->renew($license, '400');
		$content = $installation->refresh();
		self::assertSame(
			['2027-05-01T16:00:00.000000Z', ['cash_registers' => 5, 'order_takers' => 10, 'kitchen_displays' => 5]],
			[$content['expires_at'], $content['limits']],
		);
		self::assertSame('pending', $this->state($license));
		// Al llegar el instante, la tarea firma la reducción; el vencimiento renovado no cambia.
		$this->clock->instant = new \DateTimeImmutable('2027-04-01T17:00:00Z');
		$this->app->scheduledChanges->applyAllDue();
		$content = $installation->refresh();
		self::assertSame(
			[3, '2027-05-01T16:00:00.000000Z', 'basico'],
			[$content['license_revision'], $content['expires_at'], $content['plan']['plan_id']],
		);
	}

	public function testARenewalAfterTheInstantAppliesTheReductionInItsOwnRevision(): void
	{
		[$license, $installation] = $this->scheduled();
		// La tarea no corrió. El 3 de abril, en tolerancia, se renueva: una sola revisión nueva trae el periodo
		// renovado y los números del plan nuevo.
		$this->clock->instant = new \DateTimeImmutable('2027-04-03T16:00:00Z');
		self::assertSame(40000, $this->app->commercial->quoteRenewal($license, [])['suggested']);
		$this->renew($license, '400');
		$content = $installation->refresh();
		self::assertSame(
			[
				2,
				'2027-05-01T16:00:00.000000Z',
				'basico',
				['cash_registers' => 1, 'order_takers' => 0, 'kitchen_displays' => 0],
			],
			[$content['license_revision'], $content['expires_at'], $content['plan']['plan_id'], $content['limits']],
		);
		self::assertSame('applied', $this->state($license));
		self::assertSame(
			['applied' => 0, 'revisions_signed' => 0, 'skipped' => 0],
			$this->app->scheduledChanges->applyAllDue(),
		);
	}

	public function testAnExpiredLicenseReceivesTheChangeAndACancelledChangeIsNeverApplied(): void
	{
		[$license, $installation] = $this->scheduled();
		// Venció y nadie renovó: la reducción se aplica igual, y una renovación posterior parte del plan nuevo.
		$this->clock->instant = new \DateTimeImmutable('2027-05-01T00:00:00Z');
		self::assertSame(1, $this->app->scheduledChanges->applyAllDue()['applied']);
		self::assertSame([1, 0, 0], $this->limits($license));
		self::assertSame('2027-04-01T16:00:00.000000Z', $installation->refresh()['expires_at']);
		self::assertSame(40000, $this->app->commercial->quoteRenewal($license, [])['suggested']);
		// Un cambio cancelado ya no se aplica.
		$this->clock->instant = new \DateTimeImmutable('2027-03-01T16:00:00Z');
		[$other] = $this->scheduled();
		$this->app->commercial->cancelScheduledChange($this->actor, Uuid::create(), $other, [
			'version' => (int) $this->license($other)['row_version'],
		]);
		$this->clock->instant = new \DateTimeImmutable('2027-05-01T00:00:00Z');
		self::assertSame(0, $this->app->scheduledChanges->applyAllDue()['applied']);
		self::assertSame([5, 10, 5], $this->limits($other));
		self::assertSame('cancelled', $this->state($other));
	}

	public function testTheCommandOfTheDailyTaskAppliesTheChangesThatAreDue(): void
	{
		// El comando usa el reloj real: el cambio se programa con fechas que ya pasaron.
		$this->clock->instant = new \DateTimeImmutable('2025-03-01T16:00:00Z');
		[$license, $installation] = $this->scheduled('2025-03-10T16:00:00Z');
		$directory = sys_get_temp_dir() . '/adelpos-license-task-' . bin2hex(random_bytes(8));
		mkdir($directory, 0700);
		try {
			file_put_contents(
				$directory . '/config.php',
				'<?php return ' . var_export($this->fixture->values, true) . ';',
			);
			chmod($directory . '/config.php', 0600);
			$environment = array_diff_key(getenv(), $this->fixture->values);
			$environment['ADELPOS_LICENSE_CONFIG'] = $directory . '/config.php';
			$run = fn(): string => \AdelPos\License\Tests\NativeStackFixture::command(
				[PHP_BINARY, 'bin/apply-scheduled-changes.php'],
				dirname(__DIR__, 2),
				$environment,
			);
			self::assertSame(
				"Cambios programados aplicados: 1. Revisiones firmadas: 1. Ya aplicados por otra ejecución o cancelados: 0.\n",
				$run(),
			);
			self::assertSame([1, 0, 0], $this->limits($license));
			self::assertSame(2, $installation->refresh()['license_revision']);
			// Ejecutarlo otra vez no aplica nada.
			self::assertStringStartsWith('Cambios programados aplicados: 0.', $run());
		} finally {
			\AdelPos\License\Tests\NativeStackFixture::remove($directory);
		}
		// La tarea diaria de mantenimiento lo ejecuta (con PHP 8.3, la del VPS, desde la etapa 5).
		self::assertStringContainsString(
			'php8.3 bin/apply-scheduled-changes.php',
			(string) file_get_contents(dirname(__DIR__, 2) . '/deploy/adelpos-license-maintenance.sh'),
		);
	}
}
