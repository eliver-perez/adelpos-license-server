<?php
use AdelPos\License\Http\View as V;

$payload = $offline['payload'];
$requestPath = '/admin/offline/' . $offline['id_text'];
$active = null;
foreach ($license['activations'] ?? [] as $activation) {
	if ($activation['state'] === 'active') {
		$active = $activation;
	}
}
// La misma computadora es la misma identidad con la misma llave que la instalación activa.
$sameInstallation =
	$active !== null &&
	$active['server_id_text'] === $payload['server_id'] &&
	$active['public_key_text'] === $payload['installation_public_key'];
$needsTransfer = $payload['action'] === 'activate' && $active !== null && !$sameInstallation;
// Activar exige una licencia vigente. Las otras dos acciones se atienden aunque esté revocada:
// la computadora recibe su revisión de cierre.
$canApprove = $license !== null && ($payload['action'] !== 'activate' || $license['commercial_status'] === 'issued');
$approveLabel = $needsTransfer ? 'Confirmar transferencia' : 'Aprobar y generar .lic';
$actionNames = ['activate' => 'Activación', 'renew' => 'Renovación', 'deactivate' => 'Desactivación'];
$statusNames = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/offline">← Solicitudes sin internet</a>
		<h1 class="mt-3">
			<?= V::escape($actionNames[$payload['action']]) ?> sin internet
		</h1>
		<p>Revisa la instalación y la autorización comercial antes de decidir.</p>
	</div>
	<span class="tag large-tag"><?= V::escape($statusNames[$offline['status']]) ?></span>
</div>
<div class="row g-4">
	<div class="col-xl-7">
		<section class="panel form-panel">
			<h2>Identidad de la solicitud</h2>
			<dl class="detail-grid">
				<div class="wide">
					<dt>Identificador de la solicitud</dt>
					<dd class="mono"><?= V::escape($payload['request_id']) ?></dd>
				</div>
				<div>
					<dt>Producto</dt>
					<dd><?= V::escape($payload['product_id']) ?></dd>
				</div>
				<div>
					<dt>Fecha declarada por el equipo</dt>
					<dd class="mono"><?= V::escape(V::date($payload['created_at'])) ?></dd>
				</div>
				<div class="wide">
					<dt>Instalación</dt>
					<dd class="mono"><?= V::escape($payload['server_id']) ?></dd>
				</div>
				<div class="wide">
					<dt>Clave pública de instalación</dt>
					<dd class="mono"><?= V::escape($payload['installation_public_key']) ?></dd>
				</div>
				<div>
					<dt>Versión de AD-EL POS</dt>
					<dd class="mono"><?= V::escape($payload['app_version']) ?></dd>
				</div>
				<?php if (isset($payload['activation_id'])): ?>
					<div class="wide">
						<dt>Activación vinculada</dt>
						<dd class="mono"><?= V::escape($payload['activation_id']) ?></dd>
					</div>
				<?php endif; ?>
				<div>
					<dt>Importada</dt>
					<dd><?= V::escape(V::date($offline['imported_at'])) ?></dd>
				</div>
				<div>
					<dt>Importada por</dt>
					<dd><?= V::escape($offline['importer_name']) ?></dd>
				</div>
			</dl>
			<div class="note-box">
				La firma acredita posesión de la clave del equipo. La fecha declarada no acredita vigencia ni concede
				derechos comerciales.
			</div>
		</section>
		<?php if ($offline['status'] !== 'pending'): ?>
			<section class="panel form-panel mt-4">
				<h2>Decisión registrada</h2>
				<p><?= V::escape($offline['reason']) ?></p>
				<p class="text-secondary">
					<?= V::escape($offline['decider_name']) ?> · <?= V::escape(V::date($offline['decided_at'])) ?>
				</p>
				<?php if ($offline['status'] === 'approved'): ?>
					<p><a
							class="text-link"
							href="/admin/licenses/<?= V::escape($offline['license_text']) ?>"
						>Consultar licencia e historial vigente</a></p>
					<?php if ($actor->allows('offline.download')): ?>
						<a class="btn btn-primary" href="<?= V::escape($requestPath) ?>/download">Descargar .lic</a>
					<?php endif; ?>
					<p class="small text-secondary mt-3 mb-0">
						La descarga conserva la revisión autorizada en esta decisión. Entrega el archivo únicamente a su
						instalación y comprueba si existen revisiones posteriores.
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</div>
	<div class="col-xl-5">
		<?php if ($offline['status'] === 'pending' && $payload['action'] === 'activate' && $actor->canWrite()): ?>
			<section class="panel form-panel mb-4">
				<h2>Asignar licencia</h2>
				<form method="get" class="stack-form">
					<label>
						Buscar cliente o licencia
						<input class="form-control mt-2" name="q" value="<?= V::escape($query) ?>" maxlength="100">
					</label>
					<button class="btn btn-outline-secondary">Buscar licencias</button>
				</form>
				<form method="get" class="stack-form mt-3">
					<input type="hidden" name="q" value="<?= V::escape($query) ?>">
					<label>
						Licencia comercial
						<select class="form-select mt-2" name="license_id" required>
							<option value="">Seleccionar…</option>
							<?php foreach ($candidates as $candidate): ?>
								<option
									value="<?= V::escape($candidate['id_text']) ?>"
									<?= ($license['id_text'] ?? '') === $candidate['id_text'] ? 'selected' : '' ?>
								>
									<?= V::escape($candidate['customer_name']) ?> ·
									<?= V::escape(V::licenseType($candidate['license_type'])) ?> ·
									<?= V::escape($candidate['id_text']) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="btn btn-outline-primary">Revisar derechos</button>
				</form>
				<p class="small text-secondary mt-3 mb-0">
					Hasta 100 coincidencias del mismo producto. Refina la búsqueda si no aparece la licencia.
				</p>
			</section>
		<?php endif; ?>
		<?php if ($license !== null): ?>
			<section class="panel form-panel mb-4">
				<h2><?= V::escape($license['customer_name']) ?></h2>
				<p>
					<?= V::escape(V::licenseType($license['license_type'])) ?> ·
						<?= $license['commercial_status'] === 'issued' ? 'Emitida' : 'Revocada' ?>
				</p>
				<dl class="detail-grid">
					<div class="wide">
						<dt>Licencia</dt>
						<?php $licenseText = V::escape($license['id_text']); ?>
						<dd class="mono"><a href="/admin/licenses/<?= $licenseText ?>"><?= $licenseText ?></a></dd>
					</div>
					<div>
						<dt>Tipo</dt>
						<dd><?= V::escape(V::licenseTerms($license)) ?></dd>
					</div>
					<div>
						<dt>Plan</dt>
						<dd>
							<?= V::escape($license['plan_name']) ?><?= $license['plan_custom'] ? ' · personalizada' : '' ?>
						</dd>
					</div>
					<div>
						<dt>Vencimiento</dt>
						<dd><?= V::escape(V::expiry($license)) ?></dd>
					</div>
					<div>
						<dt>Tolerancia</dt>
						<dd><?= (int) $license['grace_days'] ?> días</dd>
					</div>
					<div>
						<dt>Actualizaciones hasta</dt>
						<dd><?= V::escape(V::licenseDate($license, 'updates_until')) ?></dd>
					</div>
				</dl>
				<?php
				$enabledFeatures = array_filter($license['features'], fn($feature) => (bool) $feature['enabled']);
				$moduleNames = implode(', ', array_column($enabledFeatures, 'display_name')) ?: 'Ninguno';
				?>
				<p class="small mb-0">Módulos: <?= V::escape($moduleNames) ?></p>
			</section>
		<?php endif; ?>
		<?php if ($offline['status'] === 'pending' && $actor->canWrite()): ?>
			<?php if ($canApprove && (!$needsTransfer || $actor->role === 'superadmin')): ?>
				<form
					class="panel form-panel stack-form mb-4"
					method="post"
					action="<?= V::escape($requestPath) ?>/approve"
				>
					<?php V::fields($csrf); ?>
					<input type="hidden" name="license_id" value="<?= V::escape($license['id_text']) ?>">
					<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
					<h2><?= $needsTransfer ? 'Transferir y aprobar' : 'Autorizar solicitud' ?></h2>
					<input type="hidden" name="renewal_mode" value="current">
					<?php if ($payload['action'] === 'renew'): ?>
						<p class="small text-secondary">
							Se entregará la revisión vigente de la licencia, tal como está firmada. Registrar una
							renovación o una compra de mantenimiento al aprobar estará disponible próximamente.
						</p>
					<?php endif; ?>
					<?php if ($payload['action'] === 'activate' && $sameInstallation): ?>
						<p class="small text-secondary">
							Esta computadora ya tiene activa la licencia. Recibirá su archivo vigente: no se crea otra
							activación ni cambian las fechas.
						</p>
					<?php endif; ?>
					<?php if ($payload['action'] === 'deactivate'): ?>
						<p class="small text-secondary">
							La firma acredita posesión de la clave, pero no prueba que se hayan borrado copias del
							estado anterior. La liberación requiere autorización excepcional del superadministrador.
						</p>
						<?php require __DIR__ . '/offline-confirm.php'; ?>
					<?php endif; ?>
					<?php if ($needsTransfer): ?>
						<div class="note-box">
							La licencia ya tiene una instalación activa. Esta recuperación por equipo averiado retirará
							<?= V::escape($active['server_id_text']) ?> y activará el destino en una sola operación.
						</div>
						<label class="form-check"><input
								class="form-check-input"
								type="checkbox"
								name="force_activation_id"
								value="<?= V::escape($active['id_text']) ?>"
								required
							> Autorizo retirar la instalación de origen.</label>
						<?php require __DIR__ . '/offline-confirm.php'; ?>
					<?php endif; ?>
					<label>
						Motivo / autorización
						<textarea class="form-control mt-2" name="reason" maxlength="500" rows="2" required></textarea>
					</label>
					<button class="btn btn-primary"><?= $approveLabel ?></button>
				</form>
			<?php elseif ($needsTransfer): ?>
				<div class="note-box mb-4">
					La plaza está ocupada. Solicita la desactivación firmada del equipo anterior o una transferencia al
					superadministrador.
				</div>
			<?php endif; ?>
			<form class="panel form-panel stack-form" method="post" action="<?= V::escape($requestPath) ?>/reject">
				<?php V::fields($csrf); ?>
				<h2>Rechazar solicitud</h2>
				<label>
					Motivo del rechazo
					<textarea class="form-control mt-2" name="reason" maxlength="500" rows="2" required></textarea>
				</label>
				<button class="btn btn-outline-danger">Registrar rechazo</button>
			</form>
		<?php endif; ?>
	</div>
</div>
