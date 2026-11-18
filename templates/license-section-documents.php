<?php
use AdelPos\License\Http\View as V;

// Sección «Constancias»: generar la constancia de licenciamiento o la de activación y entrega, y descargar
// las generadas. Cada generación es una revisión documental que no cambia las anteriores.
$documentNames = [
	'licensing' => 'Constancia de licenciamiento',
	'delivery' => 'Constancia de activación y entrega',
];
$documentRows = [];
foreach ($license['documents'] as $doc) {
	$canDownload =
		($doc['document_type'] === 'licensing' && $actor->canWrite()) ||
		($actor->allows('documents.delivery') &&
			(!$doc['contains_code'] || $actor->allows('credentials.reveal')) &&
			(!$doc['contains_offline'] || $actor->allows('offline.download')));
	$fileUrl = '';
	if ($doc['offline_revision_text'] !== null && $actor->allows('offline.download')) {
		$fileUrl = $licenseUrl . '/revisions/' . $doc['offline_revision_text'] . '/download';
	}
	$documentRows[] = [
		'name' => $documentNames[$doc['document_type']],
		'revision' => (int) $doc['revision'],
		'folio' => $doc['folio'],
		'generated_at' => V::date($doc['generated_at'], $zone),
		'url' => $canDownload ? '/admin/documents/' . $doc['id_text'] . '/download' : '',
		'file_url' => $fileUrl,
	];
}
$canDeliver = $actor->allows('documents.delivery');
$deliveryForm = $licenseUrl . '/documents';
?>
<div class="row g-4">
	<?php if ($actor->canWrite()): ?>
		<div class="col-xl-5">
			<section class="panel form-panel h-100" aria-labelledby="generate-title">
				<h2 id="generate-title">Generar una constancia</h2>
				<form method="post" action="<?= V::escape($deliveryForm) ?>">
					<?php V::fields($csrf); ?>
					<input type="hidden" name="document_type" value="licensing">
					<p class="text-secondary small">
						<strong>Constancia de licenciamiento:</strong> los derechos de la licencia, sin código ni archivo de
						activación.
					</p>
					<button class="btn btn-outline-primary">Generar constancia de licenciamiento</button>
				</form>
				<?php if ($canDeliver): ?>
					<details class="mt-4">
						<summary>Generar constancia de activación y entrega</summary>
						<form class="stack-form mt-3" method="post" action="<?= V::escape($deliveryForm) ?>">
							<?php V::fields($csrf); ?>
							<input type="hidden" name="document_type" value="delivery">
							<label>
								Método
								<select class="form-select mt-2" name="activation_method">
									<?php if ($actor->allows('credentials.reveal')): ?>
										<option value="online">En línea · contiene el código secreto</option>
									<?php endif; ?>
									<?php if ($actor->allows('offline.download') && $license['revisions']): ?>
										<option value="offline">Sin internet · identifica el archivo firmado</option>
									<?php endif; ?>
								</select>
							</label>
							<label>
								Archivo firmado <span class="optional">solo para la activación sin internet</span>
								<select class="form-select mt-2" name="revision_id">
									<option value="">Elige una revisión</option>
									<?php foreach ($license['revisions'] as $revision): ?>
										<option value="<?= V::escape($revision['id_text']) ?>">
											Revisión <?= (int) $revision['license_revision'] ?> ·
											<?= V::escape(V::revisionStatus($revision['license_status'], $revision['status_reason'])) ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
							<?php require __DIR__ . '/reauth-fields.php'; ?>
							<button class="btn btn-primary">Generar constancia de activación y entrega</button>
						</form>
					</details>
				<?php endif; ?>
			</section>
		</div>
	<?php endif; ?>
	<div class="col">
		<section class="panel h-100" id="documents" aria-labelledby="documents-title">
			<div class="panel-heading">
				<div>
					<h2 id="documents-title">Constancias generadas</h2>
					<p>Cada una conserva su folio y su revisión; la verificación pública confirma su autenticidad.</p>
				</div>
			</div>
			<?php if ($documentRows === []): ?>
				<p class="empty-line">Todavía no se han generado constancias.</p>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-stack">
						<thead>
							<tr>
								<th>Constancia</th>
								<th>Folio</th>
								<th>Generada</th>
								<th><span class="visually-hidden">Descarga</span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($documentRows as $row): ?>
								<tr>
									<td data-label="Constancia">
										<strong><?= V::escape($row['name']) ?></strong>
										<small class="cell-sub">Revisión <?= $row['revision'] ?></small>
									</td>
									<td data-label="Folio" class="mono"><?= V::escape($row['folio']) ?></td>
									<td data-label="Generada"><?= V::escape($row['generated_at']) ?></td>
									<td class="cell-action">
										<?php if ($row['url'] !== ''): ?>
											<a class="text-link" href="<?= V::escape($row['url']) ?>">
												Descargar PDF r<?= $row['revision'] ?>
											</a>
										<?php endif; ?>
										<?php if ($row['file_url'] !== ''): ?>
											<a class="text-link d-block mt-1" href="<?= V::escape($row['file_url']) ?>">
												Descargar el .lic de esta entrega
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
	</div>
</div>
