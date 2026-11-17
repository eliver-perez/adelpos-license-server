<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Uuid};
use AdelPos\License\Infrastructure\{Clock, Database};

/**
 * Cambios de plan programados (etapa 3, fases 5 y 7; decisiones 7 y 8 del propietario).
 *
 * Bajar de plan o reducir cupos entra en vigor cuando vence el tiempo pagado. El cambio queda
 * pendiente con su instante y su destino (plan y cupos), y se aplica una sola vez:
 * - la tarea programada lo aplica al llegar el instante (bin/apply-scheduled-changes.php);
 * - una renovación o un cambio de plan posterior al instante lo aplica antes, en su misma
 *   transacción, si la tarea todavía no corrió.
 *
 * Una licencia tiene a lo sumo un cambio pendiente: uno nuevo sustituye al anterior.
 */
final class ScheduledChanges
{
	public function __construct(
		private Database $db,
		private Clock $clock,
		private RevisionPublisher $publisher,
		private LicenseHistory $history,
	) {}

	/** Cambio pendiente de la licencia, bloqueado, o null. La licencia ya está bloqueada. */
	public function pending(string $licenseId): ?array
	{
		return $this->db->one(
			"SELECT * FROM license_scheduled_changes WHERE license_id=? AND state='pending' FOR UPDATE",
			[$licenseId],
		);
	}

	/**
	 * Programa un cambio para $effectiveAt. Si había otro pendiente, lo sustituye y lo devuelve.
	 *
	 * @param array{plan: array, limits: array<string,int>, custom: bool} $target
	 * @return array{change_id: string, replaced: ?array}
	 */
	public function schedule(
		Actor $actor,
		string $operationId,
		array $license,
		\DateTimeImmutable $effectiveAt,
		array $target,
		string $reason,
	): array {
		$replaced = $this->resolve($license['license_id'], 'replaced', $actor);
		$id = Uuid::create();
		$this->db->execute(
			'INSERT INTO license_scheduled_changes(change_id,license_id,operation_id,effective_at,plan_id,
					plan_custom,cash_registers,order_takers,kitchen_displays,admin_id,reason,created_at)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes($id),
				$license['license_id'],
				Uuid::bytes($operationId),
				CalendarPeriod::sql($effectiveAt),
				$target['plan']['plan_id'],
				$target['custom'] ? 1 : 0,
				$target['limits']['cash_registers'],
				$target['limits']['order_takers'],
				$target['limits']['kitchen_displays'],
				Uuid::bytes($actor->id),
				$reason,
				$this->clock->sql(),
			],
		);
		return ['change_id' => $id, 'replaced' => $replaced];
	}

	/**
	 * Cancela o sustituye el cambio pendiente de la licencia. Devuelve el que había, o null.
	 * $state es «cancelled» o «replaced».
	 */
	public function resolve(string $licenseId, string $state, Actor $actor): ?array
	{
		$pending = $this->pending($licenseId);
		if ($pending === null) {
			return null;
		}
		$this->db->execute(
			'UPDATE license_scheduled_changes SET state=?,resolved_at=?,resolved_by=? WHERE change_id=?',
			[$state, $this->clock->sql(), Uuid::bytes($actor->id), $pending['change_id']],
		);
		return $pending;
	}

	/**
	 * Aplica el cambio pendiente si ya llegó su instante. Quien llama tiene bloqueados el producto y
	 * la licencia. Devuelve la licencia tal como quedó y el cambio aplicado, o null si no había.
	 * Sin $actor, el cambio lo aplica el sistema (la tarea programada).
	 *
	 * @return array{license: array, applied: ?array, revisions_signed: int}
	 */
	public function applyDue(array $license, ?Actor $actor): array
	{
		$change = $this->pending($license['license_id']);
		if ($change === null || $change['effective_at'] > $this->clock->sql()) {
			return ['license' => $license, 'applied' => null, 'revisions_signed' => 0];
		}
		$planName = $this->db
			->execute('SELECT display_name FROM plans WHERE plan_id=?', [$change['plan_id']])
			->fetchColumn();
		$this->db->execute(
			'UPDATE licenses
				SET plan_id=?,plan_name=?,plan_custom=?,cash_registers=?,order_takers=?,kitchen_displays=?,
					row_version=row_version+1,updated_at=?
				WHERE license_id=?',
			[
				$change['plan_id'],
				$planName,
				(int) $change['plan_custom'],
				(int) $change['cash_registers'],
				(int) $change['order_takers'],
				(int) $change['kitchen_displays'],
				$this->clock->sql(),
				$license['license_id'],
			],
		);
		$this->db->execute(
			"UPDATE license_scheduled_changes SET state='applied',resolved_at=?,resolved_by=? WHERE change_id=?",
			[$this->clock->sql(), $actor === null ? null : Uuid::bytes($actor->id), $change['change_id']],
		);
		$signed = $this->publisher->commercialChange($license['license_id'], false);
		// El historial lo atribuye a quien programó el cambio; la auditoría, a quien lo aplicó.
		$this->history->record(
			Uuid::text($license['license_id']),
			$actor?->id ?? Uuid::text($change['admin_id']),
			'license.scheduled_change_applied',
			'Cambio programado para ' .
				CalendarPeriod::utc(new \DateTimeImmutable($change['effective_at'], new \DateTimeZone('UTC'))),
			'',
			[
				'change_id' => Uuid::text($change['change_id']),
				'effective_at' => CalendarPeriod::utc(
					new \DateTimeImmutable($change['effective_at'], new \DateTimeZone('UTC')),
				),
				'plan_id' => $change['plan_id'],
				'limits' => [
					'cash_registers' => (int) $change['cash_registers'],
					'order_takers' => (int) $change['order_takers'],
					'kitchen_displays' => (int) $change['kitchen_displays'],
				],
				'revisions_signed' => $signed,
			],
			bySystem: $actor === null,
		);
		return [
			'license' => $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [
				$license['license_id'],
			]),
			'applied' => $change,
			'revisions_signed' => $signed,
		];
	}
}
