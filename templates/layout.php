<?php
use AdelPos\License\Http\View as V;

// Botón de notificaciones de la barra superior: alertas sin revisar y las más recientes.
$alertCount = (int) ($alerts['open'] ?? 0);
$alertLabel = $alertCount === 1 ? '1 alerta sin revisar' : $alertCount . ' alertas sin revisar';
$recentAlerts = [];
foreach ($alerts['recent'] ?? [] as $recent) {
	$recentAlerts[] = [
		'url' => '/admin/licenses/' . $recent['license_text'] . '?section=computers',
		'title' => V::alertType($recent['alert_type']),
		'note' => $recent['customer_name'] . ' · ' . V::date($recent['last_seen_at'], $recent['time_zone']),
	];
}
// Navegación en tres grupos (docs/PANEL.md, sección 2). Cada entrada: dirección, nombre, ícono y contador.
$offlineCount = (int) ($pendingOffline ?? 0);
$navigation = [
	'Operación' => [
		['/admin', 'Resumen', 'home', 0],
		['/admin/licenses', 'Licencias', 'license', 0],
		['/admin/customers', 'Clientes', 'customers', 0],
		['/admin/alerts', 'Alertas', 'alerts', $alertCount],
		['/admin/offline', 'Sin internet', 'offline', $offlineCount],
	],
	'Catálogo' => [['/admin/plans', 'Planes', 'plans', 0], ['/admin/products', 'Productos', 'products', 0]],
	'Control' => [['/admin/audit', 'Auditoría', 'audit', 0]],
];
if ($actor !== null && $actor->role === 'superadmin') {
	$navigation['Control'][] = ['/admin/users', 'Administradores', 'admins', 0];
}
$navigation['Control'][] = ['/admin/security', 'Mi seguridad', 'security', 0];
// Ruta de la barra superior: la que defina la pantalla ($crumbs), o «Licencias / título».
$crumbs = $crumbs ?? [['Licencias', null], [$title, null]];
$showIssue = $actor !== null && $actor->canWrite() && $path !== '/admin/licenses/new';
// Mensaje de un formulario que no se pudo completar y volvió a su pantalla con lo escrito.
$formError = V::formError();
?>
<!doctype html>
<html lang="es">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="application-name" content="AD-EL POS — Licencias">
		<meta
			name="description"
			content="AD-EL POS — Licencias. Administración de licencias del punto de venta AD-EL POS."
		>
		<title><?= V::escape($title) ?> · AD-EL POS — Licencias</title>
		<link rel="icon" type="image/png" href="/assets/brand/icon.png">
		<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
		<link rel="stylesheet" href="/assets/app.css">
		<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script>
		<script src="/assets/app.js" defer></script>
	</head>
	<body class="<?= $actor ? 'admin-body' : 'auth-body' ?>">
		<?php if ($actor): ?>
			<a class="skip-link" href="#main-content">Saltar al contenido</a>
			<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
				<a
					class="sidebar-brand"
					href="/admin"
				><img src="/assets/brand/adel-pos-tema-oscuro.png" alt="AD-EL POS — Licencias"></a>
				<nav class="sidebar-nav" aria-label="Secciones del panel">
					<?php foreach ($navigation as $group => $items): ?>
						<div class="sidebar-caption"><?= V::escape(mb_strtoupper($group)) ?></div>
						<?php foreach ($items as [$href, $label, $icon, $count]): ?>
							<?php $active = $href === '/admin' ? $path === $href : str_starts_with($path, $href); ?>
							<a
								href="<?= $href ?>"
								class="nav-item <?= $active ? 'active' : '' ?>"
								<?= $active ? 'aria-current="page"' : '' ?>
							>
								<?= V::icon($icon) ?>
								<span class="nav-label"><?= V::escape($label) ?></span>
								<?php if ($count > 0): ?>
									<span class="nav-count"><?= $count ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</nav>
				<div class="sidebar-bottom">
					<span class="status-dot"></span> Panel privado
					<div>Control y trazabilidad en un solo lugar.</div>
				</div>
			</aside>
			<div class="workspace">
				<header class="topbar">
					<div class="d-flex align-items-center gap-3">
						<button
							class="menu-toggle"
							type="button"
							aria-controls="sidebar"
							aria-expanded="false"
							aria-label="Abrir navegación"
						>☰</button>
						<nav class="breadcrumb-label" aria-label="Ruta">
							<?php foreach ($crumbs as $index => [$crumbLabel, $crumbUrl]): ?>
								<?php if ($index > 0): ?>
									<span class="crumb-separator" aria-hidden="true">/</span>
								<?php endif; ?>
								<?php if ($crumbUrl !== null): ?>
									<a href="<?= V::escape($crumbUrl) ?>"><?= V::escape($crumbLabel) ?></a>
								<?php else: ?>
									<span class="crumb-current"><?= V::escape($crumbLabel) ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</nav>
					</div>
					<div class="account-area">
						<?php if ($showIssue): ?>
							<a class="btn btn-primary btn-sm topbar-issue" href="/admin/licenses/new">
								<?= V::icon('plus') ?><span>Emitir licencia</span>
							</a>
						<?php endif; ?>
						<div class="dropdown notifications">
							<button
								class="notification-button"
								type="button"
								data-bs-toggle="dropdown"
								aria-expanded="false"
								aria-label="Notificaciones: <?= V::escape($alertLabel) ?>"
								data-testid="notifications"
							>
								<svg
									xmlns="http://www.w3.org/2000/svg"
									width="20"
									height="20"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									stroke-width="1.8"
									stroke-linecap="round"
									stroke-linejoin="round"
									aria-hidden="true"
								>
									<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
									<path d="M13.7 21a2 2 0 0 1-3.4 0"/>
								</svg>
								<?php if ($alertCount > 0): ?>
									<span class="notification-count" data-testid="notification-count"><?= $alertCount ?></span>
								<?php endif; ?>
							</button>
							<div class="dropdown-menu dropdown-menu-end notification-menu">
								<div class="dropdown-header"><?= V::escape($alertLabel) ?></div>
								<?php foreach ($recentAlerts as $recent): ?>
									<a class="dropdown-item" href="<?= V::escape($recent['url']) ?>">
										<?= V::escape($recent['title']) ?>
										<small><?= V::escape($recent['note']) ?></small>
									</a>
								<?php endforeach; ?>
								<div class="dropdown-divider"></div>
								<a class="dropdown-item" href="/admin/alerts">Ver todas las alertas</a>
							</div>
						</div>
						<span class="avatar"><?= V::escape(mb_strtoupper(mb_substr($actor->name, 0, 1))) ?></span>
						<div class="account-name">
							<?= V::escape($actor->name) ?><small><?= V::escape(V::role($actor->role)) ?></small>
						</div>
						<form method="post" action="/logout">
							<?php V::fields($csrf); ?>
							<button class="btn btn-sm btn-outline-secondary" type="submit">Salir</button>
						</form>
					</div>
				</header>
				<main id="main-content" class="main-content">
					<?php if ($formError !== ''): ?>
						<div class="alert alert-danger" role="alert" data-testid="form-error">
							<strong>No se pudo completar.</strong> <?= V::escape($formError) ?>
						</div>
					<?php endif; ?>
					<?= $content ?>
				</main>
				<footer class="workspace-footer">
					AD-EL POS — Licencias
					<span>
						Fechas del panel en <?= V::TIMEZONE ?>; las de cada licencia, en la zona horaria de su cliente.
					</span>
				</footer>
			</div>
		<?php else: ?>
			<main class="auth-shell <?= $path === '/login' ? 'auth-login' : '' ?>">
				<section class="auth-brand-panel">
					<img src="/assets/brand/adel-pos-tema-oscuro.png" class="auth-logo" alt="AD-EL POS — Licencias">
					<div class="auth-intro">
						<span class="eyebrow light">CONTROL DE LICENCIAS</span>
						<h1>
							Licencias de AD-EL POS,<br>
							en orden y bajo control.
						</h1>
						<p>Administra clientes, productos y vigencias desde un espacio privado y seguro.</p>
						<div class="auth-proof">
							<span class="auth-proof-icon" aria-hidden="true">
								<svg
									xmlns="http://www.w3.org/2000/svg"
									width="24"
									height="24"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									stroke-width="1.8"
									stroke-linecap="round"
									stroke-linejoin="round"
								>
									<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
									<path d="m9 12 2 2 4-4"/>
								</svg>
							</span>
							<div>
								Gestión centralizada
								<small>Emisión, activación y seguimiento de licencias.</small>
							</div>
						</div>
					</div>
					<footer>AD-EL POS <span>Administración de licencias</span></footer>
				</section>
				<section class="auth-form-panel">
					<a
						href="/login"
						class="mobile-brand"
					><img src="/assets/brand/adel-pos-tema-claro.png" alt="AD-EL POS — Licencias"></a>
					<div class="auth-form-wrap"><?= $content ?></div>
					<p class="auth-footnote">Acceso exclusivo para personal autorizado.</p>
				</section>
			</main>
		<?php endif; ?>
	</body>
</html>
