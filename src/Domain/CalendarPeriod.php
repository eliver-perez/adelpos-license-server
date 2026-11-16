<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Motor de fechas del contrato V1.0 (secciones 5.1, 5.2, 5.4 y 5.5).
 *
 * Las fechas se calculan en la zona horaria del cliente y el resultado es siempre un instante UTC.
 * Es un componente puro: no consulta la base de datos ni la hora del sistema. El instante de inicio
 * lo entrega quien lo llama, que lo toma del reloj de la aplicación.
 */
final class CalendarPeriod
{
    private const TIME = '/\A([01]\d|2[0-3]):([0-5]\d):([0-5]\d)\.(\d{6})\z/';

    /**
     * Día y hora ancla de un instante: su día del mes y su hora local, con microsegundos.
     * @return array{day:int, time:string}
     */
    public static function anchor(\DateTimeImmutable $instant, string $zone): array
    {
        $local = $instant->setTimezone(self::zone($zone));
        return ['day' => (int) $local->format('j'), 'time' => $local->format('H:i:s.u')];
    }

    /**
     * Suma meses de calendario al mes local de $start y devuelve el instante del día y la hora ancla.
     * Si el día ancla no existe en el mes destino, usa su último día. El ancla no se modifica:
     * 31 ene → 28 feb → 31 mar → 30 abr.
     */
    public static function addMonths(\DateTimeImmutable $start, int $months, string $zone, int $anchorDay, string $anchorTime): \DateTimeImmutable
    {
        if ($months < 0 || $months > 1200) { throw new \InvalidArgumentException('Months must be between 0 and 1200.'); }
        if ($anchorDay < 1 || $anchorDay > 31) { throw new \InvalidArgumentException('The anchor day must be between 1 and 31.'); }
        $timeZone = self::zone($zone);
        $local = $start->setTimezone($timeZone);
        $index = (int) $local->format('Y') * 12 + (int) $local->format('n') - 1 + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->format('t');
        return self::resolve($timeZone, sprintf('%04d-%02d-%02d', $year, $month, min($anchorDay, $lastDay)), $anchorTime);
    }

    /** Suma días de calendario a la fecha local de $start, a su misma hora local. */
    public static function addDays(\DateTimeImmutable $start, int $days, string $zone): \DateTimeImmutable
    {
        if ($days < 0 || $days > 36600) { throw new \InvalidArgumentException('Days must be between 0 and 36600.'); }
        $timeZone = self::zone($zone);
        $local = $start->setTimezone($timeZone);
        // La fecha se suma en UTC para que un cambio de horario no altere la cuenta de días.
        $date = (new \DateTimeImmutable($local->format('Y-m-d'), new \DateTimeZone('UTC')))->modify('+' . $days . ' days');
        return self::resolve($timeZone, $date->format('Y-m-d'), $local->format('H:i:s.u'));
    }

    /**
     * Primer periodo de una licencia (sección 5.2). $start es el momento en que el servidor confirma
     * la activación en línea, o en que se aprueba la solicitud sin internet.
     *
     * @param array{license_type:string, billing_period?:?string, trial_duration?:?string, updates_months?:int|string|null} $terms
     * @return array{period_starts_at:\DateTimeImmutable, expires_at:?\DateTimeImmutable, updates_until:\DateTimeImmutable, anchor_day:int, anchor_local_time:string}
     */
    public static function firstPeriod(array $terms, \DateTimeImmutable $start, string $zone): array
    {
        $start = $start->setTimezone(new \DateTimeZone('UTC'));
        $anchor = self::anchor($start, $zone);
        $months = fn(int $count): \DateTimeImmutable => self::addMonths($start, $count, $zone, $anchor['day'], $anchor['time']);
        $type = $terms['license_type'] ?? '';
        if ($type === 'subscription') {
            $count = LicenseTerms::BILLING_MONTHS[$terms['billing_period'] ?? ''] ?? throw new \InvalidArgumentException('Unknown billing period.');
            $expires = $months($count);
            $updates = $expires;
        } elseif ($type === 'trial') {
            $length = LicenseTerms::TRIAL_LENGTHS[$terms['trial_duration'] ?? ''] ?? throw new \InvalidArgumentException('Unknown trial duration.');
            $expires = isset($length['days']) ? self::addDays($start, $length['days'], $zone) : $months($length['months']);
            $updates = $expires;
        } elseif ($type === 'perpetual') {
            $count = $terms['updates_months'] ?? null;
            if (is_string($count) && ctype_digit($count)) { $count = (int) $count; }
            if (!in_array($count, LicenseTerms::UPDATES_MONTHS, true)) { throw new \InvalidArgumentException('Unknown updates term.'); }
            $expires = null;
            // Con 0 meses las actualizaciones terminan en la activación misma, sin recalcular la hora local.
            $updates = $count === 0 ? $start : $months($count);
        } else {
            throw new \InvalidArgumentException('Unknown license type.');
        }
        return ['period_starts_at' => $start, 'expires_at' => $expires, 'updates_until' => $updates, 'anchor_day' => $anchor['day'], 'anchor_local_time' => $anchor['time']];
    }

    /** Instante como lo firma el servidor: UTC, seis decimales y terminado en Z (sección 3). */
    public static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Instante en UTC para una columna DATETIME(6). */
    public static function sql(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function zone(string $zone): \DateTimeZone
    {
        if (!TimeZones::valid($zone)) { throw new \InvalidArgumentException('Unknown time zone.'); }
        return new \DateTimeZone($zone);
    }

    /**
     * Instante UTC de una fecha y hora locales (sección 5.1):
     * - si la hora local se repite por un cambio de horario, el primero de los dos instantes;
     * - si no existe, el primer instante después del salto.
     * No depende de cómo PHP ajusta por su cuenta las horas ambiguas o inexistentes.
     */
    private static function resolve(\DateTimeZone $zone, string $date, string $time): \DateTimeImmutable
    {
        if (!preg_match(self::TIME, $time, $parts)) { throw new \InvalidArgumentException('The anchor time must be HH:MM:SS.uuuuuu.'); }
        // La hora de pared, leída como si fuera UTC: restarle un desfase da el instante candidato.
        $wall = (new \DateTimeImmutable($date . ' ' . substr($time, 0, 8), new \DateTimeZone('UTC')))->getTimestamp();
        $transitions = $zone->getTransitions($wall - 172800, $wall + 172800) ?: [];
        $offsets = array_values(array_unique(array_column($transitions, 'offset')));
        if ($offsets === []) { $offsets = [$zone->getOffset(new \DateTimeImmutable('@' . $wall))]; }
        $instants = [];
        foreach ($offsets as $offset) {
            $instant = $wall - $offset;
            if ($zone->getOffset(new \DateTimeImmutable('@' . $instant)) === $offset) { $instants[] = $instant; }
        }
        if ($instants !== []) { return self::at(min($instants), (int) $parts[4]); }
        for ($index = 1; $index < count($transitions); ++$index) {
            $before = $transitions[$index - 1]['offset'];
            $after = $transitions[$index]['offset'];
            $change = $transitions[$index]['ts'];
            if ($after > $before && $wall >= $change + $before && $wall < $change + $after) { return self::at($change, 0); }
        }
        throw new \LogicException('The local time cannot be resolved.');
    }

    private static function at(int $seconds, int $microseconds): \DateTimeImmutable
    {
        $instant = (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
        return $instant->setTime((int) $instant->format('G'), (int) $instant->format('i'), (int) $instant->format('s'), $microseconds);
    }
}
