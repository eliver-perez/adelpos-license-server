<?php
use AdelPos\License\Application\Payments;
use AdelPos\License\Http\View as V;

// Detalle del cliente (docs/PANEL.md, sección 3.5): sus datos, todas sus licencias con su estado y su
// historial de pagos. Las fechas van en su zona horaria.
$customerId = $customer['id_text'];
$zone = $customer['time_zone'];
$isActive = $customer['state'] === 'active';
$crumbs = [['Clientes', '/admin/customers'], [$customer['display_name'], null]];
$notices = [
	'saved' => 'Datos del cliente guardados.',
	'created' => 'Cliente registrado. Ya puedes emitirle una licencia.',
];
$noticeKey = is_string($_GET['notice'] ?? null) ? $_GET['notice'] : '';
$notice = $notices[$noticeKey] ?? '';
$facts = [
	['Razón social', $customer['legal_name'] ?: 'Sin capturar'],
	['RFC', $customer['tax_id'] ?: 'Sin capturar'],
	['Zona horaria', $zone],
	['Contacto', $customer['contact_name']],
	['Correo', $customer['email'] ?: '—'],
	['Teléfono', $customer['phone'] ?: '—'],
];
$paymentRows = [];
foreach ($payments as $payment) {
	$paymentRows[] = [
		'paid_at' => V::dateParts($payment['paid_at'], $zone),
		'concept' => V::paymentConcept($payment['concept']),
		'term' => Payments::termLabel($payment['term']) . ' · ' . $payment['plan_id'],
		'license_url' => '/admin/licenses/' . $payment['license_text'] . '?section=payments',
		'license' => V::licenseType($payment['license_type']) . ' ' . V::shortId($payment['license_text']),
		'amount' => V::money($payment['amount']),
		'reason' => $payment['override_reason'],
		'credit' => $payment['credit_amount'] === '0.00' ? '—' : V::money($payment['credit_amount']),
	];
}
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<section class="panel record-header" aria-labelledby="customer-title">
	<div class="record-title">
		<div>
			<h1 id="customer-title"><?= V::escape($customer['display_name']) ?></h1>
			<p>Cliente desde el <?= V::escape(V::date($customer['created_at'], $zone)) ?></p>
		</div>
		<?= V::badge($isActive ? 'Activo' : 'Archivado', $isActive ? 'green' : 'gray') ?>
	</div>
	<dl class="record-facts" data-testid="customer-facts">
		<?php foreach ($facts as [$label, $value]): ?>
			<div>
				<dt><?= V::escape($label) ?></dt>
				<dd><?= V::escape($value) ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<?php if ($actor->canWrite()): ?>
		<div class="action-bar">
			<?php if ($isActive): ?>
				<a class="btn btn-primary" href="/admin/licenses/new?customer_id=<?= V::escape($customerId) ?>">
					Emitir licencia para este cliente
				</a>
			<?php endif; ?>
			<a class="btn btn-outline-primary" href="/admin/customers/<?= V::escape($customerId) ?>/edit">Editar datos</a>
		</div>
	<?php endif; ?>
</section>
<section class="panel mt-4" id="customer-licenses" aria-labelledby="customer-licenses-title">
	<div class="panel-heading">
		<div>
			<h2 id="customer-licenses-title">Licencias</h2>
			<p>Todas las licencias del cliente, con su estado. Fechas en <?= V::escape($zone) ?>.</p>
		</div>
	</div>
	<?php if ($licenses === []): ?>
		<div class="empty-state">
			<h3>Este cliente todavía no tiene licencias</h3>
			<?php if ($actor->canWrite() && $isActive): ?>
				<p>Emite la primera.</p>
				<a class="btn btn-primary" href="/admin/licenses/new?customer_id=<?= V::escape($customerId) ?>">Emitir licencia</a>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack">
				<thead>
					<tr>
						<th>Licencia</th>
						<th>Plan y cupos</th>
						<th>Estado</th>
						<th>Vencimiento</th>
						<th>Computadora activa</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($licenses as $license): ?>
						<?php
						$expiry = $license['license_type'] === 'perpetual'
							? 'Sin vencimiento'
							: V::licenseDate($license, 'expires_at');
						?>
						<tr>
							<td data-label="Licencia">
								<a class="row-title" href="/admin/licenses/<?= V::escape($license['id_text']) ?>">
									<?= V::escape(V::licenseTerms($license)) ?>
								</a>
								<small class="cell-sub mono"><?= V::escape($license['id_text']) ?></small>
							</td>
							<td data-label="Plan y cupos">
								<?= V::escape($license['plan_name']) ?>
								<?php if ($license['plan_custom']): ?>
									<span class="tag">Personalizada</span>
								<?php endif; ?>
								<small class="cell-sub"><?= V::escape(V::limits($license)) ?></small>
							</td>
							<td data-label="Estado"><?= V::stateBadge($license['state'], $license['license_type']) ?></td>
							<td data-label="Vencimiento"><?= V::escape($expiry) ?></td>
							<td data-label="Computadora activa" class="mono">
								<?= V::escape($license['active_server'] === null ? 'Ninguna' : V::shortId($license['active_server'])) ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
<section class="panel mt-4" id="customer-payments" aria-labelledby="customer-payments-title">
	<div class="panel-heading">
		<div>
			<h2 id="customer-payments-title">Pagos</h2>
			<p>De todas sus licencias, del más reciente al más antiguo, en MXN.</p>
		</div>
	</div>
	<?php if ($paymentRows === []): ?>
		<p class="empty-line">Sin pagos registrados.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack">
				<thead>
					<tr>
						<th>Fecha de pago</th>
						<th>Concepto</th>
						<th>Licencia</th>
						<th>Cobrado</th>
						<th>Bonificación</th>
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
							</td>
							<td data-label="Licencia">
								<a class="text-link" href="<?= V::escape($row['license_url']) ?>"><?= V::escape($row['license']) ?></a>
							</td>
							<td data-label="Cobrado">
								<strong><?= V::escape($row['amount']) ?></strong>
								<?php if ($row['reason'] !== null): ?>
									<small class="cell-sub">Monto cambiado: <?= V::escape($row['reason']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="Bonificación"><?= V::escape($row['credit']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
