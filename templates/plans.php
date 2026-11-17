<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$canEdit = $actor->role === 'superadmin';
?>
<div class="page-heading">
	<div>
		<span class="eyebrow">CATÁLOGO</span>
		<h1>Planes</h1>
		<p>
			Cupos y precios de cada plan, en MXN. Los precios son opcionales: si falta uno, el monto se escribe a mano al
			cobrar.
		</p>
	</div>
	<?php if ($canEdit): ?>
		<a href="/admin/plans/new" class="btn btn-primary">＋ Nuevo plan</a>
	<?php endif; ?>
</div>
<section class="panel">
	<div class="table-responsive">
		<table class="table" id="plan-catalog">
			<thead>
				<tr>
					<th>Plan</th>
					<th>Cupos</th>
					<th>Suscripción (mensual · trimestral · semestral · anual)</th>
					<th>Perpetua</th>
					<th>Mantenimiento anual</th>
					<th>Estado</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($plans as $plan): ?>
					<?php
					$subscriptionPrices = [];
					foreach (['monthly', 'quarterly', 'semiannual', 'annual'] as $term) {
						$subscriptionPrices[] = V::money($plan[LicenseTerms::PRICES[$term]]);
					}
					$archived = $plan['state'] === 'archived';
					?>
					<tr>
						<td>
							<a class="text-link" href="/admin/plans/<?= V::escape($plan['plan_id']) ?>">
								<?= V::escape($plan['display_name']) ?>
							</a>
							<small class="cell-sub"><?= V::escape($plan['plan_id']) ?></small>
						</td>
						<td>
							<?= (int) $plan['cash_registers'] ?> cajas ·
							<?= (int) $plan['order_takers'] ?> comanderos ·
							<?= (int) $plan['kitchen_displays'] ?> KDS
						</td>
						<td><?= V::escape(implode(' · ', $subscriptionPrices)) ?></td>
						<td><?= V::escape(V::money($plan['price_perpetual'])) ?></td>
						<td><?= V::escape(V::money($plan['price_maintenance'])) ?></td>
						<td>
							<span class="tag <?= $archived ? '' : 'tag-green' ?>"><?= $archived ? 'Archivado' : 'Se ofrece' ?></span>
							<small class="cell-sub"><?= (int) $plan['license_count'] ?> licencias</small>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
