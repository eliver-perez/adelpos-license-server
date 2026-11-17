<?php
declare(strict_types=1);
// Proceso separado para las pruebas de concurrencia del ciclo comercial: ejecuta una operación
// de CommercialService o PlanService con su propia conexión y escribe el resultado o el rechazo.
require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
	exit(2);
}
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$config = new AdelPos\License\Config($data['config'], false);
$clock = new AdelPos\License\Tests\FrozenClock(new DateTimeImmutable($data['now']));
$app = new AdelPos\License\App($config, AdelPos\License\Infrastructure\Database::connect($config), $clock);
$actor = new AdelPos\License\Application\Actor($data['actor'], 'superadmin');
try {
	$result = $app->{$data['service']}->{$data['method']}($actor, $data['operation'], ...$data['arguments']);
	echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
} catch (AdelPos\License\Domain\Problem $problem) {
	echo json_encode(['status' => $problem->status, 'message' => $problem->getMessage()], JSON_THROW_ON_ERROR);
}
