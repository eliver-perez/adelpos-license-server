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

	public function testAlertsSayWhatHappenedWithWhatTheOwnerNeedsToDecide(): void
	{
		self::assertSame('Transferencia por autoservicio bloqueada', View::alertType('transfer_blocked'));
		self::assertSame('Transferencias frecuentes', View::alertType('frequent_transfers'));
		self::assertSame(
			'La computadora 66666666-6666-4666-8666-666666666666 (AD-EL POS 1.4.0) intentó transferir la licencia ' .
				'mientras otra transferencia está pendiente de confirmar desde el 25/09/2026 · 06:00 America/Monterrey. ' .
				'Intentos: 3.',
			View::alertDetail([
				'alert_type' => 'transfer_blocked',
				'attempts' => 3,
				'detail' => [
					'server_id' => '66666666-6666-4666-8666-666666666666',
					'app_version' => '1.4.0',
					'pending_since' => '2026-09-25T12:00:00.000000Z',
				],
			]),
		);
		// La ventana de 12 meses, en la zona del cliente.
		self::assertSame(
			'2 transferencias en los 12 meses anteriores, desde el 10/03/2026 · 10:00 America/Tijuana. ' .
				'Veces que se repitió: 1.',
			View::alertDetail([
				'alert_type' => 'frequent_transfers',
				'attempts' => 1,
				'detail' => [
					'transfers' => 2,
					'window_starts_at' => '2026-03-10T17:00:00.000000Z',
					'time_zone' => 'America/Tijuana',
				],
			]),
		);
	}

	public function testPanelHelpersOfTheDefinitiveDesign(): void
	{
		// La insignia escapa su texto y solo acepta los tonos de la tabla de colores.
		self::assertSame(
			'<span class="badge-state tone-gray"><span class="badge-dot" aria-hidden="true"></span>&lt;b&gt;</span>',
			View::badge('<b>', 'neon'),
		);
		self::assertStringContainsString('tone-blue', View::stateBadge('active', 'trial'));
		self::assertStringContainsString('Prueba vigente', View::stateBadge('active', 'trial'));
		self::assertSame(
			'3 cajas · 10 comanderos · 0 KDS',
			View::limits(['cash_registers' => 3, 'order_takers' => '10', 'kitchen_displays' => 0]),
		);
		self::assertSame(
			['vence hoy', 'vence mañana', 'en 7 días'],
			[View::daysLeft(0), View::daysLeft(1), View::daysLeft(7)],
		);
		self::assertSame('abcdef12…', View::shortId('abcdef12-0000-4000-8000-000000000000'));
		self::assertSame('', View::shortId(null));
		self::assertSame(
			'/admin/licenses?q=caf%C3%A9&page=2',
			View::query('/admin/licenses', ['q' => 'café', 'state' => '', 'page' => 1], ['page' => 2]),
		);
		self::assertSame('/admin/licenses', View::query('/admin/licenses', ['q' => '']));
		self::assertSame(
			['date' => '15/11/2026 · 22:30', 'zone' => 'America/Tijuana'],
			View::dateParts('2026-11-16 06:30:00.000000', 'America/Tijuana'),
		);
		self::assertSame(['date' => '—', 'zone' => ''], View::dateParts(null, 'America/Tijuana'));
		// Fechas largas en español sin depender de la extensión intl, en la zona del panel.
		self::assertSame(
			'martes 17 de noviembre de 2026',
			View::longDate(new \DateTimeImmutable('2026-11-18T05:59:59Z')),
		);
		self::assertSame(
			'miércoles 18 de noviembre de 2026',
			View::longDate(new \DateTimeImmutable('2026-11-18T06:00:00Z')),
		);
		self::assertSame('octubre de 2026', View::monthYear(new \DateTimeImmutable('2026-10-01 00:00')));
		self::assertStringContainsString('<svg class="icon"', View::icon('home'));
	}

	public function testRejectedFormValuesComeBackWithoutSecrets(): void
	{
		View::remember('/admin/customers/create', [
			'name' => 'Restaurante',
			'password' => 'secreta',
			'code' => '123456',
			'csrf' => 'token',
		]);
		self::assertSame('Restaurante', View::old('name', '', '/admin/customers/create'));
		// Las contraseñas y los códigos nunca vuelven.
		self::assertSame('', View::old('password', '', '/admin/customers/create'));
		self::assertSame('', View::old('code', '', '/admin/customers/create'));
		// Otro formulario de la misma pantalla conserva sus valores.
		self::assertSame('Original', View::old('name', 'Original', '/admin/customers/otro'));
		self::assertTrue(View::hasOld('/admin/customers/create'));
		self::assertFalse(View::hasOld('/admin/customers/otro'));
		View::remember('', []);
	}
}
