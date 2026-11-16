<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Problem, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database, Sessions};

final class TrustedDevices
{
    public const COOKIE = '__Host-license_trusted_device';

    public function __construct(private Database $db, private Clock $clock, private Audit $audit, private Sessions $sessions) {}

    public static function cookieOptions(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
    }

    private static function tokenHash(?string $token): ?string
    {
        return $token !== null && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) ? hash('sha256', $token) : null;
    }

    private function binding(array $user): string
    {
        // Una sustitución de contraseña o secreto MFA invalida también autorizaciones antiguas.
        return hash('sha256', $user['password_hash'] . "\0" . ($user['mfa_secret_encrypted'] ?? ''));
    }

    /** Se ejecuta después de validar la contraseña y bloquear la cuenta, dentro de su transacción. */
    public function verify(array $user, ?string $token): bool
    {
        if (!$this->db->pdo->inTransaction()) { throw new \LogicException('Trusted login requires a transaction.'); }
        $hash = self::tokenHash($token);
        if ($hash === null || $user['state'] !== 'active' || $user['mfa_secret_encrypted'] === null) { return false; }
        $device = $this->db->one('SELECT * FROM admin_trusted_devices WHERE admin_id=? AND token_hash=? FOR UPDATE', [$user['id'], $hash]);
        if (!$device || $device['revoked_at'] !== null) { return false; }
        $adminId = Uuid::text($user['id']);
        if ($device['expires_at'] <= $this->clock->sql() || !hash_equals($device['credential_binding'], $this->binding($user))) {
            $this->db->execute('UPDATE admin_trusted_devices SET revoked_at=? WHERE id=?', [$this->clock->sql(), $device['id']]);
            $this->audit->append($adminId, 'trusted_device.invalidated', 'trusted_device', Uuid::text($device['id']), ['reason' => $device['expires_at'] <= $this->clock->sql() ? 'expired' : 'credentials_changed']);
            return false;
        }
        $this->db->execute('UPDATE admin_trusted_devices SET last_used_at=? WHERE id=?', [$this->clock->sql(), $device['id']]);
        $this->audit->append($adminId, 'session.trusted_device_login', 'trusted_device', Uuid::text($device['id']));
        return true;
    }

    public function canOffer(array $session): bool
    {
        return $session['stage'] === 'authenticated' && ($session['trust_offered_at'] ?? null) !== null
            && $session['trust_offered_at'] > $this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s.u');
    }

    /** La decisión sólo puede consumirse una vez y procede de un TOTP real, nunca de confianza o recuperación. */
    public function decide(string $sessionToken, bool $trust, string $userAgent, ?string $previousToken = null): ?array
    {
        $session = $this->sessions->load($sessionToken);
        if (!$session || !$this->canOffer($session)) { throw new Problem('Verifica un código nuevo de Authenticator para confiar en este dispositivo.', 403); }
        return $this->db->transaction(function () use ($session, $trust, $userAgent, $previousToken): ?array {
            $user = $this->db->one('SELECT * FROM admin_users WHERE id=? FOR UPDATE', [$session['admin_id']]);
            $fresh = $this->db->one('SELECT * FROM admin_sessions WHERE token_hash=? FOR UPDATE', [$session['token_hash']]);
            if (!$user || $user['state'] !== 'active' || $user['mfa_secret_encrypted'] === null || !$fresh
                || $fresh['revoked_at'] !== null || $fresh['expires_at'] <= $this->clock->sql()
                || $fresh['last_seen_at'] <= $this->clock->now()->modify('-30 minutes')->format('Y-m-d H:i:s.u')
                || !$this->canOffer($fresh)) { throw new Problem('La autorización venció. Inicia sesión y verifica Authenticator nuevamente.', 403); }
            $this->db->execute('UPDATE admin_sessions SET trust_offered_at=NULL WHERE token_hash=?', [$session['token_hash']]);
            if (!$trust) { return null; }
            $previousHash = self::tokenHash($previousToken);
            if ($previousHash !== null) {
                $previous = $this->db->one('SELECT id FROM admin_trusted_devices WHERE admin_id=? AND token_hash=? AND revoked_at IS NULL FOR UPDATE', [$user['id'], $previousHash]);
                if ($previous) {
                    $this->db->execute('UPDATE admin_trusted_devices SET revoked_at=? WHERE id=?', [$this->clock->sql(), $previous['id']]);
                    $this->audit->append($session['admin_uuid'], 'trusted_device.revoked', 'trusted_device', Uuid::text($previous['id']), ['reason' => 'replaced']);
                }
            }
            $id = Uuid::create();
            $token = Crypto::token();
            $expires = $this->clock->now()->modify('+30 days');
            $this->db->execute('INSERT INTO admin_trusted_devices(id,admin_id,token_hash,credential_binding,description,created_at,expires_at) VALUES (?,?,?,?,?,?,?)', [Uuid::bytes($id), $user['id'], hash('sha256', $token), $this->binding($user), self::description($userAgent), $this->clock->sql(), $expires->format('Y-m-d H:i:s.u')]);
            $this->audit->append($session['admin_uuid'], 'trusted_device.authorized', 'trusted_device', $id, ['expires_at' => $expires->format('Y-m-d H:i:s.u')]);
            return ['token' => $token, 'expires' => $expires->getTimestamp()];
        });
    }

    public function devices(Actor $actor, ?string $currentToken): array
    {
        $hash = self::tokenHash($currentToken);
        $rows = $this->db->all('SELECT id,token_hash,description,created_at,expires_at,last_used_at FROM admin_trusted_devices WHERE admin_id=? AND revoked_at IS NULL ORDER BY created_at DESC,id', [Uuid::bytes($actor->id)]);
        foreach ($rows as &$row) {
            $row['id'] = Uuid::text($row['id']);
            $row['expired'] = $row['expires_at'] <= $this->clock->sql();
            $row['current'] = !$row['expired'] && $hash !== null && hash_equals($row['token_hash'], $hash);
            unset($row['token_hash']);
        }
        return $rows;
    }

    public function revoke(Actor $actor, ?string $deviceId): void
    {
        $this->db->transaction(function () use ($actor, $deviceId): void {
            $user = $this->db->one('SELECT state FROM admin_users WHERE id=? FOR UPDATE', [Uuid::bytes($actor->id)]);
            if (!$user || $user['state'] !== 'active') { throw new Problem('La cuenta no está activa.', 403); }
            if ($deviceId === null) {
                $this->invalidate($actor->id, 'user_revoked_all', $actor->id);
                $this->audit->append($actor->id, 'trusted_device.revoked_all', 'admin', $actor->id);
                return;
            }
            $device = $this->db->one('SELECT id FROM admin_trusted_devices WHERE id=? AND admin_id=? FOR UPDATE', [Uuid::bytes($deviceId), Uuid::bytes($actor->id)]);
            if (!$device) { throw new Problem('No se encontró el dispositivo.', 404); }
            if ($this->db->execute('UPDATE admin_trusted_devices SET revoked_at=? WHERE id=? AND revoked_at IS NULL', [$this->clock->sql(), $device['id']])->rowCount() === 1) {
                $this->audit->append($actor->id, 'trusted_device.revoked', 'trusted_device', $deviceId);
            }
        });
    }

    /** Se integra en la misma transacción que cambia las credenciales o deshabilita la cuenta. */
    public function invalidate(string $adminId, string $reason, ?string $actorId): void
    {
        if (!$this->db->pdo->inTransaction()) { throw new \LogicException('Invalidation requires a transaction.'); }
        $count = $this->db->execute('UPDATE admin_trusted_devices SET revoked_at=? WHERE admin_id=? AND revoked_at IS NULL', [$this->clock->sql(), Uuid::bytes($adminId)])->rowCount();
        if ($count > 0) { $this->audit->append($actorId, 'trusted_device.invalidated', 'admin', $adminId, ['reason' => $reason, 'count' => $count]); }
    }

    private static function description(string $userAgent): string
    {
        // Sólo una etiqueta informativa: el User-Agent nunca participa en la autorización.
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge', str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari', default => 'Navegador',
        };
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows', str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux', default => '',
        };
        return $browser . ($system === '' ? '' : ' en ' . $system);
    }
}
