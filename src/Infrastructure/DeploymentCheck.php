<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

use AdelPos\License\{App, Config};
use AdelPos\License\Domain\Problem;

/**
 * Verificación de un despliegue (bin/verify-deployment.php; etapa 5, Fase 7). Comprueba, **sin modificar nada**,
 * lo que deja instalado DEPLOY_UBUNTU_24_04.md: PHP y sus extensiones; los permisos de la configuración, las
 * llaves, el código y los respaldos; la cuenta de la aplicación en MySQL con sus permisos exactos; las
 * migraciones; la llave de firma activa; la tarea diaria; y la respuesta HTTPS del dominio.
 *
 * Cada resultado es OK, Advertencia o Error, con qué hacer. Las peticiones al sitio no escriben en la base: no
 * se abre el panel (crearía una sesión), y la API se prueba con un tipo de contenido inválido, que se rechaza
 * antes de contar intentos.
 */
final class DeploymentCheck
{
	public const OK = 'OK';
	public const WARNING = 'Advertencia';
	public const ERROR = 'Error';

	public const EXTENSIONS = ['sodium', 'pdo_mysql', 'mbstring', 'xmlwriter', 'dom', 'iconv', 'gd'];
	public const TIMER = 'adelpos-license-maintenance';
	/** La tarea corre cada día: más de 36 horas sin correr es una advertencia. */
	private const TIMER_HOURS = 36;
	/** Días antes del vencimiento del certificado en que se advierte (Certbot renueva a los 30). */
	private const CERTIFICATE_DAYS = 14;

	/** @var list<array{key: string, status: string, title: string, detail: string, fix: string}> */
	private array $results = [];

	/**
	 * @param \Closure(): App $connect arranca la aplicación con la configuración y la cuenta de MySQL.
	 * @param array{
	 *     config: string, code: string, app_user: string, admin_user: string, backup: string,
	 *     license_environment: string, url: string, http_url: string, ca_file: string, systemctl: string
	 * } $options
	 */
	public function __construct(private Config $config, private \Closure $connect, private array $options) {}

	/** @return list<array{key: string, status: string, title: string, detail: string, fix: string}> */
	public function run(): array
	{
		$this->results = [];
		$this->php();
		$this->permissions();
		$this->database();
		$this->timer();
		$this->https();
		return $this->results;
	}

	public static function failed(array $results): bool
	{
		return in_array(self::ERROR, array_column($results, 'status'), true);
	}

	private function add(string $key, string $status, string $title, string $detail, string $fix = ''): void
	{
		$this->results[] = compact('key', 'status', 'title', 'detail', 'fix');
	}

	private function php(): void
	{
		if (PHP_VERSION_ID < 80300) {
			$this->add('php', self::ERROR, 'PHP', 'PHP ' . PHP_VERSION . '.', 'Usa PHP 8.3 (php8.3), paso 1.3.');
		} else {
			$this->add('php', self::OK, 'PHP', 'PHP ' . PHP_VERSION . '.');
		}
		$missing = array_values(
			array_filter(self::EXTENSIONS, static fn(string $name): bool => !extension_loaded($name)),
		);
		if (!in_array('argon2id', password_algos(), true)) {
			$missing[] = 'argon2id';
		}
		if ($missing === []) {
			$this->add('extensions', self::OK, 'Extensiones de PHP', implode(', ', self::EXTENSIONS) . ' y Argon2id.');
			return;
		}
		$this->add(
			'extensions',
			self::ERROR,
			'Extensiones de PHP',
			'Faltan: ' . implode(', ', $missing) . '.',
			'Instálalas con apt (paso 1.3) y reinicia php8.3-fpm.',
		);
	}

	private function permissions(): void
	{
		$user = $this->options['app_user'];
		$admin = $this->options['admin_user'];
		$config = $this->options['config'];
		$this->inside('config', 'Configuración', $config);
		$this->file('config', 'Configuración', $config, 0600, $user);
		$shared = dirname($config);
		foreach (['log' => 'Registro de PHP', 'tmp' => 'Temporales de PHP'] as $folder => $title) {
			$this->directory('shared_' . $folder, $title, $shared . '/' . $folder, 0700, $user);
		}
		$keys = rtrim($this->config->get('SIGNING_KEY_DIR'), '/');
		if ($keys === '') {
			$this->add(
				'keys_dir',
				self::ERROR,
				'Carpeta de llaves',
				'SIGNING_KEY_DIR está vacío: las llaves quedarían dentro del código.',
				'Paso 5: configura --signing-dir=/srv/adelpos-license/shared/keys/test.',
			);
		} else {
			$this->inside('keys_dir', 'Carpeta de llaves', $keys);
			$this->directory('keys_dir', 'Carpeta de llaves', $keys, 0700, $user);
			foreach (glob($keys . '/*') ?: [] as $key) {
				$this->file('key', 'Llave ' . basename($key), $key, 0600, $user);
			}
		}
		$this->code();
		$backup = $this->options['backup'];
		$this->directory('backup_dir', 'Carpeta de respaldos', $backup, 0700, $admin);
		if (!is_dir($backup)) {
			return;
		}
		if (!is_readable($backup) || !is_executable($backup)) {
			$this->add(
				'recovery_key',
				self::WARNING,
				'Clave de recuperación',
				'No se pudo revisar: la carpeta de respaldos es solo de ' . $admin . '.',
				'Ejecuta esta verificación con sudo.',
			);
			return;
		}
		$this->file('recovery_key', 'Clave de recuperación', $backup . '/recovery.key', 0600, $admin);
	}

	/** El código lo puede modificar solo root: la tarea diaria lo ejecuta como root. */
	private function code(): void
	{
		$code = rtrim($this->options['code'], '/');
		$admin = $this->options['admin_user'];
		if (!is_dir($code)) {
			$this->add('code', self::ERROR, 'Código', 'No existe ' . $code . '.', 'Paso 3.');
			return;
		}
		$wrong = [];
		$paths = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($code, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD,
		);
		foreach ([new \SplFileInfo($code), ...$paths] as $path) {
			if ($path->isLink()) {
				continue;
			}
			if (($path->getPerms() & 0022) !== 0 || self::owner($path->getPathname()) !== $admin) {
				$wrong[] = $path->getPathname();
			}
		}
		if ($wrong === []) {
			$this->add('code', self::OK, 'Código', 'Solo ' . $admin . ' puede modificar ' . $code . '.');
			return;
		}
		$this->add(
			'code',
			self::ERROR,
			'Código',
			count($wrong) .
				' archivos o carpetas no son de ' .
				$admin .
				' o los puede modificar alguien más, por ejemplo ' .
				implode(', ', array_slice($wrong, 0, 3)) .
				'.',
			'sudo chown -R root:root ' . $code . ' && sudo chmod -R go-w ' . $code,
		);
	}

	/** La configuración y las llaves viven fuera del clon: cambiar de versión no las toca. */
	private function inside(string $key, string $title, string $path): void
	{
		$code = realpath($this->options['code']);
		$real = realpath($path);
		if ($code !== false && $real !== false && str_starts_with($real . '/', $code . '/')) {
			$this->add(
				$key,
				self::ERROR,
				$title,
				$path . ' está dentro del código (' . $code . ').',
				'Pasos 2 y 5: va en /srv/adelpos-license/shared.',
			);
		}
	}

	private function file(string $key, string $title, string $path, int $mode, string $owner): void
	{
		if (!is_file($path)) {
			$this->add($key, self::ERROR, $title, 'No existe ' . $path . '.', 'Revisa los pasos 2, 5, 8 y 12.1.');
			return;
		}
		$this->mode($key, $title, $path, $mode, $owner);
	}

	private function directory(string $key, string $title, string $path, int $mode, string $owner): void
	{
		if (!is_dir($path)) {
			$this->add($key, self::ERROR, $title, 'No existe ' . $path . '.', 'Paso 2.');
			return;
		}
		$this->mode($key, $title, $path, $mode, $owner);
	}

	private function mode(string $key, string $title, string $path, int $mode, string $owner): void
	{
		$actual = fileperms($path) & 0777;
		$actualOwner = self::owner($path);
		if ($actual === $mode && $actualOwner === $owner) {
			$this->add($key, self::OK, $title, sprintf('%s: %04o, de %s.', $path, $actual, $owner));
			return;
		}
		$this->add(
			$key,
			self::ERROR,
			$title,
			sprintf('%s es %04o de %s; debe ser %04o de %s.', $path, $actual, $actualOwner, $mode, $owner),
			sprintf('sudo chown %s %s && sudo chmod %o %s', $owner, $path, $mode, $path),
		);
	}

	private static function owner(string $path): string
	{
		$uid = @fileowner($path);
		if ($uid === false) {
			return '?';
		}
		if (function_exists('posix_getpwuid')) {
			return (string) (posix_getpwuid($uid)['name'] ?? $uid);
		}
		return (string) $uid;
	}

	private function database(): void
	{
		try {
			$app = ($this->connect)();
		} catch (\Throwable $error) {
			// Nunca se muestra el mensaje de PDO: puede llevar la cadena de conexión.
			$this->add(
				'database',
				self::ERROR,
				'MySQL con la cuenta de la aplicación',
				$error instanceof Problem
					? $error->getMessage()
					: 'No se pudo cargar la configuración o conectar (' . get_class($error) . ').',
				'Revisa ADELPOS_LICENSE_CONFIG, DB_DSN, DB_USER y DB_PASSWORD (pasos 4 y 5) y que MySQL esté activo.',
			);
			return;
		}
		$db = $app->db;
		$version = (string) $db->execute('SELECT VERSION()')->fetchColumn();
		$database = (string) $db->execute('SELECT DATABASE()')->fetchColumn();
		$this->add(
			'database',
			str_starts_with($version, '8.') && stripos($version, 'MariaDB') === false ? self::OK : self::ERROR,
			'MySQL con la cuenta de la aplicación',
			'MySQL ' . $version . ', base ' . $database . ', cuenta ' . $this->config->get('DB_USER') . '.',
			str_starts_with($version, '8.') ? '' : 'El servidor requiere MySQL 8.',
		);
		$rows = array_map(
			static fn(array $row): string => (string) array_values($row)[0],
			$db->all('SHOW GRANTS FOR CURRENT_USER()'),
		);
		$problems = RuntimeGrants::differences($rows, $database);
		if ($problems === []) {
			$this->add('grants', self::OK, 'Permisos de la cuenta', 'Exactamente los de bin/grants.php.');
		} else {
			$this->add(
				'grants',
				self::ERROR,
				'Permisos de la cuenta',
				implode('; ', $problems) . '.',
				'Paso 6: REVOKE ALL PRIVILEGES, GRANT OPTION FROM la cuenta y aplica de nuevo bin/grants.php.',
			);
		}
		$this->migrations($app);
		$this->signing($app);
	}

	private function migrations(App $app): void
	{
		$applied = [];
		foreach ($app->db->all('SELECT name,checksum,state FROM schema_migrations ORDER BY name') as $row) {
			$applied[$row['name']] = $row;
		}
		$problems = [];
		$files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
		foreach ($files as $file) {
			$row = $applied[basename($file)] ?? null;
			unset($applied[basename($file)]);
			if ($row === null) {
				$problems[] = basename($file) . ' sin aplicar';
			} elseif ($row['state'] !== 'complete') {
				$problems[] = basename($file) . ' incompleta';
			} elseif (!hash_equals($row['checksum'], hash_file('sha256', $file))) {
				$problems[] = basename($file) . ' cambió después de aplicarse';
			}
		}
		foreach (array_keys($applied) as $name) {
			$problems[] = $name . ' aplicada, pero este código no la tiene (la base es de una versión posterior)';
		}
		if ($problems === []) {
			$this->add('migrations', self::OK, 'Migraciones', count($files) . ' aplicadas, sin cambios.');
			return;
		}
		$this->add(
			'migrations',
			self::ERROR,
			'Migraciones',
			implode('; ', $problems) . '.',
			'Paso 6 (o 14 al actualizar): bin/migrate.php con la cuenta de migración.',
		);
	}

	private function signing(App $app): void
	{
		$environment = $this->config->get('LICENSE_ENVIRONMENT');
		$expected = $this->options['license_environment'];
		if ($environment !== $expected) {
			$this->add(
				'signing',
				self::ERROR,
				'Llave de firma activa',
				'La configuración firma en el entorno «' .
					$environment .
					'»; este sitio debe firmar en «' .
					$expected .
					'».',
				'Paso 5: --license-environment=' . $expected . '.',
			);
			return;
		}
		try {
			$app->signingKeys->check();
		} catch (\Throwable) {
			$this->add(
				'signing',
				self::ERROR,
				'Llave de firma activa',
				'No hay una llave activa del entorno «' . $environment . '» o no se puede leer su archivo.',
				'Paso 8: genera, exporta y activa la llave; revisa los permisos de la carpeta de llaves.',
			);
			return;
		}
		$kid = $app->db->one("SELECT active_kid FROM signing_scopes WHERE environment=? AND purpose='license'", [
			$environment,
		]);
		$this->add(
			'signing',
			self::OK,
			'Llave de firma activa',
			'«' . ($kid['active_kid'] ?? '') . '», del entorno «' . $environment . '».',
		);
	}

	private function timer(): void
	{
		$timer = $this->systemd(self::TIMER . '.timer', ['LoadState', 'ActiveState', 'UnitFileState']);
		if ($timer === null) {
			$this->add(
				'timer',
				self::WARNING,
				'Tarea diaria',
				'No se pudo consultar systemd.',
				'Ejecuta esta verificación en el VPS (paso 11).',
			);
			return;
		}
		if (
			($timer['LoadState'] ?? '') !== 'loaded' ||
			($timer['ActiveState'] ?? '') !== 'active' ||
			($timer['UnitFileState'] ?? '') !== 'enabled'
		) {
			$this->add(
				'timer',
				self::ERROR,
				'Tarea diaria',
				'El timer no está instalado, activo y habilitado.',
				'Paso 11: copia las unidades y ejecuta sudo systemctl enable --now ' . self::TIMER . '.timer.',
			);
			return;
		}
		$service = $this->systemd(self::TIMER . '.service', ['ExecMainExitTimestamp', 'ExecMainStatus']) ?? [];
		// Sin ejecuciones, systemd responde vacío o «n/a», que strtotime() no entiende.
		$finished = strtotime($service['ExecMainExitTimestamp'] ?? '');
		if ($finished === false) {
			$this->add(
				'timer',
				self::WARNING,
				'Tarea diaria',
				'El timer está activo, pero la tarea todavía no ha corrido.',
				'sudo systemctl start ' . self::TIMER . '.service y revisa su resultado (paso 11).',
			);
			return;
		}
		if (($service['ExecMainStatus'] ?? '') !== '0') {
			$this->add(
				'timer',
				self::ERROR,
				'Tarea diaria',
				'La última ejecución terminó con el código ' . ($service['ExecMainStatus'] ?? '?') . '.',
				'sudo journalctl -u ' . self::TIMER . '.service (paso 16, problemas comunes).',
			);
			return;
		}
		$hours = intdiv(max(0, time() - $finished), 3600);
		$late = $hours > self::TIMER_HOURS;
		$this->add(
			'timer',
			$late ? self::WARNING : self::OK,
			'Tarea diaria',
			'Última ejecución correcta hace ' . $hours . ($hours === 1 ? ' hora.' : ' horas.'),
			$late ? 'Debe correr cada día: revisa sudo systemctl list-timers ' . self::TIMER . '.timer.' : '',
		);
	}

	/**
	 * Propiedades de una unidad de systemd; null si no se pudo consultar.
	 *
	 * @param list<string> $properties
	 * @return array<string, string>|null
	 */
	private function systemd(string $unit, array $properties): ?array
	{
		$command = [$this->options['systemctl'], 'show', $unit];
		foreach ($properties as $property) {
			$command[] = '--property=' . $property;
		}
		$process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			return null;
		}
		$output = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			return null;
		}
		$values = [];
		foreach (explode("\n", trim($output)) as $line) {
			if (str_contains($line, '=')) {
				[$name, $value] = explode('=', $line, 2);
				$values[$name] = $value;
			}
		}
		return $values;
	}

	private function https(): void
	{
		$url = rtrim($this->options['url'], '/');
		$host = (string) parse_url($url, PHP_URL_HOST);
		$this->certificate($host, (int) (parse_url($url, PHP_URL_PORT) ?: 443));
		// Redirección de HTTP a HTTPS: la responde Nginx, sin llegar a PHP.
		$redirect = $this->request(rtrim($this->options['http_url'], '/') . '/login', 'GET');
		$location = $redirect['headers']['location'] ?? '';
		if (in_array($redirect['status'], [301, 308], true) && $location === 'https://' . $host . '/login') {
			$this->add(
				'redirect',
				self::OK,
				'Redirección de HTTP a HTTPS',
				$redirect['status'] . ' a ' . $location . '.',
			);
		} else {
			$this->add(
				'redirect',
				self::ERROR,
				'Redirección de HTTP a HTTPS',
				self::answer('http://…/login', $redirect['status']) . ($location === '' ? '' : ' a ' . $location) . '.',
				'Paso 10.2: el bloque de HTTP del sitio (puerto 80) y el firewall.',
			);
		}
		// Archivos ocultos dentro de public/: .htaccess existe y no se debe servir.
		$hidden = $this->request($url . '/.htaccess', 'GET');
		$this->add(
			'hidden',
			$hidden['status'] === 404 ? self::OK : self::ERROR,
			'Archivos ocultos',
			self::answer('/.htaccess', $hidden['status']) . '.',
			$hidden['status'] === 404 ? '' : 'Paso 10.2: el bloque «location ~ /\\.» debe responder 404.',
		);
		// La API con el formato del contrato (§8.7). Un tipo de contenido inválido se rechaza antes de contar
		// intentos o de leer el cuerpo: no escribe nada.
		$api = $this->request($url . '/v1/activations/challenge', 'POST', 'Content-Type: text/plain', 'verificacion');
		$decoded = json_decode($api['body'], true);
		$error = is_array($decoded) ? $decoded['error'] ?? null : null;
		$valid =
			$api['status'] === 415 &&
			str_starts_with($api['headers']['content-type'] ?? '', 'application/json') &&
			is_array($decoded) &&
			array_keys($decoded) === ['error'] &&
			is_array($error) &&
			array_keys($error) === ['code', 'message', 'request_id', 'details'] &&
			$error['code'] === 'INVALID_REQUEST' &&
			$error['request_id'] === null &&
			$error['details'] === [];
		$this->add(
			'api',
			$valid ? self::OK : self::ERROR,
			'API en línea',
			self::answer('/v1/activations/challenge', $api['status']) .
				($valid ? ' con el formato del contrato.' : '.'),
			$valid ? '' : 'Revisa el bloque /v1/ de Nginx, el pool de PHP-FPM y /srv/adelpos-license/shared/log.',
		);
		// Cabeceras de seguridad: las pone Nginx en todo, también en lo que responde PHP.
		$asset = $this->request($url . '/assets/app.css', 'GET');
		$missing = [];
		foreach (['/assets/app.css' => $asset, '/v1/' => $api] as $path => $response) {
			foreach (
				[
					'strict-transport-security' => 'max-age=',
					'x-content-type-options' => 'nosniff',
					'cache-control' => 'no-store',
				]
				as $header => $value
			) {
				if (!str_contains(strtolower($response['headers'][$header] ?? ''), $value)) {
					$missing[] = $header . ' en ' . $path;
				}
			}
			if (preg_match('~nginx/\d~i', $response['headers']['server'] ?? '')) {
				$missing[] = 'server sin la versión de Nginx en ' . $path;
			}
		}
		if ($asset['status'] === 200 && $missing === []) {
			$this->add(
				'headers',
				self::OK,
				'Cabeceras de seguridad',
				'HSTS, nosniff y no-store; Nginx no dice su versión.',
			);
			return;
		}
		$this->add(
			'headers',
			self::ERROR,
			'Cabeceras de seguridad',
			self::answer('/assets/app.css', $asset['status']) .
				($missing === [] ? '' : '; faltan: ' . implode(', ', $missing)) .
				'.',
			'Paso 10.2: instala deploy/nginx.conf.example sin quitarle líneas.',
		);
	}

	private function certificate(string $host, int $port): void
	{
		$ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'capture_peer_cert' => true, 'peer_name' => $host];
		if ($this->options['ca_file'] !== '') {
			$ssl['cafile'] = $this->options['ca_file'];
		}
		$socket = @stream_socket_client(
			'ssl://' . $host . ':' . $port,
			$errno,
			$message,
			10,
			STREAM_CLIENT_CONNECT,
			stream_context_create(['ssl' => $ssl]),
		);
		if ($socket === false) {
			$this->add(
				'certificate',
				self::ERROR,
				'Certificado HTTPS',
				'No se pudo abrir una conexión TLS verificada con ' . $host . ':' . $port . '.',
				'Paso 10: el DNS, Certbot y el sitio de Nginx.',
			);
			return;
		}
		$certificate = openssl_x509_parse(stream_context_get_params($socket)['options']['ssl']['peer_certificate']);
		fclose($socket);
		$days = intdiv((int) $certificate['validTo_time_t'] - time(), 86400);
		$soon = $days < self::CERTIFICATE_DAYS;
		$this->add(
			'certificate',
			$soon ? self::WARNING : self::OK,
			'Certificado HTTPS',
			'Válido para ' . $host . '; vence en ' . $days . ($days === 1 ? ' día.' : ' días.'),
			$soon ? 'La renovación automática falló: sudo certbot renew --cert-name ' . $host . ' (paso 10.3).' : '',
		);
	}

	private static function answer(string $path, int $status): string
	{
		return $status === 0 ? $path . ' no respondió' : $path . ' respondió ' . $status;
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private function request(string $url, string $method, string $header = '', string $body = ''): array
	{
		$context = [
			'http' => [
				'method' => $method,
				'header' => $header,
				'content' => $body,
				'ignore_errors' => true,
				'follow_location' => 0,
				'timeout' => 10,
			],
			'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
		];
		if ($this->options['ca_file'] !== '') {
			$context['ssl']['cafile'] = $this->options['ca_file'];
		}
		$stream = @fopen($url, 'r', false, stream_context_create($context));
		if ($stream === false) {
			return ['status' => 0, 'headers' => [], 'body' => ''];
		}
		$content = (string) stream_get_contents($stream);
		$lines = stream_get_meta_data($stream)['wrapper_data'] ?? [];
		fclose($stream);
		$status = 0;
		$headers = [];
		foreach ($lines as $line) {
			if (preg_match('~\AHTTP/\S+ (\d{3})~', $line, $match)) {
				$status = (int) $match[1];
				$headers = [];
			} elseif (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$headers[strtolower(trim($name))] = trim($value);
			}
		}
		return ['status' => $status, 'headers' => $headers, 'body' => $content];
	}
}
