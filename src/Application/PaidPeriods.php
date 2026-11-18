<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

use AdelPos\License\Domain\{LicenseTerms, Money, Uuid};
use AdelPos\License\Infrastructure\Database;

/**
 * Periodos pagados de una suscripción que todavía no terminan en un instante, con su valor. Son la
 * base de la bonificación de un cambio de plan (decisión 3 del propietario para la etapa 4;
 * aclaraciones n.º 27 y 28 del contrato).
 *
 * Los periodos forman una cadena que termina en el periodo vigente de la licencia:
 * - el periodo vigente es el que cubre el pago «period_payment_id»;
 * - una renovación anticipada deja su periodo en el futuro: el periodo anterior termina donde ella
 *   empieza, y sigue corriendo hasta entonces;
 * - un cambio de plan con bonificación, una conversión o el alta empiezan una cadena nueva: lo que
 *   quedaba de los periodos anteriores ya está dentro del valor de ese pago.
 *
 * El valor de un periodo es lo cobrado más la bonificación que se aplicó en ese pago. Si el periodo
 * vigente no tiene pago (licencias emitidas antes de la etapa 3), vale el precio del catálogo vigente
 * de su plan y su periodo, como en la etapa 3.
 */
final class PaidPeriods
{
	public function __construct(private Database $db) {}

	/**
	 * Periodos que todavía no terminan en $at, del más antiguo al más reciente. $at es anterior al
	 * vencimiento vigente: un pago posterior no es un cambio de plan sino una renovación (aclaración
	 * n.º 31). Devuelve null si el valor de alguno no se conoce: no hay sugerencia y el monto se
	 * escribe a mano.
	 *
	 * @return ?list<array{
	 *     payment_id: ?string,
	 *     concept: ?string,
	 *     source: string,
	 *     amount: int,
	 *     applied_credit: int,
	 *     value: int,
	 *     starts_at: \DateTimeImmutable,
	 *     ends_at: \DateTimeImmutable,
	 * }>
	 */
	public function live(array $license, \DateTimeImmutable $at): ?array
	{
		if ($license['license_type'] !== 'subscription' || $license['period_starts_at'] === null) {
			return [];
		}
		$payments = $this->db->all(
			"SELECT payment_id,concept,term,amount,credit_amount,covers_from,covers_until
				FROM license_payments
				WHERE license_id=?
					AND concept IN ('issue','renewal','conversion','plan_change')
					AND term IN ('monthly','quarterly','semiannual','annual')
				ORDER BY paid_at,created_at",
			[$license['license_id']],
		);
		$index = null;
		foreach ($payments as $position => $payment) {
			if ($payment['payment_id'] === $license['period_payment_id']) {
				$index = $position;
			}
		}
		$element = [
			'index' => $index,
			'starts_at' => self::instant($license['period_starts_at']),
			'ends_at' => self::instant($license['expires_at']),
		];
		$periods = [];
		while (true) {
			// Todo periodo de la cadena termina después del pago: el vigente, porque un pago posterior a su
			// vencimiento es una renovación, y cada anterior, porque termina donde empieza uno futuro.
			$period = $this->value($license, $element['index'] === null ? null : $payments[$element['index']]);
			if ($period === null) {
				return null;
			}
			$periods[] = $period + ['starts_at' => $element['starts_at'], 'ends_at' => $element['ends_at']];
			// Un periodo que ya empezó: los anteriores terminaron a más tardar en su inicio.
			if ($element['starts_at'] <= $at) {
				break;
			}
			// Solo una renovación anticipada empieza después del pago: el periodo anterior sigue corriendo.
			if ($element['index'] === null || $payments[$element['index']]['concept'] !== 'renewal') {
				break;
			}
			$element = $this->previous($license, $payments, $element);
			if ($element['index'] === null) {
				// El periodo anterior no tiene pago registrado: no se sabe cuánto valía ni qué plazo cubría.
				return null;
			}
		}
		return array_reverse($periods);
	}

	/**
	 * El periodo que termina donde empieza $element. Es el pago anterior que cubre hasta ese instante;
	 * si ninguno lo hace, es el primer periodo de la licencia, cuyo pago (alta o conversión sin
	 * activar) no tiene fechas porque se fijaron al activar.
	 */
	private function previous(array $license, array $payments, array $element): array
	{
		$end = $element['starts_at'];
		for ($position = $element['index'] - 1; $position >= 0; $position--) {
			$payment = $payments[$position];
			if ($payment['covers_until'] !== null && self::instant($payment['covers_until']) == $end) {
				return [
					'index' => $position,
					'starts_at' => self::instant($payment['covers_from']),
					'ends_at' => $end,
				];
			}
		}
		for ($position = $element['index'] - 1; $position >= 0; $position--) {
			if ($payments[$position]['covers_from'] === null) {
				return ['index' => $position, 'starts_at' => $this->firstStart($license), 'ends_at' => $end];
			}
		}
		return ['index' => null, 'starts_at' => $this->firstStart($license), 'ends_at' => $end];
	}

	/** El primer periodo empieza en la primera activación de la licencia (contrato §5.2). */
	private function firstStart(array $license): \DateTimeImmutable
	{
		$first = $this->db
			->execute('SELECT MIN(activated_at) FROM activations WHERE license_id=?', [$license['license_id']])
			->fetchColumn();
		if (!is_string($first)) {
			throw new \RuntimeException('Subscription period without activation.');
		}
		return self::instant($first);
	}

	/** Valor del periodo: lo cobrado más la bonificación aplicada, o el precio del catálogo si no hay pago. */
	private function value(array $license, ?array $payment): ?array
	{
		if ($payment !== null) {
			$amount = Money::fromSql($payment['amount']);
			$applied = Money::fromSql($payment['credit_amount']);
			return [
				'payment_id' => Uuid::text($payment['payment_id']),
				'concept' => $payment['concept'],
				'source' => 'payment',
				'amount' => $amount,
				'applied_credit' => $applied,
				'value' => $amount + $applied,
			];
		}
		if ($license['plan_custom']) {
			return null;
		}
		$plan = $this->db->one('SELECT * FROM plans WHERE plan_id=?', [$license['plan_id']]);
		$price = $plan === null ? null : LicenseTerms::catalogPrice($plan, $license['billing_period']);
		if ($price === null) {
			return null;
		}
		return [
			'payment_id' => null,
			'concept' => null,
			'source' => 'catalog',
			'amount' => $price,
			'applied_credit' => 0,
			'value' => $price,
		];
	}

	private static function instant(string $sql): \DateTimeImmutable
	{
		return new \DateTimeImmutable($sql, new \DateTimeZone('UTC'));
	}
}
