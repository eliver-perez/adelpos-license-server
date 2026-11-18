<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\{App, Config};
use AdelPos\License\Infrastructure\{DeploymentCheck, RuntimeGrants};
use AdelPos\License\Tests\{MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

/**
 * La verificación del despliegue (bin/verify-deployment.php; etapa 5, Fase 7) contra una instalación real: Nginx
 * con deploy/nginx.conf.example, PHP-FPM, TLS y MySQL con la cuenta de la aplicación y sus permisos exactos. La
 * disposición de carpetas es la de la guía, en una carpeta temporal; systemd es un programa de prueba que
 * responde lo que cada caso necesita.
 */
final class DeploymentCheckTest extends TestCase
{
	private MySqlFixture $fixture;
	private NativeStackFixture $stack;
	private string $user;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8, Nginx and PHP-FPM.');
		}
		$this->fixture = new MySqlFixture();
		$this->stack = new NativeStackFixture($this->fixture);
		$this->user = (string) posix_getpwuid(posix_geteuid())['name'];
		$keys = $this->fixture->signingKeys();
		$keys->generate('adelpos-test-202611-1', 'license');
		$keys->activate('adelpos-test-202611-1', 'Verificación del despliegue');
		// Código desplegado: carpetas 0755 y archivos 0644, como los deja el clon de root.
		$code = $this->stack->directory . '/code';
		mkdir($code . '/bin', 0755, true);
		chmod($code, 0755);
		chmod($code . '/bin', 0755);
		file_put_contents($code . '/bin/migrate.php', "<?php\n");
		chmod($code . '/bin/migrate.php', 0644);
		// Respaldos con su clave de recuperación.
		mkdir($this->stack->directory . '/backup', 0700);
		$this->stack->privateFile('backup/recovery.key', 'clave de prueba');
		// systemd de prueba: responde el contenido de systemd/<unidad>; sin archivo, falla como sin systemd.
		mkdir($this->stack->directory . '/systemd', 0700);
		$this->stack->privateFile('systemctl', "#!/bin/sh\ncat \"\$(dirname \"\$0\")/systemd/\$2\"\n");
		chmod($this->stack->directory . '/systemctl', 0700);
		$this->systemd('LoadState=loaded', 'ActiveState=active', 'UnitFileState=enabled');
		$this->service(3, '0');
	}

	protected function tearDown(): void
	{
		if (isset($this->stack)) {
			$this->stack->destroy();
		}
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	public function testAHealthyDeploymentPassesEveryCheckWithoutWritingAnything(): void
	{
		$before = $this->checksums();
		$results = $this->check()->run();
		self::assertSame($before, $this->checksums(), 'La verificación no escribe en la base.');
		$statuses = [];
		foreach ($results as $result) {
			$statuses[$result['key']][] = $result['status'];
		}
		self::assertSame(
			[
				'php',
				'extensions',
				'config',
				'shared_log',
				'shared_tmp',
				'keys_dir',
				'key',
				'code',
				'backup_dir',
				'recovery_key',
				'database',
				'grants',
				'migrations',
				'signing',
				'timer',
				'certificate',
				'redirect',
				'hidden',
				'api',
				'headers',
			],
			array_keys($statuses),
		);
		foreach ($statuses as $key => $list) {
			// El certificado de la pila de prueba dura dos días: se advierte que vence pronto.
			self::assertSame(
				array_fill(0, count($list), $key === 'certificate' ? DeploymentCheck::WARNING : DeploymentCheck::OK),
				$list,
				$key . ': ' . json_encode($this->find($results, $key), JSON_UNESCAPED_UNICODE),
			);
		}
		self::assertFalse(DeploymentCheck::failed($results));
		$certificate = $this->find($results, 'certificate')[0];
		// Dura exactamente dos días: según si ya pasó un segundo desde que se creó, quedan uno o dos completos.
		self::assertMatchesRegularExpression(
			'/\AVálido para 127\.0\.0\.1; vence en (?:1 día|2 días)\.\z/',
			$certificate['detail'],
		);
		self::assertStringContainsString('sudo certbot renew --cert-name 127.0.0.1', $certificate['fix']);
		self::assertSame('Última ejecución correcta hace 3 horas.', $this->find($results, 'timer')[0]['detail']);
		self::assertSame('«adelpos-test-202611-1», del entorno «test».', $this->find($results, 'signing')[0]['detail']);
		self::assertSame('301 a https://127.0.0.1/login.', $this->find($results, 'redirect')[0]['detail']);
		foreach ($results as $result) {
			self::assertSame($result['status'] === DeploymentCheck::OK, $result['fix'] === '', $result['key']);
		}
	}

	public function testPermissionProblemsAreErrorsWithWhatToDo(): void
	{
		$config = $this->stack->directory . '/config.php';
		$keys = $this->fixture->values['SIGNING_KEY_DIR'];
		$code = $this->stack->directory . '/code';
		$backup = $this->stack->directory . '/backup';
		chmod($config, 0640);
		chmod($keys, 0750);
		chmod($code . '/bin/migrate.php', 0664);
		chmod($backup, 0750);
		unlink($backup . '/recovery.key');
		chmod($this->stack->directory . '/tmp', 0755);
		$results = $this->check()->run();
		self::assertTrue(DeploymentCheck::failed($results));
		$config = $this->only($results, 'config', DeploymentCheck::ERROR);
		self::assertStringContainsString(
			'es 0640 de ' . $this->user . '; debe ser 0600 de ' . $this->user,
			$config['detail'],
		);
		self::assertStringContainsString('chmod 600 ', $config['fix']);
		self::assertStringContainsString(
			'debe ser 0700',
			$this->only($results, 'keys_dir', DeploymentCheck::ERROR)['detail'],
		);
		$wrong = $this->only($results, 'code', DeploymentCheck::ERROR);
		self::assertStringContainsString('1 archivos o carpetas', $wrong['detail']);
		self::assertStringContainsString($code . '/bin/migrate.php', $wrong['detail']);
		self::assertStringContainsString('chmod -R go-w', $wrong['fix']);
		self::assertStringContainsString(
			'debe ser 0700',
			$this->only($results, 'backup_dir', DeploymentCheck::ERROR)['detail'],
		);
		self::assertStringContainsString(
			'No existe',
			$this->only($results, 'recovery_key', DeploymentCheck::ERROR)['detail'],
		);
		self::assertStringContainsString(
			'debe ser 0700',
			$this->only($results, 'shared_tmp', DeploymentCheck::ERROR)['detail'],
		);
		self::assertSame(DeploymentCheck::OK, $this->only($results, 'shared_log')['status']);
		// El código y los respaldos son de root: con otro dueño, también es un error.
		chmod($code . '/bin/migrate.php', 0644);
		chmod($backup, 0700);
		$results = $this->check(['admin_user' => 'root'])->run();
		self::assertStringContainsString(
			'no son de root',
			$this->only($results, 'code', DeploymentCheck::ERROR)['detail'],
		);
		self::assertStringContainsString(
			'es 0700 de ' . $this->user . '; debe ser 0700 de root.',
			$this->only($results, 'backup_dir', DeploymentCheck::ERROR)['detail'],
		);
		// La configuración y las llaves, dentro del código, se perderían o cambiarían con cada versión.
		$results = $this->check(['code' => $this->stack->directory])->run();
		self::assertContains(
			'Pasos 2 y 5: va en /srv/adelpos-license/shared.',
			array_column($this->find($results, 'config'), 'fix'),
		);
		// Sin carpeta de llaves configurada, la aplicación las guardaría dentro del código.
		$results = (new DeploymentCheck(
			new Config(array_replace($this->stack->values, ['SIGNING_KEY_DIR' => '']), false),
			fn(): App => $this->stack->app,
			$this->options(),
		))->run();
		self::assertStringContainsString(
			'SIGNING_KEY_DIR está vacío',
			$this->only($results, 'keys_dir', DeploymentCheck::ERROR)['detail'],
		);
		// Sin sudo no se puede entrar a la carpeta de respaldos: se advierte en lugar de suponer.
		chmod($backup, 0);
		try {
			$results = $this->check()->run();
		} finally {
			chmod($backup, 0700);
		}
		self::assertSame(
			'Ejecuta esta verificación con sudo.',
			$this->only($results, 'recovery_key', DeploymentCheck::WARNING)['fix'],
		);
	}

	public function testExtraOrMissingPrivilegesAndOtherDatabaseProblemsAreErrors(): void
	{
		$owner = $this->fixture->owner;
		$account = "'" . $this->fixture->username . "'@'localhost'";
		$owner->execute('GRANT DELETE ON `' . $this->fixture->database . '`.`audit_events` TO ' . $account);
		$owner->execute('REVOKE INSERT ON `' . $this->fixture->database . '`.`license_documents` FROM ' . $account);
		$owner->execute('GRANT SELECT ON `' . $this->fixture->database . '`.* TO ' . $account);
		$owner->execute("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE name='009_indice_de_cobros.sql'");
		$owner->execute("UPDATE schema_migrations SET state='pending' WHERE name='008_transferencias_y_alertas.sql'");
		$owner->execute("DELETE FROM schema_migrations WHERE name='007_cambios_programados.sql'");
		$owner->execute(
			"INSERT INTO schema_migrations(name,checksum,state,applied_at)
				SELECT '999_de_una_version_posterior.sql',checksum,state,applied_at FROM schema_migrations LIMIT 1",
		);
		$results = $this->check(['license_environment' => 'production'])->run();
		$grants = $this->only($results, 'grants', DeploymentCheck::ERROR)['detail'];
		self::assertStringContainsString('audit_events: sobra DELETE', $grants);
		self::assertStringContainsString('license_documents: falta INSERT', $grants);
		self::assertStringContainsString('tiene SELECT sobre ' . $this->fixture->database . '.*', $grants);
		$migrations = $this->only($results, 'migrations', DeploymentCheck::ERROR)['detail'];
		self::assertStringContainsString('009_indice_de_cobros.sql cambió después de aplicarse', $migrations);
		self::assertStringContainsString('008_transferencias_y_alertas.sql incompleta', $migrations);
		self::assertStringContainsString('007_cambios_programados.sql sin aplicar', $migrations);
		self::assertStringContainsString(
			'999_de_una_version_posterior.sql aplicada, pero este código no la tiene',
			$migrations,
		);
		self::assertStringContainsString(
			'firma en el entorno «test»; este sitio debe firmar en «production»',
			$this->only($results, 'signing', DeploymentCheck::ERROR)['detail'],
		);
		// Sin llave activa del entorno.
		$owner->execute("UPDATE signing_scopes SET active_kid=NULL WHERE purpose='license'");
		$signing = $this->only($this->check()->run(), 'signing', DeploymentCheck::ERROR);
		self::assertStringContainsString('No hay una llave activa del entorno «test»', $signing['detail']);
		// Sin conexión: un error que no muestra el mensaje de PDO y omite lo que necesita la base.
		$results = $this->check([], static function (): App {
			throw new \PDOException('SQLSTATE[HY000] [1045] Access denied for user secreto@localhost');
		})->run();
		$database = $this->only($results, 'database', DeploymentCheck::ERROR);
		self::assertSame('No se pudo cargar la configuración o conectar (PDOException).', $database['detail']);
		self::assertSame([], $this->find($results, 'grants'));
		self::assertSame([], $this->find($results, 'signing'));
	}

	public function testTheDailyTaskMustBeEnabledAndHaveRunRecentlyWithoutErrors(): void
	{
		$this->service(40, '0');
		self::assertSame(DeploymentCheck::WARNING, $this->timer()['status']);
		$this->service(2, '1');
		self::assertSame('La última ejecución terminó con el código 1.', $this->timer()['detail']);
		$this->service(null, '0');
		self::assertStringContainsString('todavía no ha corrido', $this->timer()['detail']);
		$this->stack->privateFile(
			'systemd/adelpos-license-maintenance.service',
			"ExecMainExitTimestamp=\nExecMainStatus=0\n",
		);
		self::assertStringContainsString('todavía no ha corrido', $this->timer()['detail']);
		$this->systemd('LoadState=loaded', 'ActiveState=active', 'UnitFileState=disabled');
		self::assertSame(DeploymentCheck::ERROR, $this->timer()['status']);
		$this->systemd('LoadState=loaded', 'ActiveState=inactive', 'UnitFileState=enabled');
		self::assertSame('El timer no está instalado, activo y habilitado.', $this->timer()['detail']);
		$this->systemd('LoadState=not-found', 'ActiveState=inactive', 'UnitFileState=');
		$timer = $this->timer();
		self::assertSame(DeploymentCheck::ERROR, $timer['status']);
		self::assertStringContainsString('systemctl enable --now adelpos-license-maintenance.timer', $timer['fix']);
		unlink($this->stack->directory . '/systemd/adelpos-license-maintenance.timer');
		$timer = $this->timer();
		self::assertSame(DeploymentCheck::WARNING, $timer['status']);
		self::assertSame('No se pudo consultar systemd.', $timer['detail']);
	}

	public function testAnUntrustedCertificateOrAWrongSiteIsAnError(): void
	{
		$results = $this->check(['ca_file' => ''])->run();
		self::assertStringContainsString(
			'No se pudo abrir una conexión TLS verificada',
			$this->only($results, 'certificate', DeploymentCheck::ERROR)['detail'],
		);
		self::assertSame(
			'/v1/activations/challenge no respondió.',
			$this->only($results, 'api', DeploymentCheck::ERROR)['detail'],
		);
		// Un sitio que sirve los ocultos, no redirige a HTTPS, no pone las cabeceras y no responde como el contrato.
		$port = stream_socket_server('tcp://127.0.0.1:0');
		$address = stream_socket_get_name($port, false);
		fclose($port);
		$this->stack->privateFile(
			'wrong-site.php',
			<<<'PHP'
			<?php
			[$mode, $path] = explode('/', ltrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'), 2) + ['', ''];
			if ($mode === 'version') {
				header('Strict-Transport-Security: max-age=31536000');
				header('X-Content-Type-Options: nosniff');
				header('Cache-Control: no-store');
				header('Server: nginx/1.24.0');
			}
			// «bad»: redirige con 301, pero a otro sitio por HTTP, y la API pone un request_id. «version»: redirige
			// al sitio correcto, pero con 302, y la API responde el formato correcto con otro código.
			if ($path === 'login' && $mode === 'bad') {
				header('Location: http://otro.example/login', true, 301);
			} elseif ($path === 'login') {
				header('Location: https://127.0.0.1/login', true, 302);
			} elseif ($path === 'v1/activations/challenge') {
				http_response_code($mode === 'bad' ? 415 : 400);
				header('Content-Type: application/json');
				$id = $mode === 'bad' ? '"abc"' : 'null';
				echo '{"error":{"code":"INVALID_REQUEST","message":"x","request_id":' . $id . ',"details":{}}}';
			} elseif ($path === '.htaccess') {
				echo 'Deny from all';
			} elseif ($path === 'assets/app.css') {
				header('Content-Type: text/css');
				echo 'body{}';
			}
			PHP
			,
		);
		$server = proc_open(
			[PHP_BINARY, '-S', $address, $this->stack->directory . '/wrong-site.php'],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		try {
			$deadline = microtime(true) + 5;
			while (!($connection = @stream_socket_client('tcp://' . $address)) && microtime(true) < $deadline) {
				usleep(20000);
			}
			if ($connection) {
				fclose($connection);
			}
			$results = $this->check([
				'url' => 'http://' . $address . '/bad',
				'http_url' => 'http://' . $address . '/bad',
			])->run();
			self::assertSame(
				'http://…/login respondió 301 a http://otro.example/login.',
				$this->only($results, 'redirect', DeploymentCheck::ERROR)['detail'],
			);
			self::assertSame(
				'/.htaccess respondió 200.',
				$this->only($results, 'hidden', DeploymentCheck::ERROR)['detail'],
			);
			self::assertSame(
				'/v1/activations/challenge respondió 415.',
				$this->only($results, 'api', DeploymentCheck::ERROR)['detail'],
			);
			$headers = $this->only($results, 'headers', DeploymentCheck::ERROR)['detail'];
			self::assertStringContainsString('strict-transport-security en /assets/app.css', $headers);
			self::assertStringContainsString('cache-control en /v1/', $headers);
			$results = $this->check([
				'url' => 'http://' . $address . '/version',
				'http_url' => 'http://' . $address . '/version',
			])->run();
			self::assertSame(
				'http://…/login respondió 302 a https://127.0.0.1/login.',
				$this->only($results, 'redirect', DeploymentCheck::ERROR)['detail'],
			);
			self::assertSame(
				'/v1/activations/challenge respondió 400.',
				$this->only($results, 'api', DeploymentCheck::ERROR)['detail'],
			);
			self::assertSame(
				'/assets/app.css respondió 200; faltan: server sin la versión de Nginx en /assets/app.css, ' .
					'server sin la versión de Nginx en /v1/.',
				$this->only($results, 'headers', DeploymentCheck::ERROR)['detail'],
			);
		} finally {
			proc_terminate($server);
			proc_close($server);
		}
	}

	public function testTheCommandPrintsASpanishSummaryAndFailsOnErrors(): void
	{
		[$status, $output] = $this->command([]);
		self::assertSame(0, $status, $output);
		self::assertStringContainsString(
			"Verificación del despliegue de AD-EL POS — Licencias en {$this->stack->origin}\n",
			$output,
		);
		self::assertMatchesRegularExpression(
			'/^OK {11}Permisos de la cuenta: Exactamente los de bin\/grants\.php\.$/m',
			$output,
		);
		self::assertMatchesRegularExpression(
			'/^Advertencia {2}Certificado HTTPS: Válido para 127\.0\.0\.1; vence en (?:1 día|2 días)\.$/m',
			$output,
		);
		self::assertMatchesRegularExpression(
			'/^ {13}Qué hacer: La renovación automática falló: sudo certbot renew/m',
			$output,
		);
		self::assertStringEndsWith("\nResumen: 19 OK, 1 advertencia y 0 errores. No se modificó nada.\n", $output);
		[$status, $output] = $this->command(['--license-environment=production']);
		self::assertSame(1, $status, $output);
		self::assertMatchesRegularExpression(
			'/^Error {8}Llave de firma activa: La configuración firma en el entorno «test»/m',
			$output,
		);
		self::assertStringEndsWith("\nResumen: 18 OK, 1 advertencia y 1 error. No se modificó nada.\n", $output);
		[$status, $output] = $this->command(['--url=http://127.0.0.1']);
		self::assertSame(1, $status);
		self::assertSame("La dirección del sitio debe ser https://dominio, sin ruta: http://127.0.0.1\n", $output);
	}

	private function check(array $options = [], ?\Closure $connect = null): DeploymentCheck
	{
		return new DeploymentCheck(
			new Config($this->stack->values, false),
			$connect ?? fn(): App => $this->stack->app,
			array_replace($this->options(), $options),
		);
	}

	private function options(): array
	{
		return [
			'config' => $this->stack->directory . '/config.php',
			'code' => $this->stack->directory . '/code',
			'app_user' => $this->user,
			'admin_user' => $this->user,
			'backup' => $this->stack->directory . '/backup',
			'license_environment' => 'test',
			'url' => $this->stack->origin,
			'http_url' => $this->stack->httpOrigin,
			'ca_file' => $this->stack->directory . '/tls.crt',
			'systemctl' => $this->stack->directory . '/systemctl',
		];
	}

	/** @return array{int, string} */
	private function command(array $arguments): array
	{
		$options = $this->options();
		$process = proc_open(
			[
				PHP_BINARY,
				dirname(__DIR__, 2) . '/bin/verify-deployment.php',
				'--app-user=' . $this->user,
				'--admin-user=' . $this->user,
				'--code-dir=' . $options['code'],
				'--backup-dir=' . $options['backup'],
				'--http-url=' . $options['http_url'],
				'--ca-file=' . $options['ca_file'],
				'--systemctl=' . $options['systemctl'],
				...$arguments,
			],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			['ADELPOS_LICENSE_CONFIG' => $options['config'], 'PATH' => (string) getenv('PATH')],
		);
		fclose($pipes[0]);
		$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}

	private function systemd(string ...$lines): void
	{
		$this->stack->privateFile('systemd/adelpos-license-maintenance.timer', implode("\n", $lines) . "\n");
	}

	private function service(?int $hoursAgo, string $status): void
	{
		$finished = $hoursAgo === null ? 'n/a' : gmdate('D Y-m-d H:i:s', time() - $hoursAgo * 3600 - 60) . ' UTC';
		$this->stack->privateFile(
			'systemd/adelpos-license-maintenance.service',
			'ExecMainExitTimestamp=' . $finished . "\nExecMainStatus=" . $status . "\n",
		);
	}

	private function timer(): array
	{
		return $this->only($this->check()->run(), 'timer');
	}

	/** @return list<array{key: string, status: string, title: string, detail: string, fix: string}> */
	private function find(array $results, string $key): array
	{
		return array_values(array_filter($results, static fn(array $result): bool => $result['key'] === $key));
	}

	/** El único resultado de $key; con $status, el único con ese estado. */
	private function only(array $results, string $key, ?string $status = null): array
	{
		$found = $this->find($results, $key);
		if ($status !== null) {
			$found = array_values(
				array_filter($found, static fn(array $result): bool => $result['status'] === $status),
			);
		}
		self::assertCount(1, $found, $key . ': ' . json_encode($this->find($results, $key), JSON_UNESCAPED_UNICODE));
		return $found[0];
	}

	/** @return array<string, string> */
	private function checksums(): array
	{
		$checksums = [];
		foreach (array_keys(RuntimeGrants::PRIVILEGES) as $table) {
			$checksums[$table] = (string) $this->fixture->owner->one('CHECKSUM TABLE `' . $table . '`')['Checksum'];
		}
		return $checksums;
	}
}
