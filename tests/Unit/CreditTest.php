<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\Credit;
use PHPUnit\Framework\TestCase;

/**
 * Bonificación de un cambio de plan (decisión 6 del propietario para la etapa 3, corregida por la
 * decisión 3 para la etapa 4): por cada periodo pagado que todavía no termina, su valor × segundos no
 * consumidos ÷ segundos del periodo, al centavo, con la mitad hacia arriba, y la suma de las partes.
 */
final class CreditTest extends TestCase
{
	private static function at(string $instant): \DateTimeImmutable
	{
		return new \DateTimeImmutable($instant);
	}

	public function testTheCreditIsTheUnusedShareOfWhatWasPaidRoundedToTheCent(): void
	{
		// Periodo del 1 de marzo al 1 de abril de 2027: 31 días. Paga el 16 de marzo: faltan 16 días.
		$start = self::at('2027-03-01T16:00:00Z');
		$end = self::at('2027-04-01T16:00:00Z');
		$cases = [
			// 900.00 × 16 ÷ 31 = 464.516…
			[90000, '2027-03-16T16:00:00Z', 46452, 16 * 86400],
			// 850.00 × 16 ÷ 31 = 438.709…
			[85000, '2027-03-16T16:00:00Z', 43871, 16 * 86400],
			// Al empezar el periodo, todo; al vencer, nada.
			[90000, '2027-03-01T16:00:00Z', 90000, 31 * 86400],
			[90000, '2027-04-01T16:00:00Z', 0, 0],
		];
		foreach ($cases as [$paid, $paidAt, $credit, $remaining]) {
			self::assertSame(
				['credit' => $credit, 'remaining_seconds' => $remaining, 'period_seconds' => 31 * 86400],
				Credit::remaining($paid, $start, $end, self::at($paidAt)),
				$paidAt,
			);
		}
	}

	public function testRoundingSecondsAndLimits(): void
	{
		$start = self::at('2027-01-01T00:00:00Z');
		// La mitad de un centavo sube: 1 centavo × 1 s ÷ 2 s = 0.5 → 1.
		self::assertSame(
			1,
			Credit::remaining(1, $start, self::at('2027-01-01T00:00:02Z'), self::at('2027-01-01T00:00:01Z'))['credit'],
		);
		// Menos de la mitad baja: 1 centavo × 1 s ÷ 3 s = 0.33 → 0; 2 s ÷ 3 s = 0.67 → 1.
		$end = self::at('2027-01-01T00:00:03Z');
		self::assertSame(0, Credit::remaining(1, $start, $end, self::at('2027-01-01T00:00:02Z'))['credit']);
		self::assertSame(1, Credit::remaining(1, $start, $end, self::at('2027-01-01T00:00:01Z'))['credit']);
		// Son segundos completos: 1.999999 s cuentan como 1.
		self::assertSame(
			1,
			Credit::remaining(300, $start, $end, self::at('2027-01-01T00:00:01.000001Z'))['remaining_seconds'],
		);
		// Un periodo que todavía no empieza cuenta completo; después de vencer, nada.
		self::assertSame(300, Credit::remaining(300, $start, $end, self::at('2026-12-31T00:00:00Z'))['credit']);
		self::assertSame(0, Credit::remaining(300, $start, $end, self::at('2027-02-01T00:00:00Z'))['credit']);
		// El valor más alto (lo cobrado más una bonificación, cada uno el máximo) con un año bisiesto no se desborda.
		$year = Credit::remaining(
			Credit::MAX_VALUE_CENTS,
			self::at('2027-03-01T00:00:00Z'),
			self::at('2028-03-01T00:00:00Z'),
			self::at('2027-09-01T00:00:00Z'),
		);
		self::assertSame(182 * 86400, $year['remaining_seconds']);
		self::assertSame(intdiv(2 * Credit::MAX_VALUE_CENTS * 182 + 366, 2 * 366), $year['credit']);
		self::assertSame(19_999_999_998, Credit::MAX_VALUE_CENTS);
		$tooLong = self::at('2028-02-05T00:00:01Z');
		foreach (
			[[-1, $end], [Credit::MAX_VALUE_CENTS + 1, $end], [100, $start], [100, $tooLong]]
			as [$paid, $periodEnd]
		) {
			try {
				Credit::remaining($paid, $start, $periodEnd, $start);
				self::fail('Accepted an invalid amount or period');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testEveryUnfinishedPeriodAddsItsOwnRoundedShare(): void
	{
		// Renovación anticipada: faltan 4 de 31 días del periodo en curso y el mes siguiente no ha empezado.
		$total = Credit::total(
			[
				[
					'value' => 90000,
					'starts_at' => self::at('2027-03-01T16:00:00Z'),
					'ends_at' => self::at('2027-04-01T16:00:00Z'),
				],
				[
					'value' => 90000,
					'starts_at' => self::at('2027-04-01T16:00:00Z'),
					'ends_at' => self::at('2027-05-01T16:00:00Z'),
				],
			],
			self::at('2027-03-28T16:00:00Z'),
		);
		self::assertSame(101613, $total['credit']);
		self::assertSame([11613, 90000], array_column($total['parts'], 'credit'));
		self::assertSame([4 * 86400, 30 * 86400], array_column($total['parts'], 'remaining_seconds'));
		// Cada parte se redondea por separado y después se suman: dos medios centavos son 2 centavos, no 1.
		$half = [
			'value' => 1,
			'starts_at' => self::at('2027-01-01T00:00:00Z'),
			'ends_at' => self::at('2027-01-01T00:00:02Z'),
		];
		self::assertSame(2, Credit::total([$half, $half], self::at('2027-01-01T00:00:01Z'))['credit']);
		// Sin periodos pagados no hay bonificación.
		self::assertSame(['credit' => 0, 'parts' => []], Credit::total([], self::at('2027-01-01T00:00:00Z')));
	}
}
