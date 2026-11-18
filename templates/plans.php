<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

// Catálogo de planes (contrato §11.1; docs/PANEL.md, sección 3.6): cupos y un precio por columna, en MXN.
$canEdit = $actor->role === 'superadmin';
$crumbs = [['Planes', null]];
$priceColumns = [
	'monthly' => 'Mensual',
	'quarterly' => 'Trimestral',
	'semiannual' => 'Semestral',
	'annual' => 'Anual',
	'perpetual' => 'Perpetua',
	'maintenance' => 'Mantenimiento',
];
?>
<div class="page-heading">
	<div>
		<h1>Planes</h1>
		<p>
			Cupos y precios de cada plan, en MXN y sin IVA. Los precios son opcionales: si falta uno, el monto se escribe
			a mano al cobrar.
		</p>
	</div>
	<?php if ($canEdit): ?>
		<a href="/admin/plans/new" class="btn btn-primary">Nuevo plan</a>
	<?php endif; ?>
</div>
<section class="panel">
	<?php if ($plans === []): ?>
		<div class="empty-state">
			<h3>El catálogo está vacío</h3>
			<p>Crea un plan para poder emitir licencias.</p>
		</div>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack" id="plan-catalog">
				<thead>
					<tr>
						<th>Plan</th>
						<th>Cupos</th>
						<?php foreach ($priceColumns as $label): ?>
							<th class="text-end"><?= V::escape($label) ?></th>
						<?php endforeach; ?>
						<th>Licencias</th>
						<th>Estado</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($plans as $plan): ?>
						<?php $archived = $plan['state'] === 'archived'; ?>
						<tr>
							<td data-label="Plan">
								<a class="row-title" href="/admin/plans/<?= V::escape($plan['plan_id']) ?>">
									<?= V::escape($plan['display_name']) ?>
								</a>
								<small class="cell-sub mono"><?= V::escape($plan['plan_id']) ?></small>
							</td>
							<td data-label="Cupos"><?= V::escape(V::limits($plan)) ?></td>
							<?php foreach ($priceColumns as $term => $label): ?>
								<td data-label="<?= V::escape($label) ?>" class="text-end">
									<?= V::escape(V::money($plan[LicenseTerms::PRICES[$term]])) ?>
								</td>
							<?php endforeach; ?>
							<td data-label="Licencias"><?= (int) $plan['license_count'] ?></td>
							<td data-label="Estado">
								<?= V::badge($archived ? 'Archivado' : 'Se ofrece', $archived ? 'gray' : 'green') ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
