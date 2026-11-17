<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\Uuid;
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Reglas de la base para las transferencias y las alertas (migración 008; etapa 4, Fase 1): valen
 * aunque alguien escriba sin pasar por la aplicación. Cada sentencia de prueba se revierte.
 */
final class TransferModelTest extends TestCase
{
	private MySqlFixture $fixture;
	private Actor $actor;
	private string $license;
	private string $otherLicense;
	/** @var array<string, string> Activaciones de la licencia por nombre, en binario. */
	private array $activations = [];

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->fixture = new MySqlFixture(new FrozenClock(new \DateTimeImmutable('2026-11-16T06:30:00Z')));
		$app = $this->fixture->app;
		$this->actor = new Actor(
			$app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
		$customer = $app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Restaurante sintético',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
		])['id'];
		$issue = fn(): string => Uuid::bytes(
			$app->licenses->issue($this->actor, Uuid::create(), [
				'customer_id' => $customer,
				'product_id' => 'adel_pos',
				...MySqlFixture::terms('perpetual'),
			])['id'],
		);
		$this->license = $issue();
		$this->otherLicense = $issue();
		// Tres computadoras que tuvieron la licencia, una tras otra, y una de la otra licencia.
		foreach (['a' => 'transferred', 'b' => 'transferred', 'c' => 'active'] as $name => $state) {
			$this->activations[$name] = $this->activation($this->license, $state);
		}
		$this->activations['other_a'] = $this->activation($this->otherLicense, 'transferred');
		$this->activations['other_b'] = $this->activation($this->otherLicense, 'active');
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function activation(string $license, string $state): string
	{
		$id = Uuid::bytes(Uuid::create());
		$this->fixture->owner->execute(
			"INSERT INTO activations(activation_id,license_id,product_id,server_id,installation_public_key,state,
					activated_at,ended_at)
				VALUES (?,?,'adel_pos',?,?,?,'2026-11-01 10:00:00.000000',?)",
			[
				$id,
				$license,
				Uuid::bytes(Uuid::create()),
				random_bytes(32),
				$state,
				$state === 'active' ? null : '2026-11-02 10:00:00.000000',
			],
		);
		return $id;
	}

	/** Inserta una transferencia con el dueño de la base; devuelve el número de error de MySQL, o 0. */
	private function transfer(array $fields, bool $keep = false): int
	{
		$fields += [
			'id' => Uuid::bytes(Uuid::create()),
			'license_id' => $this->license,
			'outgoing_activation_id' => $this->activations['a'],
			'incoming_activation_id' => $this->activations['b'],
			'via' => 'self_service',
			'admin_id' => null,
			'reason' => '',
			'offline_limit_accepted' => null,
			'state' => 'pending',
			'confirmed_at' => null,
			'confirmation' => null,
			'released_by' => null,
			'release_reason' => null,
			'created_at' => '2026-11-02 10:00:00.000000',
		];
		return $this->write(
			'INSERT INTO license_transfers(' .
				implode(',', array_keys($fields)) .
				') VALUES (' .
				implode(',', array_fill(0, count($fields), '?')) .
				')',
			array_values($fields),
			$keep,
		);
	}

	/** Inserta una alerta con el dueño de la base; devuelve el número de error de MySQL, o 0. */
	private function alert(array $fields, bool $keep = false): int
	{
		$fields += [
			'alert_id' => Uuid::bytes(Uuid::create()),
			'license_id' => $this->license,
			'alert_type' => 'transfer_blocked',
			'attempts' => 1,
			'detail_json' => '{}',
			'created_at' => '2026-11-03 10:00:00.000000',
			'last_seen_at' => '2026-11-03 10:00:00.000000',
			'reviewed_at' => null,
			'reviewed_by' => null,
		];
		return $this->write(
			'INSERT INTO license_alerts(' .
				implode(',', array_keys($fields)) .
				') VALUES (' .
				implode(',', array_fill(0, count($fields), '?')) .
				')',
			array_values($fields),
			$keep,
		);
	}

	/** Ejecuta una sentencia con el dueño de la base y la revierte salvo que se pida conservarla. */
	private function write(string $sql, array $parameters, bool $keep): int
	{
		$owner = $this->fixture->owner;
		$owner->pdo->beginTransaction();
		try {
			$owner->execute($sql, $parameters);
			if ($keep) {
				$owner->pdo->commit();
			}
			return 0;
		} catch (\PDOException $error) {
			return (int) $error->errorInfo[1];
		} finally {
			if ($owner->pdo->inTransaction()) {
				$owner->pdo->rollBack();
			}
		}
	}

	public function testOnlyOneSelfServiceTransferCanBePendingPerLicense(): void
	{
		self::assertSame(0, $this->transfer([], true));
		// La segunda pendiente de la misma licencia la rechaza el índice único, aunque sea de otra computadora.
		self::assertSame(
			1062,
			$this->transfer([
				'outgoing_activation_id' => $this->activations['b'],
				'incoming_activation_id' => $this->activations['c'],
			]),
		);
		// Otra licencia tiene su propio contador.
		self::assertSame(
			0,
			$this->transfer([
				'license_id' => $this->otherLicense,
				'outgoing_activation_id' => $this->activations['other_a'],
				'incoming_activation_id' => $this->activations['other_b'],
			]),
		);
		// Una del propietario nunca está pendiente, así que no choca con la pendiente.
		self::assertSame(
			0,
			$this->transfer([
				'outgoing_activation_id' => $this->activations['b'],
				'incoming_activation_id' => $this->activations['c'],
				'via' => 'owner',
				'admin_id' => Uuid::bytes($this->actor->id),
				'reason' => 'Equipo averiado',
				'offline_limit_accepted' => 1,
				'state' => 'confirmed',
				'confirmed_at' => '2026-11-02 10:00:00.000000',
				'confirmation' => 'owner',
			]),
		);
		// Confirmada la primera, puede quedar pendiente otra.
		$this->fixture->owner->execute(
			"UPDATE license_transfers SET state='confirmed',confirmed_at=created_at,confirmation='refresh'",
		);
		self::assertSame(
			0,
			$this->transfer([
				'outgoing_activation_id' => $this->activations['b'],
				'incoming_activation_id' => $this->activations['c'],
			]),
		);
		// Una activación sale de la licencia una sola vez.
		self::assertSame(
			1062,
			$this->transfer([
				'state' => 'confirmed',
				'confirmed_at' => '2026-11-03 00:00:00',
				'confirmation' => 'refresh',
			]),
		);
	}

	public function testTheViaAndTheConfirmationMustAgree(): void
	{
		$admin = Uuid::bytes($this->actor->id);
		$owner = [
			'via' => 'owner',
			'admin_id' => $admin,
			'reason' => 'Equipo averiado',
			'offline_limit_accepted' => 1,
			'state' => 'confirmed',
			'confirmed_at' => '2026-11-02 10:00:00.000000',
			'confirmation' => 'owner',
		];
		$confirmed = ['state' => 'confirmed', 'confirmed_at' => '2026-11-03 10:00:00.000000'];
		$accepted = [
			'pendiente de autoservicio' => [],
			'del propietario, confirmada al nacer' => $owner,
			'recuperar plaza, sin destino' => ['incoming_activation_id' => null] + $owner,
			'confirmada con refresh' => ['confirmation' => 'refresh'] + $confirmed,
			'confirmada con deactivate' => ['confirmation' => 'deactivate'] + $confirmed,
			'confirmada con renew sin internet' => ['confirmation' => 'offline_renew'] + $confirmed,
			'confirmada con deactivate sin internet' => ['confirmation' => 'offline_deactivate'] + $confirmed,
			'liberada a mano' =>
				[
					'confirmation' => 'manual',
					'released_by' => $admin,
					'release_reason' => 'La computadora anterior se dañó',
				] + $confirmed,
		];
		foreach ($accepted as $case => $fields) {
			self::assertSame(0, $this->transfer($fields), $case);
		}
		$rejected = [
			'vía desconocida' => ['via' => 'api'],
			'estado desconocido' => ['state' => 'cancelled', 'confirmation' => 'refresh'] + $confirmed,
			'estado desconocido sin confirmar' => ['state' => 'cancelled'],
			'forma de confirmación desconocida' => ['confirmation' => 'email'] + $confirmed,
			'del propietario sin administrador' => ['admin_id' => null] + $owner,
			'del propietario sin motivo' => ['reason' => ''] + $owner,
			'del propietario sin aceptar el límite sin internet' => ['offline_limit_accepted' => null] + $owner,
			'del propietario con el límite rechazado' => ['offline_limit_accepted' => 0] + $owner,
			'del propietario pendiente' =>
				['state' => 'pending', 'confirmed_at' => null, 'confirmation' => null] + $owner,
			'del propietario confirmada de otra forma' => ['confirmation' => 'refresh'] + $owner,
			'de autoservicio con administrador' => ['admin_id' => $admin],
			'de autoservicio con motivo' => ['reason' => 'Motivo'],
			'de autoservicio con el límite sin internet' => ['offline_limit_accepted' => 1],
			'de autoservicio sin destino' => ['incoming_activation_id' => null],
			'de autoservicio confirmada como del propietario' => ['confirmation' => 'owner'] + $confirmed,
			'pendiente con instante de confirmación' => ['confirmed_at' => '2026-11-03 10:00:00.000000'],
			'pendiente con forma de confirmación' => ['confirmation' => 'refresh'],
			'pendiente con motivo de liberación' => ['release_reason' => 'Motivo'],
			'confirmada sin instante' => ['state' => 'confirmed', 'confirmation' => 'refresh'],
			'confirmada sin forma' => $confirmed,
			'confirmada antes de existir' =>
				['confirmation' => 'refresh', 'confirmed_at' => '2026-11-01 00:00:00'] + $confirmed,
			'liberada sin administrador' =>
				[
					'confirmation' => 'manual',
					'release_reason' => 'La computadora se dañó',
				] + $confirmed,
			'liberada sin motivo' => ['confirmation' => 'manual', 'released_by' => $admin] + $confirmed,
			'liberada con motivo vacío' =>
				[
					'confirmation' => 'manual',
					'released_by' => $admin,
					'release_reason' => '',
				] + $confirmed,
			'confirmada por la computadora con datos de liberación' =>
				[
					'confirmation' => 'refresh',
					'released_by' => $admin,
					'release_reason' => 'Motivo',
				] + $confirmed,
			'origen y destino iguales' => ['incoming_activation_id' => $this->activations['a']],
		];
		foreach ($rejected as $case => $fields) {
			self::assertSame(3819, $this->transfer($fields), $case);
		}
		// El origen y el destino son de la misma licencia.
		self::assertSame(1452, $this->transfer(['incoming_activation_id' => $this->activations['other_b']]));
	}

	public function testEachAlertTypeHasAtMostOneOpenAlertPerLicense(): void
	{
		$admin = Uuid::bytes($this->actor->id);
		self::assertSame(0, $this->alert([], true));
		// Mientras no se revisa, otra alerta del mismo tipo para la misma licencia no se abre.
		self::assertSame(1062, $this->alert([]));
		// Otro tipo u otra licencia sí.
		self::assertSame(0, $this->alert(['alert_type' => 'frequent_transfers']));
		self::assertSame(0, $this->alert(['license_id' => $this->otherLicense]));
		// Una revisada no cuenta como abierta: después de revisar, la misma situación abre una nueva.
		self::assertSame(
			0,
			$this->write('UPDATE license_alerts SET reviewed_at=last_seen_at,reviewed_by=?', [$admin], true),
		);
		self::assertSame(0, $this->alert([], true));
		self::assertSame(0, $this->alert(['reviewed_at' => '2026-11-04 10:00:00.000000', 'reviewed_by' => $admin]));
		$rejected = [
			'tipo desconocido' => ['alert_type' => 'transfer_pending'],
			'sin intentos' => ['attempts' => 0],
			'detalle que no es JSON' => ['detail_json' => 'servidor'],
			'visto antes de crearse' => ['last_seen_at' => '2026-11-02 10:00:00.000000'],
			'revisada sin quien la revisó' => ['reviewed_at' => '2026-11-04 10:00:00.000000'],
			'revisada sin instante' => ['reviewed_by' => $admin],
			'revisada antes de crearse' => ['reviewed_at' => '2026-11-02 10:00:00.000000', 'reviewed_by' => $admin],
		];
		foreach ($rejected as $case => $fields) {
			self::assertSame(3819, $this->alert($fields + ['alert_type' => 'frequent_transfers']), $case);
		}
	}

	public function testTheApplicationAccountOnlyChangesTheConfirmationAndTheReview(): void
	{
		$denied = function (string $sql): int {
			try {
				$this->fixture->app->db->execute($sql);
				return 0;
			} catch (\PDOException $error) {
				return (int) $error->errorInfo[1];
			}
		};
		$cases = [
			'INSERT INTO license_transfers(id) SELECT id FROM license_transfers WHERE 1=0' => 0,
			"UPDATE license_transfers SET state='confirmed',confirmed_at=NOW(6),confirmation='manual',
				released_by=NULL,release_reason='' WHERE 1=0" => 0,
			'UPDATE license_transfers SET via=via WHERE 1=0' => 1143,
			'UPDATE license_transfers SET outgoing_activation_id=outgoing_activation_id WHERE 1=0' => 1143,
			'UPDATE license_transfers SET created_at=created_at WHERE 1=0' => 1143,
			'DELETE FROM license_transfers WHERE 1=0' => 1142,
			'UPDATE license_alerts SET attempts=attempts+1,detail_json=detail_json,last_seen_at=NOW(6),
				reviewed_at=NOW(6),reviewed_by=NULL WHERE 1=0' => 0,
			'UPDATE license_alerts SET alert_type=alert_type WHERE 1=0' => 1143,
			'UPDATE license_alerts SET license_id=license_id WHERE 1=0' => 1143,
			'UPDATE license_alerts SET created_at=created_at WHERE 1=0' => 1143,
			'DELETE FROM license_alerts WHERE 1=0' => 1142,
		];
		foreach ($cases as $sql => $code) {
			self::assertSame($code, $denied($sql), $sql);
		}
	}

	public function testTheChallengeAcceptsTransferWithoutActivation(): void
	{
		$challenge = function (string $action, ?string $activation): int {
			return $this->write(
				"INSERT INTO activation_challenges(challenge_id,action,product_id,server_id,activation_id,nonce,
						created_at,expires_at)
					VALUES (?,?,'adel_pos',?,?,?,NOW(6),NOW(6) + INTERVAL 5 MINUTE)",
				[Uuid::bytes(Uuid::create()), $action, Uuid::bytes(Uuid::create()), $activation, str_repeat('n', 43)],
				false,
			);
		};
		$activation = $this->activations['c'];
		self::assertSame(0, $challenge('transfer', null));
		self::assertSame(0, $challenge('activate', null));
		self::assertSame(0, $challenge('refresh', $activation));
		self::assertSame(3819, $challenge('transfer', $activation));
		self::assertSame(3819, $challenge('refresh', null));
		self::assertSame(3819, $challenge('renew', $activation));
	}
}
