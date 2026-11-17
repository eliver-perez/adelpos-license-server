<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{ApiProblem, ApiResponse, Jws, Protocol, StrictJson};
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

	public function testProofMessageIsExactlyTheOneOfTheContract(): void
	{
		$input = [
			'challenge_id' => '55555555-5555-4555-8555-555555555555',
			'product_id' => 'adel_pos',
			'server_id' => '22222222-2222-4222-8222-222222222222',
		];
		// Contrato, sección 8.2: siete líneas separadas por un salto de línea, sin salto final.
		self::assertSame(
			"ADELPOS-LIC-V1\nactivate\n55555555-5555-4555-8555-555555555555\nNONCE\nadel_pos\n" .
				"22222222-2222-4222-8222-222222222222\n-",
			Protocol::proofMessage('activate', $input, 'NONCE'),
		);
		$input['activation_id'] = '33333333-3333-4333-8333-333333333333';
		foreach (['refresh', 'deactivate'] as $action) {
			self::assertSame(
				"ADELPOS-LIC-V1\n" .
					$action .
					"\n55555555-5555-4555-8555-555555555555\nNONCE\nadel_pos\n" .
					"22222222-2222-4222-8222-222222222222\n33333333-3333-4333-8333-333333333333",
				Protocol::proofMessage($action, $input, 'NONCE'),
			);
		}
	}

	/** Petición de activación con los ocho campos del contrato (sección 8.3). */
	private static function activation(): array
	{
		return [
			'request_id' => '44444444-4444-4444-8444-444444444444',
			'product_id' => 'adel_pos',
			'license_key' => 'K7M-9R3X-6TVP-8CHD',
			'server_id' => '22222222-2222-4222-8222-222222222222',
			'installation_public_key' => Protocol::encode(str_repeat("\1", 32)),
			'challenge_id' => '55555555-5555-4555-8555-555555555555',
			'proof' => Protocol::encode(str_repeat("\2", 64)),
			'app_version' => '0.12.0',
		];
	}

	public function testRequestsHaveExactlyTheFieldsOfTheContract(): void
	{
		$challenge = [
			'action' => 'activate',
			'product_id' => 'adel_pos',
			'server_id' => '22222222-2222-4222-8222-222222222222',
			'activation_id' => null,
		];
		$activation = self::activation();
		$existing = array_diff_key($activation, ['license_key' => 1, 'installation_public_key' => 1]) + [
			'activation_id' => '33333333-3333-4333-8333-333333333333',
		];
		// Lo válido se acepta y sale ordenado por nombre de campo, que es como se compara un reintento.
		self::assertSame(
			['action', 'activation_id', 'product_id', 'server_id'],
			array_keys(Protocol::input('challenge', $challenge)),
		);
		self::assertCount(8, Protocol::input('activate', $activation));
		foreach (['refresh', 'deactivate'] as $action) {
			self::assertCount(7, Protocol::input($action, $existing));
			Protocol::input(
				'challenge',
				array_replace($challenge, ['action' => $action, 'activation_id' => $existing['activation_id']]),
			);
		}
		// Los UUID que no son la identidad de la instalación se aceptan en cualquier caja.
		Protocol::input(
			'activate',
			array_replace($activation, ['request_id' => strtoupper($activation['request_id'])]),
		);

		$fingerprint = ['fingerprint_version' => '1', 'fingerprint_hash' => 'sha256:' . str_repeat('a', 64)];
		$renamed = fn(array $input): array => array_diff_key($input, ['server_id' => 1]) + [
			'installation_id' => $input['server_id'],
		];
		$invalid = [
			'campo de más en el desafío' => ['challenge', $challenge + ['extra' => true]],
			'activación vacía' => ['challenge', array_replace($challenge, ['activation_id' => ''])],
			'activar con activación' => [
				'challenge',
				array_replace($challenge, ['activation_id' => $existing['activation_id']]),
			],
			'consultar sin activación' => ['challenge', array_replace($challenge, ['action' => 'refresh'])],
			'acción transfer' => ['challenge', array_replace($challenge, ['action' => 'transfer'])],
			'acción transfer con activación' => [
				'challenge',
				array_replace($challenge, ['action' => 'transfer', 'activation_id' => $existing['activation_id']]),
			],
			'acción desconocida' => ['challenge', array_replace($challenge, ['action' => 'renew'])],
			'identidad anterior en el desafío' => ['challenge', $renamed($challenge)],
			'server_id que no es versión 4' => [
				'challenge',
				array_replace($challenge, ['server_id' => '22222222-2222-1222-8222-222222222222']),
			],
			'server_id en mayúsculas' => [
				'challenge',
				array_replace($challenge, ['server_id' => 'AAAAAAAA-2222-4222-8222-222222222222']),
			],
			'producto con formato inválido' => ['challenge', array_replace($challenge, ['product_id' => 'Adel POS'])],
			'huella' => ['activate', $activation + $fingerprint],
			'identidad anterior' => ['activate', $renamed($activation)],
			'petición anterior completa' => ['activate', $renamed($activation) + $fingerprint],
			'sin versión de la aplicación' => ['activate', array_diff_key($activation, ['app_version' => 1])],
			'versión de más de 100 caracteres' => [
				'activate',
				array_replace($activation, ['app_version' => str_repeat('1', 101)]),
			],
			'versión vacía' => ['activate', array_replace($activation, ['app_version' => ''])],
			'valor numérico' => ['activate', array_replace($activation, ['app_version' => 12])],
			'carácter de control' => ['activate', array_replace($activation, ['app_version' => "0.12\n"])],
			'llave de otro tamaño' => [
				'activate',
				array_replace($activation, ['installation_public_key' => Protocol::encode(str_repeat("\1", 31))]),
			],
			'prueba de otro tamaño' => [
				'activate',
				array_replace($activation, ['proof' => Protocol::encode(str_repeat("\2", 63))]),
			],
			'request_id inválido' => ['activate', array_replace($activation, ['request_id' => 'no-es-uuid'])],
			'código de más de 256 caracteres' => [
				'activate',
				array_replace($activation, ['license_key' => str_repeat('K', 257)]),
			],
			'activar con activation_id' => ['activate', $activation + ['activation_id' => $existing['activation_id']]],
			'consultar con código' => ['refresh', $existing + ['license_key' => 'K7M-9R3X-6TVP-8CHD']],
			'desactivar sin activación' => ['deactivate', array_diff_key($existing, ['activation_id' => 1])],
			'ruta desconocida' => ['transfer', $activation],
		];
		foreach ($invalid as $label => [$action, $input]) {
			try {
				Protocol::input($action, $input);
				self::fail('Accepted: ' . $label);
			} catch (ApiProblem $error) {
				self::assertSame(['INVALID_REQUEST', 400], [$error->errorCode, $error->status], $label);
			}
		}
	}

	public function testErrorsUseTheCodesStatusesAndFormatOfTheContract(): void
	{
		// Tabla de la sección 8.7 del contrato.
		$table = [
			'INVALID_REQUEST' => 400,
			'INCOMPATIBLE_SCHEMA' => 400,
			'INVALID_PROOF' => 401,
			'REVOKED' => 403,
			'LICENSE_NOT_FOUND' => 404,
			'ACTIVATION_LIMIT' => 409,
			'TRANSFER_LIMIT' => 409,
			'REQUEST_CONFLICT' => 409,
			'RATE_LIMITED' => 429,
			'TEMPORARY_UNAVAILABLE' => 503,
		];
		self::assertSame($table, ApiProblem::STATUS);
		foreach ($table as $code => $status) {
			$problem = new ApiProblem($code);
			self::assertSame($status, $problem->status, $code);
			self::assertNotSame('', $problem->getMessage());
			$response = ApiResponse::error($problem, null);
			self::assertSame($status, $response->status);
			self::assertSame(
				'{"error":{"code":"' .
					$code .
					'","message":"' .
					$problem->getMessage() .
					'","request_id":null,"details":{}}}',
				$response->body,
			);
		}
		// Los errores de transporte conservan su HTTP estándar con el código INVALID_REQUEST.
		self::assertSame(413, (new ApiProblem('INVALID_REQUEST', 413))->status);
		self::assertSame(
			'{"error":{"code":"ACTIVATION_LIMIT","message":"La licencia ya está activa en otra computadora.",' .
				'"request_id":"44444444-4444-4444-8444-444444444444","details":{"transfer_available":false}}}',
			ApiResponse::error(
				new ApiProblem('ACTIVATION_LIMIT', null, ['transfer_available' => false]),
				'44444444-4444-4444-8444-444444444444',
			)->body,
		);
		$this->expectException(\LogicException::class);
		new ApiProblem('EXPIRED');
	}
}
