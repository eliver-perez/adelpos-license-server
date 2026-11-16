<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Config;
use AdelPos\License\Domain\Problem;
use AdelPos\License\Infrastructure\{Clock, Totp};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionConfigTest extends TestCase
{
	private function config(string $environment, string $url, ?string $license = 'test'): Config
	{
		$values = ['APP_ENV' => $environment, 'APP_URL' => $url];
		if ($license !== null) {
			$values['LICENSE_ENVIRONMENT'] = $license;
		}
		foreach (['MFA_KEY', 'CREDENTIAL_KEY', 'AUDIT_KEY', 'SESSION_KEY', 'OPERATION_KEY'] as $name) {
			$values[$name] = base64_encode(random_bytes(32));
		}
		return new Config($values, false);
	}

	public static function invalidOrigins(): array
	{
		return array_map(fn($url) => [$url], [
			'http://licensing.sicrat.com',
			'http://127.0.0.1:8088',
			'https://user:secret@example.test',
			'https://example.test?debug=1',
			'https://example.test#fragment',
			'https://example.test/panel',
			"https://example.test\n",
			'https://bad host.test',
			'ftp://127.0.0.1',
		]);
	}

	#[DataProvider('invalidOrigins')]
	public function testProductionRejectsInvalidOrInsecureOrigins(string $url): void
	{
		$this->expectException(\RuntimeException::class);
		$this->config('production', $url)->validate();
	}

	public function testProductionAndLocalDevelopmentRemainSeparate(): void
	{
		$production = $this->config('production', 'https://licensing.sicrat.com');
		$production->validate();
		self::assertFalse($production->development());
		foreach (['development', 'testing'] as $environment) {
			foreach (['localhost', '127.0.0.1', '[::1]'] as $host) {
				$config = $this->config($environment, 'http://' . $host . ':8088');
				$config->validate();
				self::assertTrue($config->development());
			}
		}
	}

	public function testUnknownEnvironmentFailsBeforeDatabaseConnection(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->config('debug', 'https://example.test')->validate();
	}

	public function testLocalDevelopmentCannotUseOtherUrlSchemes(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->config('development', 'ftp://127.0.0.1')->validate();
	}

	public function testProductionRequiresRealApplicationKeys(): void
	{
		$this->expectException(\RuntimeException::class);
		try {
			(new Config(
				['APP_ENV' => 'production', 'LICENSE_ENVIRONMENT' => 'test', 'APP_URL' => 'https://example.test'],
				false,
			))->validate();
		} catch (\RuntimeException $error) {
			self::assertStringContainsString('MFA_KEY', $error->getMessage());
			throw $error;
		}
	}

	public function testLicenseEnvironmentIsMandatoryAndOnlyTestOrProduction(): void
	{
		foreach ([null, '', 'testing', 'development', 'prod', 'TEST', 'test '] as $value) {
			$config = $this->config('production', 'https://licensing.sicrat.com', $value);
			try {
				$config->validate();
				self::fail('Accepted license environment: ' . var_export($value, true));
			} catch (Problem $error) {
				self::assertStringStartsWith(
					in_array($value, [null, ''], true)
						? 'Falta LICENSE_ENVIRONMENT'
						: 'LICENSE_ENVIRONMENT tiene un valor no válido',
					$error->getMessage(),
				);
			}
			// No default: nothing is ever signed with an environment that was not chosen.
			try {
				$config->licenseEnvironment();
				self::fail('Defaulted license environment: ' . var_export($value, true));
			} catch (\RuntimeException) {
			}
		}
	}

	public function testLicenseEnvironmentIsIndependentFromTheApplicationEnvironment(): void
	{
		// licensing.sicrat.com: production security, test licenses.
		$testDomain = $this->config('production', 'https://licensing.sicrat.com', 'test');
		$testDomain->validate();
		self::assertFalse($testDomain->development());
		self::assertSame('test', $testDomain->licenseEnvironment());
		$production = $this->config('production', 'https://licencias.example.test', 'production');
		$production->validate();
		self::assertSame('production', $production->licenseEnvironment());
		foreach (['development', 'testing'] as $environment) {
			$local = $this->config($environment, 'http://127.0.0.1:8088', 'test');
			$local->validate();
			self::assertTrue($local->development());
			self::assertSame('test', $local->licenseEnvironment());
		}
	}

	public function testProductionLicensesRequireProductionApplicationSecurity(): void
	{
		foreach (['development', 'testing'] as $environment) {
			try {
				$this->config($environment, 'http://127.0.0.1:8088', 'production')->validate();
				self::fail('Production licenses accepted in ' . $environment);
			} catch (Problem $error) {
				self::assertStringContainsString('APP_ENV=production', $error->getMessage());
			}
		}
	}

	public function testExampleConfigurationAndConfiguratorAskForTheLicenseEnvironmentExplicitly(): void
	{
		$root = dirname(__DIR__, 2);
		$example = require $root . '/config/local.example.php';
		self::assertSame('https://licensing.sicrat.com', $example['APP_URL']);
		self::assertSame('production', $example['APP_ENV']);
		self::assertSame('test', $example['LICENSE_ENVIRONMENT']);
		$configure = static function (array $arguments) use ($root): array {
			$process = proc_open(
				[PHP_BINARY, $root . '/bin/configure.php', ...$arguments],
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes,
				$root,
			);
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			return [proc_close($process), $output . $error];
		};
		// Both stop before asking for a password or writing any file.
		$output = sys_get_temp_dir() . '/adelpos-license-config-' . bin2hex(random_bytes(6)) . '.php';
		$common = [
			'--env=production',
			'--url=https://licensing.sicrat.com',
			'--dsn=mysql:host=127.0.0.1;dbname=adelpos_license',
			'--user=adelpos_license_app',
			'--output=' . $output,
		];
		[$status, $message] = $configure($common);
		self::assertSame(1, $status);
		self::assertStringContainsString('--license-environment=test', $message);
		[$status, $message] = $configure([...$common, '--license-environment=testing']);
		self::assertSame(1, $status);
		self::assertStringContainsString('--license-environment debe ser test o production', $message);
		self::assertFileDoesNotExist($output);
	}

	public function testAuthenticatorUsesAdelPosWithoutChangingTotpSecret(): void
	{
		$uri = (new Totp(new Clock()))->provisioningUri('JBSWY3DPEHPK3PXP', 'admin@example.test');
		parse_str(parse_url($uri, PHP_URL_QUERY), $parameters);
		self::assertSame('AD-EL POS Licencias', $parameters['issuer']);
		self::assertSame('JBSWY3DPEHPK3PXP', $parameters['secret']);
	}
}
