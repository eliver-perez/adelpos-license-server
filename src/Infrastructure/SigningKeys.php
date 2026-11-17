<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

use AdelPos\License\Config;
use AdelPos\License\Domain\{Jws, Problem, Protocol};

final class SigningKeys
{
	/** Etiqueta de cada entorno en el formato sugerido de `kid`: adelpos-<etiqueta>-AAAAMM-n. */
	private const LABELS = ['test' => 'test', 'production' => 'prod'];
	/**
	 * Semillas públicas y deterministas de los archivos de prueba del contrato
	 * (contracts/v1/generate-fixtures.php). Sus llaves nunca firman en producción.
	 */
	public const FIXTURE_SEEDS = [
		'adelpos-test-fixture-a',
		'adelpos-test-fixture-b',
		'adelpos-prod-fixture-a',
		'installation-fixture-a',
		'installation-fixture-b',
	];

	public function __construct(
		private Config $config,
		private Database $db,
		private Clock $clock,
		private Audit $audit,
	) {}

	/** Entorno de firma del contrato (`test` o `production`). No es APP_ENV. */
	public function environment(): string
	{
		return $this->config->licenseEnvironment();
	}

	public function directory(): string
	{
		$directory = $this->config->get('SIGNING_KEY_DIR') ?: dirname(__DIR__, 2) . '/var/keys/' . $this->environment();
		if (!str_starts_with($directory, '/')) {
			throw new \RuntimeException('Signing directory must be absolute.');
		}
		return rtrim($directory, '/');
	}

	/** Un `kid` vacío con propósito `license` recibe el siguiente identificador sugerido del entorno. */
	public function generate(string $kid, string $purpose): array
	{
		$environment = $this->environment();
		if ($kid === '' && $purpose === 'license') {
			$kid = $this->nextKid($environment);
		}
		if (
			!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $kid) ||
			!in_array($purpose, ['license', 'manifest'], true)
		) {
			throw new Problem('Identificador o propósito de llave no válido.');
		}
		if (preg_match('/\Aadelpos-(test|prod)-/', $kid, $label) && $label[1] !== self::LABELS[$environment]) {
			throw new Problem(
				'El identificador «' .
					$kid .
					'» lleva la etiqueta de otro entorno. Este servidor firma en el entorno ' .
					$environment .
					'.',
			);
		}
		$directory = $this->directory();
		if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
			throw new \RuntimeException('Cannot create signing directory.');
		}
		$this->assertOutsidePublic($directory);
		$this->assertSeparateFolder();
		$reference = bin2hex(random_bytes(16)) . '.json';
		$pair = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey($pair);
		$public = sodium_crypto_sign_publickey($pair);
		$path = $directory . '/' . $reference;
		$mask = umask(0077);
		try {
			$handle = fopen($path, 'x');
			if (!$handle) {
				throw new \RuntimeException('Cannot create signing key.');
			}
			try {
				$content = Protocol::json([
					'kid' => $kid,
					'environment' => $environment,
					'purpose' => $purpose,
					'public_key' => Protocol::encode($public),
					'secret_key' => Protocol::encode($secret),
				]);
				if (fwrite($handle, $content) !== strlen($content)) {
					throw new \RuntimeException('Incomplete signing key write.');
				}
				fflush($handle);
			} finally {
				fclose($handle);
			}
			$this->db->transaction(function () use ($kid, $environment, $purpose, $reference, $public): void {
				$this->db->execute(
					'INSERT INTO signing_keys(kid,environment,purpose,public_key,private_ref,
							state,created_at)
						VALUES (?,?,?,?,?,\'staged\',?)',
					[$kid, $environment, $purpose, $public, $reference, $this->clock->sql()],
				);
				$this->db->execute(
					'INSERT INTO signing_scopes(environment,purpose)
						VALUES (?,?)
						ON DUPLICATE KEY UPDATE environment=environment',
					[$environment, $purpose],
				);
				$this->audit->append(null, 'signing_key.generated', 'signing_key', $kid, [
					'environment' => $environment,
					'purpose' => $purpose,
				]);
			});
		} catch (\Throwable $error) {
			// A DB connection loss at commit can be ambiguous: preserve the key
			// for reconciliation, never delete a potentially registered private.
			throw $error;
		} finally {
			umask($mask);
			sodium_memzero($secret);
			sodium_memzero($pair);
		}
		return [
			'kid' => $kid,
			'environment' => $environment,
			'purpose' => $purpose,
			'public_key' => Protocol::encode($public),
			'state' => 'staged',
		];
	}

	public function activate(string $kid, string $reason): void
	{
		if (trim($reason) === '' || strlen($reason) > 500) {
			throw new Problem('Indica un motivo de activación de la llave.');
		}
		$environment = $this->environment();
		$key = $this->db->one('SELECT * FROM signing_keys WHERE kid=? AND environment=?', [$kid, $environment]);
		if (!$key) {
			throw new Problem('La llave no existe en el entorno ' . $environment . '.');
		}
		$this->db->transaction(function () use ($kid, $key, $environment, $reason): void {
			$scope = $this->db->one('SELECT * FROM signing_scopes WHERE environment=? AND purpose=? FOR UPDATE', [
				$environment,
				$key['purpose'],
			]);
			if (!$scope) {
				throw new \RuntimeException('Missing signing scope.');
			}
			$fresh = $this->db->one('SELECT * FROM signing_keys WHERE kid=? FOR UPDATE', [$kid]);
			$secret = $this->readSecret($fresh);
			sodium_memzero($secret);
			if ($scope['active_kid'] === $kid) {
				return;
			}
			if ($scope['active_kid'] !== null) {
				$this->db->execute("UPDATE signing_keys SET state='verify_only' WHERE kid=?", [$scope['active_kid']]);
			}
			$this->db->execute("UPDATE signing_keys SET state='signing' WHERE kid=?", [$kid]);
			$this->db->execute('UPDATE signing_scopes SET active_kid=? WHERE environment=? AND purpose=?', [
				$kid,
				$environment,
				$key['purpose'],
			]);
			$this->audit->append(
				null,
				'signing_key.activated',
				'signing_key',
				$kid,
				['previous_kid' => $scope['active_kid'], 'environment' => $environment, 'purpose' => $key['purpose']],
				$reason,
			);
		});
	}

	/** Solo firma la llave activa del entorno configurado. */
	public function sign(array $payload): array
	{
		if (!$this->db->pdo->inTransaction()) {
			throw new \LogicException('Signing requires the license transaction.');
		}
		$key = $this->activeKey();
		try {
			$secret = $this->readSecret($key);
		} catch (\Throwable $error) {
			throw new \RuntimeException('License signing material unavailable.', 0, $error);
		}
		try {
			return [
				'kid' => $key['kid'],
				'environment' => $key['environment'],
				'jws' => Jws::sign($payload, $key['kid'], $secret),
			];
		} finally {
			sodium_memzero($secret);
		}
	}

	public function check(): void
	{
		$this->db->transaction(function (): void {
			$secret = $this->readSecret($this->activeKey());
			sodium_memzero($secret);
		});
	}

	/**
	 * Arranque y revisión previa. Una base de licencias pertenece a un solo entorno:
	 * si su llave activa es de otro, el servidor no arranca.
	 */
	public function assertEnvironment(): void
	{
		$environment = $this->environment();
		$foreign = $this->db->one(
			'SELECT environment,active_kid
				FROM signing_scopes
				WHERE active_kid IS NOT NULL AND environment<>?
				ORDER BY environment,purpose
				LIMIT 1',
			[$environment],
		);
		if ($foreign) {
			throw new Problem(
				'La llave de firma activa «' .
					$foreign['active_kid'] .
					'» es del entorno ' .
					$foreign['environment'] .
					', pero LICENSE_ENVIRONMENT es ' .
					$environment .
					'. Corrige la configuración o usa la base de datos de ese entorno.',
				503,
			);
		}
	}

	/** La carpeta de llaves de un entorno no guarda llaves registradas en otro. */
	public function assertSeparateFolder(): void
	{
		$environment = $this->environment();
		$directory = $this->directory();
		foreach (
			$this->db->all('SELECT kid,environment,private_ref FROM signing_keys WHERE environment<>? ORDER BY kid', [
				$environment,
			])
			as $key
		) {
			if (
				preg_match('/\A[a-f0-9]{32}\.json\z/', $key['private_ref']) &&
				file_exists($directory . '/' . $key['private_ref'])
			) {
				throw new Problem(
					'La carpeta de llaves del entorno ' .
						$environment .
						' contiene la llave «' .
						$key['kid'] .
						'», que es del entorno ' .
						$key['environment'] .
						'. Cada entorno usa su propia carpeta.',
					503,
				);
			}
		}
	}

	/**
	 * Llaves públicas de licencias del entorno, para incluirlas en una versión de AD-EL POS.
	 * Incluye las preparadas, la activa y las anteriores. Nunca lee los archivos privados.
	 */
	public function publicKeys(): array
	{
		$environment = $this->environment();
		$keys = [];
		foreach (
			$this->db->all(
				"SELECT kid,environment,public_key
					FROM signing_keys
					WHERE environment=? AND purpose='license'
					ORDER BY created_at,kid",
				[$environment],
			)
			as $key
		) {
			$keys[] = [
				'kid' => $key['kid'],
				'environment' => $key['environment'],
				'public_key' => Protocol::encode($key['public_key']),
			];
		}
		return ['environment' => $environment, 'keys' => $keys];
	}

	private function nextKid(string $environment): string
	{
		$prefix = 'adelpos-' . self::LABELS[$environment] . '-' . $this->clock->now()->format('Ym') . '-';
		$last = 0;
		foreach ($this->db->all('SELECT kid FROM signing_keys WHERE kid LIKE ?', [$prefix . '%']) as $key) {
			$number = substr($key['kid'], strlen($prefix));
			if (ctype_digit($number)) {
				$last = max($last, (int) $number);
			}
		}
		return $prefix . ($last + 1);
	}

	private function activeKey(): array
	{
		// The shared scope lock serializes publication against key rotation.
		$scope = $this->db->one("SELECT * FROM signing_scopes WHERE environment=? AND purpose='license' FOR SHARE", [
			$this->environment(),
		]);
		if (!$scope || $scope['active_kid'] === null) {
			throw new \RuntimeException('No active license signing key.');
		}
		$key = $this->db->one('SELECT * FROM signing_keys WHERE kid=? FOR SHARE', [$scope['active_kid']]);
		if (!$key || $key['state'] !== 'signing' || $key['purpose'] !== 'license') {
			throw new \RuntimeException('Invalid signing key scope.');
		}
		return $key;
	}

	private function assertOutsidePublic(string $path): void
	{
		$real = realpath($path);
		$public = realpath(dirname(__DIR__, 2) . '/public');
		if ($real === false || $real === $public || str_starts_with($real, $public . '/')) {
			throw new \RuntimeException('Signing keys must be outside public.');
		}
	}

	private function readSecret(array $key): string
	{
		if (
			$key['environment'] !== $this->environment() ||
			!preg_match('/\A[a-f0-9]{32}\.json\z/', $key['private_ref'])
		) {
			throw new \RuntimeException('Invalid key metadata.');
		}
		$path = $this->directory() . '/' . $key['private_ref'];
		$this->assertOutsidePublic($path);
		if (is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 2048) {
			throw new \RuntimeException('Invalid signing key permissions or file.');
		}
		$data = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
		if (
			($data['kid'] ?? null) !== $key['kid'] ||
			($data['environment'] ?? null) !== $key['environment'] ||
			($data['purpose'] ?? null) !== $key['purpose'] ||
			($data['public_key'] ?? null) !== Protocol::encode($key['public_key'])
		) {
			throw new \RuntimeException('Signing key metadata mismatch.');
		}
		$secret = Protocol::decode($data['secret_key'] ?? '', 64);
		$derived = sodium_crypto_sign_seed_keypair(substr($secret, 0, 32));
		try {
			if (
				!hash_equals(sodium_crypto_sign_secretkey($derived), $secret) ||
				!hash_equals(sodium_crypto_sign_publickey($derived), $key['public_key'])
			) {
				throw new \RuntimeException('Signing key pair mismatch.');
			}
			// Public deterministic fixture seeds are never accepted for production licenses
			// nor on a production server, even if someone relabels their file or registry record.
			if ($this->environment() === 'production' || $this->config->get('APP_ENV', 'production') === 'production') {
				foreach (self::FIXTURE_SEEDS as $name) {
					$fixture = sodium_crypto_sign_seed_keypair(
						hash('sha256', 'ADELPOS LICENSE PUBLIC TEST ONLY V1 ' . $name, true),
					);
					if (hash_equals(sodium_crypto_sign_publickey($fixture), $key['public_key'])) {
						throw new \RuntimeException('Public fixture key forbidden in production.');
					}
				}
			}
			return $secret;
		} catch (\Throwable $error) {
			sodium_memzero($secret);
			throw $error;
		} finally {
			sodium_memzero($derived);
		}
	}
}
