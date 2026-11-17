<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Montos en pesos mexicanos (MXN) con dos decimales (decisión 3 del propietario para la etapa 3).
 *
 * En el código viajan como centavos enteros, para que ninguna resta ni proporción pierda un
 * centavo por el redondeo de los números de punto flotante. En la base son DECIMAL(10,2).
 * No se calcula IVA ni se emiten facturas: se registra el monto tal como se cobra.
 */
final class Money
{
	public const CURRENCY = 'MXN';

	/** 99 999 999.99 MXN: lo que cabe en una columna DECIMAL(10,2). */
	public const MAX_CENTS = 9_999_999_999;

	private const WRITTEN = '/\A(\d{1,12})(?:\.(\d{1,2}))?\z/';

	private const STORED = '/\A(\d{1,8})\.(\d{2})\z/';

	/**
	 * Monto escrito por el administrador: «1500», «1500.5» o «1500.50».
	 * $label es el nombre del campo que ve el administrador, por ejemplo «El monto cobrado».
	 */
	public static function parse(mixed $value, string $label): int
	{
		$cents = self::optional($value, $label);
		if ($cents === null) {
			throw new Problem('Escribe ' . self::lower($label) . '.');
		}
		return $cents;
	}

	/** Igual que parse(), pero un campo vacío significa «sin monto». */
	public static function optional(mixed $value, string $label): ?int
	{
		if (is_int($value)) {
			$value = (string) $value;
		}
		if (!is_string($value)) {
			throw new Problem($label . ' no es válido.');
		}
		$text = trim($value);
		if ($text === '') {
			return null;
		}
		if (str_starts_with($text, '-')) {
			throw new Problem($label . ' no puede ser negativo.');
		}
		if (!preg_match(self::WRITTEN, $text, $parts)) {
			throw new Problem(
				$label . ' debe escribirse con números, sin comas ni signo de pesos, por ejemplo 1500.50.',
			);
		}
		$cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
		if ($cents > self::MAX_CENTS) {
			throw new Problem($label . ' no puede ser mayor que 99,999,999.99 MXN.');
		}
		return $cents;
	}

	/** Valor de una columna DECIMAL(10,2), o null si la columna está vacía. */
	public static function fromSql(mixed $value): ?int
	{
		if ($value === null) {
			return null;
		}
		if (!is_string($value) || !preg_match(self::STORED, $value, $parts)) {
			throw new \UnexpectedValueException('Invalid stored amount.');
		}
		return (int) $parts[1] * 100 + (int) $parts[2];
	}

	/** Centavos como texto para una columna DECIMAL(10,2): «1500.50». */
	public static function toSql(int $cents): string
	{
		if ($cents < 0 || $cents > self::MAX_CENTS) {
			throw new \InvalidArgumentException('Amount out of range.');
		}
		return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
	}

	/** Monto para leer en el panel: «$1,500.50 MXN». */
	public static function format(int $cents): string
	{
		return '$' .
			number_format(intdiv($cents, 100)) .
			'.' .
			str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) .
			' ' .
			self::CURRENCY;
	}

	private static function lower(string $label): string
	{
		return mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
	}
}
