<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Contenido de la licencia firmada (contrato V1.0, sección 4): sus campos, su orden y las
 * reglas que debe cumplir. Nada se firma sin pasar antes por assertValid().
 */
final class LicenseContent
{
	public const SCHEMA_VERSION = '1.0';

	/** El contrato fija el producto: este servidor no firma licencias de otro. */
	public const PRODUCT_ID = 'adel_pos';

	/** Valor de «typ» en el encabezado del JWS. */
	public const JWS_TYPE = 'adelpos-lic+jws';

	public const ENVIRONMENTS = ['test', 'production'];

	public const STATUS_REASONS = ['transferred', 'deactivated', 'revoked'];

	/** Campos del contenido, en el orden del ejemplo del contrato. */
	public const FIELDS = [
		'schema_version',
		'product_id',
		'environment',
		'license_id',
		'activation_id',
		'server_id',
		'installation_public_key',
		'licensee_name',
		'license_type',
		'billing_period',
		'license_status',
		'status_reason',
		'license_revision',
		'issued_at',
		'period_starts_at',
		'expires_at',
		'grace_days',
		'updates_until',
		'plan',
		'limits',
		'features',
	];

	private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';
	private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
	private const INSTANT = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.\d{6}Z\z/';
	private const KEY = '/\A[a-z][a-z0-9_]{1,63}\z/';

	/**
	 * Comprueba el contenido completo. Si algo no cumple, lanza una excepción que nombra el
	 * campo (nunca su valor) y el llamador revierte la transacción sin firmar.
	 */
	public static function assertValid(array $content): void
	{
		if (array_keys($content) !== self::FIELDS) {
			self::fail('campos');
		}
		self::check($content['schema_version'] === self::SCHEMA_VERSION, 'schema_version');
		self::check($content['product_id'] === self::PRODUCT_ID, 'product_id');
		self::check(in_array($content['environment'], self::ENVIRONMENTS, true), 'environment');
		self::check(self::matches(self::UUID, $content['license_id']), 'license_id');
		self::check(self::matches(self::UUID, $content['activation_id']), 'activation_id');
		self::check(self::matches(self::UUID_V4, $content['server_id']), 'server_id');
		self::check(self::publicKey($content['installation_public_key']), 'installation_public_key');
		self::check(self::text($content['licensee_name'], 190), 'licensee_name');

		$type = $content['license_type'];
		self::check(in_array($type, LicenseTerms::TYPES, true), 'license_type');
		self::check(
			$type === 'subscription'
				? in_array($content['billing_period'], LicenseTerms::BILLING_PERIODS, true)
				: $content['billing_period'] === null,
			'billing_period',
		);

		self::check(in_array($content['license_status'], ['active', 'revoked'], true), 'license_status');
		self::check(
			$content['license_status'] === 'active'
				? $content['status_reason'] === null
				: in_array($content['status_reason'], self::STATUS_REASONS, true),
			'status_reason',
		);
		self::check(is_int($content['license_revision']) && $content['license_revision'] >= 1, 'license_revision');

		self::check(self::instant($content['issued_at']), 'issued_at');
		self::check(self::instant($content['period_starts_at']), 'period_starts_at');
		self::check(self::instant($content['updates_until']), 'updates_until');
		if ($type === 'perpetual') {
			// La perpetua no vence. Sus actualizaciones nunca terminan antes de la activación.
			self::check($content['expires_at'] === null, 'expires_at');
			self::check($content['updates_until'] >= $content['period_starts_at'], 'updates_until');
		} else {
			// Las fechas tienen formato fijo en UTC: compararlas como texto es comparar los instantes.
			self::check(
				self::instant($content['expires_at']) && $content['expires_at'] > $content['period_starts_at'],
				'expires_at',
			);
			self::check($content['updates_until'] === $content['expires_at'], 'updates_until');
		}
		self::check($content['grace_days'] === LicenseTerms::graceDays($type), 'grace_days');

		$plan = $content['plan'];
		self::check(is_array($plan) && array_keys($plan) === ['plan_id', 'name', 'custom'], 'plan');
		self::check(self::matches(self::KEY, $plan['plan_id']), 'plan.plan_id');
		self::check(self::text($plan['name'], 190), 'plan.name');
		self::check(is_bool($plan['custom']), 'plan.custom');

		$limits = $content['limits'];
		self::check(is_array($limits) && array_keys($limits) === array_keys(LicenseTerms::LIMITS), 'limits');
		foreach ($limits as $name => $value) {
			self::check(is_int($value) && $value >= 0 && $value <= LicenseTerms::MAX_LIMIT, 'limits.' . $name);
		}

		// «features» siempre es un objeto JSON, aunque esté vacío: nunca una lista.
		self::check($content['features'] instanceof \stdClass, 'features');
		foreach (get_object_vars($content['features']) as $key => $enabled) {
			self::check(self::matches(self::KEY, (string) $key) && $enabled === true, 'features');
		}
	}

	private static function check(bool $valid, string $field): void
	{
		if (!$valid) {
			self::fail($field);
		}
	}

	private static function fail(string $field): never
	{
		throw new \UnexpectedValueException('El contenido de la licencia no cumple el contrato: ' . $field . '.');
	}

	private static function matches(string $pattern, mixed $value): bool
	{
		return is_string($value) && preg_match($pattern, $value) === 1;
	}

	private static function text(mixed $value, int $maximum): bool
	{
		return is_string($value) &&
			trim($value) !== '' &&
			mb_check_encoding($value, 'UTF-8') &&
			mb_strlen($value) <= $maximum &&
			preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
	}

	private static function publicKey(mixed $value): bool
	{
		if (!self::matches('/\A[A-Za-z0-9_-]{43}\z/', $value)) {
			return false;
		}
		try {
			$bytes = sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		} catch (\SodiumException) {
			return false;
		}
		return strlen($bytes) === 32 && Protocol::encode($bytes) === $value;
	}

	private static function instant(mixed $value): bool
	{
		if (!is_string($value) || preg_match(self::INSTANT, $value, $parts) !== 1) {
			return false;
		}
		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) &&
			(int) $parts[4] <= 23 &&
			(int) $parts[5] <= 59 &&
			(int) $parts[6] <= 59;
	}
}
