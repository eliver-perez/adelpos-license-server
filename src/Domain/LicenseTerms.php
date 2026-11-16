<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Condiciones comerciales de una licencia según el contrato V1.0 (secciones 4, 5 y 11). */
final class LicenseTerms
{
    public const TYPES = ['perpetual', 'subscription', 'trial'];
    public const BILLING_PERIODS = ['monthly', 'quarterly', 'semiannual', 'annual'];
    public const TRIAL_DURATIONS = ['15d', '1m', '3m'];
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
