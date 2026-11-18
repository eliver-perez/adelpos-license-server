<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

// Alta o edición de un plan del catálogo (contrato §11.1; docs/PANEL.md, sección 3.6). Al editar, la
// propagación a las licencias existentes se explica con un ejemplo y con el número de licencias que
// cambiarían, que el navegador recalcula mientras se escriben los cupos (antes de guardar).
$canEdit = $actor->role === 'superadmin';
$isNew = $plan === null;
$formUrl = $isNew ? '/admin/plans/create' : '/admin/plans/' . $plan['plan_id'];
$archived = !$isNew && $plan['state'] === 'archived';
$crumbs = [['Planes', '/admin/plans'], [$plan['display_name'] ?? 'Nuevo plan', null]];
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
$values = [
	'plan_id' => V::old('plan_id', $plan['plan_id'] ?? '', $formUrl),
	'display_name' => V::old('display_name', $plan['display_name'] ?? '', $formUrl),
	'reason' => V::old('reason', '', $formUrl),
];
foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
	$values[$limit] = V::old($limit, (string) (int) ($plan[$limit] ?? 0), $formUrl);
}
foreach (LicenseTerms::PRICES as $column) {
	$values[$column] = V::old($column, $plan[$column] ?? '', $formUrl);
}
$applyExisting = V::old('apply_to', 'new', $formUrl) === 'existing';
// Datos para el conteo previo: los cupos actuales del plan y las licencias que la propagación alcanzaría.
$current = [];
foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
	$current[$limit] = (int) ($plan[$limit] ?? 0);
}
$followers = 0;
$withComputer = 0;
foreach ($propagation ?? [] as $group) {
	$followers += $group['licenses'];
	$withComputer += $group['active'];
}
$cashExample = $current['cash_registers'];
$groupsJson = json_encode($propagation ?? [], JSON_THROW_ON_ERROR);
$currentJson = json_encode($current, JSON_THROW_ON_ERROR);
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
			La licencia firma números, no paquetes: un cambio aquí no modifica ninguna licencia existente, salvo que
			elijas aplicar los aumentos a las que siguen este plan.
		</p>
	</div>
	<?php if (!$isNew): ?>
		<?= V::badge($archived ? 'Archivado' : 'Se ofrece', $archived ? 'gray' : 'green') ?>
	<?php endif; ?>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel" method="post" action="<?= V::escape($formUrl) ?>" id="plan-form">
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) ($plan['row_version'] ?? 0) ?>">
			<fieldset <?= $canEdit ? '' : 'disabled' ?>>
				<legend class="visually-hidden">Datos del plan</legend>
				<div class="section-heading">
					<span>01</span>
					<h2>Nombre</h2>
				</div>
				<div class="row g-3">
					<div class="col-md-5">
						<label class="form-label" for="plan_id">Identificador</label>
						<input
							class="form-control"
							id="plan_id"
							name="plan_id"
							value="<?= V::escape($values['plan_id']) ?>"
							pattern="[a-z][a-z0-9_]{1,63}"
							maxlength="64"
							<?= $isNew ? '' : 'readonly' ?>
							required
						>
						<div class="form-text">Minúsculas, números y guiones bajos. Se firma en la licencia y no cambia.</div>
					</div>
					<div class="col-md-7">
						<label class="form-label" for="display_name">Nombre</label>
						<input
							class="form-control"
							id="display_name"
							name="display_name"
							value="<?= V::escape($values['display_name']) ?>"
							maxlength="190"
							required
						>
						<div class="form-text">Las licencias muestran el nombre nuevo cuando se vuelven a firmar.</div>
					</div>
				</div>
				<div class="section-heading mt-5">
					<span>02</span>
					<h2>Cupos</h2>
				</div>
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
								value="<?= V::escape($values[$limit]) ?>"
								data-plan-limit
								required
							>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="section-heading mt-5">
					<span>03</span>
					<h2>Precios en MXN</h2>
				</div>
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
								placeholder="Sin precio"
								value="<?= V::escape($values[$column]) ?>"
							>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="form-text">
					Todos son opcionales. Sin IVA ni facturas: es el monto que se sugiere cobrar. Sin precio, el monto se
					escribe a mano al cobrar.
				</div>
				<?php if (!$isNew): ?>
					<div class="section-heading mt-5">
						<span>04</span>
						<h2>¿A qué licencias aplican los cupos nuevos?</h2>
					</div>
					<div class="stack-form">
						<label class="choice-card">
							<input type="radio" name="apply_to" value="new" <?= $applyExisting ? '' : 'checked' ?>>
							<span>
								<strong>Solo a licencias nuevas</strong>
								<small>Las licencias existentes conservan sus números.</small>
							</span>
						</label>
						<label class="choice-card">
							<input type="radio" name="apply_to" value="existing" <?= $applyExisting ? 'checked' : '' ?>>
							<span>
								<strong>También a las existentes que siguen este plan</strong>
								<small>
									Solo los aumentos, cupo por cupo, en licencias no personalizadas: perpetuas, licencias sin
									activar, y suscripciones y pruebas vigentes o en tolerancia. Se firma una revisión nueva para
									cada computadora activa. Una reducción nunca alcanza a una licencia existente.
								</small>
							</span>
						</label>
					</div>
					<div
						class="note-box mt-3"
						data-propagation
						data-groups="<?= V::escape($groupsJson) ?>"
						data-current="<?= V::escape($currentJson) ?>"
						aria-live="polite"
					>
						<p class="mb-2">
							<strong>Ejemplo.</strong> Si subes las cajas de <?= $cashExample ?> a <?= $cashExample + 1 ?>,
							una licencia de este plan con <?= $cashExample ?> cajas pasa a <?= $cashExample + 1 ?>. Una
							licencia personalizada, revocada o vencida no cambia, y ninguna baja.
						</p>
						<p class="mb-0">
							Siguen este plan <strong data-testid="followers"><?= $followers ?></strong> licencias que la
							propagación alcanzaría, <?= $withComputer ?> con computadora activa.
							<span data-testid="propagation-impact" data-impact-text>
								Con los cupos de arriba, si eliges «también las existentes», cambiarían
								<strong data-impact-licenses>0</strong> licencias y se firmarían
								<strong data-impact-revisions>0</strong> revisiones.
							</span>
						</p>
						<?php if (($examples ?? []) !== []): ?>
							<p class="small text-secondary mt-2 mb-0">
								Algunas de ellas:
								<?php foreach ($examples as $index => $example): ?>
									<?= $index > 0 ? '·' : '' ?>
									<a href="/admin/licenses/<?= V::escape($example['id_text']) ?>">
										<?= V::escape($example['customer_name']) ?>
									</a>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</div>
					<div class="mt-3">
						<label class="form-label" for="reason">Motivo del cambio <span class="optional">opcional</span></label>
						<input class="form-control" id="reason" name="reason" maxlength="500" value="<?= V::escape($values['reason']) ?>">
					</div>
				<?php endif; ?>
				<?php if ($canEdit): ?>
					<div class="form-footer">
						<a class="btn btn-outline-secondary" href="/admin/plans">Cancelar</a>
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
