<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/**
 * Bonificación de un cambio de plan de una suscripción vigente (decisión 6 del propietario para la
 * etapa 3, corregida por la decisión 3 para la etapa 4; aclaraciones n.º 27 y 28 del contrato):
 *
 *     valor del periodo = monto cobrado + bonificación aplicada en ese pago
 *
 *     bonificación = Σ sobre cada periodo pagado que todavía no termina en el instante del pago:
 *                    valor del periodo × segundos no consumidos ÷ segundos del periodo
 *
 * - Los segundos son segundos completos: la diferencia en microsegundos, truncada.
 * - Los segundos no consumidos van del pago al final del periodo, y nunca son menos de 0 ni más
 *   que el periodo: un periodo que todavía no empieza (renovación anticipada) cuenta completo.
 * - Cada parte se redondea al centavo, la mitad hacia arriba, y después se suman.
 *
 * Se calcula con enteros. El valor de un periodo es a lo sumo dos veces el monto máximo (lo cobrado
 * más una bonificación, cada uno de hasta 99,999,999.99 MXN) y un periodo dura a lo sumo un año:
 * el producto cabe en un entero de 64 bits.
 */
final class Credit
{
	/** Valor máximo de un periodo: lo cobrado más la bonificación aplicada, cada uno hasta el monto máximo. */
	public const MAX_VALUE_CENTS = 2 * Money::MAX_CENTS;

	/** Duración máxima de un periodo: un año con holgura (366 días más un cambio de horario). */
	public const MAX_PERIOD_SECONDS = 400 * 86400;

	/**
	 * Parte de la bonificación que aporta un periodo pagado.
	 *
	 * @return array{credit: int, remaining_seconds: int, period_seconds: int}
	 */
	public static function remaining(
		int $valueCents,
		\DateTimeImmutable $periodStart,
		\DateTimeImmutable $periodEnd,
		\DateTimeImmutable $paidAt,
	): array {
		if ($valueCents < 0 || $valueCents > self::MAX_VALUE_CENTS) {
			throw new \InvalidArgumentException('Amount out of range.');
		}
		$period = intdiv(self::microseconds($periodStart, $periodEnd), 1_000_000);
		if ($period <= 0) {
			throw new \InvalidArgumentException('The period must end after it starts.');
		}
		if ($period > self::MAX_PERIOD_SECONDS) {
			throw new \InvalidArgumentException('The period is longer than a year.');
		}
		$remaining = intdiv(self::microseconds($paidAt, $periodEnd), 1_000_000);
		$remaining = max(0, min($period, $remaining));
		// Redondeo al centavo, la mitad hacia arriba: ⌊(2 × valor × restante + periodo) ÷ (2 × periodo)⌋.
		$credit = intdiv(2 * $valueCents * $remaining + $period, 2 * $period);
		return ['credit' => $credit, 'remaining_seconds' => $remaining, 'period_seconds' => $period];
	}

	/**
	 * Bonificación de todos los periodos pagados que todavía no terminan en el instante del pago.
	 * Cada parte se redondea por separado y después se suman.
	 *
	 * @param list<array{value: int, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable}> $periods
	 * @return array{credit: int, parts: list<array{credit: int, remaining_seconds: int, period_seconds: int}>}
	 */
	public static function total(array $periods, \DateTimeImmutable $paidAt): array
	{
		$parts = [];
		$total = 0;
		foreach ($periods as $period) {
			$part = self::remaining($period['value'], $period['starts_at'], $period['ends_at'], $paidAt);
			$parts[] = $part;
			$total += $part['credit'];
		}
		return ['credit' => $total, 'parts' => $parts];
	}

	/** Microsegundos de $from a $to; negativo si $to es anterior. */
	private static function microseconds(\DateTimeImmutable $from, \DateTimeImmutable $to): int
	{
		return ((int) $to->format('U') - (int) $from->format('U')) * 1_000_000 +
			((int) $to->format('u') - (int) $from->format('u'));
	}
}
