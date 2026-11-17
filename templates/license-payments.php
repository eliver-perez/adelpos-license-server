<?php
use AdelPos\License\Application\Payments;
use AdelPos\License\Http\View as V;

$clientZone = $license['time_zone'];
?>
<section class="panel mt-4" id="payments">
	<div class="panel-heading">
		<div>
			<h2>Pagos</h2>
			<p>
				Cada pago se registra tal como se cobró, en MXN, y no se puede editar ni borrar. Las fechas están en la
				zona del cliente.
			</p>
		</div>
	</div>
	<?php if (!$license['payments']): ?>
		<p class="text-secondary small px-4 pb-4 mb-0">Esta licencia no tiene pagos registrados.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table">
				<thead>
					<tr>
						<th>Fecha de pago</th>
						<th>Concepto</th>
						<th>Monto cobrado</th>
						<th>Sugerido</th>
						<th>Registró</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($license['payments'] as $payment): ?>
						<?php
						$covers =
							$payment['covers_from'] === null
								? ''
								: 'Cubre del ' .
									V::date($payment['covers_from'], $clientZone) .
									' al ' .
									V::date($payment['covers_until'], $clientZone);
						$credit = '';
						if ($payment['credit_amount'] !== '0.00') {
							$credit = 'Bonificación: ' . V::money($payment['credit_amount']);
						}
						$catalog = 'Sin precio de catálogo';
						if ($payment['catalog_amount'] !== null) {
							$catalog = 'Catálogo: ' . V::money($payment['catalog_amount']);
						}
						?>
						<tr>
							<td><?= V::escape(V::date($payment['paid_at'], $clientZone)) ?></td>
							<td>
								<strong><?= V::escape(V::paymentConcept($payment['concept'])) ?></strong>
								<small class="cell-sub">
									<?= V::escape(Payments::termLabel($payment['term'])) ?> · <?= V::escape($payment['plan_id']) ?>
								</small>
								<?php if ($covers !== ''): ?>
									<small class="cell-sub"><?= V::escape($covers) ?></small>
								<?php endif; ?>
								<?php if ($payment['reference'] !== ''): ?>
									<small class="cell-sub">Referencia: <?= V::escape($payment['reference']) ?></small>
								<?php endif; ?>
							</td>
							<td>
								<strong><?= V::escape(V::money($payment['amount'])) ?></strong>
								<?php if ($payment['override_reason'] !== null): ?>
									<small class="cell-sub">Monto cambiado: <?= V::escape($payment['override_reason']) ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?= V::escape(V::money($payment['suggested_amount'])) ?>
								<small class="cell-sub"><?= V::escape($catalog) ?></small>
								<?php if ($credit !== ''): ?>
									<small class="cell-sub"><?= V::escape($credit) ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?= V::escape($payment['actor_name']) ?>
								<small class="cell-sub">Registrado: <?= V::escape(V::date($payment['created_at'])) ?></small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
