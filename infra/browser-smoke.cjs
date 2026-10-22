const {chromium,request}=require('../backend/node_modules/playwright');const fs=require('fs');const assert=require('assert');
(async()=>{
 const path=require('path');const root=path.resolve(__dirname,'..');const base=process.env.CDRMAPP_BASE_URL||'http://127.0.0.1:8000';assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname),'Synthetic browser tests must target a local server.');const credentials=JSON.parse(fs.readFileSync(require('path').join(root,'backend/storage/app/private/browser-fixture.json'),'utf8'));
 const client=await request.newContext({baseURL:base});
 async function token(role){const r=await client.post('/api/v1/auth/login',{data:credentials[role]});assert.equal(r.status(),200);return (await r.json()).data.token;}
 const citizen=await token('citizen');const fire=await token('FIRE');const pnp=await token('PNP');const auth=t=>({Authorization:`Bearer ${t}`,Accept:'application/json'});
 const cats=await (await client.get('/api/v1/incident-categories',{headers:auth(citizen)})).json();const category=cats.data.find(x=>x.name==='Fire Department');
 const payload={category_id:category.id,description:'SYNTHETIC BROWSER TEST: no real emergency. Smoke report for local verification only.',latitude:14.5995,longitude:120.9842,location_source:'manual',idempotency_key:require('crypto').randomUUID()};
 const sent=await client.post('/api/v1/incidents',{headers:auth(citizen),data:payload});assert.equal(sent.status(),201);const incident=(await sent.json()).data;require('child_process').execFileSync('docker',['compose','-f',path.join(root,'infra/compose.yaml'),'run','--rm','web','php','tests/Browser/mark-synthetic.php'],{stdio:'pipe'});
 const retry=await client.post('/api/v1/incidents',{headers:auth(citizen),data:payload});assert.equal(retry.status(),200);assert.equal((await retry.json()).data.id,incident.id);
 assert.equal((await client.get(`/api/v1/incidents/${incident.id}`,{headers:auth(fire)})).status(),200);
 assert.equal((await client.get(`/api/v1/incidents/${incident.id}`,{headers:auth(pnp)})).status(),403);
 const browser=await chromium.launch({headless:true,executablePath:process.env.CDRMAPP_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:1440,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/login');await page.getByLabel('Email',{exact:true}).fill(credentials.admin.email);await page.getByLabel('Password',{exact:true}).fill(credentials.admin.password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');
 await page.getByText('Updated ',{exact:false}).waitFor();assert.ok(await page.locator('#incidents tr').count()>=1);await page.getByRole('button',{name:incident.public_reference.slice(0,8)}).click();await page.locator('#incident-detail[open]').waitFor();await page.getByRole('button',{name:'Close',exact:true}).click();
 fs.mkdirSync(path.join(root,'docs/screenshots'),{recursive:true});await page.screenshot({path:path.join(root,'docs/screenshots/staff-dashboard.png'),fullPage:true});
 await page.goto(base+'/manage/announcements');await page.getByLabel('Title',{exact:true}).fill('SYNTHETIC TEST: community readiness');await page.getByLabel('Description / answer').fill('Synthetic public content for local application validation.');await page.getByLabel('Publish publicly').check();await page.getByRole('button',{name:'Save content',exact:true}).click();await page.getByText('Saved.',{exact:true}).waitFor();
 await page.goto(base+'/pages/announcements');await page.getByRole('heading',{name:'SYNTHETIC TEST: community readiness'}).first().waitFor();assert.deepEqual(errors,[]);
 console.log('PASS: real HTTP submission/retry, FIRE access, PNP denial, staff session/API dashboard, report details, CMS publication, and browser JavaScript without uncaught errors.');
 await browser.close();await client.dispose();
})().catch(e=>{console.error(e.message);process.exit(1)});
