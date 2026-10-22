// Browser regression checks with intercepted HTTP only: no cloud access or real accounts.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
const html = `<meta name="csrf-token" content="synthetic">
<div id="management" data-module="announcements"><p id="form-status" role="status"></p>
<form id="editor" data-endpoint="/api/v1/management/content/announcements">
<input type="hidden" name="id"><input type="file" name="image">
<input name="title" required><textarea name="body" required></textarea>
<input type="checkbox" name="published" checked><button>Save content</button>
</form><section id="records"></section><span id="manage-page"></span>
<button id="manage-prev"></button><button id="manage-next"></button></div>`;

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let item = null;
        let mode = 'success';
        const writes = [];
        let releaseSave;
        let releaseUpload;
        let saveGate = Promise.resolve();
        let uploadGate = Promise.resolve();
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            assert.equal(url.hostname, 'cdrmapp.test', 'All browser requests must remain on the synthetic origin.');
            if (url.pathname === '/notice.png') return route.fulfill({ contentType: 'image/png', body: png });
            if (!url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'text/html', body: html });
            if (request.method() === 'GET') return route.fulfill({ json: { data: item ? [item] : [], last_page: 1 } });
            writes.push({ method: request.method(), path: url.pathname });
            if (url.pathname.endsWith('/image')) {
                assert.ok(request.postDataBuffer().includes(png), 'The selected file must reach the upload endpoint.');
                await uploadGate;
                if (mode === 'storage-failure') return route.fulfill({ status: 503, json: { error: { message: 'Image storage is unavailable. Please retry.' } } });
                if (mode === 'html-failure') return route.fulfill({ status: 413, contentType: 'text/html', body: '<h1>Payload too large</h1>' });
                if (mode === 'unconfirmed') return route.fulfill({ json: { data: { id: 123 } } });
                item.image_path = 'content/notice.png'; item.image_url = 'http://cdrmapp.test/notice.png';
                return route.fulfill({ json: { data: item } });
            }
            await saveGate;
            item = { ...item, ...request.postDataJSON(), id: 123 };
            return route.fulfill({ json: { data: item } });
        });
        await page.goto('http://cdrmapp.test/manage/announcements');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.getByText('No records yet.', { exact: true }).waitFor();
        const status = page.locator('#form-status');
        const save = page.getByRole('button', { name: 'Save content', exact: true });
        await save.click();
        assert.equal(await status.textContent(), 'Complete the highlighted fields before saving.');
        assert.equal(writes.length, 0);
        await page.locator('[name=title]').fill('Synthetic notice');
        await page.locator('[name=body]').fill('Synthetic browser image test.');
        const chooseImage = () => page.locator('input[type=file]').setInputFiles({ name: 'notice.png', mimeType: 'image/png', buffer: png });
        await chooseImage();
        saveGate = new Promise(resolve => { releaseSave = resolve; });
        uploadGate = new Promise(resolve => { releaseUpload = resolve; });
        await save.click();
        await page.getByText('Saving content…', { exact: true }).waitFor();
        assert.equal(await save.isDisabled(), true);
        releaseSave();
        await page.getByText('Content saved. Uploading image…', { exact: true }).waitFor();
        releaseUpload();
        await page.getByText('Content and image saved successfully.', { exact: true }).waitFor();
        await page.getByText('Saved image loaded.', { exact: true }).waitFor();
        assert.equal(writes.filter(request => request.path.endsWith('/image')).length, 1);
        assert.equal(await page.locator('input[type=file]').inputValue(), '');

        assert.equal(await page.locator('input[name=id]').inputValue(), '');
        assert.equal(await page.locator('[name=title]').inputValue(), '');
        assert.equal(await page.locator('[name=body]').inputValue(), '');
        await page.locator('[name=title]').fill('Synthetic retry notice');
        await page.locator('[name=body]').fill('Keep this description on upload failure.');
        await page.locator('input[name=id]').evaluate(input => { input.value = '123'; });

        for (const [failure, expected] of [
            ['storage-failure', 'Image storage is unavailable. Please retry.'],
            ['html-failure', 'Image upload failed (HTTP 413).'],
            ['unconfirmed', 'The server did not confirm a saved image. Please retry the image upload.'],
        ]) {
            mode = failure;
            await chooseImage();
            await save.click();
            await page.getByText(`Content saved, but image upload failed: ${expected}`, { exact: true }).waitFor();
            assert.equal(await save.isDisabled(), false);
            assert.equal(await page.locator('input[name=id]').inputValue(), '123');
            assert.ok(await page.locator('input[type=file]').inputValue(), 'Keep the image selected for retry.');
            assert.equal(await page.locator('[name=title]').inputValue(), 'Synthetic retry notice');
        }
        mode = 'success';
        await save.click();
        await page.getByText('Content and image saved successfully.', { exact: true }).waitFor();
        assert.equal(writes.filter(request => request.method === 'POST' && !request.path.endsWith('/image')).length, 1, 'Retries must update the same announcement.');
        await page.locator('[name=title]').fill('Synthetic text notice');
        await page.locator('[name=body]').fill('No image selected.');
        await save.click();
        await page.getByText('Content saved successfully.', { exact: true }).waitFor();
        assert.equal(await page.locator('[name=title]').inputValue(), '');
        assert.deepEqual(errors, []);
        console.log('PASS: validation feedback, save/upload progress, selected file submission, preview, storage/HTTP/unconfirmed failures, retry without duplicate announcements, and no-image feedback.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
