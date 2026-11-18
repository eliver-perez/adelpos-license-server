<?php
use AdelPos\License\Application\Payments;
use AdelPos\License\Http\View as V;

// Sección «Pagos»: cada pago tal como se registró, en MXN, con su concepto, lo cobrado, lo sugerido, la
// bonificación y el motivo del cambio de monto. Las fechas van en la zona del cliente.
$paymentRows = [];
foreach ($license['payments'] as $payment) {
	$covers = '';
	if ($payment['covers_from'] !== null) {
		$covers =
			'Cubre del ' . V::date($payment['covers_from'], $zone) . ' al ' . V::date($payment['covers_until'], $zone);
	}
	$paymentRows[] = [
		'paid_at' => V::dateParts($payment['paid_at'], $zone),
		'concept' => V::paymentConcept($payment['concept']),
		'term' => Payments::termLabel($payment['term']) . ' · ' . $payment['plan_id'],
		'covers' => $covers,
		'reference' => $payment['reference'],
		'amount' => V::money($payment['amount']),
		'reason' => $payment['override_reason'],
		'suggested' => V::money($payment['suggested_amount']),
		'catalog' => $payment['catalog_amount'] === null
			? 'Sin precio de catálogo'
			: 'Catálogo: ' . V::money($payment['catalog_amount']),
		'credit' => $payment['credit_amount'] === '0.00' ? '—' : V::money($payment['credit_amount']),
		'actor' => $payment['actor_name'],
		'created_at' => V::date($payment['created_at'], $zone),
	];
}
?>
<section class="panel" id="payments" aria-labelledby="payments-title">
	<div class="panel-heading">
		<div>
			<h2 id="payments-title">Pagos</h2>
			<p>Cada pago se registra tal como se cobró, en MXN, y no se puede editar ni borrar.</p>
		</div>
	</div>
	<?php if ($paymentRows === []): ?>
		<p class="empty-line">Esta licencia no tiene pagos registrados.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack">
				<thead>
					<tr>
						<th>Fecha de pago</th>
						<th>Concepto</th>
						<th>Cobrado</th>
						<th>Sugerido</th>
						<th>Bonificación</th>
						<th>Registró</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($paymentRows as $row): ?>
						<tr>
							<td data-label="Fecha de pago">
								<?= V::escape($row['paid_at']['date']) ?>
								<small class="cell-sub"><?= V::escape($row['paid_at']['zone']) ?></small>
							</td>
							<td data-label="Concepto">
								<strong><?= V::escape($row['concept']) ?></strong>
								<small class="cell-sub"><?= V::escape($row['term']) ?></small>
								<?php if ($row['covers'] !== ''): ?>
									<small class="cell-sub"><?= V::escape($row['covers']) ?></small>
								<?php endif; ?>
								<?php if ($row['reference'] !== ''): ?>
									<small class="cell-sub">Referencia: <?= V::escape($row['reference']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="Cobrado">
								<strong><?= V::escape($row['amount']) ?></strong>
								<?php if ($row['reason'] !== null): ?>
									<small class="cell-sub">Monto cambiado: <?= V::escape($row['reason']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="Sugerido">
								<?= V::escape($row['suggested']) ?>
								<small class="cell-sub"><?= V::escape($row['catalog']) ?></small>
							</td>
							<td data-label="Bonificación"><?= V::escape($row['credit']) ?></td>
							<td data-label="Registró">
								<?= V::escape($row['actor']) ?>
								<small class="cell-sub">Registrado: <?= V::escape($row['created_at']) ?></small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
