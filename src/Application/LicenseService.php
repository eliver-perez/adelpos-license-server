<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{ActivationCode, Capabilities, Input, LicenseContent, LicenseTerms, Problem, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Crypto, Database};

final class LicenseService
{
	// PENDIENTE etapa 3: renovación con fecha de pago y regla de tolerancia, conversión de prueba a pago,
	// compra de mantenimiento, edición del periodo inicial de una suscripción sin activar, y editor de
	// planes con su propagación a las licencias existentes (contrato, secciones 5.2 a 5.5 y 11).
	public const PENDING = [
		'renew' => 'La renovación de suscripciones estará disponible próximamente.',
		'maintenance' => 'La compra de mantenimiento estará disponible próximamente.',
	];

	public function __construct(
		private Database $db,
		private Operations $operations,
		private Audit $audit,
		private Clock $clock,
		private Crypto $crypto,
		private RevisionPublisher $publisher,
		private Payments $payments,
	) {}

	/**
	 * Emite una licencia con las condiciones del contrato. No fija fechas: el primer periodo
	 * empieza al activar en línea o al aprobar la solicitud sin internet (contrato, sección 5.2).
	 *
	 * Registra en la misma transacción el pago de alta: el monto cobrado, el precio del catálogo
	 * que se sugirió y el motivo si el monto es otro. Una prueba no tiene costo.
	 */
	public function issue(Actor $actor, string $operationId, array $input): array
	{
		$customerId = Input::text($input, 'customer_id');
		$productId = Input::text($input, 'product_id', 64);
		$terms = $this->termsInput($input);
		$planId = Input::text($input, 'plan_id', 64, false);
		if ($planId === '') {
			throw new Problem('Elige el plan de la licencia.');
		}
		$limits = [];
		foreach (LicenseTerms::LIMITS as $key => $label) {
			// Vacío: el cupo del plan. Un número distinto al del plan deja la licencia personalizada.
			$limits[$key] =
				($input[$key] ?? '') === '' ? null : Input::integer($input, $key, 0, LicenseTerms::MAX_LIMIT, $label);
		}
		$features = $this->featureInput($input);
		$reason = Input::text($input, 'reason', 500, false);
		$reference = Input::text($input, 'reference', 190, false);
		$payment = Payments::input($input);
		if ($payment['amount'] === null && $terms['license_type'] !== 'trial') {
			throw new Problem('Escribe el monto cobrado.');
		}
		$data = [$customerId, $productId, $terms, $planId, $limits, $features, $reason, $reference, $payment];
		return $this->operations->run(
			$actor,
			['superadmin', 'operator'],
			$operationId,
			'license.issue',
			$data,
			function () use (
				$actor,
				$operationId,
				$customerId,
				$productId,
				$terms,
				$planId,
				$limits,
				$features,
				$reason,
				$reference,
				$payment,
			): array {
				$customer = $this->db->one('SELECT * FROM customers WHERE id=? FOR SHARE', [Uuid::bytes($customerId)]);
				$product = $this->db->one('SELECT * FROM products WHERE product_id=? FOR SHARE', [$productId]);
				if (!$customer || $customer['state'] !== 'active' || !$product || $product['state'] !== 'active') {
					throw new Problem('Selecciona un cliente y un producto activos.');
				}
				// El contrato fija el producto de la licencia firmada: una licencia de otro no podría activarse.
				if ($productId !== LicenseContent::PRODUCT_ID) {
					throw new Problem('Este servidor solo emite licencias de AD-EL POS.');
				}
				$plan = $this->db->one('SELECT * FROM plans WHERE plan_id=? FOR SHARE', [$planId]);
				if (!$plan) {
					throw new Problem('El plan elegido no existe en el catálogo.');
				}
				$custom = false;
				foreach (array_keys(LicenseTerms::LIMITS) as $key) {
					$limits[$key] ??= (int) $plan[$key];
					$custom = $custom || $limits[$key] !== (int) $plan[$key];
				}
				$catalog = $this->validateFeatures($productId, $features);
				$paidAt = $this->payments->paidAt($payment['paid_at'], $customer['time_zone'], null);
				$id = Uuid::create();
				$bytes = Uuid::bytes($id);
				$this->db->execute(
					'INSERT INTO licenses(license_id,customer_id,product_id,license_type,billing_period,
							trial_duration,updates_months,grace_days,plan_id,plan_name,plan_custom,
							cash_registers,order_takers,kitchen_displays,commercial_reference,
							authorization_note,created_at,updated_at)
						VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
					[
						$bytes,
						Uuid::bytes($customerId),
						$productId,
						$terms['license_type'],
						$terms['billing_period'],
						$terms['trial_duration'],
						$terms['updates_months'],
						LicenseTerms::graceDays($terms['license_type']),
						$plan['plan_id'],
						$plan['display_name'],
						$custom ? 1 : 0,
						$limits['cash_registers'],
						$limits['order_takers'],
						$limits['kitchen_displays'],
						$reference,
						$reason,
						$this->clock->sql(),
						$this->clock->sql(),
					],
				);
				$this->writeFeatures($bytes, $productId, $catalog, $features);
				// El pago de alta paga el primer periodo, cuyas fechas se fijan al activar.
				$term = LicenseTerms::term($terms);
				$paymentId = $this->payments->record($actor, $operationId, $bytes, [
					'concept' => 'issue',
					'term' => $term,
					'plan_id' => $plan['plan_id'],
					'paid_at' => $paidAt,
					'amount' => $payment['amount'] ?? 0,
					// Una licencia personalizada no tiene precio en el catálogo: su monto se escribe a mano.
					'catalog' => $custom && $term !== 'trial' ? null : LicenseTerms::catalogPrice($plan, $term),
					'credit' => 0,
					'reason' => $payment['reason'],
					'reference' => $reference,
					'covers' => null,
				]);
				$this->db->execute('UPDATE licenses SET period_payment_id=? WHERE license_id=?', [$paymentId, $bytes]);
				$secret = $this->credential($bytes);
				$this->record($actor, $id, 'license.issued', $reason, $reference);
				return $this->credentialResult($actor, $id, $secret);
			},
		);
	}

	/** Tipo de licencia con su único dato propio: periodo, duración de la prueba o meses de actualizaciones. */
	private function termsInput(array $input): array
	{
		$type = Input::choice($input, 'license_type', LicenseTerms::TYPES);
		$sent = [];
		foreach (['billing_period', 'trial_duration', 'updates_months'] as $field) {
			$value = $input[$field] ?? '';
			if (!is_string($value) && !is_int($value)) {
				throw new Problem('Revisa las condiciones de la licencia.');
			}
			$sent[$field] = trim((string) $value);
		}
		$terms = [
			'license_type' => $type,
			'billing_period' => null,
			'trial_duration' => null,
			'updates_months' => null,
		];
		if ($type === 'subscription') {
			if (!in_array($sent['billing_period'], LicenseTerms::BILLING_PERIODS, true)) {
				throw new Problem('Elige el periodo de la suscripción: mensual, trimestral, semestral o anual.');
			}
			$terms['billing_period'] = $sent['billing_period'];
		} elseif ($type === 'trial') {
			if (!in_array($sent['trial_duration'], LicenseTerms::TRIAL_DURATIONS, true)) {
				throw new Problem('Elige la duración de la prueba: 15 días, 1 mes o 3 meses.');
			}
			$terms['trial_duration'] = $sent['trial_duration'];
		} else {
			$months =
				$sent['updates_months'] === ''
					? (string) LicenseTerms::DEFAULT_UPDATES_MONTHS
					: $sent['updates_months'];
			if (!in_array($months, array_map('strval', LicenseTerms::UPDATES_MONTHS), true)) {
				throw new Problem('Elige los meses de actualizaciones de la licencia perpetua: 0, 6 o 12.');
			}
			$terms['updates_months'] = (int) $months;
		}
		// Un dato de otro tipo de licencia nunca se ignora en silencio.
		if ($type !== 'subscription' && $sent['billing_period'] !== '') {
			throw new Problem('El periodo de cobro solo aplica a las suscripciones.');
		}
		if ($type !== 'trial' && $sent['trial_duration'] !== '') {
			throw new Problem('La duración de prueba solo aplica a las licencias de prueba.');
		}
		if ($type !== 'perpetual' && $sent['updates_months'] !== '') {
			throw new Problem('Los meses de actualizaciones solo aplican a las licencias perpetuas.');
		}
		return $terms;
	}

	public function change(Actor $actor, string $operationId, string $id, string $action, array $input): array
	{
		// Estas dos operaciones del motor anterior no siguen funcionando con sus reglas viejas: se rechazan siempre.
		if (isset(self::PENDING[$action])) {
			throw new Problem(self::PENDING[$action]);
		}
		if (!in_array($action, ['features', 'reissue', 'revoke'], true)) {
			throw new Problem('La operación no existe.', 404);
		}
		$reason = Input::text($input, 'reason', 500);
		$reference = Input::text($input, 'reference', 190, false);
		$features = $action === 'features' ? $this->featureInput($input) : [];
		$version = (int) ($input['version'] ?? 0);
		$roles = $action === 'revoke' ? ['superadmin'] : ['superadmin', 'operator'];
		return $this->operations->run(
			$actor,
			$roles,
			$operationId,
			'license.' . $action,
			[$id, $reason, $reference, $features, $version],
			function () use ($actor, $id, $action, $reason, $reference, $features, $version): array {
				$bytes = Uuid::bytes($id);
				// Product lock precedes license locks, as in catalog dependency changes.
				$productId = $this->db
					->execute('SELECT product_id FROM licenses WHERE license_id=?', [$bytes])
					->fetchColumn();
				if ($productId === false) {
					throw new Problem('No se encontró la licencia.', 404);
				}
				$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$productId]);
				$license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$bytes]);
				if ((int) $license['row_version'] !== $version) {
					throw new Problem('Otro operador cambió la licencia. Recarga su detalle.', 409);
				}
				if ($license['commercial_status'] === 'revoked') {
					throw new Problem('La licencia está revocada.');
				}
				if (
					(int) $license['revision_counter'] !== 0 &&
					!$this->db->one('SELECT revision_id FROM license_revisions WHERE license_id=? LIMIT 1', [$bytes])
				) {
					throw new Problem(
						'El historial firmado está incompleto. Requiere recuperación administrativa.',
						409,
					);
				}
				$result = ['id' => $id];
				$rightsChanged = $action !== 'reissue';
				if ($action === 'features') {
					$catalog = $this->validateFeatures($productId, $features);
					$previous = array_column(
						$this->db->all(
							'SELECT capability_key
								FROM license_features
								WHERE license_id=? AND enabled=1
								ORDER BY capability_key',
							[$bytes],
						),
						'capability_key',
					);
					$rightsChanged = $previous !== $features;
					$this->writeFeatures($bytes, $productId, $catalog, $features);
				} elseif ($action === 'reissue') {
					$this->db->execute(
						"UPDATE license_credentials
							SET state='retired',retired_at=?
							WHERE license_id=? AND state='active'",
						[$this->clock->sql(), $bytes],
					);
					$result = $this->credentialResult($actor, $id, $this->credential($bytes));
				} elseif ($action === 'revoke') {
					$this->db->execute("UPDATE licenses SET commercial_status='revoked' WHERE license_id=?", [$bytes]);
					$this->db->execute(
						"UPDATE license_credentials
							SET state='retired',retired_at=?
							WHERE license_id=? AND state='active'",
						[$this->clock->sql(), $bytes],
					);
				}
				$this->db->execute('UPDATE licenses SET row_version=row_version+1,updated_at=? WHERE license_id=?', [
					$this->clock->sql(),
					$bytes,
				]);
				if ($rightsChanged) {
					$this->publisher->commercialChange($bytes, $action === 'revoke');
				}
				$this->record($actor, $id, 'license.' . $action, $reason, $reference);
				return $result;
			},
		);
	}

	private function featureInput(array $input): array
	{
		$features = $input['features'] ?? [];
		if (!is_array($features) || count($features) > 100) {
			throw new Problem('La selección de módulos no es válida.');
		}
		foreach ($features as $feature) {
			if (!is_string($feature)) {
				throw new Problem('La selección de módulos no es válida.');
			}
		}
		$features = array_values(array_unique($features));
		sort($features);
		return $features;
	}

	private function validateFeatures(string $productId, array $features): array
	{
		$catalog = array_column(
			$this->db->all(
				'SELECT capability_key FROM product_capabilities WHERE product_id=? ORDER BY capability_key',
				[$productId],
			),
			'capability_key',
		);
		Capabilities::validate(
			$features,
			$catalog,
			$this->db->all('SELECT capability_key,required_key FROM capability_dependencies WHERE product_id=?', [
				$productId,
			]),
		);
		return $catalog;
	}

	private function writeFeatures(string $licenseId, string $productId, array $catalog, array $enabled): void
	{
		foreach ($catalog as $feature) {
			$value = in_array($feature, $enabled, true) ? 1 : 0;
			$this->db->execute(
				'INSERT INTO license_features(license_id,product_id,capability_key,enabled)
					VALUES (?,?,?,?)
					ON DUPLICATE KEY UPDATE enabled=?',
				[$licenseId, $productId, $feature, $value, $value],
			);
		}
	}

	private function credential(string $licenseId): string
	{
		for ($attempt = 0; $attempt < 8; ++$attempt) {
			$key = ActivationCode::generate();
			$id = Uuid::create();
			$digest = $this->crypto->digest('CREDENTIAL_KEY', $key);
			if (
				$this->db->one(
					'SELECT credential_id FROM license_credentials WHERE key_digest=? AND digest_key_version=1',
					[$digest],
				)
			) {
				continue;
			}
			try {
				$encrypted = $this->crypto->seal($key, 'credential:' . $id, 'activation-code');
				$this->db->execute(
					"INSERT INTO license_credentials(credential_id,license_id,key_digest,digest_key_version,
							state,created_at,credential_encrypted,code_format)
						VALUES (?,?,?,1,'active',?,?,'3-4-4-4')",
					[Uuid::bytes($id), $licenseId, $digest, $this->clock->sql(), $encrypted],
				);
				return $key;
			} catch (\PDOException $error) {
				if (
					(int) ($error->errorInfo[1] ?? 0) !== 1062 ||
					!$this->db->one(
						'SELECT credential_id FROM license_credentials WHERE key_digest=? AND digest_key_version=1',
						[$digest],
					)
				) {
					throw $error;
				}
			}
		}
		throw new \RuntimeException('Activation code collision retry exhausted.');
	}

	private function credentialResult(Actor $actor, string $id, string $secret): array
	{
		if (!(new Access($this->db))->actor($actor->id)->allows('credentials.reveal')) {
			return ['id' => $id];
		}
		$this->audit->append($actor->id, 'credential.disclosed_on_issue', 'license', $id);
		return ['id' => $id, 'secret' => $secret];
	}

	public function administrative(Actor $actor, string $operationId, string $id, array $input): array
	{
		$reference = Input::text($input, 'reference', 190, false);
		$note = Input::text($input, 'reason', 500, false);
		$version = (int) ($input['administrative_version'] ?? 0);
		return $this->operations->run(
			$actor,
			['superadmin', 'operator'],
			$operationId,
			'license.administrative',
			[$id, $reference, $note, $version],
			function () use ($actor, $id, $reference, $note, $version): array {
				$license =
					$this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [Uuid::bytes($id)]) ??
					throw new Problem('No se encontró la licencia.', 404);
				if ((int) $license['administrative_version'] !== $version) {
					throw new Problem(
						'Otro operador actualizó la información administrativa. Recarga el detalle.',
						409,
					);
				}
				$changes = [];
				foreach (['commercial_reference' => $reference, 'authorization_note' => $note] as $field => $value) {
					if ($license[$field] === $value) {
						continue;
					}
					$changes[$field] = ['before' => $license[$field], 'after' => $value];
					$this->db->execute(
						'INSERT INTO license_administrative_changes(id,license_id,version,
								field_name,old_value,new_value,admin_id,created_at)
							VALUES (?,?,?,?,?,?,?,?)',
						[
							Uuid::bytes(Uuid::create()),
							$license['license_id'],
							$version + 1,
							$field,
							$license[$field],
							$value,
							Uuid::bytes($actor->id),
							$this->clock->sql(),
						],
					);
				}
				if ($changes) {
					$this->db->execute(
						'UPDATE licenses
							SET commercial_reference=?,authorization_note=?,administrative_version=administrative_version+1
							WHERE license_id=?',
						[$reference, $note, $license['license_id']],
					);
					$this->audit->append($actor->id, 'license.administrative_updated', 'license', $id, [
						'changes' => $changes,
						'version' => $version + 1,
					]);
				}
				return ['id' => $id];
			},
		);
	}

	private function record(Actor $actor, string $id, string $action, string $reason, string $reference): void
	{
		$license = $this->db->one(
			'SELECT product_id,license_type,billing_period,trial_duration,updates_months,commercial_status,
					grace_days,plan_id,plan_name,plan_custom,cash_registers,order_takers,kitchen_displays,
					period_starts_at,expires_at,updates_until,row_version
				FROM licenses
				WHERE license_id=?',
			[Uuid::bytes($id)],
		);
		$license['features'] = $this->db->all(
			'SELECT capability_key,enabled FROM license_features WHERE license_id=? ORDER BY capability_key',
			[Uuid::bytes($id)],
		);
		$license['commercial_reference'] = $reference;
		$this->db->execute(
			'INSERT INTO license_changes(id,license_id,version,action,admin_id,snapshot_json,
					reason,created_at)
				VALUES (?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes(Uuid::create()),
				Uuid::bytes($id),
				(int) $license['row_version'],
				$action,
				Uuid::bytes($actor->id),
				json_encode($license, JSON_THROW_ON_ERROR),
				$reason,
				$this->clock->sql(),
			],
		);
		$this->audit->append(
			$actor->id,
			$action,
			'license',
			$id,
			['version' => (int) $license['row_version']],
			$reason,
		);
	}
}
