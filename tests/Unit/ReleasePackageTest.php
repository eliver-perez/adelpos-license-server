<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Tests\NativeStackFixture;
use PHPUnit\Framework\TestCase;

/**
 * bin/build-release.php on a throwaway repository holding the current versioned files,
 * with local files planted where a careless build would pick them up.
 */
final class ReleasePackageTest extends TestCase
{
	private const DIRECTORIES = ['bin', 'src', 'public', 'templates', 'database', 'deploy'];
	private const FILES = [
		'composer.json',
		'composer.lock',
		'config/local.example.php',
		'README.md',
		'ARCHITECTURE.md',
		'SCHEMA.md',
		'SECURITY.md',
		'OPERATIONS.md',
		'DEPLOY_UBUNTU_24_04.md',
		'TEST_PLAN.md',
		'DECISIONS.md',
		'docs/CONTRATO_LICENCIAS_ADEL_POS.md',
		// Del contrato, solo su forma publicada: el esquema de la licencia y la especificación de la API.
		'contracts/v1/license.schema.json',
		'contracts/v1/openapi.yaml',
	];
	private const PLANTED = [
		'config/local.php' => "<?php return [];\n",
		'var/keys/production/0123456789abcdef0123456789abcdef.json' => '{}',
		'var/backups/copia.adelposbackup' => 'synthetic',
		'public/.DS_Store' => 'synthetic',
		'bin/stray.key' => 'synthetic',
		'src/untracked-notes.php' => "<?php\n",
	];
	private ?string $directory = null;

	protected function setUp(): void
	{
		if (self::execute(['git', '-C', dirname(__DIR__, 2), 'rev-parse', '--verify', 'HEAD'])[0] !== 0) {
			self::markTestSkipped('Requires the project git repository.');
		}
		$this->directory = sys_get_temp_dir() . '/adelpos-license-release-' . bin2hex(random_bytes(6));
		mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		if ($this->directory !== null) {
			NativeStackFixture::remove($this->directory);
		}
	}

	public function testPackageHoldsExactlyTheVersionedReleaseFilesWithTheirChecksums(): void
	{
		$copy = $this->copy();
		$output = $this->directory . '/out/adelpos-license.tar.gz';
		mkdir(dirname($output), 0700);
		$summary = json_decode(
			NativeStackFixture::command([PHP_BINARY, $copy . '/bin/build-release.php', '--output=' . $output], $copy),
			true,
			8,
			JSON_THROW_ON_ERROR,
		);
		self::assertFalse($summary['vendor_included']);
		self::assertSame(hash_file('sha256', $output), $summary['sha256']);
		self::assertSame($summary['sha256'] . "  adelpos-license.tar.gz\n", file_get_contents($output . '.sha256'));

		$prefix = 'phar://' . $output . '/';
		$entries = [];
		foreach (new \RecursiveIteratorIterator(new \PharData($output)) as $entry) {
			$entries[] = (string) preg_replace('#\Aphar://.*?\.tar\.gz/#', '', $entry->getPathname());
		}
		sort($entries);
		[, $versioned] = self::execute(['git', '-C', $copy, 'ls-files', '-z', '--', ...self::DIRECTORIES]);
		$expected = array_merge(array_filter(explode("\0", $versioned)), self::FILES);
		$expected = array_map(static fn(string $file): string => 'adelpos-license/' . $file, [
			...$expected,
			'RELEASE.json',
		]);
		sort($expected);
		self::assertSame($expected, $entries);
		self::assertSame(count($expected) - 1, $summary['files']);

		foreach ($entries as $entry) {
			self::assertDoesNotMatchRegularExpression(
				'#\Aadelpos-license/(?:config/local\.php|var/|tests/|vendor/|node_modules/' .
					'|contracts/v1/(?:fixtures|verify|generate-fixtures|README)' .
					'|\.phpunit\.cache/|AVANCE\.md)|\.DS_Store|untracked-notes|\.(?:key|pem|adelposbackup|lic|licreq|go)\z#',
				$entry,
			);
		}
		// Ni archivos de prueba ni llaves de prueba: del contrato van exactamente dos archivos.
		self::assertSame(
			['adelpos-license/contracts/v1/license.schema.json', 'adelpos-license/contracts/v1/openapi.yaml'],
			array_values(array_filter($entries, fn(string $entry) => str_contains($entry, '/contracts/'))),
		);
		foreach (
			[
				'bin/check-secrets.php',
				'public/index.php',
				'public/assets/brand/adel-pos-tema-claro.png',
				'database/migrations/001_esquema_inicial.sql',
				'deploy/adelpos-license-maintenance.service',
				'config/local.example.php',
			]
			as $required
		) {
			self::assertContains('adelpos-license/' . $required, $entries);
		}

		$manifest = json_decode(
			(string) file_get_contents($prefix . 'adelpos-license/RELEASE.json'),
			true,
			8,
			JSON_THROW_ON_ERROR,
		);
		self::assertSame(count($expected) - 1, count($manifest['files_sha256']));
		foreach ($manifest['files_sha256'] as $file => $sha256) {
			self::assertSame(hash_file('sha256', $copy . '/' . $file), $sha256, $file);
			self::assertSame(
				$sha256,
				hash('sha256', (string) file_get_contents($prefix . 'adelpos-license/' . $file)),
				$file,
			);
		}
	}

	public function testForcedVersioningOfAKeyAbortsTheBuildWithoutLeavingFiles(): void
	{
		$copy = $this->copy();
		self::assertSame(0, self::execute(['git', '-C', $copy, 'add', '-f', '--', 'bin/stray.key'])[0]);
		$output = $this->directory . '/out/adelpos-license.tar.gz';
		mkdir(dirname($output), 0700);
		[$status] = self::execute([PHP_BINARY, $copy . '/bin/build-release.php', '--output=' . $output], $copy);
		self::assertSame(1, $status);
		self::assertSame([], glob(dirname($output) . '/*') ?: []);
	}

	/** Current versioned files in a new repository, vendor linked, and the planted local files. */
	private function copy(): string
	{
		$root = dirname(__DIR__, 2);
		$copy = $this->directory . '/repo';
		[, $listed] = self::execute(['git', '-C', $root, 'ls-files', '-z']);
		foreach (array_filter(explode("\0", $listed)) as $file) {
			if (!is_file($root . '/' . $file)) {
				continue;
			}
			if (!is_dir(dirname($copy . '/' . $file))) {
				mkdir(dirname($copy . '/' . $file), 0700, true);
			}
			copy($root . '/' . $file, $copy . '/' . $file);
		}
		self::assertSame(0, self::execute(['git', '-C', $copy, 'init', '-q', '.'])[0]);
		self::assertSame(0, self::execute(['git', '-C', $copy, 'add', '-A'])[0]);
		symlink($root . '/vendor', $copy . '/vendor');
		foreach (self::PLANTED as $file => $contents) {
			if (!is_dir(dirname($copy . '/' . $file))) {
				mkdir(dirname($copy . '/' . $file), 0700, true);
			}
			file_put_contents($copy . '/' . $file, $contents);
		}
		return $copy;
	}

	/** @return array{int,string} */
	private static function execute(array $command, ?string $cwd = null): array
	{
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
		fclose($pipes[0]);
		$output = (string) stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}
}
