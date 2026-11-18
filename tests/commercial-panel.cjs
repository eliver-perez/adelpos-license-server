'use strict';
const assert = require('node:assert/strict');

// Ciclo comercial en el panel (etapa 3): renovar, convertir una prueba, subir de plan con bonificación,
// programar y cancelar una reducción, comprar mantenimiento, y editar el catálogo propagando un aumento.
// Usa las licencias sintéticas de prepare-panel.php: «Restaurante del Norte» (perpetua activada),
// «Cocina Regional» (suscripción mensual activada) y «Café Central» (prueba activada).
module.exports = async function commercialPanel({ page, screenshot }) {
	const submit = async (button) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
	async function open(customer) {
		await page.goto('/admin/licenses');
		await page.locator('tbody tr').filter({ hasText: customer }).getByRole('link', { name: customer }).click();
		await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
		return page.url();
	}
	// Las operaciones se abren desde la barra de acciones del detalle (docs/PANEL.md, sección 3.3).
	const action = async (name) => submit(page.locator('[data-testid="license-actions"]').getByRole('link', { name }));
	// Los pagos están en su sección; se vuelve al resumen de la licencia al terminar.
	const payments = async () => {
		const url = page.url().split('?')[0];
		await page.goto(url + '?section=payments');
		const text = await page.locator('#payments tbody').innerText();
		await page.goto(url);
		return text;
	};
	const paymentCell = async (concept, label) => {
		const url = page.url().split('?')[0];
		await page.goto(url + '?section=payments');
		const row = page.locator('#payments tbody tr').filter({ hasText: concept }).first();
		const text = await row.locator('td[data-label="' + label + '"]').innerText();
		await page.goto(url);
		return text.trim();
	};
	const detail = async () => {
		const entries = await page
			.locator('#section-summary .detail-grid > div')
			.evaluateAll((items) =>
				items.map((item) => [
					item.querySelector('dt').innerText.trim(),
					item.querySelector('dd').innerText.replace(/\s+/g, ' ').trim(),
				]),
			);
		return Object.fromEntries(entries);
	};

	// 1. Renovar la suscripción: la vista previa muestra el vencimiento nuevo y sugiere el precio del plan.
	const subscriptionURL = await open('Cocina Regional');
	const before = await detail();
	// La renovación se calcula de inmediato con la duración vigente; aquí se cambia a trimestral.
	await action('Renovar');
	assert.match(await page.locator('#renewal-quote').innerText(), /Mensual \(1 mes\)/);
	await page.locator('#renew-form [name="billing_period"]').selectOption('quarterly');
	await submit(page.getByRole('button', { name: 'Calcular' }));
	assert.match(new URL(page.url()).pathname, /\/renew$/);
	const quote = await page.locator('#renewal-quote').innerText();
	assert.match(quote, /Pagó antes de que terminara la tolerancia/);
	assert.match(quote, /Trimestral \(3 meses\)/);
	assert.equal(await page.locator('[name="amount"]').inputValue(), '1500.00');
	assert.equal(await page.locator('[name="amount_reason"]').isDisabled(), true);
	await screenshot('renewal-quote-desktop');
	await submit(page.getByRole('button', { name: 'Registrar renovación' }));
	assert.equal(page.url(), subscriptionURL + '?notice=renew');
	assert.match(await page.locator('[role="status"]').innerText(), /Renovación registrada/);
	const renewed = await detail();
	assert.equal(renewed['Tipo'], 'Suscripción trimestral');
	assert.notEqual(renewed['Vencimiento'], before['Vencimiento']);
	assert.equal(renewed['Inicio del periodo'], before['Vencimiento']);
	assert.match(await payments(), /Renovación[\s\S]*Trimestral[\s\S]*\$1,500\.00 MXN/);

	// 2. Subir de plan con bonificación: la vista previa muestra lo pagado, la bonificación y lo sugerido.
	// El cambio de plan pide primero el destino.
	await action('Cambiar de plan');
	assert.equal(await page.locator('#plan-change-quote').count(), 0);
	await page.locator('#plan-change-form [name="plan_id"]').selectOption('premium');
	await page.locator('#plan-change-form [name="billing_period"]').selectOption('monthly');
	await submit(page.getByRole('button', { name: 'Calcular el cambio' }));
	assert.match(await page.locator('h1').innerText(), /aumento con bonificación/);
	const planQuote = await page.locator('#plan-change-quote').innerText();
	// Cuentan el periodo en curso (alta de 0.00) y el trimestre renovado por adelantado, que no ha empezado
	// y cuenta completo (decisión 3 de la etapa 4).
	assert.equal(await page.locator('[data-testid="credit-parts"] tbody tr').count(), 2);
	assert.match(planQuote, /\$0\.00 MXN\s*Cobrado: \$0\.00 MXN/);
	assert.match(planQuote, /\$1,500\.00 MXN\s*Cobrado: \$1,500\.00 MXN/);
	assert.match(planQuote, /Bonificación\s*\$1,500\.00 MXN/);
	assert.match(planQuote, /Precio del plan nuevo\s*\$899\.00 MXN/);
	assert.match(await page.locator('[data-testid="suggested"]').innerText(), /\$0\.00 MXN/);
	await screenshot('plan-change-quote-desktop');
	await submit(page.getByRole('button', { name: 'Registrar el cambio de plan' }));
	assert.match(await page.locator('[role="status"]').innerText(), /Cambio de plan registrado/);
	const upgraded = await detail();
	assert.equal(upgraded['Plan'], 'Plan premium');
	assert.deepEqual(
		[upgraded['Cajas'], upgraded['Comanderos'], upgraded['Pantallas de cocina (KDS)']],
		['5', '10', '5'],
	);
	assert.equal(await paymentCell('Cambio de plan', 'Bonificación'), '$1,500.00 MXN');

	// 3. Programar una reducción, sin cobro, y cancelarla.
	await action('Cambiar de plan');
	await page.locator('#plan-change-form [name="plan_id"]').selectOption('basico');
	await submit(page.getByRole('button', { name: 'Calcular el cambio' }));
	assert.match(await page.locator('h1').innerText(), /reducción programada/);
	assert.equal(await page.locator('[name="amount"]').count(), 0);
	assert.match(await page.locator('[data-testid="scheduled-at"]').innerText(), /\d{2}\/\d{2}\/\d{4}/);
	await submit(page.getByRole('button', { name: 'Programar el cambio' }));
	assert.match(await page.locator('#scheduled-change').innerText(), /Plan básico[\s\S]*Entra en vigor/);
	assert.equal((await detail())['Plan'], 'Plan premium');
	await screenshot('scheduled-change-desktop');
	await submit(page.getByRole('button', { name: 'Cancelar el cambio programado' }));
	assert.match(await page.locator('[role="status"]').innerText(), /Cambio programado cancelado/);
	assert.equal(await page.locator('#scheduled-change').count(), 0);

	// 4. Convertir la prueba en suscripción anual del plan elemental.
	const trialURL = await open('Café Central');
	// Una prueba no se renueva: se convierte.
	assert.equal(
		await page.locator('[data-testid="license-actions"]').getByRole('link', { name: 'Renovar' }).count(),
		0,
	);
	await action('Convertir');
	await page.locator('#convert-form [name="license_type"]').selectOption('subscription');
	await page.locator('#convert-form [name="billing_period"]').selectOption('annual');
	await page.locator('#convert-form [name="plan_id"]').selectOption('elemental');
	await submit(page.getByRole('button', { name: 'Calcular la conversión' }));
	assert.match(await page.locator('#conversion-quote').innerText(), /La prueba ya está activada/);
	await page.locator('[name="amount"]').fill('5000');
	await submit(page.getByRole('button', { name: 'Registrar conversión' }));
	assert.equal(page.url(), trialURL + '?notice=convert');
	const converted = await detail();
	assert.equal(converted['Tipo'], 'Suscripción anual');
	assert.equal(converted['Tolerancia'], '5 días');
	assert.match(await payments(), /Conversión[\s\S]*Anual[\s\S]*\$5,000\.00 MXN/);

	// 5. Comprar mantenimiento de la perpetua.
	await open('Restaurante del Norte');
	const updates = (await detail())['Actualizaciones hasta'];
	await action('Mantenimiento');
	assert.match(await page.locator('#maintenance-quote').innerText(), /todavía no terminan/);
	assert.equal(await page.locator('[name="amount"]').inputValue(), '3000.00');
	await submit(page.getByRole('button', { name: 'Registrar mantenimiento' }));
	assert.match(await page.locator('[role="status"]').innerText(), /Mantenimiento registrado/);
	assert.notEqual((await detail())['Actualizaciones hasta'], updates);
	assert.match(await payments(), /Mantenimiento[\s\S]*Mantenimiento anual[\s\S]*\$3,000\.00 MXN/);

	// 6. Editar el catálogo y propagar un aumento a las licencias existentes del plan elemental.
	await page.goto('/admin/plans');
	assert.match(await page.locator('#plan-catalog').innerText(), /Plan premium[\s\S]*\$15,000\.00 MXN/);
	await page.goto('/admin/plans/elemental');
	// Con los cupos actuales no cambia nada; el ejemplo explica qué pasaría.
	assert.match(await page.locator('[data-propagation]').innerText(), /Si subes las cajas de 3 a 4/);
	assert.match(await page.locator('[data-testid="propagation-impact"]').innerText(), /cambiarían 0 licencias/);
	await page.locator('#cash_registers').fill('4');
	await page.locator('#plan-form [name="apply_to"][value="existing"]').check();
	await page.locator('#plan-form [name="reason"]').fill('Una caja más para todos');
	// Antes de guardar, la pantalla dice cuántas licencias cambiarían y cuántas revisiones se firmarían.
	assert.match(
		await page.locator('[data-testid="propagation-impact"]').innerText(),
		/cambiarían 2 licencias y se firmarían 2 revisiones/,
	);
	await screenshot('plan-editor-desktop');
	await submit(page.getByRole('button', { name: 'Guardar plan' }));
	// La perpetua y la suscripción convertida siguen el plan elemental y tienen una computadora activa.
	assert.match(
		await page.locator('#propagation-result').innerText(),
		/Licencias actualizadas: 2\. Revisiones firmadas: 2\./,
	);
	await open('Café Central');
	assert.equal((await detail())['Cajas'], '4');
};
