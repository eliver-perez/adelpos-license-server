'use strict';

const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

// Sólo admite el origen HTTPS temporal de NativeStackFixture y cuentas sintéticas.
const origin = process.env.TRUST_PANEL_ORIGIN;
assert.match(origin || '', /^https:\/\/127\.0\.0\.1:\d+$/);
const password = 'Fixture-Password-Only-2026!';
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = crypto.createHmac('sha1', key).update(counter).digest();
    return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const context = await browser.newContext({ baseURL: origin, ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error' && !message.text().includes('401')) errors.push(message.text()); });
    const screenshotDir = path.join(__dirname, '../var/screenshots');
    fs.mkdirSync(screenshotDir, { recursive: true });
    async function submit(button) { await Promise.all([page.waitForNavigation(), button.click()]); }
    async function login(value = password) {
        await page.goto('/login');
        await page.locator('#login').fill('browser@example.test');
        await page.locator('#password').fill(value);
        await submit(page.getByRole('button', { name: 'Continuar' }));
    }
    try {
        await login();
        assert.equal(new URL(page.url()).pathname, '/mfa');
        const secret = await page.locator('[data-testid="totp-secret"]').textContent();
        await page.locator('#code').fill(totp(secret));
        await submit(page.getByRole('button', { name: 'Verificar e ingresar' }));
        assert.equal(await page.locator('.recovery-grid code').count(), 8);
        await page.getByRole('link', { name: 'Ya los guardé · Continuar' }).click();
        await page.waitForURL('**/trust-device');
        assert.equal((await context.cookies()).some(cookie => cookie.name === '__Host-adelpos_trusted_device'), false);
        await page.screenshot({ path: path.join(screenshotDir, 'trusted-device-offer-desktop.png'), fullPage: true, animations: 'disabled' });
        await submit(page.getByRole('button', { name: 'Confiar en este dispositivo', exact: true }));
        await page.waitForURL('**/admin');
        const trust = (await context.cookies()).find(cookie => cookie.name === '__Host-adelpos_trusted_device');
        assert.ok(trust);
        assert.equal(trust.secure, true);
        assert.equal(trust.httpOnly, true);
        assert.equal(trust.sameSite, 'Lax');
        assert.equal(trust.domain, '127.0.0.1');
        assert.equal(trust.path, '/');
        assert.ok(trust.expires > Date.now() / 1000 + 29 * 86400);
        assert.equal(await page.evaluate(() => document.cookie.includes('__Host-adelpos_trusted_device')), false);
        await page.goto('/admin/security');
        assert.equal(await page.getByText('Dispositivo actual', { exact: true }).count(), 1);
        assert.equal((await page.content()).includes(trust.value), false);
        await page.screenshot({ path: path.join(screenshotDir, 'trusted-devices-security-desktop.png'), fullPage: true, animations: 'disabled' });
        await submit(page.getByRole('button', { name: 'Salir', exact: true }));
        assert.ok((await context.cookies()).some(cookie => cookie.name === trust.name));
        await login('Wrong-Password-Only-2026!');
        assert.equal(new URL(page.url()).pathname, '/login');
        assert.match(await page.locator('[role="alert"]').innerText(), /No se pudo iniciar sesión/);
        await login();
        assert.equal(new URL(page.url()).pathname, '/admin');
        assert.equal((await context.cookies()).find(cookie => cookie.name === trust.name).expires, trust.expires);
        await page.goto('/admin/security');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(screenshotDir, 'trusted-devices-security-mobile.png'), fullPage: true, animations: 'disabled' });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.getByText('Revocar dispositivo', { exact: true }).click();
        await submit(page.getByRole('button', { name: 'Cancelar', exact: true }).first());
        await page.waitForURL('**/admin/security');
        assert.equal(await page.getByText('Dispositivo actual', { exact: true }).count(), 1);
        await page.getByText('Revocar dispositivo', { exact: true }).click();
        await submit(page.getByRole('button', { name: 'Confirmar revocación', exact: true }));
        assert.match(await page.locator('[role="status"]').innerText(), /Se revocó la confianza/);
        await page.goto('/admin');
        assert.equal(new URL(page.url()).pathname, '/admin');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await submit(page.getByRole('button', { name: 'Salir', exact: true }));
        await login();
        assert.equal(new URL(page.url()).pathname, '/mfa');
        assert.deepEqual(errors, []);
        console.log('Dispositivos de confianza OK: TOTP, confirmación explícita, cookie protegida, contraseña obligatoria, cierre de sesión, confianza sin extensión, listado, cancelación, revocación y vistas de escritorio/móvil.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
