<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Config;
use AdelPos\License\Infrastructure\Crypto;
use PHPUnit\Framework\TestCase;

final class ProtectedRecordTest extends TestCase
{
	public function testProtectedRecordsBindPurposeContextAndAuthenticationTag(): void
	{
		$crypto = new Crypto(new Config(['CREDENTIAL_KEY' => base64_encode(random_bytes(32))], false));
		$cipher = $crypto->seal('Synthetic secret', 'credential:one', 'activation-code');
		self::assertSame('Synthetic secret', $crypto->open($cipher, 'credential:one', 'activation-code'));
		self::assertNotSame($cipher, $crypto->seal('Synthetic secret', 'credential:one', 'activation-code'));
		$raw = base64_decode($cipher);
		$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
		foreach (
			[
				[$cipher, 'credential:two', 'activation-code'],
				[$cipher, 'credential:one', 'document'],
				[base64_encode($raw), 'credential:one', 'activation-code'],
			]
			as [$value, $context, $purpose]
		) {
			try {
				$crypto->open($value, $context, $purpose);
				self::fail('Unauthenticated record accepted');
			} catch (\RuntimeException) {
				self::assertTrue(true);
			}
		}
	}

	public function testRecordPurposesCannotAliasTheOfflineEvidenceKeyContext(): void
	{
		$crypto = new Crypto(new Config(['CREDENTIAL_KEY' => base64_encode(random_bytes(32))], false));
		$evidence = $crypto->encryptEvidence('Synthetic evidence', 'offline:one');
		self::assertSame('Synthetic evidence', $crypto->decryptEvidence($evidence, 'offline:one'));
		foreach (
			[
				static fn() => $crypto->seal('Synthetic secret', 'offline:one', 'offline-evidence'),
				static fn() => $crypto->open($evidence, 'offline:one', 'offline-evidence'),
				static fn() => $crypto->seal('Synthetic secret', 'credential:one', '../document'),
			]
			as $call
		) {
			try {
				$call();
				self::fail('Reserved or malformed purpose accepted');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
