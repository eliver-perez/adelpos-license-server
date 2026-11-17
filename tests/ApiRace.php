<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use AdelPos\License\Domain\{Protocol, Uuid};

/**
 * Peticiones simultáneas a la API en línea: cada una en su propio proceso, con su propia conexión. La
 * prueba retiene la fila de la licencia, espera a que todos los procesos queden bloqueados en la base
 * y solo entonces la suelta. Así compiten de verdad por la misma licencia.
 */
final class ApiRace
{
	/**
	 * @param list<array{action: string, input: array}> $requests
	 * @return list<array{status: int, body: string}> La respuesta de cada petición, en el mismo orden.
	 */
	public static function run(MySqlFixture $fixture, string $licenseId, string $now, array $requests): array
	{
		$workers = [];
		$files = [];
		$owner = $fixture->owner;
		$owner->pdo->beginTransaction();
		$owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [Uuid::bytes($licenseId)]);
		try {
			foreach ($requests as $request) {
				$file = tempnam(sys_get_temp_dir(), 'adelpos-license-api-race-');
				chmod($file, 0600);
				$files[] = $file;
				file_put_contents(
					$file,
					Protocol::json([
						'config' => $fixture->values,
						'now' => $now,
						'action' => $request['action'],
						'input' => $request['input'],
					]),
				);
				$process = proc_open(
					[PHP_BINARY, __DIR__ . '/activation-worker.php', $file],
					[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
					$pipes,
				);
				$workers[] = [$process, $pipes];
			}
			$deadline = microtime(true) + 10;
			do {
				$waiting = (int) $owner
					->execute(
						'SELECT COUNT(*)
							FROM performance_schema.data_lock_waits w
							JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
							WHERE l.OBJECT_SCHEMA=?',
						[$fixture->database],
					)
					->fetchColumn();
				if ($waiting >= count($requests)) {
					break;
				}
				usleep(10000);
			} while (microtime(true) < $deadline);
			if ($waiting < count($requests)) {
				throw new \RuntimeException('Every request must reach the database lock before it is released.');
			}
		} finally {
			$owner->pdo->commit();
		}
		$results = [];
		foreach ($workers as [$process, $pipes]) {
			$output = stream_get_contents($pipes[1]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			if (proc_close($process) !== 0) {
				throw new \RuntimeException('Worker failed: ' . $errors);
			}
			$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		}
		foreach ($files as $file) {
			unlink($file);
		}
		return $results;
	}
}
