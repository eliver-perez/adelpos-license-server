<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Tests\{MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

final class ProductionHttpTest extends TestCase
{
    private MySqlFixture $fixture;
    private NativeStackFixture $stack;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8, Nginx and PHP-FPM.'); }
        $this->fixture = new MySqlFixture();
        $this->stack = new NativeStackFixture($this->fixture);
    }

    protected function tearDown(): void
    {
        if (isset($this->stack)) { $this->stack->destroy(); }
        if (isset($this->fixture)) { $this->fixture->destroy(); }
    }

    public function testCommercialControllersEnforceBackendPermissionsAndPublicVerificationPrivacy(): void
    {
        $app=$this->stack->app;
        $password='Synthetic-Http-Only-2026!';$mfa='JBSWY3DPEHPK3PXP';
        $actor=new \AdelPos\License\Application\Actor($app->auth->bootstrap('http-doc@example.test','Responsable',$password),'superadmin');
        $app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?',[$app->crypto->encrypt($mfa,'mfa:'.$actor->id),\AdelPos\License\Domain\Uuid::bytes($actor->id)]);
        $customer=$app->catalog->saveCustomer($actor,\AdelPos\License\Domain\Uuid::create(),null,['name'=>'Titular privado','contact_name'=>'Contacto','email'=>'','state'=>'active']);
        $license=$app->licenses->issue($actor,\AdelPos\License\Domain\Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'producto_prueba','license_type'=>'perpetual','entitled_release_until'=>gmdate('Y-m-d\TH:i')]);
        $token=$app->sessions->create('authenticated',$actor->id);
        $headers=['Content-Type: application/x-www-form-urlencoded','Cookie: __Host-license_admin='.$token];
        $form=['csrf'=>$app->sessions->csrf($token),'operation_id'=>\AdelPos\License\Domain\Uuid::create(),'document_type'=>'licensing'];
        $generated=$this->stack->request('/admin/licenses/'.$license['id'].'/documents',http_build_query($form),'POST',$headers);
        self::assertSame(303,$generated['status']);
        $row=$app->db->one('SELECT BIN_TO_UUID(id) AS id_text,snapshot_encrypted FROM license_documents');
        $download=$this->stack->request('/admin/documents/'.$row['id_text'].'/download','','GET',$headers);
        self::assertSame(200,$download['status']);self::assertStringStartsWith('%PDF-',$download['body']);
        $snapshot=json_decode($app->crypto->open($row['snapshot_encrypted'],'document-snapshot:'.$row['id_text'],'document'),true);
        $verified=$this->stack->request('/verify/'.basename($snapshot['verification_url']),'','GET');
        self::assertSame(200,$verified['status']);self::assertStringContainsString(hash('sha256',$download['body']),$verified['body']);
        self::assertStringNotContainsString('set-cookie:',$verified['headers']);
        foreach ([$license['id'],$license['secret'],'Titular privado',$token] as $secret) { self::assertStringNotContainsString($secret,$verified['body']); }
        $viewer=$app->admins->create($actor,\AdelPos\License\Domain\Uuid::create(),['login'=>'no-permission@example.test','name'=>'Consulta','role'=>'viewer','new_password'=>$password]);
        $app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?',[$app->crypto->encrypt($mfa,'mfa:'.$viewer['id']),\AdelPos\License\Domain\Uuid::bytes($viewer['id'])]);
        $viewerToken=$app->sessions->create('authenticated',$viewer['id']);
        $viewerHeaders=['Content-Type: application/x-www-form-urlencoded','Cookie: __Host-license_admin='.$viewerToken];
        self::assertSame(403,$this->stack->request('/admin/documents/'.$row['id_text'].'/download','','GET',$viewerHeaders)['status']);
        $form=['csrf'=>$app->sessions->csrf($viewerToken),'operation_id'=>\AdelPos\License\Domain\Uuid::create(),'password'=>$password,'code'=>\OTPHP\TOTP::createFromSecret($mfa)->now()];
        $denied=$this->stack->request('/admin/licenses/'.$license['id'].'/reveal',http_build_query($form),'POST',$viewerHeaders);
        self::assertSame(403,$denied['status']);self::assertStringNotContainsString($license['secret'],$denied['body']);
        $form+=['reference'=>'Intrusión','reason'=>'','administrative_version'=>1];
        self::assertSame(403,$this->stack->request('/admin/licenses/'.$license['id'].'/administrative',http_build_query($form),'POST',$viewerHeaders)['status']);
        $form['csrf']=$app->sessions->csrf($token);$form['operation_id']=\AdelPos\License\Domain\Uuid::create();
        self::assertStringContainsString($license['secret'],$this->stack->request('/admin/licenses/'.$license['id'].'/reveal',http_build_query($form),'POST',$headers)['body']);
    }

    public function testProductionCookiesBrandAssetsAndAccessControls(): void
    {
        $login = $this->stack->request('/login', '', 'GET');
        self::assertSame(200, $login['status']);
        foreach (['__host-license_admin=', '; secure', 'httponly', 'samesite=lax', 'path=/', 'content-security-policy:', 'strict-transport-security:', 'cache-control: no-store'] as $value) {
            self::assertStringContainsString($value, $login['headers']);
        }
        self::assertStringNotContainsString('domain=', $login['headers']);
        self::assertStringNotContainsString('x-powered-by:', $login['headers']);
        self::assertStringNotContainsString('adelpos_admin_dev', $login['headers']);
        self::assertStringContainsString('AD-EL POS — Licencias', $login['body']);
        self::assertStringContainsString('/assets/brand/adel-pos-tema-oscuro.png', $login['body']);
        self::assertStringContainsString('<link rel="icon" type="image/png" href="/assets/brand/icon.png">', $login['body']);
        // Brand files are served byte for byte and stay exactly as the owner supplied them.
        foreach (['brand/adel-pos-tema-claro.png' => 'fece00410137a618703c5921d2ce2aac5ef06227836b75505201e58125e05434', 'brand/adel-pos-tema-oscuro.png' => '82e1a563a24ebe59fa3676fbb8cae430da4ddc263034583a0f40bbd985e29116', 'brand/icon.png' => '241504ff0356a39044862474bf3b071d82418a20763f001ba5e2f6287804456c', 'app.css' => null, 'app.js' => null] as $file => $sha256) {
            $asset = $this->stack->request('/assets/'.$file, '', 'GET');
            self::assertSame(200, $asset['status']);
            self::assertSame(file_get_contents(dirname(__DIR__, 2).'/public/assets/'.$file), $asset['body']);
            if ($sha256 !== null) { self::assertSame($sha256, hash('sha256', $asset['body'])); self::assertStringContainsString('content-type: image/png', $asset['headers']); }
        }
        $admin = $this->stack->request('/admin', '', 'GET');
        self::assertSame(303, $admin['status']);
        self::assertStringContainsString('location: /login', $admin['headers']);
        self::assertSame(403, $this->stack->request('/login', 'csrf=invalid', 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://foreign.example.test'])['status']);
        self::assertSame(404, $this->stack->request('/test.php', '', 'GET')['status']);
        self::assertSame(403, $this->stack->request('/.env', '', 'GET')['status']);
        $api = $this->stack->request('/v1/activations/challenge');
        self::assertSame(400, $api['status']);
        self::assertSame('INVALID_REQUEST', $api['json']['error']['code']);
        self::assertStringNotContainsString('access-control-allow-origin:', $api['headers']);
        self::assertStringNotContainsString('set-cookie:', $api['headers']);
    }

    public function testBootstrapFailureHidesSecretsAndKeepsSafeDiagnostic(): void
    {
        // Test-only bad URL: the production boot guard fails before connecting to SQL.
        $values = array_replace($this->stack->values, ['APP_URL'=>'https://private-secret@example.test']);
        $this->stack->privateFile('config.php', "<?php return ".var_export($values, true).";\n");
        $response = $this->stack->request('/login', '', 'GET');
        self::assertSame(503, $response['status']);
        self::assertStringContainsString('AD-EL POS — Licencias · Servicio no disponible', $response['body']);
        foreach (['private-secret', 'RuntimeException', $this->stack->directory, $values['DB_PASSWORD'], $values['MFA_KEY']] as $value) {
            self::assertStringNotContainsString($value, $response['body']);
        }
        $api = $this->stack->request('/v1/activations/challenge');
        self::assertSame(503, $api['status']);
        self::assertSame('TEMPORARY_UNAVAILABLE', $api['json']['error']['code']);
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $api['json']['error']['request_id']);
        $log = file_get_contents($this->stack->directory.'/log/php-error.log');
        self::assertStringContainsString('adelpos-license [', $log);
        self::assertStringContainsString('RuntimeException at Config.php:', $log);
        foreach (['private-secret', $values['DB_PASSWORD'], $values['MFA_KEY']] as $value) {
            self::assertStringNotContainsString($value, $log);
        }
    }
}
