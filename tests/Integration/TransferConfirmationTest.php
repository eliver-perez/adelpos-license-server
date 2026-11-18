<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{ApiProblem, Problem, Protocol, Uuid};
use AdelPos\License\Tests\{ApiRace, TransferScenario};
use PHPUnit\Framework\TestCase;

/**
 * Confirmación de la transferencia por autoservicio y liberación manual del contador (contrato §8.4,
 * regla 4; aclaraciones n.º 34 y 35 del contrato; etapa 4, Fase 3).
 */
final class TransferConfirmationTest extends TestCase
{
	use TransferScenario;

	private const PASSWORD = 'Fixture-Password-Only-2026!';
	private const MFA = 'JBSWY3DPEHPK3PXP';

	/** Activa la licencia en una computadora y la transfiere por autoservicio a otra. */
	private function transferred(string $type = 'perpetual'): array
	{
		$license = $this->issue($type);
		$old = $this->computer();
		$first = self::content($this->call('activate', $old, $license['secret']));
		$new = $this->computer();
		$moved = self::content($this->call('transfer', $new, $license['secret']));
		return [
			'license' => $license,
			'old' => $old,
			'old_activation' => $first['activation_id'],
			'new' => $new,
			'new_activation' => $moved['activation_id'],
		];
	}

	private function transfer(string $licenseId): array
	{
		return $this->fixture->owner->one(
			'SELECT * FROM license_transfers WHERE license_id=? ORDER BY created_at DESC LIMIT 1',
			[Uuid::bytes($licenseId)],
		);
	}

	/** Solicitud .licreq firmada por una computadora para su activación. */
	private function envelope(string $action, array $computer, string $licenseId, string $activationId): string
	{
		$payload = Protocol::encode(
			Protocol::json([
				'action' => $action,
				'request_id' => Uuid::create(),
				'created_at' => '2026-09-25T12:00:00Z',
				'product_id' => 'adel_pos',
				'server_id' => $computer['id'],
				'installation_public_key' => $computer['public'],
				'app_version' => '1.4.0',
				'license_id' => $licenseId,
				'activation_id' => $activationId,
			]),
		);
		return Protocol::json([
			'schema_version' => '1.0',
			'payload_b64u' => $payload,
			'signature_b64u' => Protocol::encode(
				sodium_crypto_sign_detached("ADELPOS-LICREQ-V1\n" . $payload, $computer['secret']),
			),
		]);
	}

	/** Importa y aprueba sin internet la solicitud de la computadora anterior. */
	private function approveOffline(string $action, array $scenario): array
	{
		$licenseId = $scenario['license']['id'];
		$id = $this->app->offline->import(
			$this->actor,
			Uuid::create(),
			$this->envelope($action, $scenario['old'], $licenseId, $scenario['old_activation']),
		)['id'];
		return $this->app->offline->decide($this->actor, Uuid::create(), $id, 'approved', [
			'license_id' => $licenseId,
			'version' => $this->app->read->license($licenseId)['row_version'],
			'renewal_mode' => 'current',
			'reason' => 'Solicitud de la computadora anterior',
			'accept_offline_limit' => $action === 'deactivate' ? '1' : '',
		]);
	}

	/** Código de autenticación del superadministrador en el instante del reloj. */
	private function code(): string
	{
		return \OTPHP\TOTP::createFromSecret(self::MFA, $this->clock)->at($this->clock->now()->getTimestamp());
	}

	private function enableMfa(string $adminId): void
	{
		$this->fixture->owner->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [
			$this->app->crypto->encrypt(self::MFA, 'mfa:' . $adminId),
			Uuid::bytes($adminId),
		]);
	}

	private function release(Actor $actor, string $licenseId, array $input, ?string $operation = null): array
	{
		// Cada confirmación de identidad usa un código nuevo: el reloj avanza un paso de 30 segundos.
		$this->clock->instant = $this->clock->instant->modify('+30 seconds');
		return $this->app->transfers->release(
			$actor,
			$operation ?? Uuid::create(),
			$licenseId,
			$input,
			$input['password'] ?? self::PASSWORD,
			$input['code'] ?? $this->code(),
		);
	}

	public function testTheOldComputerConfirmsOnlineOrOfflineAndTheCounterGoesBackToZero(): void
	{
		$forms = [
			'refresh' => fn(array $s) => self::content($this->call('refresh', $s['old'], null, $s['old_activation'])),
			'deactivate' => fn(array $s) => self::content(
				$this->call('deactivate', $s['old'], null, $s['old_activation']),
			),
			'offline_renew' => fn(array $s) => $this->approveOffline('renew', $s),
			'offline_deactivate' => fn(array $s) => $this->approveOffline('deactivate', $s),
		];
		foreach ($forms as $form => $confirm) {
			$scenario = $this->transferred();
			$licenseId = $scenario['license']['id'];
			self::assertSame('pending', $this->transfer($licenseId)['state'], $form);
			$this->clock->instant = $this->clock->instant->modify('+2 hours');
			$result = $confirm($scenario);
			$transfer = $this->transfer($licenseId);
			self::assertSame(
				['confirmed', $form, $this->clock->sql()],
				[$transfer['state'], $transfer['confirmation'], $transfer['confirmed_at']],
				$form,
			);
			// La computadora anterior recibió su cierre: revocada por transferencia.
			if (str_starts_with($form, 'offline_')) {
				$jws = $this->app->read->revision($licenseId, $result['revision_id'])['license_jws'];
				$closing = json_decode(
					sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
					true,
				);
			} else {
				$closing = $result;
			}
			self::assertSame(
				[$scenario['old_activation'], 'revoked', 'transferred'],
				[$closing['activation_id'], $closing['license_status'], $closing['status_reason']],
				$form,
			);
			self::assertSame(
				1,
				$this->rows('audit_events', "operation='license.transfer_confirmed' AND target_id=?", [$licenseId]),
				$form,
			);
			// Con el contador en 0, otra transferencia por autoservicio es posible.
			self::content($this->call('transfer', $this->computer(), $scenario['license']['secret']));
			self::assertSame('pending', $this->transfer($licenseId)['state'], $form);
		}
	}

	public function testTheNewComputerAForeignKeyOrARepeatedConfirmationChangeNothing(): void
	{
		$scenario = $this->transferred();
		$licenseId = $scenario['license']['id'];
		// La consulta de la computadora nueva no confirma nada.
		self::content($this->call('refresh', $scenario['new'], null, $scenario['new_activation']));
		self::assertSame('pending', $this->transfer($licenseId)['state']);
		// Una llave ajena no se autentica como la computadora anterior.
		$impostor = $this->computer();
		$impostor['id'] = $scenario['old']['id'];
		try {
			$this->call('refresh', $impostor, null, $scenario['old_activation']);
			self::fail('A foreign key confirmed the transfer');
		} catch (ApiProblem $error) {
			self::assertSame('INVALID_PROOF', $error->errorCode);
		}
		self::assertSame('pending', $this->transfer($licenseId)['state']);
		// La confirmación es idempotente: una segunda consulta de la anterior no cambia nada.
		self::content($this->call('refresh', $scenario['old'], null, $scenario['old_activation']));
		$confirmed = $this->transfer($licenseId);
		$this->clock->instant = $this->clock->instant->modify('+1 day');
		self::content($this->call('refresh', $scenario['old'], null, $scenario['old_activation']));
		self::content($this->call('deactivate', $scenario['old'], null, $scenario['old_activation']));
		self::assertSame($confirmed, $this->transfer($licenseId));
		self::assertSame(1, $this->rows('audit_events', "operation='license.transfer_confirmed'"));
	}

	public function testTheSuperadministratorReleasesTheCounterWithAReasonAndHisIdentity(): void
	{
		$scenario = $this->transferred();
		$licenseId = $scenario['license']['id'];
		$transferId = Uuid::text($this->transfer($licenseId)['id']);
		$this->enableMfa($this->actor->id);
		// Solo el superadministrador: el operador y la cuenta de consulta no pueden.
		foreach (['operator', 'viewer'] as $role) {
			$admin = $this->app->admins->create($this->actor, Uuid::create(), [
				'login' => $role . '@example.test',
				'name' => 'Cuenta ' . $role,
				'role' => $role,
				'new_password' => self::PASSWORD,
			]);
			$this->enableMfa($admin['id']);
			try {
				$this->release(new Actor($admin['id'], $role), $licenseId, [
					'transfer_id' => $transferId,
					'reason' => 'Equipo dañado',
				]);
				self::fail('Released by ' . $role);
			} catch (Problem $error) {
				self::assertSame(403, $error->status, $role);
			}
			// Se rechaza antes de pedir su identidad: no gasta su código ni su cupo de intentos.
			self::assertSame(
				0,
				$this->rows('audit_events', "operation LIKE 'session.reauth%' AND actor_id=?", [
					Uuid::bytes($admin['id']),
				]),
				$role,
			);
		}
		// El motivo es obligatorio, y la identidad se confirma con la contraseña y un código nuevo.
		$rejected = [
			'sin motivo' => [['transfer_id' => $transferId, 'reason' => ''], 422],
			'contraseña incorrecta' => [
				['transfer_id' => $transferId, 'reason' => 'Equipo dañado', 'password' => 'Otra-Contraseña-2026!'],
				401,
			],
			'código incorrecto' => [
				['transfer_id' => $transferId, 'reason' => 'Equipo dañado', 'code' => '000000'],
				401,
			],
		];
		foreach ($rejected as $case => [$input, $status]) {
			try {
				$this->release($this->actor, $licenseId, $input);
				self::fail('Released ' . $case);
			} catch (Problem $error) {
				self::assertSame($status, $error->status, $case);
			}
			self::assertSame('pending', $this->transfer($licenseId)['state'], $case);
		}
		$operation = Uuid::create();
		$input = ['transfer_id' => $transferId, 'reason' => 'La computadora anterior se dañó'];
		$this->release($this->actor, $licenseId, $input, $operation);
		$transfer = $this->transfer($licenseId);
		self::assertSame(
			[
				'confirmed',
				'manual',
				Uuid::bytes($this->actor->id),
				'La computadora anterior se dañó',
				$this->clock->sql(),
			],
			[
				$transfer['state'],
				$transfer['confirmation'],
				$transfer['released_by'],
				$transfer['release_reason'],
				$transfer['confirmed_at'],
			],
		);
		$event = $this->fixture->owner->one(
			"SELECT actor_id,payload_json FROM audit_events WHERE operation='license.transfer_released'",
		);
		$payload = json_decode($event['payload_json'], true);
		self::assertSame(
			[Uuid::bytes($this->actor->id), 'La computadora anterior se dañó', $transferId],
			[$event['actor_id'], $payload['reason'], $payload['metadata']['transfer_id']],
		);
		// La misma operación repetida no vuelve a liberar; otra operación encuentra la transferencia confirmada.
		self::assertTrue($this->release($this->actor, $licenseId, $input, $operation)['replayed']);
		try {
			$this->release($this->actor, $licenseId, $input);
			self::fail('Released twice');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		// AD-EL POS vuelve a poder transferir, y la computadora anterior todavía recibe su cierre sin cambiar nada.
		self::content($this->call('transfer', $this->computer(), $scenario['license']['secret']));
		$closing = self::content($this->call('refresh', $scenario['old'], null, $scenario['old_activation']));
		self::assertSame('transferred', $closing['status_reason']);
		self::assertSame(
			'manual',
			$this->fixture->owner->one('SELECT confirmation FROM license_transfers WHERE outgoing_activation_id=?', [
				Uuid::bytes($scenario['old_activation']),
			])['confirmation'],
		);
	}

	public function testTheOwnerTransfersDoNotConsumeTheSelfServiceTransfer(): void
	{
		$license = $this->issue();
		$old = $this->computer();
		$first = self::content($this->call('activate', $old, $license['secret']));
		// El propietario recupera la plaza del equipo averiado desde el panel.
		$this->app->offline->release($this->actor, Uuid::create(), $license['id'], [
			'activation_id' => $first['activation_id'],
			'version' => 1,
			'reason' => 'Equipo averiado',
			'accept_offline_limit' => '1',
		]);
		self::assertSame(
			['owner', 'confirmed'],
			[$this->transfer($license['id'])['via'], $this->transfer($license['id'])['state']],
		);
		// La siguiente computadora activa y, después, otra transfiere por autoservicio sin TRANSFER_LIMIT.
		self::content($this->call('activate', $this->computer(), $license['secret']));
		self::assertSame(
			['transfer_available' => true],
			self::error($this->call('activate', $this->computer(), $license['secret']))['details'],
		);
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame(1, $this->rows('license_transfers', "state='pending'"));
	}

	public function testAConfirmationRacingATransferAttemptEndsConsistent(): void
	{
		$scenario = $this->transferred();
		$licenseId = $scenario['license']['id'];
		$results = ApiRace::run($this->fixture, $licenseId, self::START, [
			[
				'action' => 'refresh',
				'input' => $this->request('refresh', $scenario['old'], null, $scenario['old_activation']),
			],
			[
				'action' => 'transfer',
				'input' => $this->request('transfer', $this->computer(), $scenario['license']['secret']),
			],
		]);
		self::assertSame(200, $results[0]['status']);
		$first = $this->fixture->owner->one('SELECT * FROM license_transfers WHERE outgoing_activation_id=?', [
			Uuid::bytes($scenario['old_activation']),
		]);
		self::assertSame(['confirmed', 'refresh'], [$first['state'], $first['confirmation']]);
		if ($results[1]['status'] === 200) {
			// La confirmación llegó primero: la transferencia nueva queda pendiente y no hubo intento bloqueado (sí la
			// alerta de transferencias frecuentes, porque ya son dos).
			self::assertSame(
				[2, 1, 0],
				[
					$this->rows('license_transfers'),
					$this->rows('license_transfers', "state='pending'"),
					$this->rows('license_alerts', "alert_type='transfer_blocked'"),
				],
			);
		} else {
			// La transferencia llegó primero: recibió TRANSFER_LIMIT con su alerta, y después se confirmó.
			self::assertSame('TRANSFER_LIMIT', json_decode($results[1]['body'], true)['error']['code']);
			self::assertSame(
				[1, 0, 1],
				[
					$this->rows('license_transfers'),
					$this->rows('license_transfers', "state='pending'"),
					$this->rows('license_alerts', "alert_type='transfer_blocked'"),
				],
			);
		}
		self::assertSame(1, $this->rows('activations', "state='active'"));
	}
}
