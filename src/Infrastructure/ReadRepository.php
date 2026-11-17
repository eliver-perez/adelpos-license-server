<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

use AdelPos\License\Domain\{Problem, Protocol, Uuid};

final class ReadRepository
{
	public function __construct(private Database $db, private Clock $clock) {}

	public function dashboard(): array
	{
		return [
			'licenses' => (int) $this->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn(),
			'customers' => (int) $this->db
				->execute("SELECT COUNT(*) FROM customers WHERE state='active'")
				->fetchColumn(),
			'perpetual' => (int) $this->db
				->execute("SELECT COUNT(*) FROM licenses WHERE license_type='perpetual' AND commercial_status='issued'")
				->fetchColumn(),
			'expiring' => (int) $this->db
				->execute(
					"SELECT COUNT(*) FROM licenses WHERE commercial_status='issued' AND expires_at>=? AND expires_at<?",
					[$this->clock->sql(), $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s.u')],
				)
				->fetchColumn(),
			'recent' => $this->db->all(
				'SELECT BIN_TO_UUID(l.license_id) AS id_text,l.license_type,l.commercial_status,
						l.expires_at,l.created_at,c.display_name AS customer_name,c.time_zone,
						p.display_name AS product_name
					FROM licenses l
					JOIN customers c ON c.id=l.customer_id
					JOIN products p ON p.product_id=l.product_id
					ORDER BY l.created_at DESC
					LIMIT 6',
			),
			'events' => $this->db->all(
				'SELECT a.operation,a.occurred_at,u.display_name AS actor_name
					FROM audit_events a
					LEFT JOIN admin_users u ON u.id=a.actor_id
					ORDER BY a.sequence DESC
					LIMIT 5',
			),
		];
	}

	public function page(string $kind, array $filters, int $page): array
	{
		$where = ['1=1'];
		$parameters = [];
		$query = trim((string) ($filters['q'] ?? ''));
		if ($kind === 'customers') {
			$from = 'customers c LEFT JOIN customer_contacts t ON t.customer_id=c.id';
			$select = 'c.*,BIN_TO_UUID(c.id) AS id_text,t.name AS contact_name,t.email,t.phone';
			$order = 'c.created_at DESC';
			if ($query !== '') {
				$where[] = '(c.display_name LIKE ? OR t.email LIKE ?)';
				$parameters = ['%' . $query . '%', '%' . $query . '%'];
			}
			if (($filters['state'] ?? '') !== '') {
				$where[] = 'c.state=?';
				$parameters[] = $filters['state'];
			}
		} elseif ($kind === 'licenses') {
			$from = 'licenses l JOIN customers c ON c.id=l.customer_id JOIN products p ON p.product_id=l.product_id';
			$select = 'l.*,BIN_TO_UUID(l.license_id) AS id_text,c.display_name AS customer_name,c.time_zone,
						p.display_name AS product_name';
			$order = 'l.created_at DESC';
			if ($query !== '') {
				$where[] = '(c.display_name LIKE ? OR BIN_TO_UUID(l.license_id) LIKE ?)';
				$parameters = ['%' . $query . '%', '%' . $query . '%'];
			}
			foreach (
				['product_id' => 'l.product_id', 'status' => 'l.commercial_status', 'type' => 'l.license_type']
				as $key => $column
			) {
				if (($filters[$key] ?? '') !== '') {
					$where[] = $column . '=?';
					$parameters[] = $filters[$key];
				}
			}
			if (($filters['expiry'] ?? '') === 'soon') {
				$where[] = 'l.expires_at>=? AND l.expires_at<?';
				$parameters[] = $this->clock->sql();
				$parameters[] = $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s.u');
			}
			if (($filters['expiry'] ?? '') === 'expired') {
				$where[] = 'l.expires_at<=?';
				$parameters[] = $this->clock->sql();
			}
		} elseif ($kind === 'offline') {
			$from = 'offline_requests o
					JOIN license_requests r ON r.product_id=o.product_id AND r.request_id=o.request_id
					LEFT JOIN offline_decisions d ON d.offline_id=o.id
					LEFT JOIN licenses l ON l.license_id=d.license_id
					LEFT JOIN customers c ON c.id=l.customer_id';
			$select = "BIN_TO_UUID(o.id) AS id_text,BIN_TO_UUID(o.request_id) AS request_text,o.product_id,
						o.imported_at,r.action,COALESCE(d.decision,'pending') AS status,c.display_name AS customer_name";
			$order = 'o.imported_at DESC,o.id';
			if ($query !== '') {
				$where[] = '(BIN_TO_UUID(o.request_id) LIKE ? OR c.display_name LIKE ?)';
				$parameters = ['%' . $query . '%', '%' . $query . '%'];
			}
			if (($filters['status'] ?? '') !== '') {
				$where[] = "COALESCE(d.decision,'pending')=?";
				$parameters[] = $filters['status'];
			}
			if (($filters['product_id'] ?? '') !== '') {
				$where[] = 'o.product_id=?';
				$parameters[] = $filters['product_id'];
			}
		} elseif ($kind === 'audit') {
			$from = 'audit_events a LEFT JOIN admin_users u ON u.id=a.actor_id';
			$select = 'a.*,u.display_name AS actor_name';
			$order = 'a.sequence DESC';
			if ($query !== '') {
				$where[] = '(a.operation LIKE ? OR a.target_id LIKE ?)';
				$parameters = ['%' . $query . '%', '%' . $query . '%'];
			}
		} else {
			throw new \InvalidArgumentException('Unknown listing.');
		}
		$clause = implode(' AND ', $where);
		$total = (int) $this->db
			->execute('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $clause, $parameters)
			->fetchColumn();
		$pages = max(1, (int) ceil($total / 20));
		$page = min(max(1, $page), $pages);
		$rows = $this->db->all(
			'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $clause . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
			[...$parameters, 20, ($page - 1) * 20],
		);
		return compact('rows', 'total', 'page', 'pages');
	}

	public function customers(): array
	{
		return $this->db->all(
			'SELECT BIN_TO_UUID(id) AS id_text,display_name
				FROM customers
				WHERE state=\'active\'
				ORDER BY display_name',
		);
	}

	public function customer(string $id): array
	{
		return $this->db->one(
			'SELECT c.*,BIN_TO_UUID(c.id) AS id_text,t.name AS contact_name,t.email,t.phone
				FROM customers c
				LEFT JOIN customer_contacts t ON t.customer_id=c.id
				WHERE c.id=?',
			[Uuid::bytes($id)],
		) ?? throw new Problem('No se encontró el cliente.', 404);
	}

	/**
	 * Catálogo de planes con sus precios opcionales, del más pequeño al más grande. Por omisión solo
	 * los que se ofrecen; con $archived también los archivados, que ya no se ofrecen al emitir.
	 */
	public function plans(bool $archived = false): array
	{
		return $this->db->all(
			'SELECT p.plan_id,p.display_name,p.cash_registers,p.order_takers,p.kitchen_displays,
					p.price_monthly,p.price_quarterly,p.price_semiannual,p.price_annual,p.price_perpetual,
					p.price_maintenance,p.state,p.row_version,
					(SELECT COUNT(*) FROM licenses l WHERE l.plan_id=p.plan_id) AS license_count
				FROM plans p' .
				($archived ? '' : " WHERE p.state='active'") .
				' ORDER BY p.cash_registers,p.order_takers,p.kitchen_displays,p.plan_id',
		);
	}

	public function plan(string $id): array
	{
		return $this->db->one('SELECT * FROM plans WHERE plan_id=?', [$id]) ??
			throw new Problem('No se encontró el plan.', 404);
	}

	public function products(): array
	{
		return $this->db->all(
			'SELECT p.*,(SELECT COUNT(*)
				FROM licenses l
				WHERE l.product_id=p.product_id) AS license_count
				FROM products p
				ORDER BY p.display_name',
		);
	}

	public function product(string $id): array
	{
		return $this->db->one('SELECT * FROM products WHERE product_id=?', [$id]) ??
			throw new Problem('No se encontró el producto.', 404);
	}

	public function capabilities(?string $productId = null): array
	{
		return $this->db->all(
			'SELECT c.*,COALESCE((SELECT GROUP_CONCAT(d.required_key
				ORDER BY d.required_key SEPARATOR \',\')
				FROM capability_dependencies d
				WHERE d.product_id=c.product_id AND d.capability_key=c.capability_key),\'\') AS requires_keys
				FROM product_capabilities c' .
				($productId === null ? '' : ' WHERE c.product_id=?') .
				' ORDER BY c.product_id,c.capability_key',
			$productId === null ? [] : [$productId],
		);
	}

	public function license(string $id, int $revisionPage = 1): array
	{
		$license =
			$this->db->one(
				'SELECT l.*,BIN_TO_UUID(l.license_id) AS id_text,c.display_name AS customer_name,c.time_zone,
						p.display_name AS product_name
					FROM licenses l
					JOIN customers c ON c.id=l.customer_id
					JOIN products p ON p.product_id=l.product_id
					WHERE l.license_id=?',
				[Uuid::bytes($id)],
			) ?? throw new Problem('No se encontró la licencia.', 404);
		$license['features'] = $this->db->all(
			'SELECT f.capability_key,f.enabled,c.display_name
				FROM license_features f
				JOIN product_capabilities c ON c.product_id=f.product_id AND c.capability_key=f.capability_key
				WHERE f.license_id=?
				ORDER BY f.capability_key',
			[Uuid::bytes($id)],
		);
		$license['history'] = $this->db->all(
			'SELECT c.*,u.display_name AS actor_name
				FROM license_changes c
				JOIN admin_users u ON u.id=c.admin_id
				WHERE c.license_id=?
				ORDER BY c.version DESC',
			[Uuid::bytes($id)],
		);
		$license['activations'] = $this->db->all(
			'SELECT BIN_TO_UUID(activation_id) AS id_text,BIN_TO_UUID(server_id) AS server_id_text,
					installation_public_key,app_version,state,activated_at,ended_at
				FROM activations
				WHERE license_id=?
				ORDER BY activated_at DESC
				LIMIT 50',
			[Uuid::bytes($id)],
		);
		foreach ($license['activations'] as &$activation) {
			// La llave pública, en el mismo formato en que la envía la instalación.
			$activation['public_key_text'] = Protocol::encode($activation['installation_public_key']);
			unset($activation['installation_public_key']);
		}
		unset($activation);
		$revisionCount = (int) $this->db
			->execute('SELECT COUNT(*) FROM license_revisions WHERE license_id=?', [Uuid::bytes($id)])
			->fetchColumn();
		$license['revision_pages'] = max(1, (int) ceil($revisionCount / 50));
		$license['revision_page'] = min(max(1, $revisionPage), $license['revision_pages']);
		$license['revisions'] = $this->db->all(
			'SELECT BIN_TO_UUID(revision_id) AS id_text,BIN_TO_UUID(activation_id) AS activation_text,
					license_revision,kid,license_status,status_reason,issued_at
				FROM license_revisions
				WHERE license_id=?
				ORDER BY license_revision DESC
				LIMIT 50 OFFSET ?',
			[Uuid::bytes($id), ($license['revision_page'] - 1) * 50],
		);
		$license['offline'] = $this->db->all(
			"SELECT BIN_TO_UUID(o.id) AS id_text,r.action,COALESCE(d.decision,'pending') AS status,
					o.imported_at
				FROM offline_requests o
				JOIN license_requests r ON r.product_id=o.product_id AND r.request_id=o.request_id
				LEFT JOIN offline_decisions d ON d.offline_id=o.id
				WHERE d.license_id=? OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(o.projection_json,
					'$.license_id')))=?
				ORDER BY o.imported_at DESC
				LIMIT 50",
			[Uuid::bytes($id), strtolower($id)],
		);
		$license['transfers'] = $this->db->all(
			'SELECT BIN_TO_UUID(t.id) AS id_text,BIN_TO_UUID(t.outgoing_activation_id) AS outgoing_text,
					BIN_TO_UUID(t.incoming_activation_id) AS incoming_text,BIN_TO_UUID(o.server_id) AS outgoing_server,
					BIN_TO_UUID(i.server_id) AS incoming_server,t.via,t.state,t.confirmation,t.confirmed_at,t.reason,
					t.release_reason,t.created_at,u.display_name AS actor_name,r.display_name AS releaser_name
				FROM license_transfers t
				JOIN activations o ON o.activation_id=t.outgoing_activation_id
				LEFT JOIN activations i ON i.activation_id=t.incoming_activation_id
				LEFT JOIN admin_users u ON u.id=t.admin_id
				LEFT JOIN admin_users r ON r.id=t.released_by
				WHERE t.license_id=?
				ORDER BY t.created_at DESC
				LIMIT 50',
			[Uuid::bytes($id)],
		);
		$license['administrative_history'] = $this->db->all(
			'SELECT h.field_name,h.old_value,h.new_value,h.version,h.created_at,u.display_name AS actor_name
				FROM license_administrative_changes h
				JOIN admin_users u ON u.id=h.admin_id
				WHERE h.license_id=?
				ORDER BY h.version DESC,h.field_name',
			[Uuid::bytes($id)],
		);
		// Cambio de plan programado, si lo hay, y los anteriores (aplicados, cancelados o sustituidos).
		$license['scheduled_changes'] = $this->db->all(
			'SELECT BIN_TO_UUID(s.change_id) AS id_text,s.effective_at,s.plan_id,p.display_name AS plan_name,
					s.plan_custom,s.cash_registers,s.order_takers,s.kitchen_displays,s.state,s.reason,s.created_at,
					s.resolved_at,u.display_name AS actor_name
				FROM license_scheduled_changes s
				JOIN plans p ON p.plan_id=s.plan_id
				JOIN admin_users u ON u.id=s.admin_id
				WHERE s.license_id=?
				ORDER BY s.created_at DESC
				LIMIT 20',
			[Uuid::bytes($id)],
		);
		$license['scheduled'] = null;
		foreach ($license['scheduled_changes'] as $change) {
			if ($change['state'] === 'pending') {
				$license['scheduled'] = $change;
			}
		}
		// Historial comercial: cada pago tal como se registró, del más reciente al más antiguo.
		$license['payments'] = $this->db->all(
			'SELECT BIN_TO_UUID(p.payment_id) AS id_text,p.concept,p.term,p.plan_id,p.paid_at,p.currency,
					p.amount,p.catalog_amount,p.credit_amount,p.suggested_amount,p.override_reason,p.reference,
					p.covers_from,p.covers_until,p.created_at,u.display_name AS actor_name
				FROM license_payments p
				JOIN admin_users u ON u.id=p.admin_id
				WHERE p.license_id=?
				ORDER BY p.paid_at DESC,p.created_at DESC
				LIMIT 100',
			[Uuid::bytes($id)],
		);
		$license['documents'] = $this->db->all(
			'SELECT BIN_TO_UUID(id) AS id_text,document_type,revision,folio,pdf_sha256,generated_at,
					credential_id IS NOT NULL AS contains_code,offline_revision_id IS NOT NULL AS contains_offline,
					BIN_TO_UUID(offline_revision_id) AS offline_revision_text
				FROM license_documents
				WHERE license_id=?
				ORDER BY generated_at DESC,revision DESC',
			[Uuid::bytes($id)],
		);
		return $license;
	}

	public function offline(string $id): array
	{
		$row =
			$this->db->one(
				"SELECT BIN_TO_UUID(o.id) AS id_text,o.product_id,o.projection_json,o.imported_at,
						u.display_name AS importer_name,COALESCE(d.decision,'pending') AS status,
						BIN_TO_UUID(d.license_id) AS license_text,BIN_TO_UUID(d.revision_id) AS revision_text,
						d.reason,d.decided_at,a.display_name AS decider_name
					FROM offline_requests o
					JOIN admin_users u ON u.id=o.imported_by
					LEFT JOIN offline_decisions d ON d.offline_id=o.id
					LEFT JOIN admin_users a ON a.id=d.admin_id
					WHERE o.id=?",
				[Uuid::bytes($id)],
			) ?? throw new Problem('No se encontró la solicitud.', 404);
		$row['payload'] = json_decode($row['projection_json'], true, 16, JSON_THROW_ON_ERROR);
		unset($row['projection_json']);
		return $row;
	}

	public function offlineCandidates(string $productId, string $query = ''): array
	{
		return $this->db->all(
			"SELECT BIN_TO_UUID(l.license_id) AS id_text,l.license_type,l.expires_at,c.time_zone,
					c.display_name AS customer_name
				FROM licenses l
				JOIN customers c ON c.id=l.customer_id
				WHERE l.product_id=? AND l.commercial_status='issued' AND (c.display_name LIKE ?
					OR BIN_TO_UUID(l.license_id) LIKE ?)
				ORDER BY c.display_name,l.license_id
				LIMIT 100",
			[$productId, '%' . $query . '%', '%' . $query . '%'],
		);
	}

	public function revision(string $licenseId, string $revisionId): array
	{
		return $this->db->one(
			'SELECT license_jws,license_revision FROM license_revisions WHERE license_id=? AND revision_id=?',
			[Uuid::bytes($licenseId), Uuid::bytes($revisionId)],
		) ?? throw new Problem('No se encontró la revisión.', 404);
	}

	public function users(): array
	{
		$users = $this->db->all(
			'SELECT BIN_TO_UUID(id) AS id_text,login,display_name,role,state,mfa_secret_encrypted IS NOT NULL AS has_mfa,created_at
				FROM admin_users
				ORDER BY created_at',
		);
		foreach ($users as &$user) {
			$user['permissions'] = array_column(
				$this->db->all('SELECT permission FROM admin_permission_grants WHERE admin_id=?', [
					Uuid::bytes($user['id_text']),
				]),
				'permission',
			);
		}
		unset($user);
		return $users;
	}
}
