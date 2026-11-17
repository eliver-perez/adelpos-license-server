<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

// Isolated, explicitly requested test data; never run against the application DB.
$fixture = new AdelPos\License\Tests\MySqlFixture();
$app = $fixture->app;
$keys = $fixture->signingKeys();
$keys->generate('panel-random-signing-key', 'license');
$keys->activate('panel-random-signing-key', 'Preparación aislada de prueba del panel');
// Precios sintéticos para comprobar que el formulario sugiere el monto de alta.
$fixture->owner->execute("UPDATE plans SET price_perpetual='15000.00',price_monthly='899.00' WHERE plan_id='premium'");
$adminId = $app->auth->bootstrap('admin@example.test', 'Administración de pruebas', 'Fixture-Password-Only-2026!');
$actor = new AdelPos\License\Application\Actor($adminId, 'superadmin', 'Administración de pruebas');
// Una perpetua, una suscripción mensual y una prueba, las tres activadas en una computadora sintética.
// commercial-panel.cjs recorre con ellas el ciclo comercial.
$types = ['perpetual', 'subscription', 'trial'];
foreach (['Restaurante del Norte', 'Cocina Regional', 'Café Central'] as $index => $name) {
	$customer = $app->catalog->saveCustomer($actor, AdelPos\License\Domain\Uuid::create(), null, [
		'name' => $name,
		'legal_name' => '',
		'contact_name' => 'Contacto de prueba',
		'email' => 'contacto' . $index . '@example.test',
		'phone' => '',
		'state' => 'active',
	]);
	$issued = $app->licenses->issue($actor, AdelPos\License\Domain\Uuid::create(), [
		'customer_id' => $customer['id'],
		'product_id' => 'adel_pos',
		...AdelPos\License\Tests\MySqlFixture::terms($types[$index]),
		'features' => ['prueba_b', 'prueba_c', 'prueba_d'],
		'reason' => 'Datos sintéticos para verificar el panel',
		'reference' => 'TEST-PANEL',
	]);
	(new AdelPos\License\Tests\Installation($app))->activate($issued['secret']);
}
// Precios del plan elemental para el ciclo comercial: se fijan después de emitir, porque las licencias de
// arriba se emiten con monto 0 y sin precio sugerido.
$fixture->owner->execute(
	"UPDATE plans SET price_quarterly='1500.00',price_maintenance='3000.00' WHERE plan_id='elemental'",
);
$viewer = $app->admins->create($actor, AdelPos\License\Domain\Uuid::create(), [
	'login' => 'viewer@example.test',
	'name' => 'Cuenta de consulta',
	'role' => 'viewer',
	'new_password' => 'Fixture-Viewer-Only-2026!',
]);
$fixture->owner->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [
	$app->crypto->encrypt('JBSWY3DPEHPK3PXP', 'mfa:' . $viewer['id']),
	AdelPos\License\Domain\Uuid::bytes($viewer['id']),
]);
$configPath = '/private/tmp/adelpos-license-panel-config.php';
$oldMask = umask(0077);
// A test preview must never inherit unrelated DB/runtime settings from a shell.
$contents = "<?php\n" . '$fixtureValues = ' . var_export($fixture->values, true) . ";\n";
$contents .=
	'foreach (array_keys($fixtureValues) as $fixtureName) { putenv($fixtureName); }' . "\nreturn \$fixtureValues;\n";
file_put_contents($configPath, $contents);
umask($oldMask);
echo 'Panel test fixture ready. Configuration: ' . $configPath . PHP_EOL;
echo 'Database: ' . $fixture->database . ' (synthetic test data only).' . PHP_EOL;
