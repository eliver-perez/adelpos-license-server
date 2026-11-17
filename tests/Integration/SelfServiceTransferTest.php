<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Domain\{ApiProblem, ApiResponse, Problem, Uuid};
use AdelPos\License\Tests\{ApiRace, MySqlFixture, TransferScenario};
use PHPUnit\Framework\TestCase;

/**
 * Transferencia por autoservicio (contrato §8.4; etapa 4, Fase 2): la computadora nueva presenta el
 * código comercial y su llave, la anterior recibe su revisión de cierre y la licencia conserva sus
 * fechas. Solo puede haber una transferencia por autoservicio pendiente de confirmar.
 */
final class SelfServiceTransferTest extends TestCase
{
	use TransferScenario;

	public function testEveryKindOfLicenseTransfersKeepingItsDatesToTheMicrosecond(): void
	{
		$cases = [
			'prueba vigente' => ['trial', fn(array $l): string => '+1 day'],
			'prueba vencida' => ['trial', fn(array $l): string => $l['expires_at'] . ' +1 day'],
			'suscripción vigente' => ['subscription', fn(array $l): string => '+10 days'],
			'suscripción en tolerancia' => ['subscription', fn(array $l): string => $l['expires_at'] . ' +2 days'],
			'suscripción vencida' => ['subscription', fn(array $l): string => $l['expires_at'] . ' +6 days'],
			'perpetua' => ['perpetual', fn(array $l): string => '+400 days'],
		];
		$dates = ['period_starts_at', 'expires_at', 'updates_until'];
		$rights = ['license_type', 'billing_period', 'grace_days', 'plan', 'limits', 'features', 'licensee_name'];
		foreach ($cases as $case => [$type, $later]) {
			$this->clock->instant = new \DateTimeImmutable(self::START);
			$license = $this->issue($type);
			$old = $this->computer();
			$first = self::content($this->call('activate', $old, $license['secret']));
			$before = $this->rights($license['id']);
			self::assertSame('2026-09-25 12:00:00.123456', $before['period_starts_at'], $case);
			$when = $later($before);
			$this->clock->instant = str_starts_with($when, '+')
				? $this->clock->instant->modify($when)
				: new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
			$new = $this->computer();
			$moved = self::content($this->call('transfer', $new, $license['secret']));
			// La licencia no cambió en nada: ni fechas, ni derechos, ni su versión.
			self::assertSame($before, $this->rights($license['id']), $case);
			// La computadora nueva firma las mismas fechas y los mismos derechos, al microsegundo.
			self::assertSame(
				array_intersect_key($first, array_flip([...$dates, ...$rights])),
				array_intersect_key($moved, array_flip([...$dates, ...$rights])),
				$case,
			);
			self::assertSame(
				['active', null, $new['id']],
				[$moved['license_status'], $moved['status_reason'], $moved['server_id']],
			);
			self::assertNotSame($first['activation_id'], $moved['activation_id'], $case);
			// Queda registrada como de autoservicio, pendiente de confirmar.
			$transfer = fn(): array => $this->fixture->owner->one(
				'SELECT * FROM license_transfers WHERE license_id=?',
				[Uuid::bytes($license['id'])],
			);
			self::assertSame(
				[
					'self_service',
					'pending',
					Uuid::bytes($first['activation_id']),
					Uuid::bytes($moved['activation_id']),
					null,
				],
				[
					$transfer()['via'],
					$transfer()['state'],
					$transfer()['outgoing_activation_id'],
					$transfer()['incoming_activation_id'],
					$transfer()['admin_id'],
				],
				$case,
			);
			// La anterior recibe su cierre al consultar, con las mismas fechas, y eso confirma la transferencia.
			$closing = self::content($this->call('refresh', $old, null, $first['activation_id']));
			self::assertSame(
				['revoked', 'transferred'],
				[$closing['license_status'], $closing['status_reason']],
				$case,
			);
			self::assertSame(
				array_intersect_key($first, array_flip($dates)),
				array_intersect_key($closing, array_flip($dates)),
				$case,
			);
			self::assertSame(['confirmed', 'refresh'], [$transfer()['state'], $transfer()['confirmation']], $case);
		}
	}

	public function testTheOldComputerKeepsItsClosingRevisionAndTheNewOneStartsAbove(): void
	{
		$license = $this->issue('subscription');
		$old = $this->computer();
		$first = self::content($this->call('activate', $old, $license['secret']));
		$new = $this->computer();
		$moved = self::content($this->call('transfer', $new, $license['secret']));
		// En la misma transacción: primero el cierre de la anterior (revisión 2), después la nueva (revisión 3).
		self::assertSame([1, 3], [$first['license_revision'], $moved['license_revision']]);
		$closing = self::content($this->call('refresh', $old, null, $first['activation_id']));
		self::assertSame(
			[2, $first['activation_id'], 'revoked', 'transferred'],
			[
				$closing['license_revision'],
				$closing['activation_id'],
				$closing['license_status'],
				$closing['status_reason'],
			],
		);
		// Volver a consultar no cambia nada: es la misma revisión de cierre.
		self::assertSame($closing, self::content($this->call('refresh', $old, null, $first['activation_id'])));
		self::assertSame(
			3,
			self::content($this->call('refresh', $new, null, $moved['activation_id']))['license_revision'],
		);
		$state = fn(string $activation): string => $this->fixture->owner
			->execute('SELECT state FROM activations WHERE activation_id=?', [Uuid::bytes($activation)])
			->fetchColumn();
		self::assertSame(['transferred', 'active'], [$state($first['activation_id']), $state($moved['activation_id'])]);
	}

	public function testWhatIsNotATransfer(): void
	{
		// Sin ninguna activación vigente: es una activación normal, y en la primera empieza el primer periodo.
		$license = $this->issue('subscription');
		$first = $this->computer();
		$activated = self::content($this->call('transfer', $first, $license['secret']));
		self::assertSame(
			['2026-09-25T12:00:00.123456Z', 1],
			[$activated['period_starts_at'], $activated['license_revision']],
		);
		// La misma instalación ya activa: recibe su revisión vigente, sin otra activación.
		$again = $this->call('transfer', $first, $license['secret']);
		self::assertSame($activated, self::content($again));
		self::assertSame(1, $this->rows('activations', 'license_id=?', [Uuid::bytes($license['id'])]));
		// Liberada la licencia, otra computadora la toma con una activación normal y las fechas no cambian.
		self::content($this->call('deactivate', $first, null, $activated['activation_id']));
		$this->clock->instant = $this->clock->instant->modify('+3 days');
		$second = self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame($activated['expires_at'], $second['expires_at']);
		self::assertSame(0, $this->rows('license_transfers'));
		self::assertSame(0, $this->rows('license_alerts'));

		// Licencia revocada: REVOKED.
		$revoked = $this->issue();
		$this->call('activate', $this->computer(), $revoked['secret']);
		$this->app->licenses->change($this->actor, Uuid::create(), $revoked['id'], 'revoke', [
			'version' => 1,
			'reason' => 'Prueba de revocación',
		]);
		self::assertSame(
			[403, 'REVOKED'],
			[
				($response = $this->call('transfer', $this->computer(), $revoked['secret']))->status,
				self::error($response)['code'],
			],
		);

		// Código inexistente, retirado o de otro producto: la misma respuesta en los tres casos.
		$retired = $this->issue();
		$this->app->licenses->change($this->actor, Uuid::create(), $retired['id'], 'reissue', [
			'version' => 1,
			'reason' => 'Código nuevo',
		]);
		$computer = $this->computer();
		$cases = [
			'inexistente' => $this->request('transfer', $computer, 'K7M-9R3X-6TVP-8CHD'),
			'mal formado' => $this->request('transfer', $computer, 'no es un código'),
			'retirado' => $this->request('transfer', $computer, $retired['secret']),
			'de otro producto' => $this->request('transfer', $computer, $license['secret'], null, 'otro_producto'),
		];
		$bodies = [];
		foreach ($cases as $case => $input) {
			$response = $this->app->activations->execute('transfer', $input);
			self::assertSame(404, $response->status, $case);
			$bodies[] = str_replace($input['request_id'], '-', $response->body);
		}
		self::assertCount(1, array_unique($bodies));
		self::assertSame(0, $this->rows('license_transfers'));
	}

	public function testASecondTransferBeforeTheConfirmationIsRefusedWithASingleOpenAlert(): void
	{
		$license = $this->issue('subscription');
		$old = $this->computer();
		$first = self::content($this->call('activate', $old, $license['secret']));
		$new = $this->computer();
		$moved = self::content($this->call('transfer', $new, $license['secret']));
		$pending = $this->fixture->owner->one('SELECT * FROM license_transfers');
		$other = $this->computer();
		$this->clock->instant = $this->clock->instant->modify('+1 hour');
		$input = $this->request('transfer', $other, $license['secret']);
		$refused = $this->app->activations->execute('transfer', $input);
		self::assertSame(409, $refused->status);
		self::assertSame(
			'{"error":{"code":"TRANSFER_LIMIT","message":"Hay una transferencia pendiente de confirmar.",' .
				'"request_id":"' .
				$input['request_id'] .
				'","details":{}}}',
			$refused->body,
		);
		// La negativa consume el desafío y queda guardada: el reintento idéntico recibe los mismos bytes.
		self::assertNotNull(
			$this->fixture->owner
				->execute('SELECT consumed_at FROM activation_challenges WHERE challenge_id=?', [
					Uuid::bytes($input['challenge_id']),
				])
				->fetchColumn(),
		);
		$alert = fn(): array => $this->fixture->owner->one('SELECT * FROM license_alerts');
		self::assertSame(['transfer_blocked', 1], [$alert()['alert_type'], (int) $alert()['attempts']]);
		self::assertSame(
			[
				'server_id' => $other['id'],
				'app_version' => '1.4.0',
				'attempted_server_ids' => [$other['id']],
				'pending_transfer_id' => Uuid::text($pending['id']),
				'pending_since' => '2026-09-25T12:00:00.123456Z',
				'pending_from_server_id' => $old['id'],
				'pending_to_server_id' => $new['id'],
			],
			json_decode($alert()['detail_json'], true),
		);
		self::assertSame($refused->body, $this->app->activations->execute('transfer', $input)->body);
		// Más intentos, de otra computadora y de la anterior: la misma alerta abierta, con su contador.
		$this->clock->instant = $this->clock->instant->modify('+1 hour');
		$another = $this->computer();
		self::assertSame(409, $this->call('transfer', $another, $license['secret'])->status);
		self::assertSame(409, $this->call('transfer', $old, $license['secret'])->status);
		self::assertSame(1, $this->rows('license_alerts'));
		self::assertSame(3, (int) $alert()['attempts']);
		self::assertSame('2026-09-25 14:00:00.123456', $alert()['last_seen_at']);
		self::assertSame('2026-09-25 13:00:00.123456', $alert()['created_at']);
		self::assertSame(
			[$other['id'], $another['id'], $old['id']],
			json_decode($alert()['detail_json'], true)['attempted_server_ids'],
		);
		self::assertSame(3, $this->rows('audit_events', "operation='license.transfer_blocked'"));
		// Nada cambió: la computadora nueva sigue activa y hay una sola transferencia.
		self::assertSame(1, $this->rows('license_transfers'));
		self::assertSame(
			$moved['activation_id'],
			Uuid::text(
				$this->fixture->owner
					->execute("SELECT activation_id FROM activations WHERE state='active'")
					->fetchColumn(),
			),
		);
		self::assertSame(1, $first['license_revision']);
	}

	public function testTransferAvailableSaysWhetherTheSelfServiceTransferIsFree(): void
	{
		$license = $this->issue();
		self::content($this->call('activate', $this->computer(), $license['secret']));
		$limit = fn(): array => self::error($this->call('activate', $this->computer(), $license['secret']));
		self::assertSame(['ACTIVATION_LIMIT', ['transfer_available' => true]], [$limit()['code'], $limit()['details']]);
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame(
			['ACTIVATION_LIMIT', ['transfer_available' => false]],
			[$limit()['code'], $limit()['details']],
		);
	}

	public function testRetriesConflictsChallengesLimitsAndTheCodeIsNeverStored(): void
	{
		$license = $this->issue();
		self::content($this->call('activate', $this->computer(), $license['secret']));
		$new = $this->computer();
		$input = $this->request('transfer', $new, $license['secret']);
		$response = $this->app->activations->execute('transfer', $input);
		self::content($response);
		// Un reintento idéntico recibe exactamente la misma respuesta y no transfiere otra vez.
		self::assertSame(
			$response->body,
			$this->app->activations->execute('transfer', array_reverse($input, true))->body,
		);
		self::assertSame(1, $this->rows('license_transfers'));
		$problem = function (string $action, array $input): string {
			try {
				$this->app->activations->execute($action, $input);
				return 'aceptada';
			} catch (ApiProblem $error) {
				return $error->errorCode;
			}
		};
		// El mismo request_id con otro contenido.
		self::assertSame('REQUEST_CONFLICT', $problem('transfer', ['app_version' => '1.5.0'] + $input));
		// El desafío solo sirve para su acción.
		$wrong = $this->request('activate', $this->computer(), $license['secret']);
		self::assertSame('INVALID_PROOF', $problem('transfer', $wrong));
		$wrong = $this->request('transfer', $this->computer(), $license['secret']);
		self::assertSame('INVALID_PROOF', $problem('activate', $wrong));
		// Una prueba firmada con otra llave.
		$forged = $this->request('transfer', $this->computer(), $license['secret']);
		$forged['installation_public_key'] = $new['public'];
		self::assertSame('INVALID_PROOF', $problem('transfer', $forged));
		// Activar y transferir comparten el límite de intentos por código: 10 cada 15 minutos.
		$attempts = 0;
		try {
			for ($i = 0; $i < 12; ++$i) {
				$this->call($i % 2 === 0 ? 'transfer' : 'activate', $this->computer(), $license['secret']);
				++$attempts;
			}
			self::fail('Code throttle bypassed');
		} catch (Problem $error) {
			self::assertSame(429, $error->status);
		}
		// Ya se habían usado 7 intentos con este código (activar, transferir y los rechazos).
		self::assertSame(3, $attempts);
		foreach (
			[
				'license_requests' => 'response_body',
				'license_requests' => 'proof_message',
				'audit_events' => 'payload_json',
				'license_alerts' => 'detail_json',
			]
			as $table => $column
		) {
			foreach ($this->fixture->owner->all("SELECT $column AS content FROM $table") as $row) {
				self::assertStringNotContainsString($license['secret'], (string) $row['content'], $table);
			}
		}
	}

	public function testTwoComputersTransferAtTheSameTimeAndExactlyOneWins(): void
	{
		$license = $this->issue();
		self::content($this->call('activate', $this->computer(), $license['secret']));
		$requests = [];
		foreach ([$this->computer(), $this->computer()] as $computer) {
			$requests[] = [
				'action' => 'transfer',
				'input' => $this->request('transfer', $computer, $license['secret']),
			];
		}
		$results = ApiRace::run($this->fixture, $license['id'], self::START, $requests);
		$statuses = array_column($results, 'status');
		sort($statuses);
		self::assertSame([200, 409], $statuses);
		foreach ($results as $result) {
			if ($result['status'] === 409) {
				self::assertSame('TRANSFER_LIMIT', json_decode($result['body'], true)['error']['code']);
			}
		}
		self::assertSame(1, $this->rows('license_transfers', "state='pending'"));
		self::assertSame(1, $this->rows('activations', "state='active'"));
		self::assertSame(1, (int) $this->fixture->owner->execute('SELECT attempts FROM license_alerts')->fetchColumn());
	}

	public function testATransferRacingTheDeactivationOfTheOldComputerEndsConsistent(): void
	{
		$license = $this->issue();
		$old = $this->computer();
		$first = self::content($this->call('activate', $old, $license['secret']));
		$new = $this->computer();
		$results = ApiRace::run($this->fixture, $license['id'], self::START, [
			['action' => 'deactivate', 'input' => $this->request('deactivate', $old, null, $first['activation_id'])],
			['action' => 'transfer', 'input' => $this->request('transfer', $new, $license['secret'])],
		]);
		self::assertSame([200, 200], array_column($results, 'status'));
		$state = $this->fixture->owner
			->execute('SELECT state FROM activations WHERE activation_id=?', [Uuid::bytes($first['activation_id'])])
			->fetchColumn();
		// Si la desactivación llegó primero, la nueva activó sin transferencia; si no, la transferencia la cerró.
		self::assertContains([$state, $this->rows('license_transfers')], [['deactivated', 0], ['transferred', 1]]);
		self::assertSame(1, $this->rows('activations', "state='active'"));
		// La computadora anterior recibió su cierre en los dos casos.
		$closing = self::content(new ApiResponse(200, $results[0]['body']));
		self::assertSame(['revoked', $state], [$closing['license_status'], $closing['status_reason']]);
	}
}
