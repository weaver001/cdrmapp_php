// Isolated browser checks: every HTTP request is intercepted on a synthetic origin.
const { chromium } = require('../backend/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../backend/resources/js/app.js'), 'utf8');
const manageView = fs.readFileSync(path.join(__dirname, '../backend/resources/views/manage.blade.php'), 'utf8');
const userFilters = manageView.match(/<form id="user-filters"[\s\S]*?<\/form>/)[0];
const resetDialog = manageView.match(/<dialog id="staff-reset-confirm"[\s\S]*?<\/dialog>/)[0];
const shell = (module, form = '') => `<meta name="csrf-token" content="synthetic">
<div id="management" data-module="${module}" data-current-user-id="1"><p id="form-status" role="status"></p>${module==='users'?userFilters:''}${form}${module==='users'?resetDialog:''}
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

        let account = null, invalid = false, resetFailure = false, resetRequests = 0, paginationFixture = false;
        const userQueries = [];
        const userWrites = [];
        const users = await open('users', shell('users', form('users', `<input name="name" required><input name="email" type="email" required><select name="role" required><option value="">Select role</option><option value="citizen">Citizen</option><option value="admin">Administrator</option><option value="officer">Officer</option></select><select name="agency_id"><option value="">None</option><option value="1">Fire Department</option></select><input type="checkbox" name="active" checked><div data-staff-passwords hidden><label>Temporary password<input type="password" name="initial_password" minlength="12" disabled></label><label>Confirm temporary password<input type="password" name="initial_password_confirmation" minlength="12" disabled></label></div><p data-account-delivery></p>`, 'Save account')), async route => {
            const request = route.request();
            if (request.method() === 'GET') {
                const query = new URL(request.url()).searchParams;
                userQueries.push(Object.fromEntries(query));
                let records = paginationFixture ? [
                    {id:21,name:'Synthetic fire officer',email:'fire@example.test',role:'officer',active:false,agency:{type:'FIRE',name:'Fire Department'}},
                    {id:22,name:'Synthetic crime officer',email:'crime@example.test',role:'officer',active:false,agency:{type:'PNP',name:'Police Department'}},
                ] : account ? [account] : [];
                const type = query.get('account_type') || 'all', activity = query.get('activity') || 'all';
                records = records.filter(item => (type==='all' || item.role===type || (item.role==='officer' && item.agency?.type===({fire:'FIRE',crime:'PNP',mdrrmo:'MDRRMO'}[type]))) && (activity==='all' || item.active===(activity==='active')));
                const last = paginationFixture ? Math.max(1,records.length) : 1;
                const data = paginationFixture ? records.slice(Number(query.get('page') || 1)-1,Number(query.get('page') || 1)) : records;
                return route.fulfill({ json: { data, meta:{last_page:last,total:records.length} } });
            }
            if (new URL(request.url()).pathname.endsWith('/reset-password')) {
                assert.deepEqual(request.postDataJSON(),{confirm:true});
                resetRequests++;
                if (!resetFailure) account.must_change_password = true;
                return resetFailure
                    ? route.fulfill({ status: 422, json: { error: { message: 'Please wait before retrying.' } } })
                    : route.fulfill({ json: { data: { message: 'Password reset requested.' } } });
            }
            userWrites.push(request.method());
            if (invalid) return route.fulfill({ status: 422, json: { error: { message: 'Email already exists.' } } });
            account = { ...request.postDataJSON(), id: 12 };
            delete account.initial_password; delete account.initial_password_confirmation;
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
        assert.equal(await users.getByRole('button',{name:'Send password reset',exact:true}).count(),0,'Citizen accounts must not show an admin reset action.');
        account.role = 'officer'; account.agency = {type:'FIRE',name:'Fire Department'};
        await users.reload(); await users.addScriptTag({type:'module',content:source});
        const popups = [];
        users.on('dialog', async dialog => { popups.push(dialog.message()); await dialog.dismiss(); });
        await users.getByRole('button', { name: 'Send password reset', exact: true }).click();
        await users.getByRole('button',{name:'Exit',exact:true}).click();
        assert.equal(resetRequests,0,'Exit must not send a reset request.');
        await users.getByRole('button', { name: 'Send password reset', exact: true }).click();
        await users.getByRole('button',{name:'Confirm',exact:true}).click();
        await users.getByText('Password reset requested.', { exact: true }).waitFor();
        assert.deepEqual(popups, ['Success']);
        await users.getByText('Password update required',{exact:true}).waitFor();
        resetFailure = true;
        await users.getByRole('button', { name: 'Send password reset', exact: true }).click();
        await users.getByRole('button',{name:'Confirm',exact:true}).click();
        await users.getByText('Please wait before retrying.', { exact: true }).waitFor();
        assert.deepEqual(popups, ['Success'], 'Failed reset requests must not show a success popup.');
        await users.getByRole('button',{name:'Exit',exact:true}).click();

        invalid = false;
        await users.getByRole('button', {name:'Clear Field',exact:true}).click();
        await users.locator('[name=name]').fill('Synthetic staff');
        await users.locator('[name=email]').fill('staff@cdrmapp.com');
        await users.locator('[name=role]').selectOption('admin');
        const writesBeforePassword = userWrites.length;
        await users.getByRole('button',{name:'Save account',exact:true}).click();
        assert.equal(userWrites.length, writesBeforePassword, 'New staff must supply a temporary password.');
        assert.equal(await users.locator('[name=initial_password]').getAttribute('type'),'password');
        await users.getByLabel('Temporary password',{exact:true}).fill('SyntheticTemporary123');
        await users.getByLabel('Confirm temporary password',{exact:true}).fill('SyntheticTemporary123');
        await users.getByRole('button',{name:'Save account',exact:true}).click();
        await users.getByText('Account saved successfully.',{exact:true}).waitFor();
        assert.equal(await users.locator('[name=initial_password]').inputValue(),'');
        assert.equal(await users.locator('[name=initial_password]').isDisabled(),true);
        await users.getByRole('button',{name:'Edit',exact:true}).click();
        assert.equal(await users.locator('[name=initial_password]').getAttribute('required'),null);
        assert.equal(await users.locator('[name=initial_password]').inputValue(),'');
        invalid = true;
        await users.getByLabel('Temporary password',{exact:true}).fill('SyntheticReplacement456');
        await users.getByLabel('Confirm temporary password',{exact:true}).fill('SyntheticReplacement456');
        await users.getByRole('button',{name:'Save account',exact:true}).click();
        await users.getByText('Email already exists.',{exact:true}).waitFor();
        assert.equal(await users.locator('[name=initial_password]').inputValue(),'SyntheticReplacement456');
        await users.locator('[name=role]').selectOption('citizen');
        assert.equal(await users.locator('[name=initial_password]').inputValue(),'');
        assert.equal(await users.locator('[name=initial_password]').isDisabled(),true);
        assert.ok(!(await users.locator('body').textContent()).includes('SyntheticTemporary123'));
        account.id = 1;
        await users.reload();
        await users.addScriptTag({type:'module',content:source});
        await users.getByRole('button',{name:'Edit',exact:true}).click();
        assert.equal(await users.locator('[name=initial_password]').isDisabled(),true,'Self password changes belong in Profile.');

        paginationFixture = true;
        await users.locator('[name=account_type]').selectOption('officer');
        await users.locator('[name=activity]').selectOption('inactive');
        await users.getByRole('button',{name:'Apply filters',exact:true}).click();
        await users.getByRole('heading',{name:'Synthetic fire officer',exact:true}).waitFor();
        await users.locator('#manage-next').click();
        await users.getByRole('heading',{name:'Synthetic crime officer',exact:true}).waitFor();
        assert.equal(userQueries.at(-1).page,'2');
        assert.equal(userQueries.at(-1).account_type,'officer');
        assert.equal(userQueries.at(-1).activity,'inactive');
        await users.locator('[name=account_type]').selectOption('fire');
        await users.getByRole('button',{name:'Apply filters',exact:true}).click();
        await users.getByRole('heading',{name:'Synthetic fire officer',exact:true}).waitFor();
        assert.equal(userQueries.at(-1).page,'1','Changing filters must return to page one.');
        assert.equal(userQueries.at(-1).account_type,'fire');
        assert.equal(userQueries.at(-1).activity,'inactive');
        await users.locator('[name=activity]').selectOption('active');
        await users.getByRole('button',{name:'Apply filters',exact:true}).click();
        await users.getByText('No accounts match these filters.',{exact:true}).waitFor();

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
