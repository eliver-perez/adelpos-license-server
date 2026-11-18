<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La guía de despliegue (DEPLOY_UBUNTU_24_04.md; etapa 5, Fase 6) coincide con el repositorio: cada herramienta
 * y archivo que nombra existe, despliega etiquetas desde un clon, y sus rutas son las de los ejemplos de deploy/.
 */
final class DeployGuideTest extends TestCase
{
	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	private static function guide(): string
	{
		return (string) file_get_contents(self::root() . '/DEPLOY_UBUNTU_24_04.md');
	}

	public function testEveryToolAndDeployFileTheGuideNamesExists(): void
	{
		// Rutas del repositorio, no de sistema (/bin/sh o …/renewal-hooks/deploy/…).
		preg_match_all('~(?<![\w/!])(?:bin|deploy)/[A-Za-z0-9._-]+~', self::guide(), $matches);
		$missing = [];
		foreach (array_unique($matches[0]) as $path) {
			// El comando de verificación del despliegue llega en la Fase 7, que quita esta excepción.
			if ($path === 'bin/verify-deployment.php') {
				continue;
			}
			if (!is_file(self::root() . '/' . $path)) {
				$missing[] = $path;
			}
		}
		self::assertSame([], $missing);
		self::assertGreaterThanOrEqual(12, count(array_unique($matches[0])));
	}

	public function testTheGuideDeploysTagsFromAReadOnlyCloneWithoutDevelopmentDependencies(): void
	{
		$guide = self::guide();
		for ($section = 1; $section <= 16; ++$section) {
			self::assertMatchesRegularExpression('/^## ' . $section . '\. /m', $guide, 'Sección ' . $section);
		}
		self::assertStringContainsString('git clone git@github-adelpos-license:', $guide);
		self::assertStringContainsString('checkout --detach v1.0.0', $guide);
		self::assertDoesNotMatchRegularExpression('/checkout\s+main|pull\s+origin\s+main/', $guide);
		// Cada instalación de dependencias (la primera y las de cada actualización) es sin las de desarrollo.
		preg_match_all('/composer install[^\n]*/', $guide, $installs);
		self::assertCount(3, $installs[0]);
		foreach ($installs[0] as $install) {
			self::assertStringContainsString('--no-dev', $install);
		}
		self::assertStringNotContainsString('npm ', $guide);
		// Ni el paquete por versión ni el enlace «current» del despliegue anterior.
		self::assertStringNotContainsString('/srv/adelpos-license/current', $guide);
		self::assertStringNotContainsString('releases/', $guide);
		// Ninguna contraseña escrita: MySQL las genera.
		self::assertDoesNotMatchRegularExpression("/IDENTIFIED BY '/", $guide);
		self::assertSame(3, substr_count($guide, 'IDENTIFIED BY RANDOM PASSWORD'));
	}

	public function testThePathsOfTheGuideAreThoseOfTheDeployExamples(): void
	{
		$guide = self::guide();
		$nginx = (string) file_get_contents(self::root() . '/deploy/nginx.conf.example');
		$pool = (string) file_get_contents(self::root() . '/deploy/php-fpm-pool.conf.example');
		$task = (string) file_get_contents(self::root() . '/deploy/adelpos-license-maintenance.sh');
		foreach (
			[
				'/srv/adelpos-license/app' => [$nginx, $task],
				'/srv/adelpos-license/shared/config.php' => [$pool, $task],
				'/srv/adelpos-license/shared/maintenance.flag' => [$nginx],
				'/run/php/adelpos-license.sock' => [$nginx, $pool],
				'/srv/adelpos-license/backup/recovery.key' => [$task],
				'/var/www/letsencrypt' => [$nginx],
				'/etc/letsencrypt/live/licensing.sicrat.com/' => [$nginx],
			]
			as $path => $files
		) {
			self::assertStringContainsString($path, $guide, $path);
			foreach ($files as $file) {
				self::assertStringContainsString($path, $file, $path);
			}
		}
		// La tarea diaria entra al clon y usa la versión de PHP del VPS.
		self::assertStringContainsString('cd /srv/adelpos-license/app', $task);
		self::assertSame(0, preg_match('/^php bin\//m', $task));
		self::assertSame(4, preg_match_all('/^php8\.3 bin\//m', $task));
	}
}
