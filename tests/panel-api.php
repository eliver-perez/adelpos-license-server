<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use AdelPos\License\Application\Actor;
use AdelPos\License\Domain\Uuid;
use AdelPos\License\Tests\{Installation, MySqlFixture};

// Simula a AD-EL POS por la API en línea durante las pruebas del panel (etapa 4), sobre la base sintética
// que preparó tests/prepare-panel.php. Nunca se ejecuta contra la base de la aplicación.
//
//   «Taquería del Puerto»: una suscripción que se transfiere por autoservicio a otra computadora y, antes de
//   que la anterior confirme, una tercera lo intenta y recibe TRANSFER_LIMIT (alerta «transfer_blocked»).
//   «Mariscos del Golfo»: una perpetua que se transfiere dos veces en el mismo día; la anterior confirma la
//   primera al consultar (alerta «frequent_transfers»).
//
// Escribe en la salida los identificadores de las dos licencias, en JSON.
//
// Con «reauth-window», en cambio, vacía el contador de confirmaciones de identidad del panel de prueba: todo
// el recorrido de Playwright usa la misma cuenta, y sus confirmaciones agotan el límite de 10 cada 15 minutos.
$configPath = '/private/tmp/adelpos-license-panel-config.php';
if (!is_file($configPath)) {
	fwrite(STDERR, "Falta la configuración del panel de prueba.\n");
	exit(1);
}
$values = require $configPath;
$config = new AdelPos\License\Config($values, false);
$app = new AdelPos\License\App($config, AdelPos\License\Infrastructure\Database::connect($config));
if (($argv[1] ?? '') === 'reauth-window') {
	$app->db->execute("DELETE FROM rate_limit_buckets WHERE scope='reauth'");
	echo '{}' . PHP_EOL;
	exit(0);
}
$admin = $app->db->one("SELECT BIN_TO_UUID(id) AS id_text FROM admin_users WHERE login='admin@example.test'");
$actor = new Actor($admin['id_text'], 'superadmin', 'Administración de pruebas');
$issue = function (string $name, string $type) use ($app, $actor): array {
	$customer = $app->catalog->saveCustomer($actor, Uuid::create(), null, [
		'name' => $name,
		'legal_name' => '',
		'contact_name' => 'Contacto de prueba',
		'email' => '',
		'phone' => '',
		'state' => 'active',
	]);
	return $app->licenses->issue($actor, Uuid::create(), [
		'customer_id' => $customer['id'],
		'product_id' => 'adel_pos',
		...MySqlFixture::terms($type),
		'reason' => 'Datos sintéticos para verificar las transferencias del panel',
	]);
};
$failed = function (string $step, int $status, int $expected): void {
	if ($status !== $expected) {
		fwrite(STDERR, $step . ': se esperaba ' . $expected . ' y llegó ' . $status . "\n");
		exit(1);
	}
};

$blocked = $issue('Taquería del Puerto', 'subscription');
(new Installation($app))->activate($blocked['secret']);
$failed('transferencia', (new Installation($app))->transfer($blocked['secret'])['status'], 200);
$failed('intento bloqueado', (new Installation($app))->transfer($blocked['secret'])['status'], 409);

$frequent = $issue('Mariscos del Golfo', 'perpetual');
$first = new Installation($app);
$first->activate($frequent['secret']);
$failed('primera transferencia', (new Installation($app))->transfer($frequent['secret'])['status'], 200);
$first->refresh();
$failed('segunda transferencia', (new Installation($app))->transfer($frequent['secret'])['status'], 200);

echo json_encode(['blocked' => $blocked['id'], 'frequent' => $frequent['id']], JSON_THROW_ON_ERROR) . PHP_EOL;
