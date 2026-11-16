<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\TrustedDevices;
use AdelPos\License\Domain\Uuid;
use AdelPos\License\Tests\{MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

final class TrustedDevicesHttpTest extends TestCase
{
    private const PASSWORD = 'Fixture-Password-Only-2026!';
    private const SECRET = 'JBSWY3DPEHPK3PXP';
    private MySqlFixture $fixture;
    private NativeStackFixture $stack;
    private array $cookies = [];

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8, Nginx and PHP-FPM.'); }
        $this->fixture = new MySqlFixture();
        $this->stack = new NativeStackFixture($this->fixture);
        $app = $this->stack->app;
        $id = $app->auth->bootstrap('trust-http@example.test', 'Prueba HTTPS', self::PASSWORD);
        $app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [$app->crypto->encrypt(self::SECRET, 'mfa:' . $id), Uuid::bytes($id)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->stack)) { $this->stack->destroy(); }
        if (isset($this->fixture)) { $this->fixture->destroy(); }
    }

    private function request(string $path, ?array $form = null, array $extraHeaders = []): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . implode('; ', array_map(static fn ($name, $value) => $name . '=' . $value, array_keys($this->cookies), $this->cookies)), ...$extraHeaders];
        $result = $this->stack->request($path, $form === null ? '' : http_build_query($form), $form === null ? 'GET' : 'POST', $headers);
        foreach ($result['raw_headers'] as $header) {
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) {
                if ($match[2] === 'deleted') { unset($this->cookies[$match[1]]); }
                else { $this->cookies[$match[1]] = urldecode($match[2]); }
            }
        }
        return $result;
    }

    private function fields(array $fields = []): array
    {
        return $fields + ['csrf' => $this->stack->app->sessions->csrf($this->cookies['__Host-license_admin']), 'operation_id' => Uuid::create()];
    }

    private function passwordLogin(string $password = self::PASSWORD): array
    {
        $this->request('/login');
        return $this->request('/login', $this->fields(['login' => 'trust-http@example.test', 'password' => $password]));
    }

    private function totpLogin(): void
    {
        $response = $this->passwordLogin();
        self::assertSame(303, $response['status']);
        self::assertStringContainsString('location: /mfa', $response['headers']);
        $response = $this->request('/mfa', $this->fields(['code' => \OTPHP\TOTP::createFromSecret(self::SECRET)->now()]));
        self::assertSame(303, $response['status']);
        self::assertStringContainsString('location: /trust-device', $response['headers']);
    }

    public function testRealHttpsAuthorizationLogoutTrustedLoginAndConfirmedRevocation(): void
    {
        $this->totpLogin();
        $offer = $this->request('/trust-device');
        self::assertStringContainsString('¿Deseas confiar en este dispositivo?', $offer['body']);
        self::assertArrayNotHasKey(TrustedDevices::COOKIE, $this->cookies);
        $approved = $this->request('/trust-device', $this->fields(['choice' => 'trust']));
        self::assertSame(303, $approved['status']);
        foreach (['__host-adelpos_trusted_device=', 'httponly', '; secure', 'samesite=lax', 'path=/', 'expires='] as $attribute) { self::assertStringContainsString($attribute, $approved['headers']); }
        self::assertStringNotContainsString('domain=', $approved['headers']);
        $trust = $this->cookies[TrustedDevices::COOKIE];
        $stored = $this->stack->app->db->one('SELECT token_hash,expires_at FROM admin_trusted_devices');
        self::assertSame(hash('sha256', $trust), $stored['token_hash']);
        $approvedAgain = $this->request('/trust-device', $this->fields(['choice' => 'trust']));
        self::assertSame(303, $approvedAgain['status']);
        self::assertSame(1, (int) $this->stack->app->db->execute('SELECT COUNT(*) FROM admin_trusted_devices')->fetchColumn());
        $security = $this->request('/admin/security');
        self::assertSame(200, $security['status']);
        self::assertStringContainsString('Dispositivo actual', $security['body']);
        self::assertStringNotContainsString($trust, $security['body']);
        self::assertStringNotContainsString($stored['token_hash'], $security['body']);
        $logout = $this->request('/logout', $this->fields());
        self::assertSame(303, $logout['status']);
        self::assertSame($trust, $this->cookies[TrustedDevices::COOKIE]);
        self::assertStringContainsString('location: /login', $this->request('/admin')['headers']);
        self::assertSame(401, $this->passwordLogin('Incorrect-Password-2026!')['status']);
        $login = $this->passwordLogin();
        self::assertStringContainsString('location: /admin', $login['headers']);
        self::assertSame(200, $this->request('/admin')['status']);
        $cancel = $this->request('/admin/security/devices/revoke', $this->fields(['scope' => 'all', 'confirmation' => 'cancel']));
        self::assertSame(303, $cancel['status']);
        self::assertNull($this->stack->app->db->one('SELECT revoked_at FROM admin_trusted_devices')['revoked_at']);
        $revoke = $this->request('/admin/security/devices/revoke', $this->fields(['scope' => 'all', 'confirmation' => 'revoke']));
        self::assertSame(303, $revoke['status']);
        self::assertSame(200, $this->request('/admin')['status']);
        $this->request('/logout', $this->fields());
        self::assertStringContainsString('location: /mfa', $this->passwordLogin()['headers']);
    }

    public function testAuthorizationAndRevocationRejectCsrfForeignOriginAndUnverifiedSessions(): void
    {
        $this->request('/login');
        $anonymous = $this->request('/trust-device', $this->fields(['choice' => 'trust']));
        self::assertStringContainsString('location: /login', $anonymous['headers']);
        $this->totpLogin();
        self::assertSame(403, $this->request('/trust-device', $this->fields(['choice'=>'trust','csrf'=>'invalid']))['status']);
        self::assertSame(403, $this->request('/trust-device', $this->fields(['choice'=>'trust']), ['Origin: https://foreign.example.test'])['status']);
        self::assertSame(0, (int) $this->stack->app->db->execute('SELECT COUNT(*) FROM admin_trusted_devices')->fetchColumn());
        $this->request('/trust-device', $this->fields(['choice' => 'trust']));
        self::assertSame(403, $this->request('/admin/security/devices/revoke', $this->fields(['scope'=>'all','confirmation'=>'revoke','csrf'=>'invalid']))['status']);
        self::assertSame(403, $this->request('/admin/security/devices/revoke', $this->fields(['scope'=>'all','confirmation'=>'revoke']), ['Origin: https://foreign.example.test'])['status']);
        self::assertSame(422, $this->request('/admin/security/devices/revoke', $this->fields(['scope'=>'all']))['status']);
        self::assertNull($this->stack->app->db->one('SELECT revoked_at FROM admin_trusted_devices')['revoked_at']);
    }

    public function testDecliningAfterTotpCreatesNoCookieAndNextLoginStillRequiresMfa(): void
    {
        $this->totpLogin();
        $response = $this->request('/trust-device', $this->fields(['choice' => 'skip']));
        self::assertSame(303, $response['status']);
        self::assertArrayNotHasKey(TrustedDevices::COOKIE, $this->cookies);
        self::assertSame(200, $this->request('/admin')['status']);
        $this->request('/logout', $this->fields());
        self::assertStringContainsString('location: /mfa', $this->passwordLogin()['headers']);
    }
}
