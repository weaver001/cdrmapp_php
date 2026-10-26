// Isolated browser checks: every HTTP request is intercepted on a synthetic origin.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8');
const shell = (module, form = '') => `<meta name="csrf-token" content="synthetic">
<div id="management" data-module="${module}"><p id="form-status" role="status"></p>${form}
<section id="records"></section><span id="manage-page"></span><button id="manage-prev"></button><button id="manage-next"></button></div>`;
const form = (module, fields, label) => `<form id="editor" data-endpoint="/api/v1/management/${module}"><input type="hidden" name="id">${fields}<button>${label}</button><button type="reset">Clear Field</button></form>`;

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const errors = [];
        async function open(module, html, handle) {
            const page = await browser.newPage({ serviceWorkers: 'block' });
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                const request = route.request();
                assert.equal(new URL(request.url()).hostname, 'cdrmapp.test');
                return request.url().includes('/api/') ? handle(route) : route.fulfill({ contentType: 'text/html', body: html });
            });
            await page.goto(`http://cdrmapp.test/manage/${module}`);
            await page.addScriptTag({ type: 'module', content: source });
            await page.waitForFunction(() => document.querySelector('#manage-page').textContent.length > 0);
            return page;
        }

        let account = null, invalid = false, resetFailure = false;
        const userWrites = [];
        const users = await open('users', shell('users', form('users', `<input name="name" required><input name="email" type="email" required><select name="role" required><option value="">Select role</option><option value="citizen">Citizen</option></select><select name="agency_id"><option value="">None</option></select><input type="checkbox" name="active" checked>`, 'Save account')), async route => {
            const request = route.request();
            if (request.method() === 'GET') return route.fulfill({ json: { data: account ? [account] : [], last_page: 1 } });
            if (new URL(request.url()).pathname.endsWith('/reset-password')) {
                return resetFailure
                    ? route.fulfill({ status: 422, json: { error: { message: 'Please wait before retrying.' } } })
                    : route.fulfill({ json: { data: { message: 'Password reset requested.' } } });
            }
            userWrites.push(request.method());
            if (invalid) return route.fulfill({ status: 422, json: { error: { message: 'Email already exists.' } } });
            account = { ...request.postDataJSON(), id: 12 };
            return route.fulfill({ json: { data: account, message: 'Account saved successfully.' } });
        });
        const fillAccount = async () => {
            await users.locator('[name=name]').fill('Synthetic Officer');
            await users.locator('[name=email]').fill('synthetic@example.test');
            await users.locator('[name=role]').selectOption('citizen');
        };
        await fillAccount();
        await users.getByRole('button', { name: 'Save account', exact: true }).click();
        await users.getByText('Account saved successfully.', { exact: true }).waitFor();
        for (const name of ['id', 'name', 'email', 'role', 'agency_id']) assert.equal(await users.locator(`[name=${name}]`).inputValue(), '');
        await users.getByRole('button', { name: 'Edit', exact: true }).click();
        assert.equal(await users.locator('[name=id]').inputValue(), '12');
        await users.getByRole('button', { name: 'Save account', exact: true }).click();
        await users.getByText('Account saved successfully.', { exact: true }).waitFor();
        assert.deepEqual(userWrites, ['POST', 'PATCH']);
        assert.equal(await users.locator('[name=id]').inputValue(), '');
        invalid = true;
        await fillAccount();
        await users.getByRole('button', { name: 'Save account', exact: true }).click();
        await users.getByText('Email already exists.', { exact: true }).waitFor();
        assert.equal(await users.locator('[name=email]').inputValue(), 'synthetic@example.test');
        await users.getByRole('button', { name: 'Clear Field', exact: true }).click();
        assert.equal(await users.locator('[name=email]').inputValue(), '');
        assert.equal(await users.locator('#form-status').textContent(), '');
        const popups = [];
        users.on('dialog', async dialog => { popups.push(dialog.message()); await dialog.dismiss(); });
        await users.getByRole('button', { name: 'Send password reset', exact: true }).click();
        await users.getByText('Password reset requested.', { exact: true }).waitFor();
        assert.deepEqual(popups, ['Success']);
        resetFailure = true;
        await users.getByRole('button', { name: 'Send password reset', exact: true }).click();
        await users.getByText('Please wait before retrying.', { exact: true }).waitFor();
        assert.deepEqual(popups, ['Success'], 'Failed reset requests must not show a success popup.');

        const audit = await open('audit', shell('audit'), route => route.fulfill({ json: { data: [{
            id: 1, action: 'content.deleted', actor_name: 'Synthetic Administrator', record_label: 'Community readiness notice', created_at: '2026-10-22T12:00:00Z',
            metadata: { before: { title: 'Community readiness notice', body: '<script>window.auditInjected=true</script>' }, after: {} },
        }], last_page: 1 } }));
        await audit.getByRole('heading', { name: 'Removed content', exact: true }).waitFor();
        await audit.getByText('Community readiness notice', { exact: true }).waitFor();
        assert.ok((await audit.locator('#records').textContent()).includes('By Synthetic Administrator'));
        assert.ok((await audit.locator('#records').textContent()).includes('<script>window.auditInjected=true</script> → None'));
        assert.equal(await audit.evaluate(() => window.auditInjected), undefined);
        assert.deepEqual(errors, []);
        console.log('PASS: account create/edit success and clearing, failed save preservation, Clear Field, reset success popup, and readable audit actor/subject/changes with safe rendering.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
