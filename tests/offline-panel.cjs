'use strict';
const crypto = require('node:crypto');
const assert = require('node:assert/strict');

module.exports = async function offlinePanel({
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
}) {
	const licenseId = new URL(licenseURL).pathname.split('/').pop();
	const subscriptionId = new URL(subscriptionURL).pathname.split('/').pop();
	const device = () => {
		const pair = crypto.generateKeyPairSync('ed25519');
		return {
			id: crypto.randomUUID(),
			private: pair.privateKey,
			public: pair.publicKey.export({ type: 'spki', format: 'der' }).subarray(-32).toString('base64url'),
		};
	};
	const request = (installation, action = 'activate', activation) => {
		const payload = {
			action,
			request_id: crypto.randomUUID(),
			created_at: '2000-01-01T00:00:00Z',
			product_id: 'adel_pos',
			server_id: installation.id,
			installation_public_key: installation.public,
			app_version: '0.12.0',
		};
		if (activation) {
			Object.assign(payload, { license_id: activation.license_id, activation_id: activation.activation_id });
		} else {
			// El código comercial es opcional y solo viaja al activar.
			payload.license_key = commercialKey;
		}
		const encoded = Buffer.from(JSON.stringify(payload)).toString('base64url');
		return Buffer.from(
			JSON.stringify({
				schema_version: '1.0',
				payload_b64u: encoded,
				signature_b64u: crypto
					.sign(null, Buffer.from('ADELPOS-LICREQ-V1\n' + encoded), installation.private)
					.toString('base64url'),
			}),
		);
	};
	const submit = async (button) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
	async function upload(bytes) {
		await page.goto('/admin/offline');
		await page
			.locator('#request_file')
			.setInputFiles({ name: 'request.licreq', mimeType: 'application/json', buffer: bytes });
		await submit(page.getByRole('button', { name: 'Verificar e importar' }));
		assert.match(new URL(page.url()).pathname, /^\/admin\/offline\/[a-f0-9-]{36}$/);
		assert.equal((await page.content()).includes(commercialKey), false);
		return new URL(page.url()).pathname;
	}
	async function choose(id) {
		await page.locator('select[name="license_id"]').selectOption(id);
		await submit(page.getByRole('button', { name: 'Revisar derechos' }));
	}
	async function approve() {
		const form = page.locator('form[action$="/approve"]');
		if (await form.locator('[name="accept_offline_limit"]').count()) {
			await form.locator('[name="accept_offline_limit"]').check();
			await form.locator('[name="password"]').fill('Fixture-Password-Only-2026!');
			await form.locator('[name="code"]').fill(await freshCode(secret));
		}
		await page.locator('form[action$="/approve"] [name="reason"]').fill('Autorización comercial de prueba offline');
		await submit(page.getByRole('button', { name: /Aprobar y generar|Confirmar transferencia/ }));
		assert.equal(await page.getByRole('link', { name: 'Descargar .lic', exact: true }).count(), 1);
		const url = await page.getByRole('link', { name: 'Descargar .lic', exact: true }).getAttribute('href');
		const response = await sensitiveDownload(url, secret);
		assert.equal(response.status(), 200);
		assert.match(response.headers()['content-type'], /^text\/plain/);
		const jws = await response.text();
		assert.match(jws, /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{86}$/);

		return { url, jws, payload: JSON.parse(Buffer.from(jws.split('.')[1], 'base64url').toString('utf8')) };
	}
	const oldDevice = device();
	const file = request(oldDevice);
	const imported = await upload(file);
	assert.match(await page.locator('main').innerText(), /Pendiente/);
	const csrf = await page.locator('[name="csrf"]').first().inputValue();
	assert.equal((await context.request.get(imported + '/download')).status(), 409);
	let response = await context.request.post('/admin/offline/import', {
		multipart: {
			csrf: 'invalid',
			operation_id: crypto.randomUUID(),
			request_file: { name: 'request.licreq', mimeType: 'application/json', buffer: file },
		},
	});
	assert.equal(response.status(), 403);
	response = await context.request.post('/admin/offline/import', {
		headers: { Origin: 'https://untrusted.example' },
		multipart: {
			csrf,
			operation_id: crypto.randomUUID(),
			request_file: { name: 'request.licreq', mimeType: 'application/json', buffer: file },
		},
	});
	assert.equal(response.status(), 403);
	response = await context.request.post('/admin/offline/import', {
		multipart: {
			csrf,
			operation_id: crypto.randomUUID(),
			request_file: { name: 'large.licreq', mimeType: 'application/json', buffer: Buffer.alloc(65537, 32) },
		},
	});
	assert.equal(response.status(), 413);
	response = await context.request.post('/admin/offline/import', {
		multipart: {
			csrf,
			operation_id: crypto.randomUUID(),
			request_file: {
				name: 'invalid.licreq',
				mimeType: 'application/json',
				buffer: Buffer.from('{"schema_version":"1.0","schema_version":"1.0"}'),
			},
		},
	});
	assert.equal(response.status(), 400);
	assert.equal(await upload(file), imported);
	await choose(licenseId);
	await screenshot('offline-review-desktop');
	await page.setViewportSize({ width: 390, height: 844 });
	assert.equal(
		await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
		true,
		'Offline review fits mobile',
	);
	await screenshot('offline-review-mobile');
	await page.setViewportSize({ width: 1440, height: 1000 });
	const activated = await approve();
	assert.equal(activated.payload.server_id, oldDevice.id);
	assert.equal(activated.payload.license_status, 'active');
	assert.equal(activated.payload.status_reason, null);
	assert.equal(activated.payload.license_revision, 1);
	await screenshot('offline-approved-desktop');
	const approvedDownload = activated.url;

	const newDevice = device();
	await upload(request(newDevice));
	await choose(licenseId);
	const transferForm = page.locator('form[action$="/approve"]');
	await transferForm.locator('[name="force_activation_id"]').check();
	await transferForm.locator('[name="accept_offline_limit"]').check();
	const values = await transferForm.evaluate((form) => Object.fromEntries(new FormData(form)));
	response = await context.request.post(await transferForm.getAttribute('action'), {
		form: { ...values, reason: 'Rejected password test', password: 'Incorrect-Password', code: totp(secret, 1) },
		maxRedirects: 0,
	});
	assert.equal(response.status(), 401);
	await transferForm.locator('[name="password"]').fill('Fixture-Password-Only-2026!');
	const transferred = await approve();
	assert.equal(transferred.payload.license_revision, 3);
	assert.notEqual(transferred.payload.activation_id, activated.payload.activation_id);
	assert.equal(transferred.payload.server_id, newDevice.id);
	// El detalle de la licencia nombra el estado en que quedó la computadora anterior.
	const detail = await (await context.request.get(licenseURL + '?section=computers')).text();
	assert.match(detail, /Transferida/);
	assert.doesNotMatch(detail, /Undefined array key|Warning/);

	await upload(request(newDevice, 'deactivate', transferred.payload));
	const deactivated = await approve();
	assert.equal(deactivated.payload.license_status, 'revoked');
	assert.equal(deactivated.payload.status_reason, 'deactivated');
	assert.equal(deactivated.payload.license_revision, 4);

	const subDevice = device();
	await upload(request(subDevice));
	await choose(subscriptionId);
	const subscription = await approve();
	await upload(request(subDevice, 'renew', subscription.payload));
	// Una solicitud de renovación puede entregar los derechos vigentes o registrar ahí mismo la renovación.
	const renewForm = page.locator('form[action$="/approve"]');
	assert.equal(await renewForm.locator('select[name="renewal_mode"]').count(), 1);
	const renewValues = await renewForm.evaluate((form) => Object.fromEntries(new FormData(form)));
	// Registrar la renovación exige su monto: sin él, la solicitud sigue pendiente.
	response = await context.request.post(await renewForm.getAttribute('action'), {
		form: {
			...renewValues,
			renewal_mode: 'extend',
			amount: '',
			reason: 'Renovación sin monto',
		},
		maxRedirects: 0,
	});
	assert.equal(response.status(), 422);
	assert.match(await response.text(), /Escribe el monto cobrado/);
	const renewed = await approve();
	assert.equal(renewed.payload.activation_id, subscription.payload.activation_id);
	// «renew» entrega la revisión vigente: es el mismo archivo firmado, no una revisión nueva.
	assert.equal(renewed.payload.license_revision, 1);
	assert.equal(renewed.jws, subscription.jws);
	assert.equal(renewed.payload.grace_days, 5);
	// Otra solicitud: esta vez se registra la renovación trimestral con su pago, y el archivo trae la revisión nueva.
	await upload(request(subDevice, 'renew', subscription.payload));
	const extendForm = page.locator('form[action$="/approve"]');
	await extendForm.locator('[name="renewal_mode"]').selectOption('extend');
	await extendForm.locator('[name="billing_period"]').selectOption('quarterly');
	await extendForm.locator('[name="amount"]').fill('1200');
	await extendForm.locator('[name="reference"]').fill('BROWSER-OFFLINE-RENEW');
	const extended = await approve();
	assert.equal(extended.payload.activation_id, subscription.payload.activation_id);
	assert.equal(extended.payload.license_revision, 2);
	assert.equal(extended.payload.billing_period, 'quarterly');
	assert.equal(extended.payload.period_starts_at, subscription.payload.expires_at);
	assert.notEqual(extended.payload.expires_at, subscription.payload.expires_at);

	await upload(request(device()));
	const rejection = page.locator('form[action$="/reject"]');
	await rejection.locator('[name="reason"]').fill('No existe autorización comercial para esta solicitud');
	await submit(rejection.getByRole('button'));
	assert.match(await page.locator('main').innerText(), /Rechazada/);
	assert.equal(await page.getByRole('link', { name: 'Descargar .lic', exact: true }).count(), 0);
	return approvedDownload;
};
