'use strict';

const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

// Only the synthetic fixture from prepare-panel.php is supported.
const baseURL = 'http://127.0.0.1:8088';
function totp(secret, offset = 0) {
	const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	const bits = [...secret].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
	const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
	const counter = Buffer.alloc(8);
	counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
	const digest = crypto.createHmac('sha1', key).update(counter).digest();
	return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
const future = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 16);

(async () => {
	const browser = await chromium.launch({
		executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		headless: true,
	});
	const errors = [];
	const screenshotDir = path.join(__dirname, '../var/screenshots');
	fs.mkdirSync(screenshotDir, { recursive: true });
	const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
	const page = await context.newPage();
	page.on('response', (response) => {
		const route = new URL(response.url()).pathname;
		if (process.env.PANEL_DEBUG && ['/login', '/mfa'].includes(route)) {
			console.log(
				response.request().method(),
				route,
				response.status(),
				response.headers().location || '',
				'cookie:',
				Boolean(response.headers()['set-cookie']),
				'origin:',
				response.request().headers().origin,
			);
		}
	});
	page.on('pageerror', (error) => errors.push(error.message));
	// Pages declare /assets/brand/icon.png; a browser /favicon.ico probe (e.g. for downloads) is ignored by URL,
	// because Chrome no longer puts the URL in the message text.
	page.on('console', (message) => {
		const url = message.location()?.url || '';
		if (message.type() === 'error' && !/\/favicon\.ico(?:$|\?)/.test(url) && !message.text().includes('favicon'))
			errors.push(message.text() + (url ? ' · ' + url : ''));
	});
	async function submit(button) {
		await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
	}
	let lastReauthStep = Math.floor(Date.now() / 30000);
	async function freshCode(secret) {
		while (Math.floor(Date.now() / 30000) + 1 <= lastReauthStep)
			await new Promise((resolve) => setTimeout(resolve, 300));
		lastReauthStep = Math.floor(Date.now() / 30000) + 1;
		return totp(secret, 1);
	}
	async function sensitiveDownload(url, secret) {
		const confirm = await context.request.get(url);
		assert.equal(confirm.status(), 200);
		const csrf = (await confirm.text()).match(/name="csrf" value="([^"]+)"/)[1];
		return context.request.post(url, {
			form: {
				csrf,
				operation_id: crypto.randomUUID(),
				password: 'Fixture-Password-Only-2026!',
				code: await freshCode(secret),
			},
		});
	}
	async function screenshot(name) {
		await page.screenshot({
			path: path.join(screenshotDir, name + '.png'),
			fullPage: true,
			animations: 'disabled',
		});
	}
	// AD-EL POS brand: product name in the title, the supplied logo shown, and no broken image.
	async function assertBrand(where, logoVisible = true) {
		assert.match(await page.title(), /AD-EL POS — Licencias$/, where);
		const images = await page.locator('img').evaluateAll((list) =>
			list.map((img) => ({
				src: img.getAttribute('src'),
				loaded: img.complete && img.naturalWidth > 0,
				visible: img.offsetParent !== null,
			})),
		);
		if (logoVisible) {
			assert.ok(
				images.some(
					(image) => image.visible && /^\/assets\/brand\/adel-pos-tema-(claro|oscuro)\.png$/.test(image.src),
				),
				where + ': logo de AD-EL POS',
			);
		}
		for (const image of images.filter((image) => image.visible)) {
			assert.equal(image.loaded, true, where + ': ' + image.src);
		}
		assert.equal(
			await page.locator('link[rel="icon"]').getAttribute('href'),
			'/assets/brand/icon.png',
			where + ': ícono de pestaña',
		);
		const icon = await page.request.get('/assets/brand/icon.png');
		assert.equal(icon.status(), 200, where + ': ícono de pestaña');
	}
	try {
		await page.goto('/admin/licenses');
		assert.equal(new URL(page.url()).pathname, '/login');
		await screenshot('login-desktop');
		await assertBrand('acceso');
		await page.locator('#login').fill('admin@example.test');
		await page.locator('#password').fill('Fixture-Password-Only-2026!');
		await submit(page.getByRole('button', { name: 'Continuar' }));
		assert.equal(
			new URL(page.url()).pathname,
			'/mfa',
			await page
				.locator('[role="alert"]')
				.allTextContents()
				.then((values) => values.join('; ')),
		);
		const secret = await page.locator('[data-testid="totp-secret"]').textContent();
		assert.match(await page.locator('.qr-wrap img').getAttribute('src'), /^data:image\/svg\+xml;base64,/);
		await page.locator('#code').fill(totp(secret));
		await submit(page.getByRole('button', { name: 'Verificar e ingresar' }));
		assert.equal(await page.locator('.recovery-grid code').count(), 8);
		await page.getByRole('link', { name: 'Ya los guardé · Continuar' }).click();
		await page.waitForURL('**/trust-device');
		await submit(page.getByRole('button', { name: 'Ahora no' }));
		await page.waitForURL('**/admin');
		await screenshot('dashboard-desktop');
		await assertBrand('resumen');
		assert.equal((await context.cookies()).find((cookie) => cookie.name === 'adelpos_admin_dev').httpOnly, true);

		// Pantallas del panel definitivo (etapa 5), antes de que el recorrido cambie los datos sintéticos.
		await require('./screens-panel.cjs')({ page, screenshot });

		await page.goto('/admin/licenses');
		await page
			.locator('tbody tr')
			.filter({ hasText: 'Restaurante del Norte' })
			.getByRole('link', { name: 'Restaurante del Norte' })
			.click();
		await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
		// La barra de acciones descarga el archivo de la revisión vigente.
		const signedDownload = await page.getByRole('link', { name: 'Descargar .lic vigente' }).getAttribute('href');
		const signedFile = await sensitiveDownload(signedDownload, secret);
		assert.equal(signedFile.status(), 200);
		assert.match(signedFile.headers()['content-disposition'], /^attachment; filename="license-/);
		const compact = await signedFile.text();
		assert.match(compact, /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{86}$/);
		const signedPayload = JSON.parse(Buffer.from(compact.split('.')[1], 'base64url').toString('utf8'));
		assert.equal(signedPayload.license_id, new URL(page.url()).pathname.split('/').pop());
		assert.equal(signedPayload.license_revision, 1);
		await screenshot('activated-license-desktop');

		await page.goto('/admin/customers/new');
		await page.locator('#name').fill('Restaurante de Pruebas');
		await page.locator('#legal_name').fill('<script>window.unwantedScript=true</script>');
		await page.locator('#contact_name').fill('Contacto de pruebas');
		await page.locator('#email').fill('restaurante@example.test');
		// La zona horaria del cliente se elige de una lista; por omisión, la de Monterrey.
		assert.equal(await page.locator('#time_zone').inputValue(), 'America/Monterrey');
		await page.locator('#time_zone').selectOption('America/Tijuana');
		await submit(page.getByRole('button', { name: 'Guardar cliente' }));
		const customerId = new URL(page.url()).pathname.split('/').pop();
		assert.match(customerId, /^[a-f0-9-]{36}$/);
		// El detalle del cliente muestra sus datos (escapados), sus licencias y sus pagos.
		assert.match(await page.locator('[role="status"]').innerText(), /Cliente registrado/);
		const facts = await page.locator('[data-testid="customer-facts"]').innerText();
		assert.match(facts, /America\/Tijuana/);
		assert.match(facts, /<script>window\.unwantedScript=true<\/script>/);
		assert.equal(await page.evaluate(() => window.unwantedScript), undefined);
		assert.match(await page.locator('#customer-licenses').innerText(), /todavía no tiene licencias/);
		await page.goto('/admin/customers/' + customerId + '/edit');
		assert.equal(await page.locator('#time_zone').inputValue(), 'America/Tijuana');
		assert.equal(await page.locator('#legal_name').inputValue(), '<script>window.unwantedScript=true</script>');
		// «Emitir licencia para este cliente» abre el formulario con el cliente elegido.
		await page.goto('/admin/customers/' + customerId);
		await submit(page.getByRole('link', { name: 'Emitir licencia para este cliente' }));
		assert.equal(await page.locator('#customer_id').inputValue(), customerId);

		await page.goto('/admin/licenses/new');
		await page.locator('#customer_id').selectOption(customerId);
		// The initial schema also seeds adel_pos (no modules); the module checks use the synthetic product.
		await page.locator('#product_id').selectOption('adel_pos');
		await page.locator('[value="prueba_e"]').check();
		assert.equal(await page.locator('[value="prueba_a"]').isChecked(), true);
		assert.equal(await page.locator('[value="prueba_c"]').isChecked(), false);
		assert.equal(await page.locator('#reason').getAttribute('required'), null);
		// A perpetual license is the default: only its own field is enabled, with 6 months of updates.
		assert.equal(await page.locator('#updates_months').inputValue(), '6');
		assert.equal(await page.locator('#billing_period').isDisabled(), true);
		assert.equal(await page.locator('#trial_duration').isDisabled(), true);
		assert.deepEqual(
			await page.locator('#plan_id option').evaluateAll((options) => options.map((option) => option.value)),
			['basico', 'elemental', 'premium'],
		);
		// El plan llena los tres cupos; mientras no se cambien, la licencia sigue a su plan.
		const limits = () =>
			Promise.all(
				['cash_registers', 'order_takers', 'kitchen_displays'].map((name) =>
					page.locator('#' + name).inputValue(),
				),
			);
		assert.deepEqual(await limits(), ['1', '0', '0']);
		await page.locator('#plan_id').selectOption('premium');
		assert.deepEqual(await limits(), ['5', '10', '5']);
		assert.equal(await page.locator('#custom-notice').isHidden(), true);
		// Cambiar un número avisa de inmediato que la licencia queda personalizada, y volver al del plan lo quita.
		await page.locator('#order_takers').fill('12');
		assert.equal(await page.locator('#custom-notice').isVisible(), true);
		assert.match(await page.locator('#custom-notice').innerText(), /Licencia personalizada/);
		await page.locator('#order_takers').fill('10');
		assert.equal(await page.locator('#custom-notice').isHidden(), true);
		// El pago de alta sugiere el precio del catálogo del plan y del plazo; el motivo solo se pide si el monto cambia.
		assert.equal(await page.locator('#amount').inputValue(), '15000.00');
		assert.match(await page.locator('#price-note').innerText(), /Precio del catálogo: \$15,000\.00 MXN/);
		assert.equal(await page.locator('#amount_reason').isDisabled(), true);
		await page.locator('#amount').fill('14000');
		assert.equal(await page.locator('#amount_reason').isDisabled(), false);
		await page.locator('#amount_reason').fill('Descuento de lanzamiento');
		// El formulario no deja pasar cupos fuera de 0 a 999.
		assert.deepEqual(
			await page.locator('#cash_registers').evaluate((input) => [input.min, input.max, input.required]),
			['0', '999', true],
		);
		await screenshot('license-form-desktop');
		await submit(page.getByRole('button', { name: 'Emitir y mostrar clave' }));
		const commercialKey = await page.locator('#commercial-key').inputValue();
		assert.match(commercialKey, /^[2-9A-HJKMNP-Z]{3}(?:-[2-9A-HJKMNP-Z]{4}){3}$/);
		await page.getByRole('link', { name: 'Ya la guardé · Ver licencia' }).click();
		await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
		const licenseURL = page.url();
		assert.match(await page.locator('main').innerText(), /Sin vencimiento/);
		// No dates until the license is activated: its initial period can still be edited.
		assert.match(await page.locator('main').innerText(), /Empieza al activar/);
		// Sin activar: solo se edita su periodo inicial; no se renueva ni compra mantenimiento.
		const actions = page.locator('[data-testid="license-actions"]');
		assert.equal(await actions.getByRole('link', { name: 'Editar periodo inicial' }).count(), 1);
		assert.equal(await actions.getByRole('link', { name: 'Renovar' }).count(), 0);
		assert.equal(await actions.getByRole('link', { name: 'Mantenimiento' }).count(), 0);
		assert.match(await page.locator('[data-testid="license-state"]').innerText(), /Sin activar/);
		// Detalle con el modelo del contrato: tipo, plan, cupos y la zona horaria del cliente.
		const rights = async () => {
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
		let detail = await rights();
		assert.equal(detail['Tipo'], 'Perpetua · 6 meses de actualizaciones');
		assert.equal(detail['Plan'], 'Plan premium');
		assert.deepEqual(
			[detail['Cajas'], detail['Comanderos'], detail['Pantallas de cocina (KDS)']],
			['5', '10', '5'],
		);
		assert.equal(detail['Inicio del periodo'], 'Empieza al activar');
		assert.equal(detail['Actualizaciones hasta'], 'Empieza al activar');
		assert.match(detail['Zona horaria del cliente'], /^America\/Tijuana/);
		assert.equal(await page.getByText('Instalaciones', { exact: true }).count(), 0);
		// El servidor rechaza un cupo fuera de rango aunque el formulario se envíe directamente.
		const outOfRange = await context.request.post('/admin/licenses/create', {
			form: {
				csrf: await page.locator('[name="csrf"]').first().inputValue(),
				operation_id: crypto.randomUUID(),
				customer_id: customerId,
				product_id: 'adel_pos',
				license_type: 'perpetual',
				updates_months: '6',
				plan_id: 'basico',
				cash_registers: '1000',
			},
			maxRedirects: 0,
		});
		assert.equal(outOfRange.status(), 422);
		assert.match(await outOfRange.text(), /Cajas debe ser un número entero entre 0 y 999/);
		assert.equal(await page.locator('#until').count(), 0);
		assert.equal((await page.content()).includes(commercialKey), false);
		// The server refuses them even when the request is sent directly.
		const pendingForm = {
			csrf: await page.locator('[name="csrf"]').first().inputValue(),
			version: '1',
			until: future(365),
			reference: 'BROWSER-MAINT',
			reason: 'Compra separada de mantenimiento',
		};
		for (const [operation, message] of [
			// Una perpetua sin activar no necesita mantenimiento todavía, y no se renueva porque no vence.
			['maintenance', /Una perpetua sin activar todavía no necesita mantenimiento/],
			['renew', /Una licencia perpetua no se renueva porque no vence/],
		]) {
			const pending = await context.request.post(licenseURL + '/' + operation, {
				form: { ...pendingForm, operation_id: crypto.randomUUID() },
				maxRedirects: 0,
			});
			assert.equal(pending.status(), 422, operation);
			assert.match(await pending.text(), message);
		}
		await page.goto(licenseURL + '?section=history');
		assert.equal(await page.locator('#commercial-history tbody tr').count(), 1);
		assert.match(await page.locator('#license-audit').innerText(), /Licencia emitida/);
		// El pago de alta queda en el historial, con el precio sugerido y el motivo del cambio de monto.
		await page.goto(licenseURL + '?section=payments');
		const payments = await page.locator('#payments tbody').innerText();
		assert.match(payments, /Alta/);
		assert.match(payments, /\$14,000\.00 MXN/);
		assert.match(payments, /Catálogo: \$15,000\.00 MXN/);
		assert.match(payments, /Monto cambiado: Descuento de lanzamiento/);
		await page.goto(licenseURL);
		await screenshot('license-detail-desktop');
		const metadata = page.locator('form[action$="/administrative"]');
		await metadata.locator('[name="reference"]').fill('Contrato sintético BROWSER-084');
		await submit(metadata.getByRole('button'));
		assert.match(await page.locator('[role="status"]').innerText(), /Datos comerciales actualizados/);
		await page.goto(licenseURL + '?section=documents');
		await submit(page.getByRole('button', { name: 'Generar constancia de licenciamiento', exact: true }));
		assert.match(await page.locator('[role="status"]').innerText(), /Constancia generada/);
		await page.goto(licenseURL + '?section=documents');
		const pdfUrl = await page.getByRole('link', { name: 'Descargar PDF r1', exact: true }).getAttribute('href');
		const pdfResponse = await context.request.get(pdfUrl);
		assert.equal(pdfResponse.status(), 200);
		assert.match(pdfResponse.headers()['content-type'], /application\/pdf/);
		assert.equal((await pdfResponse.body()).subarray(0, 5).toString(), '%PDF-');

		const csrf = await page.locator('[name="csrf"]').first().inputValue();
		let response = await context.request.post('/admin/customers/create', {
			form: { csrf: 'invalid', operation_id: crypto.randomUUID() },
			maxRedirects: 0,
		});
		assert.equal(response.status(), 403);
		response = await context.request.post('/admin/customers/create', {
			headers: { Origin: 'https://untrusted.example' },
			form: { csrf, operation_id: crypto.randomUUID() },
			maxRedirects: 0,
		});
		assert.equal(response.status(), 403);
		response = await context.request.get('/v1/activations/challenge');
		assert.equal(response.status(), 405);
		// Formato de error del contrato: sin «request_id» del cliente va null, y «details» es un objeto.
		assert.deepEqual(await response.json(), {
			error: {
				code: 'INVALID_REQUEST',
				message: 'La solicitud no es válida.',
				request_id: null,
				details: {},
			},
		});

		await page.goto('/admin/licenses/new');
		await page.locator('#customer_id').selectOption(customerId);
		await page.locator('#product_id').selectOption('adel_pos');
		await page.locator('[value="trial"]').check();
		assert.equal(await page.locator('#updates_months').isDisabled(), true);
		assert.equal(await page.locator('#billing_period').isDisabled(), true);
		await page.locator('#trial_duration').selectOption('1m');
		// Una prueba no tiene costo.
		assert.equal(await page.locator('#amount').inputValue(), '0.00');
		assert.equal(await page.locator('#amount').getAttribute('readonly'), '');
		await submit(page.getByRole('button', { name: 'Emitir y mostrar clave' }));
		await page.getByRole('link', { name: 'Ya la guardé · Ver licencia' }).click();
		await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
		assert.match(await page.locator('main').innerText(), /Prueba de 1 mes/);
		assert.match(await page.locator('main').innerText(), /0 días/);
		assert.equal((await rights())['Tipo'], 'Prueba de 1 mes');

		await page.goto('/admin/licenses/new');
		await page.locator('#customer_id').selectOption(customerId);
		await page.locator('#product_id').selectOption('adel_pos');
		await page.locator('[value="subscription"]').check();
		assert.equal(await page.locator('#updates_months').isDisabled(), true);
		assert.equal(await page.locator('#trial_duration').isDisabled(), true);
		await page.locator('#billing_period').selectOption('quarterly');
		await page.locator('#reason').fill('Suscripción de prueba');
		// Plan elemental con una caja más: la licencia queda personalizada.
		await page.locator('#plan_id').selectOption('elemental');
		await page.locator('#cash_registers').fill('4');
		assert.equal(await page.locator('#custom-notice').isVisible(), true);
		// Una licencia personalizada no tiene precio en el catálogo: el monto se escribe a mano.
		assert.equal(await page.locator('#amount').inputValue(), '');
		assert.match(await page.locator('#price-note').innerText(), /personalizada no tiene precio/);
		await page.locator('#amount').fill('1200');
		assert.equal(await page.locator('#amount_reason').isDisabled(), true);
		await submit(page.getByRole('button', { name: 'Emitir y mostrar clave' }));
		await page.getByRole('link', { name: 'Ya la guardé · Ver licencia' }).click();
		await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
		assert.match(await page.locator('main').innerText(), /5 días/);
		assert.match(await page.locator('main').innerText(), /Empieza al activar/);
		detail = await rights();
		assert.equal(detail['Tipo'], 'Suscripción trimestral');
		assert.equal(detail['Plan'], 'Plan elemental Personalizada');
		assert.deepEqual([detail['Cajas'], detail['Comanderos'], detail['Pantallas de cocina (KDS)']], ['4', '3', '3']);
		assert.match(await page.locator('main').innerText(), /Licencia personalizada/);
		const subscriptionURL = page.url();
		await page.goto(subscriptionURL + '?section=history');
		assert.equal(await page.locator('#commercial-history tbody tr').count(), 1);
		await page.goto(subscriptionURL + '?section=payments');
		assert.match(await page.locator('#payments tbody').innerText(), /\$1,200\.00 MXN[\s\S]*Sin precio de catálogo/);
		const offlineDownload = await require('./offline-panel.cjs')({
			page,
			context,
			secret,
			totp,
			freshCode,
			sensitiveDownload,
			licenseURL,
			subscriptionURL,
			commercialKey,
			screenshot,
		});

		// Después de activarse, la licencia muestra su periodo en la zona horaria del cliente, y cada
		// revisión de cierre dice por qué se cerró.
		await page.goto(licenseURL);
		detail = await rights();
		assert.match(detail['Inicio del periodo'], /^\d{2}\/\d{2}\/\d{4} · \d{2}:\d{2} America\/Tijuana$/);
		assert.match(detail['Actualizaciones hasta'], /^\d{2}\/\d{2}\/\d{4} · \d{2}:\d{2} America\/Tijuana$/);
		assert.equal(detail['Vencimiento'], 'Sin vencimiento');
		await page.goto(licenseURL + '?section=revisions');
		const signedStates = await page
			.locator('[data-testid="revision-list"] tbody tr td:nth-child(2)')
			.allInnerTexts();
		assert.deepEqual(
			signedStates.map((text) => text.trim()),
			['Revocada · licencia liberada', 'Activa', 'Revocada · transferida a otra computadora', 'Activa'],
		);
		await screenshot('license-detail-activated-desktop');

		// Ciclo comercial: renovar, subir de plan, programar y cancelar una reducción, convertir una prueba,
		// comprar mantenimiento y propagar un aumento del catálogo.
		await require('./commercial-panel.cjs')({ page, screenshot });

		// Transferencias y alertas (etapa 4): AD-EL POS simulado por la API, la alerta en la barra y en el
		// resumen, marcarla como revisada, liberar el contador y la alerta de transferencias frecuentes.
		await require('./transfers-panel.cjs')({ page, secret, freshCode, screenshot });

		for (const route of [
			'/admin/customers',
			'/admin/products',
			'/admin/products/adel_pos',
			'/admin/plans',
			'/admin/plans/elemental',
			'/admin/plans/new',
			'/admin/licenses',
			'/admin/offline',
			'/admin/audit',
			'/admin/users',
			'/admin/security',
		]) {
			const result = await page.goto(route);
			assert.equal(result.status(), 200, route);
		}
		await page.setViewportSize({ width: 390, height: 844 });
		await page.goto('/admin');
		await screenshot('dashboard-mobile');
		await assertBrand('resumen en móvil', false);
		assert.equal(
			await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
			true,
			await page.evaluate(() =>
				[...document.querySelectorAll('body *')]
					.filter(
						(element) =>
							element.getBoundingClientRect().right > window.innerWidth + 1 &&
							getComputedStyle(element).position !== 'fixed',
					)
					.map((element) => element.tagName + '.' + element.className)
					.slice(0, 20)
					.join(', '),
			),
		);
		await page.getByRole('button', { name: 'Abrir navegación' }).click();
		assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'true');
		await page.keyboard.press('Escape');
		assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'false');

		const viewer = await browser.newContext({ baseURL });
		const readPage = await viewer.newPage();
		await readPage.goto('/login');
		await readPage.locator('#login').fill('viewer@example.test');
		await readPage.locator('#password').fill('Fixture-Viewer-Only-2026!');
		await Promise.all([readPage.waitForURL('**/mfa'), readPage.getByRole('button', { name: 'Continuar' }).click()]);
		await readPage.locator('#code').fill(totp('JBSWY3DPEHPK3PXP'));
		await Promise.all([
			readPage.waitForURL('**/trust-device'),
			readPage.getByRole('button', { name: 'Verificar e ingresar' }).click(),
		]);
		await Promise.all([
			readPage.waitForURL('**/admin'),
			readPage.getByRole('button', { name: 'Ahora no' }).click(),
		]);
		await readPage.goto('/admin/licenses');
		assert.equal(await readPage.getByRole('link', { name: 'Emitir licencia', exact: true }).count(), 0);
		const viewerCsrf = await readPage.locator('[name="csrf"]').first().inputValue();
		response = await viewer.request.post('/admin/customers/create', {
			form: {
				csrf: viewerCsrf,
				operation_id: crypto.randomUUID(),
				name: 'Unauthorized',
				contact_name: 'Attempt',
				email: '',
				state: 'active',
			},
			maxRedirects: 0,
		});
		assert.equal(response.status(), 403);
		response = await viewer.request.get('/admin/users');
		assert.equal(response.status(), 403);
		// El catálogo de planes se consulta con cualquier cuenta, pero solo el superadministrador lo edita.
		response = await viewer.request.get('/admin/plans');
		assert.equal(response.status(), 200);
		response = await viewer.request.get('/admin/plans/new');
		assert.equal(response.status(), 403);
		response = await viewer.request.get(signedDownload);
		assert.equal(response.status(), 403);
		response = await viewer.request.get(offlineDownload);
		assert.equal(response.status(), 403);
		response = await viewer.request.post('/admin/offline/import', {
			multipart: {
				csrf: viewerCsrf,
				operation_id: crypto.randomUUID(),
				request_file: { name: 'request.licreq', mimeType: 'application/json', buffer: Buffer.from('{}') },
			},
		});
		assert.equal(response.status(), 403);
		await viewer.close();
		assert.deepEqual(errors, [], 'No JavaScript or console errors');
		console.log(
			'Panel OK: login, MFA, roles, CSRF/origin, issuance of the three license types, ' +
				'commercial cycle (renewal, plan change with credit, scheduled reduction, trial conversion, ' +
				'maintenance and catalog propagation), self-service transfers and alerts (top bar, summary, review, ' +
				'manual release and frequent transfers), offline upload/review/approval/rejection/download, ' +
				'forced transfer with reauthentication, signed deactivation and responsive navigation.',
		);
		console.log('Screenshots: var/screenshots (synthetic data, no credentials).');
	} catch (error) {
		if (process.env.PANEL_DEBUG && new URL(page.url()).pathname === '/login')
			console.error(await page.locator('main').innerText());
		console.error(error);
		process.exitCode = 1;
	} finally {
		await browser.close();
	}
})();
