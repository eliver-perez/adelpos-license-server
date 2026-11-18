<?php
use AdelPos\License\Application\Payments;
use AdelPos\License\Domain\Money;
use AdelPos\License\Http\View as V;

// Resumen (docs/PANEL.md, sección 3.1): lo que el propietario tiene que hacer hoy.
$returnTo = '/admin';
$notice = ($_GET['notice'] ?? '') === 'alert-reviewed' ? 'Alerta marcada como revisada.' : '';
$crumbs = [['Resumen', null]];
$activeTotal = $counts['active']['subscription'] + $counts['active']['perpetual'] + $counts['active']['trial'];
$activeTypes = [
	['Suscripciones', $counts['active']['subscription'], '/admin/licenses?state=active&type=subscription'],
	['Perpetuas', $counts['active']['perpetual'], '/admin/licenses?state=active&type=perpetual'],
	['Pruebas', $counts['active']['trial'], '/admin/licenses?state=active&type=trial'],
];
$metrics = [
	[
		'label' => 'En tolerancia',
		'value' => $counts['grace'],
		'note' => 'Vencidas, dentro de sus días de tolerancia: cobra antes de que terminen',
		'url' => '/admin/licenses?state=grace&sort=expiry_asc',
		'tone' => 'amber',
	],
	[
		'label' => 'Vencidas en los últimos 30 días',
		'value' => $counts['expired_recent'],
		'note' => 'Con la tolerancia agotada: ya no toman pedidos nuevos',
		'url' => '/admin/licenses?state=expired&sort=expiry_desc',
		'tone' => 'red',
	],
	[
		'label' => 'Alertas sin revisar',
		'value' => $counts['alerts'],
		'note' => 'Transferencias bloqueadas o frecuentes',
		'url' => '/admin/alerts?status=open',
		'tone' => 'blue',
	],
];
$dueUrl = '/admin?due=';
$canWrite = $actor->canWrite();
$typeNames = ['subscription' => 'Suscripción', 'trial' => 'Prueba'];
$months = [
	'current' => 'Mes en curso',
	'previous' => 'Mes anterior',
];
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<div class="page-heading">
	<div>
		<h1>Resumen</h1>
		<p>Lo que necesita tu atención hoy, <?= V::escape(V::longDate($now)) ?>.</p>
	</div>
	<?php if ($canWrite): ?>
		<div class="heading-actions">
			<a class="btn btn-outline-secondary" href="/admin/customers/new">Nuevo cliente</a>
			<a class="btn btn-outline-secondary" href="/admin/offline">Importar .licreq</a>
		</div>
	<?php endif; ?>
</div>
<div class="metric-grid" data-testid="dashboard-counts">
	<section class="metric-card tone-green" aria-labelledby="metric-active">
		<span id="metric-active">Licencias activas por tipo</span>
		<strong><?= (int) $activeTotal ?></strong>
		<ul class="metric-breakdown">
			<?php foreach ($activeTypes as [$label, $value, $url]): ?>
				<li>
					<a href="<?= V::escape($url) ?>"><?= V::escape($label) ?></a>
					<b data-testid="active-<?= V::escape(mb_strtolower($label)) ?>"><?= (int) $value ?></b>
				</li>
			<?php endforeach; ?>
		</ul>
		<small>
			<a href="/admin/licenses?state=pending"><?= (int) $counts['pending'] ?> sin activar todavía</a>
		</small>
	</section>
	<?php foreach ($metrics as $metric): ?>
		<a class="metric-card tone-<?= $metric['tone'] ?>" href="<?= V::escape($metric['url']) ?>">
			<span><?= V::escape($metric['label']) ?></span>
			<strong><?= (int) $metric['value'] ?></strong>
			<small><?= V::escape($metric['note']) ?></small>
		</a>
	<?php endforeach; ?>
</div>
<section class="panel mt-4" id="due-soon" aria-labelledby="due-soon-title">
	<div class="panel-heading">
		<div>
			<h2 id="due-soon-title">Por vencer</h2>
			<p>Suscripciones y pruebas que vencen en los próximos <?= (int) $dueDays ?> días. Cobra a tiempo.</p>
		</div>
		<nav class="segmented" aria-label="Días por vencer">
			<?php foreach ([7, 15, 30] as $days): ?>
				<a
					href="<?= $dueUrl . $days ?>#due-soon"
					class="<?= $days === $dueDays ? 'active' : '' ?>"
					<?= $days === $dueDays ? 'aria-current="true"' : '' ?>
				><?= $days ?> días</a>
			<?php endforeach; ?>
		</nav>
	</div>
	<?php if ($due === []): ?>
		<div class="empty-state">
			<h3>Nada vence en los próximos <?= (int) $dueDays ?> días</h3>
			<p>Las suscripciones y las pruebas aparecen aquí cuando se acerca su vencimiento.</p>
		</div>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="due-list">
				<thead>
					<tr>
						<th>Cliente</th>
						<th>Plan</th>
						<th>Tipo</th>
						<th>Vence</th>
						<th><span class="visually-hidden">Acción</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($due as $license): ?>
						<?php
						$expiry = V::dateParts($license['expires_at'], $license['time_zone']);
						$licenseUrl = '/admin/licenses/' . $license['id_text'];
						$isTrial = $license['license_type'] === 'trial';
						$actionUrl = $licenseUrl . ($isTrial ? '/convert' : '/renew');
						?>
						<tr>
							<td data-label="Cliente">
								<a class="row-title" href="<?= V::escape($licenseUrl) ?>"><?= V::escape($license['customer_name']) ?></a>
							</td>
							<td data-label="Plan">
								<?= V::escape($license['plan_name']) ?>
								<small class="cell-sub"><?= V::escape(V::limits($license)) ?></small>
							</td>
							<td data-label="Tipo"><?= V::escape(V::licenseTerms($license)) ?></td>
							<td data-label="Vence">
								<strong><?= V::escape($expiry['date']) ?></strong>
								<small class="cell-sub"><?= V::escape($expiry['zone']) ?></small>
								<?= V::badge(V::daysLeft($license['days_left']), $license['days_left'] <= 2 ? 'amber' : 'gray') ?>
							</td>
							<td class="cell-action">
								<?php if ($canWrite): ?>
									<a class="btn btn-sm btn-outline-primary" href="<?= V::escape($actionUrl) ?>">
										<?= $isTrial ? 'Convertir' : 'Renovar' ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
<div class="row g-4 mt-1">
	<div class="col-xl-5">
		<section class="panel h-100" id="collections" aria-labelledby="collections-title">
			<div class="panel-heading">
				<div>
					<h2 id="collections-title">Cobros del periodo</h2>
					<p>
						Cifras informativas, no contables: la suma del registro de pagos, en MXN. Los meses se cuentan en
						<?= V::escape($collections['time_zone']) ?>.
					</p>
				</div>
			</div>
			<div class="collection-grid">
				<?php foreach ($months as $key => $label): ?>
					<?php $month = $collections[$key]; ?>
					<div class="collection" data-testid="collections-<?= $key ?>">
						<span><?= V::escape($label) ?> · <?= V::escape(V::monthYear($month['from'])) ?></span>
						<strong><?= V::escape(Money::format($month['total'])) ?></strong>
						<small><?= (int) $month['operations'] ?> operaciones</small>
						<ul>
							<?php foreach (Payments::CONCEPTS as $concept => $conceptName): ?>
								<li>
									<span><?= V::escape($conceptName) ?></span>
									<b><?= (int) ($month['by_concept'][$concept] ?? 0) ?></b>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	</div>
	<div class="col-xl-7">
		<section class="panel h-100" id="alerts" aria-labelledby="alerts-title">
			<div class="panel-heading">
				<div>
					<h2 id="alerts-title">Alertas recientes</h2>
					<p>Las más recientes sin revisar.</p>
				</div>
				<a href="/admin/alerts" class="text-link">Ver todas →</a>
			</div>
			<div class="panel-body">
				<?php foreach ($alerts['recent'] as $alert): ?>
					<?php require __DIR__ . '/alert-item.php'; ?>
				<?php endforeach; ?>
				<?php if (!$alerts['recent']): ?>
					<p class="empty-line">No hay alertas sin revisar.</p>
				<?php endif; ?>
			</div>
		</section>
	</div>
</div>
<section class="panel mt-4 activity-panel" aria-labelledby="activity-title">
	<div class="panel-heading">
		<div>
			<h2 id="activity-title">Actividad reciente</h2>
			<p>Las últimas operaciones registradas en la auditoría.</p>
		</div>
		<a href="/admin/audit" class="text-link">Consultar auditoría →</a>
	</div>
	<ol class="activity-list">
		<?php foreach ($events as $event): ?>
			<li>
				<strong><?= V::escape(V::action($event['operation'])) ?></strong>
				<span><?= V::escape($event['actor_name'] ?? 'Sistema o AD-EL POS') ?></span>
				<small><?= V::escape(V::date($event['occurred_at'])) ?></small>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
