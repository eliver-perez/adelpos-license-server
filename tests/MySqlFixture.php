<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use AdelPos\License\{App, Config};
use AdelPos\License\Infrastructure\{Database, Migrator};

final class MySqlFixture
{
    public readonly string $database;
    public readonly string $username;
    public readonly array $values;
    public readonly Database $owner;
    public readonly App $app;
    private \PDO $server;

    public function __construct(?FrozenClock $clock = null)
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn || !str_starts_with($dsn, 'mysql:')) { throw new \RuntimeException('TEST_MYSQL_DSN must explicitly identify an isolated MySQL 8 test server.'); }
        $this->server = new \PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(5));
        $this->database = 'adelpos_license_test_' . $suffix;
        $this->username = 'adelpos_license_test_' . $suffix;
        $password = bin2hex(random_bytes(24));
        $this->server->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $databaseDsn = preg_replace('/;?dbname=[^;]*/', '', $dsn) . ';dbname=' . $this->database;
        $this->owner = new Database(new \PDO($databaseDsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: ''));
        (new Migrator($this->owner, dirname(__DIR__) . '/database/migrations'))->migrate();
        self::syntheticCatalog($this->owner);
        $this->server->exec("CREATE USER '" . $this->username . "'@'localhost' IDENTIFIED BY " . $this->server->quote($password));
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/grants.php', '--database=' . $this->database, '--user=' . $this->username];
        $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $grants = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new \RuntimeException('Grant generation failed: ' . $error); }
        foreach (explode(';', $grants) as $sql) { if (trim($sql) !== '') { $this->server->exec($sql); } }
        $values = ['APP_ENV'=>'testing','LICENSE_ENVIRONMENT'=>'test','APP_URL'=>'http://127.0.0.1:8088','DB_DSN'=>$databaseDsn,'DB_USER'=>$this->username,'DB_PASSWORD'=>$password,'SIGNING_KEY_DIR'=>sys_get_temp_dir().'/'.$this->database.'-keys'];
        foreach (['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) { $values[$name]=base64_encode(random_bytes(32)); }
        $this->values = $values;
        $config = new Config($values, false);
        $this->app = new App($config, Database::connect($config), $clock);
    }

    /**
     * The initial schema seeds only adel_pos, without modules. Tests use this synthetic
     * product instead: five modules, one dependency (prueba_e requires prueba_a).
     */
    public const PRODUCT = 'producto_prueba';
    public const MODULES = ['prueba_a'=>'Módulo de prueba A','prueba_b'=>'Módulo de prueba B','prueba_c'=>'Módulo de prueba C','prueba_d'=>'Módulo de prueba D','prueba_e'=>'Módulo de prueba E'];

    /** Valid commercial terms of the contract model for a license type; each test overrides what it exercises. */
    public static function terms(string $type = 'perpetual', string $plan = 'elemental'): array
    {
        return ['license_type'=>$type,'plan_id'=>$plan] + ['perpetual'=>['updates_months'=>'6'],'subscription'=>['billing_period'=>'monthly'],'trial'=>['trial_duration'=>'15d']][$type];
    }

    public static function syntheticCatalog(Database $db): void
    {
        $db->execute("INSERT INTO products(product_id,display_name,description,state,created_at,updated_at) VALUES (?,'Producto de prueba','Producto sintético para pruebas automáticas','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [self::PRODUCT]);
        foreach (self::MODULES as $key => $name) { $db->execute('INSERT INTO product_capabilities(product_id,capability_key,display_name) VALUES (?,?,?)', [self::PRODUCT, $key, $name]); }
        $db->execute('INSERT INTO capability_dependencies(product_id,capability_key,required_key) VALUES (?,?,?)', [self::PRODUCT, 'prueba_e', 'prueba_a']);
    }

    public function destroy(): void
    {
        // Only random databases/users created by this fixture can be removed.
        if (!preg_match('/\Aadelpos_license_test_[a-f0-9]{10}\z/', $this->database)) { throw new \LogicException('Unsafe fixture identifier.'); }
        $this->server->exec('DROP DATABASE `' . $this->database . '`');
        $this->server->exec("DROP USER '" . $this->username . "'@'localhost'");
        // The fixture folder, plus the folders of other license environments: «<folder>-<environment>».
        foreach ([$this->values['SIGNING_KEY_DIR'], ...(glob($this->values['SIGNING_KEY_DIR'].'-*', GLOB_ONLYDIR) ?: [])] as $directory) {
            foreach (glob($directory.'/*.json') ?: [] as $file) { unlink($file); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }

    /** The same database seen with another configuration, for example another license environment. */
    public function appWith(array $overrides, ?FrozenClock $clock = null): App
    {
        return new App(new Config(array_replace($this->values, $overrides), false), $this->owner, $clock);
    }

    public function signingKeys(?FrozenClock $clock = null): \AdelPos\License\Infrastructure\SigningKeys
    {
        return (new App(new Config($this->values, false), $this->owner, $clock))->signingKeys;
    }
}
