<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\App;
use AdelPos\License\Domain\{Problem, Protocol};
use AdelPos\License\Infrastructure\SigningKeys;
use AdelPos\License\Tests\{FrozenClock, MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

/**
 * Contract section 3: license signing environments «test» and «production», independent
 * from APP_ENV. Every key, its registry scope and its folder belong to exactly one of them.
 */
final class SigningEnvironmentsTest extends TestCase
{
	private const PRODUCTION = [
		'APP_ENV' => 'production',
		'APP_URL' => 'https://licencias.example.test',
		'LICENSE_ENVIRONMENT' => 'production',
	];
	private MySqlFixture $fixture;
	private FrozenClock $clock;
	private string $directory;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-16T06:30:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$this->directory = sys_get_temp_dir() . '/adelpos-license-environments-' . bin2hex(random_bytes(6));
		mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		putenv('ADELPOS_LICENSE_CONFIG');
		if (isset($this->directory)) {
			NativeStackFixture::remove($this->directory);
		}
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function folder(string $environment): string
	{
		return $this->fixture->values['SIGNING_KEY_DIR'] . ($environment === 'test' ? '' : '-' . $environment);
	}

	private function test(): SigningKeys
	{
		return $this->fixture->signingKeys($this->clock);
	}

	/** Production licenses on the same database, with their own key folder. */
	private function production(array $overrides = []): SigningKeys
	{
		return $this->fixture->appWith(
			array_replace(self::PRODUCTION, ['SIGNING_KEY_DIR' => $this->folder('production')], $overrides),
			$this->clock,
		)->signingKeys;
	}

	private function activeTestKey(): string
	{
		$key = $this->test()->generate('', 'license');
		$this->test()->activate($key['kid'], 'Prueba aislada');
		return $key['kid'];
	}

	/** Private configuration file for a child process or for App::boot(). */
	private function configuration(array $overrides, bool $owner): array
	{
		$values = array_replace(
			$this->fixture->values,
			$owner
				? [
					'DB_USER' => getenv('TEST_MYSQL_USER') ?: 'root',
					'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
				]
				: [],
			$overrides,
		);
		$file = $this->directory . '/config-' . bin2hex(random_bytes(4)) . '.php';
		$mask = umask(0077);
		try {
			file_put_contents($file, '<?php return ' . var_export($values, true) . ';');
		} finally {
			umask($mask);
		}
		return [$file, $values];
	}

	/** @return array{int, string, string} Exit status, standard output and standard error. */
	private function cli(array $arguments, array $overrides = [], bool $owner = false): array
	{
		[$file, $values] = $this->configuration($overrides, $owner);
		$environment = array_diff_key(getenv(), $values);
		$environment['ADELPOS_LICENSE_CONFIG'] = $file;
		$process = proc_open(
			[PHP_BINARY, ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			dirname(__DIR__, 2),
			$environment,
		);
		fclose($pipes[0]);
		$output = (string) stream_get_contents($pipes[1]);
		$error = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output, $error];
	}

	private function boot(array $overrides = []): App
	{
		[$file] = $this->configuration($overrides, false);
		putenv('ADELPOS_LICENSE_CONFIG=' . $file);
		try {
			return App::boot();
		} finally {
			putenv('ADELPOS_LICENSE_CONFIG');
		}
	}

	/** Replaces a registered key, in its file and in the registry, with a public fixture seed. */
	private function plantFixtureSeed(SigningKeys $keys, string $kid, string $seed): void
	{
		$keys->generate($kid, 'license');
		$keys->activate($kid, 'Prueba aislada');
		$record = $this->fixture->owner->one('SELECT * FROM signing_keys WHERE kid=?', [$kid]);
		$pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'ADELPOS LICENSE PUBLIC TEST ONLY V1 ' . $seed, true));
		$public = sodium_crypto_sign_publickey($pair);
		$path = $keys->directory() . '/' . $record['private_ref'];
		$file = json_decode(file_get_contents($path), true);
		$file['public_key'] = Protocol::encode($public);
		$file['secret_key'] = Protocol::encode(sodium_crypto_sign_secretkey($pair));
		file_put_contents($path, Protocol::json($file));
		$this->fixture->owner->execute('UPDATE signing_keys SET public_key=? WHERE kid=?', [$public, $kid]);
	}

	public function testKeysAreRegisteredAndStoredByLicenseEnvironment(): void
	{
		$test = $this->test();
		self::assertSame('test', $test->environment());
		$first = $test->generate('', 'license');
		self::assertSame(
			['kid' => 'adelpos-test-202611-1', 'environment' => 'test', 'purpose' => 'license', 'state' => 'staged'],
			array_diff_key($first, ['public_key' => true]),
		);
		self::assertSame('adelpos-test-202611-2', $test->generate('', 'license')['kid']);
		self::assertSame('nombre-libre', $test->generate('nombre-libre', 'license')['kid']);
		$production = $this->production();
		self::assertSame('production', $production->environment());
		self::assertSame('adelpos-prod-202611-1', $production->generate('', 'license')['kid']);

		// An identifier never carries the label of the other environment, and only license keys get a suggested one.
		foreach (
			[
				[$test, 'adelpos-prod-202611-9', 'license'],
				[$production, 'adelpos-test-202611-9', 'license'],
				[$test, '', 'manifest'],
			]
			as [$keys, $kid, $purpose]
		) {
			try {
				$keys->generate($kid, $purpose);
				self::fail('Accepted key identifier: ' . $kid);
			} catch (Problem $error) {
				self::assertStringContainsString($kid === '' ? 'no válido' : 'otro entorno', $error->getMessage());
			}
		}

		$registered = $this->fixture->owner->all('SELECT kid,environment,private_ref FROM signing_keys ORDER BY kid');
		self::assertSame(
			[
				'adelpos-prod-202611-1' => 'production',
				'adelpos-test-202611-1' => 'test',
				'adelpos-test-202611-2' => 'test',
				'nombre-libre' => 'test',
			],
			array_column($registered, 'environment', 'kid'),
		);
		foreach ($registered as $key) {
			$path = $this->folder($key['environment']) . '/' . $key['private_ref'];
			self::assertFileExists($path);
			self::assertFileDoesNotExist(
				$this->folder($key['environment'] === 'test' ? 'production' : 'test') . '/' . $key['private_ref'],
			);
			self::assertSame(0600, fileperms($path) & 0777);
			self::assertSame($key['environment'], json_decode(file_get_contents($path), true)['environment']);
		}
		self::assertSame(
			[
				['environment' => 'production', 'purpose' => 'license'],
				['environment' => 'test', 'purpose' => 'license'],
			],
			$this->fixture->owner->all('SELECT environment,purpose FROM signing_scopes ORDER BY environment,purpose'),
		);

		// Without SIGNING_KEY_DIR every environment has its own folder: var/keys/<environment>.
		$root = dirname(__DIR__, 2);
		self::assertSame(
			$root . '/var/keys/test',
			$this->fixture->appWith(['SIGNING_KEY_DIR' => ''])->signingKeys->directory(),
		);
		self::assertSame(
			$root . '/var/keys/production',
			$this->fixture->appWith(self::PRODUCTION + ['SIGNING_KEY_DIR' => ''])->signingKeys->directory(),
		);

		// A folder that already holds keys of one environment cannot receive keys of the other.
		try {
			$this->production(['SIGNING_KEY_DIR' => $this->folder('test')])->generate('', 'license');
			self::fail('Shared key folder accepted');
		} catch (Problem $error) {
			self::assertStringContainsString('Cada entorno usa su propia carpeta', $error->getMessage());
		}
		self::assertSame(4, (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM signing_keys')->fetchColumn());
	}

	public function testServerSignsOnlyWithTheActiveKeyOfItsLicenseEnvironment(): void
	{
		$db = $this->fixture->owner;
		$kid = $this->activeTestKey();
		self::assertSame($kid, $db->transaction(fn() => $this->test()->sign(['prueba' => true]))['kid']);

		// The same database configured for production has no key of its own, and never falls back to a test key.
		$production = $this->production();
		try {
			$db->transaction(fn() => $production->sign(['prueba' => true]));
			self::fail('Signed production with a test key');
		} catch (\RuntimeException $error) {
			self::assertSame('No active license signing key.', $error->getMessage());
		}
		try {
			$production->activate($kid, 'Intento de usar una llave de prueba');
			self::fail('Activated a test key for production');
		} catch (Problem $error) {
			self::assertSame('La llave no existe en el entorno production.', $error->getMessage());
		}

		// The registry cannot point the production scope to a test key.
		$db->execute("INSERT INTO signing_scopes(environment,purpose) VALUES ('production','license')");
		try {
			$db->execute(
				"UPDATE signing_scopes SET active_kid=? WHERE environment='production' AND purpose='license'",
				[$kid],
			);
			self::fail('Crossed environments in the registry');
		} catch (\PDOException $error) {
			self::assertSame(1452, (int) $error->errorInfo[1]);
		}

		// Relabelling a registry row is not enough: its file is in the test folder, not in the production one...
		$relabelled = $this->test()->generate('', 'license');
		$reference = $db
			->execute('SELECT private_ref FROM signing_keys WHERE kid=?', [$relabelled['kid']])
			->fetchColumn();
		$db->execute("UPDATE signing_keys SET environment='production',state='signing' WHERE kid=?", [
			$relabelled['kid'],
		]);
		$db->execute("UPDATE signing_scopes SET active_kid=? WHERE environment='production' AND purpose='license'", [
			$relabelled['kid'],
		]);
		$attempt = function () use ($db, $production): string {
			try {
				$db->transaction(fn() => $production->sign(['prueba' => true]));
				self::fail('Signed production with a relabelled test key');
			} catch (\RuntimeException $error) {
				self::assertSame('License signing material unavailable.', $error->getMessage());
				return $error->getPrevious()->getMessage();
			}
		};
		self::assertSame('Signing keys must be outside public.', $attempt());
		// ...and even when copied to the production folder, the file itself says «test».
		mkdir($this->folder('production'), 0700);
		copy($this->folder('test') . '/' . $reference, $this->folder('production') . '/' . $reference);
		chmod($this->folder('production') . '/' . $reference, 0600);
		self::assertSame('Signing key metadata mismatch.', $attempt());

		// The test environment keeps signing with its own key.
		self::assertSame($kid, $db->transaction(fn() => $this->test()->sign(['prueba' => true]))['kid']);
		self::assertSame(0, (int) $db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
	}

	public function testServerDoesNotBootWhenItsActiveKeyBelongsToAnotherLicenseEnvironment(): void
	{
		$kid = $this->activeTestKey();
		self::assertSame('test', $this->boot()->signingKeys->environment());

		// The configuration now says production, but this database signs test licenses.
		try {
			$this->boot(self::PRODUCTION);
			self::fail('Booted with a key of another environment');
		} catch (Problem $error) {
			self::assertSame(503, $error->status);
			foreach ([$kid, 'es del entorno test', 'LICENSE_ENVIRONMENT es production'] as $text) {
				self::assertStringContainsString($text, $error->getMessage());
			}
		}
		// A missing or unknown license environment also stops the boot, before connecting to the database.
		foreach (['', 'testing'] as $value) {
			try {
				$this->boot([
					'LICENSE_ENVIRONMENT' => $value,
					'DB_DSN' => 'mysql:host=must-not-connect.invalid;dbname=unrelated',
				]);
				self::fail('Booted without a license environment');
			} catch (Problem $error) {
				self::assertStringContainsString('LICENSE_ENVIRONMENT', $error->getMessage());
			}
		}
		// Keys that were only prepared for another environment do not sign anything and do not stop the server.
		$this->production()->generate('', 'license');
		self::assertSame('test', $this->boot()->signingKeys->environment());
		$this->test()->assertEnvironment();
		try {
			$this->production()->assertEnvironment();
			self::fail('Production accepted a database with an active test key');
		} catch (Problem) {
		}
	}

	public function testPreflightDetectsLicenseEnvironmentMismatchAndSharedKeyFolders(): void
	{
		$kid = $this->activeTestKey();
		[$status, $output, $error] = $this->cli(['bin/preflight.php', '--require-signer']);
		self::assertSame(0, $status, $error);
		self::assertStringContainsString('Entorno de licencias: test.', $output);
		self::assertStringContainsString('Llave firmante de licencias del entorno test verificada.', $output);

		[$status, $output, $error] = $this->cli(
			['bin/preflight.php', '--require-signer'],
			self::PRODUCTION + ['SIGNING_KEY_DIR' => $this->folder('production')],
		);
		self::assertSame(1, $status);
		self::assertSame('', $output);
		foreach ([$kid, 'es del entorno test', 'LICENSE_ENVIRONMENT es production'] as $text) {
			self::assertStringContainsString($text, $error);
		}

		[$status, , $error] = $this->cli(['bin/preflight.php'], ['LICENSE_ENVIRONMENT' => '']);
		self::assertSame(1, $status);
		self::assertStringContainsString('Falta LICENSE_ENVIRONMENT', $error);

		// A key registered for production must not sit in the folder of the test environment.
		$production = $this->production();
		$prepared = $production->generate('', 'license');
		$reference = $this->fixture->owner
			->execute('SELECT private_ref FROM signing_keys WHERE kid=?', [$prepared['kid']])
			->fetchColumn();
		[$status, , $error] = $this->cli(['bin/preflight.php']);
		self::assertSame(0, $status, $error);
		copy($this->folder('production') . '/' . $reference, $this->folder('test') . '/' . $reference);
		[$status, , $error] = $this->cli(['bin/preflight.php']);
		self::assertSame(1, $status);
		foreach ([$prepared['kid'], 'que es del entorno production', 'Cada entorno usa su propia carpeta'] as $text) {
			self::assertStringContainsString($text, $error);
		}
	}

	public function testPublicFixtureSeedsAreRejectedForProductionLicensesAndOnProductionServers(): void
	{
		// Development or automated tests with test licenses: the public seeds of the contract fixtures are usable.
		$this->plantFixtureSeed($this->test(), 'semilla-en-pruebas', 'adelpos-test-fixture-a');
		$this->test()->check();
		self::assertSame(
			'semilla-en-pruebas',
			$this->fixture->owner->transaction(fn() => $this->test()->sign(['prueba' => true]))['kid'],
		);

		$rejected = function (SigningKeys $keys, string $case): void {
			try {
				$keys->check();
				self::fail('Public fixture seed accepted: ' . $case);
			} catch (\RuntimeException $error) {
				self::assertSame('Public fixture key forbidden in production.', $error->getMessage(), $case);
			}
			try {
				$this->fixture->owner->transaction(fn() => $keys->sign(['prueba' => true]));
				self::fail('Signed with a public fixture seed: ' . $case);
			} catch (\RuntimeException $error) {
				self::assertSame(
					'Public fixture key forbidden in production.',
					$error->getPrevious()->getMessage(),
					$case,
				);
			}
		};
		// The same test key on a server with production security (licensing.sicrat.com): rejected.
		$rejected(
			$this->fixture->appWith(
				['APP_ENV' => 'production', 'APP_URL' => 'https://licensing.sicrat.com'],
				$this->clock,
			)->signingKeys,
			'test licenses on a production server',
		);

		// Production licenses: rejected even when the file and the registry were relabelled as a production key.
		$this->plantFixtureSeed($this->production(), 'adelpos-prod-202611-1', 'adelpos-test-fixture-b');
		$rejected($this->production(), 'production licenses');
		// The rule does not depend on the application environment having been validated.
		$rejected(
			$this->production(['APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1:8088']),
			'production licenses, unvalidated configuration',
		);
		foreach (['installation-fixture-a', 'installation-fixture-b'] as $index => $seed) {
			$this->plantFixtureSeed($this->production(), 'adelpos-prod-202611-' . ($index + 2), $seed);
			$rejected($this->production(), $seed);
		}
	}

	public function testPublicKeyExportContainsOnlyPublicLicenseKeysOfTheEnvironment(): void
	{
		$kid = $this->activeTestKey();
		$staged = $this->test()->generate('', 'license');
		$this->test()->generate('manifiesto-de-prueba', 'manifest');
		$this->production()->generate('', 'license');

		$export = $this->test()->publicKeys();
		self::assertSame(['environment', 'keys'], array_keys($export));
		self::assertSame('test', $export['environment']);
		self::assertSame([$kid, $staged['kid']], array_column($export['keys'], 'kid'));
		foreach ($export['keys'] as $key) {
			self::assertSame(['kid', 'environment', 'public_key'], array_keys($key));
			self::assertSame('test', $key['environment']);
			self::assertSame(
				$this->fixture->owner
					->execute('SELECT public_key FROM signing_keys WHERE kid=?', [$key['kid']])
					->fetchColumn(),
				Protocol::decode($key['public_key'], 32),
			);
		}
		self::assertSame(['adelpos-prod-202611-1'], array_column($this->production()->publicKeys()['keys'], 'kid'));

		// The command prints exactly that document; the read-only application account is enough.
		[$status, $output, $error] = $this->cli(['bin/signing-key.php', '--action=export']);
		self::assertSame(0, $status, $error);
		self::assertSame($export, json_decode($output, true, 8, JSON_THROW_ON_ERROR));
		self::assertSame("Entorno de licencias: test\n", $error);

		$files = [...glob($this->folder('test') . '/*.json'), ...glob($this->folder('production') . '/*.json')];
		self::assertCount(4, $files);
		foreach ($files as $file) {
			$secret = json_decode(file_get_contents($file), true)['secret_key'];
			$seed = Protocol::encode(substr(Protocol::decode($secret, 64), 0, 32));
			foreach ([$secret, $seed, basename($file, '.json')] as $private) {
				self::assertStringNotContainsString($private, $output);
				self::assertStringNotContainsString($private, Protocol::json($export));
			}
		}
		foreach (
			[
				'"secret_key"',
				'"private_ref"',
				'"purpose"',
				'"state"',
				'manifiesto-de-prueba',
				'adelpos-prod-',
				'"production"',
			]
			as $text
		) {
			self::assertStringNotContainsString($text, $output);
		}
	}

	public function testKeyCommandShowsTheLicenseEnvironmentInEveryOperation(): void
	{
		$banner = "Entorno de licencias: test\n";
		[$status, $output, $error] = $this->cli(['bin/signing-key.php', '--action=generate'], [], true);
		self::assertSame(0, $status, $error);
		self::assertStringStartsWith($banner, $output);
		self::assertSame(
			1,
			preg_match(
				'/^\{"kid":"(adelpos-test-\d{6}-1)","environment":"test","purpose":"license",' .
					'"public_key":"[A-Za-z0-9_-]{43}","state":"staged"\}$/m',
				$output,
				$match,
			),
			$output,
		);
		self::assertStringContainsString('Llave preparada en el entorno test.', $output);
		self::assertSame(gmdate('Ym'), substr($match[1], 13, 6));

		[$status, , $error] = $this->cli(
			['bin/signing-key.php', '--action=activate', '--kid=' . $match[1], '--reason=Prueba aislada'],
			[],
			true,
		);
		self::assertSame(1, $status);
		self::assertStringContainsString('--trust-confirmed', $error);
		[$status, $output, $error] = $this->cli(
			[
				'bin/signing-key.php',
				'--action=activate',
				'--kid=' . $match[1],
				'--reason=Prueba aislada',
				'--trust-confirmed',
			],
			[],
			true,
		);
		self::assertSame(0, $status, $error);
		self::assertSame(
			$banner . "Llave firmante activada en el entorno test. Las públicas anteriores se conservan.\n",
			$output,
		);

		[$status, $output, $error] = $this->cli(['bin/signing-key.php', '--action=list']);
		self::assertSame(0, $status, $error);
		self::assertStringStartsWith($banner, $output);
		$listed = json_decode(explode("\n", trim($output))[1], true, 8, JSON_THROW_ON_ERROR);
		self::assertSame(
			[$match[1], 'test', 'license', 'signing'],
			[$listed['kid'], $listed['environment'], $listed['purpose'], $listed['state']],
		);
		self::assertArrayNotHasKey('private_ref', $listed);

		[$status, $output, $error] = $this->cli(['bin/signing-key.php', '--action=export']);
		self::assertSame(0, $status);
		self::assertSame($banner, $error);
		self::assertSame($match[1], json_decode($output, true)['keys'][0]['kid']);

		[$status, , $error] = $this->cli(['bin/signing-key.php', '--action=otra']);
		self::assertSame(1, $status);
		self::assertStringContainsString('--action=generate|activate|list|export', $error);

		// Once this database signs test licenses, the command cannot prepare production keys in it.
		[$status, $output, $error] = $this->cli(
			['bin/signing-key.php', '--action=generate'],
			self::PRODUCTION + ['SIGNING_KEY_DIR' => $this->folder('production')],
			true,
		);
		self::assertSame(1, $status);
		self::assertSame('', $output);
		self::assertStringContainsString('LICENSE_ENVIRONMENT es production', $error);
		self::assertSame(1, (int) $this->fixture->owner->execute('SELECT COUNT(*) FROM signing_keys')->fetchColumn());
	}
}
