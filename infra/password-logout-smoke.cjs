// Intercepted browser HTTP only; no real passwords, accounts, or email delivery.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let successful = false;
        await page.route('**/*', route => {
            const request = route.request(), url = new URL(request.url());
            assert.equal(url.hostname, 'cdrmapp.test');
            if (url.pathname === '/api/v1/me/change-password') {
                return successful
                    ? route.fulfill({ json: { data: { message: 'Password updated. Please sign in again.', redirect: '/login' } } })
                    : route.fulfill({ status: 422, json: { error: { message: 'The current password is incorrect.' } } });
            }
            if (url.pathname === '/login') return route.fulfill({ contentType: 'text/html', body: '<h1>Staff sign in</h1>' });
            return route.fulfill({ contentType: 'text/html', body: `<meta name="csrf-token" content="synthetic"><p id="form-status"></p>
                <form class="api-form" data-endpoint="/api/v1/me/change-password" data-method="POST">
                <input name="current_password" type="password" required><input name="password" type="password" required>
                <input name="password_confirmation" type="password" required><button>Update password &amp; sign out</button></form>` });
        });
        await page.goto('http://cdrmapp.test/profile');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.locator('[name=current_password]').fill('SyntheticOldPassword123');
        await page.locator('[name=password]').fill('SyntheticNewPassword456');
        await page.locator('[name=password_confirmation]').fill('SyntheticNewPassword456');
        await page.getByRole('button', { name: 'Update password & sign out', exact: true }).click();
        await page.getByText('The current password is incorrect.', { exact: true }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/profile');
        assert.equal(await page.locator('[name=password]').inputValue(), 'SyntheticNewPassword456');
        successful = true;
        await page.getByRole('button', { name: 'Update password & sign out', exact: true }).click();
        await page.waitForURL('http://cdrmapp.test/login');
        await page.getByRole('heading', { name: 'Staff sign in', exact: true }).waitFor();
        assert.deepEqual(errors, []);
        console.log('PASS: successful password update returns to Login; failed update stays on the profile without clearing entered fields.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
