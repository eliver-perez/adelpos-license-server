<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Infrastructure\RuntimeGrants;
use PHPUnit\Framework\TestCase;

/**
 * Los permisos de la cuenta de la aplicación (etapa 5, Fase 7): la lista que aplica bin/grants.php y su comparación
 * con SHOW GRANTS, que ve cada privilegio y cada columna de más o de menos.
 */
final class RuntimeGrantsTest extends TestCase
{
	private const ACTIVATIONS =
		'GRANT SELECT, INSERT, UPDATE (`app_version`, `current_revision_id`, `ended_at`, `state`) ' .
		'ON `db`.`activations` TO `app`@`localhost`';

	public function testGrantsAreComparedPrivilegeByPrivilegeAndColumnByColumn(): void
	{
		// Una cuenta con exactamente lo previsto no tiene diferencias.
		$rows = ['GRANT USAGE ON *.* TO `app`@`localhost`'];
		foreach (RuntimeGrants::statements('db', 'app') as $statement) {
			preg_match('/\AGRANT (.+) ON `db`\.`(\w+)` TO /', $statement, $match);
			$rows[] =
				'GRANT ' . str_replace(',', ', ', $match[1]) . ' ON `db`.`' . $match[2] . '` TO `app`@`localhost`';
		}
		self::assertSame([], RuntimeGrants::differences($rows, 'db'));
		// MySQL escribe las columnas en otro orden y entre comillas invertidas: es lo mismo.
		$rows = ['GRANT USAGE ON *.* TO `app`@`localhost`', self::ACTIVATIONS];
		$problems = RuntimeGrants::differences($rows, 'db');
		self::assertSame([], preg_grep('/\Aactivations:/', $problems));
		self::assertContains('audit_events: falta INSERT', $problems);
		self::assertContains('audit_events: falta SELECT', $problems);
		// Una columna de más, un rol, otra base, una tabla sin permisos previstos y permisos globales.
		$rows[1] = str_replace('`state`)', '`state`, `license_id`)', self::ACTIVATIONS);
		$rows[] = 'GRANT `administrador`@`%` TO `app`@`localhost`';
		$rows[] = 'GRANT SELECT ON `otra`.`tabla` TO `app`@`localhost`';
		$rows[] = 'GRANT SELECT ON `db`.`tabla_nueva` TO `app`@`localhost`';
		$rows[] = 'GRANT RELOAD, BACKUP_ADMIN ON *.* TO `app`@`localhost`';
		$rows[] = 'GRANT DELETE ON `db`.* TO `app`@`localhost`';
		$problems = RuntimeGrants::differences($rows, 'db');
		self::assertContains(
			'activations: sobra UPDATE(app_version,current_revision_id,ended_at,license_id,state)',
			$problems,
		);
		self::assertContains('activations: falta UPDATE(app_version,current_revision_id,ended_at,state)', $problems);
		self::assertContains(
			'tiene un rol o un permiso no previsto: GRANT `administrador`@`%` TO `app`@`localhost`',
			$problems,
		);
		self::assertContains('tiene SELECT sobre otra.tabla', $problems);
		self::assertContains('tabla_nueva: tiene SELECT y no debería tener ninguno', $problems);
		self::assertContains('tiene RELOAD sobre *.*', $problems);
		self::assertContains('tiene BACKUP_ADMIN sobre *.*', $problems);
		self::assertContains('tiene DELETE sobre db.*', $problems);
	}

	public function testHistoryTablesCannotBeChangedOrDeleted(): void
	{
		foreach (
			[
				'audit_events',
				'license_changes',
				'license_payments',
				'license_revisions',
				'offline_requests',
				'offline_decisions',
				'license_administrative_changes',
				'license_documents',
			]
			as $table
		) {
			self::assertSame('SELECT,INSERT', RuntimeGrants::PRIVILEGES[$table], $table);
		}
		self::assertSame(
			"GRANT SELECT,INSERT ON `adelpos_license`.`audit_events` TO 'adelpos_license_app'@'localhost';",
			RuntimeGrants::statements('adelpos_license', 'adelpos_license_app')[
				array_search('audit_events', array_keys(RuntimeGrants::PRIVILEGES), true)
			],
		);
	}
}
