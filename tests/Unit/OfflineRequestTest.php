<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{ApiProblem, OfflineRequest, Protocol, Uuid};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineRequestTest extends TestCase
{
	/** Solicitud de activación del contrato (sección 9.1); $prefix permite firmar con otro prefijo. */
	private function envelope(
		array $changes = [],
		?string $inner = null,
		string $prefix = "ADELPOS-LICREQ-V1\n",
		array $remove = [],
	): string {
		$pair = sodium_crypto_sign_seed_keypair(str_repeat('x', 32));
		$payload = [
			'action' => 'activate',
			'request_id' => Uuid::create(),
			'created_at' => '2001-02-03T12:34:56.123456789Z',
			'product_id' => 'adel_pos',
			'server_id' => Uuid::create(),
			'installation_public_key' => Protocol::encode(sodium_crypto_sign_publickey($pair)),
			'app_version' => '0.12.0',
			'license_key' => 'K7M-9R3X-6TVP-8CHD',
		];
		$payload = array_diff_key(array_replace($payload, $changes), array_flip($remove));
		$encoded = Protocol::encode($inner ?? Protocol::json($payload));
		return Protocol::json([
			'schema_version' => '1.0',
			'payload_b64u' => $encoded,
			'signature_b64u' => Protocol::encode(
				sodium_crypto_sign_detached($prefix . $encoded, sodium_crypto_sign_secretkey($pair)),
			),
		]);
	}

	public function testExactSignedBytesAndSafeProjection(): void
	{
		$envelope = $this->envelope();
		$verified = OfflineRequest::verify($envelope);
		// El contenido que se guarda a la vista nunca lleva el código comercial.
		self::assertSame(
			['action', 'request_id', 'created_at', 'product_id', 'server_id', 'installation_public_key', 'app_version'],
			array_keys($verified['payload']),
		);
		self::assertSame('2001-02-03T12:34:56.123456789Z', $verified['payload']['created_at']);
		$reformatted = json_encode(array_reverse(json_decode($envelope, true), true), JSON_PRETTY_PRINT);
		self::assertSame($verified, OfflineRequest::verify($reformatted));
		// El código comercial es opcional al activar.
		OfflineRequest::verify($this->envelope(remove: ['license_key']));
		// «renew» y «deactivate» llevan la licencia y la activación, y no llevan código.
		$bound = [
			'license_id' => '11111111-1111-4111-8111-111111111111',
			'activation_id' => '33333333-3333-4333-8333-333333333333',
		];
		foreach (['renew', 'deactivate'] as $action) {
			$payload = OfflineRequest::verify($this->envelope(['action' => $action] + $bound, remove: ['license_key']))[
				'payload'
			];
			self::assertSame($action, $payload['action']);
			self::assertCount(9, $payload);
		}
		// Sin decimales también es un instante válido.
		OfflineRequest::verify($this->envelope(['created_at' => '2026-11-16T06:30:00Z']));
	}

	public function testTheReplacedFormatIsRejected(): void
	{
		$fingerprint = ['fingerprint_version' => '1', 'fingerprint_hash' => 'sha256:' . str_repeat('a', 64)];
		$previous = ['installation_id' => Uuid::create()] + $fingerprint;
		$invalid = [
			'huella' => $this->envelope($fingerprint),
			'identidad anterior' => $this->envelope($previous, remove: ['server_id', 'app_version']),
			'solicitud anterior completa' => $this->envelope(
				$previous,
				prefix: "LICREQ-V1\n",
				remove: ['server_id', 'app_version'],
			),
		];
		foreach ($invalid as $label => $envelope) {
			try {
				OfflineRequest::verify($envelope);
				self::fail('Accepted: ' . $label);
			} catch (ApiProblem $error) {
				self::assertSame('INVALID_REQUEST', $error->errorCode, $label);
			}
		}
		// El contenido correcto firmado con el prefijo anterior no autentica.
		try {
			OfflineRequest::verify($this->envelope(prefix: "LICREQ-V1\n"));
			self::fail('Previous signature prefix accepted');
		} catch (ApiProblem $error) {
			self::assertSame(['INVALID_PROOF', 401], [$error->errorCode, $error->status]);
		}
		self::assertSame("ADELPOS-LICREQ-V1\n", OfflineRequest::SIGNATURE_PREFIX);
	}

	public static function invalidPayloads(): array
	{
		$bound = [
			'license_id' => '11111111-1111-4111-8111-111111111111',
			'activation_id' => '33333333-3333-4333-8333-333333333333',
		];
		return [
			'wrong calendar' => [['created_at' => '2026-02-30T00:00:00Z']],
			'non UTC' => [['created_at' => '2026-01-01T00:00:00-06:00']],
			'UTC written as an offset' => [['created_at' => '2026-01-01T00:00:00+00:00']],
			'ten decimals' => [['created_at' => '2026-01-01T00:00:00.1234567890Z']],
			'invalid hour' => [['created_at' => '2026-01-01T24:00:00Z']],
			'missing seconds' => [['created_at' => '2026-01-01T00:00Z']],
			'server_id is not version 4' => [['server_id' => '00000000-0000-1000-8000-000000000000']],
			'uppercase server_id' => [['server_id' => 'AAAAAAAA-2222-4222-8222-222222222222']],
			'numeric version' => [['app_version' => 12]],
			'empty version' => [['app_version' => '']],
			'version over 100 characters' => [['app_version' => str_repeat('1', 101)]],
			'unknown member' => [['features' => ['prueba_d' => true]]],
			'renew missing identity' => [['action' => 'renew']],
			'renew with a commercial code' => [['action' => 'renew'] + $bound],
			'activate with an activation' => [$bound],
			'invalid action' => [['action' => 'refresh']],
			'transfer is not an offline action' => [['action' => 'transfer']],
			'invalid product' => [['product_id' => 'Adel POS']],
			'padded public' => [['installation_public_key' => str_repeat('a', 43) . '=']],
			'control character' => [['license_key' => "secret\n"]],
		];
	}
	#[DataProvider('invalidPayloads')]
	public function testMalformedSignedPayloadIsRejected(array $changes): void
	{
		$this->expectException(ApiProblem::class);
		OfflineRequest::verify($this->envelope($changes));
	}

	public function testTamperedSignatureAndDuplicateOuterKeys(): void
	{
		$outer = json_decode($this->envelope(), true);
		$outer['signature_b64u'] = Protocol::encode(str_repeat("\0", 64));
		try {
			OfflineRequest::verify(Protocol::json($outer));
			self::fail('Invalid signature accepted');
		} catch (ApiProblem $error) {
			self::assertSame('INVALID_PROOF', $error->errorCode);
		}
		$this->expectException(ApiProblem::class);
		OfflineRequest::verify('{"schema_version":"1.0",' . substr($this->envelope(), 1));
	}

	public function testDuplicateInnerKeysAndOversizeEnvelopeAreRejected(): void
	{
		try {
			OfflineRequest::verify($this->envelope([], '{"action":"activate","action":"renew"}'));
			self::fail('Ambiguous JSON accepted');
		} catch (ApiProblem $error) {
			self::assertSame('INVALID_REQUEST', $error->errorCode);
		}
		try {
			OfflineRequest::verify(str_repeat(' ', 65537));
			self::fail('Oversize accepted');
		} catch (ApiProblem $error) {
			self::assertSame(413, $error->status);
		}
		$outer = json_decode($this->envelope(), true);
		$outer['schema_version'] = '2.0';
		try {
			OfflineRequest::verify(Protocol::json($outer));
			self::fail('Unknown schema accepted');
		} catch (ApiProblem $error) {
			self::assertSame('INCOMPATIBLE_SCHEMA', $error->errorCode);
		}
	}
}
