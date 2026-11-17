<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\Credit;
use PHPUnit\Framework\TestCase;

/**
 * Bonificación de un cambio de plan (decisión 6 del propietario): monto pagado × segundos restantes
 * ÷ segundos del periodo, al centavo, con la mitad hacia arriba.
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
		// Pagar antes de que empiece el periodo no bonifica más de lo pagado; después de vencer, nada.
		self::assertSame(300, Credit::remaining(300, $start, $end, self::at('2026-12-31T00:00:00Z'))['credit']);
		self::assertSame(0, Credit::remaining(300, $start, $end, self::at('2027-02-01T00:00:00Z'))['credit']);
		// El monto más alto con un periodo de un año bisiesto no se desborda.
		$year = Credit::remaining(
			9_999_999_999,
			self::at('2027-03-01T00:00:00Z'),
			self::at('2028-03-01T00:00:00Z'),
			self::at('2027-09-01T00:00:00Z'),
		);
		self::assertSame(182 * 86400, $year['remaining_seconds']);
		self::assertSame(intdiv(2 * 9_999_999_999 * 182 + 366, 2 * 366), $year['credit']);
		foreach ([[-1, $end], [100, $start]] as [$paid, $periodEnd]) {
			try {
				Credit::remaining($paid, $start, $periodEnd, $start);
				self::fail('Accepted an invalid amount or period');
			} catch (\InvalidArgumentException) {
			}
		}
	}
}
