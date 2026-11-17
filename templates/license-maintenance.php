<?php
use AdelPos\License\Domain\{CalendarPeriod, Money};
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$zone = $quote['time_zone'];
$dates = $quote['dates'];
$suggested = $quote['suggested'];
$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
$priceNote = 'Precio de mantenimiento anual del plan: ' . ($suggested === null ? '' : Money::format($suggested)) . '.';
if ($license['plan_custom']) {
	$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
} elseif ($suggested === null) {
	$priceNote = 'El plan no tiene precio de mantenimiento. Escribe el monto cobrado.';
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>">← Detalle de la licencia</a>
		<h1 class="mt-3">Comprar mantenimiento</h1>
		<p><?= V::escape($license['customer_name']) ?> · <?= V::escape(V::licenseTerms($license)) ?></p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<section class="panel form-panel" id="maintenance-quote">
			<h2>Cálculo del mantenimiento</h2>
			<dl class="detail-grid">
				<div>
					<dt>Actualizaciones hasta (ahora)</dt>
					<dd><?= V::escape(V::date($license['updates_until'], $zone)) ?></dd>
				</div>
				<div>
					<dt>Fecha de pago</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($quote['paid_at']), $zone)) ?></dd>
				</div>
				<div>
					<dt>Se cuentan desde</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($dates['updates_from']), $zone)) ?></dd>
				</div>
				<div>
					<dt>Actualizaciones hasta (nuevo)</dt>
					<dd data-testid="new-updates-until">
						<?= V::escape(V::date(CalendarPeriod::sql($dates['updates_until']), $zone)) ?>
					</dd>
				</div>
			</dl>
			<div class="note-box">
				<?php if ($dates['from_payment']): ?>
					Las actualizaciones ya habían terminado: los 12 meses se cuentan desde la fecha de pago.
				<?php else: ?>
					Las actualizaciones todavía no terminan: los 12 meses se suman a su fin actual.
				<?php endif; ?>
				La versión instalada sigue funcionando aunque terminen las actualizaciones.
			</div>
		</section>
		<form class="panel form-panel mt-4" method="get" action="/admin/licenses/<?= V::escape($licenseId) ?>/maintenance">
			<h2>Cambiar la fecha de pago</h2>
			<input
				class="form-control"
				type="datetime-local"
				name="paid_at"
				aria-label="Fecha de pago"
				value="<?= V::escape($quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i')) ?>"
			>
			<button class="btn btn-outline-primary mt-3">Volver a calcular</button>
		</form>
	</div>
	<div class="col-xl-4">
		<form
			class="panel form-panel stack-form"
			method="post"
			action="/admin/licenses/<?= V::escape($licenseId) ?>/maintenance"
			data-amount-form
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<input type="hidden" name="paid_at" value="<?= V::escape(CalendarPeriod::utc($quote['paid_at'])) ?>">
			<h2>Confirmar el mantenimiento</h2>
			<p class="small text-secondary mb-0"><?= V::escape($priceNote) ?></p>
			<label>
				Monto cobrado (MXN)
				<input
					class="form-control mt-2"
					name="amount"
					inputmode="decimal"
					maxlength="12"
					pattern="\d{1,8}(\.\d{1,2})?"
					value="<?= V::escape($suggestedText) ?>"
					data-suggested="<?= V::escape($suggestedText) ?>"
					required
				>
			</label>
			<label>
				Motivo del cambio de monto
				<input class="form-control mt-2" name="amount_reason" maxlength="500" disabled>
			</label>
			<label>
				Referencia comercial <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reference" maxlength="190">
			</label>
			<label>
				Nota <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reason" maxlength="500">
			</label>
			<button class="btn btn-primary">Registrar mantenimiento</button>
		</form>
	</div>
</div>
