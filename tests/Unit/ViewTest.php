<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Http\View;
use PHPUnit\Framework\TestCase;

/** Textos del panel y de las constancias para el modelo de licencias del contrato. */
final class ViewTest extends TestCase
{
	public function testDatesShowTheirTimeZoneAndLicenseDatesUseTheCustomerOne(): void
	{
		// Sin zona se usa la del panel.
		self::assertSame('16/11/2026 · 00:30 America/Monterrey', View::date('2026-11-16 06:30:00.000000'));
		// El mismo instante es el día anterior en Tijuana.
		self::assertSame(
			'15/11/2026 · 22:30 America/Tijuana',
			View::date('2026-11-16 06:30:00.000000', 'America/Tijuana'),
		);
		self::assertSame('16/11/2026 · 06:30 UTC', View::date('2026-11-16 06:30:00', 'UTC'));
		// Una zona que no existe no rompe la pantalla: se muestra la del panel.
		self::assertSame('16/11/2026 · 00:30 America/Monterrey', View::date('2026-11-16 06:30:00', 'Marte/Olimpo'));
		self::assertSame('—', View::date(null));
		self::assertSame('—', View::date('', 'UTC'));

		$license = [
			'license_type' => 'subscription',
			'time_zone' => 'America/Tijuana',
			'period_starts_at' => '2026-10-31 12:00:00.000000',
			'expires_at' => '2026-11-30 13:00:00.000000',
			'updates_until' => null,
		];
		self::assertSame('31/10/2026 · 05:00 America/Tijuana', View::licenseDate($license, 'period_starts_at'));
		// Después del cambio de horario, la misma hora local.
		self::assertSame('30/11/2026 · 05:00 America/Tijuana', View::expiry($license));
		// Una licencia emitida no tiene fechas hasta que se activa.
		self::assertSame('Empieza al activar', View::licenseDate($license, 'updates_until'));
		self::assertSame('Empieza al activar', View::expiry(['expires_at' => null] + $license));
		self::assertSame('Sin vencimiento', View::expiry(['license_type' => 'perpetual', 'expires_at' => null]));
		// Sin zona del cliente en la fila, la del panel.
		self::assertSame(
			'30/11/2026 · 07:00 America/Monterrey',
			View::licenseDate(['expires_at' => '2026-11-30 13:00:00'], 'expires_at'),
		);
	}

	public function testLicenseTermsNameTheTypeWithItsCondition(): void
	{
		$cases = [
			'Perpetua · sin actualizaciones' => ['license_type' => 'perpetual', 'updates_months' => 0],
			'Perpetua · 6 meses de actualizaciones' => ['license_type' => 'perpetual', 'updates_months' => '6'],
			'Perpetua · 12 meses de actualizaciones' => ['license_type' => 'perpetual', 'updates_months' => 12],
			'Suscripción mensual' => ['license_type' => 'subscription', 'billing_period' => 'monthly'],
			'Suscripción trimestral' => ['license_type' => 'subscription', 'billing_period' => 'quarterly'],
			'Suscripción semestral' => ['license_type' => 'subscription', 'billing_period' => 'semiannual'],
			'Suscripción anual' => ['license_type' => 'subscription', 'billing_period' => 'annual'],
			'Prueba de 15 días' => ['license_type' => 'trial', 'trial_duration' => '15d'],
			'Prueba de 1 mes' => ['license_type' => 'trial', 'trial_duration' => '1m'],
			'Prueba de 3 meses' => ['license_type' => 'trial', 'trial_duration' => '3m'],
		];
		foreach ($cases as $expected => $license) {
			self::assertSame($expected, View::licenseTerms($license));
		}
	}

	public function testRevisionStatusExplainsWhyARevisionWasClosed(): void
	{
		self::assertSame('Activa', View::revisionStatus('active', null));
		self::assertSame('Revocada · licencia liberada', View::revisionStatus('revoked', 'deactivated'));
		self::assertSame('Revocada · transferida a otra computadora', View::revisionStatus('revoked', 'transferred'));
		self::assertSame('Revocada · revocada por el propietario', View::revisionStatus('revoked', 'revoked'));
		self::assertSame('Revocada · sin motivo registrado', View::revisionStatus('revoked', null));
	}
}
