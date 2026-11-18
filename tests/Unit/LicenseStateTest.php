<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\LicenseState;
use PHPUnit\Framework\TestCase;

/** Estado de una licencia en el panel (docs/PANEL.md, sección 5): las reglas del contrato (§5.6). */
final class LicenseStateTest extends TestCase
{
	private static function license(array $changes = []): array
	{
		return $changes + [
			'commercial_status' => 'issued',
			'license_type' => 'subscription',
			'period_starts_at' => '2027-01-10 16:00:00.000000',
			'expires_at' => '2027-02-10 16:00:00.000000',
			'grace_days' => 5,
		];
	}

	private static function at(string $instant): \DateTimeImmutable
	{
		return new \DateTimeImmutable($instant);
	}

	public function testStatesHaveExactLimits(): void
	{
		$license = self::license();
		self::assertSame('active', LicenseState::of($license, self::at('2027-02-10T15:59:59.999999Z')));
		// El vencimiento es exclusivo: en ese instante empieza la tolerancia.
		self::assertSame('grace', LicenseState::of($license, self::at('2027-02-10T16:00:00Z')));
		// La tolerancia son 5 × 86 400 segundos, también exclusiva.
		self::assertSame('grace', LicenseState::of($license, self::at('2027-02-15T15:59:59.999999Z')));
		self::assertSame('expired', LicenseState::of($license, self::at('2027-02-15T16:00:00Z')));
		// Una prueba no tiene tolerancia.
		$trial = self::license(['license_type' => 'trial', 'grace_days' => 0]);
		self::assertSame('active', LicenseState::of($trial, self::at('2027-02-10T15:59:59Z')));
		self::assertSame('expired', LicenseState::of($trial, self::at('2027-02-10T16:00:00Z')));
		// Una perpetua activada siempre está vigente; una sin activar no tiene fechas.
		$perpetual = self::license(['license_type' => 'perpetual', 'expires_at' => null, 'grace_days' => 0]);
		self::assertSame('active', LicenseState::of($perpetual, self::at('2099-01-01T00:00:00Z')));
		$pending = self::license(['period_starts_at' => null, 'expires_at' => null]);
		self::assertSame('pending', LicenseState::of($pending, self::at('2099-01-01T00:00:00Z')));
		// Revocada gana a todo lo demás.
		self::assertSame(
			'revoked',
			LicenseState::of(self::license(['commercial_status' => 'revoked']), self::at('2027-01-11T00:00:00Z')),
		);
		self::assertSame(
			'revoked',
			LicenseState::of(['commercial_status' => 'revoked'] + $pending, self::at('2027-01-11T00:00:00Z')),
		);
		self::assertSame(
			'2027-02-15 16:00:00',
			LicenseState::graceLimit('2027-02-10 16:00:00.000000', 5)->format('Y-m-d H:i:s'),
		);
	}

	public function testLabelsAndTonesFollowTheColorTable(): void
	{
		self::assertSame(
			['Vigente', 'green'],
			[LicenseState::label('active', 'subscription'), LicenseState::tone('active', 'subscription')],
		);
		self::assertSame(
			['Prueba vigente', 'blue'],
			[LicenseState::label('active', 'trial'), LicenseState::tone('active', 'trial')],
		);
		self::assertSame(
			['En tolerancia', 'amber'],
			[LicenseState::label('grace', 'subscription'), LicenseState::tone('grace', 'x')],
		);
		self::assertSame(
			['Vencida', 'red'],
			[LicenseState::label('expired', 'trial'), LicenseState::tone('expired', 'trial')],
		);
		self::assertSame(
			['Revocada', 'darkred'],
			[LicenseState::label('revoked', 'trial'), LicenseState::tone('revoked', 'x')],
		);
		self::assertSame(
			['Sin activar', 'outline'],
			[LicenseState::label('pending', 'trial'), LicenseState::tone('pending', 'x')],
		);
	}

	public function testCalendarDaysCountLocalDatesAcrossDaylightSavingChanges(): void
	{
		// 9 de febrero a las 23:30 en Tijuana es el 10 de febrero en UTC.
		$expires = '2027-02-10 07:30:00.000000';
		self::assertSame(7, LicenseState::calendarDays($expires, 'America/Tijuana', self::at('2027-02-02T08:00:00Z')));
		self::assertSame(8, LicenseState::calendarDays($expires, 'America/Tijuana', self::at('2027-02-02T07:59:59Z')));
		self::assertSame(0, LicenseState::calendarDays($expires, 'America/Tijuana', self::at('2027-02-10T07:29:00Z')));
		self::assertSame(-1, LicenseState::calendarDays($expires, 'America/Tijuana', self::at('2027-02-10T08:00:00Z')));
		// En UTC el mismo instante ya es otro día.
		self::assertSame(8, LicenseState::calendarDays($expires, 'UTC', self::at('2027-02-02T08:00:00Z')));
		// El 14 de marzo de 2027 Tijuana adelanta una hora: del 14 a la 01:00 al 15 a las 00:00 locales hay
		// 22 horas, y es un día de calendario.
		self::assertSame(
			1,
			LicenseState::calendarDays(
				'2027-03-15 07:00:00.000000',
				'America/Tijuana',
				self::at('2027-03-14T09:00:00Z'),
			),
		);
		// Una zona inválida usa la del panel.
		self::assertSame(
			LicenseState::calendarDays($expires, 'America/Monterrey', self::at('2027-02-02T08:00:00Z')),
			LicenseState::calendarDays($expires, 'Marte/Olimpo', self::at('2027-02-02T08:00:00Z')),
		);
	}
}
