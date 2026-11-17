<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{Capabilities, LicenseContent, LicenseTerms, Protocol, Uuid};
use AdelPos\License\Infrastructure\{Clock, Database, SigningKeys};

/** Firma las revisiones de una licencia con el contenido exacto del contrato V1.0 (sección 4). */
final class RevisionPublisher
{
	public function __construct(private Database $db, private Clock $clock, private SigningKeys $keys) {}

	/**
	 * Firma una revisión nueva para la activación y la deja como su revisión vigente.
	 * Quien llama ya tiene los bloqueos del producto, la licencia y la activación, en ese orden.
	 * Si el contenido no cumple el contrato no se firma: la excepción revierte la transacción.
	 */
	public function publish(string $licenseId, string $activationId): array
	{
		if (!$this->db->pdo->inTransaction()) {
			throw new \LogicException('Publication requires a transaction.');
		}
		$license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$licenseId]);
		$activation = $this->db->one('SELECT * FROM activations WHERE activation_id=? AND license_id=? FOR UPDATE', [
			$activationId,
			$licenseId,
		]);
		if (!$license || !$activation || (int) $license['revision_counter'] >= PHP_INT_MAX - 1) {
			throw new \RuntimeException('Invalid publication state.');
		}
		// El nombre firmado del plan se actualiza cada vez que se firma, por el motivo que sea. Cambiar
		// el nombre en el catálogo no firma por sí solo (etapa 3, Fase 2).
		$catalogName = $this->db
			->execute('SELECT display_name FROM plans WHERE plan_id=?', [$license['plan_id']])
			->fetchColumn();
		if (is_string($catalogName) && $catalogName !== $license['plan_name']) {
			$this->db->execute('UPDATE licenses SET plan_name=? WHERE license_id=?', [$catalogName, $licenseId]);
			$license['plan_name'] = $catalogName;
		}
		// El contador es global por licencia: cada firma lo aumenta y ningún número se reutiliza.
		$revision = (int) $license['revision_counter'] + 1;
		$now = $this->clock->sql();
		$content = $this->content($license, $activation, $revision, $now);
		LicenseContent::assertValid($content);
		$signed = $this->keys->sign($content);
		if ($signed['environment'] !== $content['environment']) {
			throw new \RuntimeException('Signing key environment does not match the license environment.');
		}
		$revisionId = Uuid::bytes(Uuid::create());
		$this->db->execute(
			'INSERT INTO license_revisions(revision_id,license_id,activation_id,license_revision,
					kid,environment,license_status,status_reason,issued_at,payload_json,license_jws,jws_sha256)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
			[
				$revisionId,
				$licenseId,
				$activationId,
				$revision,
				$signed['kid'],
				$signed['environment'],
				$content['license_status'],
				$content['status_reason'],
				$now,
				Protocol::json($content),
				$signed['jws'],
				hash('sha256', $signed['jws']),
			],
		);
		$this->db->execute('UPDATE licenses SET revision_counter=? WHERE license_id=?', [$revision, $licenseId]);
		$this->db->execute('UPDATE activations SET current_revision_id=? WHERE activation_id=?', [
			$revisionId,
			$activationId,
		]);
		return ['revision' => $revision, 'revision_id' => Uuid::text($revisionId), 'jws' => $signed['jws']];
	}

	/**
	 * Vuelve a firmar las activaciones vigentes después de un cambio comercial o de una revocación.
	 * Devuelve cuántas revisiones firmó: una licencia sin activar no firma ninguna.
	 */
	public function commercialChange(string $licenseId, bool $revoke): int
	{
		$activations = $this->db->all(
			"SELECT activation_id
				FROM activations
				WHERE license_id=? AND state='active'
				ORDER BY activation_id FOR UPDATE",
			[$licenseId],
		);
		foreach ($activations as $activation) {
			if ($revoke) {
				$this->db->execute("UPDATE activations SET state='revoked',ended_at=? WHERE activation_id=?", [
					$this->clock->sql(),
					$activation['activation_id'],
				]);
			}
			$this->publish($licenseId, $activation['activation_id']);
		}
		// Una activación terminada conserva para siempre su revisión de cierre: un cambio
		// posterior nunca vuelve a autorizar a una computadora que ya salió.
		return count($activations);
	}

	/** Contenido de la licencia, con los campos en el orden del contrato. */
	private function content(array $license, array $activation, int $revision, string $now): array
	{
		$customer = $this->db->one('SELECT display_name,legal_name FROM customers WHERE id=?', [
			$license['customer_id'],
		]);
		if (!$customer) {
			throw new \RuntimeException('Invalid publication state.');
		}
		// Razón social si está capturada; si no, el nombre comercial.
		$legalName = trim((string) $customer['legal_name']);
		$active = $license['commercial_status'] === 'issued' && $activation['state'] === 'active';
		$limits = [];
		foreach (array_keys(LicenseTerms::LIMITS) as $name) {
			$limits[$name] = (int) $license[$name];
		}
		return [
			'schema_version' => LicenseContent::SCHEMA_VERSION,
			'product_id' => $license['product_id'],
			'environment' => $this->keys->environment(),
			'license_id' => Uuid::text($license['license_id']),
			'activation_id' => Uuid::text($activation['activation_id']),
			'server_id' => Uuid::text($activation['server_id']),
			'installation_public_key' => Protocol::encode($activation['installation_public_key']),
			'licensee_name' => $legalName !== '' ? $legalName : $customer['display_name'],
			'license_type' => $license['license_type'],
			'billing_period' => $license['billing_period'],
			'license_status' => $active ? 'active' : 'revoked',
			'status_reason' => $active ? null : $this->closingReason($activation),
			'license_revision' => $revision,
			'issued_at' => Protocol::utc($now),
			'period_starts_at' => Protocol::utc($license['period_starts_at']),
			'expires_at' => Protocol::utc($license['expires_at']),
			'grace_days' => (int) $license['grace_days'],
			'updates_until' => Protocol::utc($license['updates_until']),
			'plan' => [
				'plan_id' => $license['plan_id'],
				'name' => $license['plan_name'],
				'custom' => (bool) $license['plan_custom'],
			],
			'limits' => $limits,
			'features' => (object) $this->features($license),
		];
	}

	/**
	 * Motivo de cierre: «deactivated» si la licencia se liberó, «transferred» si el propietario
	 * la retiró de esa computadora desde el panel y «revoked» si revocó la licencia.
	 */
	private function closingReason(array $activation): string
	{
		return $activation['state'] === 'active' ? 'revoked' : $activation['state'];
	}

	/** Módulos habilitados, como «clave → true». Los que no están habilitados no se firman. */
	private function features(array $license): array
	{
		$enabled = array_column(
			$this->db->all(
				'SELECT capability_key
					FROM license_features
					WHERE license_id=? AND enabled=1
					ORDER BY capability_key',
				[$license['license_id']],
			),
			'capability_key',
		);
		$catalog = array_column(
			$this->db->all('SELECT capability_key FROM product_capabilities WHERE product_id=?', [
				$license['product_id'],
			]),
			'capability_key',
		);
		Capabilities::validate(
			$enabled,
			$catalog,
			$this->db->all('SELECT capability_key,required_key FROM capability_dependencies WHERE product_id=?', [
				$license['product_id'],
			]),
		);
		return array_fill_keys($enabled, true);
	}
}
