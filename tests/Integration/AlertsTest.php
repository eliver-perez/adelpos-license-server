<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Http\View;
use AdelPos\License\Tests\TransferScenario;
use PHPUnit\Framework\TestCase;

/**
 * Alertas del panel (contrato §8.4, reglas 2 y 5; decisión 4 del propietario; etapa 4, Fase 4): un
 * intento de transferencia bloqueado y dos o más transferencias en los 12 meses anteriores, una alerta
 * abierta por tipo y licencia, y su revisión.
 */
final class AlertsTest extends TestCase
{
	use TransferScenario;

	/** Activa la licencia en una computadora y devuelve la computadora con su activación. */
	private function activated(array $license): array
	{
		$computer = $this->computer();
		$computer['activation'] = self::content($this->call('activate', $computer, $license['secret']))[
			'activation_id'
		];
		return $computer;
	}

	/**
	 * Transfiere por autoservicio a una computadora nueva y deja que la anterior confirme al consultar.
	 * Devuelve la computadora nueva con su activación.
	 */
	private function moveTo(array $license, array $from): array
	{
		$to = $this->computer();
		$to['activation'] = self::content($this->call('transfer', $to, $license['secret']))['activation_id'];
		self::content($this->call('refresh', $from, null, $from['activation']));
		return $to;
	}

	private function alerts(string $licenseId, string $type): array
	{
		return $this->fixture->owner->all(
			'SELECT * FROM license_alerts WHERE license_id=? AND alert_type=? ORDER BY created_at,alert_id',
			[Uuid::bytes($licenseId), $type],
		);
	}

	private function at(string $instant): void
	{
		$this->clock->instant = new \DateTimeImmutable($instant);
	}

	public function testABlockedTransferAndFrequentTransfersOpenTheirAlerts(): void
	{
		// Un intento bloqueado por TRANSFER_LIMIT.
		$blocked = $this->issue();
		$this->activated($blocked);
		self::content($this->call('transfer', $this->computer(), $blocked['secret']));
		self::assertSame(409, $this->call('transfer', $this->computer(), $blocked['secret'])->status);
		self::assertCount(1, $this->alerts($blocked['id'], 'transfer_blocked'));
		// Una sola transferencia no es frecuente; la segunda en 12 meses, confirmada o no, sí.
		self::assertCount(0, $this->alerts($blocked['id'], 'frequent_transfers'));
		$license = $this->issue();
		$first = $this->activated($license);
		$this->at('2026-10-01T12:00:00Z');
		$second = $this->moveTo($license, $first);
		self::assertSame([], $this->alerts($license['id'], 'frequent_transfers'));
		$this->at('2026-12-01T12:00:00Z');
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		$alerts = $this->alerts($license['id'], 'frequent_transfers');
		self::assertCount(1, $alerts);
		$detail = json_decode($alerts[0]['detail_json'], true);
		self::assertSame(
			[2, '2025-12-01T12:00:00.000000Z', 'America/Monterrey', ['self_service', 'self_service']],
			[
				$detail['transfers'],
				$detail['window_starts_at'],
				$detail['time_zone'],
				array_column($detail['latest'], 'via'),
			],
		);
		self::assertSame(1, (int) $alerts[0]['attempts']);
		// La auditoría de la transferencia nombra la alerta que abrió.
		$event = json_decode(
			$this->fixture->owner
				->execute(
					"SELECT payload_json FROM audit_events WHERE operation='license.transferred' ORDER BY sequence DESC LIMIT 1",
				)
				->fetchColumn(),
			true,
		);
		self::assertSame(
			[Uuid::text($alerts[0]['alert_id']), true],
			[$event['metadata']['frequent_alert']['alert_id'], $event['metadata']['frequent_alert']['opened']],
		);
		unset($second);
	}

	public function testOneOpenAlertKeepsItsCounterAndANewOneOpensAfterTheReview(): void
	{
		$license = $this->issue();
		$computer = $this->activated($license);
		foreach (['2026-10-01', '2026-11-01', '2026-12-01'] as $day) {
			$this->at($day . 'T12:00:00Z');
			$computer = $this->moveTo($license, $computer);
		}
		$alerts = $this->alerts($license['id'], 'frequent_transfers');
		self::assertCount(1, $alerts);
		self::assertSame(
			[2, '2026-11-01 12:00:00.000000', '2026-12-01 12:00:00.000000', 3],
			[
				(int) $alerts[0]['attempts'],
				$alerts[0]['created_at'],
				$alerts[0]['last_seen_at'],
				json_decode($alerts[0]['detail_json'], true)['transfers'],
			],
		);
		// Revisada, la misma situación abre una alerta nueva.
		$this->app->alerts->review($this->actor, Uuid::create(), Uuid::text($alerts[0]['alert_id']));
		$this->at('2027-01-01T12:00:00Z');
		$this->moveTo($license, $computer);
		$alerts = $this->alerts($license['id'], 'frequent_transfers');
		self::assertCount(2, $alerts);
		self::assertNotNull($alerts[0]['reviewed_at']);
		self::assertSame(
			[null, 1, 4],
			[
				$alerts[1]['reviewed_at'],
				(int) $alerts[1]['attempts'],
				json_decode($alerts[1]['detail_json'], true)['transfers'],
			],
		);
		// Lo mismo con un intento bloqueado.
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame(409, $this->call('transfer', $this->computer(), $license['secret'])->status);
		$blocked = $this->alerts($license['id'], 'transfer_blocked');
		$this->app->alerts->review($this->actor, Uuid::create(), Uuid::text($blocked[0]['alert_id']));
		self::assertSame(409, $this->call('transfer', $this->computer(), $license['secret'])->status);
		self::assertCount(2, $this->alerts($license['id'], 'transfer_blocked'));
	}

	public function testTheTwelveMonthsAreCalendarMonthsInTheCustomerTimeZone(): void
	{
		// Tijuana cambia de horario el 8 de marzo de 2026 y el 14 de marzo de 2027. El 10 de marzo a las 10:00 es
		// 17:00 UTC en 2026 y 18:00 UTC en 2027: doce meses de calendario no son lo mismo en UTC.
		$this->customer = $this->app->catalog->saveCustomer($this->actor, Uuid::create(), null, [
			'name' => 'Restaurante en Tijuana',
			'contact_name' => 'Prueba',
			'email' => '',
			'state' => 'active',
			'time_zone' => 'America/Tijuana',
		])['id'];
		$cases = [
			'el límite exacto cuenta' => ['2026-03-10T17:00:00Z', '2027-03-10T18:00:00Z', 1],
			'un microsegundo antes ya no' => ['2026-03-10T17:00:00Z', '2027-03-10T18:00:00.000001Z', 0],
			'en UTC quedaría fuera, en Tijuana entra' => ['2026-03-10T17:30:00Z', '2027-03-10T18:00:00Z', 1],
		];
		foreach ($cases as $case => [$first, $second, $expected]) {
			$this->at('2026-03-01T12:00:00Z');
			$license = $this->issue();
			$computer = $this->activated($license);
			$this->at($first);
			$computer = $this->moveTo($license, $computer);
			$this->at($second);
			$this->moveTo($license, $computer);
			self::assertCount($expected, $this->alerts($license['id'], 'frequent_transfers'), $case);
		}
		self::assertSame(
			'2026-03-10T17:00:00.000000Z',
			json_decode(
				$this->fixture->owner->execute('SELECT detail_json FROM license_alerts LIMIT 1')->fetchColumn(),
				true,
			)['window_starts_at'],
		);
	}

	public function testTheOwnerTransfersCountForTheAlert(): void
	{
		$license = $this->issue();
		$first = $this->activated($license);
		// El propietario recupera la plaza (transferencia del propietario, sin destino).
		$this->app->offline->release($this->actor, Uuid::create(), $license['id'], [
			'activation_id' => $first['activation'],
			'version' => 1,
			'reason' => 'Equipo averiado',
			'accept_offline_limit' => '1',
		]);
		self::assertSame([], $this->alerts($license['id'], 'frequent_transfers'));
		// Otra computadora activa y, un mes después, otra transfiere por autoservicio: son dos en 12 meses.
		$this->activated($license);
		$this->at('2026-10-25T12:00:00Z');
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		$alerts = $this->alerts($license['id'], 'frequent_transfers');
		self::assertCount(1, $alerts);
		self::assertSame(
			['self_service', 'owner'],
			array_column(json_decode($alerts[0]['detail_json'], true)['latest'], 'via'),
		);
		// Y la transferencia del propietario que llega después también suma un caso a la misma alerta.
		$active = $this->fixture->owner
			->execute("SELECT BIN_TO_UUID(activation_id) FROM activations WHERE state='active'")
			->fetchColumn();
		$this->app->offline->release($this->actor, Uuid::create(), $license['id'], [
			'activation_id' => $active,
			'version' => 1,
			'reason' => 'Otro equipo averiado',
			'accept_offline_limit' => '1',
		]);
		self::assertSame(2, (int) $this->alerts($license['id'], 'frequent_transfers')[0]['attempts']);
	}

	public function testTheOperatorAndTheSuperadministratorReviewAndTheViewerOnlySees(): void
	{
		$license = $this->issue();
		$this->activated($license);
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame(409, $this->call('transfer', $this->computer(), $license['secret'])->status);
		$alertId = Uuid::text($this->alerts($license['id'], 'transfer_blocked')[0]['alert_id']);
		$accounts = [];
		foreach (['operator', 'viewer'] as $role) {
			$accounts[$role] = new Actor(
				$this->app->admins->create($this->actor, Uuid::create(), [
					'login' => $role . '@example.test',
					'name' => 'Cuenta ' . $role,
					'role' => $role,
					'new_password' => 'Fixture-Password-Only-2026!',
				])['id'],
				$role,
			);
		}
		try {
			$this->app->alerts->review($accounts['viewer'], Uuid::create(), $alertId);
			self::fail('The viewer reviewed an alert');
		} catch (Problem $error) {
			self::assertSame(403, $error->status);
		}
		self::assertSame(1, $this->app->read->alertSummary()['open']);
		$this->clock->instant = $this->clock->instant->modify('+1 hour');
		$operation = Uuid::create();
		$this->app->alerts->review($accounts['operator'], $operation, $alertId);
		$alert = $this->alerts($license['id'], 'transfer_blocked')[0];
		self::assertSame(
			[Uuid::bytes($accounts['operator']->id), $this->clock->sql()],
			[$alert['reviewed_by'], $alert['reviewed_at']],
		);
		$event = $this->fixture->owner->one(
			"SELECT actor_id,target_id FROM audit_events WHERE operation='alert.reviewed'",
		);
		self::assertSame([Uuid::bytes($accounts['operator']->id), $alertId], [$event['actor_id'], $event['target_id']]);
		// Repetir la misma operación no cambia nada; revisarla de nuevo se rechaza.
		self::assertTrue($this->app->alerts->review($accounts['operator'], $operation, $alertId)['replayed']);
		try {
			$this->app->alerts->review($this->actor, Uuid::create(), $alertId);
			self::fail('Reviewed twice');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
		self::assertSame(1, $this->rows('audit_events', "operation='alert.reviewed'"));
		self::assertSame(['open' => 0, 'recent' => []], $this->app->read->alertSummary());
	}

	public function testTheTopBarShowsHowManyAlertsAreUnreviewed(): void
	{
		$license = $this->issue();
		$computer = $this->activated($license);
		$this->at('2026-10-01T12:00:00Z');
		$computer = $this->moveTo($license, $computer);
		$this->at('2026-11-01T12:00:00Z');
		self::content($this->call('transfer', $this->computer(), $license['secret']));
		self::assertSame(409, $this->call('transfer', $this->computer(), $license['secret'])->status);
		$summary = $this->app->read->alertSummary();
		self::assertSame(2, $summary['open']);
		$render = function (Actor $actor, array $summary): string {
			ob_start();
			(new View())->render('error', [
				'title' => 'Prueba',
				'message' => 'Prueba de la barra superior',
				'status' => 200,
				'actor' => $actor,
				'csrf' => 'csrf',
				'path' => '/admin',
				'now' => $this->clock->now(),
				'alerts' => $summary,
			]);
			return (string) ob_get_clean();
		};
		$html = $render($this->actor, $summary);
		self::assertStringContainsString('data-testid="notification-count">2</span>', $html);
		self::assertStringContainsString('aria-label="Notificaciones: 2 alertas sin revisar"', $html);
		self::assertSame(2, substr_count($html, 'href="/admin/licenses/' . $license['id'] . '"'));
		// Sin alertas sin revisar, el botón sigue y el número no aparece.
		foreach ($this->alerts($license['id'], 'transfer_blocked') as $alert) {
			$this->app->alerts->review($this->actor, Uuid::create(), Uuid::text($alert['alert_id']));
		}
		foreach ($this->alerts($license['id'], 'frequent_transfers') as $alert) {
			$this->app->alerts->review($this->actor, Uuid::create(), Uuid::text($alert['alert_id']));
		}
		$html = $render($this->actor, $this->app->read->alertSummary());
		self::assertStringContainsString('aria-label="Notificaciones: 0 alertas sin revisar"', $html);
		self::assertStringNotContainsString('notification-count', $html);
		// La lista muestra primero las sin revisar y deja filtrar las revisadas.
		$this->call('transfer', $this->computer(), $license['secret']);
		$listing = $this->app->read->page('alerts', ['status' => ''], 1);
		self::assertSame([null], array_slice(array_column($listing['rows'], 'reviewed_at'), 0, 1));
		self::assertSame(3, $listing['total']);
		self::assertSame(2, $this->app->read->page('alerts', ['status' => 'reviewed'], 1)['total']);
		self::assertSame(1, $this->app->read->page('alerts', ['status' => 'open'], 1)['total']);
		self::assertSame(1, $this->app->read->page('alerts', ['type' => 'frequent_transfers'], 1)['total']);
		unset($computer);
	}
}
