<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Bonificación de un cambio de plan de una suscripción vigente (decisión 6 del propietario para la
 * etapa 3; propuesta V1.1 n.º 22):
 *
 *     bonificación = monto pagado del periodo vigente × segundos restantes ÷ segundos del periodo
 *
 * - Los segundos son segundos completos: la diferencia en microsegundos, truncada.
 * - Los segundos restantes van del pago al vencimiento, y nunca son menos de 0 ni más que el
 *   periodo: la bonificación nunca supera lo que se pagó por el periodo.
 * - Se redondea al centavo, la mitad hacia arriba.
 *
 * Se calcula con enteros: con montos de hasta 99,999,999.99 MXN y periodos de hasta un año, el
 * producto cabe en un entero de 64 bits.
 */
final class Credit
{
	/**
	 * @return array{credit: int, remaining_seconds: int, period_seconds: int}
	 */
	public static function remaining(
		int $paidCents,
		\DateTimeImmutable $periodStart,
		\DateTimeImmutable $periodEnd,
		\DateTimeImmutable $paidAt,
	): array {
		if ($paidCents < 0 || $paidCents > Money::MAX_CENTS) {
			throw new \InvalidArgumentException('Amount out of range.');
		}
		$period = intdiv(self::microseconds($periodStart, $periodEnd), 1_000_000);
		if ($period <= 0) {
			throw new \InvalidArgumentException('The period must end after it starts.');
		}
		$remaining = intdiv(self::microseconds($paidAt, $periodEnd), 1_000_000);
		$remaining = max(0, min($period, $remaining));
		// Redondeo al centavo, la mitad hacia arriba: ⌊(2 × monto × restante + periodo) ÷ (2 × periodo)⌋.
		$credit = intdiv(2 * $paidCents * $remaining + $period, 2 * $period);
		return ['credit' => $credit, 'remaining_seconds' => $remaining, 'period_seconds' => $period];
	}

	/** Microsegundos de $from a $to; negativo si $to es anterior. */
	private static function microseconds(\DateTimeImmutable $from, \DateTimeImmutable $to): int
	{
		return ((int) $to->format('U') - (int) $from->format('U')) * 1_000_000 +
			((int) $to->format('u') - (int) $from->format('u'));
	}
}
