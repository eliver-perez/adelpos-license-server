<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Infrastructure\{Database, Migrator};
use AdelPos\License\Tests\NativeStackFixture;
use PHPUnit\Framework\TestCase;

/**
 * The single initial migration. The inherited 001-007 chain is read from the repository's
 * first commit, identified by the SHA-256 of its composer.lock, and never copied back.
 */
final class InitialSchemaTest extends TestCase
{
	private const ORIGIN_COMPOSER_LOCK_SHA256 = '7f996783b79412c73ad00d23e5354a5141517ca93da5d294370f353aab9f1730';
	/** Registered hardening (DECISIONS.md): the only differences allowed against the inherited chain. */
	private const HARDENING = [
		'column license_credentials.code_format IS_NULLABLE: YES -> NO',
		'column license_credentials.credential_encrypted IS_NULLABLE: YES -> NO',
	];
	/** Every migration, in order. Only the first one is compared with the inherited chain. */
	private const MIGRATIONS = [
		'001_esquema_inicial.sql',
		'002_entornos_de_firma.sql',
		'003_contrato_v1.sql',
		'004_retiro_de_lo_heredado.sql',
		'005_precios_y_pagos.sql',
		'006_editor_de_planes.sql',
		'007_cambios_programados.sql',
		'008_transferencias_y_alertas.sql',
	];
	private \PDO $server;
	private string $dsn;
	/** @var list<string> */
	private array $databases = [];
	/** @var list<string> */
	private array $directories = [];

	protected function setUp(): void
	{
		$dsn = getenv('TEST_MYSQL_DSN');
		if (!$dsn) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->dsn = $dsn;
		$this->server = new \PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
		]);
	}

	protected function tearDown(): void
	{
		foreach ($this->databases as $name) {
			if (preg_match('/\Aadelpos_license_test_[a-f0-9]{10}\z/', $name)) {
				$this->server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
			}
		}
		foreach ($this->directories as $directory) {
			NativeStackFixture::remove($directory);
		}
	}

	public function testInitialSchemaMatchesTheInheritedChainExceptRegisteredHardening(): void
	{
		$origin = $this->originMigrations();
		self::assertCount(7, $origin);
		[$inheritedName, $inherited] = $this->database();
		self::assertSame(array_keys($origin), (new Migrator($inherited, $this->directory($origin)))->migrate());
		[$initialName, $initial] = $this->database();
		self::assertSame(
			['001_esquema_inicial.sql'],
			(new Migrator(
				$initial,
				$this->directory([
					'001_esquema_inicial.sql' => (string) file_get_contents(
						self::migrations() . '/001_esquema_inicial.sql',
					),
				]),
			))->migrate(),
		);
		$before = $this->schema($inheritedName);
		$after = $this->schema($initialName);
		self::assertCount(34, $before['tables']);
		self::assertSame(self::HARDENING, self::differences($before, $after));
	}

	public function testEmptyDatabaseGetsOnlyAdelPosWithoutModulesAndRepeatingDoesNothing(): void
	{
		[$name, $db] = $this->database();
		self::assertSame(array_map('basename', glob(self::migrations() . '/*.sql')), self::MIGRATIONS);
		self::assertSame(self::MIGRATIONS, (new Migrator($db, self::migrations()))->migrate());
		$schema = $this->schema($name);
		self::assertSame(
			[['product_id' => 'adel_pos', 'display_name' => 'AD-EL POS', 'state' => 'active']],
			$db->all('SELECT product_id,display_name,state FROM products'),
		);
		foreach (
			['product_capabilities', 'capability_dependencies', 'licenses', 'admin_users', 'signing_keys']
			as $table
		) {
			self::assertSame(0, (int) $db->execute('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
		}
		// The plan catalog of the contract (section 11): the only other seeded data.
		self::assertSame(
			[
				['basico', 'Plan básico', 1, 0, 0],
				['elemental', 'Plan elemental', 3, 3, 3],
				['premium', 'Plan premium', 5, 10, 5],
			],
			array_map(
				static fn(array $plan): array => array_map(
					static fn($value) => is_numeric($value) ? (int) $value : $value,
					array_values($plan),
				),
				$db->all(
					'SELECT plan_id,display_name,cash_registers,order_takers,kitchen_displays
						FROM plans
						ORDER BY plan_id',
				),
			),
		);
		// 33 tablas del esquema inicial, más «plans», «license_payments», «license_scheduled_changes»,
		// «license_alerts» y «schema_migrations», menos «license_limits», «subscription_periods» y
		// «maintenance_purchases».
		self::assertCount(35, $schema['tables']);
		self::assertSame(1, (int) $db->execute('SELECT COUNT(*) FROM security_state')->fetchColumn());
		self::assertSame(
			['admin', 0],
			array_values(
				array_map(
					static fn($v) => is_numeric($v) ? (int) $v : $v,
					$db->one('SELECT stream_id,last_sequence FROM audit_heads'),
				),
			),
		);
		self::assertSame([], (new Migrator($db, self::migrations()))->migrate());
		self::assertSame($schema, $this->schema($name));
		self::assertSame(
			array_fill_keys(self::MIGRATIONS, 'complete'),
			array_column($db->all('SELECT name,state FROM schema_migrations ORDER BY name'), 'state', 'name'),
		);
	}

	public function testSigningKeysBelongOnlyToTheTestOrProductionLicenseEnvironment(): void
	{
		[, $db] = $this->database();
		(new Migrator($db, self::migrations()))->migrate();
		$key = static fn(string $kid, string $environment): array => [
			'INSERT INTO signing_keys(kid,environment,purpose,public_key,private_ref,created_at)
				VALUES (?,?,\'license\',?,?,UTC_TIMESTAMP(6))',
			[$kid, $environment, random_bytes(32), bin2hex(random_bytes(16)) . '.json'],
		];
		foreach (['test', 'production'] as $environment) {
			$db->execute(...$key('llave-' . $environment, $environment));
			$db->execute('INSERT INTO signing_scopes(environment,purpose,active_kid) VALUES (?,\'license\',?)', [
				$environment,
				'llave-' . $environment,
			]);
		}
		foreach (['development', 'testing', 'prod', ''] as $environment) {
			foreach (
				[
					$key('llave-' . $environment, $environment),
					['INSERT INTO signing_scopes(environment,purpose) VALUES (?,\'license\')', [$environment]],
				]
				as [$sql, $parameters]
			) {
				try {
					$db->execute($sql, $parameters);
					self::fail('Accepted signing environment: ' . $environment);
				} catch (\PDOException $error) {
					self::assertSame(3819, (int) $error->errorInfo[1]);
				}
			}
		}
		// A scope can never point to a key of another environment.
		try {
			$db->execute("UPDATE signing_scopes SET active_kid='llave-test' WHERE environment='production'");
			self::fail('Crossed environments');
		} catch (\PDOException $error) {
			self::assertSame(1452, (int) $error->errorInfo[1]);
		}

		// Keys of the earlier application environments cannot be carried over: the migration stops.
		[, $legacy] = $this->database();
		$first = $this->directory([
			'001_esquema_inicial.sql' => (string) file_get_contents(self::migrations() . '/001_esquema_inicial.sql'),
		]);
		(new Migrator($legacy, $first))->migrate();
		$legacy->execute(...$key('llave-anterior', 'testing'));
		try {
			(new Migrator($legacy, self::migrations()))->migrate();
			self::fail('Migrated a key of an application environment');
		} catch (\PDOException $error) {
			self::assertSame(3819, (int) $error->errorInfo[1]);
		}
		self::assertMigrationRejected($legacy, self::migrations(), 'incompleta');
	}

	public function testMigratorAppliesOnlyPendingFilesAndRejectsModifiedOrIncompleteOnes(): void
	{
		[, $db] = $this->database();
		$directory = $this->directory([
			'001_esquema_inicial.sql' => (string) file_get_contents(self::migrations() . '/001_esquema_inicial.sql'),
		]);
		(new Migrator($db, $directory))->migrate();
		$db->execute(
			"INSERT INTO customers(id,display_name,created_at,updated_at)
				VALUES (?,'Cliente sintético',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
			[random_bytes(16)],
		);
		$customers = $db->all('SELECT * FROM customers');
		$pending = "CREATE TABLE prueba_sintetica (id INT PRIMARY KEY) ENGINE=InnoDB;\nINSERT INTO prueba_sintetica
				VALUES (1);\n";
		file_put_contents($directory . '/002_prueba_sintetica.sql', $pending);
		self::assertSame(['002_prueba_sintetica.sql'], (new Migrator($db, $directory))->migrate());
		self::assertSame($customers, $db->all('SELECT * FROM customers'));
		self::assertSame(1, (int) $db->execute('SELECT COUNT(*) FROM prueba_sintetica')->fetchColumn());
		file_put_contents($directory . '/002_prueba_sintetica.sql', "-- cambio posterior\n" . $pending);
		self::assertMigrationRejected($db, $directory, 'modificada');
		file_put_contents($directory . '/002_prueba_sintetica.sql', $pending);
		$db->execute("UPDATE schema_migrations SET state='pending' WHERE name='002_prueba_sintetica.sql'");
		self::assertMigrationRejected($db, $directory, 'incompleta');
	}

	public function testTransfersRecordedBeforeMigration008BecomeConfirmedOwnerTransfers(): void
	{
		// Hasta la etapa 3 solo el propietario transfería, desde el panel. Esas filas quedan con la vía «owner» y
		// confirmadas desde su creación.
		[, $db] = $this->database();
		$files = [];
		foreach (self::MIGRATIONS as $name) {
			$files[$name] = (string) file_get_contents(self::migrations() . '/' . $name);
		}
		$latest = array_pop($files);
		$directory = $this->directory($files);
		(new Migrator($db, $directory))->migrate();
		$admin = random_bytes(16);
		$customer = random_bytes(16);
		$license = random_bytes(16);
		[$outgoing, $incoming] = [random_bytes(16), random_bytes(16)];
		$db->execute(
			"INSERT INTO admin_users(id,login,display_name,password_hash,role,created_at,updated_at)
				VALUES (?,'admin@example.test','Admin','-','superadmin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
			[$admin],
		);
		$db->execute(
			"INSERT INTO customers(id,display_name,created_at,updated_at)
				VALUES (?,'Restaurante sintético',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
			[$customer],
		);
		$db->execute(
			"INSERT INTO licenses(license_id,customer_id,product_id,license_type,updates_months,grace_days,plan_id,
					plan_name,plan_custom,cash_registers,order_takers,kitchen_displays,created_at,updated_at)
				VALUES (?,?,'adel_pos','perpetual',6,0,'elemental','Plan elemental',0,3,3,3,UTC_TIMESTAMP(6),
					UTC_TIMESTAMP(6))",
			[$license, $customer],
		);
		foreach ([[$outgoing, 'transferred'], [$incoming, 'active']] as [$activation, $state]) {
			$db->execute(
				"INSERT INTO activations(activation_id,license_id,product_id,server_id,installation_public_key,state,
						activated_at,ended_at)
					VALUES (?,?,'adel_pos',?,?,?,'2026-11-01 10:00:00.000000',?)",
				[
					$activation,
					$license,
					random_bytes(16),
					random_bytes(32),
					$state,
					$state === 'active' ? null : '2026-11-02 10:00:00.000000',
				],
			);
		}
		$db->execute(
			"INSERT INTO license_transfers(id,license_id,outgoing_activation_id,incoming_activation_id,admin_id,reason,
					offline_limit_accepted,created_at)
				VALUES (?,?,?,?,?,'Equipo averiado',1,'2026-11-02 10:00:00.000000')",
			[random_bytes(16), $license, $outgoing, $incoming, $admin],
		);
		file_put_contents($directory . '/' . self::MIGRATIONS[count(self::MIGRATIONS) - 1], $latest);
		(new Migrator($db, $directory))->migrate();
		self::assertSame(
			[
				'via' => 'owner',
				'state' => 'confirmed',
				'confirmation' => 'owner',
				'confirmed_at' => '2026-11-02 10:00:00.000000',
				'pending_license_id' => null,
			],
			$db->one('SELECT via,state,confirmation,confirmed_at,pending_license_id FROM license_transfers'),
		);
	}

	public function testRuntimeGrantsCoverExactlyTheSchemaTables(): void
	{
		[$name, $db] = $this->database();
		(new Migrator($db, self::migrations()))->migrate();
		$tables = array_column(
			$this->rows(
				'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME',
				$name,
			),
			'name',
		);
		$grants = NativeStackFixture::command([
			PHP_BINARY,
			dirname(__DIR__, 2) . '/bin/grants.php',
			'--database=' . $name,
			'--user=adelpos_license_app',
		]);
		preg_match_all('/ ON `' . preg_quote($name, '/') . '`\.`([a-z_]+)` TO /', $grants, $matches);
		$granted = $matches[1];
		sort($granted);
		self::assertSame($tables, $granted);
	}

	private static function assertMigrationRejected(Database $db, string $directory, string $reason): void
	{
		try {
			(new Migrator($db, $directory))->migrate();
			self::fail('Migration accepted');
		} catch (\RuntimeException $error) {
			self::assertStringContainsString($reason, $error->getMessage());
		}
	}

	private static function migrations(): string
	{
		return dirname(__DIR__, 2) . '/database/migrations';
	}

	/** @return array{string, Database} */
	private function database(): array
	{
		$name = 'adelpos_license_test_' . bin2hex(random_bytes(5));
		$this->server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
		$this->databases[] = $name;
		$dsn = preg_replace('/;?dbname=[^;]*/', '', $this->dsn) . ';dbname=' . $name;
		return [
			$name,
			new Database(new \PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '')),
		];
	}

	/** @param array<string,string> $files */
	private function directory(array $files): string
	{
		$directory = sys_get_temp_dir() . '/adelpos-license-schema-' . bin2hex(random_bytes(6));
		mkdir($directory, 0700);
		$this->directories[] = $directory;
		foreach ($files as $name => $sql) {
			file_put_contents($directory . '/' . $name, $sql);
		}
		return $directory;
	}

	/** @return array<string,string> */
	private function originMigrations(): array
	{
		$root = dirname(__DIR__, 2);
		[$status, $roots] = self::git($root, ['rev-list', '--max-parents=0', 'HEAD']);
		if ($status !== 0) {
			self::markTestSkipped('Requires the repository history.');
		}
		foreach (preg_split('/\s+/', trim($roots)) ?: [] as $commit) {
			[$status, $lock] = self::git($root, ['show', $commit . ':composer.lock']);
			if ($status !== 0 || !hash_equals(self::ORIGIN_COMPOSER_LOCK_SHA256, hash('sha256', $lock))) {
				continue;
			}
			[, $names] = self::git($root, ['ls-tree', '--name-only', $commit, 'database/migrations/']);
			$files = [];
			foreach (array_filter(explode("\n", $names)) as $path) {
				[$status, $sql] = self::git($root, ['show', $commit . ':' . $path]);
				if ($status !== 0) {
					self::fail('Cannot read inherited migration ' . $path);
				}
				$files[basename($path)] = $sql;
			}
			ksort($files);
			return $files;
		}
		self::markTestSkipped('Requires the full history with the original base copy.');
	}

	/** @return array{int, string} */
	private static function git(string $root, array $arguments): array
	{
		$process = proc_open(
			['git', '-C', $root, ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		fclose($pipes[0]);
		$output = (string) stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}

	/** @return list<array<string,mixed>> */
	private function rows(string $sql, string $schema): array
	{
		$statement = $this->server->prepare($sql);
		$statement->execute(array_fill(0, substr_count($sql, '?'), $schema));
		return $statement->fetchAll(\PDO::FETCH_ASSOC);
	}

	/** Tables, columns, indexes, foreign keys and check constraints, without the schema name. */
	private function schema(string $name): array
	{
		$schema = ['tables' => [], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
		foreach (
			$this->rows(
				'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA=?',
				$name,
			)
			as $row
		) {
			$schema['tables'][$row['TABLE_NAME']] = $row;
		}
		foreach (
			$this->rows(
				'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,
						COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION
					FROM information_schema.COLUMNS
					WHERE TABLE_SCHEMA=?',
				$name,
			)
			as $row
		) {
			$schema['columns'][$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = $row;
		}
		foreach (
			$this->rows(
				'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,
						EXPRESSION,IS_VISIBLE
					FROM information_schema.STATISTICS
					WHERE TABLE_SCHEMA=?
					ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
				$name,
			)
			as $row
		) {
			$schema['indexes'][$row['TABLE_NAME'] . '.' . $row['INDEX_NAME']][] = $row;
		}
		foreach (
			$this->rows(
				'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,
						k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
					FROM information_schema.KEY_COLUMN_USAGE k
					JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
						AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
					WHERE k.TABLE_SCHEMA=? AND k.REFERENCED_TABLE_NAME IS NOT NULL
					ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
				$name,
			)
			as $row
		) {
			$schema['foreign_keys'][$row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME']][] = $row;
		}
		foreach (
			$this->rows(
				"SELECT t.TABLE_NAME,t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE
					FROM information_schema.TABLE_CONSTRAINTS t
					JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA
						AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
					WHERE t.TABLE_SCHEMA=? AND t.CONSTRAINT_TYPE='CHECK'",
				$name,
			)
			as $row
		) {
			$schema['checks'][$row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME']] = $row;
		}
		foreach ($schema as &$section) {
			ksort($section);
		}
		return $schema;
	}

	/** @return list<string> One line per difference, sorted. */
	private static function differences(array $before, array $after): array
	{
		$lines = [];
		foreach ($before as $section => $items) {
			$label = rtrim(
				$section === 'indexes'
					? 'index'
					: ($section === 'foreign_keys'
						? 'foreign key'
						: ($section === 'checks'
							? 'check'
							: $section)),
				's',
			);
			foreach (array_unique([...array_keys($items), ...array_keys($after[$section])]) as $key) {
				if (!isset($after[$section][$key])) {
					$lines[] = $label . ' ' . $key . ': removed';
					continue;
				}
				if (!isset($items[$key])) {
					$lines[] = $label . ' ' . $key . ': added';
					continue;
				}
				if ($section === 'columns' || $section === 'tables' || $section === 'checks') {
					foreach ($items[$key] as $attribute => $value) {
						if ($value !== $after[$section][$key][$attribute]) {
							$lines[] =
								$label .
								' ' .
								$key .
								' ' .
								$attribute .
								': ' .
								var_export($value, true) .
								' -> ' .
								var_export($after[$section][$key][$attribute], true);
						}
					}
				} elseif ($items[$key] !== $after[$section][$key]) {
					$lines[] = $label . ' ' . $key . ': changed';
				}
			}
		}
		sort($lines);
		return array_map(
			static fn(string $line): string => str_replace(["'YES'", "'NO'"], ['YES', 'NO'], $line),
			$lines,
		);
	}
}
