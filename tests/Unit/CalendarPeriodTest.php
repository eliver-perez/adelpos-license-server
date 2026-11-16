<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\CalendarPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract sections 5.1, 5.2, 5.4 and 5.5. Every expected instant was worked out by hand from the
 * rules of the contract, with America/Monterrey at UTC-6 all year and America/Tijuana on the
 * United States schedule: in 2027, UTC-8 until 14 March 10:00Z, UTC-7 until 7 November 09:00Z.
 */
final class CalendarPeriodTest extends TestCase
{
	private const MONTERREY = 'America/Monterrey';
	private const TIJUANA = 'America/Tijuana';

	private static function at(string $utc): \DateTimeImmutable
	{
		return new \DateTimeImmutable($utc);
	}

	/** Adds months keeping the anchor of the start itself, as the first period does. */
	private static function months(string $start, int $months, string $zone): string
	{
		$anchor = CalendarPeriod::anchor(self::at($start), $zone);
		return CalendarPeriod::utc(
			CalendarPeriod::addMonths(self::at($start), $months, $zone, $anchor['day'], $anchor['time']),
		);
	}

	/** @return iterable<string, array{string, int, string, string}> Start, months, zone and expected instant. */
	public static function monthCases(): iterable
	{
		// The anchor day: 31 January, 10:00 in Monterrey.
		yield '31 Jan + 1 month is 28 Feb' => [
			'2026-01-31T16:00:00Z',
			1,
			self::MONTERREY,
			'2026-02-28T16:00:00.000000Z',
		];
		yield '31 Jan + 2 months is 31 Mar' => [
			'2026-01-31T16:00:00Z',
			2,
			self::MONTERREY,
			'2026-03-31T16:00:00.000000Z',
		];
		yield '31 Jan + 3 months is 30 Apr' => [
			'2026-01-31T16:00:00Z',
			3,
			self::MONTERREY,
			'2026-04-30T16:00:00.000000Z',
		];
		yield '31 Jan + 12 months is 31 Jan' => [
			'2026-01-31T16:00:00Z',
			12,
			self::MONTERREY,
			'2027-01-31T16:00:00.000000Z',
		];
		yield '31 Jan + 0 months is itself' => [
			'2026-01-31T16:00:00Z',
			0,
			self::MONTERREY,
			'2026-01-31T16:00:00.000000Z',
		];
		// Leap and common years.
		yield '31 Jan + 1 month in a leap year is 29 Feb' => [
			'2028-01-31T16:00:00Z',
			1,
			self::MONTERREY,
			'2028-02-29T16:00:00.000000Z',
		];
		yield '31 Jan + 1 month in a common year is 28 Feb' => [
			'2027-01-31T16:00:00Z',
			1,
			self::MONTERREY,
			'2027-02-28T16:00:00.000000Z',
		];
		yield '2100 is not a leap year' => ['2100-01-31T10:00:00Z', 1, 'UTC', '2100-02-28T10:00:00.000000Z'];
		yield '2000 was a leap year' => ['2000-01-31T10:00:00Z', 1, 'UTC', '2000-02-29T10:00:00.000000Z'];
		yield '29 Feb + 1 month is 29 Mar' => [
			'2028-02-29T16:00:00Z',
			1,
			self::MONTERREY,
			'2028-03-29T16:00:00.000000Z',
		];
		yield '29 Feb + 12 months is 28 Feb' => [
			'2028-02-29T16:00:00Z',
			12,
			self::MONTERREY,
			'2029-02-28T16:00:00.000000Z',
		];
		yield '29 Feb + 24 months is 28 Feb' => [
			'2028-02-29T16:00:00Z',
			24,
			self::MONTERREY,
			'2030-02-28T16:00:00.000000Z',
		];
		yield '29 Feb + 48 months is 29 Feb again' => [
			'2028-02-29T16:00:00Z',
			48,
			self::MONTERREY,
			'2032-02-29T16:00:00.000000Z',
		];
		// The year changes.
		yield '31 Dec + 1 month is 31 Jan of the next year' => [
			'2026-12-31T16:00:00Z',
			1,
			self::MONTERREY,
			'2027-01-31T16:00:00.000000Z',
		];
		yield '31 Dec + 2 months is 28 Feb of the next year' => [
			'2026-12-31T16:00:00Z',
			2,
			self::MONTERREY,
			'2027-02-28T16:00:00.000000Z',
		];
		yield '30 Nov + 3 months is 28 Feb' => [
			'2026-11-30T16:00:00Z',
			3,
			self::MONTERREY,
			'2027-02-28T16:00:00.000000Z',
		];
		// The four subscription terms, from the example of the contract: 16 Nov 2026 00:30 in Monterrey.
		yield 'monthly' => ['2026-11-16T06:30:00Z', 1, self::MONTERREY, '2026-12-16T06:30:00.000000Z'];
		yield 'quarterly' => ['2026-11-16T06:30:00Z', 3, self::MONTERREY, '2027-02-16T06:30:00.000000Z'];
		yield 'semiannual' => ['2026-11-16T06:30:00Z', 6, self::MONTERREY, '2027-05-16T06:30:00.000000Z'];
		yield 'annual' => ['2026-11-16T06:30:00Z', 12, self::MONTERREY, '2027-11-16T06:30:00.000000Z'];
		yield 'microseconds are kept' => [
			'2026-11-16T06:30:12.345678Z',
			1,
			self::MONTERREY,
			'2026-12-16T06:30:12.345678Z',
		];
		// Monterrey close to midnight UTC: the day that counts is the local one.
		yield 'UTC says 1 Mar, Monterrey says 28 Feb: + 1 month is 28 Mar' => [
			'2026-03-01T03:30:00Z',
			1,
			self::MONTERREY,
			'2026-03-29T03:30:00.000000Z',
		];
		yield 'UTC says 1 Feb, Monterrey says 31 Jan: + 1 month is 28 Feb' => [
			'2026-02-01T03:30:00Z',
			1,
			self::MONTERREY,
			'2026-03-01T03:30:00.000000Z',
		];
		yield 'UTC says 31 Jan, Monterrey says 30 Jan: + 1 month is 28 Feb' => [
			'2026-01-31T02:00:00Z',
			1,
			self::MONTERREY,
			'2026-03-01T02:00:00.000000Z',
		];
		yield 'UTC says 31 Jan, Monterrey says 30 Jan: + 2 months is 30 Mar' => [
			'2026-01-31T02:00:00Z',
			2,
			self::MONTERREY,
			'2026-03-31T02:00:00.000000Z',
		];
		yield 'last microsecond of 30 Nov in Monterrey + 1 month' => [
			'2026-12-01T05:59:59.999999Z',
			1,
			self::MONTERREY,
			'2026-12-31T05:59:59.999999Z',
		];
		yield 'last microsecond of 30 Nov in Monterrey + 3 months' => [
			'2026-12-01T05:59:59.999999Z',
			3,
			self::MONTERREY,
			'2027-03-01T05:59:59.999999Z',
		];
		yield 'first instant of 1 Dec in Monterrey + 1 month' => [
			'2026-12-01T06:00:00Z',
			1,
			self::MONTERREY,
			'2027-01-01T06:00:00.000000Z',
		];
		// Tijuana keeps the local hour across a clock change: the UTC hour moves.
		yield '10:00 in winter + 1 month is 10:00 in summer' => [
			'2027-03-01T18:00:00Z',
			1,
			self::TIJUANA,
			'2027-04-01T17:00:00.000000Z',
		];
		yield '10:00 in winter + 9 months is 10:00 in winter' => [
			'2027-03-01T18:00:00Z',
			9,
			self::TIJUANA,
			'2027-12-01T18:00:00.000000Z',
		];
		// 14 March 2027 in Tijuana: 02:00 to 03:00 does not exist. The result is the first instant after the jump.
		yield 'missing hour: 01:59:59.999999 still exists' => [
			'2027-02-14T09:59:59.999999Z',
			1,
			self::TIJUANA,
			'2027-03-14T09:59:59.999999Z',
		];
		yield 'missing hour: 02:00 is its first instant' => [
			'2027-02-14T10:00:00Z',
			1,
			self::TIJUANA,
			'2027-03-14T10:00:00.000000Z',
		];
		yield 'missing hour: 02:30' => ['2027-02-14T10:30:00Z', 1, self::TIJUANA, '2027-03-14T10:00:00.000000Z'];
		yield 'missing hour: 02:30:00.123456 loses its fraction' => [
			'2027-02-14T10:30:00.123456Z',
			1,
			self::TIJUANA,
			'2027-03-14T10:00:00.000000Z',
		];
		yield 'missing hour: 02:59:59.999999 is its last instant' => [
			'2027-02-14T10:59:59.999999Z',
			1,
			self::TIJUANA,
			'2027-03-14T10:00:00.000000Z',
		];
		yield 'missing hour: 03:00 exists' => ['2027-02-14T11:00:00Z', 1, self::TIJUANA, '2027-03-14T10:00:00.000000Z'];
		yield 'missing hour: 03:00:00.000001 exists' => [
			'2027-02-14T11:00:00.000001Z',
			1,
			self::TIJUANA,
			'2027-03-14T10:00:00.000001Z',
		];
		// 7 November 2027 in Tijuana: 01:00 to 02:00 happens twice. The result is the first of the two.
		yield 'repeated hour: 00:59:59.999999 happens once' => [
			'2027-10-07T07:59:59.999999Z',
			1,
			self::TIJUANA,
			'2027-11-07T07:59:59.999999Z',
		];
		yield 'repeated hour: 01:00 is its first instant' => [
			'2027-10-07T08:00:00Z',
			1,
			self::TIJUANA,
			'2027-11-07T08:00:00.000000Z',
		];
		yield 'repeated hour: 01:30' => ['2027-10-07T08:30:00Z', 1, self::TIJUANA, '2027-11-07T08:30:00.000000Z'];
		yield 'repeated hour: 01:59:59.999999 is its last instant' => [
			'2027-10-07T08:59:59.999999Z',
			1,
			self::TIJUANA,
			'2027-11-07T08:59:59.999999Z',
		];
		yield 'repeated hour: 02:00 happens once' => [
			'2027-10-07T09:00:00Z',
			1,
			self::TIJUANA,
			'2027-11-07T10:00:00.000000Z',
		];
	}

	#[DataProvider('monthCases')]
	public function testAddingCalendarMonthsKeepsTheAnchorDayAndTheLocalTime(
		string $start,
		int $months,
		string $zone,
		string $expected,
	): void {
		self::assertSame($expected, self::months($start, $months, $zone));
	}

	public function testTheAnchorDoesNotDriftWhenPeriodsAreChained(): void
	{
		// 31 Jan → 28 Feb → 31 Mar → 30 Apr → 31 May: each period starts where the last one ended, with the same anchor.
		$instant = self::at('2026-01-31T16:00:00Z');
		$anchor = CalendarPeriod::anchor($instant, self::MONTERREY);
		self::assertSame(['day' => 31, 'time' => '10:00:00.000000'], $anchor);
		foreach (
			[
				'2026-02-28T16:00:00.000000Z',
				'2026-03-31T16:00:00.000000Z',
				'2026-04-30T16:00:00.000000Z',
				'2026-05-31T16:00:00.000000Z',
			]
			as $expected
		) {
			$instant = CalendarPeriod::addMonths($instant, 1, self::MONTERREY, $anchor['day'], $anchor['time']);
			self::assertSame($expected, CalendarPeriod::utc($instant));
		}
		// Without the stored anchor the day would stay at 28 after February.
		self::assertSame('2026-03-28T16:00:00.000000Z', self::months('2026-02-28T16:00:00Z', 1, self::MONTERREY));

		// 29 Feb 2028, yearly: 28 Feb until the next leap year brings the 29th back.
		$instant = self::at('2028-02-29T16:00:00Z');
		foreach (
			[
				'2029-02-28T16:00:00.000000Z',
				'2030-02-28T16:00:00.000000Z',
				'2031-02-28T16:00:00.000000Z',
				'2032-02-29T16:00:00.000000Z',
			]
			as $expected
		) {
			$instant = CalendarPeriod::addMonths($instant, 12, self::MONTERREY, 29, '10:00:00.000000');
			self::assertSame($expected, CalendarPeriod::utc($instant));
		}

		// A missing hour moves one period only: the anchor is still 02:30, and the next month returns to it.
		$shifted = CalendarPeriod::addMonths(self::at('2027-02-14T10:30:00Z'), 1, self::TIJUANA, 14, '02:30:00.000000');
		self::assertSame('2027-03-14T10:00:00.000000Z', CalendarPeriod::utc($shifted));
		self::assertSame(
			'2027-04-14T09:30:00.000000Z',
			CalendarPeriod::utc(CalendarPeriod::addMonths($shifted, 1, self::TIJUANA, 14, '02:30:00.000000')),
		);
	}

	/** @return iterable<string, array{string, int, string, string}> */
	public static function dayCases(): iterable
	{
		yield '15 days' => ['2026-11-16T06:30:00Z', 15, self::MONTERREY, '2026-12-01T06:30:00.000000Z'];
		yield '15 days keep microseconds' => [
			'2026-11-16T06:30:12.345678Z',
			15,
			self::MONTERREY,
			'2026-12-01T06:30:12.345678Z',
		];
		yield '15 days across the end of the year' => [
			'2026-12-20T16:00:00Z',
			15,
			self::MONTERREY,
			'2027-01-04T16:00:00.000000Z',
		];
		yield '15 days across 29 Feb' => ['2028-02-20T16:00:00Z', 15, self::MONTERREY, '2028-03-06T16:00:00.000000Z'];
		yield '15 days across 28 Feb' => ['2027-02-20T16:00:00Z', 15, self::MONTERREY, '2027-03-07T16:00:00.000000Z'];
		yield '15 days from the local day, not the UTC day' => [
			'2026-02-01T03:30:00Z',
			15,
			self::MONTERREY,
			'2026-02-16T03:30:00.000000Z',
		];
		yield '0 days is itself' => ['2026-11-16T06:30:00Z', 0, self::MONTERREY, '2026-11-16T06:30:00.000000Z'];
		// Calendar days at the same local time: across a clock change they are not 15 × 24 hours.
		yield '15 days across the spring change keep 10:00' => [
			'2027-03-05T18:00:00Z',
			15,
			self::TIJUANA,
			'2027-03-20T17:00:00.000000Z',
		];
		yield '15 days across the autumn change keep 10:00' => [
			'2027-10-30T17:00:00Z',
			15,
			self::TIJUANA,
			'2027-11-14T18:00:00.000000Z',
		];
		yield '15 days into the missing hour' => [
			'2027-02-27T10:30:00Z',
			15,
			self::TIJUANA,
			'2027-03-14T10:00:00.000000Z',
		];
		yield '15 days into the repeated hour' => [
			'2027-10-23T08:30:00Z',
			15,
			self::TIJUANA,
			'2027-11-07T08:30:00.000000Z',
		];
	}

	#[DataProvider('dayCases')]
	public function testAddingCalendarDaysKeepsTheLocalTime(
		string $start,
		int $days,
		string $zone,
		string $expected,
	): void {
		self::assertSame($expected, CalendarPeriod::utc(CalendarPeriod::addDays(self::at($start), $days, $zone)));
	}

	/** @return iterable<string, array{array<string,mixed>, ?string, string}> Terms, expected expiry and expected end of updates. */
	public static function firstPeriodCases(): iterable
	{
		// Activated on 16 Nov 2026 at 06:30:00Z, which is 00:30 in Monterrey.
		yield 'monthly subscription' => [
			['license_type' => 'subscription', 'billing_period' => 'monthly'],
			'2026-12-16T06:30:00.000000Z',
			'2026-12-16T06:30:00.000000Z',
		];
		yield 'quarterly subscription' => [
			['license_type' => 'subscription', 'billing_period' => 'quarterly'],
			'2027-02-16T06:30:00.000000Z',
			'2027-02-16T06:30:00.000000Z',
		];
		yield 'semiannual subscription' => [
			['license_type' => 'subscription', 'billing_period' => 'semiannual'],
			'2027-05-16T06:30:00.000000Z',
			'2027-05-16T06:30:00.000000Z',
		];
		yield 'annual subscription' => [
			['license_type' => 'subscription', 'billing_period' => 'annual'],
			'2027-11-16T06:30:00.000000Z',
			'2027-11-16T06:30:00.000000Z',
		];
		yield '15-day trial' => [
			['license_type' => 'trial', 'trial_duration' => '15d'],
			'2026-12-01T06:30:00.000000Z',
			'2026-12-01T06:30:00.000000Z',
		];
		yield '1-month trial' => [
			['license_type' => 'trial', 'trial_duration' => '1m'],
			'2026-12-16T06:30:00.000000Z',
			'2026-12-16T06:30:00.000000Z',
		];
		yield '3-month trial' => [
			['license_type' => 'trial', 'trial_duration' => '3m'],
			'2027-02-16T06:30:00.000000Z',
			'2027-02-16T06:30:00.000000Z',
		];
		yield 'perpetual without updates' => [
			['license_type' => 'perpetual', 'updates_months' => 0],
			null,
			'2026-11-16T06:30:00.000000Z',
		];
		yield 'perpetual with 6 months of updates' => [
			['license_type' => 'perpetual', 'updates_months' => 6],
			null,
			'2027-05-16T06:30:00.000000Z',
		];
		yield 'perpetual with 12 months of updates' => [
			['license_type' => 'perpetual', 'updates_months' => 12],
			null,
			'2027-11-16T06:30:00.000000Z',
		];
		// A database row: every column is present and numbers may arrive as text.
		yield 'row of a subscription' => [
			[
				'license_type' => 'subscription',
				'billing_period' => 'annual',
				'trial_duration' => null,
				'updates_months' => null,
			],
			'2027-11-16T06:30:00.000000Z',
			'2027-11-16T06:30:00.000000Z',
		];
		yield 'row of a perpetual license' => [
			[
				'license_type' => 'perpetual',
				'billing_period' => null,
				'trial_duration' => null,
				'updates_months' => '12',
			],
			null,
			'2027-11-16T06:30:00.000000Z',
		];
	}

	#[DataProvider('firstPeriodCases')]
	public function testFirstPeriodStartsAtTheActivationForEveryLicenseType(
		array $terms,
		?string $expires,
		string $updates,
	): void {
		// The instant may arrive in any zone: what is stored and signed is UTC.
		$period = CalendarPeriod::firstPeriod(
			$terms,
			new \DateTimeImmutable('2026-11-16 00:30:00', new \DateTimeZone(self::MONTERREY)),
			self::MONTERREY,
		);
		self::assertSame(
			['period_starts_at', 'expires_at', 'updates_until', 'anchor_day', 'anchor_local_time'],
			array_keys($period),
		);
		self::assertSame('2026-11-16T06:30:00.000000Z', CalendarPeriod::utc($period['period_starts_at']));
		self::assertSame('UTC', $period['period_starts_at']->getTimezone()->getName());
		self::assertSame($expires, $period['expires_at'] === null ? null : CalendarPeriod::utc($period['expires_at']));
		self::assertSame($updates, CalendarPeriod::utc($period['updates_until']));
		// The anchor is taken from the start, in the time zone of the customer.
		self::assertSame([16, '00:30:00.000000'], [$period['anchor_day'], $period['anchor_local_time']]);
	}

	public function testFirstPeriodUsesTheLocalDayAndKeepsAnActivationInsideARepeatedHour(): void
	{
		// 03:30Z on 1 March is still 28 February in Monterrey: the anchor day is 28, not 1.
		$period = CalendarPeriod::firstPeriod(
			['license_type' => 'subscription', 'billing_period' => 'monthly'],
			self::at('2026-03-01T03:30:00.5Z'),
			self::MONTERREY,
		);
		self::assertSame(
			[28, '21:30:00.500000', '2026-03-29T03:30:00.500000Z'],
			[$period['anchor_day'], $period['anchor_local_time'], CalendarPeriod::utc($period['expires_at'])],
		);
		// The same instant for a customer in Tijuana is 19:30 of the 28th.
		$period = CalendarPeriod::firstPeriod(
			['license_type' => 'subscription', 'billing_period' => 'monthly'],
			self::at('2026-03-01T03:30:00.5Z'),
			self::TIJUANA,
		);
		self::assertSame(
			[28, '19:30:00.500000', '2026-03-29T02:30:00.500000Z'],
			[$period['anchor_day'], $period['anchor_local_time'], CalendarPeriod::utc($period['expires_at'])],
		);

		// Activated at 09:30Z on 7 November 2027 in Tijuana: the second time the clock shows 01:30.
		$start = self::at('2027-11-07T09:30:00Z');
		$perpetual = CalendarPeriod::firstPeriod(
			['license_type' => 'perpetual', 'updates_months' => 0],
			$start,
			self::TIJUANA,
		);
		self::assertSame([7, '01:30:00.000000'], [$perpetual['anchor_day'], $perpetual['anchor_local_time']]);
		// With 0 months, updates end at the activation itself, never before it...
		self::assertSame('2027-11-07T09:30:00.000000Z', CalendarPeriod::utc($perpetual['updates_until']));
		self::assertEquals($perpetual['period_starts_at'], $perpetual['updates_until']);
		// ...although that local time, looked up again, is the first of the two instants.
		self::assertSame(
			'2027-11-07T08:30:00.000000Z',
			CalendarPeriod::utc(CalendarPeriod::addMonths($start, 0, self::TIJUANA, 7, '01:30:00.000000')),
		);
		$monthly = CalendarPeriod::firstPeriod(
			['license_type' => 'subscription', 'billing_period' => 'monthly'],
			$start,
			self::TIJUANA,
		);
		self::assertSame('2027-12-07T09:30:00.000000Z', CalendarPeriod::utc($monthly['expires_at']));
		$trial = CalendarPeriod::firstPeriod(
			['license_type' => 'trial', 'trial_duration' => '15d'],
			$start,
			self::TIJUANA,
		);
		self::assertSame('2027-11-22T09:30:00.000000Z', CalendarPeriod::utc($trial['expires_at']));
	}

	public function testInstantsAreWrittenInUtcWithSixDecimals(): void
	{
		$local = new \DateTimeImmutable('2026-11-16 00:30:00.5', new \DateTimeZone(self::MONTERREY));
		self::assertSame('2026-11-16T06:30:00.500000Z', CalendarPeriod::utc($local));
		self::assertSame('2026-11-16 06:30:00.500000', CalendarPeriod::sql($local));
		self::assertSame('2026-11-16T06:30:00.000000Z', CalendarPeriod::utc(self::at('2026-11-16T06:30:00Z')));
		self::assertSame('2026-11-16T06:30:00.000000Z', CalendarPeriod::utc(self::at('2026-11-16T07:30:00+01:00')));
		// The stored form goes back to the signed form without losing anything.
		self::assertSame(
			'2026-11-16T06:30:00.500000Z',
			\AdelPos\License\Domain\Protocol::utc(CalendarPeriod::sql($local)),
		);
		foreach (self::monthCases() as [$start, $months, $zone]) {
			self::assertMatchesRegularExpression(
				'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/',
				self::months($start, $months, $zone),
			);
		}
	}

	public function testInvalidArgumentsAreRejected(): void
	{
		$start = self::at('2026-11-16T06:30:00Z');
		$invalid = [
			'negative months' => fn() => CalendarPeriod::addMonths($start, -1, self::MONTERREY, 16, '00:30:00.000000'),
			'anchor day 0' => fn() => CalendarPeriod::addMonths($start, 1, self::MONTERREY, 0, '00:30:00.000000'),
			'anchor day 32' => fn() => CalendarPeriod::addMonths($start, 1, self::MONTERREY, 32, '00:30:00.000000'),
			'anchor time without microseconds' => fn() => CalendarPeriod::addMonths(
				$start,
				1,
				self::MONTERREY,
				16,
				'00:30:00',
			),
			'anchor time 24:00' => fn() => CalendarPeriod::addMonths($start, 1, self::MONTERREY, 16, '24:00:00.000000'),
			'anchor time with an offset' => fn() => CalendarPeriod::addMonths(
				$start,
				1,
				self::MONTERREY,
				16,
				'00:30:00.000000-06:00',
			),
			'unknown zone' => fn() => CalendarPeriod::addMonths($start, 1, 'America/Monterey', 16, '00:30:00.000000'),
			'zone abbreviation' => fn() => CalendarPeriod::addMonths($start, 1, 'CST', 16, '00:30:00.000000'),
			'offset instead of a zone' => fn() => CalendarPeriod::addDays($start, 15, '-06:00'),
			'negative days' => fn() => CalendarPeriod::addDays($start, -1, self::MONTERREY),
			'unknown type' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'lifetime'],
				$start,
				self::MONTERREY,
			),
			'no type' => fn() => CalendarPeriod::firstPeriod([], $start, self::MONTERREY),
			'subscription without period' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'subscription'],
				$start,
				self::MONTERREY,
			),
			'unknown period' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'subscription', 'billing_period' => 'weekly'],
				$start,
				self::MONTERREY,
			),
			'trial without duration' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'trial', 'trial_duration' => null],
				$start,
				self::MONTERREY,
			),
			'unknown duration' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'trial', 'trial_duration' => '30d'],
				$start,
				self::MONTERREY,
			),
			'perpetual without updates term' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'perpetual', 'updates_months' => null],
				$start,
				self::MONTERREY,
			),
			'perpetual with 3 months' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'perpetual', 'updates_months' => 3],
				$start,
				self::MONTERREY,
			),
			'first period in an unknown zone' => fn() => CalendarPeriod::firstPeriod(
				['license_type' => 'perpetual', 'updates_months' => 6],
				$start,
				'Monterrey',
			),
		];
		foreach ($invalid as $name => $call) {
			try {
				$call();
				self::fail('Accepted: ' . $name);
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Section 5.1 stated another way, without the zone transition tables: of all the instants,
	 * the first whose local time is not earlier than the wanted one. When the wanted time exists
	 * once it is that instant; when it repeats, the first; when it is missing, the first after the jump.
	 */
	private static function firstInstantNotBefore(\DateTimeZone $zone, string $wanted): \DateTimeImmutable
	{
		$instant = (new \DateTimeImmutable($wanted . ':00', new \DateTimeZone('UTC')))->modify('-15 hours');
		for ($minute = 0; $minute < 31 * 60; ++$minute) {
			if ($instant->setTimezone($zone)->format('Y-m-d H:i') >= $wanted) {
				return $instant;
			}
			$instant = $instant->modify('+1 minute');
		}
		self::fail('No instant found for ' . $wanted);
	}

	public function testEveryLocalTimeAroundAClockChangeResolvesToTheFirstInstantNotBeforeIt(): void
	{
		// Changes of one hour and of thirty minutes, at 02:00 and at midnight, in both hemispheres.
		$changes = 0;
		$missing = 0;
		$repeated = 0;
		foreach (
			[self::TIJUANA, 'America/Santiago', 'Australia/Lord_Howe', 'Europe/Madrid', self::MONTERREY]
			as $name
		) {
			$zone = new \DateTimeZone($name);
			$transitions = $zone->getTransitions(gmmktime(0, 0, 0, 1, 1, 2027), gmmktime(0, 0, 0, 1, 1, 2028));
			// A zone without changes is checked on an ordinary day.
			$moments =
				count($transitions) > 1
					? array_slice($transitions, 1)
					: [['ts' => gmmktime(12, 0, 0, 6, 15, 2027), 'offset' => $transitions[0]['offset']]];
			foreach ($moments as $index => $transition) {
				$before = count($transitions) > 1 ? $transitions[$index]['offset'] : $transition['offset'];
				$changes += count($transitions) > 1 ? 1 : 0;
				// Wall times from 90 minutes before the earlier side of the change to 90 minutes after the later one.
				$from = $transition['ts'] + min($before, $transition['offset']) - 5400;
				$to = $transition['ts'] + max($before, $transition['offset']) + 5400;
				for ($wall = $from; $wall <= $to; $wall += 300) {
					$wanted = gmdate('Y-m-d H:i', $wall);
					$expected = self::firstInstantNotBefore($zone, $wanted);
					// addMonths with 0 months resolves the anchor day and time inside the month of its start.
					$start = new \DateTimeImmutable(substr($wanted, 0, 8) . '15 12:00:00', new \DateTimeZone('UTC'));
					$actual = CalendarPeriod::addMonths(
						$start,
						0,
						$name,
						(int) substr($wanted, 8, 2),
						substr($wanted, 11) . ':00.000000',
					);
					self::assertSame(
						CalendarPeriod::utc($expected),
						CalendarPeriod::utc($actual),
						$name . ' ' . $wanted,
					);
					$shown = $actual->setTimezone($zone)->format('Y-m-d H:i');
					if ($shown !== $wanted) {
						++$missing;
						self::assertGreaterThan($wanted, $shown, $name . ' ' . $wanted);
					} elseif (
						$actual
							->modify('+' . abs($before - $transition['offset']) . ' seconds')
							->setTimezone($zone)
							->format('Y-m-d H:i') === $wanted
					) {
						++$repeated;
					}
				}
			}
		}
		// The data of this run really contained changes with missing and with repeated local times.
		self::assertGreaterThanOrEqual(6, $changes);
		self::assertGreaterThan(20, $missing);
		self::assertGreaterThan(20, $repeated);
	}

	public function testResultsAlwaysLandOnTheAnchorDayOrTheLastDayOfTheMonth(): void
	{
		mt_srand(20261116);
		$base = gmmktime(0, 0, 0, 1, 1, 2026);
		foreach ([self::MONTERREY, 'UTC', self::TIJUANA] as $name) {
			$zone = new \DateTimeZone($name);
			for ($case = 0; $case < 300; ++$case) {
				$start = \DateTimeImmutable::createFromFormat(
					'U.u',
					sprintf('%d.%06d', $base + mt_rand(0, 6 * 366 * 86400), mt_rand(0, 999999)),
				);
				$months = mt_rand(1, 36);
				$anchor = CalendarPeriod::anchor($start, $name);
				$result = CalendarPeriod::addMonths($start, $months, $name, $anchor['day'], $anchor['time']);
				$from = $start->setTimezone($zone);
				$to = $result->setTimezone($zone);
				$context = $name . ' ' . CalendarPeriod::utc($start) . ' + ' . $months;
				self::assertGreaterThan($start, $result, $context);
				self::assertSame(
					(int) $from->format('Y') * 12 + (int) $from->format('n') + $months,
					(int) $to->format('Y') * 12 + (int) $to->format('n'),
					$context,
				);
				self::assertSame(min($anchor['day'], (int) $to->format('t')), (int) $to->format('j'), $context);
				// Without clock changes the local time is exactly the anchor; with them it differs only inside a missing hour.
				if ($name !== self::TIJUANA || $to->format('H:i:s.u') === $anchor['time']) {
					self::assertSame($anchor['time'], $to->format('H:i:s.u'), $context);
				} else {
					self::assertSame('03:00:00.000000', $to->format('H:i:s.u'), $context);
					self::assertStringStartsWith('02:', $anchor['time'], $context);
				}
			}
		}
	}
}
