<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$canEdit = $actor->role === 'superadmin';
$isNew = $plan === null;
$action = $isNew ? '/admin/plans/create' : '/admin/plans/' . V::escape($plan['plan_id']);
$archived = !$isNew && $plan['state'] === 'archived';
$notices = [
	'created' => 'Plan creado. Ya se ofrece al emitir licencias.',
	'archived' => 'Plan archivado. Ya no se ofrece al emitir; las licencias que lo siguen no cambian.',
	'restored' => 'El plan vuelve a ofrecerse al emitir licencias.',
];
$noticeKey = is_string($_GET['notice'] ?? null) ? $_GET['notice'] : '';
$notice = $notices[$noticeKey] ?? '';
// Resultado de una propagación: cuántas licencias se actualizaron y cuántas revisiones se firmaron.
$updated = is_string($_GET['updated'] ?? null) && ctype_digit($_GET['updated']) ? (int) $_GET['updated'] : null;
$signed = is_string($_GET['signed'] ?? null) && ctype_digit($_GET['signed']) ? (int) $_GET['signed'] : null;
$priceLabels = [
	'monthly' => 'Suscripción mensual',
	'quarterly' => 'Suscripción trimestral',
	'semiannual' => 'Suscripción semestral',
	'annual' => 'Suscripción anual',
	'perpetual' => 'Licencia perpetua',
	'maintenance' => 'Mantenimiento anual de una perpetua',
];
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<?php if ($noticeKey === 'saved' && $updated !== null && $signed !== null): ?>
	<div class="alert alert-success" role="status" id="propagation-result">
		Plan guardado. Licencias actualizadas: <?= $updated ?>. Revisiones firmadas: <?= $signed ?>.
	</div>
<?php endif; ?>
<div class="page-heading">
	<div>
		<a href="/admin/plans" class="text-link">← Planes</a>
		<h1 class="mt-3"><?= V::escape($plan['display_name'] ?? 'Nuevo plan') ?></h1>
		<p>
			La licencia firma números, no paquetes: un cambio aquí no modifica ninguna licencia existente, salvo que elijas
			aplicar los aumentos a las que siguen este plan.
		</p>
	</div>
	<?php if (!$isNew): ?>
		<span class="tag large-tag <?= $archived ? '' : 'tag-green' ?>"><?= $archived ? 'Archivado' : 'Se ofrece' ?></span>
	<?php endif; ?>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel" method="post" action="<?= $action ?>" id="plan-form">
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) ($plan['row_version'] ?? 0) ?>">
			<fieldset <?= $canEdit ? '' : 'disabled' ?>>
				<div class="row g-3">
					<div class="col-md-5">
						<label class="form-label" for="plan_id">Identificador</label>
						<input
							class="form-control"
							id="plan_id"
							name="plan_id"
							value="<?= V::escape($plan['plan_id'] ?? '') ?>"
							pattern="[a-z][a-z0-9_]{1,63}"
							maxlength="64"
							<?= $isNew ? '' : 'readonly' ?>
							required
						>
						<div class="form-text">Se firma en la licencia y no cambia.</div>
					</div>
					<div class="col-md-7">
						<label class="form-label" for="display_name">Nombre</label>
						<input
							class="form-control"
							id="display_name"
							name="display_name"
							value="<?= V::escape($plan['display_name'] ?? '') ?>"
							maxlength="190"
							required
						>
						<div class="form-text">Las licencias firmadas muestran el nombre nuevo cuando se vuelven a firmar.</div>
					</div>
				</div>
				<h2 class="h5 mt-4">Cupos</h2>
				<div class="row g-3">
					<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
						<div class="col-md-4">
							<label class="form-label" for="<?= $limit ?>"><?= V::escape($label) ?></label>
							<input
								class="form-control"
								type="number"
								id="<?= $limit ?>"
								name="<?= $limit ?>"
								min="0"
								max="<?= LicenseTerms::MAX_LIMIT ?>"
								step="1"
								value="<?= (int) ($plan[$limit] ?? 0) ?>"
								required
							>
						</div>
					<?php endforeach; ?>
				</div>
				<h2 class="h5 mt-4">Precios en MXN <span class="optional">opcionales</span></h2>
				<div class="row g-3">
					<?php foreach (LicenseTerms::PRICES as $term => $column): ?>
						<div class="col-md-4">
							<label class="form-label" for="<?= $column ?>"><?= V::escape($priceLabels[$term]) ?></label>
							<input
								class="form-control"
								id="<?= $column ?>"
								name="<?= $column ?>"
								inputmode="decimal"
								maxlength="12"
								pattern="\d{1,8}(\.\d{1,2})?"
								value="<?= V::escape($plan[$column] ?? '') ?>"
							>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="form-text">Sin IVA ni facturas: es el monto que se sugiere cobrar.</div>
				<?php if (!$isNew): ?>
					<h2 class="h5 mt-4">¿A qué licencias aplican los cupos nuevos?</h2>
					<div class="stack-form">
						<label class="choice-card">
							<input type="radio" name="apply_to" value="new" checked>
							<span>
								<strong>Solo a licencias nuevas</strong>
								<small>Las licencias existentes conservan sus números.</small>
							</span>
						</label>
						<label class="choice-card">
							<input type="radio" name="apply_to" value="existing">
							<span>
								<strong>También a las existentes que siguen este plan</strong>
								<small>
									Solo los aumentos, cupo por cupo, en licencias no personalizadas: perpetuas, licencias sin
									activar, y suscripciones y pruebas vigentes o en tolerancia. Se firma una revisión nueva para
									cada activación vigente. Una reducción nunca alcanza a una licencia existente.
								</small>
							</span>
						</label>
					</div>
					<div class="mt-3">
						<label class="form-label" for="reason">Motivo del cambio <span class="optional">opcional</span></label>
						<input class="form-control" id="reason" name="reason" maxlength="500">
					</div>
				<?php endif; ?>
				<?php if ($canEdit): ?>
					<div class="form-footer">
						<button class="btn btn-primary" type="submit"><?= $isNew ? 'Crear plan' : 'Guardar plan' ?></button>
					</div>
				<?php endif; ?>
			</fieldset>
		</form>
	</div>
	<?php if (!$isNew && $canEdit): ?>
		<div class="col-xl-4">
			<section class="panel form-panel">
				<h2><?= $archived ? 'Volver a ofrecer' : 'Archivar' ?></h2>
				<p class="text-secondary small">
					<?php if ($archived): ?>
						El plan volverá a aparecer al emitir licencias y al cambiar de plan.
					<?php else: ?>
						Un plan en uso no se borra: se archiva y deja de ofrecerse al emitir. Las licencias que lo siguen no
						cambian y se pueden renovar.
					<?php endif; ?>
				</p>
				<form method="post" action="/admin/plans/<?= V::escape($plan['plan_id']) ?>/state">
					<?php V::fields($csrf); ?>
					<input type="hidden" name="version" value="<?= (int) $plan['row_version'] ?>">
					<input type="hidden" name="state" value="<?= $archived ? 'active' : 'archived' ?>">
					<button class="btn btn-outline-primary"><?= $archived ? 'Volver a ofrecer el plan' : 'Archivar el plan' ?></button>
				</form>
			</section>
		</div>
	<?php endif; ?>
</div>
