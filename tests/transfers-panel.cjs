'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// Transferencias y alertas en el panel (etapa 4). AD-EL POS se simula por la API con tests/panel-api.php:
// «Taquería del Puerto» se transfiere por autoservicio y un segundo intento queda bloqueado, y «Mariscos del
// Golfo» se transfiere dos veces. Después, en el panel: la transferencia en el detalle, la alerta en la barra
// superior y en el resumen, marcarla como revisada, liberar el contador a mano y la alerta de transferencias
// frecuentes.
module.exports = async function transfersPanel({ page, secret, freshCode, screenshot }) {
	const submit = async (button) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
	// El aviso de la operación; si no aparece, el texto de la página dice qué pasó.
	const notice = async () => {
		const status = page.locator('[role="status"]');
		if ((await status.count()) === 0) {
			throw new Error('Sin aviso en ' + page.url() + ': ' + (await page.locator('main').innerText()));
		}
		return status.innerText();
	};
	const count = async () => {
		const badge = page.locator('[data-testid="notification-count"]');
		return (await badge.count()) === 0 ? 0 : Number(await badge.innerText());
	};

	await page.goto('/admin');
	const before = await count();
	const api = (...args) =>
		JSON.parse(
			execFileSync(process.env.ADELPOS_PANEL_PHP || 'php', ['tests/panel-api.php', ...args], {
				cwd: path.join(__dirname, '..'),
				encoding: 'utf8',
			}),
		);
	const licenses = api();

	// 1. La alerta del intento bloqueado y la de transferencias frecuentes, en la barra y en el resumen.
	await page.goto('/admin');
	assert.equal(await count(), before + 2);
	assert.match(
		await page.locator('[data-testid="notifications"]').getAttribute('aria-label'),
		new RegExp('Notificaciones: ' + (before + 2) + ' alertas sin revisar'),
	);
	const summary = await page.locator('#alerts').innerText();
	assert.match(summary, /Transferencia por autoservicio bloqueada[\s\S]*Taquería del Puerto/);
	assert.match(summary, /Transferencias frecuentes[\s\S]*Mariscos del Golfo/);
	await screenshot('dashboard-alerts-desktop');

	// 2. El menú de notificaciones lleva al detalle de la licencia, con la transferencia pendiente.
	await page.locator('[data-testid="notifications"]').click();
	const menu = page.locator('.notification-menu');
	await menu.waitFor({ state: 'visible' });
	await screenshot('notifications-menu-desktop');
	await Promise.all([
		page.waitForURL(new RegExp('/admin/licenses/' + licenses.blocked + '$')),
		menu.getByRole('link', { name: /Transferencia por autoservicio bloqueada[\s\S]*Taquería del Puerto/ }).click(),
	]);
	const transfers = page.locator('#transfers');
	assert.match(await transfers.locator('[data-testid="pending-transfer"]').innerText(), /pendiente de confirmar/);
	const history = await transfers.locator('[data-testid="transfer-history"] tbody').innerText();
	assert.match(history, /Autoservicio[\s\S]*La pidió AD-EL POS[\s\S]*Pendiente de confirmar/);
	assert.match(
		await transfers.locator('[data-testid="alert"]').innerText(),
		/intentó transferir la licencia mientras otra transferencia está pendiente[\s\S]*Intentos: 1\./,
	);
	await screenshot('license-transfers-desktop');

	// 3. Marcar la alerta como revisada desde el detalle: vuelve al detalle y la barra tiene una menos.
	await submit(transfers.getByRole('button', { name: 'Marcar como revisada' }));
	assert.equal(
		page.url(),
		new URL('/admin/licenses/' + licenses.blocked + '?notice=alert-reviewed', page.url()).href,
	);
	assert.match(await notice(), /Alerta marcada como revisada/);
	assert.equal(await count(), before + 1);
	assert.match(
		await transfers.locator('[data-testid="alert"]').innerText(),
		/Revisada por Administración de pruebas/,
	);
	assert.equal(await transfers.getByRole('button', { name: 'Marcar como revisada' }).count(), 0);

	// 4. Liberar el contador a mano, con motivo y confirmando la identidad. Los recorridos anteriores ya
	// agotaron las confirmaciones de identidad de esta cuenta en los últimos 15 minutos.
	api('reauth-window');
	await transfers.locator('summary').filter({ hasText: 'Liberar el contador de autoservicio' }).click();
	await transfers.locator('[name="reason"]').fill('La computadora anterior se dañó');
	await transfers.locator('[name="password"]').fill('Fixture-Password-Only-2026!');
	await transfers.locator('[name="code"]').fill(await freshCode(secret));
	await submit(transfers.getByRole('button', { name: 'Liberar el contador' }));
	assert.match(await notice(), /Contador de autoservicio liberado/);
	assert.equal(await page.locator('[data-testid="pending-transfer"]').count(), 0);
	assert.match(
		await page.locator('[data-testid="transfer-history"] tbody').innerText(),
		/Confirmada[\s\S]*Liberada a mano[\s\S]*La computadora anterior se dañó/,
	);
	await screenshot('transfer-released-desktop');

	// 5. La alerta de transferencias frecuentes, en la pantalla de alertas: se filtra y se marca como revisada.
	await page.goto('/admin/alerts?status=open');
	const open = await page.locator('[data-testid="alert-list"]').innerText();
	assert.match(
		open,
		/Transferencias frecuentes[\s\S]*Mariscos del Golfo[\s\S]*2 transferencias en los 12 meses anteriores/,
	);
	assert.doesNotMatch(open, /Taquería del Puerto/);
	await screenshot('alerts-desktop');
	const frequent = page.locator('[data-testid="alert"]').filter({ hasText: 'Mariscos del Golfo' });
	await submit(frequent.getByRole('button', { name: 'Marcar como revisada' }));
	assert.match(await notice(), /Alerta marcada como revisada/);
	assert.equal(await count(), before);
	await page.goto('/admin/alerts?status=reviewed');
	const reviewed = await page.locator('[data-testid="alert-list"]').innerText();
	assert.match(reviewed, /Taquería del Puerto/);
	assert.match(reviewed, /Mariscos del Golfo/);

	// 6. En un teléfono, el botón de notificaciones sigue en la barra superior.
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto('/admin');
	assert.equal(await page.locator('[data-testid="notifications"]').isVisible(), true);
	await screenshot('dashboard-alerts-mobile');
	await page.setViewportSize({ width: 1440, height: 1000 });
};
