<?php
// Pruebas del panel con Playwright, con datos sintéticos y limpieza garantizada.
//
//   npm ci --ignore-scripts --no-audit --no-fund
//   env TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' TEST_MYSQL_USER=root \
//       TEST_MYSQL_PASSWORD=... php tests/run-panel-tests.php [panel|trusted|all]
//
// «panel» usa el servidor PHP local en 127.0.0.1:8088; «trusted» usa Nginx, PHP-FPM y TLS reales
// (TEST_FPM_BIN, TEST_NGINX_BIN y TEST_OPENSSL_BIN si no están en PATH). Chrome: CHROME_PATH.
// Al terminar se eliminan la base y el usuario de prueba, las llaves y la configuración temporal.
// El registro temporal del servidor se conserva solo si hubo fallas.
declare(strict_types=1);

$mode = $argv[1] ?? 'all';
if (!in_array($mode, ['panel', 'trusted', 'all'], true)) {
	fwrite(STDERR, "Uso: php tests/run-panel-tests.php [panel|trusted|all]\n");
	exit(2);
}
$dsn = getenv('TEST_MYSQL_DSN');
if (!$dsn || !str_starts_with($dsn, 'mysql:')) {
	fwrite(STDERR, "Define TEST_MYSQL_DSN con un MySQL 8 aislado para pruebas.\n");
	exit(2);
}
$root = dirname(__DIR__);
$env = getenv();
$pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$log = sys_get_temp_dir() . '/adelpos-license-panel-' . bin2hex(random_bytes(4)) . '.log';

function waitFor(callable $ready, float $seconds): bool
{
	$deadline = microtime(true) + $seconds;
	do {
		if ($ready()) {
			return true;
		}
		usleep(100000);
	} while (microtime(true) < $deadline);
	return false;
}

function dropFixture(PDO $pdo, string $database): void
{
	if (!preg_match('/\Aadelpos_license_test_[a-f0-9]{10}\z/', $database)) {
		throw new RuntimeException('Nombre de base inesperado.');
	}
	$pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
	$pdo->exec("DROP USER IF EXISTS '" . $database . "'@'localhost'");
	$keys = sys_get_temp_dir() . '/' . $database . '-keys';
	foreach (glob($keys . '/*') ?: [] as $file) {
		unlink($file);
	}
	if (is_dir($keys)) {
		rmdir($keys);
	}
}

function panel(string $root, array $env, PDO $pdo, string $log): int
{
	if (@fsockopen('127.0.0.1', 8088)) {
		fwrite(STDERR, "El puerto 8088 está ocupado.\n");
		return 1;
	}
	$prepare = proc_open(
		[PHP_BINARY, 'tests/prepare-panel.php'],
		[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		$root,
		$env,
	);
	$out = (string) stream_get_contents($pipes[1]);
	$err = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($prepare) !== 0 || !preg_match('/Database: (adelpos_license_test_[a-f0-9]{10})/', $out, $match)) {
		fwrite(STDERR, "No se pudo preparar el panel:\n" . $err);
		return 1;
	}
	$config = '/private/tmp/adelpos-license-panel-config.php';
	$server = null;
	try {
		$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:8088', '-t', 'public', 'public/router.php'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
			$pipes,
			$root,
			$env + ['ADELPOS_LICENSE_CONFIG' => $config],
		);
		if (!waitFor(static fn() => (bool) @fsockopen('127.0.0.1', 8088), 10)) {
			fwrite(STDERR, "El servidor local no arrancó; revisa $log\n");
			return 1;
		}
		return proc_close(
			proc_open(
				['node', 'tests/panel.cjs'],
				[0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
				$pipes,
				$root,
				$env,
			),
		);
	} finally {
		if (is_resource($server)) {
			proc_terminate($server, 15);
			proc_close($server);
		}
		dropFixture($pdo, $match[1]);
		if (is_file($config)) {
			unlink($config);
		}
	}
}

function trusted(string $root, array $env, string $log): int
{
	$prepare = proc_open(
		[PHP_BINARY, 'tests/prepare-trusted-panel.php'],
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']],
		$pipes,
		$root,
		$env,
	);
	$origin = null;
	while (($line = fgets($pipes[1])) !== false) {
		if (preg_match('/TRUST_PANEL_ORIGIN=(https:\/\/127\.0\.0\.1:\d+)/', $line, $match)) {
			$origin = $match[1];
			break;
		}
	}
	try {
		if ($origin === null) {
			fwrite(STDERR, "No se pudo levantar la pila HTTPS; revisa $log\n");
			return 1;
		}
		return proc_close(
			proc_open(
				['node', 'tests/trusted-devices-panel.cjs'],
				[0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
				$nodePipes,
				$root,
				$env + ['TRUST_PANEL_ORIGIN' => $origin],
			),
		);
	} finally {
		fwrite($pipes[0], "\n");
		fclose($pipes[0]);
		stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($prepare);
	}
}

$status = 0;
if ($mode !== 'trusted') {
	$status |= panel($root, $env, $pdo, $log);
}
if ($mode !== 'panel') {
	$status |= trusted($root, $env, $log);
}
// El registro del servidor local solo sirve para diagnosticar una falla: si todo pasó, se borra.
if ($status === 0 && is_file($log)) {
	unlink($log);
}
fwrite(
	STDOUT,
	$status === 0
		? "Pruebas del panel: OK. Datos sintéticos eliminados.\n"
		: "Pruebas del panel: con fallas. Datos sintéticos eliminados; registro: $log\n",
);
exit($status === 0 ? 0 : 1);
