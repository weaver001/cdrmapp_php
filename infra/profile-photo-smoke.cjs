// Requests are intercepted: no real accounts, storage buckets or notifications.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
const avatar = url => `<span data-profile-avatar><span>S</span><img data-avatar-image alt="" ${url ? `src="${url}"` : 'hidden'}></span>`;

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let photoUrl = null, version = 0, mode = 'success', broken = false;
        let gate = Promise.resolve(), release;
        await page.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            assert.equal(url.hostname, 'cdrmapp.test');
            if (url.pathname === '/api/v1/me/photo') {
                if (request.method() === 'GET') return route.fulfill({ status: broken ? 404 : 200, contentType: 'image/png', headers: {'Cache-Control':'private, no-store'}, body: broken ? Buffer.alloc(0) : png });
                assert.ok(request.postDataBuffer().includes(png));
                await gate;
                if (mode === 'failure') return route.fulfill({status:503,json:{error:{message:'Profile image storage is unavailable. Please retry.'}}});
                photoUrl = `/api/v1/me/photo?v=${++version}`;
                return route.fulfill({json:{data:{message:'Profile image updated.',photo_url:photoUrl}}});
            }
            return route.fulfill({contentType:'text/html',body:`<aside>${avatar(photoUrl)}<span>Synthetic ${url.searchParams.get('role')}</span></aside>
                <p id="form-status" role="status"></p><form id="photo-form">${avatar(photoUrl)}<label>Profile photo<input type="file" name="image" required></label><button>Upload photo</button></form>`});
        });
        const installScript = () => page.addScriptTag({type:'module',content:fs.readFileSync(path.join(__dirname,'../backend/resources/js/app.js'),'utf8')});
        const choose = () => page.locator('input[type=file]').setInputFiles({name:'profile.png',mimeType:'image/png',buffer:png});
        for (const role of ['admin','officer']) {
            photoUrl = null; mode = 'success'; broken = false;
            await page.goto(`http://cdrmapp.test/profile?role=${role}`);
            await installScript();
            assert.equal(await page.locator('[data-avatar-image][hidden]').count(), 2);
            gate = new Promise(resolve => {release=resolve;});
            await choose(); await page.getByRole('button',{name:'Upload photo'}).click();
            await page.getByText('Uploading profile photo…',{exact:true}).waitFor();
            assert.equal(await page.getByRole('button',{name:'Upload photo'}).isDisabled(), true);
            release();
            await page.getByText('Profile image updated.',{exact:true}).waitFor();
            await page.waitForFunction(() => [...document.querySelectorAll('[data-avatar-image]')].every(i=>!i.hidden && i.naturalWidth>0));
            assert.equal(await page.locator('input[type=file]').evaluate(i=>i.files.length), 0);
            assert.ok((await page.locator('[data-avatar-image]').evaluateAll(images=>images.map(i=>i.getAttribute('src')))).every(src=>src===photoUrl));
            const previous = photoUrl;
            mode = 'failure';
            await choose(); await page.getByRole('button',{name:'Upload photo'}).click();
            await page.getByText('Profile image storage is unavailable. Please retry.',{exact:true}).waitFor();
            assert.equal(await page.locator('input[type=file]').evaluate(i=>i.files.length), 1);
            assert.ok((await page.locator('[data-avatar-image]').evaluateAll(images=>images.map(i=>i.getAttribute('src')))).every(src=>src===previous));
            mode = 'success';
            await page.getByRole('button',{name:'Upload photo'}).click();
            await page.getByText('Profile image updated.',{exact:true}).waitFor();
            await page.waitForFunction(old=>[...document.querySelectorAll('[data-avatar-image]')].every(i=>i.getAttribute('src')!==old && !i.hidden && i.naturalWidth>0),previous);
            await page.reload(); await installScript();
            assert.ok((await page.locator('[data-avatar-image]').evaluateAll(images=>images.map(i=>i.getAttribute('src')))).every(src=>src===photoUrl));
            broken = true;
            await page.reload(); await installScript();
            await page.waitForFunction(()=>[...document.querySelectorAll('[data-avatar-image]')].every(i=>i.hidden));
            assert.equal(await page.locator('[data-profile-avatar] > span').first().textContent(),'S');
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: admin/officer avatars and previews update immediately; reload, versioning, failed-upload retry and missing-image fallback.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exit(1);});
