<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\CalendarPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Renovación de una suscripción (contrato §5.3) en el motor de fechas: la fecha base según la
 * tolerancia, el día ancla al encadenar periodos y la zona horaria del cliente.
 */
final class RenewalDatesTest extends TestCase
{
	private const MONTERREY = 'America/Monterrey';
	private const TIJUANA = 'America/Tijuana';

	/** Hora local de un instante, para leer los resultados como los ve el cliente. */
	private static function local(\DateTimeImmutable $instant, string $zone): string
	{
		return $instant->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i:s.u');
	}

	private static function at(string $local, string $zone = self::MONTERREY): \DateTimeImmutable
	{
		[$date, $time] = explode(' ', $local);
		return CalendarPeriod::localInstant($date, $time . '.000000', $zone);
	}

	public function testTheFourExamplesOfTheContract(): void
	{
		// Vence el 2 de marzo a las 10:00 (hora local de todos los casos), con 5 días de tolerancia.
		$expires = self::at('2027-03-02 10:00:00');
		$cases = [
			'paga el 1 de marzo, antes de vencer' => ['2027-03-01 10:00:00', '2027-04-02 10:00:00.000000', true],
			'paga el 5 de marzo, dentro de la tolerancia' => [
				'2027-03-05 10:00:00',
				'2027-04-02 10:00:00.000000',
				true,
			],
			'paga el 7 de marzo a las 10:00, al terminar la tolerancia' => [
				'2027-03-07 10:00:00',
				'2027-04-07 10:00:00.000000',
				false,
			],
			'paga el 20 de marzo' => ['2027-03-20 10:00:00', '2027-04-20 10:00:00.000000', false],
		];
		foreach ($cases as $name => [$paid, $expected, $inTime]) {
			$renewal = CalendarPeriod::renewal($expires, 5, self::at($paid), 1, self::MONTERREY, 2, '10:00:00.000000');
			self::assertSame($expected, self::local($renewal['expires_at'], self::MONTERREY), $name);
			self::assertSame($inTime, $renewal['in_time'], $name);
			self::assertEquals($renewal['expires_at'], $renewal['updates_until'], $name);
			// El periodo nuevo empieza en la base: el vencimiento anterior o la fecha de pago.
			self::assertEquals($inTime ? $expires : self::at($paid), $renewal['period_starts_at'], $name);
		}
		// Al pagar tarde, el día y la hora ancla pasan a ser los del pago.
		$late = CalendarPeriod::renewal(
			$expires,
			5,
			self::at('2027-03-20 11:15:42'),
			1,
			self::MONTERREY,
			2,
			'10:00:00.000000',
		);
		self::assertSame([20, '11:15:42.000000'], [$late['anchor_day'], $late['anchor_local_time']]);
		self::assertSame('2027-04-20 11:15:42.000000', self::local($late['expires_at'], self::MONTERREY));
	}

	public function testTheEndOfTheToleranceIsExclusiveAndCountsExactHours(): void
	{
		$expires = new \DateTimeImmutable('2027-03-02T16:00:00.000000Z');
		$limit = new \DateTimeImmutable('2027-03-07T16:00:00.000000Z');
		$renew = fn(\DateTimeImmutable $paid): array => CalendarPeriod::renewal(
			$expires,
			5,
			$paid,
			1,
			self::MONTERREY,
			2,
			'10:00:00.000000',
		);
		$before = $renew($limit->modify('-1 microsecond'));
		self::assertTrue($before['in_time']);
		self::assertSame('2027-04-02T16:00:00.000000Z', CalendarPeriod::utc($before['expires_at']));
		$at = $renew($limit);
		self::assertFalse($at['in_time']);
		self::assertSame('2027-04-07T16:00:00.000000Z', CalendarPeriod::utc($at['expires_at']));
		// Los 5 días son 120 horas exactas: un cambio de horario dentro de la tolerancia no la alarga.
		// En Tijuana, el 14 de marzo de 2027 la hora se adelanta: el límite cae a las 11:00 locales.
		$tijuana = CalendarPeriod::renewal(
			self::at('2027-03-12 10:00:00', self::TIJUANA),
			5,
			self::at('2027-03-17 10:30:00', self::TIJUANA),
			1,
			self::TIJUANA,
			12,
			'10:00:00.000000',
		);
		self::assertTrue($tijuana['in_time']);
		self::assertSame('2027-04-12 10:00:00.000000', self::local($tijuana['expires_at'], self::TIJUANA));
		self::assertFalse(
			CalendarPeriod::renewal(
				self::at('2027-03-12 10:00:00', self::TIJUANA),
				5,
				self::at('2027-03-17 11:00:00', self::TIJUANA),
				1,
				self::TIJUANA,
				12,
				'10:00:00.000000',
			)['in_time'],
		);
		// Una prueba no tiene tolerancia: el límite es el vencimiento mismo.
		self::assertFalse(
			CalendarPeriod::renewal($expires, 0, $expires, 1, self::MONTERREY, 2, '10:00:00.000000')['in_time'],
		);
	}

	public function testChainedRenewalsKeepTheAnchorDayAndTheDurationCanChange(): void
	{
		// Día ancla 31: 31 ene → 28 feb → 31 mar → 30 abr → 31 may, siempre a las 09:30 locales.
		$expires = self::at('2027-01-31 09:30:00');
		$chain = [];
		foreach (range(1, 4) as $index) {
			$renewal = CalendarPeriod::renewal(
				$expires,
				5,
				$expires->modify('-1 day'),
				1,
				self::MONTERREY,
				31,
				'09:30:00.000000',
			);
			$expires = $renewal['expires_at'];
			$chain[] = self::local($expires, self::MONTERREY);
		}
		self::assertSame(
			[
				'2027-02-28 09:30:00.000000',
				'2027-03-31 09:30:00.000000',
				'2027-04-30 09:30:00.000000',
				'2027-05-31 09:30:00.000000',
			],
			$chain,
		);
		// La duración puede cambiar al renovar: de mensual a anual, y de anual a trimestral.
		$annual = CalendarPeriod::renewal($expires, 5, $expires, 12, self::MONTERREY, 31, '09:30:00.000000');
		self::assertSame('2028-05-31 09:30:00.000000', self::local($annual['expires_at'], self::MONTERREY));
		$quarterly = CalendarPeriod::renewal(
			$annual['expires_at'],
			5,
			$annual['expires_at'],
			3,
			self::MONTERREY,
			31,
			'09:30:00.000000',
		);
		self::assertSame('2028-08-31 09:30:00.000000', self::local($quarterly['expires_at'], self::MONTERREY));
		foreach ([0, 2, 24] as $months) {
			try {
				CalendarPeriod::renewal($expires, 5, $expires, $months, self::MONTERREY, 31, '09:30:00.000000');
				self::fail('Accepted a duration of ' . $months . ' months');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testARenewalInTheCustomerTimeZoneKeepsTheLocalTimeAcrossADaylightSavingChange(): void
	{
		// Vence el 2 de marzo a las 10:00 en Tijuana (UTC−8); el periodo nuevo vence el 2 de abril a las
		// 10:00, que ya es horario de verano (UTC−7).
		$renewal = CalendarPeriod::renewal(
			self::at('2027-03-02 10:00:00', self::TIJUANA),
			5,
			self::at('2027-03-01 18:00:00', self::TIJUANA),
			1,
			self::TIJUANA,
			2,
			'10:00:00.000000',
		);
		self::assertSame('2027-03-02T18:00:00.000000Z', CalendarPeriod::utc($renewal['period_starts_at']));
		self::assertSame('2027-04-02T17:00:00.000000Z', CalendarPeriod::utc($renewal['expires_at']));
		// Pagar tarde a las 02:30 del 14 de marzo, hora que no existe en Tijuana: no puede ocurrir. La hora
		// del pago es un instante real; su hora local es la que se toma de ancla.
		$late = CalendarPeriod::renewal(
			self::at('2027-03-02 10:00:00', self::TIJUANA),
			5,
			new \DateTimeImmutable('2027-03-14T10:00:00Z'),
			1,
			self::TIJUANA,
			2,
			'10:00:00.000000',
		);
		self::assertSame([14, '03:00:00.000000'], [$late['anchor_day'], $late['anchor_local_time']]);
		self::assertSame('2027-04-14 03:00:00.000000', self::local($late['expires_at'], self::TIJUANA));
	}

	/**
	 * El límite registrado en la etapa 2 (DECISIONS.md, sección 10, Fase 3, decisión 8): si la hora ancla
	 * del último día de un mes no existía, el vencimiento cayó ya en el mes siguiente. El periodo
	 * encadenado se cuenta desde el mes del ancla y no se salta un mes. Son cambios de horario reales
	 * de los datos de zonas horarias.
	 */
	public function testAChainedPeriodDoesNotSkipAMonthWhenTheAnchorTimeDidNotExist(): void
	{
		$cases = [
			// El 31 de diciembre de 1981 Singapur pasó de las 23:30 a las 00:00 del 1 de enero.
			[
				'Asia/Singapore',
				'1981-12-31',
				'23:45:00.000000',
				31,
				'1982-01-01 00:00:00.000000',
				'1982-01-31 23:45:00.000000',
			],
			[
				'Asia/Kuala_Lumpur',
				'1981-12-31',
				'23:45:00.000000',
				31,
				'1982-01-01 00:00:00.000000',
				'1982-01-31 23:45:00.000000',
			],
			// El 31 de marzo de 1979 Sofía pasó de las 23:00 a las 00:00 del 1 de abril.
			[
				'Europe/Sofia',
				'1979-03-31',
				'23:30:00.000000',
				31,
				'1979-04-01 00:00:00.000000',
				'1979-04-30 23:30:00.000000',
			],
		];
		foreach ($cases as [$zone, $date, $time, $day, $resolved, $next]) {
			$expires = CalendarPeriod::localInstant($date, $time, $zone);
			self::assertSame($resolved, self::local($expires, $zone), $zone);
			$renewal = CalendarPeriod::renewal($expires, 5, $expires->modify('-1 day'), 1, $zone, $day, $time);
			self::assertSame($next, self::local($renewal['expires_at'], $zone), $zone);
			self::assertSame(
				$next,
				self::local(CalendarPeriod::addMonths($expires, 1, $zone, $day, $time), $zone),
				$zone,
			);
		}
		// Un instante cualquiera del primer día de un mes sigue contando desde su propio mes.
		$first = self::at('2027-04-01 00:00:00');
		self::assertSame(
			'2027-05-31 00:00:00.000000',
			self::local(CalendarPeriod::addMonths($first, 1, self::MONTERREY, 31, '00:00:00.000000'), self::MONTERREY),
		);
	}
}
