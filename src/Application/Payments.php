<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{CalendarPeriod, Input, LicenseTerms, Money, Problem, Uuid};
use AdelPos\License\Infrastructure\{Audit, Clock, Database};

/**
 * Registro de pagos de una licencia (etapa 3, Fase 1): de solo inserción, en MXN y con el monto
 * tal como se cobró. Cada operación comercial registra aquí su pago dentro de su misma
 * transacción, así que el pago, el cambio de la licencia, la firma y la auditoría se confirman
 * juntos o no se confirma ninguno.
 */
final class Payments
{
	public const CONCEPTS = [
		'issue' => 'Alta',
		'renewal' => 'Renovación',
		'conversion' => 'Conversión',
		'plan_change' => 'Cambio de plan',
		'maintenance' => 'Mantenimiento',
	];

	private const LOCAL = '/\A(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?\z/';

	private const EXACT = '/\A(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z\z/';

	public function __construct(private Database $db, private Clock $clock, private Audit $audit) {}

	/**
	 * Datos del pago tal como llegan del formulario: el monto cobrado, el motivo si se cambió el
	 * monto sugerido y la fecha de pago. Se validan antes de abrir la transacción.
	 *
	 * @return array{amount: ?int, reason: string, paid_at: string}
	 */
	public static function input(array $input): array
	{
		return [
			'amount' => Money::optional($input['amount'] ?? '', 'El monto cobrado'),
			'reason' => Input::text($input, 'amount_reason', 500, false),
			'paid_at' => Input::text($input, 'paid_at', 40, false),
		];
	}

	/**
	 * Instante del pago. Llega como fecha y hora local del cliente («2026-11-16T10:30», la zona de
	 * su ficha), o como instante UTC exacto cuando lo fija la pantalla de revisión. Vacío es ahora.
	 * No puede ser posterior a este momento ni anterior al último pago registrado de la licencia.
	 */
	public function paidAt(string $value, string $zone, ?string $licenseId): \DateTimeImmutable
	{
		$now = $this->clock->now();
		if ($value === '') {
			$paidAt = $now;
		} elseif (preg_match(self::EXACT, $value, $parts)) {
			$paidAt = $this->exact($parts);
		} elseif (preg_match(self::LOCAL, $value, $parts)) {
			$paidAt = $this->local($parts, $zone);
		} else {
			throw new Problem('Escribe la fecha de pago con su día y su hora.');
		}
		if ($paidAt > $now) {
			throw new Problem('La fecha de pago no puede ser posterior a este momento.');
		}
		if ($licenseId !== null) {
			$last = $this->db
				->execute('SELECT MAX(paid_at) FROM license_payments WHERE license_id=?', [$licenseId])
				->fetchColumn();
			if (is_string($last) && CalendarPeriod::sql($paidAt) < $last) {
				throw new Problem(
					'La fecha de pago es anterior al último pago registrado de esta licencia (' .
						self::localText($last, $zone) .
						').',
				);
			}
		}
		return $paidAt;
	}

	/**
	 * Registra el pago y su evento de auditoría. Devuelve el identificador del pago, en binario.
	 *
	 * Lo sugerido es el precio del catálogo menos la bonificación, nunca menos de 0. Si el monto
	 * cobrado es distinto, el motivo es obligatorio; si es el sugerido, no se escribe motivo.
	 *
	 * @param array{
	 *     concept: string,
	 *     term: string,
	 *     plan_id: string,
	 *     paid_at: \DateTimeImmutable,
	 *     amount: int,
	 *     catalog: ?int,
	 *     credit: int,
	 *     reason: string,
	 *     reference: string,
	 *     covers: ?array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
	 * } $payment
	 */
	public function record(Actor $actor, string $operationId, string $licenseId, array $payment): string
	{
		if (!isset(self::CONCEPTS[$payment['concept']])) {
			throw new \InvalidArgumentException('Unknown payment concept.');
		}
		if ($payment['term'] === 'trial' && $payment['amount'] !== 0) {
			throw new Problem('Una licencia de prueba no tiene costo: su monto es 0.');
		}
		$suggested = $payment['catalog'] === null ? null : max(0, $payment['catalog'] - $payment['credit']);
		$overridden = $suggested !== null && $payment['amount'] !== $suggested;
		if ($overridden && $payment['reason'] === '') {
			throw new Problem(
				'El monto cobrado es distinto del sugerido (' .
					Money::format($suggested) .
					'). Escribe el motivo del cambio.',
			);
		}
		if (!$overridden && $payment['reason'] !== '') {
			throw new Problem(
				$suggested === null
					? 'No hay monto sugerido: el motivo solo se escribe cuando cambias el monto sugerido.'
					: 'El monto cobrado es el sugerido: el motivo solo se escribe cuando lo cambias.',
			);
		}
		$id = Uuid::create();
		$covers = $payment['covers'];
		$this->db->execute(
			'INSERT INTO license_payments(payment_id,license_id,operation_id,concept,term,plan_id,paid_at,
					currency,amount,catalog_amount,credit_amount,suggested_amount,override_reason,reference,
					covers_from,covers_until,admin_id,created_at)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
			[
				Uuid::bytes($id),
				$licenseId,
				Uuid::bytes($operationId),
				$payment['concept'],
				$payment['term'],
				$payment['plan_id'],
				CalendarPeriod::sql($payment['paid_at']),
				Money::CURRENCY,
				Money::toSql($payment['amount']),
				$payment['catalog'] === null ? null : Money::toSql($payment['catalog']),
				Money::toSql($payment['credit']),
				$suggested === null ? null : Money::toSql($suggested),
				$overridden ? $payment['reason'] : null,
				$payment['reference'],
				$covers === null ? null : CalendarPeriod::sql($covers[0]),
				$covers === null ? null : CalendarPeriod::sql($covers[1]),
				Uuid::bytes($actor->id),
				$this->clock->sql(),
			],
		);
		$this->audit->append(
			$actor->id,
			'payment.recorded',
			'license',
			Uuid::text($licenseId),
			[
				'payment_id' => $id,
				'concept' => $payment['concept'],
				'term' => $payment['term'],
				'plan_id' => $payment['plan_id'],
				'paid_at' => CalendarPeriod::utc($payment['paid_at']),
				'currency' => Money::CURRENCY,
				'amount' => Money::toSql($payment['amount']),
				'catalog_amount' => $payment['catalog'] === null ? null : Money::toSql($payment['catalog']),
				'credit_amount' => Money::toSql($payment['credit']),
				'suggested_amount' => $suggested === null ? null : Money::toSql($suggested),
				'overridden' => $overridden,
			],
			$overridden ? $payment['reason'] : '',
		);
		return Uuid::bytes($id);
	}

	/** Nombre del plazo de un pago para el panel. */
	public static function termLabel(string $term): string
	{
		return LicenseTerms::TERM_LABELS[$term] ?? $term;
	}

	/** Fecha y hora de un instante guardado, en la zona del cliente y con el nombre de la zona. */
	private static function localText(string $stored, string $zone): string
	{
		$local = (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
		return $local->format('d/m/Y · H:i') . ' ' . $zone;
	}

	private function exact(array $parts): \DateTimeImmutable
	{
		$fraction = str_pad($parts[5] ?? '', 6, '0');
		$instant = \DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s.u',
			$parts[1] . ' ' . $parts[2] . ':' . $parts[3] . ':' . $parts[4] . '.' . $fraction,
			new \DateTimeZone('UTC'),
		);
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			!$instant ||
			($errors !== false && ($errors['warning_count'] || $errors['error_count'])) ||
			CalendarPeriod::sql($instant) !==
				$parts[1] . ' ' . $parts[2] . ':' . $parts[3] . ':' . $parts[4] . '.' . $fraction
		) {
			throw new Problem('Escribe la fecha de pago con su día y su hora.');
		}
		return $instant;
	}

	private function local(array $parts, string $zone): \DateTimeImmutable
	{
		$hour = (int) $parts[2];
		$minute = (int) $parts[3];
		$second = (int) ($parts[4] ?? 0);
		if ($hour > 23 || $minute > 59 || $second > 59) {
			throw new Problem('Escribe la fecha de pago con su día y su hora.');
		}
		try {
			return CalendarPeriod::localInstant(
				$parts[1],
				sprintf('%02d:%02d:%02d.000000', $hour, $minute, $second),
				$zone,
			);
		} catch (\InvalidArgumentException) {
			throw new Problem('Escribe la fecha de pago con su día y su hora.');
		}
	}
}
