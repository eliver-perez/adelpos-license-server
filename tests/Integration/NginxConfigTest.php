<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Tests\{MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

/**
 * La configuración de Nginx del despliegue (deploy/nginx.conf.example; etapa 5, Fase 5), con Nginx, PHP-FPM y
 * TLS reales: límites de intentos con el formato de error del contrato, archivos ocultos, redirección de HTTP a
 * HTTPS y nombres propios en el contexto http, que conviven con los de AIDEX en el mismo Nginx.
 */
final class NginxConfigTest extends TestCase
{
	private MySqlFixture $fixture;
	private NativeStackFixture $stack;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8, Nginx and PHP-FPM.');
		}
		$this->fixture = new MySqlFixture();
		$this->stack = new NativeStackFixture($this->fixture);
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

	public function testApiRateLimitAnswersWithTheContractErrorFormat(): void
	{
		// 60 por minuto con ráfagas de 20: la petición 22 seguida ya no llega a la aplicación, cuyo propio límite
		// (30 por minuto) no se alcanza.
		$statuses = [];
		$limited = null;
		$body = '{"action":"activate","product_id":"adel_pos","server_id":"x","activation_id":null}';
		for ($i = 0; $i < 30; ++$i) {
			$response = $this->stack->request('/v1/activations/challenge', $body);
			$statuses[] = $response['status'];
			if ($response['status'] === 429 && $limited === null) {
				$limited = $response;
			}
		}
		self::assertNotNull($limited, implode(',', $statuses));
		self::assertSame(400, $statuses[0]);
		self::assertSame(
			'{"error":{"code":"RATE_LIMITED","message":"Demasiadas solicitudes desde esta dirección. Intenta de ' .
				'nuevo en un minuto.","request_id":null,"details":{}}}',
			$limited['body'],
		);
		self::assertSame(
			['code' => 'RATE_LIMITED', 'request_id' => null, 'details' => []],
			array_intersect_key($limited['json']['error'], array_flip(['code', 'request_id', 'details'])),
		);
		self::assertStringContainsString('content-type: application/json', $limited['headers']);
		self::assertStringContainsString('retry-after: 60', $limited['headers']);
		self::assertStringContainsString('strict-transport-security: max-age=31536000', $limited['headers']);
		self::assertStringContainsString('x-content-type-options: nosniff', $limited['headers']);
		self::assertStringContainsString('cache-control: no-store', $limited['headers']);
		self::assertMatchesRegularExpression(
			'/x-request-id: [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
			$limited['headers'],
		);
		// La aplicación no recibió las rechazadas: ni un solo 429 suyo.
		self::assertStringNotContainsString('Intenta nuevamente más tarde', $limited['body']);
		// Un cuerpo de más de 16 KiB también responde con el formato del contrato.
		sleep(2);
		$large = $this->stack->request('/v1/activations', str_repeat('x', 17 * 1024));
		self::assertSame(413, $large['status']);
		self::assertSame(
			['code' => 'INVALID_REQUEST', 'request_id' => null, 'details' => []],
			array_intersect_key($large['json']['error'], array_flip(['code', 'request_id', 'details'])),
		);
	}

	public function testLoginAttemptsAreLimitedButOpeningThePageIsNot(): void
	{
		// Abrir la página no gasta intentos.
		for ($i = 0; $i < 12; ++$i) {
			self::assertNotSame(429, $this->stack->request('/login', '', 'GET')['status']);
		}
		// 10 envíos por minuto con ráfagas de 5: el séptimo seguido lo detiene Nginx.
		$statuses = [];
		$limited = null;
		for ($i = 0; $i < 8; ++$i) {
			$response = $this->stack->request('/login', 'csrf=x', 'POST', [
				'Content-Type: application/x-www-form-urlencoded',
			]);
			$statuses[] = $response['status'];
			if ($response['status'] === 429 && $limited === null) {
				$limited = $response;
			}
		}
		self::assertNotNull($limited, implode(',', $statuses));
		self::assertSame(403, $statuses[0]);
		self::assertStringContainsString(
			'Demasiados intentos de inicio de sesión desde esta dirección',
			$limited['body'],
		);
		self::assertStringContainsString('retry-after: 60', $limited['headers']);
		self::assertStringContainsString('strict-transport-security: max-age=31536000', $limited['headers']);
	}

	public function testHiddenFilesAndScriptsInsidePublicAreNotServed(): void
	{
		// public/.htaccess existe en el repositorio: un archivo oculto responde 404, no 403.
		self::assertFileExists(dirname(__DIR__, 2) . '/public/.htaccess');
		foreach (['/.htaccess', '/.env', '/.git/config', '/assets/.oculto', '/router.php', '/index.php'] as $path) {
			$response = $this->stack->request($path, '', 'GET');
			self::assertSame(404, $response['status'], $path);
			self::assertStringContainsString('strict-transport-security: max-age=31536000', $response['headers']);
		}
		self::assertSame(200, $this->stack->request('/assets/app.css', '', 'GET')['status']);
	}

	public function testHttpRedirectsToHttpsAndServesTheCertbotChallenge(): void
	{
		$get = function (string $path): array {
			$stream = fopen(
				$this->stack->httpOrigin . $path,
				'r',
				false,
				stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]),
			);
			$body = (string) stream_get_contents($stream);
			$headers = strtolower(implode("\n", stream_get_meta_data($stream)['wrapper_data']));
			fclose($stream);
			return [$headers, $body];
		};
		[$headers] = $get('/admin/licenses?q=1');
		self::assertStringContainsString('http/1.1 301', $headers);
		self::assertStringContainsString('location: https://127.0.0.1/admin/licenses?q=1', $headers);
		self::assertStringNotContainsString('server: nginx/', $headers);
		// La validación de Certbot se sirve por HTTP desde su propia carpeta.
		mkdir($this->stack->directory . '/letsencrypt/.well-known/acme-challenge', 0700, true);
		file_put_contents(
			$this->stack->directory . '/letsencrypt/.well-known/acme-challenge/prueba',
			'token-de-prueba',
		);
		[$headers, $body] = $get('/.well-known/acme-challenge/prueba');
		self::assertStringContainsString('http/1.1 200', $headers);
		self::assertSame('token-de-prueba', $body);
	}

	/**
	 * Lo declarado en el contexto http es global: los nombres del sitio de AD-EL POS no chocan con los de AIDEX,
	 * y «nginx -t» acepta los dos sitios juntos. Con un nombre repetido, falla (por eso los nombres son propios).
	 */
	public function testNamesInTheHttpContextDoNotCollideWithAidex(): void
	{
		// «nginx -t» también abre los puertos: se detiene la pila y el sitio de AIDEX usa un puerto libre.
		$this->stack->stop();
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$aidexAddress = stream_socket_get_name($socket, false);
		fclose($socket);
		$configuration = file_get_contents($this->stack->directory . '/nginx.conf');
		$test = function (string $aidex) use ($configuration): bool {
			$combined = preg_replace('/\n}\n\z/', "\n" . $aidex . "\n}\n", $configuration);
			$this->stack->privateFile('nginx-aidex.conf', $combined);
			try {
				NativeStackFixture::command([
					getenv('TEST_NGINX_BIN') ?: 'nginx',
					'-t',
					'-p',
					$this->stack->directory . '/',
					'-c',
					$this->stack->directory . '/nginx-aidex.conf',
				]);
				return true;
			} catch (\RuntimeException) {
				return false;
			}
		};
		$aidex = static fn(string $variable, string $zone): string => "map \$request_id \${$variable} {\n" .
			"    default \"00000000-0000-4000-8000-000000000000\";\n}\n" .
			"limit_req_zone \$binary_remote_addr zone={$zone}:10m rate=30r/m;\n" .
			"server {\n    listen {$aidexAddress};\n    server_name licensing.aidex.example;\n" .
			"    location / {\n        limit_req zone={$zone};\n        return 204;\n    }\n}";
		self::assertTrue($test($aidex('aidex_correlation_id', 'aidex_api')));
		// El control negativo: una zona de AD-EL POS repetida en otro sitio hace fallar «nginx -t». Una variable
		// de map repetida, en cambio, NO lo hace fallar: una definición sustituye a la otra sin aviso (comprobado
		// con Nginx 1.31.3). Por eso la prueba de abajo exige el prefijo propio en todo lo declarado en el contexto http.
		self::assertFalse($test($aidex('aidex_correlation_id', 'adelpos_api')));
		self::assertTrue($test($aidex('adelpos_correlation_id', 'aidex_api')));
	}

	public function testEverythingDeclaredInTheHttpContextUsesTheAdelposPrefix(): void
	{
		$example = file_get_contents(dirname(__DIR__, 2) . '/deploy/nginx.conf.example');
		// Lo que queda fuera de los bloques server{} es lo global.
		$outside = '';
		$depth = 0;
		foreach (preg_split('/(?<=[{};])/', preg_replace('/#.*$/m', '', $example)) as $piece) {
			$opens = substr_count($piece, '{');
			if ($depth === 0) {
				$outside .= $piece;
			}
			$depth += $opens - substr_count($piece, '}');
		}
		preg_match_all('/\bmap\s+\S+\s+\$(\w+)/', $outside, $maps);
		preg_match_all('/\blimit_req_zone\s+\S+\s+zone=(\w+):/', $outside, $zones);
		self::assertSame(['adelpos_correlation_id', 'adelpos_login_limit_key'], $maps[1]);
		self::assertSame(['adelpos_api', 'adelpos_login'], $zones[1]);
		// Ninguna directiva nombra a AIDEX (los comentarios sí lo mencionan, para explicar la convivencia).
		self::assertStringNotContainsString('aidex', strtolower(preg_replace('/#.*$/m', '', $example)));
		// Compatible con Nginx 1.24.0: sin directivas posteriores (http2 como directiva llegó en 1.25.1).
		self::assertDoesNotMatchRegularExpression('/^\s*(http2|http3|quic_\w+)\s/m', $example);
		self::assertMatchesRegularExpression('/^\s*listen 443 ssl;$/m', $example);
		self::assertMatchesRegularExpression('/^\s*root \/srv\/adelpos-license\/app\/public;$/m', $example);
	}

	/** public/ solo contiene lo que se sirve: el punto de entrada, los recursos y el .htaccess de protección. */
	public function testPublicContainsOnlyWhatMayBeServed(): void
	{
		$root = dirname(__DIR__, 2);
		$process = proc_open(['git', '-C', $root, 'ls-files', '--', 'public'], [1 => ['pipe', 'w']], $pipes);
		$files = array_filter(explode("\n", (string) stream_get_contents($pipes[1])));
		fclose($pipes[1]);
		proc_close($process);
		$unexpected = array_values(
			array_filter(
				$files,
				static fn(string $file): bool => !in_array(
					$file,
					[
						'public/.htaccess',
						'public/index.php',
						'public/router.php',
						'public/assets/vendor/bootstrap/LICENSE',
					],
					true,
				) && !preg_match('~\Apublic/assets/[A-Za-z0-9/_.-]+\.(css|js|png|map)\z~', $file),
			),
		);
		self::assertSame([], $unexpected);
	}
}
