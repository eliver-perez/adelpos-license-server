<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Zonas horarias IANA admitidas para los clientes: las fechas de sus licencias se calculan en la suya. */
final class TimeZones
{
    public const DEFAULT = 'America/Monterrey';

    /** @return list<string> */
    public static function all(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public static function valid(string $identifier): bool
    {
        return in_array($identifier, self::all(), true);
    }
}
