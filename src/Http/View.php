<?php
declare(strict_types=1);
namespace AdelPos\License\Http;

use AdelPos\License\Application\{Alerts, Payments};
use AdelPos\License\Domain\{LicenseState, Money, TimeZones, Uuid};

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

	/** Campos que nunca se vuelven a escribir en un formulario: contraseñas, códigos y controles. */
	private const SECRET_FIELDS = ['password', 'new_password', 'confirmation', 'code', 'csrf', 'operation_id'];

	/** @var array<string, mixed> Lo escrito en el formulario que no pasó la validación. */
	private static array $old = [];
	private static string $oldForm = '';

	/**
	 * Guarda lo escrito en un formulario rechazado ($form es su dirección de envío) para volver a mostrarlo
	 * (docs/PANEL.md, sección 7). Nunca guarda contraseñas ni códigos.
	 */
	public static function remember(string $form, array $input): void
	{
		self::$oldForm = $form;
		self::$old = array_diff_key($input, array_flip(self::SECRET_FIELDS));
	}

	/** Valor de un campo: lo escrito si su formulario ($form) fue el rechazado; si no, $default. */
	public static function old(string $name, mixed $default, string $form): string
	{
		if (self::$oldForm !== $form || !array_key_exists($name, self::$old) || !is_scalar(self::$old[$name])) {
			return is_scalar($default) ? (string) $default : '';
		}
		return (string) self::$old[$name];
	}

	/** Valores de una lista del formulario rechazado (por ejemplo, las casillas features[]); si no, $default. */
	public static function oldList(string $name, array $default, string $form): array
	{
		if (self::$oldForm !== $form) {
			return $default;
		}
		$values = self::$old[$name] ?? [];
		return array_values(array_filter(is_array($values) ? $values : [], 'is_string'));
	}

	/** Si el formulario rechazado ($form) traía el campo; para casillas y opciones. */
	public static function hasOld(string $form): bool
	{
		return self::$oldForm === $form && self::$old !== [];
	}

	private static string $formError = '';
	private static string $origin = '';

	/** Mensaje del formulario que no se pudo completar; sin argumento, lo devuelve. */
	public static function formError(?string $message = null): string
	{
		if ($message !== null) {
			self::$formError = $message;
		}
		return self::$formError;
	}

	/** Dirección de la pantalla que se muestra: cada formulario la envía para volver a ella si falla. */
	public static function origin(string $origin): void
	{
		self::$origin = $origin;
	}

	public static function fields(string $csrf): void
	{
		echo '<input type="hidden" name="csrf" value="' . self::escape($csrf) . '">';
		echo '<input type="hidden" name="operation_id" value="' . Uuid::create() . '">';
		if (self::$origin !== '') {
			echo '<input type="hidden" name="form_origin" value="' . self::escape(self::$origin) . '">';
		}
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

	/** Nombre de un tipo de alerta del panel. */
	public static function alertType(string $type): string
	{
		return Alerts::TYPES[$type] ?? $type;
	}

	/**
	 * Lo que pasó, en una frase, con lo que el propietario necesita para decidir. Las fechas de la ventana
	 * de 12 meses van en la zona del cliente, que es en la que se calculan.
	 */
	public static function alertDetail(array $alert): string
	{
		$detail = $alert['detail'];
		if ($alert['alert_type'] === 'transfer_blocked') {
			return 'La computadora ' .
				$detail['server_id'] .
				' (AD-EL POS ' .
				$detail['app_version'] .
				') intentó transferir la licencia mientras otra transferencia está pendiente de confirmar desde el ' .
				self::date($detail['pending_since'], $alert['time_zone'] ?? null) .
				'. Intentos: ' .
				$alert['attempts'] .
				'.';
		}
		return $detail['transfers'] .
			' transferencias en los 12 meses anteriores, desde el ' .
			self::date($detail['window_starts_at'], $detail['time_zone']) .
			'. Veces que se repitió: ' .
			$alert['attempts'] .
			'.';
	}

	/**
	 * Insignia de estado (docs/PANEL.md, sección 5): un punto de color y el texto. El color nunca va solo.
	 * Tonos: green, blue, amber, red, darkred, gray y outline.
	 */
	public static function badge(string $label, string $tone): string
	{
		$tone = in_array($tone, ['green', 'blue', 'amber', 'red', 'darkred', 'gray', 'outline'], true) ? $tone : 'gray';
		return '<span class="badge-state tone-' .
			$tone .
			'"><span class="badge-dot" aria-hidden="true"></span>' .
			self::escape($label) .
			'</span>';
	}

	/** Insignia del estado de una licencia, con el nombre y el color de la tabla de estados. */
	public static function stateBadge(string $state, string $licenseType): string
	{
		return self::badge(LicenseState::label($state, $licenseType), LicenseState::tone($state, $licenseType));
	}

	/** Los tres cupos en una línea: «3 cajas · 3 comanderos · 3 KDS». */
	public static function limits(array $license): string
	{
		return (int) $license['cash_registers'] .
			' cajas · ' .
			(int) $license['order_takers'] .
			' comanderos · ' .
			(int) $license['kitchen_displays'] .
			' KDS';
	}

	/**
	 * Fecha de una licencia en la zona de su cliente, separada en la fecha y la zona para mostrarlas en
	 * dos líneas. Sin fecha: «Empieza al activar».
	 *
	 * @return array{date: string, zone: string}
	 */
	public static function dateParts(?string $value, ?string $zone): array
	{
		if ($value === null || $value === '') {
			return ['date' => '—', 'zone' => ''];
		}
		$zone = $zone !== null && TimeZones::valid($zone) ? $zone : self::TIMEZONE;
		$local = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
		return ['date' => $local->format('d/m/Y · H:i'), 'zone' => $zone];
	}

	private const MONTHS = [
		'enero',
		'febrero',
		'marzo',
		'abril',
		'mayo',
		'junio',
		'julio',
		'agosto',
		'septiembre',
		'octubre',
		'noviembre',
		'diciembre',
	];
	private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

	/** «martes 17 de noviembre de 2026», en la zona del panel. Sin depender de la extensión intl. */
	public static function longDate(\DateTimeImmutable $instant): string
	{
		$local = $instant->setTimezone(new \DateTimeZone(self::TIMEZONE));
		return self::WEEKDAYS[(int) $local->format('w')] .
			' ' .
			$local->format('j') .
			' de ' .
			self::MONTHS[(int) $local->format('n') - 1] .
			' de ' .
			$local->format('Y');
	}

	/** «noviembre de 2026»: el mes de un instante en su propia zona. */
	public static function monthYear(\DateTimeImmutable $local): string
	{
		return self::MONTHS[(int) $local->format('n') - 1] . ' de ' . $local->format('Y');
	}

	/** Días de calendario que faltan, en palabras: «vence hoy», «vence mañana», «en 5 días». */
	public static function daysLeft(int $days): string
	{
		return match (true) {
			$days <= 0 => 'vence hoy',
			$days === 1 => 'vence mañana',
			default => 'en ' . $days . ' días',
		};
	}

	/** Un identificador largo, abreviado a sus primeros 8 caracteres para las listas. */
	public static function shortId(?string $uuid): string
	{
		return $uuid === null || $uuid === '' ? '' : substr($uuid, 0, 8) . '…';
	}

	/** Dirección con los filtros actuales y algunos cambiados; los vacíos no se escriben. */
	public static function query(string $path, array $filters, array $changes = []): string
	{
		$values = array_filter(
			array_replace($filters, $changes),
			static fn($value): bool => $value !== '' && $value !== null,
		);
		return $path . ($values === [] ? '' : '?' . http_build_query($values));
	}

	/** Ícono de trazo propio, decorativo (aria-hidden), para la navegación y los botones. */
	public static function icon(string $name): string
	{
		$paths = [
			'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
			'license' =>
				'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="12" r="2.5"/>' .
				'<path d="M13 10h5M13 14h3"/>',
			'customers' =>
				'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/>' .
				'<path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.8.8 3 2.5 3.5 5.2"/>',
			'alerts' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
			'offline' => '<path d="M4 8h14l-3-3M20 16H6l3 3"/>',
			'plans' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
			'products' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
			'audit' =>
				'<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/>' .
				'<circle cx="4" cy="18" r="1"/>',
			'admins' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
			'security' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
			'plus' => '<path d="M12 5v14M5 12h14"/>',
			'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
			'download' => '<path d="M12 4v11m0 0-4-4m4 4 4-4"/><path d="M5 19h14"/>',
			'more' =>
				'<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
			'computer' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
		];
		return '<svg class="icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
			'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" ' .
			'aria-hidden="true" focusable="false">' .
			($paths[$name] ?? '') .
			'</svg>';
	}

	public static function role(string $role): string
	{
		return ['superadmin' => 'Superadministrador', 'operator' => 'Operador de licencias', 'viewer' => 'Consulta'][
			$role
		] ?? $role;
	}

	/** Nombre en español de cada operación de la auditoría. */
	public const ACTIONS = [
		'license.administrative_updated' => 'Información administrativa actualizada',
		'credential.revealed' => 'Código consultado',
		'document.generated' => 'Constancia generada',
		'document.downloaded' => 'Constancia descargada',
		'offline.downloaded' => 'Archivo firmado descargado',
		'license.issued' => 'Licencia emitida',
		'payment.recorded' => 'Pago registrado',
		'license.features' => 'Módulos actualizados',
		'license.renewed' => 'Suscripción renovada',
		'license.converted' => 'Prueba convertida en licencia de pago',
		'license.plan_changed' => 'Plan cambiado',
		'license.plan_change_scheduled' => 'Cambio de plan programado',
		'license.scheduled_change_cancelled' => 'Cambio programado cancelado',
		'license.scheduled_change_applied' => 'Cambio programado aplicado',
		'license.maintenance_bought' => 'Mantenimiento comprado',
		'license.initial_terms_edited' => 'Periodo inicial editado',
		'license.reissue' => 'Clave comercial reemplazada',
		'license.revoke' => 'Licencia revocada',
		'offline.imported' => 'Solicitud sin internet importada',
		'offline.approved' => 'Solicitud sin internet aprobada',
		'offline.rejected' => 'Solicitud sin internet rechazada',
		'offline.transferred' => 'Transferencia sin internet autorizada',
		'license.force_release' => 'Recuperación de equipo averiado',
		'license.transferred' => 'Transferencia por autoservicio',
		'license.transfer_blocked' => 'Transferencia por autoservicio bloqueada',
		'license.transfer_confirmed' => 'Transferencia confirmada por la computadora anterior',
		'license.transfer_released' => 'Transferencia pendiente liberada a mano',
		'alert.reviewed' => 'Alerta revisada',
		'activation.transfer' => 'Transferencia en línea',
		'customer.created' => 'Cliente registrado',
		'customer.updated' => 'Cliente actualizado',
		'product.created' => 'Producto registrado',
		'product.updated' => 'Producto actualizado',
		'plan.created' => 'Plan creado',
		'plan.updated' => 'Plan actualizado',
		'plan.archived' => 'Plan archivado',
		'plan.restored' => 'Plan ofrecido de nuevo',
		'license.plan_propagated' => 'Aumento del catálogo aplicado',
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
		'maintenance.scheduled_changes' => 'Cambios programados aplicados por la tarea diaria',
		'admin.recovered_via_cli' => 'Acceso de administrador recuperado desde la terminal',
	];

	public static function action(string $action): string
	{
		return self::ACTIONS[$action] ?? $action;
	}
}
