<?php
use AdelPos\License\Domain\{CalendarPeriod, Money};
use AdelPos\License\Http\View as V;

// Mantenimiento de una perpetua (contrato §5.5; docs/PANEL.md, sección 3.4): 12 meses de actualizaciones.
// Se calcula de inmediato con la fecha de pago actual.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$formUrl = $licenseUrl . '/maintenance';
$zone = $license['time_zone'];
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], ['Mantenimiento', null]];
$paidLocal = is_string($_GET['paid_at'] ?? null) ? $_GET['paid_at'] : '';
$suggestedText = '';
$priceNote = '';
if ($quote !== null) {
	$dates = $quote['dates'];
	$paidLocal = $quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
	$suggested = $quote['suggested'];
	$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
	$priceNote = 'Precio de mantenimiento anual del plan: ' . ($suggested === null ? '' : Money::format($suggested)) . '.';
	if ($license['plan_custom']) {
		$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
	} elseif ($suggested === null) {
		$priceNote = 'El plan no tiene precio de mantenimiento. Escribe el monto cobrado.';
	}
	$newUntil = V::date(CalendarPeriod::sql($dates['updates_until']), $zone);
	$countsFrom = V::date(CalendarPeriod::sql($dates['updates_from']), $zone);
	$paidText = V::date(CalendarPeriod::sql($quote['paid_at']), $zone);
	$suggestedLabel = $suggestedText === '' ? 'Sin sugerencia' : V::money($suggestedText);
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="<?= V::escape($licenseUrl) ?>">← <?= V::escape($license['customer_name']) ?></a>
		<h1 class="mt-3">Comprar mantenimiento</h1>
		<p><?= V::escape(V::licenseTerms($license)) ?> · <?= V::escape($license['plan_name']) ?> · Fechas en <?= V::escape($zone) ?></p>
	</div>
</div>
<?php if ($quoteError !== ''): ?>
	<div class="alert alert-danger" role="alert"><?= V::escape($quoteError) ?></div>
<?php endif; ?>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel" method="get" action="<?= V::escape($formUrl) ?>" id="maintenance-form">
			<h2>Datos del mantenimiento</h2>
			<label class="form-label" for="paid_at">Fecha de pago <span class="optional">vacía: este momento</span></label>
			<input class="form-control" type="datetime-local" id="paid_at" name="paid_at" value="<?= V::escape($paidLocal) ?>">
			<p class="small text-secondary mt-3 mb-0">
				Son 12 meses de actualizaciones, contados desde la fecha posterior entre la fecha de pago y el fin actual
				de las actualizaciones.
			</p>
			<button class="btn btn-outline-primary mt-3">Calcular</button>
		</form>
		<?php if ($quote !== null): ?>
			<section class="panel form-panel mt-4 result-panel" id="maintenance-quote" aria-labelledby="maintenance-result">
				<h2 id="maintenance-result">Resultado</h2>
				<ul class="change-list">
					<li>
						<span>Actualizaciones hasta</span>
						<del><?= V::escape(V::date($license['updates_until'], $zone)) ?></del>
						<strong data-testid="new-updates-until"><?= V::escape($newUntil) ?></strong>
					</li>
				</ul>
				<dl class="detail-grid">
					<div>
						<dt>Se cuentan desde</dt>
						<dd><?= V::escape($countsFrom) ?></dd>
					</div>
					<div>
						<dt>Fecha de pago</dt>
						<dd><?= V::escape($paidText) ?></dd>
					</div>
					<div>
						<dt>Monto sugerido</dt>
						<dd data-testid="suggested"><?= V::escape($suggestedLabel) ?></dd>
					</div>
				</dl>
				<p class="note-box mb-0">
					<?php if ($dates['from_payment']): ?>
						Las actualizaciones ya habían terminado: los 12 meses se cuentan desde la fecha de pago.
					<?php else: ?>
						Las actualizaciones todavía no terminan: los 12 meses se suman a su fin actual.
					<?php endif; ?>
					La versión instalada sigue funcionando aunque terminen las actualizaciones.
				</p>
			</section>
		<?php endif; ?>
	</div>
	<div class="col-xl-4">
		<?php if ($quote !== null): ?>
			<form class="panel form-panel stack-form sticky-summary" method="post" action="<?= V::escape($formUrl) ?>" data-amount-form>
				<?php V::fields($csrf); ?>
				<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
				<input type="hidden" name="paid_at" value="<?= V::escape(CalendarPeriod::utc($quote['paid_at'])) ?>">
				<h2>Confirmar</h2>
				<?php require __DIR__ . '/payment-fields.php'; ?>
				<button class="btn btn-primary">Registrar mantenimiento</button>
			</form>
		<?php endif; ?>
	</div>
</div>
