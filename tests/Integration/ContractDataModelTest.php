<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{CalendarPeriod, Protocol, Uuid};
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

/**
 * Database rules of the contract data model (migration 003): they hold even for a writer that
 * bypasses the application. Every statement runs with the owner account and is rolled back.
 */
final class ContractDataModelTest extends TestCase
{
    private MySqlFixture $fixture;
    private FrozenClock $clock;
    private Actor $actor;
    private string $customer;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8.'); }
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-16T06:30:00Z'));
        $this->fixture = new MySqlFixture($this->clock);
        $app = $this->fixture->app;
        $this->actor = new Actor($app->auth->bootstrap('admin@example.test', 'Admin', 'Fixture-Password-Only-2026!'), 'superadmin');
        $this->customer = $app->catalog->saveCustomer($this->actor, Uuid::create(), null, ['name'=>'Restaurante sintético','contact_name'=>'Prueba','email'=>'','state'=>'active'])['id'];
    }

    protected function tearDown(): void { if (isset($this->fixture)) { $this->fixture->destroy(); } }

    private function license(string $type): string
    {
        return $this->fixture->app->licenses->issue($this->actor, Uuid::create(), ['customer_id'=>$this->customer,'product_id'=>'adel_pos',...MySqlFixture::terms($type)])['id'];
    }

    /** Runs one statement as the owner and rolls it back; returns the MySQL error number, or 0 when accepted. */
    private function attempt(string $sql, array $parameters = []): int
    {
        $owner = $this->fixture->owner;
        $owner->pdo->beginTransaction();
        try { $owner->execute($sql, $parameters); return 0; }
        catch (\PDOException $error) { return (int) $error->errorInfo[1]; }
        finally { $owner->pdo->rollBack(); }
    }

    /** @param list<string> $accepted @param array<string,int>|list<string> $rejected Assignment => expected error (3819 when listed without key). */
    private function assertAssignments(string $table, string $where, array $parameters, array $accepted, array $rejected, string $case): void
    {
        foreach ($accepted as $assignment) { self::assertSame(0, $this->attempt("UPDATE $table SET $assignment WHERE $where", $parameters), $case . ' must accept: ' . $assignment); }
        foreach ($rejected as $key => $value) {
            [$assignment, $code] = is_int($key) ? [$value, 3819] : [$key, $value];
            self::assertSame($code, $this->attempt("UPDATE $table SET $assignment WHERE $where", $parameters), $case . ' must reject: ' . $assignment);
        }
    }

    public function testTermsMustMatchTheLicenseType(): void
    {
        $cases = [
            'perpetual' => [
                ['updates_months=0', 'updates_months=6', 'updates_months=12'],
                ["billing_period='monthly'", "trial_duration='15d'", 'updates_months=NULL', 'updates_months=3', 'grace_days=5', 'grace_days=15', "license_type='subscription'", "license_type='trial'", "license_type='lifetime'"],
            ],
            'subscription' => [
                ["billing_period='monthly'", "billing_period='quarterly'", "billing_period='semiannual'", "billing_period='annual'"],
                ['updates_months=6', 'updates_months=0', 'billing_period=NULL', "billing_period='weekly'", "trial_duration='15d'", 'grace_days=0', 'grace_days=15', "license_type='perpetual'", "license_type='trial'", "license_type='lifetime'", "license_type=''"],
            ],
            'trial' => [
                ["trial_duration='15d'", "trial_duration='1m'", "trial_duration='3m'"],
                ['trial_duration=NULL', "trial_duration='30d'", "billing_period='monthly'", 'updates_months=6', 'updates_months=0', 'grace_days=5', "license_type='subscription'", "license_type='perpetual'", "license_type='lifetime'", "license_type=''"],
            ],
        ];
        foreach ($cases as $type => [$accepted, $rejected]) {
            $this->assertAssignments('licenses', 'license_id=?', [Uuid::bytes($this->license($type))], $accepted, $rejected, $type);
        }
        // A type can change only together with its terms (stage 3 converts a trial into a paid license).
        $trial = [Uuid::bytes($this->license('trial'))];
        self::assertSame(0, $this->attempt("UPDATE licenses SET license_type='subscription',trial_duration=NULL,billing_period='annual',grace_days=5 WHERE license_id=?", $trial));
        self::assertSame(0, $this->attempt("UPDATE licenses SET license_type='perpetual',trial_duration=NULL,updates_months=6 WHERE license_id=?", $trial));
    }

    public function testCountsStayBetweenZeroAndNineHundredNinetyNineAndThePlanMustExist(): void
    {
        $license = [Uuid::bytes($this->license('subscription'))];
        foreach (['cash_registers', 'order_takers', 'kitchen_displays'] as $column) {
            $this->assertAssignments('licenses', 'license_id=?', $license, [$column . '=0', $column . '=999'], [$column . '=-1', $column . '=1000'], 'license');
            $this->assertAssignments('plans', "plan_id='elemental'", [], [$column . '=0', $column . '=999'], [$column . '=-1', $column . '=1000'], 'plan');
        }
        $this->assertAssignments('licenses', 'license_id=?', $license, ["plan_id='premium'", 'plan_custom=1', "plan_name='Otro nombre'"], ["plan_id='inexistente'" => 1452, "plan_name=''", 'plan_custom=2'], 'license plan');
        $this->assertAssignments('plans', "plan_id='basico'", [], ["display_name='Plan inicial'"], ["display_name=''"], 'plan name');
        // A plan that a license started from cannot disappear.
        self::assertSame(1451, $this->attempt("DELETE FROM plans WHERE plan_id='elemental'"));
        self::assertSame(0, $this->attempt("DELETE FROM plans WHERE plan_id='premium'"));
    }

    public function testThePeriodIsCompleteOrAbsentAndAgreesWithTheType(): void
    {
        $start = "period_starts_at='2026-11-16 06:30:00.123456',anchor_day=16,anchor_local_time='00:30:00.123456'";
        $end = "expires_at='2026-12-16 06:30:00.123456',updates_until='2026-12-16 06:30:00.123456'";
        foreach (['subscription', 'trial'] as $type) {
            $this->assertAssignments('licenses', 'license_id=?', [Uuid::bytes($this->license($type))],
                ["$start,$end", str_replace('anchor_day=16', 'anchor_day=31', "$start,$end"), str_replace("'00:30:00.123456'", "'23:59:59.999999'", "$start,$end")],
                [
                    "period_starts_at='2026-11-16 06:30:00'", $start, $end, "$start,expires_at='2026-12-16 06:30:00.123456'",
                    str_replace('anchor_day=16', 'anchor_day=0', "$start,$end"), str_replace('anchor_day=16', 'anchor_day=32', "$start,$end"), str_replace('anchor_day=16', 'anchor_day=NULL', "$start,$end"),
                    str_replace("'00:30:00.123456'", "'24:00:00'", "$start,$end"), str_replace("'00:30:00.123456'", "'-01:00:00'", "$start,$end"), str_replace("'00:30:00.123456'", 'NULL', "$start,$end"),
                    // The term ends after it starts, and updates end with it.
                    "$start,expires_at='2026-11-16 06:30:00.123456',updates_until='2026-11-16 06:30:00.123456'",
                    "$start,expires_at='2026-11-15 06:30:00',updates_until='2026-11-15 06:30:00'",
                    "$start,expires_at='2026-12-16 06:30:00.123456',updates_until='2027-05-16 06:30:00.123456'",
                    "$start,updates_until='2026-12-16 06:30:00.123456'",
                ], $type);
        }
        $this->assertAssignments('licenses', 'license_id=?', [Uuid::bytes($this->license('perpetual'))],
            // Updates for 0 months end at the activation itself.
            ["$start,updates_until='2026-11-16 06:30:00.123456'", "$start,updates_until='2027-05-16 06:30:00.123456'"],
            ["$start,$end", $start, "updates_until='2027-05-16 06:30:00'", "$start,updates_until='2026-11-16 06:29:59'", "expires_at='2026-12-16 06:30:00'"], 'perpetual');
    }

    public function testTheFirstPeriodOfTheDateEngineFitsTheDatabaseRulesForEveryLicenseType(): void
    {
        $owner = $this->fixture->owner;
        $terms = [
            'perpetual' => ['updates_months=0', 'updates_months=6', 'updates_months=12'],
            'subscription' => ["billing_period='monthly'", "billing_period='quarterly'", "billing_period='semiannual'", "billing_period='annual'"],
            'trial' => ["trial_duration='15d'", "trial_duration='1m'", "trial_duration='3m'"],
        ];
        // An ordinary instant with microseconds; the second 01:30 of a repeated hour; and a start whose next month falls in a missing hour.
        $starts = [['America/Monterrey', '2026-11-16T06:30:00.123456Z'], ['America/Tijuana', '2027-11-07T09:30:00Z'], ['America/Tijuana', '2027-02-14T10:30:00.999999Z'], ['UTC', '2028-02-29T23:59:59.999999Z']];
        $write = 'UPDATE licenses SET period_starts_at=?,expires_at=?,anchor_day=?,anchor_local_time=?,updates_until=? WHERE license_id=?';
        $checked = 0;
        foreach ($terms as $type => $assignments) {
            $id = Uuid::bytes($this->license($type));
            foreach ($assignments as $assignment) {
                $owner->execute("UPDATE licenses SET $assignment WHERE license_id=?", [$id]);
                $row = $owner->one('SELECT license_type,billing_period,trial_duration,updates_months FROM licenses WHERE license_id=?', [$id]);
                foreach ($starts as [$zone, $start]) {
                    $period = CalendarPeriod::firstPeriod($row, new \DateTimeImmutable($start), $zone);
                    $values = [CalendarPeriod::sql($period['period_starts_at']), $period['expires_at'] === null ? null : CalendarPeriod::sql($period['expires_at']), $period['anchor_day'], $period['anchor_local_time'], CalendarPeriod::sql($period['updates_until']), $id];
                    self::assertSame(0, $this->attempt($write, $values), "$type, $assignment, $zone, $start");
                    ++$checked;
                }
            }
        }
        self::assertSame(40, $checked);
        // What is stored is exactly what the engine produced, to the microsecond.
        $period = CalendarPeriod::firstPeriod(['license_type' => 'trial', 'trial_duration' => '3m'], new \DateTimeImmutable('2026-11-16T06:30:00.123456Z'), 'America/Monterrey');
        $owner->execute($write, [CalendarPeriod::sql($period['period_starts_at']), CalendarPeriod::sql($period['expires_at']), $period['anchor_day'], $period['anchor_local_time'], CalendarPeriod::sql($period['updates_until']), $id]);
        $stored = $owner->one('SELECT period_starts_at,expires_at,anchor_day,anchor_local_time,updates_until FROM licenses WHERE license_id=?', [$id]);
        self::assertSame(['2026-11-16 06:30:00.123456', '2027-02-16 06:30:00.123456', 16, '00:30:00.123456', '2027-02-16 06:30:00.123456'], [$stored['period_starts_at'], $stored['expires_at'], (int) $stored['anchor_day'], $stored['anchor_local_time'], $stored['updates_until']]);
        self::assertSame('2027-02-16T06:30:00.123456Z', Protocol::utc($stored['expires_at']));
    }

    public function testActivationsUseServerIdAndRevisionsRecordTheirReasonAndSigningEnvironment(): void
    {
        $app = $this->fixture->app; $owner = $this->fixture->owner;
        foreach (['activations', 'activation_challenges', 'license_requests'] as $table) {
            $columns = array_column($owner->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->fixture->database, $table]), 'name');
            self::assertContains('server_id', $columns, $table); self::assertNotContains('installation_id', $columns, $table);
        }
        $keys = $this->fixture->signingKeys($this->clock);
        $kid = $keys->generate('', 'license')['kid']; $keys->activate($kid, 'Isolated test');
        $issued = $app->licenses->issue($this->actor, Uuid::create(), ['customer_id'=>$this->customer,'product_id'=>'adel_pos',...MySqlFixture::terms('subscription')]);
        $pair = sodium_crypto_sign_keypair(); $server = Uuid::create();
        $challenge = json_decode($app->activations->challenge(['action'=>'activate','product_id'=>'adel_pos','installation_id'=>$server,'activation_id'=>null])->body, true);
        $input = ['request_id'=>Uuid::create(),'product_id'=>'adel_pos','installation_id'=>$server,'challenge_id'=>$challenge['challenge_id'],'app_version'=>'0.12.0','license_key'=>$issued['secret'],'installation_public_key'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('a', 64)];
        $input['proof'] = Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage('activate', $input, $challenge['nonce']), sodium_crypto_sign_secretkey($pair)));
        self::assertSame(200, $app->activations->execute('activate', $input)->status);

        $activation = $owner->one('SELECT * FROM activations');
        self::assertSame(Uuid::bytes($server), $activation['server_id']);
        self::assertSame(Uuid::bytes($server), $owner->execute('SELECT server_id FROM activation_challenges')->fetchColumn());
        self::assertSame(Uuid::bytes($server), $owner->execute('SELECT server_id FROM license_requests')->fetchColumn());
        // Every signed revision records the environment of the key that signed it, and a reason only when it closes an activation.
        $revision = $owner->one('SELECT * FROM license_revisions');
        self::assertSame([$kid, 'test', 'active', null], [$revision['kid'], $revision['environment'], $revision['license_status'], $revision['status_reason']]);
        $this->assertAssignments('license_revisions', 'revision_id=?', [$revision['revision_id']],
            ["license_status='revoked',status_reason='transferred'", "license_status='revoked',status_reason='deactivated'", "license_status='revoked',status_reason='revoked'"],
            ["status_reason='deactivated'", "license_status='revoked',status_reason='expired'", "license_status='revoked',status_reason=''", "environment='production'" => 1452], 'revision');
        $this->assertAssignments('activations', 'activation_id=?', [$activation['activation_id']],
            ["state='transferred',ended_at='2026-11-17 00:00:00'", "state='deactivated',ended_at='2026-11-17 00:00:00'", "state='revoked',ended_at='2026-11-17 00:00:00'", "app_version='0.13.0'"],
            ["state='transferida',ended_at='2026-11-17 00:00:00'", "state='transferred'"], 'activation');

        // The application account reads the plan catalog, records the application version and cannot touch identity or signed history.
        $denied = function (string $sql): int { try { $this->fixture->app->db->execute($sql); return 0; } catch (\PDOException $error) { return (int) $error->errorInfo[1]; } };
        self::assertSame(3, (int) $app->db->execute('SELECT COUNT(*) FROM plans')->fetchColumn());
        self::assertSame(0, $denied("UPDATE activations SET app_version='0.13.0' WHERE 1=0"));
        foreach (["UPDATE activations SET server_id=server_id WHERE 1=0" => 1143, "UPDATE plans SET cash_registers=9 WHERE 1=0" => 1142, "DELETE FROM plans WHERE 1=0" => 1142, "INSERT INTO plans(plan_id,display_name,cash_registers,order_takers,kitchen_displays,created_at,updated_at) VALUES ('otro','Otro',1,1,1,NOW(6),NOW(6))" => 1142, "UPDATE license_revisions SET status_reason=status_reason WHERE 1=0" => 1142] as $sql => $code) {
            self::assertSame($code, $denied($sql), $sql);
        }
    }
}
