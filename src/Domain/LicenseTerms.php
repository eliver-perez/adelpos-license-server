<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Condiciones comerciales de una licencia según el contrato V1.0 (secciones 4, 5 y 11). */
final class LicenseTerms
{
	public const TYPES = ['perpetual', 'subscription', 'trial'];
	public const BILLING_PERIODS = ['monthly', 'quarterly', 'semiannual', 'annual'];
	/** Meses de calendario de cada periodo de cobro (contrato, sección 5.1). */
	public const BILLING_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];
	public const TRIAL_DURATIONS = ['15d', '1m', '3m'];
	/** Duración de cada prueba: «15 días» son días de calendario; las demás, meses de calendario. */
	public const TRIAL_LENGTHS = ['15d' => ['days' => 15], '1m' => ['months' => 1], '3m' => ['months' => 3]];
	public const UPDATES_MONTHS = [0, 6, 12];
	public const DEFAULT_UPDATES_MONTHS = 6;
	/** Cupos de sesiones simultáneas, con el nombre que ve el administrador. */
	public const LIMITS = [
		'cash_registers' => 'Cajas',
		'order_takers' => 'Comanderos',
		'kitchen_displays' => 'Pantallas de cocina (KDS)',
	];
	public const MAX_LIMIT = 999;

	/**
	 * Precio del catálogo de cada plazo, todos opcionales y en MXN (decisión 3 del propietario para
	 * la etapa 3): los cuatro periodos de la suscripción, la perpetua y el mantenimiento anual.
	 */
	public const PRICES = [
		'monthly' => 'price_monthly',
		'quarterly' => 'price_quarterly',
		'semiannual' => 'price_semiannual',
		'annual' => 'price_annual',
		'perpetual' => 'price_perpetual',
		'maintenance' => 'price_maintenance',
	];

	/** Nombre de cada plazo para el administrador. */
	public const TERM_LABELS = [
		'monthly' => 'Mensual',
		'quarterly' => 'Trimestral',
		'semiannual' => 'Semestral',
		'annual' => 'Anual',
		'perpetual' => 'Perpetua',
		'maintenance' => 'Mantenimiento anual',
		'trial' => 'Prueba',
	];

	/** Meses de actualizaciones que compra el mantenimiento de una perpetua (decisión 4). */
	public const MAINTENANCE_MONTHS = 12;

	/** Plazo que se paga con estas condiciones: el periodo de la suscripción, «perpetual» o «trial». */
	public static function term(array $terms): string
	{
		return match ($terms['license_type'] ?? '') {
			'subscription' => in_array($terms['billing_period'] ?? '', self::BILLING_PERIODS, true)
				? $terms['billing_period']
				: throw new \InvalidArgumentException('Unknown billing period.'),
			'perpetual' => 'perpetual',
			'trial' => 'trial',
			default => throw new \InvalidArgumentException('Unknown license type.'),
		};
	}

	/**
	 * Precio del catálogo de un plazo, en centavos, o null si el plan no lo tiene.
	 * Una prueba no tiene costo.
	 */
	public static function catalogPrice(array $plan, string $term): ?int
	{
		if ($term === 'trial') {
			return 0;
		}
		$column = self::PRICES[$term] ?? throw new \InvalidArgumentException('Unknown term.');
		return Money::fromSql($plan[$column] ?? null);
	}

	/** Tipo de licencia con su único dato propio: periodo, duración de la prueba o meses de actualizaciones. */
	public static function fromInput(array $input): array
	{
		$type = Input::choice($input, 'license_type', self::TYPES);
		$sent = [];
		foreach (['billing_period', 'trial_duration', 'updates_months'] as $field) {
			$value = $input[$field] ?? '';
			if (!is_string($value) && !is_int($value)) {
				throw new Problem('Revisa las condiciones de la licencia.');
			}
			$sent[$field] = trim((string) $value);
		}
		$terms = [
			'license_type' => $type,
			'billing_period' => null,
			'trial_duration' => null,
			'updates_months' => null,
		];
		if ($type === 'subscription') {
			if (!in_array($sent['billing_period'], self::BILLING_PERIODS, true)) {
				throw new Problem('Elige el periodo de la suscripción: mensual, trimestral, semestral o anual.');
			}
			$terms['billing_period'] = $sent['billing_period'];
		} elseif ($type === 'trial') {
			if (!in_array($sent['trial_duration'], self::TRIAL_DURATIONS, true)) {
				throw new Problem('Elige la duración de la prueba: 15 días, 1 mes o 3 meses.');
			}
			$terms['trial_duration'] = $sent['trial_duration'];
		} else {
			$months = $sent['updates_months'] === '' ? (string) self::DEFAULT_UPDATES_MONTHS : $sent['updates_months'];
			if (!in_array($months, array_map('strval', self::UPDATES_MONTHS), true)) {
				throw new Problem('Elige los meses de actualizaciones de la licencia perpetua: 0, 6 o 12.');
			}
			$terms['updates_months'] = (int) $months;
		}
		// Un dato de otro tipo de licencia nunca se ignora en silencio.
		if ($type !== 'subscription' && $sent['billing_period'] !== '') {
			throw new Problem('El periodo de cobro solo aplica a las suscripciones.');
		}
		if ($type !== 'trial' && $sent['trial_duration'] !== '') {
			throw new Problem('La duración de prueba solo aplica a las licencias de prueba.');
		}
		if ($type !== 'perpetual' && $sent['updates_months'] !== '') {
			throw new Problem('Los meses de actualizaciones solo aplican a las licencias perpetuas.');
		}
		return $terms;
	}

	/** Tolerancia después del vencimiento: solo las suscripciones la tienen. */
	public static function graceDays(string $type): int
	{
		return $type === 'subscription' ? 5 : 0;
	}
}
