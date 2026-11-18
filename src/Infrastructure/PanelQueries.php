<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

use AdelPos\License\Domain\{LicenseState, LicenseTerms, Money, Problem, Uuid};

/**
 * Consultas del panel definitivo (etapa 5; docs/PANEL.md): conteos y cobros del resumen, «por vencer»,
 * la lista de licencias con su búsqueda, filtros y orden, y la lista y el detalle de los clientes.
 *
 * Los estados siguen las reglas del contrato (§5.6) con la hora del servidor, igual que
 * Domain\LicenseState. Lo que depende de la fecha de calendario en la zona del cliente se acota primero
 * en SQL, con rangos sobre columnas indexadas, y se termina de filtrar en PHP.
 */
final class PanelQueries
{
	public const PAGE_SIZE = 20;
	/** Ventanas de «por vencer», en días de calendario en la zona del cliente. */
	public const DUE_WINDOWS = [7, 15, 30];
	public const RECENT_EXPIRY_DAYS = 30;
	/** Zona con la que se cortan los meses de los cobros: la del panel. */
	public const PANEL_TIME_ZONE = 'America/Monterrey';
	public const SORTS = [
		'created_desc' => 'l.created_at DESC,l.license_id',
		'created_asc' => 'l.created_at ASC,l.license_id',
		'expiry_asc' => 'l.expires_at IS NULL,l.expires_at ASC,l.created_at DESC,l.license_id',
		'expiry_desc' => 'l.expires_at IS NULL,l.expires_at DESC,l.created_at DESC,l.license_id',
	];

	/** Columnas de una licencia para las listas: con su cliente y la computadora activa. */
	private const LICENSE_SELECT = 'l.license_type,l.billing_period,l.trial_duration,l.updates_months,l.commercial_status,
			l.period_starts_at,l.expires_at,l.grace_days,l.updates_until,l.plan_id,l.plan_name,l.plan_custom,
			l.cash_registers,l.order_takers,l.kitchen_displays,l.commercial_reference,l.created_at,
			BIN_TO_UUID(l.license_id) AS id_text,BIN_TO_UUID(c.id) AS customer_text,c.display_name AS customer_name,
			c.legal_name,c.time_zone,BIN_TO_UUID(a.server_id) AS active_server,a.activated_at AS active_since,
			EXISTS(SELECT 1 FROM license_transfers t WHERE t.pending_license_id=l.license_id) AS transfer_pending';

	private const LICENSE_FROM = 'licenses l
			JOIN customers c ON c.id=l.customer_id
			LEFT JOIN activations a ON a.license_id=l.license_id AND a.state=\'active\'';

	public function __construct(private Database $db, private Clock $clock) {}

	/**
	 * Tarjetas del resumen: licencias vigentes por tipo, sin activar, en tolerancia, vencidas en los
	 * últimos 30 días (fin de la tolerancia en la zona del cliente), alertas sin revisar y solicitudes
	 * sin internet pendientes.
	 */
	public function counts(): array
	{
		$now = $this->clock->sql();
		$row = $this->db->one(
			"SELECT COALESCE(SUM(period_starts_at IS NULL),0) AS pending,
					COALESCE(SUM(period_starts_at IS NOT NULL AND license_type='subscription' AND expires_at>?),0)
						AS active_subscription,
					COALESCE(SUM(period_starts_at IS NOT NULL AND license_type='perpetual'),0) AS active_perpetual,
					COALESCE(SUM(period_starts_at IS NOT NULL AND license_type='trial' AND expires_at>?),0)
						AS active_trial,
					COALESCE(SUM(period_starts_at IS NOT NULL AND license_type<>'perpetual' AND expires_at<=?
						AND DATE_ADD(expires_at,INTERVAL grace_days DAY)>?),0) AS grace
				FROM licenses
				WHERE commercial_status='issued'",
			[$now, $now, $now, $now],
		);
		return [
			'active' => [
				'subscription' => (int) $row['active_subscription'],
				'perpetual' => (int) $row['active_perpetual'],
				'trial' => (int) $row['active_trial'],
			],
			'pending' => (int) $row['pending'],
			'grace' => (int) $row['grace'],
			'expired_recent' => count($this->recentlyExpired()),
			'alerts' => (int) $this->db
				->execute('SELECT COUNT(*) FROM license_alerts WHERE reviewed_at IS NULL')
				->fetchColumn(),
			'offline_pending' => $this->offlinePending(),
		];
	}

	/** Solicitudes sin internet sin decisión, para la navegación y el resumen. */
	public function offlinePending(): int
	{
		return (int) $this->db
			->execute(
				'SELECT COUNT(*)
					FROM offline_requests o
					LEFT JOIN offline_decisions d ON d.offline_id=o.id
					WHERE d.offline_id IS NULL',
			)
			->fetchColumn();
	}

	/**
	 * Licencias vencidas cuyo fin de tolerancia cae, en la zona del cliente, entre hoy − 30 días y hoy.
	 * La tolerancia de una licencia es de 0 o 5 días: el rango sobre expires_at (índice) la cubre con margen.
	 */
	public function recentlyExpired(): array
	{
		$now = $this->clock->now();
		$rows = $this->db->all(
			"SELECT l.expires_at,l.grace_days,c.time_zone
				FROM licenses l
				JOIN customers c ON c.id=l.customer_id
				WHERE l.commercial_status='issued' AND l.period_starts_at IS NOT NULL AND l.license_type<>'perpetual'
					AND l.expires_at>=? AND l.expires_at<=?
					AND DATE_ADD(l.expires_at,INTERVAL l.grace_days DAY)<=?",
			[self::sql($now->modify('-40 days')), self::sql($now), self::sql($now)],
		);
		return array_values(
			array_filter(
				$rows,
				fn(array $row): bool => LicenseState::calendarDays(
					self::sql(LicenseState::graceLimit($row['expires_at'], (int) $row['grace_days'])),
					$row['time_zone'],
					$now,
				) >= -self::RECENT_EXPIRY_DAYS,
			),
		);
	}

	/**
	 * «Por vencer»: suscripciones y pruebas no revocadas, activadas y todavía no vencidas, cuya fecha de
	 * vencimiento en la zona del cliente cae entre hoy y hoy + $days. Cada fila lleva sus días restantes.
	 */
	public function dueSoon(int $days): array
	{
		if (!in_array($days, self::DUE_WINDOWS, true)) {
			throw new Problem('Elige 7, 15 o 30 días.');
		}
		$now = $this->clock->now();
		$rows = $this->db->all(
			'SELECT ' .
				self::LICENSE_SELECT .
				' FROM ' .
				self::LICENSE_FROM .
				" WHERE l.commercial_status='issued' AND l.license_type IN ('subscription','trial')
					AND l.period_starts_at IS NOT NULL AND l.expires_at>? AND l.expires_at<?
				ORDER BY l.expires_at,l.license_id
				LIMIT 200",
			[self::sql($now), self::sql($now->modify('+' . ($days + 2) . ' days'))],
		);
		$due = [];
		foreach ($rows as $row) {
			$row['days_left'] = LicenseState::calendarDays($row['expires_at'], $row['time_zone'], $now);
			if ($row['days_left'] <= $days) {
				$due[] = self::license($row, $now);
			}
		}
		return $due;
	}

	/**
	 * Cobros del mes en curso y del anterior, con los meses cortados en la zona del panel: total en
	 * centavos y número de operaciones por concepto. Son cifras informativas, no contables.
	 */
	public function collections(): array
	{
		$zone = new \DateTimeZone(self::PANEL_TIME_ZONE);
		$current = $this->clock->now()->setTimezone($zone)->modify('first day of this month')->setTime(0, 0);
		$months = [
			'current' => [$current, $current->modify('+1 month')],
			'previous' => [$current->modify('-1 month'), $current],
		];
		$result = [];
		// Todo pago es en MXN (chk_payment_currency): no hace falta filtrar la moneda, y así el índice
		// ix_payment_paid_concept (migración 009) cubre la consulta completa.
		foreach ($months as $key => [$from, $until]) {
			$rows = $this->db->all(
				'SELECT concept,COUNT(*) AS operations,SUM(amount) AS total
					FROM license_payments
					WHERE paid_at>=? AND paid_at<?
					GROUP BY concept',
				[self::sql($from), self::sql($until)],
			);
			$byConcept = [];
			$total = 0;
			$operations = 0;
			foreach ($rows as $row) {
				$byConcept[$row['concept']] = (int) $row['operations'];
				$total += Money::fromSql($row['total']) ?? 0;
				$operations += (int) $row['operations'];
			}
			$result[$key] = [
				'from' => $from,
				'until' => $until,
				'total' => $total,
				'operations' => $operations,
				'by_concept' => $byConcept,
			];
		}
		$result['time_zone'] = self::PANEL_TIME_ZONE;
		return $result;
	}

	/**
	 * Lista de licencias con búsqueda, filtros, orden y paginación (docs/PANEL.md, sección 3.2).
	 *
	 * Filtros: q (cliente, razón social, license_id, server_id de cualquier computadora que la haya tenido o
	 * referencia comercial), type, state, plan, custom ('1') y transfer ('pending'). Orden: SORTS.
	 */
	public function licenses(array $filters, int $page): array
	{
		$now = $this->clock->now();
		[$where, $parameters] = $this->licenseConditions($filters, self::sql($now));
		$sort = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['created_desc'];
		$clause = implode(' AND ', $where);
		$total = (int) $this->db
			->execute(
				'SELECT COUNT(*) FROM licenses l JOIN customers c ON c.id=l.customer_id WHERE ' . $clause,
				$parameters,
			)
			->fetchColumn();
		$pages = max(1, (int) ceil($total / self::PAGE_SIZE));
		$page = min(max(1, $page), $pages);
		$rows = $this->db->all(
			'SELECT ' .
				self::LICENSE_SELECT .
				' FROM ' .
				self::LICENSE_FROM .
				' WHERE ' .
				$clause .
				' ORDER BY ' .
				$sort .
				' LIMIT ? OFFSET ?',
			[...$parameters, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE],
		);
		return [
			'rows' => array_map(fn(array $row): array => self::license($row, $now), $rows),
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
		];
	}

	/** @return array{0: list<string>, 1: list<mixed>} Condiciones de la lista de licencias. */
	private function licenseConditions(array $filters, string $now): array
	{
		$where = ['1=1'];
		$parameters = [];
		$query = trim((string) ($filters['q'] ?? ''));
		if ($query !== '') {
			$like = '%' . addcslashes($query, '%_\\') . '%';
			$conditions = ['c.display_name LIKE ?', 'c.legal_name LIKE ?', 'l.commercial_reference LIKE ?'];
			$parameters = [$like, $like, $like];
			// Un identificador (o parte de él): la licencia o cualquier computadora que la haya tenido. LIKE no
			// distingue mayúsculas con la colación de la conexión, así que el identificador se busca tal cual.
			if (preg_match('/\A[0-9a-fA-F-]{4,36}\z/', $query)) {
				$identifier = '%' . $query . '%';
				$conditions[] = 'BIN_TO_UUID(l.license_id) LIKE ?';
				$conditions[] =
					'EXISTS(SELECT 1 FROM activations x WHERE x.license_id=l.license_id AND BIN_TO_UUID(x.server_id) LIKE ?)';
				$parameters[] = $identifier;
				$parameters[] = $identifier;
			}
			$where[] = '(' . implode(' OR ', $conditions) . ')';
		}
		if (in_array($filters['type'] ?? '', LicenseTerms::TYPES, true)) {
			$where[] = 'l.license_type=?';
			$parameters[] = $filters['type'];
		}
		$activated = "l.commercial_status='issued' AND l.period_starts_at IS NOT NULL";
		$limit = 'DATE_ADD(l.expires_at,INTERVAL l.grace_days DAY)';
		switch ($filters['state'] ?? '') {
			case LicenseState::REVOKED:
				$where[] = "l.commercial_status='revoked'";
				break;
			case LicenseState::PENDING:
				$where[] = "l.commercial_status='issued' AND l.period_starts_at IS NULL";
				break;
			case LicenseState::ACTIVE:
				$where[] = $activated . " AND (l.license_type='perpetual' OR l.expires_at>?)";
				$parameters[] = $now;
				break;
			case LicenseState::GRACE:
				$where[] = $activated . " AND l.license_type<>'perpetual' AND l.expires_at<=? AND " . $limit . '>?';
				$parameters[] = $now;
				$parameters[] = $now;
				break;
			case LicenseState::EXPIRED:
				$where[] = $activated . " AND l.license_type<>'perpetual' AND " . $limit . '<=?';
				$parameters[] = $now;
				break;
		}
		if (is_string($filters['plan'] ?? null) && $filters['plan'] !== '') {
			$where[] = 'l.plan_id=?';
			$parameters[] = $filters['plan'];
		}
		if (($filters['custom'] ?? '') === '1') {
			$where[] = 'l.plan_custom=1';
		}
		if (($filters['transfer'] ?? '') === 'pending') {
			$where[] = 'EXISTS(SELECT 1 FROM license_transfers t WHERE t.pending_license_id=l.license_id)';
		}
		return [$where, $parameters];
	}

	/** Lista de clientes con su número de licencias y cuántas están vigentes. */
	public function customers(array $filters, int $page): array
	{
		$where = ['1=1'];
		$parameters = [];
		$query = trim((string) ($filters['q'] ?? ''));
		if ($query !== '') {
			$like = '%' . addcslashes($query, '%_\\') . '%';
			$where[] = '(c.display_name LIKE ? OR c.legal_name LIKE ? OR c.tax_id LIKE ? OR t.email LIKE ?)';
			array_push($parameters, $like, $like, $like, $like);
		}
		if (in_array($filters['state'] ?? '', ['active', 'archived'], true)) {
			$where[] = 'c.state=?';
			$parameters[] = $filters['state'];
		}
		$clause = implode(' AND ', $where);
		$from = 'customers c LEFT JOIN customer_contacts t ON t.customer_id=c.id';
		$total = (int) $this->db
			->execute('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $clause, $parameters)
			->fetchColumn();
		$pages = max(1, (int) ceil($total / self::PAGE_SIZE));
		$page = min(max(1, $page), $pages);
		$now = $this->clock->sql();
		$rows = $this->db->all(
			"SELECT BIN_TO_UUID(c.id) AS id_text,c.display_name,c.legal_name,c.tax_id,c.time_zone,c.state,
					t.name AS contact_name,t.email,
					(SELECT COUNT(*) FROM licenses l WHERE l.customer_id=c.id) AS license_count,
					(SELECT COUNT(*) FROM licenses l WHERE l.customer_id=c.id AND l.commercial_status='issued'
						AND l.period_starts_at IS NOT NULL AND (l.license_type='perpetual' OR l.expires_at>?))
						AS active_count
				FROM " .
				$from .
				' WHERE ' .
				$clause .
				' ORDER BY c.display_name,c.id LIMIT ? OFFSET ?',
			[$now, ...$parameters, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE],
		);
		return compact('rows', 'total', 'page', 'pages');
	}

	/** Licencias de un cliente, de la más reciente a la más antigua, con su estado. */
	public function customerLicenses(string $customerId): array
	{
		$now = $this->clock->now();
		return array_map(
			fn(array $row): array => self::license($row, $now),
			$this->db->all(
				'SELECT ' .
					self::LICENSE_SELECT .
					' FROM ' .
					self::LICENSE_FROM .
					' WHERE l.customer_id=? ORDER BY l.created_at DESC,l.license_id LIMIT 200',
				[Uuid::bytes($customerId)],
			),
		);
	}

	/** Pagos de todas las licencias de un cliente, del más reciente al más antiguo. */
	public function customerPayments(string $customerId): array
	{
		return $this->db->all(
			'SELECT BIN_TO_UUID(p.payment_id) AS id_text,BIN_TO_UUID(p.license_id) AS license_text,p.concept,p.term,
					p.plan_id,p.paid_at,p.amount,p.catalog_amount,p.credit_amount,p.suggested_amount,p.override_reason,
					p.reference,l.license_type
				FROM license_payments p
				JOIN licenses l ON l.license_id=p.license_id
				WHERE l.customer_id=?
				ORDER BY p.paid_at DESC,p.created_at DESC
				LIMIT 100',
			[Uuid::bytes($customerId)],
		);
	}

	/** Una fila de licencia con su estado calculado. */
	private static function license(array $row, \DateTimeImmutable $now): array
	{
		$row['state'] = LicenseState::of($row, $now);
		$row['transfer_pending'] = (bool) $row['transfer_pending'];
		$row['plan_custom'] = (bool) $row['plan_custom'];
		return $row;
	}

	private static function sql(\DateTimeImmutable $instant): string
	{
		return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
	}
}
