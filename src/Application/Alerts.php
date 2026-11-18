<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Problem, Protocol, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Alertas del panel (decisión 4 del propietario para la etapa 4; contrato §8.4). No se envían correos:
 * se ven en el resumen y en el botón de notificaciones de la barra superior.
 *
 * Un mismo tipo y una misma licencia comparten una alerta abierta. Mientras nadie la revisa, cada caso
 * nuevo solo aumenta su contador, actualiza su último instante y su detalle. Revisada, deja de estar
 * abierta: si la situación vuelve a ocurrir, se abre otra.
 */
final class Alerts
{
	public const TYPES = [
		'transfer_blocked' => 'Transferencia por autoservicio bloqueada',
		'frequent_transfers' => 'Transferencias frecuentes',
	];

	/** Servidores distintos que se guardan en el detalle de un intento bloqueado. */
	private const MAX_SERVERS = 20;

	/** Transferencias que se nombran en el detalle de la alerta de transferencias frecuentes. */
	private const MAX_TRANSFERS = 20;

	/** Ventana de la alerta de transferencias frecuentes: los 12 meses de calendario anteriores. */
	public const WINDOW_MONTHS = 12;

	/** Cuentas que pueden marcar una alerta como revisada; la de consulta solo las ve. */
	public const REVIEWERS = ['superadmin', 'operator'];

	public function __construct(
		private Database $db,
		private Clock $clock,
		private Audit $audit,
		private Operations $operations,
	) {}

	/**
	 * Alerta de transferencias frecuentes (contrato §8.4, regla 5; decisión 6 del propietario): una licencia
	 * suma dos o más transferencias, de autoservicio o del propietario y confirmadas o no, dentro de los
	 * 12 meses de calendario anteriores, en la zona horaria del cliente. La ventana incluye su inicio
	 * exacto (aclaración n.º 37 del contrato). Se evalúa después de registrar cada transferencia, con la licencia
	 * bloqueada.
	 *
	 * @return ?array{alert_id: string, attempts: int, opened: bool} null si no hay alerta
	 */
	public function frequentTransfers(string $licenseId): ?array
	{
		$zone = $this->db
			->execute(
				'SELECT c.time_zone FROM licenses l JOIN customers c ON c.id=l.customer_id WHERE l.license_id=?',
				[$licenseId],
			)
			->fetchColumn();
		if (!is_string($zone)) {
			throw new \RuntimeException('License without customer.');
		}
		$now = $this->clock->now();
		$since = CalendarPeriod::monthsBefore($now, self::WINDOW_MONTHS, $zone);
		$transfers = $this->db->all(
			'SELECT id,via,created_at
				FROM license_transfers
				WHERE license_id=? AND created_at>=? AND created_at<=?
				ORDER BY created_at DESC,id',
			[$licenseId, CalendarPeriod::sql($since), CalendarPeriod::sql($now)],
		);
		if (count($transfers) < 2) {
			return null;
		}
		$listed = [];
		foreach (array_slice($transfers, 0, self::MAX_TRANSFERS) as $transfer) {
			$listed[] = [
				'transfer_id' => Uuid::text($transfer['id']),
				'via' => $transfer['via'],
				'created_at' => Protocol::utc($transfer['created_at']),
			];
		}
		return $this->raise(
			$licenseId,
			'frequent_transfers',
			fn(?array $previous): array => [
				'transfers' => count($transfers),
				'window_starts_at' => CalendarPeriod::utc($since),
				'time_zone' => $zone,
				'latest' => $listed,
			],
		);
	}

	/**
	 * Marca una alerta como revisada: queda quién y cuándo, también en la auditoría. Lo pueden hacer el
	 * operador y el superadministrador. Revisada, deja de estar abierta, y si la situación se repite se
	 * abre otra.
	 */
	public function review(Actor $actor, string $operationId, string $alertId): array
	{
		return $this->operations->run(
			$actor,
			self::REVIEWERS,
			$operationId,
			'alert.review',
			[$alertId],
			function () use ($actor, $alertId): array {
				$alert =
					$this->db->one('SELECT * FROM license_alerts WHERE alert_id=? FOR UPDATE', [
						Uuid::bytes($alertId),
					]) ?? throw new Problem('No se encontró la alerta.', 404);
				if ($alert['reviewed_at'] !== null) {
					throw new Problem('La alerta ya estaba revisada.', 409);
				}
				$this->db->execute('UPDATE license_alerts SET reviewed_at=?,reviewed_by=? WHERE alert_id=?', [
					$this->clock->sql(),
					Uuid::bytes($actor->id),
					$alert['alert_id'],
				]);
				$this->audit->append($actor->id, 'alert.reviewed', 'alert', $alertId, [
					'license_id' => Uuid::text($alert['license_id']),
					'alert_type' => $alert['alert_type'],
					'attempts' => (int) $alert['attempts'],
				]);
				return ['id' => $alertId, 'license_id' => Uuid::text($alert['license_id'])];
			},
		);
	}

	/**
	 * Un intento de transferencia por autoservicio bloqueado con TRANSFER_LIMIT. El detalle permite al
	 * propietario decidir: la instalación que lo intentó y su versión de AD-EL POS, las instalaciones
	 * de la transferencia pendiente y desde cuándo está pendiente. El número de intentos es el
	 * contador de la alerta. Quien llama tiene bloqueada la licencia.
	 *
	 * @param array $pending fila de «license_transfers» pendiente
	 * @param array{server_id: string, app_version: string} $attempt
	 */
	public function transferBlocked(string $licenseId, array $pending, array $attempt): array
	{
		$servers = $this->db->one(
			'SELECT BIN_TO_UUID(o.server_id) AS from_server,BIN_TO_UUID(i.server_id) AS to_server
				FROM activations o
				JOIN activations i ON i.activation_id=?
				WHERE o.activation_id=?',
			[$pending['incoming_activation_id'], $pending['outgoing_activation_id']],
		);
		return $this->raise($licenseId, 'transfer_blocked', function (?array $previous) use (
			$pending,
			$attempt,
			$servers,
		): array {
			$attempted = $previous['attempted_server_ids'] ?? [];
			if (!in_array($attempt['server_id'], $attempted, true) && count($attempted) < self::MAX_SERVERS) {
				$attempted[] = $attempt['server_id'];
			}
			return [
				'server_id' => $attempt['server_id'],
				'app_version' => $attempt['app_version'],
				'attempted_server_ids' => $attempted,
				'pending_transfer_id' => Uuid::text($pending['id']),
				'pending_since' => Protocol::utc($pending['created_at']),
				'pending_from_server_id' => $servers['from_server'],
				'pending_to_server_id' => $servers['to_server'],
			];
		});
	}

	/**
	 * Abre la alerta, o suma un caso a la que ya está abierta. $detail recibe el detalle anterior (o
	 * null si no hay alerta abierta) y devuelve el nuevo. No escribe en la auditoría: la cabeza de la
	 * auditoría se bloquea al final de la transacción, y el evento que provoca la alerta la nombra.
	 *
	 * @return array{alert_id: string, attempts: int, opened: bool}
	 */
	private function raise(string $licenseId, string $type, callable $detail): array
	{
		$now = $this->clock->sql();
		$open = $this->db->one(
			'SELECT alert_id,attempts,detail_json
				FROM license_alerts
				WHERE open_license_id=? AND alert_type=?
				FOR UPDATE',
			[$licenseId, $type],
		);
		if ($open !== null) {
			$previous = json_decode($open['detail_json'], true, 16, JSON_THROW_ON_ERROR);
			$this->db->execute(
				'UPDATE license_alerts SET attempts=attempts+1,last_seen_at=?,detail_json=? WHERE alert_id=?',
				[$now, Protocol::json($detail($previous)), $open['alert_id']],
			);
			return [
				'alert_id' => Uuid::text($open['alert_id']),
				'attempts' => (int) $open['attempts'] + 1,
				'opened' => false,
			];
		}
		$id = Uuid::create();
		$this->db->execute(
			'INSERT INTO license_alerts(alert_id,license_id,alert_type,attempts,detail_json,created_at,last_seen_at)
				VALUES (?,?,?,1,?,?,?)',
			[Uuid::bytes($id), $licenseId, $type, Protocol::json($detail(null)), $now, $now],
		);
		return ['alert_id' => $id, 'attempts' => 1, 'opened' => true];
	}
}
