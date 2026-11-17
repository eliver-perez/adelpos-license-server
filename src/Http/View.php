<?php
declare(strict_types=1);
namespace AdelPos\License\Http;

use AdelPos\License\Application\Payments;
use AdelPos\License\Domain\{Money, TimeZones, Uuid};

final class View
{
	public const TIMEZONE = 'America/Monterrey';

	public function render(string $name, array $data): void
	{
		extract($data, EXTR_SKIP);
		ob_start();
		require dirname(__DIR__, 2) . '/templates/' . $name . '.php';
		$content = ob_get_clean();
		require dirname(__DIR__, 2) . '/templates/layout.php';
	}

	public static function escape(mixed $value): string
	{
		return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public static function fields(string $csrf): void
	{
		echo '<input type="hidden" name="csrf" value="' . self::escape($csrf) . '">';
		echo '<input type="hidden" name="operation_id" value="' . Uuid::create() . '">';
	}

	/**
	 * Fecha y hora con su zona a la vista. Sin zona se usa la del panel; las fechas de una
	 * licencia se muestran en la zona horaria de su cliente.
	 */
	public static function date(?string $value, ?string $zone = null): string
	{
		if ($value === null || $value === '') {
			return '—';
		}
		$zone = $zone !== null && TimeZones::valid($zone) ? $zone : self::TIMEZONE;
		$local = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
		return $local->format('d/m/Y · H:i') . ' ' . $zone;
	}

	/**
	 * Una fecha de la licencia (inicio, vencimiento o fin de actualizaciones) en la zona horaria
	 * de su cliente. Una licencia emitida no tiene fechas hasta que se activa.
	 */
	public static function licenseDate(array $license, string $field): string
	{
		return ($license[$field] ?? null) === null
			? 'Empieza al activar'
			: self::date($license[$field], $license['time_zone'] ?? null);
	}

	/** Tipo de licencia con su condición: periodo de cobro, duración de la prueba o meses de actualizaciones. */
	public static function licenseTerms(array $license): string
	{
		$periods = [
			'monthly' => 'mensual',
			'quarterly' => 'trimestral',
			'semiannual' => 'semestral',
			'annual' => 'anual',
		];
		$durations = ['15d' => '15 días', '1m' => '1 mes', '3m' => '3 meses'];
		if ($license['license_type'] === 'subscription') {
			return 'Suscripción ' . ($periods[$license['billing_period'] ?? ''] ?? '');
		}
		if ($license['license_type'] === 'trial') {
			return 'Prueba de ' . ($durations[$license['trial_duration'] ?? ''] ?? '');
		}
		$months = (int) ($license['updates_months'] ?? 0);
		return 'Perpetua · ' . ($months === 0 ? 'sin actualizaciones' : $months . ' meses de actualizaciones');
	}

	/** Estado firmado de una revisión. Una revisión de cierre dice por qué se cerró. */
	public static function revisionStatus(string $status, ?string $reason): string
	{
		if ($status === 'active') {
			return 'Activa';
		}
		$reasons = [
			'deactivated' => 'licencia liberada',
			'transferred' => 'transferida a otra computadora',
			'revoked' => 'revocada por el propietario',
		];
		return 'Revocada · ' . ($reasons[$reason ?? ''] ?? 'sin motivo registrado');
	}

	/** Nombre del tipo de licencia en el panel y en las constancias. */
	public static function licenseType(string $type): string
	{
		return ['perpetual' => 'Perpetua', 'subscription' => 'Suscripción', 'trial' => 'Prueba'][$type] ?? $type;
	}

	/** Vencimiento de una licencia: las suscripciones y las pruebas no tienen fecha hasta que se activan. */
	public static function expiry(array $license): string
	{
		if ($license['license_type'] === 'perpetual') {
			return 'Sin vencimiento';
		}
		return self::licenseDate($license, 'expires_at');
	}

	/** Monto guardado (DECIMAL) para leer: «$1,500.50 MXN». Sin monto, una raya. */
	public static function money(?string $value): string
	{
		$cents = Money::fromSql($value);
		return $cents === null ? '—' : Money::format($cents);
	}

	/** Concepto de un pago: alta, renovación, conversión, cambio de plan o mantenimiento. */
	public static function paymentConcept(string $concept): string
	{
		return Payments::CONCEPTS[$concept] ?? $concept;
	}

	public static function role(string $role): string
	{
		return ['superadmin' => 'Superadministrador', 'operator' => 'Operador de licencias', 'viewer' => 'Consulta'][
			$role
		] ?? $role;
	}

	public static function action(string $action): string
	{
		return [
			'license.administrative_updated' => 'Información administrativa actualizada',
			'credential.revealed' => 'Código consultado',
			'document.generated' => 'Constancia generada',
			'document.downloaded' => 'Constancia descargada',
			'offline.downloaded' => 'Archivo firmado descargado',
			'license.issued' => 'Licencia emitida',
			'payment.recorded' => 'Pago registrado',
			'license.features' => 'Módulos actualizados',
			'license.renew' => 'Suscripción renovada',
			'license.maintenance' => 'Mantenimiento registrado',
			'license.reissue' => 'Clave comercial reemplazada',
			'license.revoke' => 'Licencia revocada',
			'offline.imported' => 'Solicitud sin internet importada',
			'offline.approved' => 'Solicitud sin internet aprobada',
			'offline.rejected' => 'Solicitud sin internet rechazada',
			'offline.transferred' => 'Transferencia sin internet autorizada',
			'license.force_release' => 'Recuperación de equipo averiado',
			'customer.created' => 'Cliente registrado',
			'customer.updated' => 'Cliente actualizado',
			'product.created' => 'Producto registrado',
			'product.updated' => 'Producto actualizado',
			'session.login' => 'Inicio de sesión',
			'session.logout' => 'Cierre de sesión',
			'trusted_device.authorized' => 'Dispositivo de confianza autorizado',
			'session.trusted_device_login' => 'Inicio de sesión con dispositivo de confianza',
			'trusted_device.revoked' => 'Confianza de dispositivo revocada',
			'trusted_device.revoked_all' => 'Confianza de todos los dispositivos revocada',
			'trusted_device.invalidated' => 'Autorización de confianza vencida o invalidada',
			'admin.mfa_enrolled' => 'Verificación en dos pasos configurada',
			'admin.created' => 'Administrador registrado',
			'admin.bootstrap' => 'Cuenta inicial creada',
			'session.password_verified' => 'Contraseña verificada',
			'admin.updated' => 'Administrador actualizado',
			'admin.password_changed' => 'Contraseña cambiada',
			'admin.operation_failed' => 'Operación administrativa no completada',
			'session.recovery_login' => 'Inicio de sesión con código de recuperación',
			'session.reauthenticated' => 'Identidad confirmada de nuevo',
			'session.login_failed' => 'Inicio de sesión fallido',
			'session.mfa_failed' => 'Código de verificación incorrecto',
			'session.reauth_failed' => 'Confirmación de identidad fallida',
			'activation.activate' => 'Activación en línea',
			'activation.refresh' => 'Consulta de actualización en línea',
			'activation.deactivate' => 'Desactivación en línea',
			'capability.updated' => 'Módulo actualizado',
			'credential.disclosed_on_issue' => 'Código mostrado al emitir',
			'material.access_denied' => 'Acceso a material sensible denegado',
			'signing_key.generated' => 'Llave de firma generada',
			'signing_key.activated' => 'Llave de firma activada',
			'maintenance.cleanup' => 'Limpieza de mantenimiento',
			'admin.recovered_via_cli' => 'Acceso de administrador recuperado desde la terminal',
		][$action] ?? $action;
	}
}
