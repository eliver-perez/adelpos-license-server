<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{LicenseState, Uuid};
use AdelPos\License\Tests\{FrozenClock, Installation, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Consultas del panel definitivo (etapa 5, Fase 2; docs/PANEL.md): conteos del resumen, «por vencer» por
 * fechas de calendario en la zona del cliente, vencidas recientes, cobros por mes en la zona del panel,
 * búsqueda, filtros y orden de la lista de licencias, y la lista y el detalle de los clientes.
 */
final class PanelQueriesTest extends TestCase
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
		// 9 de enero de 2027 a las 23:30 en Tijuana (UTC−8): el día local es otro que en UTC.
		$this->clock = new FrozenClock(new \DateTimeImmutable('2027-01-10T07:30:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->app = $this->fixture->app;
		$keys = $this->fixture->signingKeys($this->clock);
		$keys->generate('panel-queries-key', 'license');
		$keys->activate('panel-queries-key', 'Isolated test setup');
		$this->actor = new Actor(
			$this->app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'),
			'superadmin',
		);
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function at(string $instant): void
	{
		$this->clock->instant = new \DateTimeImmutable($instant);
	}

	private function customer(string $name, string $zone = 'America/Tijuana', array $data = []): string
	{
		return $this->app->catalog->saveCustomer(
			$this->actor,
			Uuid::create(),
			null,
			$data + [
				'name' => $name,
				'contact_name' => 'Contacto',
				'email' => '',
				'state' => 'active',
				'time_zone' => $zone,
			],
		)['id'];
	}

	/** Emite una licencia; con $activate, la activa en una computadora sintética nueva. */
	private function license(
		string $customer,
		string $type = 'subscription',
		bool $activate = true,
		array $input = [],
	): array {
		$issued = $this->app->licenses->issue(
			$this->actor,
			Uuid::create(),
			array_replace(
				[
					'customer_id' => $customer,
					'product_id' => 'adel_pos',
					...MySqlFixture::terms($type),
					'amount' => $type === 'trial' ? '0' : '100',
				],
				$input,
			),
		);
		$installation = null;
		if ($activate) {
			$installation = new Installation($this->app);
			$installation->activate($issued['secret']);
		}
		return $issued + ['installation' => $installation];
	}

	private function revoke(string $id, string $reason): void
	{
		$this->app->licenses->change($this->actor, Uuid::create(), $id, 'revoke', [
			'version' => (int) $this->app->read->license($id, 1, 'summary')['row_version'],
			'reason' => $reason,
		]);
	}

	/** @return list<string> */
	private function ids(array $rows): array
	{
		return array_map(static fn(array $row): string => $row['id_text'], $rows);
	}

	public function testDueSoonCountsCalendarDaysInTheCustomerTimeZone(): void
	{
		$customer = $this->customer('Restaurante de Tijuana');
		// Suscripción mensual activada el 9 de enero a las 23:30 locales: vence el 9 de febrero a las 23:30
		// en Tijuana, que es el 10 de febrero a las 07:30 UTC.
		$monthly = $this->license($customer)['id'];
		self::assertSame(
			'2027-02-10 07:30:00.000000',
			$this->fixture->owner->one('SELECT expires_at FROM licenses WHERE license_id=?', [Uuid::bytes($monthly)])[
				'expires_at'
			],
		);
		// 2 de febrero a las 00:00 en Tijuana: faltan 7 días de calendario, aunque falten 7 días y 23.5 horas.
		$this->at('2027-02-02T08:00:00Z');
		$due = $this->app->panel->dueSoon(7);
		self::assertSame([$monthly], $this->ids($due));
		self::assertSame(7, $due[0]['days_left']);
		self::assertSame(LicenseState::ACTIVE, $due[0]['state']);
		// Un segundo antes, todavía es el 1 de febrero en Tijuana: faltan 8 días.
		$this->at('2027-02-02T07:59:59Z');
		self::assertSame([], $this->app->panel->dueSoon(7));
		self::assertSame([$monthly], $this->ids($this->app->panel->dueSoon(15)));
		// El último instante antes de vencer: «vence hoy».
		$this->at('2027-02-10T07:29:59.999999Z');
		self::assertSame(0, $this->app->panel->dueSoon(7)[0]['days_left']);
		// Al vencer ya no está por vencer ni vigente: está en tolerancia.
		$this->at('2027-02-10T07:30:00Z');
		self::assertSame([], $this->app->panel->dueSoon(30));
		self::assertSame(1, $this->app->panel->counts()['grace']);
		self::assertSame(0, $this->app->panel->counts()['active']['subscription']);
		self::assertSame(0, $this->app->panel->licenses(['state' => LicenseState::ACTIVE], 1)['total']);
		self::assertSame(1, $this->app->panel->licenses(['state' => LicenseState::GRACE], 1)['total']);
		// Solo 7, 15 o 30 días.
		$this->expectException(\AdelPos\License\Domain\Problem::class);
		$this->app->panel->dueSoon(10);
	}

	public function testDueSoonListsSubscriptionsAndTrialsButNotPerpetualPendingOrRevoked(): void
	{
		$customer = $this->customer('Restaurante mixto', 'America/Monterrey');
		$subscription = $this->license($customer)['id'];
		$trial = $this->license($customer, 'trial', true, ['trial_duration' => '15d'])['id'];
		$this->license($customer, 'perpetual');
		$this->license($customer, 'subscription', false);
		$revoked = $this->license($customer)['id'];
		$this->revoke($revoked, 'Prueba de la lista');
		$this->at('2027-01-20T16:00:00Z');
		// La prueba de 15 días vence primero.
		self::assertSame([$trial, $subscription], $this->ids($this->app->panel->dueSoon(30)));
		self::assertSame([$trial], $this->ids($this->app->panel->dueSoon(7)));
	}

	public function testDashboardCountsFollowTheStatesOfTheContract(): void
	{
		$customer = $this->customer('Restaurante de los conteos', 'America/Monterrey');
		$subscription = $this->license($customer)['id'];
		$this->license($customer, 'perpetual');
		$this->license($customer, 'trial', true, ['trial_duration' => '15d']);
		$this->license($customer, 'subscription', false);
		$revoked = $this->license($customer)['id'];
		$this->revoke($revoked, 'No cuenta');
		$counts = $this->app->panel->counts();
		self::assertSame(['subscription' => 1, 'perpetual' => 1, 'trial' => 1], $counts['active']);
		self::assertSame(1, $counts['pending']);
		self::assertSame(0, $counts['grace']);
		self::assertSame(0, $counts['expired_recent']);
		self::assertSame(0, $counts['alerts']);
		self::assertSame(0, $counts['offline_pending']);
		// La suscripción vence el 10 de febrero a las 07:30 UTC y su tolerancia termina 5 × 24 horas después.
		$this->at('2027-02-15T07:29:59.999999Z');
		$counts = $this->app->panel->counts();
		self::assertSame(['subscription' => 0, 'perpetual' => 1, 'trial' => 0], $counts['active']);
		self::assertSame(1, $counts['grace']);
		// La prueba venció el 25 de enero sin tolerancia: es una vencida reciente.
		self::assertSame(1, $counts['expired_recent']);
		$this->at('2027-02-15T07:30:00Z');
		$counts = $this->app->panel->counts();
		self::assertSame(0, $counts['grace']);
		self::assertSame(2, $counts['expired_recent']);
		$expired = $this->app->panel->licenses(['state' => LicenseState::EXPIRED], 1);
		self::assertSame(2, $expired['total']);
		self::assertContains($subscription, $this->ids($expired['rows']));
	}

	public function testRecentlyExpiredCountsThirtyCalendarDaysInTheCustomerTimeZone(): void
	{
		$customer = $this->customer('Restaurante de la prueba vencida');
		// Prueba de 15 días activada el 9 de enero a las 23:30 en Tijuana: vence el 24 de enero a las 23:30
		// locales (25 de enero, 07:30 UTC), sin tolerancia.
		$this->license($customer, 'trial', true, ['trial_duration' => '15d']);
		// 23 de febrero a las 23:59 en Tijuana: el vencimiento fue hace 30 días de calendario.
		$this->at('2027-02-24T07:59:59Z');
		self::assertSame(1, $this->app->panel->counts()['expired_recent']);
		// 24 de febrero a las 00:00 en Tijuana: ya pasaron 31.
		$this->at('2027-02-24T08:00:00Z');
		self::assertSame(0, $this->app->panel->counts()['expired_recent']);
		// Sigue vencida: solo sale de las recientes.
		self::assertSame(1, $this->app->panel->licenses(['state' => LicenseState::EXPIRED], 1)['total']);
	}

	public function testRecentlyExpiredCountsFromTheEndOfTheGracePeriod(): void
	{
		$customer = $this->customer('Restaurante de la suscripción vencida');
		// Suscripción mensual: vence el 9 de febrero a las 23:30 en Tijuana y su tolerancia termina el 14 a la
		// misma hora (15 de febrero, 07:30 UTC). El 14 de marzo Tijuana cambia de horario.
		$this->license($customer);
		// 16 de marzo a las 23:59 en Tijuana: el fin de la tolerancia fue hace 30 días de calendario, aunque el
		// vencimiento fue hace 35.
		$this->at('2027-03-17T06:59:59Z');
		self::assertSame(1, $this->app->panel->counts()['expired_recent']);
		$this->at('2027-03-17T07:00:00Z');
		self::assertSame(0, $this->app->panel->counts()['expired_recent']);
	}

	public function testCollectionsSplitMonthsInThePanelTimeZoneAndCountOperationsByConcept(): void
	{
		$this->at('2026-11-20T18:00:00Z');
		$customer = $this->customer('Restaurante de los cobros', 'America/Monterrey');
		// 1 de noviembre a las 00:00 en Monterrey (UTC−6) es el 1 de noviembre a las 06:00 UTC.
		$this->license($customer, 'perpetual', false, ['amount' => '1500.50', 'paid_at' => '2026-11-01T06:00:00Z']);
		$this->license($customer, 'perpetual', false, ['amount' => '1000', 'paid_at' => '2026-11-01T05:59:59Z']);
		$this->license($customer, 'trial', false, ['paid_at' => '2026-11-15T12:00:00Z']);
		$this->license($customer, 'perpetual', false, ['amount' => '999', 'paid_at' => '2026-09-30T23:00:00Z']);
		$collections = $this->app->panel->collections();
		self::assertSame('America/Monterrey', $collections['time_zone']);
		self::assertSame(150050, $collections['current']['total']);
		self::assertSame(2, $collections['current']['operations']);
		self::assertSame(['issue' => 2], $collections['current']['by_concept']);
		self::assertSame('2026-11-01 00:00', $collections['current']['from']->format('Y-m-d H:i'));
		// El segundo anterior al 1 de noviembre en Monterrey es de octubre.
		self::assertSame(100000, $collections['previous']['total']);
		self::assertSame(['issue' => 1], $collections['previous']['by_concept']);
		// Septiembre no entra en ninguno de los dos meses.
		self::assertSame(3, $collections['current']['operations'] + $collections['previous']['operations']);
	}

	public function testLicenseSearchCoversNamesIdentifiersComputersAndReferences(): void
	{
		$north = $this->customer('Restaurante del Norte', 'America/Monterrey', ['legal_name' => 'Norteña SA de CV']);
		$south = $this->customer('Cocina del Sur', 'America/Monterrey');
		$first = $this->license($north, 'subscription', true, ['reference' => 'CONTRATO-77']);
		$second = $this->license($south);
		$search = fn(string $q): array => $this->ids($this->app->panel->licenses(['q' => $q], 1)['rows']);
		self::assertSame([$first['id']], $search('del Norte'));
		self::assertSame([$first['id']], $search('Norteña'));
		self::assertSame([$first['id']], $search('CONTRATO-77'));
		self::assertSame([$second['id']], $search(strtoupper($second['id'])));
		self::assertSame([$second['id']], $search(substr($second['id'], 0, 8)));
		// La computadora que la tiene y también la que la tuvo antes de una transferencia.
		$before = $second['installation']->serverId;
		self::assertSame([$second['id']], $search($before));
		$after = new Installation($this->app);
		self::assertSame(200, $after->transfer($second['secret'])['status']);
		self::assertSame([$second['id']], $search($after->serverId));
		self::assertSame([$second['id']], $search($before));
		// Los comodines de LIKE se buscan como texto.
		self::assertSame([], $search('%'));
		self::assertSame([], $search('_'));
	}

	public function testLicenseFiltersAndSorting(): void
	{
		$customer = $this->customer('Restaurante de los filtros', 'America/Monterrey');
		$subscription = $this->license($customer)['id'];
		$this->at('2027-01-10T08:00:00Z');
		$custom = $this->license($customer, 'subscription', true, [
			...MySqlFixture::terms('subscription', 'premium'),
			'cash_registers' => '9',
			'billing_period' => 'annual',
		]);
		$this->at('2027-01-10T08:30:00Z');
		$perpetual = $this->license($customer, 'perpetual')['id'];
		$this->at('2027-01-10T09:00:00Z');
		$pending = $this->license($customer, 'trial', false)['id'];
		$filter = fn(array $filters): array => $this->ids($this->app->panel->licenses($filters, 1)['rows']);
		self::assertSame([$pending, $perpetual, $custom['id'], $subscription], $filter([]));
		self::assertSame([$subscription, $custom['id'], $perpetual, $pending], $filter(['sort' => 'created_asc']));
		// Por vencimiento, las que no tienen fecha (perpetua y sin activar) van al final.
		self::assertSame([$subscription, $custom['id'], $pending, $perpetual], $filter(['sort' => 'expiry_asc']));
		self::assertSame([$custom['id'], $subscription, $pending, $perpetual], $filter(['sort' => 'expiry_desc']));
		self::assertSame([$perpetual], $filter(['type' => 'perpetual']));
		self::assertSame([$pending], $filter(['state' => LicenseState::PENDING]));
		self::assertSame([$perpetual, $custom['id'], $subscription], $filter(['state' => LicenseState::ACTIVE]));
		self::assertSame([$custom['id']], $filter(['plan' => 'premium']));
		self::assertSame([$custom['id']], $filter(['custom' => '1']));
		self::assertSame([], $filter(['transfer' => 'pending']));
		// Una transferencia por autoservicio queda pendiente hasta que la anterior la confirma.
		self::assertSame(200, (new Installation($this->app))->transfer($custom['secret'])['status']);
		self::assertSame([$custom['id']], $filter(['transfer' => 'pending']));
		$row = $this->app->panel->licenses(['transfer' => 'pending'], 1)['rows'][0];
		self::assertTrue($row['transfer_pending']);
		self::assertNotSame($custom['installation']->serverId, $row['active_server']);
		// Un filtro desconocido no filtra.
		self::assertCount(4, $filter(['state' => 'otro', 'type' => 'otro', 'sort' => 'otro']));
		$this->revoke($perpetual, 'Prueba del filtro');
		self::assertSame([$perpetual], $filter(['state' => LicenseState::REVOKED]));
		// Una licencia revocada antes de activarse está revocada, no sin activar.
		$this->revoke($pending, 'Revocada sin activar');
		self::assertSame([], $filter(['state' => LicenseState::PENDING]));
		self::assertSame([$pending, $perpetual], $filter(['state' => LicenseState::REVOKED]));
	}

	public function testLicenseListPaginatesByTwenty(): void
	{
		$customer = $this->customer('Restaurante de las páginas', 'America/Monterrey');
		for ($i = 0; $i < 21; ++$i) {
			$this->license($customer, 'perpetual', false);
		}
		$first = $this->app->panel->licenses([], 1);
		self::assertSame([21, 1, 2, 20], [$first['total'], $first['page'], $first['pages'], count($first['rows'])]);
		$second = $this->app->panel->licenses([], 99);
		self::assertSame([2, 1], [$second['page'], count($second['rows'])]);
	}

	public function testCustomerListAndDetailShowTheirLicensesAndPayments(): void
	{
		$customer = $this->customer('Café con licencias', 'America/Monterrey', [
			'legal_name' => 'Café SA',
			'tax_id' => 'CAF010101AAA',
			'email' => 'cafe@example.test',
		]);
		$this->customer('Cliente sin licencias', 'America/Monterrey');
		$active = $this->license($customer)['id'];
		$this->at('2027-01-10T08:00:00Z');
		$pending = $this->license($customer, 'perpetual', false, ['amount' => '2500'])['id'];
		foreach (['Café', 'Café SA', 'CAF010101', 'cafe@example'] as $query) {
			$found = $this->app->panel->customers(['q' => $query], 1);
			self::assertSame(1, $found['total'], $query);
			self::assertSame(2, (int) $found['rows'][0]['license_count']);
			self::assertSame(1, (int) $found['rows'][0]['active_count']);
		}
		self::assertSame(2, $this->app->panel->customers([], 1)['total']);
		self::assertSame(0, $this->app->panel->customers(['state' => 'archived'], 1)['total']);
		$licenses = $this->app->panel->customerLicenses($customer);
		self::assertSame([$pending, $active], $this->ids($licenses));
		self::assertSame([LicenseState::PENDING, LicenseState::ACTIVE], array_column($licenses, 'state'));
		$payments = $this->app->panel->customerPayments($customer);
		self::assertSame(['2500.00', '100.00'], array_column($payments, 'amount'));
		self::assertSame([$pending, $active], array_column($payments, 'license_text'));
	}

	public function testPropagationPreviewMatchesWhatSavingThePlanDoes(): void
	{
		$customer = $this->customer('Restaurante del catálogo', 'America/Monterrey');
		// Siguen el plan elemental (3, 3, 3): una suscripción y una perpetua activadas y una prueba sin activar.
		$this->license($customer);
		$this->license($customer, 'perpetual');
		$this->license($customer, 'trial', false);
		// No las alcanza la propagación: una personalizada, una revocada y una vencida sin tolerancia.
		$this->license($customer, 'subscription', true, ['cash_registers' => '7']);
		$this->revoke($this->license($customer)['id'], 'Fuera del catálogo');
		$this->license($customer, 'trial', true);
		$this->at('2027-01-26T07:30:00Z');
		$groups = $this->app->panel->propagationGroups('elemental');
		self::assertSame(
			[['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3, 'licenses' => 3, 'active' => 2]],
			$groups,
		);
		$current = ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3];
		$impact = fn(array $limits): array => \AdelPos\License\Infrastructure\PanelQueries::propagationImpact(
			$groups,
			$current,
			$limits + $current,
		);
		// Solo los aumentos cuentan: bajar un cupo, o dejarlo igual, no cambia ninguna licencia.
		self::assertSame(['licenses' => 0, 'revisions' => 0], $impact(['cash_registers' => 2]));
		self::assertSame(['licenses' => 0, 'revisions' => 0], $impact([]));
		self::assertSame(['licenses' => 3, 'revisions' => 2], $impact(['kitchen_displays' => 4, 'order_takers' => 1]));
		// Una licencia que se quedó abajo (el plan subió antes solo para las nuevas) no cambia si ese cupo no sube
		// ahora; una que espera su reducción (cupos mayores que el plan) no cambia si el aumento no la supera.
		$pure = fn(
			array $group,
			array $now,
			array $limits,
		): array => \AdelPos\License\Infrastructure\PanelQueries::propagationImpact(
			[$group + ['licenses' => 1, 'active' => 1]],
			$now,
			$limits,
		);
		$below = ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3];
		$plan = ['cash_registers' => 4, 'order_takers' => 3, 'kitchen_displays' => 3];
		self::assertSame(['licenses' => 0, 'revisions' => 0], $pure($below, $plan, $plan));
		self::assertSame(['licenses' => 1, 'revisions' => 1], $pure($below, $plan, ['order_takers' => 4] + $plan));
		$waiting = ['cash_registers' => 6, 'order_takers' => 3, 'kitchen_displays' => 3];
		self::assertSame(
			['licenses' => 0, 'revisions' => 0],
			$pure($waiting, $current, ['cash_registers' => 5] + $current),
		);
		self::assertSame(
			['licenses' => 1, 'revisions' => 1],
			$pure($waiting, $current, ['cash_registers' => 7] + $current),
		);
		self::assertCount(3, $this->app->panel->propagationExamples('elemental'));
		self::assertSame([], $this->app->panel->propagationGroups('premium'));
		// Guardar con «también las existentes» hace exactamente lo que dijo la vista previa.
		$saved = $this->app->plans->update($this->actor, Uuid::create(), 'elemental', [
			'display_name' => 'Plan elemental',
			'cash_registers' => '3',
			'order_takers' => '1',
			'kitchen_displays' => '4',
			'apply_to' => 'existing',
			'version' => (int) $this->app->read->plan('elemental')['row_version'],
		]);
		self::assertSame([3, 2], [$saved['licenses_updated'], $saved['revisions_signed']]);
		// La auditoría se busca también por las operaciones cuyo nombre en español coincide (Kernel las calcula).
		$audit = fn(array $filters): int => $this->app->read->page('audit', $filters, 1)['total'];
		self::assertSame(0, $audit(['q' => 'Licencia emitida']));
		self::assertSame(6, $audit(['q' => 'Licencia emitida', 'operations' => ['license.issued']]));
		self::assertSame(1, $audit(['q' => 'plan.updated']));
	}
}
