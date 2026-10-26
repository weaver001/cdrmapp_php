// Intercepted HTTP only: verifies the page-settings editor without real accounts or mail.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const errors = [];
        for (const type of ['services', 'announcements', 'team-members', 'faqs', 'contact-details']) {
            const page = await browser.newPage({ serviceWorkers: 'block' });
            page.on('pageerror', error => errors.push(error.message));
            let fail = false;
            let saved = null;
            await page.route('**/*', route => {
                const request = route.request(), url = new URL(request.url());
                assert.equal(url.hostname, 'cdrmapp.test');
                if (url.pathname.startsWith('/api/')) {
                    assert.equal(url.pathname, `/api/v1/management/pages/${type}`);
                    assert.equal(request.method(), 'PATCH');
                    if (fail) return route.fulfill({ status: 422, json: { error: { fields: { heading: ['The page heading could not be saved.'] } } } });
                    saved = request.postDataJSON();
                    return route.fulfill({ json: { data: { ...saved, type }, message: 'Page updated successfully.' } });
                }
                return route.fulfill({ contentType: 'text/html', body: `<meta name="csrf-token" content="synthetic">
                    <form id="page-editor" data-endpoint="/api/v1/management/pages/${type}">
                    <input name="eyebrow" value="Community"><input name="heading" required value="Original heading">
                    <textarea name="introduction">Original introduction</textarea>
                    ${type === 'contact-details' ? '<input name="form_heading" value="Contact us" required><textarea name="form_introduction">Non-emergency enquiries</textarea>' : ''}
                    <button>Save page</button><p id="page-status" role="status"></p></form>` });
            });
            await page.goto(`http://cdrmapp.test/manage/${type}`);
            await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
            await page.locator('[name=heading]').fill(`Updated ${type}`);
            await page.locator('[name=introduction]').fill('First paragraph.\nSecond paragraph.');
            if (type === 'contact-details') await page.locator('[name=form_heading]').fill('Write to our team');
            await page.getByRole('button', { name: 'Save page', exact: true }).click();
            await page.getByText('Page updated successfully.', { exact: true }).waitFor();
            assert.equal(saved.heading, `Updated ${type}`);
            assert.equal(saved.introduction, 'First paragraph.\nSecond paragraph.');
            assert.equal(await page.locator('[name=heading]').inputValue(), `Updated ${type}`, 'Settings stay populated after saving.');
            if (type === 'contact-details') assert.equal(saved.form_heading, 'Write to our team');
            fail = true;
            await page.locator('[name=heading]').fill('Unsaved changes');
            await page.getByRole('button', { name: 'Save page', exact: true }).click();
            await page.getByText('The page heading could not be saved.', { exact: true }).waitFor();
            assert.equal(await page.locator('[name=heading]').inputValue(), 'Unsaved changes');
            assert.equal(await page.getByRole('button', { name: 'Save page', exact: true }).isDisabled(), false);
            await page.close();
        }
        assert.deepEqual(errors, []);
        console.log('PASS: all five CMS page editors send the correct page settings, show success, retain saved settings, preserve failed edits, and support contact form text.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
