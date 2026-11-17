<?php
use AdelPos\License\Domain\{CalendarPeriod, LicenseTerms, Money};
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$zone = $quote['time_zone'];
$terms = $quote['terms'];
$target = $quote['target'];
$period = $quote['period'];
$suggested = $quote['suggested'];
$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
$priceNote = 'Precio del catálogo: ' . ($suggested === null ? '' : Money::format($suggested)) . '. No hay bonificación.';
if ($target['custom']) {
	$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
} elseif ($suggested === null) {
	$priceNote = 'El plan no tiene precio para este tipo de licencia. Escribe el monto cobrado.';
}
$newTerms = V::licenseTerms($terms);
$limitsText = [];
foreach (LicenseTerms::LIMITS as $limit => $label) {
	$limitsText[] = $label . ': ' . $target['limits'][$limit];
}
$hidden = [
	'license_type' => $terms['license_type'],
	'billing_period' => $terms['billing_period'] ?? '',
	'updates_months' => $terms['updates_months'] === null ? '' : (string) $terms['updates_months'],
	'plan_id' => $target['plan']['plan_id'],
	'paid_at' => CalendarPeriod::utc($quote['paid_at']),
];
foreach (LicenseTerms::LIMITS as $limit => $label) {
	$hidden[$limit] = (string) $target['limits'][$limit];
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>">← Detalle de la licencia</a>
		<h1 class="mt-3">Convertir la prueba en licencia de pago</h1>
		<p><?= V::escape($license['customer_name']) ?> · <?= V::escape(V::licenseTerms($license)) ?></p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<section class="panel form-panel" id="conversion-quote">
			<h2>Cálculo de la conversión</h2>
			<dl class="detail-grid">
				<div>
					<dt>Vencimiento de la prueba</dt>
					<dd><?= V::escape(V::expiry($license)) ?></dd>
				</div>
				<div>
					<dt>Fecha de pago</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($quote['paid_at']), $zone)) ?></dd>
				</div>
				<div>
					<dt>Tipo nuevo</dt>
					<dd><?= V::escape($newTerms) ?></dd>
				</div>
				<div>
					<dt>Plan</dt>
					<dd>
						<?= V::escape($target['plan']['display_name']) ?>
						<?php if ($target['custom']): ?>
							<span class="tag">Personalizada</span>
						<?php endif; ?>
					</dd>
				</div>
				<div class="wide">
					<dt>Cupos</dt>
					<dd><?= V::escape(implode(' · ', $limitsText)) ?></dd>
				</div>
				<?php if ($period !== []): ?>
					<div>
						<dt>Inicio del periodo nuevo</dt>
						<dd><?= V::escape(V::date(CalendarPeriod::sql($period['period_starts_at']), $zone)) ?></dd>
					</div>
					<div>
						<dt>Vencimiento nuevo</dt>
						<dd data-testid="new-expiry">
							<?php if ($period['expires_at'] === null): ?>
								Sin vencimiento
							<?php else: ?>
								<?= V::escape(V::date(CalendarPeriod::sql($period['expires_at']), $zone)) ?>
							<?php endif; ?>
						</dd>
					</div>
					<div>
						<dt>Actualizaciones hasta</dt>
						<dd><?= V::escape(V::date(CalendarPeriod::sql($period['updates_until']), $zone)) ?></dd>
					</div>
				<?php endif; ?>
			</dl>
			<div class="note-box">
				<?php if ($period !== []): ?>
					La prueba ya está activada: el periodo nuevo empieza en la fecha de pago y los días de prueba que
					faltaban no se acumulan. La computadora recibe una revisión nueva de su misma activación, sin
					reinstalar.
				<?php else: ?>
					La prueba no está activada: cambian sus condiciones y sus fechas se fijan al activarla.
				<?php endif; ?>
			</div>
		</section>
	</div>
	<div class="col-xl-4">
		<form
			class="panel form-panel stack-form"
			method="post"
			action="/admin/licenses/<?= V::escape($licenseId) ?>/convert"
			data-amount-form
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<?php foreach ($hidden as $name => $value): ?>
				<input type="hidden" name="<?= V::escape($name) ?>" value="<?= V::escape($value) ?>">
			<?php endforeach; ?>
			<h2>Confirmar la conversión</h2>
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
			<button class="btn btn-primary">Registrar conversión</button>
		</form>
	</div>
</div>
