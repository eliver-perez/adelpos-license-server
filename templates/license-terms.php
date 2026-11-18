<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$plans = $quote['plans'];
$types = ['subscription' => 'Suscripción', 'perpetual' => 'Perpetua', 'trial' => 'Prueba'];
// El tipo se elige primero: el formulario muestra solo la condición de ese tipo.
$type = is_string($_GET['license_type'] ?? null) && isset($types[$_GET['license_type']])
	? $_GET['license_type']
	: $license['license_type'];
$periods = [
	'monthly' => 'Mensual',
	'quarterly' => 'Trimestral',
	'semiannual' => 'Semestral',
	'annual' => 'Anual',
];
$durations = ['15d' => '15 días', '1m' => '1 mes', '3m' => '3 meses'];
$months = ['0' => 'Sin actualizaciones', '6' => '6 meses', '12' => '12 meses'];
$currentMonths = (string) ($license['updates_months'] ?? '6');
$licenseUrl = '/admin/licenses/' . $licenseId;
$formUrl = $licenseUrl . '/terms';
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], ['Periodo inicial', null]];
$selectedPlan = V::old('plan_id', $license['plan_id'], $formUrl);
$limitValues = [];
foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
	$limitValues[$limit] = V::old($limit, '', $formUrl);
}
$noteValue = V::old('reason', '', $formUrl);
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>">← Detalle de la licencia</a>
		<h1 class="mt-3">Editar el periodo inicial</h1>
		<p>
			<?= V::escape($license['customer_name']) ?> · <?= V::escape(V::licenseTerms($license)) ?>. La licencia
			todavía no se activa: no hay fechas que mover.
		</p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel stack-form mb-4" method="get" action="/admin/licenses/<?= V::escape($licenseId) ?>/terms">
			<h2>Tipo de licencia</h2>
			<label class="form-label" for="terms_type">Tipo</label>
			<select class="form-select" id="terms_type" name="license_type">
				<?php foreach ($types as $value => $label): ?>
					<option value="<?= $value ?>" <?= $value === $type ? 'selected' : '' ?>><?= V::escape($label) ?></option>
				<?php endforeach; ?>
			</select>
			<button class="btn btn-outline-primary">Cambiar el tipo</button>
		</form>
		<form
			class="panel form-panel stack-form"
			id="terms-form"
			method="post"
			action="/admin/licenses/<?= V::escape($licenseId) ?>/terms"
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<input type="hidden" name="license_type" value="<?= V::escape($type) ?>">
			<h2><?= V::escape($types[$type]) ?></h2>
			<?php if ($type === 'subscription'): ?>
				<label>
					Periodo de la suscripción
					<select class="form-select mt-2" name="billing_period">
						<?php foreach ($periods as $value => $label): ?>
							<option value="<?= $value ?>" <?= $value === $license['billing_period'] ? 'selected' : '' ?>>
								<?= V::escape($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php elseif ($type === 'trial'): ?>
				<label>
					Duración de la prueba
					<select class="form-select mt-2" name="trial_duration">
						<?php foreach ($durations as $value => $label): ?>
							<option value="<?= $value ?>" <?= $value === $license['trial_duration'] ? 'selected' : '' ?>>
								<?= V::escape($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php else: ?>
				<label>
					Actualizaciones incluidas
					<select class="form-select mt-2" name="updates_months">
						<?php foreach ($months as $value => $label): ?>
							<option value="<?= $value ?>" <?= (string) $value === $currentMonths ? 'selected' : '' ?>>
								<?= V::escape($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<?php require __DIR__ . '/target-fields.php'; ?>
			<label>
				Nota <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reason" maxlength="500" value="<?= V::escape($noteValue) ?>">
			</label>
			<p class="small text-secondary mb-0">
				Editar el periodo inicial no registra un pago. Si el cliente paga una diferencia, anótala en la referencia
				comercial de la licencia.
			</p>
			<button class="btn btn-primary">Guardar el periodo inicial</button>
		</form>
	</div>
</div>
