<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use AdelPos\License\Domain\Uuid;

/**
 * Pruebas de concurrencia reales: varios procesos con su propia conexión intentan la misma
 * operación a la vez. La prueba retiene la fila de la licencia, espera a que todos los procesos
 * queden bloqueados en la base y solo entonces la suelta.
 */
final class Concurrency
{
	/**
	 * @param list<array{service: string, method: string, operation: string, arguments: list<mixed>}> $jobs
	 * @return list<array> Lo que escribió cada proceso: «result», o «status» y «message» si se rechazó.
	 */
	public static function race(
		MySqlFixture $fixture,
		string $actor,
		string $licenseId,
		string $now,
		array $jobs,
	): array {
		$workers = [];
		$files = [];
		$owner = $fixture->owner;
		$owner->pdo->beginTransaction();
		$owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [Uuid::bytes($licenseId)]);
		try {
			foreach ($jobs as $job) {
				$file = tempnam(sys_get_temp_dir(), 'adelpos-license-race-');
				chmod($file, 0600);
				file_put_contents(
					$file,
					json_encode(
						['config' => $fixture->values, 'actor' => $actor, 'now' => $now] + $job,
						JSON_THROW_ON_ERROR,
					),
				);
				$files[] = $file;
				$process = proc_open(
					[PHP_BINARY, __DIR__ . '/commerce-worker.php', $file],
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
				if ($waiting >= count($jobs)) {
					break;
				}
				usleep(10000);
			} while (microtime(true) < $deadline);
			if ($waiting < count($jobs)) {
				throw new \RuntimeException('Every worker must reach the database lock before it is released.');
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
