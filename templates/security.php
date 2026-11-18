<?php
use AdelPos\License\Http\View as V;

$crumbs = [['Mi seguridad', null]];
?>
<div class="page-heading">
	<div>
		<span class="eyebrow">MI CUENTA</span>
		<h1>Seguridad de tu cuenta</h1>
		<p>Tu acceso utiliza contraseña y verificación en dos pasos.</p>
	</div>
	<?= V::badge('Verificación en dos pasos activa', 'green') ?>
</div>
<form class="panel form-panel security-form" method="post" action="/admin/security">
	<?php V::fields($csrf); ?>
	<h2>Cambiar contraseña</h2>
	<p class="text-secondary">
		Al guardar, se cerrarán todas tus sesiones. Ingresa nuevamente con la contraseña nueva.
	</p>
	<div class="stack-form">
		<label>
			Contraseña actual
			<input class="form-control mt-2" name="password" type="password" autocomplete="current-password" required>
		</label>
		<label>
			Contraseña nueva
			<input
				class="form-control mt-2"
				name="new_password"
				type="password"
				minlength="12"
				maxlength="1024"
				autocomplete="new-password"
				required
			>
		</label>
		<label>
			Confirmar contraseña nueva
			<input
				class="form-control mt-2"
				name="confirmation"
				type="password"
				minlength="12"
				maxlength="1024"
				autocomplete="new-password"
				required
			>
		</label>
		<label>
			Código de autenticación nuevo
			<input
				class="form-control mt-2"
				name="code"
				inputmode="numeric"
				maxlength="6"
				autocomplete="one-time-code"
				required
			>
		</label>
		<button class="btn btn-primary">Actualizar contraseña y cerrar sesiones</button>
	</div>
</form>
<section class="panel form-panel mt-4" aria-labelledby="trusted-devices-title">
	<div class="page-heading">
		<div>
			<h2 id="trusted-devices-title">Dispositivos de confianza</h2>
			<p>
				Cada autorización dura 30 días desde que la aceptas. Su uso no amplía el plazo. Al cambiar tu contraseña
				se invalidan todas.
			</p>
		</div>
	</div>
	<?php if ($devicesRevoked): ?>
		<div class="alert alert-success" role="status">
			Se revocó la confianza. En el próximo inicio de sesión se solicitará Authenticator.
		</div>
	<?php endif; ?>
	<?php if ($devicesUnavailable): ?>
		<div class="alert alert-warning" role="alert">
			No se pudieron consultar tus dispositivos. Inténtalo de nuevo más tarde. Si no se puede verificar una
			autorización, se solicitará Authenticator.
		</div>
	<?php elseif ($devices === []): ?>
		<p class="text-secondary">No tienes dispositivos de confianza registrados.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table">
				<thead>
					<tr>
						<th>Dispositivo</th>
						<th>Autorizado</th>
						<th>Vencimiento</th>
						<th>Último uso</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($devices as $device): ?>
						<tr>
							<td>
								<?= V::escape($device['description']) ?>
								<?php if ($device['current']): ?>
									<span class="tag tag-green">Dispositivo actual</span>
								<?php endif; ?>
								<?php if ($device['expired']): ?>
									<span class="tag">Vencido</span>
								<?php endif; ?>
							</td>
							<td><?= V::escape(V::date($device['created_at'])) ?></td>
							<td><?= V::escape(V::date($device['expires_at'])) ?></td>
							<td>
								<?php if ($device['last_used_at'] === null): ?>
									Sin usos posteriores
								<?php else: ?>
									<?= V::escape(V::date($device['last_used_at'])) ?>
								<?php endif; ?>
							</td>
							<td>
								<details>
									<summary>Revocar dispositivo</summary>
									<p>¿Confirmas que deseas revocar la confianza de este dispositivo? Su sesión actual
										seguirá abierta.</p>
									<form method="post" action="/admin/security/devices/revoke">
										<?php V::fields($csrf); ?>
										<input type="hidden" name="scope" value="one">
										<input type="hidden" name="device_id" value="<?= V::escape($device['id']) ?>">
										<button
											class="btn btn-outline-danger"
											name="confirmation"
											value="revoke"
										>Confirmar revocación</button>
										<button
											class="btn btn-outline-secondary"
											name="confirmation"
											value="cancel"
										>Cancelar</button>
									</form>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<details class="mt-3">
			<summary>Revocar todos los dispositivos de confianza</summary>
			<p>
				¿Confirmas que deseas revocar todas las autorizaciones? En tus próximos accesos necesitarás
				Authenticator. Las sesiones abiertas conservarán su vencimiento habitual.
			</p>
			<form method="post" action="/admin/security/devices/revoke">
				<?php V::fields($csrf); ?>
				<input type="hidden" name="scope" value="all">
				<button
					class="btn btn-outline-danger"
					name="confirmation"
					value="revoke"
				>Confirmar revocación de todos</button>
				<button class="btn btn-outline-secondary" name="confirmation" value="cancel">Cancelar</button>
			</form>
		</details>
	<?php endif; ?>
</section>
