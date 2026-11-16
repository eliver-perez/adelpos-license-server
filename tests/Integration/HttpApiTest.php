<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\{Protocol, Uuid};
use AdelPos\License\Tests\MySqlFixture;
use PHPUnit\Framework\TestCase;

final class HttpApiTest extends TestCase
{
    private MySqlFixture $fixture;
    private $process;
    private string $directory;
    private string $origin;
    private array $config;
    protected function setUp(): void
    {
        if(!getenv('TEST_MYSQL_DSN'))self::markTestSkipped('Requires isolated MySQL 8.');
        $this->fixture=new MySqlFixture();
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        if(!$socket)throw new \RuntimeException('Cannot reserve test port.');
        $address=stream_socket_get_name($socket,false);fclose($socket);$this->origin='http://'.$address;
        $this->directory=sys_get_temp_dir().'/'.$this->fixture->database.'-http';mkdir($this->directory,0700);
        $this->config=array_replace($this->fixture->values,['APP_URL'=>$this->origin]);$this->writeConfig();
        $log=$this->directory.'/server.log';
        $environment=array_diff_key(getenv(),$this->config);
        $this->process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2),array_merge($environment,['ADELPOS_LICENSE_CONFIG'=>$this->directory.'/config.php']));
        fclose($pipes[0]);
        $deadline=microtime(true)+5;
        do{$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);return;}usleep(10000);}while(microtime(true)<$deadline);
        throw new \RuntimeException('Test HTTP server did not start.');
    }
    private function writeConfig(): void
    {
        $path=$this->directory.'/config.php';file_put_contents($path,"<?php return ".var_export($this->config,true).";\n");chmod($path,0600);
    }
    protected function tearDown(): void
    {
        if(isset($this->process)&&is_resource($this->process)){proc_terminate($this->process);proc_close($this->process);}
        if(isset($this->directory)){foreach(glob($this->directory.'/*')?:[] as $file)unlink($file);rmdir($this->directory);}
        if(isset($this->fixture))$this->fixture->destroy();
    }
    private function http(string $path,string $body='{}',string $method='POST',array $headers=['Content-Type: application/json']): array
    {
        $stream=fopen($this->origin.$path,'r',false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='GET'?'':$body,'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]));
        if(!$stream)throw new \RuntimeException('Test HTTP connection failed.');
        $raw=stream_get_contents($stream);$meta=stream_get_meta_data($stream);fclose($stream);
        $responseHeaders=$meta['wrapper_data'];preg_match('/HTTP\/\S+ (\d+)/',$responseHeaders[0],$match);
        return ['status'=>(int)$match[1],'body'=>$raw,'headers'=>strtolower(implode("\n",$responseHeaders)),'json'=>json_decode($raw,true)];
    }
    private function signed(string $action,array $device,string $key='',?string $activation=null): array
    {
        $challenge=$this->http('/v1/activations/challenge',Protocol::json(['action'=>$action,'product_id'=>'producto_prueba','installation_id'=>$device['id'],'activation_id'=>$activation]));
        self::assertSame(200,$challenge['status'],$challenge['body']);
        $input=['request_id'=>Uuid::create(),'product_id'=>'producto_prueba','installation_id'=>$device['id'],'challenge_id'=>$challenge['json']['challenge_id'],'app_version'=>'HTTP-test'];
        if($action==='activate')$input+=['license_key'=>$key,'installation_public_key'=>Protocol::encode(sodium_crypto_sign_publickey($device['pair'])),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('b',64)];else $input['activation_id']=$activation;
        $input['proof']=Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage($action,$input,$challenge['json']['nonce']),sodium_crypto_sign_secretkey($device['pair'])));
        return $input;
    }
    private function payload(array $response): array
    {
        self::assertSame(200,$response['status'],$response['body']);
        [$header,$payload,$signature]=explode('.',$response['json']['license_jws']);
        $kid=json_decode(sodium_base642bin($header,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true)['kid'];
        $public=$this->fixture->app->db->execute('SELECT public_key FROM signing_keys WHERE kid=?',[$kid])->fetchColumn();
        self::assertTrue(sodium_crypto_sign_verify_detached(Protocol::decode($signature,64),$header.'.'.$payload,$public));
        return json_decode(sodium_base642bin($payload,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
    }
    public function testOnlineLifecycleThroughRealHttpDoesNotCreatePanelSessions(): void
    {
        $app=$this->fixture->app;$keys=$this->fixture->signingKeys();$keys->generate('http-random-key','license');$keys->activate('http-random-key','HTTP fixture');
        $actor=new Actor($app->auth->bootstrap('admin@example.test','HTTP test','Fixture-Password-Only-2026!'),'superadmin');
        $customer=$app->catalog->saveCustomer($actor,Uuid::create(),null,['name'=>'HTTP client','contact_name'=>'Test','email'=>'','state'=>'active']);
        $license=$app->licenses->issue($actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'producto_prueba',...MySqlFixture::terms(),'features'=>['prueba_d'],'reason'=>'HTTP flow']);
        $device=['id'=>Uuid::create(),'pair'=>sodium_crypto_sign_keypair()];
        $request=$this->signed('activate',$device,$license['secret']);
        $activated=$this->http('/v1/activations',Protocol::json($request));$payload=$this->payload($activated);
        self::assertSame($activated['body'],$this->http('/v1/activations',Protocol::json(array_reverse($request,true)))['body']);
        self::assertStringNotContainsString('set-cookie:',$activated['headers']);self::assertStringContainsString('cache-control: no-store',$activated['headers']);
        $app->licenses->change($actor,Uuid::create(),$license['id'],'features',['version'=>1,'features'=>['prueba_b'],'reason'=>'HTTP rights update']);
        $fresh=$this->payload($this->http('/v1/activations/refresh',Protocol::json($this->signed('refresh',$device,'',$payload['activation_id']))));
        self::assertSame(2,$fresh['license_revision']);self::assertFalse($fresh['features']['prueba_d']);
        $deactivation=$this->signed('deactivate',$device,'',$payload['activation_id']);
        $ack=$this->http('/v1/activations/deactivate',Protocol::json($deactivation));self::assertSame(200,$ack['status']);self::assertSame('deactivated',$ack['json']['status']);
        self::assertSame($ack['body'],$this->http('/v1/activations/deactivate',Protocol::json($deactivation))['body']);
        $old=$this->payload($this->http('/v1/activations/refresh',Protocol::json($this->signed('refresh',$device,'',$payload['activation_id']))));self::assertSame('revoked',$old['license_status']);
        $other=['id'=>Uuid::create(),'pair'=>sodium_crypto_sign_keypair()];
        $next=$this->payload($this->http('/v1/activations',Protocol::json($this->signed('activate',$other,$license['secret']))));self::assertNotSame($payload['activation_id'],$next['activation_id']);
        $app->licenses->change($actor,Uuid::create(),$license['id'],'revoke',['version'=>2,'reason'=>'HTTP revocation']);
        $revoked=$this->payload($this->http('/v1/activations/refresh',Protocol::json($this->signed('refresh',$other,'',$next['activation_id']))));self::assertSame('revoked',$revoked['license_status']);
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM admin_sessions')->fetchColumn());
        self::assertStringNotContainsString($license['secret'],file_get_contents($this->directory.'/server.log'));
    }
    public function testServerAnswersUnavailableWhenItsActiveKeyBelongsToAnotherLicenseEnvironment(): void
    {
        $keys=$this->fixture->signingKeys();$kid=$keys->generate('','license')['kid'];$keys->activate($kid,'HTTP fixture');
        $path='/v1/activations/challenge';
        $body=Protocol::json(['action'=>'activate','product_id'=>'producto_prueba','installation_id'=>Uuid::create(),'activation_id'=>null]);
        self::assertSame(200,$this->http($path,$body)['status']);
        // Same database and keys, but the configuration now claims the other license environment.
        $this->config=array_replace($this->config,['APP_ENV'=>'production','APP_URL'=>'https://license.example.test','LICENSE_ENVIRONMENT'=>'production']);$this->writeConfig();
        $api=$this->http($path,$body);self::assertSame(503,$api['status']);self::assertSame('TEMPORARY_UNAVAILABLE',$api['json']['error']['code']);
        $panel=$this->http('/login','','GET');self::assertSame(503,$panel['status']);
        foreach([$api['body'],$panel['body']] as $response){self::assertStringNotContainsString($kid,$response);self::assertStringNotContainsString('LICENSE_ENVIRONMENT',$response);}
        self::assertStringContainsString('Problem at SigningKeys.php',file_get_contents($this->directory.'/server.log'));
        // A configuration without license environment does not start either.
        $this->config=array_replace($this->fixture->values,['APP_URL'=>$this->origin]);unset($this->config['LICENSE_ENVIRONMENT']);$this->writeConfig();
        self::assertSame(503,$this->http($path,$body)['status']);
        $this->config['LICENSE_ENVIRONMENT']='test';$this->writeConfig();
        self::assertSame(200,$this->http($path,$body)['status']);
        self::assertSame(2,(int)$this->fixture->owner->execute('SELECT COUNT(*) FROM activation_challenges')->fetchColumn());
    }
    public function testHttpValidationTlsAndRateLimitsUseStableJsonErrors(): void
    {
        $path='/v1/activations/challenge';
        $get=$this->http($path,'','GET');self::assertSame(405,$get['status']);self::assertStringContainsString('allow: post',$get['headers']);
        self::assertSame(404,$this->http('/v1/other')['status']);
        self::assertSame(415,$this->http($path,'{}','POST',['Content-Type: text/plain'])['status']);
        self::assertSame(400,$this->http($path,'{"action":"activate","action":"refresh"}')['status']);
        self::assertSame(413,$this->http($path,str_repeat(' ',16385))['status']);
        $invalid=$this->http('/v1/activations','{"request_id":"not-a-uuid"}');self::assertSame(400,$invalid['status']);self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/',$invalid['json']['error']['request_id']);
        $this->config['APP_ENV']='production';$this->config['APP_URL']='https://license.example.test';$this->writeConfig();
        self::assertSame(403,$this->http($path,'{}','POST',['Content-Type: application/json','X-Forwarded-Proto: https'])['status']);
        $this->config['APP_ENV']='testing';$this->config['APP_URL']=$this->origin;$this->writeConfig();
        $this->fixture->owner->execute('DELETE FROM rate_limit_buckets');
        $body=Protocol::json(['action'=>'activate','product_id'=>'producto_prueba','installation_id'=>Uuid::create(),'activation_id'=>null]);
        for($i=0;$i<30;++$i)self::assertSame(200,$this->http($path,$body)['status']);
        $limited=$this->http($path,$body);self::assertSame(429,$limited['status']);self::assertSame('RATE_LIMITED',$limited['json']['error']['code']);self::assertStringContainsString('retry-after: 60',$limited['headers']);
        self::assertStringNotContainsString('access-control-allow-origin:',$limited['headers']);
    }
}
