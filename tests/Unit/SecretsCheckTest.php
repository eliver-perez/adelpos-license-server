<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/check-secrets.php against throwaway git repositories. Secret-looking
 * fixtures are assembled from fragments so this file never trips the check.
 */
final class SecretsCheckTest extends TestCase
{
	private const PASSWORD = 'DB_PASS' . 'WORD';
	private const KEY_NAME = 'AUDIT' . '_KEY';
	private const VALUE = 'valor-sintetico-' . 'no-usar';
	private const PUBLIC_TEST_PREFIX = 'ADELPOS LICENSE PUBLIC TEST ONLY V1 ';
	private ?string $directory = null;

	protected function setUp(): void
	{
		if (!self::gitAvailable()) {
			self::markTestSkipped('Requires git.');
		}
		$this->directory = sys_get_temp_dir() . '/secrets-check-' . bin2hex(random_bytes(6));
		mkdir($this->directory, 0700);
		self::execute(['git', '-C', $this->directory, 'init', '-q', '.']);
	}

	protected function tearDown(): void
	{
		if ($this->directory !== null) {
			self::remove($this->directory);
		}
	}

	public function testCleanRepositoryWithPlaceholdersAndPublicTestSeedsPasses(): void
	{
		$this->write(
			'config/local.example.php',
			"<?php return ['" . self::PASSWORD . "' => '', '" . self::KEY_NAME . "' => '', 'DB_USER' => 'app'];\n",
			true,
		);
		$this->write('bin/tool.php', "<?php putenv('" . self::PASSWORD . "=' . \$secret);\n", true);
		$this->write(
			'deploy/env.example',
			self::PASSWORD . "=\n" . self::KEY_NAME . "=\"\"\nexport " . self::KEY_NAME . "=\$FROM_ENVIRONMENT\n",
			true,
		);
		$this->write(
			'contracts/vectors.json',
			json_encode(
				[
					'keys' => [
						[
							'name' => 'lic-test-v1-a',
							'seed_hex' => hash('sha256', self::PUBLIC_TEST_PREFIX . 'lic-test-v1-a'),
						],
					],
				],
				JSON_PRETTY_PRINT,
			),
			true,
		);
		[$status, $output, $error] = $this->check();
		self::assertSame(0, $status, $error);
		self::assertStringContainsString('sin hallazgos en 4 archivos', $output);
	}

	#[DataProvider('secrets')]
	public function testSecretIsReportedWithoutPrintingItsValue(
		string $path,
		string $contents,
		bool $stage,
		string $reason,
	): void {
		$this->write($path, $contents, $stage);
		[$status, , $error] = $this->check();
		self::assertSame(1, $status);
		self::assertStringContainsString($path . ':', $error);
		self::assertStringContainsString($reason, $error);
		self::assertStringNotContainsString(self::VALUE, $error);
	}

	public static function secrets(): iterable
	{
		$base64url = static fn(int $bytes): string => rtrim(
			strtr(base64_encode(random_bytes($bytes)), '+/', '-_'),
			'=',
		);
		$pem = '-----BEGIN ' . 'EC PRIVATE KEY-----' . "\nMHcCAQEE\n" . '-----END ' . 'EC PRIVATE KEY-----' . "\n";
		yield 'PHP config password' => [
			'config/local.php',
			"<?php return ['" . self::PASSWORD . "' => '" . self::VALUE . "'];\n",
			true,
			'clave privada ' . self::PASSWORD,
		];
		yield 'PHP config key' => [
			'config/other.php',
			"<?php return ['" . self::KEY_NAME . "' => '" . self::VALUE . "'];\n",
			true,
			'clave privada ' . self::KEY_NAME,
		];
		yield 'putenv literal' => [
			'bin/tool.php',
			"<?php putenv('" . self::PASSWORD . '=' . self::VALUE . "');\n",
			true,
			'clave privada ' . self::PASSWORD,
		];
		yield 'env file' => [
			'deploy/app.env',
			'export ' . self::KEY_NAME . '=' . self::VALUE . "\n",
			true,
			'clave privada ' . self::KEY_NAME,
		];
		yield 'JSON config' => [
			'config/app.json',
			json_encode([self::PASSWORD => self::VALUE]),
			true,
			'clave privada ' . self::PASSWORD,
		];
		yield 'untracked file' => [
			'notes/secret.env',
			self::PASSWORD . '=' . self::VALUE . "\n",
			false,
			'clave privada ' . self::PASSWORD,
		];
		yield 'PEM private key' => ['docs/key.txt', $pem, true, 'llave privada PEM'];
		yield 'Ed25519 secret key' => [
			'keys/production.json',
			json_encode(['kid' => 'k1', 'secret_key' => $base64url(64)]),
			true,
			'material de llave privada Ed25519',
		];
		yield 'Ed25519 unknown seed' => [
			'contracts/vectors.json',
			json_encode(['keys' => [['name' => 'lic-test-v1-a', 'seed_hex' => bin2hex(random_bytes(32))]]]),
			true,
			'material de llave privada Ed25519',
		];
		yield 'file under var/keys' => ['var/keys/production/abc.json', '{}', true, 'archivo bajo var/keys/'];
		yield 'PEM file' => ['tls/server.pem', 'x', true, 'archivo de llave (.key o .pem)'];
	}

	public function testStagedContentIsCheckedEvenWhenTheWorkingCopyIsClean(): void
	{
		$this->write('config/local.php', "<?php return ['" . self::PASSWORD . "' => '" . self::VALUE . "'];\n", true);
		file_put_contents($this->directory . '/config/local.php', "<?php return ['" . self::PASSWORD . "' => ''];\n");
		[$status, , $error] = $this->check();
		self::assertSame(1, $status);
		self::assertStringContainsString(
			'config/local.php:1: valor no vacío en la clave privada ' . self::PASSWORD,
			$error,
		);
	}

	public function testProjectRepositoryHasNoSecrets(): void
	{
		$root = dirname(__DIR__, 2);
		if (self::execute(['git', '-C', $root, 'rev-parse', '--show-toplevel'])[0] !== 0) {
			self::markTestSkipped('The project is not a git repository.');
		}
		[$status, $output, $error] = self::execute([PHP_BINARY, $root . '/bin/check-secrets.php', '--root=' . $root]);
		self::assertSame(0, $status, $error);
		self::assertStringContainsString('sin hallazgos', $output);
	}

	private function write(string $path, string $contents, bool $stage): void
	{
		$file = $this->directory . '/' . $path;
		if (!is_dir(dirname($file))) {
			mkdir(dirname($file), 0700, true);
		}
		file_put_contents($file, $contents);
		if ($stage) {
			self::assertSame(0, self::execute(['git', '-C', $this->directory, 'add', '--', $path])[0]);
		}
	}

	/** @return array{int,string,string} */
	private function check(): array
	{
		return self::execute([
			PHP_BINARY,
			dirname(__DIR__, 2) . '/bin/check-secrets.php',
			'--root=' . $this->directory,
		]);
	}

	/** @return array{int,string,string} */
	private static function execute(array $command): array
	{
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		fclose($pipes[0]);
		$output = (string) stream_get_contents($pipes[1]);
		$error = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output, $error];
	}

	private static function gitAvailable(): bool
	{
		return self::execute(['git', '--version'])[0] === 0;
	}

	private static function remove(string $directory): void
	{
		foreach (
			new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST,
			)
			as $entry
		) {
			$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($directory);
	}
}
