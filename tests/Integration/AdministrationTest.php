<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Infrastructure\Migrator;
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class AdministrationTest extends TestCase
{
    private MySqlFixture $fixture;
    private FrozenClock $clock;
    private Actor $actor;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Set TEST_MYSQL_DSN for an isolated MySQL 8 instance.'); }
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
        $this->fixture = new MySqlFixture($this->clock);
        $id = $this->fixture->app->auth->bootstrap('admin@example.test', 'Administrador de prueba', 'Fixture-Password-Only-2026!');
        $this->actor = new Actor($id, 'superadmin', 'Administrador');
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) { $this->fixture->destroy(); }
    }

    private function customer(): string
    {
        return $this->fixture->app->catalog->saveCustomer($this->actor, Uuid::create(), null, ['name'=>'Cliente de prueba','legal_name'=>'','contact_name'=>'Contacto','email'=>'contacto@example.test','phone'=>'','state'=>'active'])['id'];
    }

    private function issueInput(string $type = 'perpetual'): array
    {
        return ['customer_id'=>$this->customer(),'product_id'=>'producto_prueba','license_type'=>$type,'entitled_release_until'=>'2026-09-25T12:00','expires_at'=>'2026-10-01T00:00','features'=>['prueba_b','prueba_c','prueba_a','prueba_e'],'reason'=>'Compra de prueba','reference'=>'TEST-001'];
    }

    public function testMigrationIsRepeatableAndBootstrapIsSingleUse(): void
    {
        self::assertSame([], (new Migrator($this->fixture->owner, dirname(__DIR__,2).'/database/migrations'))->migrate());
        self::assertSame('AD-EL POS', $this->fixture->app->read->product('adel_pos')['display_name']);
        self::assertSame([], $this->fixture->app->read->capabilities('adel_pos'));
        $this->expectException(Problem::class);
        $this->fixture->app->auth->bootstrap('other@example.test','Otro','Fixture-Password-Only-2026!');
    }

    public function testPerpetualDoesNotIncludeMaintenanceAndKeyIsNeverArchived(): void
    {
        $app=$this->fixture->app;
        $result=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput());
        $license=$app->read->license($result['id']);
        self::assertNull($license['expires_at']); self::assertNull($license['maintenance_until']);
        self::assertSame(0,(int)$license['grace_days']); self::assertSame(0,(int)$license['revision_counter']);
        self::assertMatchesRegularExpression('/\A[2-9A-HJKMNP-Z]{3}(?:-[2-9A-HJKMNP-Z]{4}){3}\z/',$result['secret']);
        self::assertSame('TEST-001', json_decode($license['history'][0]['snapshot_json'], true, 512, JSON_THROW_ON_ERROR)['commercial_reference']);
        $stored=$app->db->one('SELECT * FROM license_credentials WHERE license_id=?',[Uuid::bytes($result['id'])]);
        self::assertSame($app->crypto->digest('CREDENTIAL_KEY',$result['secret']),$stored['key_digest']);
        foreach (['admin_operations'=>'result_json','license_changes'=>'snapshot_json','audit_events'=>'payload_json'] as $table=>$column) {
            foreach($app->db->all("SELECT $column AS content FROM $table") as $row) { self::assertStringNotContainsString($result['secret'],$row['content']); }
        }
        self::assertGreaterThanOrEqual(3,$app->audit->verify());
    }

    public function testDuplicateIssuanceAndConflictingRequest(): void
    {
        $app=$this->fixture->app; $operation=Uuid::create(); $input=$this->issueInput();
        $first=$app->licenses->issue($this->actor,$operation,$input);
        $retry=$app->licenses->issue($this->actor,$operation,$input);
        self::assertSame($first['id'],$retry['id']); self::assertTrue($retry['replayed']); self::assertArrayNotHasKey('secret',$retry);
        self::assertSame(1,(int)$app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
        $input['reason']='Altered'; $this->expectException(Problem::class);
        $app->licenses->issue($this->actor,$operation,$input);
    }

    public function testMfaEnrollmentReplayRecoveryAndSessionRotation(): void
    {
        $app=$this->fixture->app;
        $anonymous=$app->sessions->create();
        $pending=$app->auth->login('admin@example.test','Fixture-Password-Only-2026!','127.0.0.1',$anonymous);
        self::assertNull($app->sessions->load($anonymous));
        $record=$app->sessions->load($pending);
        $secret=$app->crypto->decrypt($record['enrollment_secret'],'mfa:'.$this->actor->id);
        $code=\OTPHP\TOTP::createFromSecret($secret,$this->clock)->at($this->clock->now()->getTimestamp());
        $result=$app->auth->verifyMfa($pending,$code,'127.0.0.1');
        self::assertCount(8,$result['recovery_codes']); self::assertNull($app->sessions->load($pending));
        self::assertSame('authenticated',$app->sessions->load($result['token'])['stage']);
        $pending2=$app->auth->login('admin@example.test','Fixture-Password-Only-2026!','127.0.0.1',$app->sessions->create());
        try { $app->auth->verifyMfa($pending2,$code,'127.0.0.1'); self::fail('Reused TOTP accepted'); } catch(Problem $error) { self::assertSame(401,$error->status); }
        $recovery=$result['recovery_codes'][0];
        $app->auth->verifyMfa($pending2,$recovery,'127.0.0.1');
        $pending3=$app->auth->login('admin@example.test','Fixture-Password-Only-2026!','127.0.0.1',$app->sessions->create());
        $this->expectException(Problem::class);
        $app->auth->verifyMfa($pending3,$recovery,'127.0.0.1');
    }

    public function testSubscriptionRenewalAndDuplicateDoNotAddExtraPeriod(): void
    {
        $app=$this->fixture->app; $issued=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput('subscription'));
        $input=['version'=>1,'until'=>'2026-11-01T00:00','reason'=>'Renovación comprada','reference'=>'TEST-002']; $operation=Uuid::create();
        $app->licenses->change($this->actor,$operation,$issued['id'],'renew',$input);
        $app->licenses->change($this->actor,$operation,$issued['id'],'renew',$input);
        $license=$app->read->license($issued['id']);
        self::assertSame(15,(int)$license['grace_days']); self::assertNull($license['maintenance_until']);
        self::assertSame('2026-11-01 00:00:00.000000',$license['expires_at']);
        self::assertSame(2,(int)$app->db->execute('SELECT COUNT(*) FROM subscription_periods')->fetchColumn());
    }

    public function testMaintenanceRequiresSeparateExplicitPurchase(): void
    {
        $app=$this->fixture->app; $issued=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput());
        $app->licenses->change($this->actor,Uuid::create(),$issued['id'],'maintenance',['version'=>1,'until'=>'2027-09-25T12:00','reason'=>'Compra de mantenimiento','reference'=>'TEST-MAINT']);
        $license=$app->read->license($issued['id']); self::assertNull($license['expires_at']);
        self::assertSame($license['maintenance_until'],$license['entitled_release_until']);
        self::assertCount(2,$license['history']);
    }

    public function testInvalidDependenciesRollbackWithoutKeyOrLicense(): void
    {
        $app=$this->fixture->app; $input=$this->issueInput(); $input['features']=['prueba_e'];
        try { $app->licenses->issue($this->actor,Uuid::create(),$input); self::fail('Invalid rights accepted'); } catch(Problem) {}
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
        self::assertSame(2,$app->audit->verify());
    }

    public function testStaleRightsFormAndActivatedRightsAreRejected(): void
    {
        $app=$this->fixture->app; $issued=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput());
        try { $app->licenses->change($this->actor,Uuid::create(),$issued['id'],'features',['version'=>0,'features'=>[],'reason'=>'Old form']); self::fail('Stale accepted'); } catch(Problem $error) { self::assertSame(409,$error->status); }
        $this->fixture->owner->execute('UPDATE licenses SET revision_counter=1 WHERE license_id=?',[Uuid::bytes($issued['id'])]);
        $this->expectException(Problem::class);
        $app->licenses->change($this->actor,Uuid::create(),$issued['id'],'features',['version'=>1,'features'=>[],'reason'=>'Needs signed revision']);
    }

    public function testViewerCannotMutateEvenWithForgedActorRole(): void
    {
        $app=$this->fixture->app;
        $user=$app->admins->create($this->actor,Uuid::create(),['login'=>'viewer@example.test','name'=>'Consulta','role'=>'viewer','new_password'=>'Fixture-Password-Only-2026!']);
        $forged=new Actor($user['id'],'superadmin');
        $input=$this->issueInput(); $this->expectException(Problem::class);
        $app->licenses->issue($forged,Uuid::create(),$input);
    }

    public function testLastSuperadminCannotBeDisabled(): void
    {
        $this->expectException(Problem::class);
        $this->fixture->app->admins->update($this->actor,Uuid::create(),$this->actor->id,['role'=>'viewer','state'=>'disabled','reason'=>'Test last admin']);
    }

    public function testApplicationCannotEditAppendOnlyHistory(): void
    {
        foreach(['UPDATE audit_events SET operation=operation WHERE 1=0','DELETE FROM audit_events WHERE 1=0','UPDATE license_changes SET action=action WHERE 1=0'] as $sql) {
            try { $this->fixture->app->db->execute($sql); self::fail('Privilege allowed: '.$sql); }
            catch(\PDOException $error) { self::assertSame(1142,(int)$error->errorInfo[1]); }
        }
    }

    public function testAuditFailureRollsBackRightsCredentialAndOperation(): void
    {
        $app=$this->fixture->app; $input=$this->issueInput();
        $this->fixture->owner->execute('UPDATE audit_heads SET last_sequence=0');
        try { $app->licenses->issue($this->actor,Uuid::create(),$input); self::fail('Audit collision should fail'); } catch(\PDOException) {}
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn());
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
    }

    public function testUniqueCredentialAndCommercialChecksExistInDatabase(): void
    {
        $app=$this->fixture->app; $issued=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput());
        try { $this->fixture->owner->execute('UPDATE licenses SET grace_days=15 WHERE license_id=?',[Uuid::bytes($issued['id'])]); self::fail('Invalid perpetual term accepted'); } catch(\PDOException $error) { self::assertSame(3819,(int)$error->errorInfo[1]); }
        $credential=$app->db->one('SELECT * FROM license_credentials LIMIT 1');
        // Every required column is supplied, so only the single-active-credential rule can reject it.
        try { $this->fixture->owner->execute('INSERT INTO license_credentials(credential_id,license_id,key_digest,state,created_at,credential_encrypted,code_format) VALUES (?,?,?,\'active\',?,?,\'3-4-4-4\')',[Uuid::bytes(Uuid::create()),$credential['license_id'],str_repeat('f',64),$this->clock->sql(),$credential['credential_encrypted']]); self::fail('Second active credential accepted'); }
        catch (\PDOException $error) { self::assertSame(1062,(int)$error->errorInfo[1]); }
        try { $this->fixture->owner->execute('INSERT INTO license_credentials(credential_id,license_id,key_digest,state,created_at) VALUES (?,?,?,\'retired\',?)',[Uuid::bytes(Uuid::create()),$credential['license_id'],str_repeat('e',64),$this->clock->sql()]); self::fail('Credential without its protected copy accepted'); }
        catch (\PDOException $error) { self::assertSame(1364,(int)$error->errorInfo[1]); }
    }

    public function testSearchCannotInjectSqlAndSnapshotIsConsistent(): void
    {
        $app=$this->fixture->app; $this->customer();
        $result=$app->read->page('customers',['q'=>"' OR 1=1 --"],1);
        self::assertSame(0,$result['total']);
        self::assertSame(1,$app->read->dashboard()['customers']);
    }

    public function testTwoProcessesRenewingSameRequestCommitOnlyOnce(): void
    {
        $app=$this->fixture->app;
        $issued=$app->licenses->issue($this->actor,Uuid::create(),$this->issueInput('subscription'));
        $input=['version'=>1,'until'=>'2026-11-01T00:00','reason'=>'Concurrent renewal','reference'=>'RACE'];
        $file=tempnam(sys_get_temp_dir(),'adelpos-license-race-');
        chmod($file,0600);
        file_put_contents($file,json_encode(['config'=>$this->fixture->values,'actor'=>$this->actor->id,'operation'=>Uuid::create(),'license'=>$issued['id'],'input'=>$input],JSON_THROW_ON_ERROR));
        $workers=[];
        $this->fixture->owner->pdo->beginTransaction();
        $this->fixture->owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE',[Uuid::bytes($issued['id'])]);
        try {
            for($index=0;$index<2;++$index) {
                $process=proc_open([PHP_BINARY,dirname(__DIR__).'/race-worker.php',$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process);
                $workers[]=[$process,$pipes];
            }
            // Observe two real lock waits: the first worker on the license and
            // the duplicate on the operation row owned by the first worker.
            $deadline=microtime(true)+10;
            do {
                $waiting=(int)$this->fixture->owner->execute('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?',[$this->fixture->database])->fetchColumn();
                if($waiting>=2)break;
                usleep(10000);
            } while(microtime(true)<$deadline);
            self::assertGreaterThanOrEqual(2,$waiting,'Both workers must reach database lock barriers before release.');
            $this->fixture->owner->pdo->commit();
            $results=[];
            foreach($workers as [$process,$pipes]) {
                $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
                fclose($pipes[1]);fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$errors);
                $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0]['id'],$results[1]['id']);
            self::assertNotSame($results[0]['replayed'],$results[1]['replayed']);
            self::assertSame(2,(int)$app->db->execute('SELECT COUNT(*) FROM subscription_periods')->fetchColumn());
            self::assertCount(2,$app->read->license($issued['id'])['history']);
        } finally {
            if($this->fixture->owner->pdo->inTransaction())$this->fixture->owner->pdo->rollBack();
            unlink($file);
        }
    }
}
