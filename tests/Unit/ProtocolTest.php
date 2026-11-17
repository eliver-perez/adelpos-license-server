<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{ApiProblem, Jws, Protocol, StrictJson};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
	public function testFixtureConfigurationCannotInheritRuntimeDatabaseSettings(): void
	{
		$previous = getenv('DB_DSN');
		putenv('DB_DSN=mysql:host=must-not-connect;dbname=unrelated');
		try {
			self::assertSame(
				'mysql:dbname=isolated_test',
				(new \AdelPos\License\Config(['DB_DSN' => 'mysql:dbname=isolated_test'], false))->get('DB_DSN'),
			);
		} finally {
			$previous === false ? putenv('DB_DSN') : putenv('DB_DSN=' . $previous);
		}
	}
	public static function invalidJson(): array
	{
		return array_map(fn($value) => [$value], [
			'[]',
			'null',
			'{"a":1,"a":2}',
			'{"a":1,"\\u0061":2}',
			'{"a":[{"b":1,"b":2}]}',
			'{"a":NaN}',
			"{\"a\":\"\xff\"}",
			'{"a":1} trailing',
		]);
	}
	#[DataProvider('invalidJson')]
	public function testInvalidOrAmbiguousJsonIsRejected(string $json): void
	{
		$this->expectException(ApiProblem::class);
		StrictJson::object($json);
	}
	public function testNestedJsonAndEscapedDelimitersRemainIntact(): void
	{
		$json = json_encode(
			['a' => (object) ['b' => [true, null, 1.5, 'a,}:"b']], 'c' => (object) []],
			JSON_THROW_ON_ERROR,
		);
		self::assertEquals(get_object_vars(json_decode($json)), StrictJson::object($json));
	}
	public function testBase64PaddingAndNoncanonicalTrailingBitsAreRejected(): void
	{
		$encoded = Protocol::encode(str_repeat("\0", 32));
		self::assertSame(str_repeat("\0", 32), Protocol::decode($encoded, 32));
		foreach ([$encoded . '=', substr($encoded, 0, -1) . 'B'] as $invalid) {
			try {
				Protocol::decode($invalid, 32);
				self::fail('Invalid base64 accepted');
			} catch (ApiProblem $error) {
				self::assertSame('INVALID_REQUEST', $error->errorCode);
			}
		}
	}
	public function testJwsHasTheExactContractHeaderAndAVerifiableSignature(): void
	{
		$pair = sodium_crypto_sign_keypair();
		$jws = Jws::sign(
			['licensee_name' => 'Café Ñandú / Centro', 'features' => new \stdClass()],
			'adelpos-test-202611-1',
			sodium_crypto_sign_secretkey($pair),
		);
		[$header, $payload, $signature] = explode('.', $jws);
		self::assertSame(
			'{"alg":"EdDSA","kid":"adelpos-test-202611-1","typ":"adelpos-lic+jws"}',
			sodium_base642bin($header, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
		);
		// Sin secuencias de escape para diagonales ni acentos, y «features» como objeto.
		self::assertSame(
			'{"licensee_name":"Café Ñandú / Centro","features":{}}',
			sodium_base642bin($payload, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
		);
		self::assertTrue(
			sodium_crypto_sign_verify_detached(
				Protocol::decode($signature, 64),
				$header . '.' . $payload,
				sodium_crypto_sign_publickey($pair),
			),
		);
	}

	public function testProofMessageMatchesTheSharedFixtures(): void
	{
		$vectors = json_decode(
			file_get_contents(dirname(__DIR__, 2) . '/contracts/v1/fixtures/vectors.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		foreach ($vectors['proofs'] as $vector) {
			self::assertSame(
				$vector['message_utf8'],
				Protocol::proofMessage($vector['action'], $vector, $vector['nonce']),
			);
		}
	}
	public function testSchemaDoesNotCoerceOrAllowUnknownFields(): void
	{
		$valid = [
			'action' => 'activate',
			'product_id' => 'adel_pos',
			'installation_id' => '22222222-2222-4222-8222-222222222222',
			'activation_id' => null,
		];
		self::assertSame('activate', Protocol::input('challenge', $valid)['action']);
		foreach (
			[
				$valid + ['extra' => true],
				array_replace($valid, ['activation_id' => '']),
				array_replace($valid, ['installation_id' => '22222222-2222-1222-8222-222222222222']),
			]
			as $invalid
		) {
			try {
				Protocol::input('challenge', $invalid);
				self::fail('Invalid schema accepted');
			} catch (ApiProblem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}
}
