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
        page.on('pageerror', error => errors.push(error.message));
        const reports = Array.from({ length: 15 }, (_, index) => ({
            id: index + 1, agency: { type: ['FIRE', 'PNP', 'MDRRMO'][Math.floor(index / 5)], name: ['Fire Department (BFP)', 'Police Department (PNP)', 'MDRRMO'][Math.floor(index / 5)] },
            public_reference: `synthetic-${index + 1}`, received_at: '2026-10-26T00:00:00Z',
            latitude: 14.806 + index * 0.001, longitude: 121.039 + index * 0.002,
            description: '[TEST REPORT] Synthetic map fixture', workflow_status: 'submitted', is_test: true,
        }));
        reports.push({ ...reports[0], id: 16, agency: null, public_reference: 'synthetic-triage' });
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.hostname === 'maps.googleapis.com') {
                return route.fulfill({ contentType: 'application/javascript', body: `
                    window.__markers = [];
                    window.google = { maps: {
                        Map: class {}, Size: class { constructor(width,height) { Object.assign(this,{width,height}); } },
                        Point: class { constructor(x,y) { Object.assign(this,{x,y}); } },
                        Marker: class { constructor(options) { this.options=options;window.__markers.push(this); } addListener() {} setMap(map) { this.options.map=map; } }
                    }};
                    window.cdrmMapReady();
                ` });
            }
            assert.equal(url.hostname, 'cdrmapp.test');
            if (url.pathname.endsWith('/map-overlay')) return route.fulfill({ contentType: 'application/javascript', body: 'export class GoogleMapsOverlay {setMap() {} setProps() {}} export class HeatmapLayer {}' });
            if (url.pathname.endsWith('/summary')) return route.fulfill({ json: { data: { total: 16, by_status: { submitted: 16 }, validated: 0 } } });
            if (url.pathname.endsWith('/incidents')) return route.fulfill({ json: { data: reports, meta: { last_page: 1 } } });
            if (url.pathname.endsWith('/notifications')) return route.fulfill({ json: { data: [], meta: { total: 0 } } });
            if (url.pathname.endsWith('/heatmap')) return route.fulfill({ json: { data: { points: [], exploratory: false } } });
            return route.fulfill({ contentType: 'text/html', body: `<meta name="csrf-token" content="synthetic">
                <div id="dashboard" data-map-key="synthetic" data-role="admin">
                <form id="filters"></form><p id="dashboard-status"></p><section id="summary"></section><span id="unread"></span>
                <table><tbody id="incidents"></tbody></table><span id="page"></span><button id="previous"></button><button id="next"></button>
                <a id="export"></a><button id="print"></button><button id="refresh"></button>
                <input type="checkbox" id="show-pins" checked><input type="checkbox" id="exploratory">
                <p id="map-status"></p><div id="map"></div></div>` });
        });
        await page.goto('http://cdrmapp.test/dashboard');
        await page.addScriptTag({ type: 'module', content: fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8') });
        await page.waitForFunction(() => window.__markers?.length === 16);
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
        assert.equal(markers.find(marker => marker.title.endsWith('synthetic-triage')).icon, '/images/map/triage.svg');
        assert.deepEqual(errors, []);
        console.log('PASS: five flame pins, five police-badge pins, five rescue-cross pins, neutral triage fallback, test labels, exact coordinates, and pin anchors.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
