// Google Maps and all API calls are intercepted; no real maps quota or alerts used.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CDRMAPP_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        const errors = [];
        let mapsRequests = 0;
        let paginated = false;
        const requestedPages = [];
        const statusWrites = [];
        let failNextCompletion = false;
        const completionMarkup = fs.readFileSync(path.join(__dirname, '../backend/resources/views/dashboard.blade.php'), 'utf8').match(/<dialog id="complete-report"[\s\S]*?<\/dialog>/)[0];
        const detailsMarkup = fs.readFileSync(path.join(__dirname, '../backend/resources/views/report-map.blade.php'), 'utf8').match(/<aside id="report-details"[\s\S]*?<\/aside>/)[0];
        const allStyles = fs.readFileSync(path.join(__dirname, '../backend/resources/css/app.css'), 'utf8');
        const mapStyles = allStyles.slice(allStyles.indexOf('#report-map-page'));
        page.on('pageerror', error => errors.push(error.message));
        const reports = Array.from({ length: 15 }, (_, index) => ({
            id: index + 1, agency: { type: ['FIRE', 'PNP', 'MDRRMO'][Math.floor(index / 5)], name: ['Fire Department (BFP)', 'Police Department (PNP)', 'MDRRMO'][Math.floor(index / 5)] },
            agency_id: Math.floor(index / 5) + 1, assigned_officer_id: 501 + Math.floor(index / 5),
            public_reference: `synthetic-${index + 1}`, received_at: '2026-10-26T00:00:00Z',
            latitude: 14.806 + index * 0.001, longitude: 121.039 + index * 0.002,
            description: '[TEST REPORT] Synthetic map fixture', workflow_status: 'need_action', is_test: true, responses: [],
        }));
        reports.push({ ...reports[0], id: 16, agency: null, agency_id: null, assigned_officer_id: null, public_reference: 'synthetic-triage', responses: [] });
        reports[0].responses.push({ note: 'Legacy response note', officer_id: 501, officer: {name: 'Legacy responder'}, occurred_at: '2026-10-26T00:00:00Z' });
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.hostname === 'maps.googleapis.com') {
                mapsRequests++;
                return route.fulfill({ contentType: 'application/javascript', body: `
                    window.__markers = [];
                    window.google = { maps: {
                        Map: class { constructor(element, options) { window.__map=this; Object.assign(this, options); } panTo(position) { this.center=position; } setZoom(zoom) { this.zoom=zoom; } }, Size: class { constructor(width,height) { Object.assign(this,{width,height}); } },
                        Point: class { constructor(x,y) { Object.assign(this,{x,y}); } },
                        Marker: class { constructor(options) { this.options=options;this.listeners={};window.__markers.push(this); } addListener(name,callback) {this.listeners[name]=callback;} setMap(map) { this.options.map=map; } }
                    }};
                    (window.cdrmReportMapReady || window.cdrmMapReady)();
                ` });
            }
            assert.equal(url.hostname, 'cdrmapp.test');
            if (url.pathname.endsWith('/map-overlay')) return route.fulfill({ contentType: 'application/javascript', body: 'export class GoogleMapsOverlay {setMap() {} setProps() {}} export class HeatmapLayer {}' });
            if (/\/incidents\/\d+$/.test(url.pathname)) return route.fulfill({ json: { data: { ...reports.find(report => report.id === Number(url.pathname.split('/').at(-1))), histories: [], attachments: [] } } });
            if (url.pathname.endsWith('/officers')) {
                const agency = Number(url.searchParams.get('agency_id'));
                return route.fulfill({ json: { data: [{ id: 500 + agency, name: `Synthetic officer ${agency}` }] } });
            }
            if (url.pathname.endsWith('/status')) {
                const body = route.request().postDataJSON();
                assert.deepEqual(Object.keys(body), body.status === 'done' ? ['status', 'note'] : ['status']);
                assert.ok(['done', 'need_action'].includes(body.status));
                if (body.status === 'done') {
                    assert.ok(body.note.trim());
                    if (failNextCompletion) { failNextCompletion = false; return route.fulfill({status:500,json:{error:{message:'Synthetic save failure'}}}); }
                }
                statusWrites.push(body.status);
                const report = reports.find(item => item.id === Number(url.pathname.split('/').at(-2)));
                report.workflow_status = body.status;
                if (body.status === 'done') report.responses.push({note:body.note, officer_id:501, officer:{name:'Synthetic responder'}, occurred_at:'2026-10-27T00:00:00Z', outcome:'Done'});
                return route.fulfill({ json: { data: report } });
            }
            if (url.pathname.endsWith('/summary')) return route.fulfill({ json: { data: { total: 16, by_status: { need_action: reports.filter(report => report.workflow_status === 'need_action').length, done: reports.filter(report => report.workflow_status === 'done').length }, validated: 0 } } });
            if (url.pathname.endsWith('/incidents')) { requestedPages.push(url.searchParams.get('page')); return route.fulfill({ json: { data: reports.filter(report => (!url.searchParams.get('status') || report.workflow_status === url.searchParams.get('status')) && (!url.searchParams.get('q') || report.public_reference === url.searchParams.get('q'))), meta: { last_page: paginated ? 2 : 1 } } }); }
            if (url.pathname.endsWith('/notifications')) return route.fulfill({ json: { data: [], meta: { total: 0 } } });
            if (url.pathname.endsWith('/heatmap')) return route.fulfill({ json: { data: { points: [], exploratory: false } } });
            if (/^\/incidents\/\d+\/map$/.test(url.pathname)) return route.fulfill({ contentType: 'text/html', body: `<div id="report-map-page" data-incident-id="${url.pathname.split('/')[2]}" data-map-key="synthetic"><a class="report-map-back" href="/dashboard" aria-label="Back to incident dashboard">Back</a><div id="report-map"></div>${detailsMarkup}<p id="report-map-status"></p></div><style>${mapStyles}</style>` });
            const mapView = url.pathname === '/incident-map';
            const role = url.searchParams.get('role') === 'officer' ? 'officer' : 'admin';
            return route.fulfill({ contentType: 'text/html', body: `<meta name="csrf-token" content="synthetic"><style>#incidents tr {height:120px}</style>
                <div id="dashboard" ${mapView ? 'data-map-key="synthetic"' : ''} data-role="${role}">
                <form id="filters"><input name="q"><select name="status"><option value="">All statuses</option><option value="need_action">Need action</option><option value="done">Done</option></select><select name="agency_id"><option value="">All authorized</option><option value="1">Fire Department (BFP)</option><option value="2">Police Department (PNP)</option><option value="3">MDRRMO</option></select><button>Apply filters</button></form><p id="dashboard-status"></p><section id="summary"></section><span id="unread"></span>
                <span id="page"></span><button id="previous"></button><button id="next"></button>
                <a id="switch-view" href="${mapView ? '/dashboard' : '/incident-map'}"></a>${role === 'admin' ? '<a id="export"></a><button id="print"></button>' : ''}<button id="refresh"></button>
                ${mapView ? `
                <input type="checkbox" id="show-pins" checked><input type="checkbox" id="exploratory">
                <p id="map-status"></p><div id="map"></div>` : '<table><tbody id="incidents"></tbody></table>'}
                <dialog id="incident-detail"><form method="dialog"><button>Close</button></form><div id="detail-body"></div></dialog>${completionMarkup}</div>` });
        });
        await page.goto('http://cdrmapp.test/incident-map');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.waitForFunction(() => window.__markers?.length === 15);
        const markers = await page.evaluate(() => window.__markers.map(marker => ({
            title: marker.options.title, icon: marker.options.icon.url,
            size: marker.options.icon.scaledSize, anchor: marker.options.icon.anchor, position: marker.options.position,
        })));
        for (const [type, file] of [['FIRE', 'fire'], ['PNP', 'police'], ['MDRRMO', 'mdrrmo']]) {
            const expected = reports.filter(report => report.agency?.type === type);
            assert.equal(markers.filter(marker => marker.icon === `/images/map/${file}.svg`).length, 5);
            for (const report of expected) {
                const marker = markers.find(marker => marker.title.endsWith(report.public_reference));
                assert.equal(marker.icon, `/images/map/${file}.svg`);
                assert.ok(marker.title.startsWith('[TEST] '));
                assert.deepEqual(marker.position, { lat: report.latitude, lng: report.longitude });
                assert.deepEqual(marker.size, { width: 40, height: 48 });
                assert.deepEqual(marker.anchor, { x: 20, y: 47 });
            }
            assert.ok(fs.existsSync(path.join(__dirname, `../backend/public/images/map/${file}.svg`)));
        }
        assert.equal(markers.find(marker => marker.title.endsWith('synthetic-triage')), undefined, 'Reports awaiting triage must not create map pins.');
        await page.evaluate(() => window.__markers.find(marker => marker.options.map && marker.options.title.endsWith('synthetic-1')).listeners.click());
        await page.locator('#incident-detail[open]').waitFor();
        assert.equal(await page.getByLabel('Routed agency', { exact: true }).count(), 0);
        assert.equal(await page.getByLabel('Assigned officer', { exact: true }).count(), 0);
        assert.equal(await page.getByRole('button', {name:'Log response',exact:true}).count(), 0);
        assert.ok((await page.locator('#detail-body').textContent()).includes('Legacy response note'));
        await page.getByRole('button', {name:'Mark as done',exact:true}).click();
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        assert.equal(statusWrites.length, 0, 'An empty response must not send a completion request.');
        await page.getByLabel('Response notes', {exact:true}).fill('   ');
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        assert.equal(statusWrites.length, 0, 'Whitespace-only response notes must be rejected.');
        await page.getByRole('button', {name:'Cancel',exact:true}).click();
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        for (const index of [0, 5, 10]) reports[index].workflow_status = 'done';
        await page.locator('#refresh').click();
        await page.waitForFunction(() => window.__markers.filter(marker => marker.options.map && marker.options.icon.url.endsWith('-done.svg')).length === 3);
        const completed = await page.evaluate(() => window.__markers.filter(marker => marker.options.map).map(marker => ({ title: marker.options.title, icon: marker.options.icon.url })));
        for (const [index, file] of [[0, 'fire'], [5, 'police'], [10, 'mdrrmo']]) {
            const marker = completed.find(marker => marker.title.endsWith(reports[index].public_reference));
            assert.equal(marker.icon, `/images/map/${file}-done.svg`);
            assert.ok(marker.title.includes(' · Done · '));
            assert.ok(fs.existsSync(path.join(__dirname, `../backend/public/images/map/${file}-done.svg`)));
        }
        assert.equal(completed.filter(marker => !marker.icon.endsWith('-done.svg')).length, 12);
        for (const index of [0, 5, 10]) reports[index].workflow_status = 'need_action';
        await page.locator('#refresh').click();
        await page.waitForFunction(() => {
            const current = window.__markers.filter(marker => marker.options.map);
            return current.length === 15 && current.every(marker => !marker.options.icon.url.endsWith('-done.svg'));
        });
        const mapLoads = mapsRequests;
        await page.goto('http://cdrmapp.test/dashboard');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.locator('#incidents tr').first().waitFor();
        assert.equal(await page.locator('#incidents tr').count(), 16);
        const eyeLink = page.getByRole('link', { name: 'View report synthetic-6 on map', exact: true });
        const destination = new URL(await eyeLink.getAttribute('href'), 'http://cdrmapp.test');
        assert.equal(destination.pathname, '/incidents/6/map');
        assert.equal(new URL(destination.searchParams.get('return_to'), 'http://cdrmapp.test').pathname, '/dashboard');
        assert.equal(await eyeLink.locator('svg[aria-hidden="true"]').count(), 1);
        assert.ok((await page.locator('#incidents tr').first().textContent()).includes('Latitude: 14.806000 · Longitude: 121.039000'));
        await page.getByRole('button', { name: 'Mark as done', exact: true }).first().click();
        const responseNote = 'SYNTHETIC assistance completed.\n<script>window.__noteExecuted=true</script>';
        await page.getByLabel('Response notes', {exact:true}).fill(responseNote);
        failNextCompletion = true;
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        await page.getByText('Synthetic save failure', {exact:true}).waitFor();
        assert.equal(await page.getByLabel('Response notes', {exact:true}).inputValue(), responseNote);
        assert.equal(reports[0].workflow_status, 'need_action');
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        await page.locator('#complete-report').waitFor({state:'hidden'});
        await page.getByRole('button', { name: 'Mark as undone', exact: true }).waitFor();
        assert.equal(await page.locator('#incidents tr').first().locator('td').nth(4).textContent(), 'Done');
        await page.getByRole('button', {name:'syntheti',exact:true}).first().click();
        await page.locator('#incident-detail[open]').waitFor();
        assert.ok((await page.locator('#detail-body').textContent()).includes(responseNote));
        assert.ok((await page.locator('#detail-body').textContent()).includes('Legacy response note'));
        assert.equal(await page.locator('#detail-body script').count(), 0);
        assert.equal(await page.evaluate(() => window.__noteExecuted), undefined);
        await page.getByRole('button', {name:'Close',exact:true}).click();
        await page.getByRole('button', { name: 'Mark as undone', exact: true }).click();
        await page.waitForFunction(() => document.querySelectorAll('#incidents button').length === 32 && !document.querySelector('#incidents').textContent.includes('Mark as undone'));
        assert.deepEqual(statusWrites, ['done', 'need_action']);
        assert.equal(mapsRequests, mapLoads, 'The report dashboard must not load Google Maps.');
        assert.equal(await page.evaluate(() => typeof window.google), 'undefined');
        await page.locator('[name=status]').selectOption('done');
        await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
        await page.getByText('No reports match these filters.', { exact: true }).waitFor();
        assert.equal(await page.locator('#incidents td').getAttribute('colspan'), '7');
        assert.equal(new URL(await page.locator('#switch-view').getAttribute('href'), 'http://cdrmapp.test').searchParams.get('status'), 'done');
        reports[5].workflow_status = 'done';
        const mapNote = 'Response completed.\n<script>window.__mapNoteExecuted=true</script>';
        reports[5].responses.push({note:mapNote, officer:{name:'Map responder'}, occurred_at:'2026-10-27T00:00:00Z', outcome:'Done'});
        await page.locator('#refresh').click();
        await page.getByRole('link', { name: 'View report synthetic-6 on map', exact: true }).click();
        await page.waitForURL('**/incidents/6/map?**');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/report-map.js'), 'utf8') });
        await page.waitForFunction(() => window.__markers?.length === 1 && window.__map?.zoom === 16);
        assert.deepEqual(await page.evaluate(() => window.__map.center), { lat: reports[5].latitude, lng: reports[5].longitude });
        assert.equal(await page.evaluate(() => window.__map.disableDefaultUI), true);
        assert.equal(await page.evaluate(() => window.__markers[0].options.icon.url), '/images/map/police-done.svg');
        assert.equal(await page.locator('#dashboard, #summary, #filters, #incidents').count(), 0);
        assert.equal(await page.locator('#report-map-status').isHidden(), true);
        const reportDetails = page.locator('#report-details');
        assert.ok((await reportDetails.textContent()).includes('synthetic-6'));
        assert.ok((await reportDetails.textContent()).includes('Police Department (PNP)'));
        assert.ok((await reportDetails.textContent()).includes(mapNote));
        assert.ok((await reportDetails.textContent()).includes('Map responder'));
        assert.ok((await reportDetails.textContent()).includes('Done'));
        assert.equal(await reportDetails.getByRole('heading', {name:'Category', exact:true}).count(), 0);
        assert.equal(await reportDetails.getByRole('heading', {name:'Location', exact:true}).count(), 0);
        assert.equal(await reportDetails.getByRole('heading', {name:'Coordinates', exact:true}).count(), 1);
        assert.equal(await reportDetails.getByRole('heading', {name:'Location source', exact:true}).count(), 1);
        assert.equal(await reportDetails.locator('script').count(), 0);
        assert.equal(await page.evaluate(() => window.__mapNoteExecuted), undefined);
        const desktopMap = await page.locator('#report-map').boundingBox();
        const desktopDetails = await reportDetails.boundingBox();
        assert.ok(desktopMap.x + desktopMap.width <= desktopDetails.x + 1, 'Desktop details must not cover the incident pin.');
        await page.setViewportSize({width:390,height:844});
        const mobileMap = await page.locator('#report-map').boundingBox();
        const mobileDetails = await reportDetails.boundingBox();
        assert.ok(mobileMap.y + mobileMap.height <= mobileDetails.y + 1, 'Mobile details must sit below the map.');
        assert.equal(await reportDetails.evaluate(element => getComputedStyle(element).overflowY), 'auto');
        await page.setViewportSize({width:1280,height:720});
        await page.getByRole('link', {name:'Back to incident dashboard'}).click();
        await page.waitForURL(url => url.pathname === '/dashboard');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.locator('#incidents tr').first().waitFor();
        assert.equal(await page.locator('[name=status]').inputValue(), 'done');
        paginated = true;
        await page.goto('http://cdrmapp.test/dashboard?status=need_action&page=2');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.getByText('Page 2 of 2', {exact:true}).waitFor();
        const returnEye = page.getByRole('link', {name:'View report synthetic-12 on map',exact:true});
        await returnEye.scrollIntoViewIfNeeded();
        const originalScroll = await page.evaluate(() => Math.round(window.scrollY));
        assert.ok(originalScroll > 0);
        await returnEye.click();
        await page.waitForURL('**/incidents/12/map?**');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/report-map.js'), 'utf8') });
        await page.getByRole('link', {name:'Back to incident dashboard'}).click();
        await page.waitForURL(url => url.pathname === '/dashboard');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.getByText('Page 2 of 2', {exact:true}).waitFor();
        await page.waitForFunction(scroll => Math.abs(window.scrollY - scroll) <= 1, originalScroll);
        assert.equal(await page.locator('[name=status]').inputValue(), 'need_action');
        assert.equal(requestedPages.at(-1), '2');
        assert.equal(new URL(page.url()).hash, '#report-12');
        paginated = false;
        await page.goto('http://cdrmapp.test/dashboard?role=officer');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.locator('#incidents tr').first().waitFor();
        assert.equal(await page.locator('#export, #print').count(), 0);
        assert.equal(await page.getByRole('link', { name: 'View report synthetic-6 on map', exact: true }).count(), 1);
        await page.getByRole('button', { name: 'Mark as done', exact: true }).first().click();
        await page.getByLabel('Response notes', {exact:true}).fill('SYNTHETIC officer completed response.');
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        await page.locator('#complete-report').waitFor({state:'hidden'});
        await page.waitForFunction(() => document.querySelector('#incidents tr td:nth-child(5)').textContent === 'Done');
        await page.goto('http://cdrmapp.test/incident-map?q=synthetic-1&focus=1&role=officer');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.waitForFunction(() => window.__markers?.length === 1);
        await page.evaluate(() => window.__markers[0].listeners.click());
        await page.locator('#incident-detail[open]').waitFor();
        assert.ok((await page.locator('#detail-body').textContent()).includes('SYNTHETIC officer completed response.'));
        await page.getByRole('button', {name:'Mark as undone',exact:true}).click();
        await page.getByRole('button', {name:'Mark as done',exact:true}).waitFor();
        await page.getByRole('button', {name:'Mark as done',exact:true}).click();
        await page.getByLabel('Response notes', {exact:true}).fill('SYNTHETIC map completion notes.');
        await page.getByRole('button', {name:'Save notes and mark as done',exact:true}).click();
        await page.locator('#complete-report').waitFor({state:'hidden'});
        assert.ok((await page.locator('#detail-body').textContent()).includes('SYNTHETIC map completion notes.'));
        assert.ok((await page.locator('#detail-body').textContent()).includes('Legacy response note'));
        assert.deepEqual(errors, []);
        console.log('PASS: required completion notes, cancel/failed-save retry, preserved response logs via reference and map pin, no rerouting/log-response forms, agency pins and admin-only exports.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
