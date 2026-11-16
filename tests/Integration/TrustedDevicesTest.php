<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Integration;

use AdelPos\License\Application\{Actor, TrustedDevices};
use AdelPos\License\Domain\{Problem, Uuid};
use AdelPos\License\Infrastructure\Crypto;
use AdelPos\License\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class TrustedDevicesTest extends TestCase
{
	private const PASSWORD = 'Fixture-Password-Only-2026!';
	private MySqlFixture $fixture;
	private FrozenClock $clock;
	private Actor $actor;
	private string $secret;

	protected function setUp(): void
	{
		if (!getenv('TEST_MYSQL_DSN')) {
			self::markTestSkipped('Requires isolated MySQL 8.');
		}
		$this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00Z'));
		$this->fixture = new MySqlFixture($this->clock);
		$id = $this->fixture->app->auth->bootstrap('trusted@example.test', 'Prueba de confianza', self::PASSWORD);
		$this->actor = new Actor($id, 'superadmin');
	}

	protected function tearDown(): void
	{
		if (isset($this->fixture)) {
			$this->fixture->destroy();
		}
	}

	private function login(
		?string $trust = null,
		string $password = self::PASSWORD,
		string $login = 'trusted@example.test',
	): string {
		$app = $this->fixture->app;
		return $app->auth->login($login, $password, '127.0.0.1', $app->sessions->create(), $trust);
	}

	private function verify(string $pending): array
	{
		$app = $this->fixture->app;
		$session = $app->sessions->load($pending);
		if ($session['enrollment_secret'] !== null) {
			$this->secret = $app->crypto->decrypt($session['enrollment_secret'], 'mfa:' . $this->actor->id);
		}
		$code = \OTPHP\TOTP::createFromSecret($this->secret, $this->clock)->at($this->clock->now()->getTimestamp());
		return $app->auth->verifyMfa($pending, $code, '127.0.0.1');
	}

	private function enroll(): array
	{
		return $this->verify($this->login());
	}

	private function authorize(): array
	{
		$result = $this->enroll();
		return $this->fixture->app->trustedDevices->decide(
			$result['token'],
			true,
			'Mozilla/5.0 (Macintosh) Chrome/140.0 Safari/537.36',
		) + ['session' => $result['token']];
	}

	private function reject(callable $operation, int $status): void
	{
		try {
			$operation();
			self::fail('Se aceptó una operación no autorizada.');
		} catch (Problem $error) {
			self::assertSame($status, $error->status);
		}
	}

	public function testUnknownBrowserAndIncorrectTotpCannotCreateTrust(): void
	{
		$app = $this->fixture->app;
		$pending = $this->login(Crypto::token());
		self::assertSame('pending', $app->sessions->load($pending)['stage']);
		self::assertNull($app->sessions->load($pending)['user_mfa']);
		self::assertNotNull($app->sessions->load($pending)['enrollment_secret']);
		$this->reject(fn() => $app->auth->verifyMfa($pending, 'invalid', '127.0.0.1'), 401);
		$this->reject(fn() => $app->trustedDevices->decide($pending, true, ''), 403);
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM admin_trusted_devices')->fetchColumn());
	}

	public function testDecliningKeepsAuthenticatedSessionAndCreatesNoDevice(): void
	{
		$result = $this->enroll();
		$app = $this->fixture->app;
		self::assertCount(8, $result['recovery_codes']);
		self::assertTrue($app->trustedDevices->canOffer($app->sessions->load($result['token'])));
		self::assertNull($app->trustedDevices->decide($result['token'], false, ''));
		self::assertSame('authenticated', $app->sessions->load($result['token'])['stage']);
		self::assertSame(0, (int) $app->db->execute('SELECT COUNT(*) FROM admin_trusted_devices')->fetchColumn());
		$this->reject(fn() => $app->trustedDevices->decide($result['token'], true, ''), 403);
	}

	public function testExplicitTrustIsHashedAccountBoundAndExpiresAfterThirtyDaysWithoutSliding(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$before = $app->db->one('SELECT * FROM admin_trusted_devices');
		self::assertSame(hash('sha256', $grant['token']), $before['token_hash']);
		self::assertNotSame($grant['token'], $before['token_hash']);
		self::assertSame(Uuid::bytes($this->actor->id), $before['admin_id']);
		self::assertSame('Chrome en macOS', $before['description']);
		self::assertSame('2026-12-03 12:00:00.000000', $before['expires_at']);
		$this->clock->instant = $this->clock->now()->modify('+1 day');
		$token = $this->login($grant['token']);
		self::assertSame('authenticated', $app->sessions->load($token)['stage']);
		self::assertFalse($app->trustedDevices->canOffer($app->sessions->load($token)));
		$after = $app->db->one('SELECT * FROM admin_trusted_devices');
		self::assertSame($before['expires_at'], $after['expires_at']);
		self::assertSame($this->clock->sql(), $after['last_used_at']);
		$this->reject(fn() => $app->trustedDevices->decide($token, true, ''), 403);
		$devices = $app->trustedDevices->devices($this->actor, $grant['token']);
		self::assertTrue($devices[0]['current']);
		self::assertArrayNotHasKey('token_hash', $devices[0]);
		foreach ($app->db->all('SELECT payload_json FROM audit_events') as $event) {
			self::assertStringNotContainsString($grant['token'], $event['payload_json']);
			self::assertStringNotContainsString($this->secret, $event['payload_json']);
		}
		self::assertGreaterThan(0, $app->audit->verify());
	}

	public function testTrustNeverAcceptsWrongPasswordAndNeverAuthenticatesByItself(): void
	{
		$grant = $this->authorize();
		$this->reject(fn() => $this->login($grant['token'], 'Wrong-Password-Only-2026!'), 401);
		self::assertNull($this->fixture->app->sessions->load($grant['token']));
		self::assertSame(
			1,
			(int) $this->fixture->app->db
				->execute("SELECT COUNT(*) FROM admin_sessions WHERE stage='authenticated' AND revoked_at IS NULL")
				->fetchColumn(),
		);
	}

	public function testMissingMalformedTamperedAndAnotherBrowsersTokensRequireTotp(): void
	{
		$grant = $this->authorize();
		foreach ([null, 'invalid', Crypto::token(), substr($grant['token'], 0, 42)] as $token) {
			self::assertSame('pending', $this->fixture->app->sessions->load($this->login($token))['stage']);
		}
	}

	public function testAnotherUsersCookieDoesNotBypassTotpOrAllowRevocation(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$other = $app->admins->create($this->actor, Uuid::create(), [
			'login' => 'other@example.test',
			'name' => 'Otra cuenta',
			'role' => 'viewer',
			'new_password' => self::PASSWORD,
		]);
		$app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [
			$app->crypto->encrypt('JBSWY3DPEHPK3PXP', 'mfa:' . $other['id']),
			Uuid::bytes($other['id']),
		]);
		$pending = $this->login($grant['token'], self::PASSWORD, 'other@example.test');
		self::assertSame('pending', $app->sessions->load($pending)['stage']);
		$device = $app->trustedDevices->devices($this->actor, $grant['token'])[0];
		$this->reject(fn() => $app->trustedDevices->revoke(new Actor($other['id'], 'viewer'), $device['id']), 404);
		self::assertFalse($app->trustedDevices->devices($this->actor, null)[0]['current']);
	}

	public function testIndividualRevocationIsImmediateAndDoesNotEndSessionsOrTotp(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$device = $app->trustedDevices->devices($this->actor, $grant['token'])[0];
		$app->trustedDevices->revoke($this->actor, $device['id']);
		self::assertSame('pending', $app->sessions->load($this->login($grant['token']))['stage']);
		self::assertSame('authenticated', $app->sessions->load($grant['session'])['stage']);
		self::assertNotNull($app->db->execute('SELECT mfa_secret_encrypted FROM admin_users')->fetchColumn());
	}

	public function testMultipleDevicesCanBeRevokedIndependentlyAndAllTogether(): void
	{
		$first = $this->authorize();
		$app = $this->fixture->app;
		$this->clock->instant = $this->clock->now()->modify('+30 seconds');
		$result = $this->verify($this->login());
		$second = $app->trustedDevices->decide($result['token'], true, 'Firefox/140.0');
		self::assertCount(2, $app->trustedDevices->devices($this->actor, null));
		$firstDevice = $app->db->one('SELECT id FROM admin_trusted_devices WHERE token_hash=?', [
			hash('sha256', $first['token']),
		]);
		$app->trustedDevices->revoke($this->actor, Uuid::text($firstDevice['id']));
		self::assertSame('authenticated', $app->sessions->load($this->login($second['token']))['stage']);
		$app->trustedDevices->revoke($this->actor, null);
		self::assertSame('pending', $app->sessions->load($this->login($second['token']))['stage']);
		self::assertSame([], $app->trustedDevices->devices($this->actor, null));
	}

	public function testExpiryBoundaryRequiresTotpAndIsAudited(): void
	{
		$grant = $this->authorize();
		$this->clock->instant = $this->clock->now()->modify('+30 days');
		$pending = $this->login($grant['token']);
		self::assertSame('pending', $this->fixture->app->sessions->load($pending)['stage']);
		$event = $this->fixture->app->db->one(
			"SELECT payload_json FROM audit_events WHERE operation='trusted_device.invalidated'",
		);
		self::assertStringContainsString('expired', $event['payload_json']);
		$result = $this->verify($pending);
		$renewed = $this->fixture->app->trustedDevices->decide($result['token'], true, '', $grant['token']);
		self::assertNotSame($grant['token'], $renewed['token']);
		self::assertSame($this->clock->now()->modify('+30 days')->getTimestamp(), $renewed['expires']);
		self::assertSame(
			'authenticated',
			$this->fixture->app->sessions->load($this->login($renewed['token']))['stage'],
		);
	}

	public function testPasswordChangeRevokesAllTrustAndPreservesExistingSessionRevocationPolicy(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$app->auth->changePassword($this->actor, 'New-Fixture-Password-2026!');
		self::assertNull($app->sessions->load($grant['session']));
		self::assertSame([], $app->trustedDevices->devices($this->actor, null));
		self::assertSame(
			'pending',
			$app->sessions->load($this->login($grant['token'], 'New-Fixture-Password-2026!'))['stage'],
		);
	}

	public function testCredentialBindingRejectsPasswordResetAndMfaReconfiguration(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		// Simula una sustitución externa: ni siquiera omitir el gancho de revocación conserva la confianza.
		$app->db->execute('UPDATE admin_users SET password_hash=? WHERE id=?', [
			password_hash(self::PASSWORD, PASSWORD_ARGON2ID, [
				'memory_cost' => 65536,
				'time_cost' => 3,
				'threads' => 1,
			]),
			Uuid::bytes($this->actor->id),
		]);
		self::assertSame('pending', $app->sessions->load($this->login($grant['token']))['stage']);
		$this->clock->instant = $this->clock->now()->modify('+30 seconds');
		$fresh = $this->verify($this->login());
		$second = $app->trustedDevices->decide($fresh['token'], true, '');
		$app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [
			$app->crypto->encrypt('JBSWY3DPEHPK3PXP', 'mfa:' . $this->actor->id),
			Uuid::bytes($this->actor->id),
		]);
		self::assertSame('pending', $app->sessions->load($this->login($second['token']))['stage']);
	}

	public function testDisabledAccountLosesTrustEvenAfterReactivation(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$other = $app->admins->create($this->actor, Uuid::create(), [
			'login' => 'second@example.test',
			'name' => 'Otro administrador',
			'role' => 'superadmin',
			'new_password' => self::PASSWORD,
		]);
		$actor = new Actor($other['id'], 'superadmin');
		$app->admins->update($actor, Uuid::create(), $this->actor->id, [
			'role' => 'superadmin',
			'state' => 'disabled',
			'reason' => 'Prueba de deshabilitación',
		]);
		$this->reject(fn() => $this->login($grant['token']), 401);
		$app->admins->update($actor, Uuid::create(), $this->actor->id, [
			'role' => 'superadmin',
			'state' => 'active',
			'reason' => 'Prueba de rehabilitación',
		]);
		self::assertSame('pending', $app->sessions->load($this->login($grant['token']))['stage']);
	}

	public function testLogoutKeepsTrustAndTrustedSessionsStillExpireAfterInactivityAndEightHours(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$app->auth->logout($grant['session'], $this->actor->id);
		self::assertNull($app->sessions->load($grant['session']));
		$token = $this->login($grant['token']);
		self::assertSame('authenticated', $app->sessions->load($token)['stage']);
		$this->clock->instant = $this->clock->now()->modify('+30 minutes');
		self::assertNull($app->sessions->load($token));
		$token = $this->login($grant['token']);
		$record = $app->sessions->load($token);
		$this->clock->instant = $this->clock->now()->modify('+8 hours');
		$this->fixture->owner->execute('UPDATE admin_sessions SET last_seen_at=? WHERE token_hash=?', [
			$this->clock->sql(),
			$record['token_hash'],
		]);
		self::assertNull($app->sessions->load($token));
	}

	public function testTrustQueryFailureFallsBackToRealTotpAndDecliningStillWorks(): void
	{
		$grant = $this->authorize();
		$app = $this->fixture->app;
		$this->fixture->owner->execute('RENAME TABLE admin_trusted_devices TO unavailable_trusted_devices');
		try {
			$pending = $this->login($grant['token']);
			self::assertSame('pending', $app->sessions->load($pending)['stage']);
			$this->clock->instant = $this->clock->now()->modify('+30 seconds');
			$result = $this->verify($pending);
			self::assertSame('authenticated', $app->sessions->load($result['token'])['stage']);
			self::assertNull($app->trustedDevices->decide($result['token'], false, ''));
		} finally {
			$this->fixture->owner->execute('RENAME TABLE unavailable_trusted_devices TO admin_trusted_devices');
		}
	}

	public function testRecoveryCodesAndStaleOffersCannotAuthorizeTrust(): void
	{
		$result = $this->enroll();
		$app = $this->fixture->app;
		$this->clock->instant = $this->clock->now()->modify('+10 minutes');
		$this->reject(fn() => $app->trustedDevices->decide($result['token'], true, ''), 403);
		$recovered = $app->auth->verifyMfa($this->login(), $result['recovery_codes'][0], '127.0.0.1');
		self::assertSame('authenticated', $app->sessions->load($recovered['token'])['stage']);
		$this->reject(fn() => $app->trustedDevices->decide($recovered['token'], true, ''), 403);
	}

	public function testTrustDoesNotReplaceReauthenticationForSensitiveOperations(): void
	{
		$grant = $this->authorize();
		self::assertSame('authenticated', $this->fixture->app->sessions->load($this->login($grant['token']))['stage']);
		$this->reject(fn() => $this->fixture->app->auth->reauthenticate($this->actor, self::PASSWORD, 'invalid'), 401);
	}
}
