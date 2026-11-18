<?php
use AdelPos\License\Domain\{LicenseState, LicenseTerms};
use AdelPos\License\Http\View as V;

// Sección «Resumen» del detalle: condiciones, plan y cupos, fechas, cambio programado, datos comerciales y
// módulos. Espera las variables de license-detail.php.
$scheduled = $license['scheduled'];
$graceEnd = '';
if ($activated && $license['license_type'] === 'subscription') {
	$graceEnd = V::date(
		LicenseState::graceLimit($license['expires_at'], (int) $license['grace_days'])->format('Y-m-d H:i:s.u'),
		$zone,
	);
}
$termNotes = [
	'perpetual' => 'El uso de las versiones adquiridas no vence, aunque termine el periodo de actualizaciones.',
	'subscription' =>
		'Después del vencimiento hay ' .
		(int) $license['grace_days'] .
		' días de tolerancia. Al terminar se puede cobrar y cerrar lo capturado, pero no tomar pedidos nuevos.',
	'trial' =>
		'La prueba no tiene tolerancia. Al vencer se puede cobrar y cerrar lo capturado, pero no tomar pedidos nuevos.',
];
$productFeatures = [];
foreach ($capabilities as $feature) {
	if ($feature['product_id'] === $license['product_id']) {
		$productFeatures[] = $feature;
	}
}
// Lo escrito en los datos comerciales, si el formulario no pasó la validación.
$administrativeForm = $licenseUrl . '/administrative';
$referenceValue = V::old('reference', $license['commercial_reference'], $administrativeForm);
$noteValue = V::old('reason', $license['authorization_note'], $administrativeForm);
$fieldNames = ['commercial_reference' => 'Referencia comercial', 'authorization_note' => 'Nota de autorización'];
$enabledFeatures = [];
foreach ($license['features'] as $current) {
	if ($current['enabled']) {
		$enabledFeatures[] = $current['capability_key'];
	}
}
?>
<div class="row g-4">
	<div class="col-xl-6">
		<section class="panel form-panel h-100" aria-labelledby="terms-title">
			<h2 id="terms-title">Condiciones</h2>
			<dl class="detail-grid">
				<div>
					<dt>Tipo</dt>
					<dd><?= V::escape(V::licenseTerms($license)) ?></dd>
				</div>
				<div>
					<dt>Tolerancia</dt>
					<dd><?= (int) $license['grace_days'] ?> días</dd>
				</div>
				<div>
					<dt>Producto</dt>
					<dd><?= V::escape($license['product_name']) ?></dd>
				</div>
				<div>
					<dt>Computadoras principales</dt>
					<dd>1 a la vez</dd>
				</div>
			</dl>
			<p class="note-box mb-0"><?= V::escape($termNotes[$license['license_type']]) ?></p>
		</section>
	</div>
	<div class="col-xl-6">
		<section class="panel form-panel h-100" aria-labelledby="plan-title">
			<h2 id="plan-title">Plan y cupos</h2>
			<dl class="detail-grid">
				<div class="wide">
					<dt>Plan</dt>
					<dd>
						<?= V::escape($license['plan_name']) ?>
						<?php if ($license['plan_custom']): ?>
							<span class="tag">Personalizada</span>
						<?php endif; ?>
					</dd>
				</div>
				<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
					<div>
						<dt><?= V::escape($label) ?></dt>
						<dd><?= (int) $license[$limit] ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<?php if ($license['plan_custom']): ?>
				<p class="note-box mb-0">
					<strong>Licencia personalizada.</strong> Sus cupos son propios y ya no siguen a su plan: un cambio del
					catálogo no la alcanza.
				</p>
			<?php endif; ?>
		</section>
	</div>
	<div class="col-12">
		<section class="panel form-panel" aria-labelledby="dates-title">
			<h2 id="dates-title">Fechas</h2>
			<dl class="detail-grid detail-grid-wide">
				<div>
					<dt>Inicio del periodo</dt>
					<dd><?= V::escape(V::licenseDate($license, 'period_starts_at')) ?></dd>
				</div>
				<div>
					<dt>Vencimiento</dt>
					<dd><?= V::escape(V::expiry($license)) ?></dd>
				</div>
				<?php if ($graceEnd !== ''): ?>
					<div>
						<dt>Fin de la tolerancia</dt>
						<dd><?= V::escape($graceEnd) ?></dd>
					</div>
				<?php endif; ?>
				<div>
					<dt>Actualizaciones hasta</dt>
					<dd><?= V::escape(V::licenseDate($license, 'updates_until')) ?></dd>
				</div>
				<div>
					<dt>Zona horaria del cliente</dt>
					<dd><?= V::escape($zone) ?></dd>
				</div>
			</dl>
			<p class="text-secondary small mb-0">
				Las fechas de esta licencia se calculan y se muestran en la zona horaria de su cliente.
				<?php if (!$activated): ?>
					Todavía no tiene fechas: empiezan a contar al activarla.
				<?php endif; ?>
			</p>
		</section>
	</div>
	<?php if ($scheduled !== null): ?>
		<div class="col-12">
			<section class="panel form-panel" id="scheduled-change" aria-labelledby="scheduled-title">
				<h2 id="scheduled-title">
					Cambio programado: <?= V::escape($scheduled['plan_name']) ?>
					<?php if ($scheduled['plan_custom']): ?>
						<span class="tag">Personalizada</span>
					<?php endif; ?>
				</h2>
				<p class="mb-2">
					Entra en vigor el <?= V::escape(V::date($scheduled['effective_at'], $zone)) ?>:
					<?= V::escape(V::limits($scheduled)) ?>.
				</p>
				<p class="text-secondary small">
					Hasta entonces la licencia conserva sus cupos. Lo programó <?= V::escape($scheduled['actor_name']) ?>.
				</p>
				<?php if ($canChange): ?>
					<form method="post" action="<?= V::escape($licenseUrl) ?>/scheduled-cancel">
						<?php V::fields($csrf); ?>
						<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
						<button class="btn btn-outline-danger">Cancelar el cambio programado</button>
					</form>
				<?php endif; ?>
			</section>
		</div>
	<?php endif; ?>
	<div class="col-xl-6">
		<section class="panel form-panel h-100" aria-labelledby="commercial-title">
			<h2 id="commercial-title">Datos comerciales</h2>
			<p class="text-secondary small">
				Cambiarlos no modifica el código, el archivo firmado, los derechos, las fechas ni las computadoras.
			</p>
			<?php if ($actor->canWrite()): ?>
				<form class="stack-form" method="post" action="<?= V::escape($administrativeForm) ?>">
					<?php V::fields($csrf); ?>
					<input
						type="hidden"
						name="administrative_version"
						value="<?= (int) $license['administrative_version'] ?>"
					>
					<label>
						Referencia comercial <span class="optional">opcional</span>
						<input
							class="form-control mt-2"
							name="reference"
							maxlength="190"
							value="<?= V::escape($referenceValue) ?>"
						>
					</label>
					<label>
						Nota de autorización <span class="optional">opcional</span>
						<textarea
							class="form-control mt-2"
							name="reason"
							maxlength="500"
							rows="2"
						><?= V::escape($noteValue) ?></textarea>
					</label>
					<div>
						<button class="btn btn-outline-primary">Guardar datos comerciales</button>
					</div>
				</form>
			<?php else: ?>
				<dl class="detail-grid">
					<div class="wide">
						<dt>Referencia comercial</dt>
						<dd><?= V::escape($license['commercial_reference'] ?: 'Sin capturar') ?></dd>
					</div>
					<div class="wide">
						<dt>Nota de autorización</dt>
						<dd><?= V::escape($license['authorization_note'] ?: 'Sin capturar') ?></dd>
					</div>
				</dl>
			<?php endif; ?>
			<?php if ($license['administrative_history']): ?>
				<details class="mt-3">
					<summary>Cambios anteriores de los datos comerciales</summary>
					<ul class="plain-list mt-2">
						<?php foreach ($license['administrative_history'] as $change): ?>
							<li>
								<strong><?= V::escape($fieldNames[$change['field_name']] ?? $change['field_name']) ?></strong>
								<span>
									<?= V::escape($change['old_value'] ?: '(vacío)') ?> →
									<?= V::escape($change['new_value'] ?: '(vacío)') ?>
								</span>
								<small><?= V::escape($change['actor_name']) ?> · <?= V::escape(V::date($change['created_at'])) ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	</div>
	<div class="col-xl-6">
		<section class="panel form-panel h-100" aria-labelledby="features-title">
			<h2 id="features-title">Módulos</h2>
			<?php if ($productFeatures === []): ?>
				<p class="text-secondary mb-0">
					Este producto no tiene módulos. La licencia firma <code>features: {}</code>, como pide el contrato.
				</p>
			<?php else: ?>
				<form method="post" action="<?= V::escape($licenseUrl) ?>/features">
					<?php V::fields($csrf); ?>
					<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
					<fieldset <?= $canChange ? '' : 'disabled' ?>>
						<legend class="visually-hidden">Módulos habilitados</legend>
						<div class="feature-grid">
							<?php foreach ($productFeatures as $feature): ?>
								<label class="feature-choice">
									<input
										class="form-check-input"
										type="checkbox"
										name="features[]"
										value="<?= V::escape($feature['capability_key']) ?>"
										data-requires="<?= V::escape($feature['requires_keys']) ?>"
										<?= in_array($feature['capability_key'], $enabledFeatures, true) ? 'checked' : '' ?>
									>
									<span><?= V::escape($feature['display_name']) ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ($canChange): ?>
							<label class="form-label mt-3" for="feature_reason">Motivo del cambio</label>
							<input class="form-control" name="reason" id="feature_reason" maxlength="500" required>
							<button class="btn btn-outline-primary mt-3">Guardar módulos</button>
						<?php endif; ?>
					</fieldset>
				</form>
			<?php endif; ?>
		</section>
	</div>
</div>
