<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Config;
use AdelPos\License\Domain\LicenseContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reglas del contenido firmado (contrato V1.0, sección 4): lo que no las cumple no se firma. */
final class LicenseContentTest extends TestCase
{
	/** El ejemplo de la sección 4 del contrato, con identificadores de prueba. */
	private static function subscription(): array
	{
		return [
			'schema_version' => '1.0',
			'product_id' => 'adel_pos',
			'environment' => 'production',
			'license_id' => '11111111-1111-4111-8111-111111111111',
			'activation_id' => '33333333-3333-4333-8333-333333333333',
			'server_id' => '22222222-2222-4222-8222-222222222222',
			'installation_public_key' => str_repeat('A', 43),
			'licensee_name' => 'Restaurante de Pruebas, S.A. de C.V.',
			'license_type' => 'subscription',
			'billing_period' => 'monthly',
			'license_status' => 'active',
			'status_reason' => null,
			'license_revision' => 7,
			'issued_at' => '2026-11-16T06:30:00.000000Z',
			'period_starts_at' => '2026-11-16T06:30:00.000000Z',
			'expires_at' => '2026-12-16T06:30:00.000000Z',
			'grace_days' => 5,
			'updates_until' => '2026-12-16T06:30:00.000000Z',
			'plan' => ['plan_id' => 'elemental', 'name' => 'Plan elemental', 'custom' => false],
			'limits' => ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => 3],
			'features' => new \stdClass(),
		];
	}

	private static function perpetual(): array
	{
		return array_replace(self::subscription(), [
			'license_type' => 'perpetual',
			'billing_period' => null,
			'expires_at' => null,
			'grace_days' => 0,
			'updates_until' => '2027-05-16T06:30:00.000000Z',
		]);
	}

	private static function trial(): array
	{
		return array_replace(self::subscription(), [
			'license_type' => 'trial',
			'billing_period' => null,
			'expires_at' => '2026-12-01T06:30:00.000000Z',
			'grace_days' => 0,
			'updates_until' => '2026-12-01T06:30:00.000000Z',
		]);
	}

	public function testTheContractExampleAndEveryLicenseTypeAreValid(): void
	{
		$this->expectNotToPerformAssertions();
		LicenseContent::assertValid(self::subscription());
		LicenseContent::assertValid(self::perpetual());
		LicenseContent::assertValid(self::trial());
		foreach (['quarterly', 'semiannual', 'annual'] as $period) {
			LicenseContent::assertValid(array_replace(self::subscription(), ['billing_period' => $period]));
		}
		// Perpetua sin actualizaciones: terminan en la activación misma.
		LicenseContent::assertValid(
			array_replace(self::perpetual(), ['updates_until' => '2026-11-16T06:30:00.000000Z']),
		);
		// Las tres revisiones de cierre.
		foreach (LicenseContent::STATUS_REASONS as $reason) {
			LicenseContent::assertValid(
				array_replace(self::subscription(), ['license_status' => 'revoked', 'status_reason' => $reason]),
			);
		}
		// Entorno de pruebas, licencia personalizada con cupos en los extremos y módulos habilitados.
		LicenseContent::assertValid(
			array_replace(self::subscription(), [
				'environment' => 'test',
				'plan' => ['plan_id' => 'basico', 'name' => 'Plan básico', 'custom' => true],
				'limits' => ['cash_registers' => 0, 'order_takers' => 999, 'kitchen_displays' => 1],
				'features' => (object) ['modulo_uno' => true, 'ab' => true],
			]),
		);
	}

	public function testFieldsAreExactlyTheContractOnesInTheContractOrder(): void
	{
		self::assertSame(LicenseContent::FIELDS, array_keys(self::subscription()));
		self::assertCount(21, LicenseContent::FIELDS);
		self::assertSame(LicenseContent::ENVIRONMENTS, Config::LICENSE_ENVIRONMENTS);
		$content = self::subscription();
		$missing = $content;
		unset($missing['status_reason']);
		$reordered = ['product_id' => $content['product_id']] + $content;
		foreach ([$missing, $content + ['extra' => 1], $reordered, []] as $invalid) {
			try {
				LicenseContent::assertValid($invalid);
				self::fail('Accepted a different set or order of fields');
			} catch (\UnexpectedValueException $error) {
				self::assertSame('El contenido de la licencia no cumple el contrato: campos.', $error->getMessage());
			}
		}
	}

	/** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
	public static function invalidContents(): iterable
	{
		$cases = [
			['subscription', ['schema_version' => '1.1'], 'schema_version'],
			['subscription', ['schema_version' => 1.0], 'schema_version'],
			['subscription', ['product_id' => 'producto_prueba'], 'product_id'],
			['subscription', ['environment' => 'testing'], 'environment'],
			['subscription', ['environment' => 'development'], 'environment'],
			['subscription', ['license_id' => '11111111-1111-4111-8111-11111111111'], 'license_id'],
			['subscription', ['license_id' => strtoupper('aaaaaaaa-1111-4111-8111-111111111111')], 'license_id'],
			['subscription', ['activation_id' => null], 'activation_id'],
			// server_id: UUID versión 4, en minúsculas.
			['subscription', ['server_id' => '22222222-2222-1222-8222-222222222222'], 'server_id'],
			['subscription', ['server_id' => '22222222-2222-4222-7222-222222222222'], 'server_id'],
			['subscription', ['server_id' => 'AAAAAAAA-2222-4222-8222-222222222222'], 'server_id'],
			['subscription', ['installation_public_key' => str_repeat('A', 42)], 'installation_public_key'],
			['subscription', ['installation_public_key' => str_repeat('A', 44)], 'installation_public_key'],
			['subscription', ['installation_public_key' => str_repeat('A', 42) . '='], 'installation_public_key'],
			// 43 caracteres válidos, pero codificación no canónica de 32 bytes.
			['subscription', ['installation_public_key' => str_repeat('A', 42) . 'B'], 'installation_public_key'],
			['subscription', ['licensee_name' => ''], 'licensee_name'],
			['subscription', ['licensee_name' => '   '], 'licensee_name'],
			['subscription', ['licensee_name' => "Nombre\x01"], 'licensee_name'],
			['subscription', ['licensee_name' => str_repeat('ñ', 191)], 'licensee_name'],
			['subscription', ['licensee_name' => null], 'licensee_name'],
			['subscription', ['license_type' => 'demo'], 'license_type'],
			['subscription', ['billing_period' => null], 'billing_period'],
			['subscription', ['billing_period' => 'weekly'], 'billing_period'],
			['perpetual', ['billing_period' => 'monthly'], 'billing_period'],
			['trial', ['billing_period' => 'monthly'], 'billing_period'],
			['subscription', ['license_status' => 'expired'], 'license_status'],
			['subscription', ['license_status' => 'grace'], 'license_status'],
			['subscription', ['status_reason' => 'deactivated'], 'status_reason'],
			['subscription', ['license_status' => 'revoked'], 'status_reason'],
			['subscription', ['license_status' => 'revoked', 'status_reason' => 'expired'], 'status_reason'],
			['subscription', ['license_revision' => 0], 'license_revision'],
			['subscription', ['license_revision' => '7'], 'license_revision'],
			['subscription', ['license_revision' => 7.0], 'license_revision'],
			// Instantes: UTC, con seis decimales y terminados en «Z».
			['subscription', ['issued_at' => '2026-11-16T06:30:00Z'], 'issued_at'],
			['subscription', ['issued_at' => '2026-11-16T06:30:00.000000+00:00'], 'issued_at'],
			['subscription', ['issued_at' => '2026-11-16 06:30:00.000000Z'], 'issued_at'],
			['subscription', ['issued_at' => '2026-02-30T06:30:00.000000Z'], 'issued_at'],
			['subscription', ['issued_at' => '2026-11-16T24:00:00.000000Z'], 'issued_at'],
			['subscription', ['period_starts_at' => null], 'period_starts_at'],
			['subscription', ['updates_until' => null], 'updates_until'],
			['subscription', ['expires_at' => null], 'expires_at'],
			['trial', ['expires_at' => null], 'expires_at'],
			['perpetual', ['expires_at' => '2027-05-16T06:30:00.000000Z'], 'expires_at'],
			// El vencimiento es posterior al inicio del periodo.
			['subscription', ['expires_at' => '2026-11-16T06:30:00.000000Z'], 'expires_at'],
			['subscription', ['expires_at' => '2026-11-01T06:30:00.000000Z'], 'expires_at'],
			// Suscripción y prueba: las actualizaciones terminan con la vigencia.
			['subscription', ['updates_until' => '2027-01-16T06:30:00.000000Z'], 'updates_until'],
			['trial', ['updates_until' => '2026-12-16T06:30:00.000000Z'], 'updates_until'],
			['perpetual', ['updates_until' => '2026-11-16T06:29:59.999999Z'], 'updates_until'],
			['subscription', ['grace_days' => 15], 'grace_days'],
			['subscription', ['grace_days' => 0], 'grace_days'],
			['subscription', ['grace_days' => '5'], 'grace_days'],
			['perpetual', ['grace_days' => 5], 'grace_days'],
			['trial', ['grace_days' => 5], 'grace_days'],
			['subscription', ['plan' => 'elemental'], 'plan'],
			['subscription', ['plan' => ['plan_id' => 'elemental', 'name' => 'Plan elemental']], 'plan'],
			[
				'subscription',
				['plan' => ['name' => 'Plan elemental', 'plan_id' => 'elemental', 'custom' => false]],
				'plan',
			],
			[
				'subscription',
				['plan' => ['plan_id' => 'Elemental', 'name' => 'Plan elemental', 'custom' => false]],
				'plan.plan_id',
			],
			['subscription', ['plan' => ['plan_id' => 'elemental', 'name' => '', 'custom' => false]], 'plan.name'],
			[
				'subscription',
				['plan' => ['plan_id' => 'elemental', 'name' => 'Plan elemental', 'custom' => 0]],
				'plan.custom',
			],
			['subscription', ['limits' => ['cash_registers' => 3, 'order_takers' => 3]], 'limits'],
			[
				'subscription',
				['limits' => ['order_takers' => 3, 'cash_registers' => 3, 'kitchen_displays' => 3]],
				'limits',
			],
			[
				'subscription',
				['limits' => ['cash_registers' => -1, 'order_takers' => 3, 'kitchen_displays' => 3]],
				'limits.cash_registers',
			],
			[
				'subscription',
				['limits' => ['cash_registers' => 3, 'order_takers' => 1000, 'kitchen_displays' => 3]],
				'limits.order_takers',
			],
			// En V1 no existe «sin límite».
			[
				'subscription',
				['limits' => ['cash_registers' => 3, 'order_takers' => 3, 'kitchen_displays' => null]],
				'limits.kitchen_displays',
			],
			[
				'subscription',
				['limits' => ['cash_registers' => '3', 'order_takers' => 3, 'kitchen_displays' => 3]],
				'limits.cash_registers',
			],
			// «features» es un objeto con «clave → true»: nunca una lista ni un valor falso.
			['subscription', ['features' => []], 'features'],
			['subscription', ['features' => ['modulo_uno' => true]], 'features'],
			['subscription', ['features' => (object) ['modulo_uno' => false]], 'features'],
			['subscription', ['features' => (object) ['modulo_uno' => 1]], 'features'],
			['subscription', ['features' => (object) ['Modulo' => true]], 'features'],
			['subscription', ['features' => (object) ['m' => true]], 'features'],
		];
		foreach ($cases as $index => [$base, $changes, $field]) {
			yield $index . ' ' . $field => [$base, $field, $changes, $field];
		}
	}

	#[DataProvider('invalidContents')]
	public function testAnyRuleBrokenNamesItsFieldAndIsNotAccepted(
		string $base,
		string $label,
		array $changes,
		string $field,
	): void {
		$content = array_replace(self::$base(), $changes);
		try {
			LicenseContent::assertValid($content);
			self::fail('Accepted invalid content: ' . $label);
		} catch (\UnexpectedValueException $error) {
			self::assertSame(
				'El contenido de la licencia no cumple el contrato: ' . $field . '.',
				$error->getMessage(),
			);
		}
	}

	public function testTheErrorNeverRepeatsTheValue(): void
	{
		try {
			LicenseContent::assertValid(array_replace(self::subscription(), ['licensee_name' => "Secreto\x00"]));
			self::fail('Accepted invalid content');
		} catch (\UnexpectedValueException $error) {
			self::assertStringNotContainsString('Secreto', $error->getMessage());
		}
	}
}
