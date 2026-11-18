'use strict';
const assert = require('node:assert/strict');

// Pantallas del panel definitivo (etapa 5; docs/PANEL.md), en escritorio y en teléfono: el resumen con sus
// conteos, «Por vencer» y los cobros; la búsqueda, los filtros y el orden de las licencias; cada sección del
// detalle; y la lista y el detalle de los clientes. Usa las tres licencias sintéticas de prepare-panel.php,
// recién activadas: «Restaurante del Norte» (perpetua), «Cocina Regional» (suscripción mensual) y
// «Café Central» (prueba de 15 días).
module.exports = async function screensPanel({ page, screenshot }) {
	const submit = async (button) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
	const rows = async (selector) =>
		page
			.locator(selector + ' tbody tr')
			.evaluateAll((items) => items.map((item) => item.querySelector('td').innerText.trim()));
	// Todo campo tiene una etiqueta: <label>, aria-label o aria-labelledby (docs/PANEL.md, sección 8).
	async function allLabeled(where) {
		const missing = await page.evaluate(() =>
			[...document.querySelectorAll('input:not([type="hidden"]), select, textarea')]
				.filter((field) => field.labels.length === 0)
				.filter((field) => !field.getAttribute('aria-label') && !field.getAttribute('aria-labelledby'))
				.map((field) => field.name || field.id),
		);
		assert.deepEqual(missing, [], where + ': campos sin etiqueta');
	}
	// Ninguna pantalla desborda a lo ancho en un teléfono de 390 px.
	async function fitsPhone(where) {
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
		assert.equal(overflow <= 0, true, where + ' desborda ' + overflow + ' px');
	}

	// 1. Resumen: tarjetas, «Por vencer» con su filtro y los cobros del periodo.
	await page.goto('/admin');
	const counts = page.locator('[data-testid="dashboard-counts"]');
	assert.equal(await counts.locator('[data-testid="active-suscripciones"]').innerText(), '1');
	assert.equal(await counts.locator('[data-testid="active-perpetuas"]').innerText(), '1');
	assert.equal(await counts.locator('[data-testid="active-pruebas"]').innerText(), '1');
	assert.match(await counts.innerText(), /En tolerancia\s*0[\s\S]*Vencidas en los últimos 30 días\s*0/);
	// Por omisión, 30 días: la prueba de 15 días primero. La suscripción mensual vence en 30 o 31 días de
	// calendario, según el mes en que corra la prueba, así que puede estar o no.
	const due30 = await rows('[data-testid="due-list"]');
	assert.equal(due30[0], 'Café Central');
	assert.deepEqual(due30.slice(1), due30.length === 2 ? ['Cocina Regional'] : []);
	assert.match(
		await page.locator('[data-testid="due-list"]').innerText(),
		/Café Central[\s\S]*en 15 días[\s\S]*Convertir/,
	);
	await submit(page.locator('#due-soon').getByRole('link', { name: '15 días' }));
	assert.deepEqual(await rows('[data-testid="due-list"]'), ['Café Central']);
	await submit(page.locator('#due-soon').getByRole('link', { name: '7 días' }));
	assert.match(await page.locator('#due-soon').innerText(), /Nada vence en los próximos 7 días/);
	// El acceso directo de una prueba lleva a su conversión.
	await submit(page.locator('#due-soon').getByRole('link', { name: '30 días' }));
	const convertHref = await page
		.locator('[data-testid="due-list"]')
		.getByRole('link', { name: 'Convertir' })
		.getAttribute('href');
	assert.match(convertHref, /^\/admin\/licenses\/[a-f0-9-]{36}\/convert$/);
	// Cobros: las tres altas sintéticas de este mes, con monto 0.
	const current = await page.locator('[data-testid="collections-current"]').innerText();
	assert.match(current, /Mes en curso[\s\S]*\$0\.00 MXN[\s\S]*3 operaciones[\s\S]*Alta\s*3/);
	assert.match(await page.locator('[data-testid="collections-previous"]').innerText(), /0 operaciones/);
	await screenshot('dashboard-desktop');

	// 2. Licencias: búsqueda, filtros, orden y limpiar.
	await page.goto('/admin/licenses');
	assert.equal(await page.locator('[data-testid="license-list"] tbody tr').count(), 3);
	assert.match(await page.locator('[data-testid="license-list"]').innerText(), /Prueba vigente/);
	await page.locator('#q').fill('Cocina');
	await submit(page.getByRole('button', { name: 'Filtrar' }));
	assert.deepEqual(await rows('[data-testid="license-list"]'), ['Cocina Regional']);
	assert.match(await page.locator('[data-testid="license-list"]').innerText(), /Suscripción mensual[\s\S]*Vigente/);
	await submit(page.getByRole('link', { name: 'Limpiar filtros' }));
	await page.locator('#type').selectOption('trial');
	await submit(page.getByRole('button', { name: 'Filtrar' }));
	assert.deepEqual(await rows('[data-testid="license-list"]'), ['Café Central']);
	await page.goto('/admin/licenses?state=pending');
	assert.match(await page.locator('main').innerText(), /Ninguna licencia cumple estos filtros/);
	await page.goto('/admin/licenses?sort=expiry_asc');
	assert.deepEqual(await rows('[data-testid="license-list"]'), [
		'Café Central',
		'Cocina Regional',
		'Restaurante del Norte',
	]);
	await page.goto('/admin/licenses?state=active&plan=elemental&sort=expiry_desc');
	assert.deepEqual(await rows('[data-testid="license-list"]'), [
		'Cocina Regional',
		'Café Central',
		'Restaurante del Norte',
	]);
	assert.equal(await page.locator('#state').inputValue(), 'active');
	assert.equal(await page.locator('#sort').inputValue(), 'expiry_desc');
	// La computadora activa se puede buscar por su server_id.
	const server = await page
		.locator('[data-testid="license-list"] tbody tr')
		.filter({ hasText: 'Cocina Regional' })
		.locator('td[data-label="Computadora activa"] span')
		.getAttribute('title');
	await page.goto('/admin/licenses?q=' + server);
	assert.deepEqual(await rows('[data-testid="license-list"]'), ['Cocina Regional']);
	await screenshot('licenses-desktop');

	// 3. Detalle: cabecera, barra de acciones y cada sección con su propia dirección.
	await submit(page.getByRole('link', { name: 'Cocina Regional' }));
	const licenseURL = page.url();
	const header = await page.locator('.record-header').innerText();
	assert.match(header, /Cocina Regional[\s\S]*Vigente[\s\S]*Suscripción mensual[\s\S]*Plan elemental/);
	assert.match(header, /3 cajas · 3 comanderos · 3 KDS[\s\S]*America\/Monterrey/);
	assert.match(header, new RegExp(server.slice(0, 8)));
	const actionNames = await page
		.locator('[data-testid="license-actions"] > a, [data-testid="license-actions"] > .dropdown > button')
		.allInnerTexts();
	assert.deepEqual(
		actionNames.map((name) => name.trim()),
		['Renovar', 'Cambiar de plan', 'Descargar .lic vigente', 'Constancias', 'Código', 'Revocar'],
	);
	const sections = {
		summary: ['Resumen', /Condiciones[\s\S]*Plan y cupos[\s\S]*Fechas[\s\S]*Datos comerciales[\s\S]*Módulos/],
		payments: ['Pagos', /Alta[\s\S]*\$0\.00 MXN/],
		computers: ['Computadoras y transferencias', /Computadora activa[\s\S]*Historial de computadoras[\s\S]*Activa/],
		revisions: ['Revisiones firmadas', /Activa[\s\S]*Descargar \.lic/],
		offline: ['Sin internet', /no tiene solicitudes sin internet/],
		documents: ['Constancias', /Generar constancia de licenciamiento[\s\S]*Todavía no se han generado/],
		history: ['Historial', /Cambios comerciales[\s\S]*Licencia emitida[\s\S]*Auditoría de la licencia/],
	};
	for (const [key, [label, content]] of Object.entries(sections)) {
		await page.goto(licenseURL + '?section=' + key);
		assert.equal(await page.locator('.section-tabs a[aria-current="page"]').innerText(), label);
		assert.match(await page.locator('#section-' + key).innerText(), content, key);
		// La cabecera y la barra de acciones están en todas las secciones.
		assert.equal(await page.locator('[data-testid="license-actions"]').count(), 1);
	}
	await page.goto(licenseURL + '?section=otra');
	assert.equal(await page.locator('.section-tabs a[aria-current="page"]').innerText(), 'Resumen');
	// Las operaciones sensibles piden una confirmación explícita en su propia página.
	await page.goto(licenseURL);
	await submit(page.locator('[data-testid="license-actions"]').getByRole('link', { name: 'Revocar' }));
	const confirm = page.locator('[data-testid="confirm-form"]');
	assert.match(await confirm.innerText(), /Qué va a pasar[\s\S]*no se puede volver a activar/);
	assert.equal(await confirm.locator('[name="understood"]').getAttribute('required'), '');
	await screenshot('revoke-confirm-desktop');

	// 4. Clientes: búsqueda y detalle con sus licencias y sus pagos.
	await page.goto('/admin/customers?q=Cocina');
	assert.deepEqual(await rows('[data-testid="customer-list"]'), ['Cocina Regional']);
	assert.match(await page.locator('[data-testid="customer-list"]').innerText(), /1\s*1 vigentes/);
	await submit(page.getByRole('link', { name: 'Cocina Regional' }));
	assert.match(await page.locator('#customer-licenses').innerText(), /Suscripción mensual[\s\S]*Vigente/);
	assert.match(await page.locator('#customer-payments').innerText(), /Alta[\s\S]*\$0\.00 MXN/);
	const customerURL = page.url();
	await screenshot('customer-detail-desktop');

	// 5. Un formulario rechazado vuelve con el mensaje y con lo escrito (el correo se valida en el servidor). Va en
	// otra pestaña de la misma sesión: el 422 esperado aparece en la consola del navegador y el recorrido principal
	// exige una consola sin errores.
	const form = await page.context().newPage();
	await form.goto(customerURL + '/edit');
	await form.locator('#contact_name').fill('Contacto escrito a mano');
	await form.locator('#email').evaluate((input) => {
		input.type = 'text';
	});
	await form.locator('#email').fill('no-es-un-correo');
	const [rejected] = await Promise.all([
		form.waitForNavigation({ waitUntil: 'load' }),
		form.getByRole('button', { name: 'Guardar cliente' }).click(),
	]);
	assert.equal(rejected.status(), 422);
	assert.match(await form.locator('[data-testid="form-error"]').innerText(), /El correo electrónico no es válido/);
	assert.equal(await form.locator('#contact_name').inputValue(), 'Contacto escrito a mano');
	assert.equal(await form.locator('#email').inputValue(), 'no-es-un-correo');
	await form.screenshot({ path: 'var/screenshots/form-error-desktop.png', fullPage: true });
	await form.close();

	// 6. Etiquetas en todos los campos y navegación con teclado.
	for (const route of [
		'/admin',
		'/admin/licenses',
		licenseURL,
		licenseURL + '?section=documents',
		licenseURL + '/renew',
		licenseURL + '/plan',
		licenseURL + '/revoke',
		'/admin/licenses/new',
		'/admin/customers',
		customerURL + '/edit',
		'/admin/plans',
		'/admin/plans/elemental',
		'/admin/alerts',
		'/admin/offline',
		'/admin/audit',
		'/admin/users',
		'/admin/security',
	]) {
		await page.goto(route);
		await allLabeled(route);
	}
	await page.goto('/admin');
	await page.keyboard.press('Tab');
	assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Saltar al contenido');
	await page.keyboard.press('Enter');
	assert.equal(new URL(page.url()).hash, '#main-content');
	// La auditoría también se busca por el nombre en español de la operación.
	await page.goto('/admin/audit?q=' + encodeURIComponent('Licencia emitida'));
	assert.equal(await page.locator('[data-testid="audit-list"] tbody tr').count(), 3);

	// 7. Las mismas pantallas en un teléfono: sin desbordamiento, tablas como tarjetas y secciones en una lista.
	await page.setViewportSize({ width: 390, height: 844 });
	for (const [route, name] of [
		['/admin', 'dashboard-mobile'],
		['/admin/licenses', 'licenses-mobile'],
		[licenseURL, 'license-detail-mobile'],
		[licenseURL + '?section=computers', 'license-computers-mobile'],
		[licenseURL + '?section=payments', 'license-payments-mobile'],
		[licenseURL + '/renew', 'renew-mobile'],
		[licenseURL + '/plan?plan_id=premium&billing_period=monthly', 'plan-change-mobile'],
		[licenseURL + '/revoke', 'revoke-confirm-mobile'],
		['/admin/customers', 'customers-mobile'],
		[customerURL, 'customer-detail-mobile'],
		['/admin/plans', 'plans-mobile'],
		['/admin/plans/elemental', 'plan-mobile'],
		['/admin/alerts', 'alerts-mobile'],
		['/admin/offline', 'offline-mobile'],
		['/admin/audit', 'audit-mobile'],
		['/admin/users', 'users-mobile'],
		['/admin/security', 'security-mobile'],
		['/admin/licenses/new', 'license-form-mobile'],
	]) {
		await page.goto(route);
		await fitsPhone(route);
		await screenshot(name);
	}
	// En el teléfono las secciones se eligen de una lista y el botón «Ver» lleva a la elegida.
	await page.goto(licenseURL);
	assert.equal(await page.locator('.section-tabs ul').isVisible(), false);
	await page.locator('#section-select').selectOption('history');
	await submit(page.getByRole('button', { name: 'Ver', exact: true }));
	assert.equal(new URL(page.url()).searchParams.get('section'), 'history');
	// Una fila de la lista se ve como tarjeta con la etiqueta de cada dato.
	await page.goto('/admin/licenses');
	const cell = page.locator('[data-testid="license-list"] tbody tr').first().locator('td[data-label="Estado"]');
	assert.equal(await cell.evaluate((td) => getComputedStyle(td, '::before').content), '"Estado"');
	await page.setViewportSize({ width: 1440, height: 1000 });
};
