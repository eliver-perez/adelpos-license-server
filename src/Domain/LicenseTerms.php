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
    public const LIMITS = ['cash_registers' => 'Cajas', 'order_takers' => 'Comanderos', 'kitchen_displays' => 'Pantallas de cocina (KDS)'];
    public const MAX_LIMIT = 999;

    /** Tolerancia después del vencimiento: solo las suscripciones la tienen. */
    public static function graceDays(string $type): int
    {
        return $type === 'subscription' ? 5 : 0;
    }
}
