<?php
use AdelPos\License\Domain\TimeZones;
use AdelPos\License\Http\View as V;

// Alta o edición de un cliente. Si el formulario no pasó la validación, se vuelve a mostrar lo escrito.
$isNew = $customer === null;
$formUrl = '/admin/customers/' . ($customer['id_text'] ?? 'create');
$backUrl = $isNew ? '/admin/customers' : '/admin/customers/' . $customer['id_text'];
$crumbs = [['Clientes', '/admin/customers'], ['Nuevo cliente', null]];
if (!$isNew) {
	$crumbs = [['Clientes', '/admin/customers'], [$customer['display_name'], $backUrl], ['Editar datos', null]];
}
$values = [];
$columns = [
	'name' => 'display_name',
	'tax_id' => 'tax_id',
	'legal_name' => 'legal_name',
	'contact_name' => 'contact_name',
	'email' => 'email',
	'phone' => 'phone',
	'state' => 'state',
	'time_zone' => 'time_zone',
];
foreach ($columns as $field => $column) {
	$values[$field] = V::old($field, $customer[$column] ?? '', $formUrl);
}
$selectedZone = $values['time_zone'] !== '' ? $values['time_zone'] : TimeZones::DEFAULT;
?>
<div class="page-heading">
	<div>
		<a href="<?= V::escape($backUrl) ?>" class="text-link">← <?= $isNew ? 'Clientes' : 'Detalle del cliente' ?></a>
		<h1 class="mt-3"><?= $isNew ? 'Nuevo cliente' : 'Editar ' . V::escape($customer['display_name']) ?></h1>
		<p>Solo la información necesaria para administrar sus licencias.</p>
	</div>
</div>
<form
	class="panel form-panel"
	method="post"
	action="<?= V::escape($formUrl) ?>"
>
	<?php V::fields($csrf); ?>
	<input type="hidden" name="version" value="<?= (int) ($customer['row_version'] ?? 0) ?>">
	<fieldset <?= !$actor->canWrite() ? 'disabled' : '' ?>>
		<div class="section-heading">
			<span>01</span>
			<h2>Datos del cliente</h2>
		</div>
		<div class="row g-4">
			<div class="col-md-6">
				<label for="name" class="form-label">Nombre comercial</label>
				<input
					class="form-control"
					id="name"
					name="name"
					maxlength="190"
					value="<?= V::escape($values['name']) ?>"
					required
				>
			</div>
			<div class="col-md-6">
				<label for="tax_id" class="form-label">RFC <span class="optional">opcional</span></label>
				<input
					class="form-control"
					id="tax_id"
					name="tax_id"
					maxlength="32"
					value="<?= V::escape($values['tax_id']) ?>"
				>
			</div>
			<div class="col-md-6">
				<label for="legal_name" class="form-label">Razón social <span class="optional">opcional</span></label>
				<input
					class="form-control"
					id="legal_name"
					name="legal_name"
					maxlength="190"
					value="<?= V::escape($values['legal_name']) ?>"
				>
			</div>
			<div class="col-md-6">
				<label for="time_zone" class="form-label">Zona horaria</label>
				<select class="form-select" id="time_zone" name="time_zone" required>
					<?php foreach (TimeZones::all() as $zone): ?>
						<option value="<?= V::escape($zone) ?>" <?= $zone === $selectedZone ? 'selected' : '' ?>>
							<?= V::escape($zone) ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div class="form-text">
					Las fechas de sus licencias se calculan y se muestran en esta zona. Cambiarla no modifica las
					fechas que ya están firmadas.
				</div>
			</div>
		</div>
		<div class="section-heading mt-5">
			<span>02</span>
			<h2>Contacto principal</h2>
		</div>
		<div class="row g-4">
			<div class="col-md-12">
				<label for="contact_name" class="form-label">Nombre de contacto</label>
				<input
					class="form-control"
					id="contact_name"
					name="contact_name"
					maxlength="190"
					value="<?= V::escape($values['contact_name']) ?>"
					required
				>
			</div>
			<div class="col-md-6">
				<label for="email" class="form-label">Correo electrónico</label>
				<input
					type="email"
					class="form-control"
					id="email"
					name="email"
					maxlength="190"
					value="<?= V::escape($values['email']) ?>"
				>
			</div>
			<div class="col-md-6">
				<label for="phone" class="form-label">Teléfono</label>
				<input
					class="form-control"
					id="phone"
					name="phone"
					maxlength="60"
					value="<?= V::escape($values['phone']) ?>"
				>
			</div>
			<div class="col-md-6">
				<label for="state" class="form-label">Estado</label>
				<select class="form-select" name="state" id="state">
					<option value="active">Activo</option>
					<option
						value="archived"
						<?= $values['state'] === 'archived' ? 'selected' : '' ?>
					>Archivado</option>
				</select>
				<div class="form-text">Archivar conserva las licencias y su historial.</div>
			</div>
		</div>
		<?php if ($actor->canWrite()): ?>
			<div class="form-footer">
				<a href="<?= V::escape($backUrl) ?>" class="btn btn-outline-secondary">Cancelar</a>
				<button class="btn btn-primary" type="submit">Guardar cliente</button>
			</div>
		<?php endif; ?>
	</fieldset>
</form>
