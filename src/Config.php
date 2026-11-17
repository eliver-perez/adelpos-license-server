<?php
declare(strict_types=1);
namespace AdelPos\License;

use AdelPos\License\Domain\{LicenseContent, Problem};

final class Config
{
	/** Entornos de firma del contrato (§3). Son independientes de APP_ENV. */
	public const LICENSE_ENVIRONMENTS = LicenseContent::ENVIRONMENTS;

	public function __construct(private array $values = [], private bool $environmentOverrides = true) {}

	public static function load(): self
	{
		$path = getenv('ADELPOS_LICENSE_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
		return new self(is_file($path) ? require $path : []);
	}

	public function get(string $name, string $default = ''): string
	{
		$environment = $this->environmentOverrides ? getenv($name) : false;
		return $environment !== false ? $environment : (string) ($this->values[$name] ?? $default);
	}

	public function development(): bool
	{
		return in_array($this->get('APP_ENV', 'production'), ['development', 'testing'], true);
	}

	/** Entorno de firma de las licencias: decide con qué llaves se firma y qué dice `environment`. */
	public function licenseEnvironment(): string
	{
		$environment = $this->get('LICENSE_ENVIRONMENT');
		if (!in_array($environment, self::LICENSE_ENVIRONMENTS, true)) {
			throw new \RuntimeException('Invalid license environment.');
		}
		return $environment;
	}

	public function key(string $name): string
	{
		$key = base64_decode($this->get($name), true);
		if ($key === false || strlen($key) !== 32) {
			throw new \RuntimeException('Falta configurar una clave de aplicación válida: ' . $name);
		}
		return $key;
	}

	public function validate(): void
	{
		if (!in_array($this->get('APP_ENV', 'production'), ['production', 'development', 'testing'], true)) {
			throw new \RuntimeException('APP_ENV debe ser production, development o testing.');
		}
		$license = $this->get('LICENSE_ENVIRONMENT');
		if (!in_array($license, self::LICENSE_ENVIRONMENTS, true)) {
			throw new Problem(
				($license === ''
					? 'Falta LICENSE_ENVIRONMENT en la configuración'
					: 'LICENSE_ENVIRONMENT tiene un valor no válido') .
					': debe ser test o production. Es el entorno de firma de las licencias y no depende de APP_ENV.',
			);
		}
		// Las llaves de producción nunca se usan con las facilidades de desarrollo (HTTP local, cookie sin prefijo).
		if ($license === 'production' && $this->get('APP_ENV', 'production') !== 'production') {
			throw new Problem('LICENSE_ENVIRONMENT=production solo se permite con APP_ENV=production.');
		}
		foreach (['MFA_KEY', 'CREDENTIAL_KEY', 'AUDIT_KEY', 'SESSION_KEY', 'OPERATION_KEY'] as $name) {
			$this->key($name);
		}
		$origin = $this->get('APP_URL');
		$url = parse_url($origin);
		if (
			!$url ||
			!isset($url['host']) ||
			preg_match('/[\x00-\x20\x7f]/', $origin) ||
			isset($url['user']) ||
			isset($url['pass']) ||
			isset($url['query']) ||
			isset($url['fragment']) ||
			!in_array($url['scheme'] ?? '', ['http', 'https'], true) ||
			!in_array($url['path'] ?? '', ['', '/'], true)
		) {
			throw new \RuntimeException('APP_URL debe ser el origen del sitio, sin subdirectorio.');
		}
		if (
			($url['scheme'] ?? '') !== 'https' &&
			!($this->development() && in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true))
		) {
			throw new \RuntimeException('HTTPS es obligatorio fuera del desarrollo local.');
		}
	}
}
