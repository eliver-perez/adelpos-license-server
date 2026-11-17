<?php
use AdelPos\License\Domain\{CalendarPeriod, Money};
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$zone = $quote['time_zone'];
$dates = $quote['dates'];
$graceLimit = (new \DateTimeImmutable($license['expires_at'], new \DateTimeZone('UTC')))->modify(
	'+' . (int) $license['grace_days'] * 24 . ' hours',
);
$suggested = $quote['suggested'];
$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
$priceNote = 'Precio del catálogo para esta duración: ' . ($suggested === null ? '' : Money::format($suggested)) . '.';
if ($license['plan_custom']) {
	$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
} elseif ($suggested === null) {
	$priceNote = 'El plan no tiene precio para esta duración. Escribe el monto cobrado.';
}
$periods = [
	'monthly' => 'Mensual (1 mes)',
	'quarterly' => 'Trimestral (3 meses)',
	'semiannual' => 'Semestral (6 meses)',
	'annual' => 'Anual (12 meses)',
];
$paidLocal = $quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>">← Detalle de la licencia</a>
		<h1 class="mt-3">Renovar suscripción</h1>
		<p><?= V::escape($license['customer_name']) ?> · <?= V::escape($license['plan_name']) ?></p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<section class="panel form-panel" id="renewal-quote">
			<h2>Cálculo de la renovación</h2>
			<dl class="detail-grid">
				<div>
					<dt>Vencimiento actual</dt>
					<dd><?= V::escape(V::date($license['expires_at'], $zone)) ?></dd>
				</div>
				<div>
					<dt>Fin de la tolerancia</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($graceLimit), $zone)) ?></dd>
				</div>
				<div>
					<dt>Fecha de pago</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($quote['paid_at']), $zone)) ?></dd>
				</div>
				<div>
					<dt>Duración</dt>
					<dd><?= V::escape($periods[$quote['billing_period']]) ?></dd>
				</div>
				<div>
					<dt>Inicio del periodo nuevo</dt>
					<dd><?= V::escape(V::date(CalendarPeriod::sql($dates['period_starts_at']), $zone)) ?></dd>
				</div>
				<div>
					<dt>Vencimiento nuevo</dt>
					<dd data-testid="new-expiry"><?= V::escape(V::date(CalendarPeriod::sql($dates['expires_at']), $zone)) ?></dd>
				</div>
			</dl>
			<div class="note-box">
				<?php if ($dates['in_time']): ?>
					Pagó antes de que terminara la tolerancia: el periodo nuevo empieza en el vencimiento actual y conserva
					su día y su hora.
				<?php else: ?>
					Pagó después de que terminó la tolerancia: el periodo nuevo empieza en la fecha de pago, y su día y su
					hora pasan a ser los del pago. Los días de tolerancia no se cobran.
				<?php endif; ?>
			</div>
			<p class="small text-secondary mt-3 mb-0">
				La licencia conserva sus cupos al renovar. Fechas en la zona del cliente (<?= V::escape($zone) ?>).
			</p>
		</section>
		<form class="panel form-panel mt-4" method="get" action="/admin/licenses/<?= V::escape($licenseId) ?>/renew">
			<h2>Cambiar la duración o la fecha de pago</h2>
			<div class="row g-3">
				<div class="col-md-6">
					<label class="form-label" for="quote_billing_period">Duración</label>
					<select class="form-select" id="quote_billing_period" name="billing_period">
						<?php foreach ($periods as $value => $label): ?>
							<option value="<?= $value ?>" <?= $value === $quote['billing_period'] ? 'selected' : '' ?>>
								<?= V::escape($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="quote_paid_at">Fecha de pago</label>
					<input
						class="form-control"
						type="datetime-local"
						id="quote_paid_at"
						name="paid_at"
						value="<?= V::escape($paidLocal) ?>"
					>
				</div>
			</div>
			<button class="btn btn-outline-primary mt-3">Volver a calcular</button>
		</form>
	</div>
	<div class="col-xl-4">
		<form
			class="panel form-panel stack-form"
			method="post"
			action="/admin/licenses/<?= V::escape($licenseId) ?>/renew"
			data-amount-form
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<input type="hidden" name="billing_period" value="<?= V::escape($quote['billing_period']) ?>">
			<input type="hidden" name="paid_at" value="<?= V::escape(CalendarPeriod::utc($quote['paid_at'])) ?>">
			<h2>Confirmar la renovación</h2>
			<p class="small text-secondary mb-0"><?= V::escape($priceNote) ?></p>
			<label>
				Monto cobrado (MXN)
				<input
					class="form-control mt-2"
					name="amount"
					id="amount"
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
				<input class="form-control mt-2" name="amount_reason" id="amount_reason" maxlength="500" disabled>
			</label>
			<label>
				Referencia comercial <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reference" maxlength="190">
			</label>
			<label>
				Nota <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reason" maxlength="500">
			</label>
			<p class="small text-secondary mb-0">
				Se firmará una revisión nueva. Las computadoras en línea la reciben al consultar; las que no tienen internet,
				con una solicitud de renovación o con la descarga del archivo .lic.
			</p>
			<button class="btn btn-primary">Registrar renovación</button>
		</form>
	</div>
</div>
