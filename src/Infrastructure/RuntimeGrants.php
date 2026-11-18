<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

/**
 * Permisos exactos de la cuenta de la aplicación, tabla por tabla. bin/grants.php los imprime para aplicarlos, y
 * bin/verify-deployment.php comprueba que la cuenta no tenga ni más ni menos (etapa 5, Fase 7). Las tablas de
 * historia (auditoría, pagos, revisiones firmadas, decisiones, constancias…) no tienen UPDATE ni DELETE.
 */
final class RuntimeGrants
{
	/** Tabla → privilegios; las columnas de un UPDATE limitado van entre paréntesis. */
	public const PRIVILEGES = [
		'schema_migrations' => 'SELECT',
		'security_state' => 'SELECT,UPDATE',
		'admin_users' => 'SELECT,INSERT,UPDATE',
		'admin_sessions' => 'SELECT,INSERT,UPDATE,DELETE',
		'admin_recovery_codes' => 'SELECT,INSERT,UPDATE',
		'admin_trusted_devices' => 'SELECT,INSERT,UPDATE',
		'rate_limit_buckets' => 'SELECT,INSERT,UPDATE,DELETE',
		'audit_heads' => 'SELECT,UPDATE',
		'audit_events' => 'SELECT,INSERT',
		'admin_operations' => 'SELECT,INSERT,UPDATE',
		'customers' => 'SELECT,INSERT,UPDATE',
		'customer_contacts' => 'SELECT,INSERT,UPDATE',
		'products' => 'SELECT,INSERT,UPDATE',
		'product_capabilities' => 'SELECT,INSERT,UPDATE',
		'capability_dependencies' => 'SELECT,INSERT,DELETE',
		'plans' => 'SELECT,INSERT,UPDATE',
		'licenses' => 'SELECT,INSERT,UPDATE',
		'license_features' => 'SELECT,INSERT,UPDATE',
		'license_credentials' => 'SELECT,INSERT,UPDATE',
		'license_changes' => 'SELECT,INSERT',
		'license_payments' => 'SELECT,INSERT',
		'license_scheduled_changes' =>
			'SELECT,INSERT,UPDATE (state,resolved_at,resolved_by,cash_registers,order_takers,kitchen_displays)',
		'signing_keys' => 'SELECT',
		'signing_scopes' => 'SELECT',
		'activations' => 'SELECT,INSERT,UPDATE (state,ended_at,current_revision_id,app_version)',
		'license_revisions' => 'SELECT,INSERT',
		'activation_challenges' => 'SELECT,INSERT,UPDATE,DELETE',
		'license_requests' => 'SELECT,INSERT,UPDATE',
		'offline_requests' => 'SELECT,INSERT',
		'offline_decisions' => 'SELECT,INSERT',
		'license_transfers' => 'SELECT,INSERT,UPDATE (state,confirmed_at,confirmation,released_by,release_reason)',
		'license_alerts' => 'SELECT,INSERT,UPDATE (attempts,detail_json,last_seen_at,reviewed_at,reviewed_by)',
		'admin_permission_grants' => 'SELECT,INSERT,DELETE',
		'license_administrative_changes' => 'SELECT,INSERT',
		'license_documents' => 'SELECT,INSERT',
	];

	/**
	 * Sentencias GRANT para la cuenta $user de la base $database.
	 *
	 * @return list<string>
	 */
	public static function statements(string $database, string $user): array
	{
		$statements = [];
		foreach (self::PRIVILEGES as $table => $privileges) {
			$statements[] = "GRANT $privileges ON `$database`.`$table` TO '$user'@'localhost';";
		}
		return $statements;
	}

	/**
	 * Diferencias, en español, entre lo que tiene la cuenta según las filas de SHOW GRANTS FOR CURRENT_USER() y
	 * PRIVILEGES. Una lista vacía quiere decir que tiene exactamente los permisos previstos: ni un privilegio de
	 * más (por ejemplo, DELETE en una tabla de historia), ni uno de menos, ni permisos globales, sobre toda la
	 * base, sobre otra base o por medio de un rol.
	 *
	 * @param list<string> $rows
	 * @return list<string>
	 */
	public static function differences(array $rows, string $database): array
	{
		$problems = [];
		$actual = [];
		foreach ($rows as $row) {
			if (!preg_match('/\AGRANT (.+?) ON (\S+) TO /', $row, $match)) {
				// Un rol («GRANT `rol`@`%` TO …») da permisos que esta comparación no vería.
				$problems[] = 'tiene un rol o un permiso no previsto: ' . $row;
				continue;
			}
			$target = str_replace(['`', '\\'], '', $match[2]);
			$privileges = self::normalize($match[1]);
			if (str_starts_with($target, $database . '.') && !str_ends_with($target, '.*')) {
				$actual[substr($target, strlen($database) + 1)] = $privileges;
				continue;
			}
			// USAGE sobre *.* es solo el permiso de conectarse; cualquier otra cosa fuera de las tablas sobra.
			foreach (array_diff($privileges, ['USAGE']) as $privilege) {
				$problems[] = 'tiene ' . $privilege . ' sobre ' . $target;
			}
		}
		foreach (self::PRIVILEGES as $table => $privileges) {
			$expected = self::normalize($privileges);
			$has = $actual[$table] ?? [];
			foreach (array_diff($has, $expected) as $extra) {
				$problems[] = $table . ': sobra ' . $extra;
			}
			foreach (array_diff($expected, $has) as $missing) {
				$problems[] = $table . ': falta ' . $missing;
			}
		}
		foreach (array_diff_key($actual, self::PRIVILEGES) as $table => $privileges) {
			$problems[] = $table . ': tiene ' . implode(', ', $privileges) . ' y no debería tener ninguno';
		}
		return $problems;
	}

	/**
	 * «SELECT, INSERT, UPDATE (`b`, `a`)» → ['INSERT', 'SELECT', 'UPDATE(a,b)']: privilegios ordenados, con las
	 * columnas de un UPDATE limitado también ordenadas, para comparar sin importar cómo los escribe MySQL.
	 *
	 * @return list<string>
	 */
	private static function normalize(string $privileges): array
	{
		preg_match_all(
			'/([A-Z_ ]+?)\s*(?:\(([^)]*)\))?\s*(?:,|$)/',
			strtoupper(trim($privileges)),
			$parts,
			PREG_SET_ORDER,
		);
		$list = [];
		foreach ($parts as $part) {
			$name = trim($part[1]);
			if ($name === '') {
				continue;
			}
			if (($part[2] ?? '') !== '') {
				$columns = array_map(
					static fn(string $column): string => strtolower(trim($column, ' `')),
					explode(',', $part[2]),
				);
				sort($columns);
				$name .= '(' . implode(',', $columns) . ')';
			}
			$list[] = $name;
		}
		sort($list);
		return $list;
	}
}
