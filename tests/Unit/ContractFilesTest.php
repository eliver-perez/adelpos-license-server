<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{
	ApiProblem,
	CalendarPeriod,
	Credit,
	Jws,
	LicenseContent,
	LicenseTerms,
	Money,
	OfflineRequest,
	Protocol,
};
use AdelPos\License\Http\ApiKernel;
use AdelPos\License\Infrastructure\SigningKeys;
use AdelPos\License\Tests\ContractSchemas;
use PHPUnit\Framework\TestCase;

/**
 * Los archivos formales del contrato (contracts/v1/) y el código del servidor dicen lo mismo:
 * el esquema de la licencia, el OpenAPI, los archivos de prueba y el verificador de referencia.
 */
final class ContractFilesTest extends TestCase
{
	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	/** Contenido de una licencia como lo arma el servidor: «features» es un objeto. */
	private static function content(string $json): array
	{
		$content = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (is_array($content['features'] ?? null)) {
			$content['features'] = (object) $content['features'];
		}
		return $content;
	}

	private static function contentOf(string $jws): string
	{
		return sodium_base642bin(explode('.', $jws)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	}

	private static function accepts(array $content): bool
	{
		try {
			LicenseContent::assertValid($content);
			return true;
		} catch (\UnexpectedValueException) {
			return false;
		}
	}

	/** @return array{0: int, 1: string} Código de salida y salida completa de un comando. */
	private static function command(array $command, array $environment = []): array
	{
		$process = proc_open(
			$command,
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			self::root(),
			$environment + getenv(),
		);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}

	public function testFixturesAreExactlyWhatTheirGeneratorWrites(): void
	{
		[$status, $output] = self::command([PHP_BINARY, 'contracts/v1/generate-fixtures.php', '--check']);
		self::assertSame(0, $status, $output);
		self::assertStringStartsWith('Comprobado: 17 licencias, 17 casos negativos', $output);
		// La carpeta del contrato solo tiene lo que la especificación enumera.
		$files = array_map('basename', glob(ContractSchemas::directory() . '/*'));
		sort($files);
		self::assertSame(
			['README.md', 'fixtures', 'generate-fixtures.php', 'license.schema.json', 'openapi.yaml', 'verify'],
			$files,
		);
	}

	public function testEveryFixtureKeyIsRejectedByTheServerInProduction(): void
	{
		$vectors = ContractSchemas::vectors();
		$names = array_column([...$vectors['signing_keys'], ...$vectors['installations']], 'name');
		sort($names);
		$known = SigningKeys::FIXTURE_SEEDS;
		sort($known);
		self::assertSame($known, $names);
		foreach ([...$vectors['signing_keys'], ...$vectors['installations']] as $key) {
			$name = $key['name'];
			// La semilla es pública y sale del nombre: es lo que el servidor reconoce y rechaza.
			self::assertSame(hash('sha256', 'ADELPOS LICENSE PUBLIC TEST ONLY V1 ' . $name), $key['seed_hex']);
		}
		self::assertSame(['test', 'test', 'production'], array_column($vectors['signing_keys'], 'environment'));
		// El nombre de una llave de firma es su «kid».
		self::assertSame(array_column($vectors['signing_keys'], 'kid'), array_column($vectors['signing_keys'], 'name'));
	}

	public function testValidLicensesComplyWithTheSchemaTheValidatorAndThePublisher(): void
	{
		$vectors = ContractSchemas::vectors();
		$keys = array_column($vectors['signing_keys'], null, 'kid');
		self::assertSame(
			[
				'perpetual-0-months',
				'perpetual-6-months',
				'perpetual-12-months',
				'subscription-monthly',
				'subscription-annual',
				'trial-15-days',
				'custom',
				'closing-deactivated',
				'closing-transferred',
				'closing-revoked',
				'renewed-subscription',
				'trial-converted-to-subscription',
				'trial-converted-to-perpetual',
				'perpetual-with-maintenance',
				'plan-upgrade',
				'scheduled-reduction-applied',
				'rotated-key',
			],
			array_column($vectors['licenses'], 'name'),
		);
		foreach ($vectors['licenses'] as $license) {
			$name = $license['name'];
			// 1. Cumple el esquema publicado.
			self::assertNull(ContractSchemas::licenseErrors($license['content_json']), $name);
			// 2. El validador del servidor también lo acepta, con sus campos en el orden del contrato.
			$content = self::content($license['content_json']);
			self::assertSame(LicenseContent::FIELDS, array_keys($content), $name);
			self::assertTrue(self::accepts($content), $name);
			self::assertSame($keys[$license['kid']]['environment'], $content['environment'], $name);
			// 3. El servidor, con esa llave, firma exactamente los mismos bytes.
			$pair = sodium_crypto_sign_seed_keypair(hex2bin($keys[$license['kid']]['seed_hex']));
			self::assertSame(
				$license['jws'],
				Jws::sign($content, $license['kid'], sodium_crypto_sign_secretkey($pair)),
				$name,
			);
			self::assertSame(
				'{"alg":"EdDSA","kid":"' . $license['kid'] . '","typ":"adelpos-lic+jws"}',
				$license['header_json'],
			);
			self::assertSame($license['content_json'], self::contentOf($license['jws']), $name);
			self::assertSame(hash('sha256', $license['jws']), $license['sha256'], $name);
			// 4. El archivo .lic es ese mismo JWS, sin nada más.
			self::assertSame(
				$license['jws'],
				file_get_contents(ContractSchemas::directory() . '/fixtures/licenses/' . $name . '.lic'),
				$name,
			);
		}
		// Los tres motivos de cierre están representados.
		$reasons = array_filter(array_map(fn(array $l) => $l['content']['status_reason'], $vectors['licenses']));
		sort($reasons);
		self::assertSame(['deactivated', 'revoked', 'transferred'], array_values($reasons));
	}

	public function testNegativeLicensesFailTheSchemaAndTheValidatorExactlyWhenExpected(): void
	{
		$cases = ContractSchemas::vectors()['negative_licenses'];
		// Los casos que pide la sección 13 del contrato.
		foreach (
			[
				'altered-signature',
				'unknown-kid',
				'test-license-on-production-installation',
				'test-key-under-production-kid',
				'environment-does-not-match-kid',
				'other-installation',
				'other-product',
				'missing-field',
				'schema-version-2',
				'wrong-types',
			]
			as $required
		) {
			self::assertContains($required, array_column($cases, 'name'));
		}
		foreach ($cases as $case) {
			$json = self::contentOf($case['jws']);
			$schemaValid = ContractSchemas::licenseErrors($json) === null;
			self::assertSame($case['schema_valid'], $schemaValid, $case['name']);
			// El validador del servidor coincide con el esquema en todos estos casos.
			self::assertSame($schemaValid, self::accepts(self::content($json)), $case['name']);
		}
	}

	public function testEveryRuleOfTheServerValidatorIsInTheSchemaExceptTheOnesASchemaCannotExpress(): void
	{
		$inSchema = 0;
		$codeOnly = [];
		foreach (LicenseContentTest::invalidCases() as $index => $case) {
			[$base, $changes, $field] = $case;
			$content = array_replace(LicenseContentTest::$base(), $changes);
			$rejected = ContractSchemas::licenseErrors(Protocol::json($content)) !== null;
			if ($case[3] ?? true) {
				self::assertTrue($rejected, 'El esquema acepta el caso ' . $index . ' (' . $field . ')');
				$inSchema++;
			} else {
				// Reglas que solo comprueba el servidor: el esquema las deja pasar.
				self::assertFalse($rejected, 'El esquema ya rechaza el caso ' . $index . ' (' . $field . ')');
				$codeOnly[] = $field;
			}
		}
		self::assertSame(60, $inSchema);
		// Fechas cruzadas, calendario real, seis decimales exactos, orden de las claves y la
		// diferencia entre 7 y 7.0 o entre lista y objeto, que en JSON no existe.
		sort($codeOnly);
		self::assertSame(
			[
				'expires_at',
				'expires_at',
				'features',
				'issued_at',
				'issued_at',
				'license_revision',
				'limits',
				'plan',
				'updates_until',
				'updates_until',
				'updates_until',
			],
			$codeOnly,
		);
	}

	public function testDateCasesAreReproducedByTheDateEngine(): void
	{
		$dates = ContractSchemas::vectors()['dates'];
		self::assertCount(12, $dates['add_months']);
		foreach ($dates['add_months'] as $case) {
			$start = new \DateTimeImmutable($case['start']);
			$anchor = CalendarPeriod::anchor($start, $case['time_zone']);
			$result = CalendarPeriod::addMonths(
				$start,
				$case['months'],
				$case['time_zone'],
				$anchor['day'],
				$anchor['time'],
			);
			self::assertSame($case['expected'], CalendarPeriod::utc($result), $case['name']);
		}
		self::assertCount(2, $dates['add_days']);
		foreach ($dates['add_days'] as $case) {
			$result = CalendarPeriod::addDays(
				new \DateTimeImmutable($case['start']),
				$case['days'],
				$case['time_zone'],
			);
			self::assertSame($case['expected'], CalendarPeriod::utc($result), $case['name']);
		}
		self::assertCount(10, $dates['first_period']);
		foreach ($dates['first_period'] as $case) {
			$period = CalendarPeriod::firstPeriod(
				$case['terms'],
				new \DateTimeImmutable($case['start']),
				$case['time_zone'],
			);
			self::assertSame(
				$case['expected'],
				[
					'period_starts_at' => CalendarPeriod::utc($period['period_starts_at']),
					'expires_at' => $period['expires_at'] === null ? null : CalendarPeriod::utc($period['expires_at']),
					'updates_until' => CalendarPeriod::utc($period['updates_until']),
					'anchor_day' => $period['anchor_day'],
					'anchor_local_time' => $period['anchor_local_time'],
				],
				$case['name'],
			);
		}
	}

	/** Casos del ciclo comercial (etapa 3): renovación, conversión de una prueba y mantenimiento. */
	public function testCommercialDateCasesAreReproducedByTheDateEngine(): void
	{
		$dates = ContractSchemas::vectors()['dates'];
		$utc = fn(?\DateTimeImmutable $instant): ?string => $instant === null ? null : CalendarPeriod::utc($instant);
		self::assertCount(9, $dates['renewal']);
		foreach ($dates['renewal'] as $case) {
			$renewal = CalendarPeriod::renewal(
				new \DateTimeImmutable($case['expires_at']),
				$case['grace_days'],
				new \DateTimeImmutable($case['paid_at']),
				LicenseTerms::BILLING_MONTHS[$case['billing_period']],
				$case['time_zone'],
				$case['anchor_day'],
				$case['anchor_local_time'],
			);
			self::assertSame(
				$case['expected'],
				[
					'period_starts_at' => $utc($renewal['period_starts_at']),
					'expires_at' => $utc($renewal['expires_at']),
					'anchor_day' => $renewal['anchor_day'],
					'anchor_local_time' => $renewal['anchor_local_time'],
				],
				$case['name'],
			);
		}
		self::assertCount(4, $dates['conversion']);
		foreach ($dates['conversion'] as $case) {
			$period = CalendarPeriod::firstPeriod(
				$case['terms'],
				new \DateTimeImmutable($case['paid_at']),
				$case['time_zone'],
			);
			self::assertSame(
				$case['expected'],
				[
					'period_starts_at' => $utc($period['period_starts_at']),
					'expires_at' => $utc($period['expires_at']),
					'updates_until' => $utc($period['updates_until']),
					'anchor_day' => $period['anchor_day'],
					'anchor_local_time' => $period['anchor_local_time'],
				],
				$case['name'],
			);
		}
		self::assertCount(3, $dates['maintenance']);
		foreach ($dates['maintenance'] as $case) {
			$maintenance = CalendarPeriod::maintenance(
				new \DateTimeImmutable($case['updates_until']),
				new \DateTimeImmutable($case['paid_at']),
				$case['time_zone'],
				$case['anchor_day'],
				$case['anchor_local_time'],
			);
			self::assertSame(
				$case['expected'],
				[
					'updates_from' => $utc($maintenance['updates_from']),
					'updates_until' => $utc($maintenance['updates_until']),
					'anchor_day' => $maintenance['anchor_day'],
					'anchor_local_time' => $maintenance['anchor_local_time'],
				],
				$case['name'],
			);
		}
	}

	public function testCreditCasesAreReproducedToTheCent(): void
	{
		$credits = ContractSchemas::vectors()['credits'];
		self::assertCount(8, $credits);
		foreach ($credits as $case) {
			$result = Credit::remaining(
				$case['paid_cents'],
				new \DateTimeImmutable($case['period_starts_at']),
				new \DateTimeImmutable($case['expires_at']),
				new \DateTimeImmutable($case['paid_at']),
			);
			self::assertSame(Money::toSql($case['paid_cents']), $case['paid_mxn'], $case['name']);
			self::assertSame(
				$case['expected'],
				[
					'remaining_seconds' => $result['remaining_seconds'],
					'period_seconds' => $result['period_seconds'],
					'credit_cents' => $result['credit'],
					'credit_mxn' => Money::toSql($result['credit']),
				],
				$case['name'],
			);
		}
	}

	public function testProofsAndOfflineRequestsAreReproducedByTheProtocol(): void
	{
		$vectors = ContractSchemas::vectors();
		$installations = array_column($vectors['installations'], null, 'name');
		// Las cuatro acciones. «transfer» es solo un vector criptográfico hasta la etapa 4.
		self::assertSame(['activate', 'transfer', 'refresh', 'deactivate'], array_column($vectors['proofs'], 'action'));
		foreach ($vectors['proofs'] as $proof) {
			self::assertSame(
				$proof['message'],
				Protocol::proofMessage($proof['action'], $proof, $proof['nonce']),
				$proof['action'],
			);
			self::assertTrue(
				sodium_crypto_sign_verify_detached(
					Protocol::decode($proof['proof'], 64),
					$proof['message'],
					Protocol::decode($installations[$proof['installation']]['public_key'], 32),
				),
				$proof['action'],
			);
		}
		self::assertSame(['activate', 'renew', 'deactivate'], array_column($vectors['offline_requests'], 'action'));
		foreach ($vectors['offline_requests'] as $request) {
			$verified = OfflineRequest::verify($request['envelope_json']);
			// El servidor conserva el contenido sin el código comercial.
			self::assertSame(array_diff_key($request['payload'], ['license_key' => true]), $verified['payload']);
			self::assertSame(
				$installations[$request['installation']]['public_key'],
				Protocol::encode($verified['public']),
			);
			self::assertSame(
				OfflineRequest::SIGNATURE_PREFIX . Protocol::encode($request['payload_json']),
				$request['message'],
			);
			self::assertSame(
				$request['envelope_json'],
				file_get_contents(
					ContractSchemas::directory() . '/fixtures/requests/' . $request['action'] . '.licreq',
				),
			);
		}
	}

	public function testOpenApiRoutesAreExactlyTheRoutesOfTheApi(): void
	{
		$openapi = ContractSchemas::openapi();
		self::assertSame('3.1.0', $openapi['openapi']);
		self::assertSame(array_keys(ApiKernel::ROUTES), array_keys($openapi['paths']));
		foreach ($openapi['paths'] as $path => $methods) {
			// Todas las rutas son POST y nada más.
			self::assertSame(['post'], array_keys($methods), $path);
		}
	}

	public function testOpenApiRequestsHaveExactlyTheFieldsOfTheProtocol(): void
	{
		$openapi = ContractSchemas::openapi();
		foreach (ApiKernel::ROUTES as $path => $action) {
			$reference = $openapi['paths'][$path]['post']['requestBody']['content']['application/json']['schema'];
			$schema = $openapi['components']['schemas'][basename($reference['$ref'])];
			$fields = Protocol::REQUEST_FIELDS[$action];
			self::assertSame($fields, $schema['required'], $path);
			self::assertSame($fields, array_keys($schema['properties']), $path);
			self::assertFalse($schema['additionalProperties'], $path);
		}
		self::assertSame(
			Protocol::CHALLENGE_ACTIONS,
			$openapi['components']['schemas']['ChallengeRequest']['properties']['action']['enum'],
		);
	}

	public function testOpenApiErrorsAreTheCodesAndStatusesOfTheContract(): void
	{
		$openapi = ContractSchemas::openapi();
		$codes = $openapi['components']['schemas']['ErrorCode'];
		self::assertSame(array_keys(ApiProblem::STATUS), $codes['enum']);
		self::assertSame(ApiProblem::STATUS, $codes['x-http-status']);
		// Cada ruta enumera los HTTP con los que puede responder.
		$expected = [
			'/v1/activations/challenge' => [200, 400, 413, 415, 429, 503],
			'/v1/activations' => [200, 400, 401, 403, 404, 409, 413, 415, 429, 503],
			'/v1/activations/refresh' => [200, 400, 401, 409, 413, 415, 429, 503],
			'/v1/activations/deactivate' => [200, 400, 401, 409, 413, 415, 429, 503],
		];
		foreach ($expected as $path => $statuses) {
			self::assertSame($statuses, array_keys($openapi['paths'][$path]['post']['responses']), $path);
		}
		// El formato de error que produce el servidor cumple el del OpenAPI, para todos los códigos.
		foreach (array_keys(ApiProblem::STATUS) as $code) {
			$details = $code === 'ACTIVATION_LIMIT' ? ['transfer_available' => false] : [];
			foreach ([null, '44444444-4444-4444-8444-444444444441'] as $requestId) {
				$body = \AdelPos\License\Domain\ApiResponse::error(new ApiProblem($code, null, $details), $requestId);
				self::assertNull(ContractSchemas::componentErrors('Error', $body->body), $code);
			}
		}
	}

	public function testOpenApiExamplesComplyWithTheirOwnSchemas(): void
	{
		$openapi = ContractSchemas::openapi();
		$checked = 0;
		$check = function (array $content, string $where) use (&$checked): void {
			$media = $content['application/json'];
			foreach ($media['examples'] ?? [] as $name => $example) {
				$json = json_encode($example['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
				// Un objeto vacío del YAML llega aquí como lista vacía: «details» siempre es objeto.
				$json = str_replace('"details":[]', '"details":{}', $json);
				$schema = basename($media['schema']['$ref']);
				self::assertNull(ContractSchemas::componentErrors($schema, $json), $where . ' · ' . $name);
				$checked++;
			}
		};
		foreach ($openapi['paths'] as $path => $methods) {
			$check($methods['post']['requestBody']['content'], $path . ' petición');
			foreach ($methods['post']['responses'] as $status => $response) {
				if (isset($response['content'])) {
					$check($response['content'], $path . ' ' . $status);
				}
			}
		}
		foreach ($openapi['components']['responses'] as $name => $response) {
			$check($response['content'], 'respuesta ' . $name);
		}
		self::assertSame(17, $checked);
		// El contenido de la licencia del OpenAPI es el esquema publicado.
		self::assertSame('license.schema.json', $openapi['components']['schemas']['LicenseContent']['$ref']);
		self::assertNull(
			ContractSchemas::componentErrors(
				'LicenseContent',
				ContractSchemas::vectors()['licenses'][0]['content_json'],
			),
		);
	}

	public function testGoReferenceVerifierRunsEveryFixtureCase(): void
	{
		[$found] = self::command(['go', 'version']);
		if ($found !== 0) {
			self::markTestSkipped('Requiere Go para ejecutar el verificador de referencia.');
		}
		// Sin red y sin dependencias externas: solo la biblioteca estándar de Go.
		$environment = ['GOFLAGS' => '-mod=mod', 'GOPROXY' => 'off', 'GOSUMDB' => 'off', 'GOTOOLCHAIN' => 'local'];
		[$status, $output] = self::command(
			['go', 'run', '-C', 'contracts/v1/verify', '.', '../fixtures/vectors.json'],
			$environment,
		);
		self::assertSame(0, $status, $output);
		self::assertStringContainsString(
			'OK: 17 licencias, 17 casos negativos, 7 casos de revisión, 13 estados temporales, ' .
				'4 pruebas de posesión y 3 solicitudes .licreq',
			$output,
		);
		// El verificador también pasa sus propias pruebas.
		[$status, $output] = self::command(['go', 'vet', '-C', 'contracts/v1/verify', './...'], $environment);
		self::assertSame(0, $status, $output);
		self::assertSame(
			"module adelpos.example/license-verify\n\ngo 1.22\n",
			file_get_contents(ContractSchemas::directory() . '/verify/go.mod'),
		);
	}
}
