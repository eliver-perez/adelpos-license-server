<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

// Utiliza únicamente la base aleatoria y el HTTPS local de las fixtures de pruebas.
$fixture = new Aibid\Tests\MySqlFixture();
$stack = null;
try {
    $stack = new Aibid\Tests\NativeStackFixture($fixture);
    $stack->app->auth->bootstrap('browser@example.test', 'Prueba visual', 'Fixture-Password-Only-2026!');
    echo 'Panel sintético listo: ' . $stack->origin . PHP_EOL;
    echo 'En otra terminal: TRUST_PANEL_ORIGIN=' . $stack->origin . ' node tests/trusted-devices-panel.cjs' . PHP_EOL;
    echo 'Pulsa Enter al terminar para cerrar el panel y eliminar sus datos.' . PHP_EOL;
    fgets(STDIN);
} finally {
    if ($stack !== null) { $stack->destroy(); }
    $fixture->destroy();
}
