'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const http=require('node:http'),https=require('node:https'),net=require('node:net');
const {spawn,execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const repo=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-staff-http-'));
const current=path.join(temp,'private'),before=path.join(temp,'before');
const manifest=JSON.parse(fs.readFileSync(path.join(repo,'documents/portal/staff-review-source.json'),'utf8'));
fs.cpSync(path.join(repo,'_private'),current,{recursive:true});fs.rmSync(path.join(current,'data'),{recursive:true,force:true});
const reserve=()=>new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
(async()=>{
 let php,browser,proxy,logs='';
 try{
  const port=await reserve(),tls=await reserve(),origin='https://127.0.0.1:'+tls;
  const env={...process.env,STAFF_TEST_PRIVATE:current,STAFF_TEST_BEFORE:before,SITESEE_REAL_ESTATE_SITE_URL:origin,SITESEE_REAL_ESTATE_BOOKING_DB:path.join(current,'data/bookings.sqlite'),SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:'synthetic-staff-fixture-secret-1234567890'};
  const setup=action=>execFileSync('php',[path.join(__dirname,'fixtures/staff-http/setup.php'),action],{env,encoding:'utf8'});
  const seeded=JSON.parse(setup('seed')),refs=seeded.refs;env.SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=seeded.hash;
  fs.cpSync(current,before,{recursive:true});
  for(const [n,item]of Object.entries(manifest.files))fs.writeFileSync(path.join(before,n.replace(/^private\//,'')),Buffer.from(item.before,'base64'));
  for(const root of [current,before]){const p=path.join(root,'server/booking-mail-client.php');fs.writeFileSync(p,fs.readFileSync(p,'utf8').replace('function booking_provider_http(', 'function booking_provider_http_fixture_unused('));}
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem'),'-days','1','-subj','/CN=localhost'],{stdio:'ignore'});
  fs.mkdirSync(path.join(temp,'sessions'),{mode:0o700});
  php=spawn('php',['-d','opcache.enable_cli=0','-d','session.save_path='+path.join(temp,'sessions'),'-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail','-S','127.0.0.1:'+port,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/staff-http/router.php')],{env,stdio:['ignore','pipe','pipe']});php.stderr.on('data',b=>logs+=b.toString());
  proxy=https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))},(req,res)=>{const f=http.request({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});f.on('error',()=>{res.writeHead(503);res.end();});req.pipe(f);});
  await new Promise(resolve=>proxy.listen(tls,'127.0.0.1',resolve));
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  // A Pacific device still sees Central appointment dates and request times.
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1200,height:1000},timezoneId:'Pacific/Honolulu'});
  await context.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const goto=async(url='/staff-bookings.php')=>{for(let i=0;i<25;i++){const r=await page.goto(origin+url);if(r.status()!==503)return r;await page.waitForTimeout(100);}throw Error('Staff server unavailable '+logs);};
  const csrf=()=>page.locator('input[name=csrf]').first().inputValue();
  const post=async(data,extra={})=>page.request.post(origin+'/staff-bookings.php',{form:{csrf:await csrf(),...data},headers:{Origin:origin},maxRedirects:0,...extra});
  const first=await goto();assert.match(first.headers()['cache-control'],/no-store/);assert.match(first.headers()['content-security-policy'],/default-src 'none'/);
  assert.match(first.headers()['content-security-policy'],/font-src 'self'/);assert.equal(await page.getByRole('button',{name:'Sign In',exact:true}).count(),1);
  assert.equal((await page.request.post(origin+'/staff-bookings.php',{form:{action:'review_paid',reference:refs.paid}})).status(),403);
  await page.getByLabel('Password',{exact:true}).fill('isolated-staff-password');await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('heading',{name:'Recent Requests',exact:true}).waitFor();
  const cookies=await context.cookies();assert(cookies[0].secure&&cookies[0].httpOnly&&cookies[0].sameSite==='Strict');
  assert.match(await page.locator('.request-list').innerText(),/2026-10-02 9:00 AM Central/);
  const shots=process.env.PORTAL_SCREENSHOTS;
  const screenshot=async name=>{if(shots){fs.mkdirSync(shots,{recursive:true});await page.screenshot({path:path.join(shots,'staff-'+name+'.png'),fullPage:true});console.log('STAFF_VISUAL_'+name+':'+(await page.screenshot({type:'jpeg',quality:65,fullPage:true})).toString('base64'));}};
  await screenshot('list');
  const contracts=()=>page.locator('form').evaluateAll(forms=>forms.map(f=>({method:f.method,fields:[...f.querySelectorAll('input,select,textarea')].map(e=>({tag:e.tagName,type:e.type,name:e.name,value:e.value,required:e.required,min:e.getAttribute('min'),max:e.getAttribute('max'),maxLength:e.getAttribute('maxlength'),step:e.getAttribute('step'),checked:e.checked,options:e.tagName==='SELECT'?[...e.options].map(o=>o.value):null})),buttons:[...f.querySelectorAll('button')].map(e=>e.textContent.trim())})).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b))));
  const snapshot=setup('snapshot');
  for(const [name,ref]of Object.entries(refs)){
    await goto('/staff-before.php?reference='+ref);const original=await contracts();
    const response=await goto('/staff-bookings.php?reference='+ref);assert.equal(response.status(),200,name);assert.deepEqual(await contracts(),original,'All original forms, fields, amounts, fingerprints, required consent and button labels: '+name);
    assert.match(await page.locator('.facts').innerText(),/Central Time/);
    assert.equal(await page.locator('#request-details').getAttribute('open'),null,'Private access stays folded: '+name);
    for(const width of [320,390,736,1200]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow '+name+' '+width);}
    const open=await page.locator('details.staff-fold[open]').evaluateAll(ds=>ds.map(d=>d.id));
    if(['paid','rush'].includes(name)){assert(!open.includes('readiness'));assert(await page.locator('.next-step form').first().isVisible());}
    if(name==='reviewed-unlinked')assert(open.includes('readiness'));
    if(['reviewed','confirmed'].includes(name))assert(open.includes('calendar-confirmation'));
    if(['uncertain','submitted','draft','missing-evidence'].includes(name))assert(open.includes('readiness'));
    if(name==='legacy-uncertain'){assert(open.includes('calendar-confirmation'));assert(await page.getByRole('button',{name:'Recheck Calendar Result',exact:true}).isVisible());}
    if(name==='draft')assert(await page.getByRole('button',{name:'Repair & Send Saved Invitation',exact:true}).isVisible());
    if(['pending-change','moved','cancelled-notice'].includes(name))assert(open.includes('manage-appointment'));
    if(['complete','cancelled'].includes(name))assert.equal(open.length,0,'Completed records start compact');
    if(['pending-change','moved','cancelled-notice','cancelled'].includes(name))assert.equal(await page.locator('input[name=action][value=confirm_calendar],input[name=action][value=send_invitation],input[name=action][value=resume_invitation]').count(),0,'No new original actions after lifecycle work');
    if(['paid','complete','draft'].includes(name))await screenshot(name);
    if(name==='rush'){await page.setViewportSize({width:390,height:1000});await screenshot('rush-mobile');}
  }
  assert.equal(setup('snapshot'),snapshot,'Opening every old/new page and status leaves recorded data unchanged');
  assert(!fs.existsSync(path.join(current,'provider-blocked.txt')),'No provider calls on any GET');
  // Keyboard access to folded controls and no action until explicit submission.
  await goto('/staff-bookings.php?reference='+refs.complete);const management=page.locator('#manage-appointment>summary');await management.focus();await page.keyboard.press('Enter');assert(await page.getByRole('button',{name:'Reconcile Calendar',exact:true}).isVisible());
  await page.getByText('Cancel Appointment',{exact:true}).click();assert.equal(await page.locator('form').filter({has:page.locator('input[name=action][value=lifecycle_cancel]')}).locator('input[name=agreed]').isChecked(),false);
  assert.equal(setup('snapshot'),snapshot,'Expanding management changes no records');
  // Configuration failure must expose the blocked unsent invitation, not hide the reason.
  for(const root of [current,before]){const f=path.join(root,'booking-mail.json'),c=JSON.parse(fs.readFileSync(f));c.enabled=false;fs.writeFileSync(f,JSON.stringify(c));}
  await goto('/staff-bookings.php?reference='+refs.confirmed);assert.notEqual(await page.locator('#readiness').getAttribute('open'),null);assert.match(await page.locator('#readiness').innerText(),/prerequisite needs attention/);assert(await page.getByRole('button',{name:'Check Booking Readiness',exact:true}).isVisible());assert.equal(await page.locator('input[name=action][value=send_invitation]').count(),0);
  // Rejected POSTs stay rejected and keep their working section open with visible feedback.
  await goto('/staff-bookings.php?reference='+refs.reviewed);assert.equal((await post({action:'confirm_calendar',reference:refs.reviewed},{headers:{Origin:'https://untrusted.example'}})).status(),403);
  const rejected=await post({action:'confirm_calendar',reference:refs.reviewed});assert.equal(rejected.status(),200);await page.goto(origin+'/staff-bookings.php?reference='+refs.reviewed);
  // Use a real browser POST so the returned error presentation can be inspected.
  await page.locator('form').filter({has:page.locator('input[name=action][value=confirm_calendar]')}).evaluate(f=>f.submit());
  await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/Confirm the customer-agreed/);assert.notEqual(await page.locator('#calendar-confirmation').getAttribute('open'),null);
  assert.equal(setup('snapshot'),snapshot,'Rejected consent/origin requests leave all bookings unchanged');
  assert(!fs.existsSync(path.join(current,'provider-blocked.txt')),'Rejected actions call no providers');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Fatal error|Warning|Notice)/.test(logs),logs);
  console.log('portal-staff: PASS (19 baseline form contracts; unchanged ledgers; no GET/provider calls; real HTTPS login/CSRF/origin/consent; staged controls; recovery; Central Time; keyboard; 320/390/736/1200 layout)');
 }finally{if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php){php.kill();await new Promise(r=>php.once('exit',r));}fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
