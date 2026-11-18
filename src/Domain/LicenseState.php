<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Estado de una licencia tal como lo muestra el panel (docs/PANEL.md, sección 5), con las mismas reglas
 * del contrato (§5.6) y la hora del servidor:
 *
 * - revocada: la licencia comercial está revocada;
 * - sin activar: no está revocada y todavía no tiene fechas;
 * - vigente: perpetua activada, o antes de su vencimiento;
 * - en tolerancia: desde el vencimiento y durante sus días de tolerancia (24 horas exactas cada uno);
 * - vencida: con la tolerancia agotada.
 *
 * También cuenta los días de calendario hasta una fecha en la zona del cliente, para «por vencer».
 */
final class LicenseState
{
	public const REVOKED = 'revoked';
	public const PENDING = 'pending';
	public const ACTIVE = 'active';
	public const GRACE = 'grace';
	public const EXPIRED = 'expired';

	/** Estados en el orden en que se ofrecen en los filtros. */
	public const ALL = [self::ACTIVE, self::GRACE, self::EXPIRED, self::REVOKED, self::PENDING];

	public const LABELS = [
		self::ACTIVE => 'Vigente',
		self::GRACE => 'En tolerancia',
		self::EXPIRED => 'Vencida',
		self::REVOKED => 'Revocada',
		self::PENDING => 'Sin activar',
	];

	/**
	 * Estado de una licencia en un instante. Necesita commercial_status, license_type, period_starts_at,
	 * expires_at (UTC, formato de MySQL) y grace_days.
	 */
	public static function of(array $license, \DateTimeImmutable $now): string
	{
		if ($license['commercial_status'] === 'revoked') {
			return self::REVOKED;
		}
		if ($license['period_starts_at'] === null) {
			return self::PENDING;
		}
		// Una licencia activada sin vencimiento es una perpetua: la base exige expires_at vacío en la perpetua y
		// lleno en la suscripción y la prueba activadas (chk_license_period). No hace falta mirar el tipo.
		if ($license['expires_at'] === null) {
			return self::ACTIVE;
		}
		$expires = self::instant($license['expires_at']);
		if ($now < $expires) {
			return self::ACTIVE;
		}
		return $now < self::graceLimit($license['expires_at'], (int) $license['grace_days'])
			? self::GRACE
			: self::EXPIRED;
	}

	/** Fin de la tolerancia: el vencimiento más grace_days × 86 400 segundos, exclusivo (contrato §5.6). */
	public static function graceLimit(string $expiresAt, int $graceDays): \DateTimeImmutable
	{
		return self::instant($expiresAt)->modify('+' . $graceDays * 86400 . ' seconds');
	}

	/** Nombre del estado; una prueba vigente se llama «Prueba vigente». */
	public static function label(string $state, string $licenseType): string
	{
		if ($state === self::ACTIVE && $licenseType === 'trial') {
			return 'Prueba vigente';
		}
		return self::LABELS[$state] ?? $state;
	}

	/** Tono de color de la insignia (docs/PANEL.md, sección 5). */
	public static function tone(string $state, string $licenseType): string
	{
		return match ($state) {
			self::ACTIVE => $licenseType === 'trial' ? 'blue' : 'green',
			self::GRACE => 'amber',
			self::EXPIRED => 'red',
			self::REVOKED => 'darkred',
			default => 'outline',
		};
	}

	/**
	 * Días de calendario, en la zona del cliente, desde hoy hasta la fecha de $instant: 0 si es hoy, 1 si
	 * es mañana, negativo si ya pasó.
	 */
	public static function calendarDays(string $instant, string $zone, \DateTimeImmutable $now): int
	{
		$timeZone = new \DateTimeZone(TimeZones::valid($zone) ? $zone : TimeZones::DEFAULT);
		$today = new \DateTimeImmutable($now->setTimezone($timeZone)->format('Y-m-d'), new \DateTimeZone('UTC'));
		$day = new \DateTimeImmutable(
			self::instant($instant)->setTimezone($timeZone)->format('Y-m-d'),
			new \DateTimeZone('UTC'),
		);
		return (int) round(($day->getTimestamp() - $today->getTimestamp()) / 86400);
	}

	/** Un instante guardado (UTC, formato de MySQL). */
	public static function instant(string $value): \DateTimeImmutable
	{
		return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
	}
}
