<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$job = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$config = new AdelPos\License\Config($job['config'], false);
$app = new AdelPos\License\App(
	$config,
	AdelPos\License\Infrastructure\Database::connect($config),
	new AdelPos\License\Tests\FrozenClock(new DateTimeImmutable($job['now'] ?? '2026-09-25T12:00:00Z')),
);
// La acción de la API; por omisión, activar.
$response = $app->activations->execute($job['action'] ?? 'activate', $job['input']);
echo AdelPos\License\Domain\Protocol::json(['status' => $response->status, 'body' => $response->body]);
