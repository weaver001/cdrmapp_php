// All requests are mocked; no real accounts or audit records are changed.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({executablePath:'/usr/bin/chromium', headless:true, args:['--no-sandbox']});
    try {
        const page = await browser.newPage();
        const errors = [], requests = [];
        page.on('pageerror', error => errors.push(error.message));
        const markup = fs.readFileSync('backend/resources/views/manage.blade.php','utf8').match(/<form id="audit-filters"[\s\S]*?<\/form>/)[0]
            .replace(/@foreach[\s\S]*?@endforeach/g, '<option value="1">Synthetic actor / agency</option>')
            .replace(/\{\{ now\('Asia\/Manila'\)->toDateString\(\) \}\}/g, '2026-11-03');
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/api/v1/management/audit') {
                requests.push(Object.fromEntries(url.searchParams));
                return route.fulfill({json:{data:url.searchParams.get('q') === 'missing' ? [] : [{id:1,action:'content.deleted',record_label:'Removed notice',actor_name:'Synthetic admin',created_at:'2026-11-03T02:00:00Z',metadata:{before:{title:'Removed notice'},after:{}}}],meta:{last_page:2}}});
            }
            return route.fulfill({contentType:'text/html',body:`<div id="management" data-module="audit"><h1>Audit History</h1><p id="form-status"></p>${markup}<div id="records"></div><button id="manage-prev">Previous</button><button id="manage-next">Next</button><span id="manage-page"></span></div>`});
        });
        await page.goto('http://cdrmapp.test/manage/audit');
        await page.addScriptTag({type:'module', content:fs.readFileSync('backend/resources/js/app.js','utf8')});
        await page.getByText('Removed notice',{exact:true}).first().waitFor();
        assert.equal(requests.at(-1).from,'2026-11-03');
        await page.locator('[name=action]').selectOption('deleted');
        await page.locator('[name=module]').selectOption('content');
        await page.locator('[name=q]').fill('Removed');
        await page.getByRole('button',{name:'Apply Filters',exact:true}).click();
        await page.waitForFunction(() => new URL(location.href).searchParams.get('action') === 'deleted');
        await page.locator('#manage-next').click();
        await page.waitForTimeout(100);
        assert.equal(requests.at(-1).page,'2');
        assert.equal(requests.at(-1).q,'Removed');
        assert.equal(requests.at(-1).module,'content');
        await page.getByRole('button',{name:'Clear Filter',exact:true}).click();
        await page.waitForTimeout(100);
        assert.equal(requests.at(-1).from,'');
        assert.equal(requests.at(-1).to,'');
        assert.equal(requests.at(-1).page,'1');
        assert.equal(requests.at(-1).action,'');
        await page.locator('[name=q]').fill('missing');
        await page.getByRole('button',{name:'Apply Filters',exact:true}).click();
        await page.getByText('No audit entries match these filters.',{exact:true}).waitFor();
        assert.deepEqual(errors,[]);
        console.log('PASS: Audit History date defaults, combined filters, pagination, clear and empty results.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exit(1);});
