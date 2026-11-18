<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

// Constancias PDF (etapa 5, Fase 4): la de licenciamiento y la de activación y entrega, en una página A4.
// Las dos llevan la marca, el folio, el titular (y su RFC si existe), el tipo y el periodo, el plan y los
// cupos, las fechas en la zona horaria del cliente, el fin de las actualizaciones, la revisión y el QR de
// verificación. La de activación y entrega agrega el método, la computadora, el SHA-256 del .lic si aplica e
// instrucciones para el restaurante. Ninguna muestra montos ni pagos: se entregan al cliente.
$d = $document;
$licensing = $d['type'] === 'licensing';
$title = $licensing ? 'Constancia de licenciamiento' : 'Constancia de activación y entrega';
$zone = $d['time_zone'] ?? V::TIMEZONE;
$generated = V::dateParts($d['generated_at'], $zone);
$expiry = V::licenseDate($d, 'expires_at');
if ($d['license_type'] === 'perpetual') {
	$expiry = 'Sin vencimiento (uso perpetuo)';
}
$limits = [];
foreach (LicenseTerms::LIMITS as $limit => $label) {
	$limits[] = [$label, (int) $d['limits'][$limit]];
}
$plan = $d['plan']['name'] . ($d['plan']['custom'] ? ' · cupos personalizados' : '');
// Revisión firmada más reciente de la licencia al generar la constancia; 0 si todavía no se firma ninguna.
$signedRevision = (int) ($d['signed_revision'] ?? 0);
$computer = $d['installation'] ?? ($d['active_server'] ?? null);
$online = ($d['activation_method'] ?? 'none') === 'online';
$artifactStatus = ($d['artifact_status'] ?? '') === 'active' ? 'activa' : 'de cierre';
$methodName = $online ? 'En línea, con el código de activación' : 'Sin internet, con el archivo firmado (.lic)';
$grace = (int) $d['grace_days'] === 0 ? 'Sin tolerancia' : (int) $d['grace_days'] . ' días después del vencimiento';
?>
<!doctype html>
<html lang="es">
	<head>
		<meta charset="utf-8">
		<title><?= $title ?></title>
		<style>
			@page {
				margin: 28pt 36pt 30pt;
			}
			body {
				font-family: 'DejaVu Sans';
				font-size: 8.6pt;
				color: #17243a;
				line-height: 1.35;
			}
			.header {
				width: 100%;
				border-collapse: collapse;
			}
			.header td {
				border: 0;
				padding: 0;
				vertical-align: middle;
			}
			.logo {
				width: 170pt;
				height: 56.667pt;
			}
			.logo-text {
				font-size: 22pt;
				font-weight: bold;
				color: #142641;
				margin: 0;
			}
			.folio-box {
				text-align: right;
				font-size: 7.6pt;
				color: #475467;
			}
			.folio-box strong {
				display: block;
				font-size: 9pt;
				color: #142641;
				font-family: 'DejaVu Sans Mono';
			}
			h1 {
				font-size: 16pt;
				margin: 12pt 0 2pt;
				color: #142641;
			}
			.subtitle {
				margin: 0 0 8pt;
				color: #475467;
			}
			h2 {
				font-size: 9.6pt;
				margin: 10pt 0 3pt;
				padding-bottom: 2pt;
				border-bottom: 1.2pt solid #2463eb;
				color: #142641;
			}
			table.data {
				width: 100%;
				border-collapse: collapse;
			}
			table.data th,
			table.data td {
				border-bottom: 0.6pt solid #e2e8f0;
				padding: 3pt 3pt;
				text-align: left;
				vertical-align: top;
			}
			table.data th {
				width: 32%;
				font-weight: bold;
				color: #344054;
			}
			.zone {
				color: #475467;
				font-size: 7.4pt;
			}
			.mono {
				font-family: 'DejaVu Sans Mono';
				font-size: 7.6pt;
				word-wrap: break-word;
			}
			.code {
				font-family: 'DejaVu Sans Mono';
				font-size: 17pt;
				letter-spacing: 1pt;
				background: #edf2f8;
				padding: 8pt 10pt;
				margin: 4pt 0;
			}
			ol {
				margin: 3pt 0 0 14pt;
				padding: 0;
			}
			ol li {
				margin-bottom: 2pt;
			}
			.notice {
				border-left: 3pt solid #2463eb;
				padding: 5pt 8pt;
				background: #f4f7fc;
				margin: 6pt 0 0;
			}
			.verification {
				width: 100%;
				border-collapse: collapse;
				margin-top: 10pt;
				page-break-inside: avoid;
			}
			.verification td {
				border: 0;
				padding: 0;
				vertical-align: top;
			}
			.qr {
				width: 76pt;
				height: 76pt;
			}
			/* La de activación y entrega lleva más datos: se compacta para caber en una página A4. */
			.delivery {
				font-size: 7.8pt;
				line-height: 1.25;
			}
			.delivery .logo {
				width: 132pt;
				height: 44pt;
			}
			.delivery h1 {
				font-size: 13.5pt;
				margin-top: 6pt;
			}
			.delivery .subtitle {
				margin-bottom: 4pt;
			}
			.delivery h2 {
				font-size: 8.8pt;
				margin: 6pt 0 2pt;
			}
			.delivery table.data th,
			.delivery table.data td {
				padding: 1.6pt 3pt;
			}
			.delivery .code {
				font-size: 14pt;
				padding: 5pt 8pt;
				margin: 3pt 0;
			}
			.delivery ol li {
				margin-bottom: 1pt;
			}
			.delivery .notice {
				padding: 4pt 7pt;
				margin-top: 4pt;
			}
			.delivery .verification {
				margin-top: 4pt;
			}
			.delivery .qr {
				width: 62pt;
				height: 62pt;
			}
			.delivery .footer {
				margin-top: 6pt;
			}
			.footer {
				margin-top: 10pt;
				border-top: 0.8pt solid #cad5e2;
				padding-top: 5pt;
				font-size: 6.8pt;
				color: #475467;
			}
		</style>
	</head>
	<body class="<?= $licensing ? 'licensing' : 'delivery' ?>">
		<table class="header">
			<tr>
				<td>
					<?php if ($logo !== null): ?>
						<img class="logo" src="<?= V::escape($logo) ?>" alt="AD-EL POS">
					<?php else: ?>
						<p class="logo-text">AD-EL POS</p>
					<?php endif; ?>
				</td>
				<td class="folio-box">
					Folio
					<strong><?= V::escape($d['folio']) ?></strong>
					Revisión de la constancia: <?= (int) $d['revision'] ?><br>
					Generada: <?= V::escape($generated['date']) ?> <?= V::escape($generated['zone']) ?>
				</td>
			</tr>
		</table>
		<h1><?= $title ?></h1>
		<p class="subtitle">
			<?php if ($licensing): ?>
				Derechos de uso de AD-EL POS que tiene el titular, tal como están registrados al generar esta
				constancia.
			<?php else: ?>
				Lo necesario para activar AD-EL POS en la computadora principal del restaurante.
			<?php endif; ?>
		</p>
		<h2>Titular</h2>
		<table class="data">
			<tr>
				<th>Nombre</th>
				<td><?= V::escape($d['holder']) ?></td>
			</tr>
			<?php if ($d['legal_name']): ?>
				<tr>
					<th>Razón social</th>
					<td><?= V::escape($d['legal_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if ($d['tax_id']): ?>
				<tr>
					<th>RFC</th>
					<td><?= V::escape($d['tax_id']) ?></td>
				</tr>
			<?php endif; ?>
		</table>
		<h2>Licencia</h2>
		<table class="data">
			<tr>
				<th>Producto</th>
				<td><?= V::escape($d['product']) ?></td>
			</tr>
			<tr>
				<th>Licencia</th>
				<td class="mono"><?= V::escape($d['license_id']) ?></td>
			</tr>
			<tr>
				<th>Tipo y periodo</th>
				<td><?= V::escape(V::licenseTerms($d)) ?></td>
			</tr>
			<tr>
				<th>Plan</th>
				<td><?= V::escape($plan) ?></td>
			</tr>
			<tr>
				<th>Cupos simultáneos</th>
				<td>
					<?php foreach ($limits as $index => [$label, $value]): ?>
						<?= $index > 0 ? ' · ' : '' ?><?= V::escape($label) ?>: <?= $value ?>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th>Revisión firmada</th>
				<td>
					<?php if ($signedRevision > 0): ?>
						<?= $signedRevision ?> (la más reciente al generar esta constancia)
					<?php else: ?>
						Todavía no se firma: la primera revisión se firma al activar
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<h2>Fechas <span class="zone">· zona horaria del titular: <?= V::escape($zone) ?></span></h2>
		<table class="data">
			<tr>
				<th>Emisión</th>
				<td><?= V::escape(V::date($d['issued_at'], $zone)) ?></td>
			</tr>
			<tr>
				<th>Inicio del periodo</th>
				<td><?= V::escape(V::licenseDate($d, 'period_starts_at')) ?></td>
			</tr>
			<tr>
				<th>Vencimiento</th>
				<td><?= V::escape($expiry) ?></td>
			</tr>
			<tr>
				<th>Tolerancia</th>
				<td><?= V::escape($grace) ?></td>
			</tr>
			<tr>
				<th>Actualizaciones hasta</th>
				<td><?= V::escape(V::licenseDate($d, 'updates_until')) ?></td>
			</tr>
		</table>
		<?php if ($licensing): ?>
			<p class="notice">
				Esta constancia acredita los derechos indicados. No contiene el código ni el archivo de activación, no es
				un comprobante de pago y no sustituye el contrato comercial.
			</p>
		<?php else: ?>
			<h2>Activación</h2>
			<table class="data">
				<tr>
					<th>Método</th>
					<td><?= V::escape($methodName) ?></td>
				</tr>
				<tr>
					<th>Computadora (server_id)</th>
					<td class="mono"><?= V::escape($computer ?? 'Se registra al activar') ?></td>
				</tr>
				<?php if (!$online): ?>
					<tr>
						<th>Archivo</th>
						<td class="mono"><?= V::escape($d['offline_filename']) ?></td>
					</tr>
					<tr>
						<th>Revisión del archivo</th>
						<td><?= (int) $d['license_revision'] ?> · revisión <?= $artifactStatus ?></td>
					</tr>
					<tr>
						<th>SHA-256 del archivo</th>
						<td class="mono"><?= V::escape($d['offline_sha256']) ?></td>
					</tr>
				<?php endif; ?>
			</table>
			<?php if ($online): ?>
				<p class="code"><?= V::escape($d['activation_code']) ?></p>
			<?php endif; ?>
			<h2>Instrucciones para el restaurante</h2>
			<ol>
				<?php if ($online): ?>
					<li>
						En la computadora principal del restaurante, abre AD-EL POS y entra a la activación de la
						licencia.
					</li>
					<li>Escribe el código de arriba, con o sin guiones, y confirma. La computadora necesita internet.</li>
					<li>
						AD-EL POS muestra el plan y las fechas de esta constancia. Las cajas, los comanderos y las
						pantallas de cocina no se activan aparte.
					</li>
				<?php else: ?>
					<li>Copia el archivo .lic indicado a la computadora que generó la solicitud (.licreq).</li>
					<li>En AD-EL POS, importa el archivo en la activación sin internet.</li>
					<li>
						AD-EL POS comprueba la firma y que el archivo sea de esa computadora. El SHA-256 sirve para
						confirmar que es el mismo archivo.
					</li>
				<?php endif; ?>
				<li>Para cambiar de computadora, AD-EL POS transfiere la licencia; si no puede, comunícate con soporte.</li>
			</ol>
			<p class="notice">
				CONFIDENCIAL. Entrega el código o el archivo solo a la persona autorizada, por un canal seguro. Esta
				constancia registra una entrega: no demuestra que la computadora siga activada.
			</p>
		<?php endif; ?>
		<table class="verification">
			<tr>
				<td>
					<h2>Verificación de autenticidad</h2>
					<p>
						Escanea el código QR o abre la dirección de abajo: confirma el folio, la revisión, el estado actual
						de la licencia y el SHA-256 de este PDF. Un QR copiado a otro documento no lo valida.
					</p>
					<p class="mono"><?= V::escape($d['verification_url']) ?></p>
				</td>
				<td style="width: 86pt; text-align: right">
					<img class="qr" src="<?= V::escape($qr) ?>" alt="Código QR de verificación">
				</td>
			</tr>
		</table>
		<div class="footer">
			AD-EL POS — Licencias. Documento con las condiciones vigentes al generarse; pueden cambiar después. Las
			fechas están en la zona horaria del titular (<?= V::escape($zone) ?>). Esta constancia no incluye montos ni
			pagos.
		</div>
	</body>
</html>
