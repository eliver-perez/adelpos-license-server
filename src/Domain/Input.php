<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

final class Input
{
	/** Nombre de cada campo de los formularios del panel, para los mensajes de error (etapa 5). */
	public const LABELS = [
		'activation_id' => 'Computadora',
		'activation_method' => 'Método de activación',
		'amount_reason' => 'Motivo del cambio de monto',
		'apply_to' => 'A qué licencias aplican los cupos',
		'billing_period' => 'Periodo',
		'capability_key' => 'Identificador del módulo',
		'choice' => 'Confiar en este dispositivo',
		'code' => 'Código de autenticación',
		'confirmation' => 'Confirmación',
		'contact_name' => 'Nombre de contacto',
		'customer_id' => 'Cliente',
		'description' => 'Descripción',
		'display_name' => 'Nombre',
		'document_type' => 'Tipo de constancia',
		'email' => 'Correo electrónico',
		'force_activation_id' => 'Computadora de origen',
		'legal_name' => 'Razón social',
		'license_id' => 'Licencia',
		'license_type' => 'Tipo de licencia',
		'login' => 'Correo de acceso',
		'name' => 'Nombre',
		'paid_at' => 'Fecha de pago',
		'phone' => 'Teléfono',
		'plan_id' => 'Plan',
		'product_id' => 'Producto',
		'reason' => 'Motivo',
		'reference' => 'Referencia comercial',
		'renewal_mode' => 'Qué hacer al aprobar',
		'requires' => 'Módulos requeridos',
		'revision_id' => 'Revisión',
		'role' => 'Rol',
		'scope' => 'Qué dispositivos',
		'state' => 'Estado',
		'tax_id' => 'RFC',
		'time_zone' => 'Zona horaria',
		'transfer_id' => 'Transferencia',
		'updates_months' => 'Meses de actualizaciones',
	];

	/** Nombre del campo para el administrador; sin uno registrado, su clave. */
	public static function label(string $key): string
	{
		return self::LABELS[$key] ?? $key;
	}

	public static function text(array $input, string $key, int $max = 190, bool $required = true): string
	{
		$value = $input[$key] ?? '';
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
			throw new Problem('El campo «' . self::label($key) . '» no es válido.');
		}
		$value = trim($value);
		if ($required && $value === '') {
			throw new Problem('Escribe o elige el campo «' . self::label($key) . '»: es obligatorio.');
		}
		if (mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
			throw new Problem(
				'Revisa el campo «' .
					self::label($key) .
					'»: admite hasta ' .
					$max .
					' caracteres, sin caracteres de control.',
			);
		}
		return $value;
	}

	public static function choice(array $input, string $key, array $choices): string
	{
		$value = self::text($input, $key);
		if (!in_array($value, $choices, true)) {
			throw new Problem('Elige una opción válida en «' . self::label($key) . '».');
		}
		return $value;
	}

	/** Entero sin signo escrito con dígitos, o un entero nativo. $label es el nombre que ve el administrador. */
	public static function integer(array $input, string $key, int $min, int $max, string $label): int
	{
		$value = $input[$key] ?? null;
		if (is_string($value) && preg_match('/\A\d{1,9}\z/', trim($value))) {
			$value = (int) trim($value);
		}
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new Problem($label . ' debe ser un número entero entre ' . $min . ' y ' . $max . '.');
		}
		return $value;
	}

	public static function identifier(string $value): string
	{
		if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $value)) {
			throw new Problem(
				'El identificador debe usar letras minúsculas, números y guiones bajos (2 a 64 caracteres).',
			);
		}
		return $value;
	}

	public static function date(string $value): \DateTimeImmutable
	{
		$format = strlen($value) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s';
		$date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			!$date ||
			($errors !== false && ($errors['warning_count'] || $errors['error_count'])) ||
			$date->format(substr($format, 1)) !== $value
		) {
			throw new Problem('Introduce una fecha UTC válida.');
		}
		return $date;
	}

	public static function email(string $email): string
	{
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Problem('El correo electrónico no es válido.');
		}
		return $email;
	}

	public static function password(string $password): void
	{
		if (strlen($password) < 12 || strlen($password) > 1024) {
			throw new Problem('La contraseña debe tener entre 12 y 1024 bytes.');
		}
	}
}
