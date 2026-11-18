<?php
use AdelPos\License\Http\View as V;

// Sección «Revisiones firmadas»: cada revisión con su número, su estado y su motivo, la computadora, la
// fecha en la zona del cliente y la descarga de su archivo .lic, de 50 en 50.
$revisionsUrl = $licenseUrl . '/revisions/';
$pageUrl = $licenseUrl . '?section=revisions&revision_page=';
$canDownload = $actor->allows('offline.download');
?>
<section class="panel" id="revisions" aria-labelledby="revisions-title">
	<div class="panel-heading">
		<div>
			<h2 id="revisions-title">Revisiones firmadas</h2>
			<p>
				Cada firma es una revisión con un número mayor que la anterior. Descargar un archivo no crea otra
				activación: conserva la computadora para la que se firmó.
			</p>
		</div>
	</div>
	<?php if (!$license['revisions']): ?>
		<p class="empty-line">Todavía no hay revisiones: la primera se firma al activar la licencia.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="revision-list">
				<thead>
					<tr>
						<th>Revisión</th>
						<th>Estado firmado</th>
						<th>Computadora</th>
						<th>Firmada</th>
						<th><span class="visually-hidden">Descarga</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($license['revisions'] as $revision): ?>
						<?php $revisionState = V::revisionStatus($revision['license_status'], $revision['status_reason']); ?>
						<tr>
							<td data-label="Revisión">
								<strong><?= (int) $revision['license_revision'] ?></strong>
								<small class="cell-sub">Llave: <?= V::escape($revision['kid']) ?></small>
							</td>
							<td data-label="Estado firmado">
								<?= V::badge($revisionState, $revision['license_status'] === 'active' ? 'green' : 'gray') ?>
							</td>
							<td data-label="Computadora" class="mono" title="<?= V::escape($revision['server_id_text']) ?>">
								<?= V::escape(V::shortId($revision['server_id_text'])) ?>
							</td>
							<td data-label="Firmada"><?= V::escape(V::date($revision['issued_at'], $zone)) ?></td>
							<td class="cell-action">
								<?php if ($canDownload): ?>
									<a class="text-link" href="<?= V::escape($revisionsUrl . $revision['id_text']) ?>/download">
										Descargar .lic
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="pagination-bar">
			<span>
				<?= (int) $license['revision_count'] ?> revisiones · Página <?= (int) $license['revision_page'] ?> de
				<?= (int) $license['revision_pages'] ?>
			</span>
			<nav class="d-flex gap-2" aria-label="Páginas de revisiones">
				<?php if ($license['revision_page'] > 1): ?>
					<a class="btn btn-sm btn-outline-secondary" href="<?= $pageUrl . ((int) $license['revision_page'] - 1) ?>">
						Más recientes
					</a>
				<?php endif; ?>
				<?php if ($license['revision_page'] < $license['revision_pages']): ?>
					<a class="btn btn-sm btn-outline-secondary" href="<?= $pageUrl . ((int) $license['revision_page'] + 1) ?>">
						Anteriores
					</a>
				<?php endif; ?>
			</nav>
		</div>
	<?php endif; ?>
</section>
