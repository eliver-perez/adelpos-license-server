<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Application\{Actor, LicenseActions};
use PHPUnit\Framework\TestCase;

/**
 * Acciones de la barra del detalle (docs/PANEL.md, sección 3.3): solo las que aplican a la licencia y al rol.
 * Cada servicio vuelve a comprobar sus reglas al ejecutar; aquí se prueba lo que el panel ofrece.
 */
final class LicenseActionsTest extends TestCase
{
	private static function license(string $type, bool $activated = true, string $status = 'issued'): array
	{
		return [
			'license_type' => $type,
			'commercial_status' => $status,
			'period_starts_at' => $activated ? '2027-01-10 16:00:00.000000' : null,
			'current_revision' => $activated ? '0b8f5f8e-0000-4000-8000-000000000001' : null,
		];
	}

	public function testOperationsDependOnTheTypeAndTheActivation(): void
	{
		$owner = new Actor('a', 'superadmin');
		$all = ['download', 'documents', 'reveal', 'reissue', 'revoke'];
		self::assertSame(['renew', 'plan', ...$all], LicenseActions::for(self::license('subscription'), $owner));
		self::assertSame(['convert', 'plan', ...$all], LicenseActions::for(self::license('trial'), $owner));
		self::assertSame(['plan', 'maintenance', ...$all], LicenseActions::for(self::license('perpetual'), $owner));
		// Sin activar: no se renueva ni compra mantenimiento; se edita su periodo inicial. Sin .lic vigente.
		$pending = ['terms', 'documents', 'reveal', 'reissue', 'revoke'];
		self::assertSame($pending, LicenseActions::for(self::license('subscription', false), $owner));
		self::assertSame($pending, LicenseActions::for(self::license('perpetual', false), $owner));
		// Una prueba sin activar se convierte y cambia de plan.
		self::assertSame(
			['convert', 'plan', 'terms', 'documents', 'reveal', 'reissue', 'revoke'],
			LicenseActions::for(self::license('trial', false), $owner),
		);
		// Revocada: solo descargar lo firmado y consultar sus constancias.
		self::assertSame(
			['download', 'documents'],
			LicenseActions::for(self::license('subscription', true, 'revoked'), $owner),
		);
	}

	public function testActionsDependOnTheRoleAndThePermissions(): void
	{
		$subscription = self::license('subscription');
		self::assertSame([], LicenseActions::for($subscription, new Actor('v', 'viewer')));
		// Un operador sin permisos especiales: opera, pero no descarga, no revela ni revoca.
		self::assertSame(
			['renew', 'plan', 'documents', 'reissue'],
			LicenseActions::for($subscription, new Actor('o', 'operator')),
		);
		$granted = new Actor('o', 'operator', '', ['offline.download', 'credentials.reveal']);
		self::assertSame(
			['renew', 'plan', 'download', 'documents', 'reveal', 'reissue'],
			LicenseActions::for($subscription, $granted),
		);
		// Una cuenta de consulta con permisos guardados sigue sin acciones.
		self::assertSame([], LicenseActions::for($subscription, new Actor('v', 'viewer', '', ['offline.download'])));
	}
}
