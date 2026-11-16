<?php
declare(strict_types=1);
namespace AdelPos\License\Http;

use AdelPos\License\Domain\{Problem, Uuid};

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

    public static function date(?string $value): string
    {
        return $value === null || $value === '' ? '—' : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y · H:i') . ' ' . self::TIMEZONE;
    }

    public static function dateInput(\DateTimeInterface $instant): string
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d\TH:i');
    }

    /** Convert the panel's local wall time at the HTTP boundary; domain inputs remain UTC. */
    public static function utcDateInput(string $value): string
    {
        $format = strlen($value) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone(self::TIMEZONE));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format(substr($format, 1)) !== $value) {
            throw new Problem('Introduce una fecha válida en ' . self::TIMEZONE . '.');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(substr($format, 1));
    }

    public static function role(string $role): string
    {
        return ['superadmin' => 'Superadministrador', 'operator' => 'Operador de licencias', 'viewer' => 'Consulta'][$role] ?? $role;
    }

    public static function action(string $action): string
    {
        return [
            'license.administrative_updated'=>'Información administrativa actualizada', 'credential.revealed'=>'Código consultado', 'document.generated'=>'Constancia generada', 'document.downloaded'=>'Constancia descargada', 'offline.downloaded'=>'Archivo firmado descargado',
            'license.issued' => 'Licencia emitida', 'license.features' => 'Módulos actualizados',
            'license.renew' => 'Suscripción renovada', 'license.maintenance' => 'Mantenimiento registrado',
            'license.reissue' => 'Clave comercial reemplazada', 'license.revoke' => 'Licencia revocada',
            'offline.imported'=>'Solicitud sin internet importada', 'offline.approved'=>'Solicitud sin internet aprobada',
            'offline.rejected'=>'Solicitud sin internet rechazada', 'offline.transferred'=>'Transferencia sin internet autorizada',
            'license.force_release'=>'Recuperación de equipo averiado',
            'customer.created' => 'Cliente registrado', 'customer.updated' => 'Cliente actualizado',
            'product.created' => 'Producto registrado', 'product.updated' => 'Producto actualizado',
            'session.login' => 'Inicio de sesión', 'session.logout' => 'Cierre de sesión',
            'trusted_device.authorized' => 'Dispositivo de confianza autorizado',
            'session.trusted_device_login' => 'Inicio de sesión con dispositivo de confianza',
            'trusted_device.revoked' => 'Confianza de dispositivo revocada',
            'trusted_device.revoked_all' => 'Confianza de todos los dispositivos revocada',
            'trusted_device.invalidated' => 'Autorización de confianza vencida o invalidada',
            'admin.mfa_enrolled' => 'Verificación en dos pasos configurada', 'admin.created' => 'Administrador registrado',
            'admin.bootstrap' => 'Cuenta inicial creada', 'session.password_verified' => 'Contraseña verificada',
            'admin.updated' => 'Administrador actualizado', 'admin.password_changed' => 'Contraseña cambiada',
            'admin.operation_failed' => 'Operación administrativa no completada',
            'session.recovery_login' => 'Inicio de sesión con código de recuperación',
            'session.reauthenticated' => 'Identidad confirmada de nuevo', 'session.login_failed' => 'Inicio de sesión fallido',
            'session.mfa_failed' => 'Código de verificación incorrecto', 'session.reauth_failed' => 'Confirmación de identidad fallida',
            'activation.activate' => 'Activación en línea', 'activation.refresh' => 'Consulta de actualización en línea',
            'activation.deactivate' => 'Desactivación en línea', 'capability.updated' => 'Módulo actualizado',
            'credential.disclosed_on_issue' => 'Código mostrado al emitir', 'material.access_denied' => 'Acceso a material sensible denegado',
            'signing_key.generated' => 'Llave de firma generada', 'signing_key.activated' => 'Llave de firma activada',
        ][$action] ?? $action;
    }
}
