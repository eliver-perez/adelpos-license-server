<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Tests\{FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Editor del catálogo de planes y propagación de aumentos (etapa 3, Fase 2; contrato §11 y
 * decisión 9 del propietario): solo aumentos, solo cuando se elige, y todo o nada.
 */
final class PlanCatalogTest extends TestCase
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
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-16T06:30:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('plan-test-key', 'license');
		$keys->activate('plan-test-key', 'Isolated test setup');
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

	/** Emite y activa una licencia; devuelve su identificador. */
	private function activated(string $type, array $input = []): string
	{
		$issued = $this->issue($type, $input);
		(new Installation($this->app))->activate($issued['secret']);
		return $issued['id'];
	}

	private function plan(string $planId): array
	{
		return $this->fixture->owner->one('SELECT * FROM plans WHERE plan_id=?', [$planId]);
	}

	/** Formulario completo del plan, con sus valores actuales y los cambios del caso. */
	private function form(string $planId, array $changes): array
	{
		$plan = $this->plan($planId);
		$form = [
			'display_name' => $plan['display_name'],
			'cash_registers' => (string) $plan['cash_registers'],
			'order_takers' => (string) $plan['order_takers'],
			'kitchen_displays' => (string) $plan['kitchen_displays'],
			'version' => (int) $plan['row_version'],
			'apply_to' => 'new',
		];
		foreach (['price_monthly', 'price_quarterly', 'price_semiannual', 'price_annual'] as $column) {
			$form[$column] = (string) $plan[$column];
		}
		foreach (['price_perpetual', 'price_maintenance'] as $column) {
			$form[$column] = (string) $plan[$column];
		}
		return array_replace($form, $changes);
	}

	private function update(string $planId, array $changes, ?string $operation = null, ?Actor $actor = null): array
	{
		return $this->app->plans->update(
			$actor ?? $this->actor,
			$operation ?? Uuid::create(),
			$planId,
			$this->form($planId, $changes),
		);
	}

	/** Cupos de una licencia: cajas, comanderos y KDS. */
	private function limits(string $license): array
	{
		$row = $this->fixture->owner->one(
			'SELECT cash_registers,order_takers,kitchen_displays FROM licenses WHERE license_id=?',
			[Uuid::bytes($license)],
		);
		return array_map('intval', array_values($row));
	}

	private function signedLimits(string $license): array
	{
		return array_values(Installation::signed($this->app, $license)['limits']);
	}

	public function testPlansAreCreatedEditedAndArchivedAndAnArchivedPlanIsNotOffered(): void
	{
		$created = $this->app->plans->create($this->actor, Uuid::create(), [
			'plan_id' => 'familiar',
			'display_name' => 'Plan familiar',
			'cash_registers' => '2',
			'order_takers' => '1',
			'kitchen_displays' => '1',
			'price_monthly' => '499',
			'price_perpetual' => '9000.50',
		]);
		self::assertSame(
			['familiar', 0, 0],
			[$created['id'], $created['licenses_updated'], $created['revisions_signed']],
		);
		$plan = $this->plan('familiar');
		self::assertSame(
			['Plan familiar', 2, 1, 1, '499.00', null, '9000.50', 'active', 1],
			[
				$plan['display_name'],
				(int) $plan['cash_registers'],
				(int) $plan['order_takers'],
				(int) $plan['kitchen_displays'],
				$plan['price_monthly'],
				$plan['price_annual'],
				$plan['price_perpetual'],
				$plan['state'],
				(int) $plan['row_version'],
			],
		);
		foreach (
			[
				['plan_id' => 'familiar'] + $this->form('familiar', []),
				['plan_id' => 'Familiar'] + $this->form('familiar', []),
				['plan_id' => 'familiar-2'] + $this->form('familiar', []),
			]
			as $input
		) {
			try {
				$this->app->plans->create($this->actor, Uuid::create(), $input);
				self::fail('Accepted plan id: ' . $input['plan_id']);
			} catch (Problem) {
			}
		}
		// Editar nombre y precios; la auditoría guarda los valores anteriores y nuevos.
		$this->update('familiar', [
			'display_name' => 'Plan familiar plus',
			'price_monthly' => '549.90',
			'price_perpetual' => '',
		]);
		$event = json_decode(
			$this->fixture->owner->one("SELECT payload_json FROM audit_events WHERE operation='plan.updated'")[
				'payload_json'
			],
			true,
		);
		self::assertSame(
			[
				'display_name' => ['before' => 'Plan familiar', 'after' => 'Plan familiar plus'],
				'price_monthly' => ['before' => '499.00', 'after' => '549.90'],
				'price_perpetual' => ['before' => '9000.50', 'after' => null],
			],
			$event['metadata']['changes'],
		);
		// Un formulario obsoleto no se aplica, y un precio negativo tampoco.
		foreach ([['version' => 1], ['price_annual' => '-1']] as $changes) {
			try {
				$this->update('familiar', ['display_name' => 'Otro'] + $changes);
				self::fail('Accepted a stale form or a negative price');
			} catch (Problem) {
			}
		}
		// Archivado: se conserva, pero ya no se ofrece al emitir.
		$this->issue('perpetual', ['plan_id' => 'familiar']);
		$this->app->plans->setState($this->actor, Uuid::create(), 'familiar', [
			'state' => 'archived',
			'version' => (int) $this->plan('familiar')['row_version'],
		]);
		self::assertSame('archived', $this->plan('familiar')['state']);
		self::assertNotContains('familiar', array_column($this->app->read->plans(), 'plan_id'));
		self::assertContains('familiar', array_column($this->app->read->plans(true), 'plan_id'));
		try {
			$this->issue('perpetual', ['plan_id' => 'familiar']);
			self::fail('An archived plan was offered');
		} catch (Problem $error) {
			self::assertStringContainsString('archivado', $error->getMessage());
		}
		// Un plan en uso no se puede borrar ni siquiera con la cuenta dueña de la base.
		self::assertSame(1451, $this->deleteAttempt('familiar', $this->fixture->owner));
		$this->app->plans->setState($this->actor, Uuid::create(), 'familiar', [
			'state' => 'active',
			'version' => (int) $this->plan('familiar')['row_version'],
		]);
		$this->issue('perpetual', ['plan_id' => 'familiar']);
		self::assertSame(
			['plan.archived', 'plan.created', 'plan.restored', 'plan.updated'],
			array_column(
				$this->fixture->owner->all(
					"SELECT DISTINCT operation FROM audit_events WHERE operation LIKE 'plan.%' ORDER BY operation",
				),
				'operation',
			),
		);
	}

	private function deleteAttempt(string $planId, ?\AdelPos\License\Infrastructure\Database $db = null): int
	{
		try {
			($db ?? $this->app->db)->execute('DELETE FROM plans WHERE plan_id=?', [$planId]);
			return 0;
		} catch (\PDOException $error) {
			return (int) $error->errorInfo[1];
		}
	}

	public function testTheDatabaseKeepsThePlanStateAndAnIdentifierThatCanBeSigned(): void
	{
		$owner = $this->fixture->owner;
		$attempt = function (string $sql) use ($owner): int {
			$owner->pdo->beginTransaction();
			try {
				$owner->execute($sql);
				return 0;
			} catch (\PDOException $error) {
				return (int) $error->errorInfo[1];
			} finally {
				$owner->pdo->rollBack();
			}
		};
		$insert = fn(string $id): string => "INSERT INTO plans(plan_id,display_name,cash_registers,order_takers,
				kitchen_displays,created_at,updated_at)
			VALUES ('$id','Plan',1,1,1,NOW(6),NOW(6))";
		self::assertSame(0, $attempt($insert('plan_2027')));
		foreach (['Plan', 'p', '2027', 'plan-2027', 'plan 2027', '_plan'] as $id) {
			self::assertSame(3819, $attempt($insert($id)), $id);
		}
		// Una letra fuera de ASCII ni siquiera cabe en la columna.
		self::assertSame(1366, $attempt($insert('plan_ñ')));
		self::assertSame(0, $attempt("UPDATE plans SET state='archived' WHERE plan_id='basico'"));
		self::assertSame(3819, $attempt("UPDATE plans SET state='deleted' WHERE plan_id='basico'"));
	}

	public function testOnlyTheSuperadminEditsTheCatalog(): void
	{
		foreach (['operator', 'viewer'] as $role) {
			$created = $this->app->admins->create($this->actor, Uuid::create(), [
				'login' => $role . '@example.test',
				'name' => $role,
				'role' => $role,
				'new_password' => 'Fixture-Password-Only-2026!',
			]);
			$other = new Actor($created['id'], $role);
			$calls = [
				fn() => $this->app->plans->create(
					$other,
					Uuid::create(),
					[
						'plan_id' => 'nuevo_' . $role,
					] + $this->form('basico', []),
				),
				fn() => $this->update('basico', ['cash_registers' => '9'], null, $other),
				fn() => $this->app->plans->setState($other, Uuid::create(), 'basico', [
					'state' => 'archived',
					'version' => 1,
				]),
			];
			foreach ($calls as $call) {
				try {
					$call();
					self::fail('A ' . $role . ' edited the catalog');
				} catch (Problem $error) {
					self::assertSame(403, $error->status);
				}
			}
		}
		self::assertSame(1, (int) $this->plan('basico')['cash_registers']);
		self::assertSame(3, (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM plans')->fetchColumn());
		// La cuenta de la aplicación inserta y actualiza planes, pero nunca los borra.
		self::assertSame(1142, $this->deleteAttempt('premium'));
	}

	public function testIncreasesReachExistingLicensesOnlyWhenTheOwnerChoosesIt(): void
	{
		$subscription = $this->activated('subscription');
		$perpetual = $this->activated('perpetual');
		$trial = $this->activated('trial');
		$unactivated = $this->issue('subscription');
		// Solo licencias nuevas: ninguna existente cambia ni se firma.
		$result = $this->update('elemental', ['cash_registers' => '4']);
		self::assertSame([0, 0], [$result['licenses_updated'], $result['revisions_signed']]);
		foreach ([$subscription, $perpetual, $trial, $unactivated['id']] as $license) {
			self::assertSame([3, 3, 3], $this->limits($license));
		}
		self::assertSame(3, $this->revisionCount());
		self::assertSame([4, 3, 3], $this->limits($this->issue('perpetual')['id']));
		// También las existentes: suben y se firma una revisión para cada activación vigente.
		$result = $this->update('elemental', ['cash_registers' => '5', 'apply_to' => 'existing']);
		self::assertSame([5, 3], [$result['licenses_updated'], $result['revisions_signed']]);
		foreach ([$subscription, $perpetual, $trial, $unactivated['id']] as $license) {
			self::assertSame([5, 3, 3], $this->limits($license));
		}
		foreach ([$subscription, $perpetual, $trial] as $license) {
			self::assertSame([5, 3, 3], $this->signedLimits($license));
			self::assertSame(2, Installation::signed($this->app, $license)['license_revision']);
		}
		self::assertSame(6, $this->revisionCount());
		// La licencia sin activar firma sus números nuevos al activarse.
		$content = (new Installation($this->app))->activate($unactivated['secret']);
		self::assertSame(['cash_registers' => 5, 'order_takers' => 3, 'kitchen_displays' => 3], $content['limits']);
		// Cada licencia actualizada deja su cambio en su historial comercial.
		self::assertSame(
			5,
			(int) $this->fixture->owner
				->execute("SELECT COUNT(*) FROM license_changes WHERE action='license.plan_propagated'")
				->fetchColumn(),
		);
	}

	private function revisionCount(): int
	{
		return (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn();
	}

	public function testAMixedChangeOnlyRaisesTheIncreaseInExistingLicenses(): void
	{
		$license = $this->activated('subscription');
		// Sube cajas y baja comanderos: en las existentes solo sube el aumento.
		$result = $this->update('elemental', [
			'cash_registers' => '6',
			'order_takers' => '1',
			'apply_to' => 'existing',
		]);
		self::assertSame([1, 1], [$result['licenses_updated'], $result['revisions_signed']]);
		self::assertSame([6, 3, 3], $this->limits($license));
		self::assertSame([6, 3, 3], $this->signedLimits($license));
		// La auditoría registra cuáles cupos subieron en el catálogo: la baja de comanderos no es un aumento.
		$event = json_decode(
			$this->fixture->owner->one(
				"SELECT payload_json FROM audit_events WHERE operation='plan.updated' ORDER BY sequence DESC LIMIT 1",
			)['payload_json'],
			true,
		);
		self::assertSame(['cash_registers' => 6], $event['metadata']['increases']);
		// Una licencia que sigue el plan con un número mayor que el del catálogo (por ejemplo, mientras espera
		// una reducción programada) conserva el suyo: un aumento menor nunca la baja.
		$this->fixture->owner->execute('UPDATE licenses SET kitchen_displays=6 WHERE license_id=?', [
			Uuid::bytes($license),
		]);
		$result = $this->update('elemental', ['kitchen_displays' => '5', 'apply_to' => 'existing']);
		self::assertSame([0, 0], [$result['licenses_updated'], $result['revisions_signed']]);
		self::assertSame([6, 3, 6], $this->limits($license));
		$this->update('elemental', ['kitchen_displays' => '3']);
		$this->fixture->owner->execute('UPDATE licenses SET kitchen_displays=3 WHERE license_id=?', [
			Uuid::bytes($license),
		]);
		// Una reducción pura no alcanza a ninguna licencia existente, aunque se elija «también las existentes».
		$result = $this->update('elemental', ['kitchen_displays' => '0', 'apply_to' => 'existing']);
		self::assertSame([0, 0], [$result['licenses_updated'], $result['revisions_signed']]);
		self::assertSame([6, 3, 3], $this->limits($license));
		// Las licencias nuevas sí toman los números del catálogo.
		self::assertSame([6, 1, 0], $this->limits($this->issue('perpetual')['id']));
	}

	public function testCustomRevokedAndExpiredLicensesDoNotChange(): void
	{
		$custom = $this->activated('subscription', ['order_takers' => '4']);
		$revoked = $this->activated('perpetual');
		$this->app->licenses->change($this->actor, Uuid::create(), $revoked, 'revoke', [
			'version' => 1,
			'reason' => 'Prueba de revocación',
		]);
		$expired = $this->activated('subscription');
		$trial = $this->activated('trial');
		$inGrace = $this->activated('subscription');
		$otherPlan = $this->activated('subscription', ['plan_id' => 'premium']);
		// Un mes y tres días después: la suscripción mensual está en tolerancia y la prueba de 15 días, vencida.
		$this->fixture->owner->execute(
			"UPDATE licenses
				SET period_starts_at='2026-09-16 06:30:00',expires_at='2026-10-16 06:30:00',
					updates_until='2026-10-16 06:30:00'
				WHERE license_id=?",
			[Uuid::bytes($expired)],
		);
		$this->clock->instant = new \DateTimeImmutable('2026-12-19T06:30:00Z');
		$before = $this->revisionCount();
		$result = $this->update('elemental', ['kitchen_displays' => '7', 'apply_to' => 'existing']);
		self::assertSame([1, 1], [$result['licenses_updated'], $result['revisions_signed']]);
		self::assertSame([3, 3, 7], $this->limits($inGrace));
		foreach (
			[$custom => [3, 4, 3], $revoked => [3, 3, 3], $expired => [3, 3, 3], $trial => [3, 3, 3]]
			as $id => $limits
		) {
			self::assertSame($limits, $this->limits($id));
		}
		self::assertSame([5, 10, 5], $this->limits($otherPlan));
		self::assertSame($before + 1, $this->revisionCount());
		// Justo en el límite de la tolerancia ya está vencida: no cambia.
		$this->clock->instant = new \DateTimeImmutable('2026-12-21T06:30:00Z');
		$result = $this->update('elemental', ['kitchen_displays' => '8', 'apply_to' => 'existing']);
		self::assertSame([0, 0], [$result['licenses_updated'], $result['revisions_signed']]);
		self::assertSame([3, 3, 7], $this->limits($inGrace));
	}

	public function testAnIdenticalRetryAppliesOnceAndAFailureHalfwayChangesNothing(): void
	{
		$first = $this->activated('perpetual');
		$second = $this->activated('perpetual');
		$operation = Uuid::create();
		$form = $this->form('elemental', ['cash_registers' => '9', 'apply_to' => 'existing']);
		$result = $this->app->plans->update($this->actor, $operation, 'elemental', $form);
		$again = $this->app->plans->update($this->actor, $operation, 'elemental', $form);
		self::assertSame(
			[2, 2, false],
			[$result['licenses_updated'], $result['revisions_signed'], $result['replayed']],
		);
		self::assertSame([2, 2, true], [$again['licenses_updated'], $again['revisions_signed'], $again['replayed']]);
		self::assertSame(4, $this->revisionCount());
		// La llave de firma desaparece: la propagación falla en la primera firma y no cambia nada.
		$directory = $this->fixture->values['SIGNING_KEY_DIR'];
		$files = glob($directory . '/*.json');
		$saved = [];
		foreach ($files as $file) {
			$saved[$file] = [file_get_contents($file), fileperms($file) & 0777];
			unlink($file);
		}
		$operation = Uuid::create();
		$form = $this->form('elemental', ['cash_registers' => '12', 'apply_to' => 'existing']);
		try {
			$this->app->plans->update($this->actor, $operation, 'elemental', $form);
			self::fail('Propagated without a signing key');
		} catch (\Throwable) {
		}
		self::assertSame(9, (int) $this->plan('elemental')['cash_registers']);
		self::assertSame([[9, 3, 3], [9, 3, 3]], [$this->limits($first), $this->limits($second)]);
		self::assertSame(4, $this->revisionCount());
		// Con la llave de vuelta, la misma operación se aplica completa.
		foreach ($saved as $file => [$bytes, $mode]) {
			file_put_contents($file, $bytes);
			chmod($file, $mode);
		}
		$result = $this->app->plans->update($this->actor, $operation, 'elemental', $form);
		self::assertSame(
			[2, 2, false],
			[$result['licenses_updated'], $result['revisions_signed'], $result['replayed']],
		);
		self::assertSame([[12, 3, 3], [12, 3, 3]], [$this->limits($first), $this->limits($second)]);
	}

	public function testARenamedPlanReachesTheSignedNameOnlyWhenTheLicenseIsSignedForAnotherReason(): void
	{
		$license = $this->activated('perpetual');
		$this->update('elemental', ['display_name' => 'Plan elemental 2027', 'apply_to' => 'existing']);
		self::assertSame('Plan elemental', Installation::signed($this->app, $license)['plan']['name']);
		self::assertSame(1, Installation::revisions($this->app, $license));
		// Otro motivo firma una revisión: el nombre firmado se actualiza.
		$this->app->licenses->change($this->actor, Uuid::create(), $license, 'features', [
			'version' => 1,
			'features' => ['prueba_a'],
			'reason' => 'Cambio de módulos',
		]);
		self::assertSame('Plan elemental 2027', Installation::signed($this->app, $license)['plan']['name']);
	}
}
