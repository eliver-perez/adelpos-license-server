<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{Money, Problem};
use PHPUnit\Framework\TestCase;

/** Montos en MXN con dos decimales, como centavos enteros (decisión 3 del propietario para la etapa 3). */
final class MoneyTest extends TestCase
{
	public function testWrittenAmountsBecomeExactCents(): void
	{
		$cases = [
			'0' => 0,
			'0.00' => 0,
			'1500' => 150000,
			'1500.5' => 150050,
			'1500.50' => 150050,
			'  799.99 ' => 79999,
			'0.01' => 1,
			'99999999.99' => Money::MAX_CENTS,
		];
		foreach ($cases as $written => $cents) {
			self::assertSame($cents, Money::parse($written, 'El monto'), var_export($written, true));
		}
		self::assertSame(150000, Money::parse(1500, 'El monto'));
		self::assertNull(Money::optional('', 'El precio'));
		self::assertNull(Money::optional('   ', 'El precio'));
	}

	public function testNegativeMalformedAndTooLargeAmountsAreRejected(): void
	{
		$cases = [
			'-1' => 'no puede ser negativo',
			'-0.01' => 'no puede ser negativo',
			'1,500.00' => 'sin comas',
			'$1500' => 'sin comas ni signo de pesos',
			'1500.505' => 'por ejemplo 1500.50',
			'1e3' => 'por ejemplo 1500.50',
			'.50' => 'por ejemplo 1500.50',
			'1500.' => 'por ejemplo 1500.50',
			'100000000' => 'no puede ser mayor que 99,999,999.99 MXN',
			'mil' => 'por ejemplo 1500.50',
		];
		foreach ($cases as $written => $message) {
			// PHP convierte en entero una llave como «-1»: se vuelve a texto, que es lo que llega del formulario.
			$written = (string) $written;
			try {
				Money::optional($written, 'El precio');
				self::fail('Accepted: ' . $written);
			} catch (Problem $error) {
				self::assertStringContainsString($message, $error->getMessage(), $written);
				self::assertStringStartsWith('El precio', $error->getMessage());
			}
		}
		foreach ([1.5, null, [], true] as $value) {
			try {
				Money::optional($value, 'El precio');
				self::fail('Accepted a non-text value');
			} catch (Problem $error) {
				self::assertSame('El precio no es válido.', $error->getMessage());
			}
		}
		try {
			Money::parse('', 'El monto cobrado');
			self::fail('An empty required amount was accepted');
		} catch (Problem $error) {
			self::assertSame('Escribe el monto cobrado.', $error->getMessage());
		}
	}

	public function testStoredAmountsAndTheirDisplay(): void
	{
		self::assertSame('1500.50', Money::toSql(150050));
		self::assertSame('0.05', Money::toSql(5));
		self::assertSame('0.00', Money::toSql(0));
		self::assertSame(150050, Money::fromSql('1500.50'));
		self::assertSame(5, Money::fromSql('0.05'));
		self::assertNull(Money::fromSql(null));
		self::assertSame('$1,500.50 MXN', Money::format(150050));
		self::assertSame('$0.05 MXN', Money::format(5));
		self::assertSame('$99,999,999.99 MXN', Money::format(Money::MAX_CENTS));
		foreach ([-1, Money::MAX_CENTS + 1] as $cents) {
			try {
				Money::toSql($cents);
				self::fail('Stored an amount out of range');
			} catch (\InvalidArgumentException) {
			}
		}
		foreach (['1500.5', '-1.00', '1500', 1500] as $stored) {
			try {
				Money::fromSql($stored);
				self::fail('Read an invalid stored amount');
			} catch (\UnexpectedValueException) {
			}
		}
	}
}
