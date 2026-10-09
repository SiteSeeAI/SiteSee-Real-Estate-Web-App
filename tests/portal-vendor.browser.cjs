'use strict';
const {assertApplicationShell}=require('./application-shell.browser-checks.cjs');
const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const http=require('node:http'),https=require('node:https'),net=require('node:net');
const {spawn,execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const repo=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-portal-http-'));
const root=path.join(temp,'private');fs.cpSync(path.join(repo,'_private'),root,{recursive:true});fs.rmSync(path.join(root,'data'),{recursive:true,force:true});
fs.writeFileSync(path.join(temp,'application-entry.php'),fs.readFileSync(path.join(repo,'public/application-entry.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',root));
const vendorEntry=path.join(temp,'vendor.php');fs.writeFileSync(vendorEntry,fs.readFileSync(path.join(repo,'public/vendor.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',root));
const entry=path.join(temp,'account.php');fs.writeFileSync(entry,fs.readFileSync(path.join(repo,'public/account.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',root));
fs.writeFileSync(path.join(root,'portal-test.json'),JSON.stringify({release:'portal-20260929-r2',stage:'TEST',enabled:true}),{mode:0o600});
for(const [file,name]of [['portal-billing.php','portal_stripe'],['booking-job.php','booking_job_stripe'],['booking-lifecycle.php','booking_lifecycle_connection'],['portal-sms.php','portal_sms_send'],['portal-sms.php','portal_sms_check']]){
 const f=path.join(root,'server',file);fs.writeFileSync(f,fs.readFileSync(f,'utf8').replace('function '+name+'(','function '+name+'_unused_fixture('));
}
fs.writeFileSync(path.join(root,'fixture-providers.php'),fs.readFileSync(path.join(__dirname,'fixtures/portal-http/service-providers.php'),'utf8').replace('function portal_stripe(','function portal_fixture_base_stripe('));
const reserve=()=>new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
(async()=>{
 let php,browser,proxy,logs='';
 try{
  const port=await reserve(),tls=await reserve(),origin='https://127.0.0.1:'+tls,ref='DDDD000001';
  const env={...process.env,PORTAL_TEST_PRIVATE:root,PORTAL_TEST_ACCOUNT:entry,VENDOR_TEST_ENTRY:vendorEntry,SITESEE_REAL_ESTATE_SITE_URL:origin,SITESEE_REAL_ESTATE_BOOKING_DB:path.join(root,'data/bookings.sqlite'),SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET:'sk_test_isolated1234567890123456',SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:'isolated-job-browser-secret-1234567890'};
  const fixture=(which,action)=>execFileSync('php',[path.join(__dirname,'fixtures',which,'setup.php'),action],{env,encoding:'utf8'});
  fixture('portal-http','seed');fixture('portal-http','seed-service');env.SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=fixture('job-http','prepare');const baseline=fixture('job-http','snapshot');
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem'),'-days','1','-subj','/CN=localhost'],{stdio:'ignore'});
  fs.mkdirSync(path.join(temp,'sessions'),{mode:0o700});
  php=spawn('php',['-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','session.save_path='+path.join(temp,'sessions'),'-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail','-S','127.0.0.1:'+port,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/job-http/router.php')],{env,stdio:['ignore','pipe','pipe']});php.stderr.on('data',b=>logs+=b.toString());
  proxy=https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))},(req,res)=>{const f=http.request({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});f.on('error',()=>{res.writeHead(503);res.end();});req.pipe(f);});await new Promise(r=>proxy.listen(tls,'127.0.0.1',r));
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const contexts=[],pages=[];
  for(let i=0;i<4;i++){
   const c=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1200,height:950}});contexts.push(c);
   await c.route('**/*',async r=>{
    if(r.request().url().startsWith(origin))return r.continue();
    // Minimal synthetic Stripe UI boundary: records the exact secret and return URL; never pays.
    if(r.request().url().startsWith('https://js.stripe.com/'))return r.fulfill({contentType:'application/javascript',body:"window.Stripe=()=>({elements:o=>{window.fixtureSecret=o.clientSecret;return {create:()=>({mount:s=>{document.querySelector(s).textContent='Synthetic secure payment field';}})}},confirmPayment:async o=>{window.fixtureReturn=o.confirmParams.return_url;return {error:{message:'Synthetic bank verification required'}}}});"});
    return r.abort();
   });pages.push(await c.newPage());
  }
  const [staff,vendor,foreign,anonymous]=pages,errors=[];pages.forEach(p=>p.on('pageerror',e=>errors.push(e.message)));
  const go=async(p,url)=>{for(let i=0;i<25;i++){const r=await p.goto(origin+url);if(r.status()!==503)return r;await p.waitForTimeout(100);}throw Error(logs);};
  const csrf=p=>p.locator('input[name=csrf]').first().inputValue();
  const post=async(p,url,data,headers={Origin:origin})=>p.request.post(origin+url,{form:{csrf:await csrf(p),...data},headers,maxRedirects:0});
  const jobUrl='/account.php?view=job&reference='+ref,staffUrl='/staff-bookings.php?reference='+ref,prodUrl='/staff-production.php?reference='+ref;
  const layout=async(p,name)=>{for(const width of [320,390,736,1200]){await p.setViewportSize({width,height:950});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),name+' overflow '+width);}if(process.env.PORTAL_SCREENSHOTS){fs.mkdirSync(process.env.PORTAL_SCREENSHOTS,{recursive:true});await p.setViewportSize({width:390,height:950});await p.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'job-'+name+'.png'),fullPage:true});}};
  await go(staff,'/staff-vendors.php');
  assert.equal((await post(staff,'/staff-vendors.php',{action:'vendor_save',name:'Unauthorized',phone:'3125550180',enabled:'1'})).status(),403);
  await go(staff,'/staff-vendors.php');await staff.getByLabel('Password',{exact:true}).fill('isolated-staff-password');await staff.getByRole('button',{name:'Sign In',exact:true}).click();await staff.getByRole('heading',{name:'Add Vendor',exact:true}).waitFor();
  const create=async(name,phone)=>{const form=staff.locator('section').filter({has:staff.getByRole('heading',{name:'Add Vendor',exact:true})});await form.getByLabel('Vendor Name',{exact:true}).fill(name);await form.getByLabel('Cell Phone Number',{exact:true}).fill(phone);await form.getByRole('button',{name:'Create Vendor Account',exact:true}).click();await staff.getByText('Vendor account saved. Any previous sign-in sessions have ended. No text was sent.',{exact:true}).waitFor();};
  await create('Vendor One','3125550130');await create('Vendor Two','3125550131');assert(!fs.existsSync(path.join(root,'data/sms-fixture.json')),'Account creation sends no SMS');
  const login=async(p,phone)=>{fixture('vendor-http','reset-rate');await go(p,'/vendor.php');await p.getByLabel('Cell Phone Number',{exact:true}).fill(phone);await p.getByLabel('Text me a one-time sign-in code.',{exact:true}).check();await p.getByRole('button',{name:'Text My Sign In Code',exact:true}).click();await p.getByRole('heading',{name:'Enter Your Text Code',exact:true}).waitFor();const code=Object.values(JSON.parse(fs.readFileSync(path.join(root,'data/sms-fixture.json')))).at(-1).code;await p.getByLabel('Sign In Code',{exact:true}).fill(code);await p.getByRole('button',{name:'Sign In',exact:true}).click();await p.getByRole('heading',{name:'My Jobs',exact:true}).waitFor().catch(async e=>{throw Error(e.message+' BODY: '+await p.locator('body').innerText()+' PHP: '+logs);});};
  await go(vendor,'/vendor.php');await assertApplicationShell(vendor,'vendor',false);
  await login(vendor,'3125550130');assert.match(await vendor.locator('main').innerText(),/No jobs are assigned/);
  await assertApplicationShell(vendor,'vendor',true);
  await login(foreign,'3125550131');
  const assign=async(name)=>{await go(staff,staffUrl+'&step=review');const select=staff.getByLabel('Vendor Account',{exact:true});await select.selectOption({label:name},{timeout:5000}).catch(async e=>{throw Error(e.message+' BODY: '+await staff.locator('main').innerText());});await staff.getByRole('button',{name:'Assign Vendor',exact:true}).click();await staff.getByText('Vendor access updated. The booking, calendar and customer payment records are preserved.',{exact:true}).waitFor();};
  await assign('Vendor One · +13125550130');
  await go(vendor,'/vendor.php');assert.equal(await vendor.getByRole('link',{name:'Open Job →',exact:true}).count(),1);
  const url='/vendor.php?reference='+ref;
  const denied=await go(foreign,url);assert.equal(denied.status(),404);const denial=await denied.text();const missing=await go(foreign,'/vendor.php?reference=FFFFFFFFFF');assert.equal(missing.status(),404);assert.equal(await missing.text(),denial,'Foreign and absent jobs use identical denials');
  await go(vendor,url);const onsite=vendor.locator('#job-onsite');const verified=()=>vendor.getByText('Fees verified. Confirm the displayed total with the agent before Job Complete.',{exact:true}).waitFor();await verified();
  assert.equal(await vendor.getByRole('link',{name:'Production',exact:true}).count(),0);assert.match(await vendor.locator('main').innerText(),/1234567890/);assert(!/cs_test_http|pi_http|cus_http|contact_selection/.test(await vendor.content()),'Vendor output excludes processor/CRM identities');
  assert.equal(await vendor.getByLabel('Service 1',{exact:true}).locator('option[value=video]').innerText(),'Property Video · Already ordered');
  for(const action of ['vendor_save','vendor_assign','job_production_save','job_production_complete','job_recover','review_paid','confirm_calendar','lifecycle_cancel'])assert.equal((await post(vendor,'/vendor.php',{action,reference:ref})).status(),403,'Manager action denied: '+action);
  await go(vendor,'/staff-bookings.php');assert.equal(await vendor.getByRole('button',{name:'Sign In',exact:true}).count(),1,'Vendor session never grants staff login');
  await go(vendor,'/staff-production.php');assert.equal(await vendor.getByRole('button',{name:'Sign In',exact:true}).count(),1);
  await go(vendor,'/staff-vendors.php');assert.equal(await vendor.getByRole('button',{name:'Sign In',exact:true}).count(),1);
  await go(vendor,'/account.php');assert.equal(await vendor.getByRole('button',{name:'Text My Sign In Code',exact:true}).count(),1,'Vendor session never grants a customer account');
  await go(anonymous,'/vendor.php');assert.equal((await post(anonymous,'/vendor.php',{action:'job_preview',reference:ref})).status(),403,'Anonymous preview is closed');
  await go(vendor,url);await verified();await vendor.getByLabel('Service 1',{exact:true}).selectOption('drone');await verified();assert.equal(await vendor.locator('#job-extra-total').innerText(),'$120.00');assert.equal(await vendor.locator('#job-commission').inputValue(),'$21.60');
  const fields=async()=>Object.fromEntries(await onsite.locator('input[type=hidden]').evaluateAll(ns=>ns.filter(n=>n.name!=='csrf').map(n=>[n.name,n.value])));
  const stale=await fields();assert.equal((await post(vendor,'/vendor.php',{...stale,action:'job_complete',agreed:'yes'},{Origin:'https://untrusted.example'})).status(),403);
  await assign('Vendor Two · +13125550131');
  for(const action of ['job_preview','job_save','job_complete'])assert.equal((await post(vendor,'/vendor.php',{...stale,action,agreed:'yes'})).status(),404,'Stale reassignment blocks '+action);
  assert(!fs.existsSync(path.join(root,'data/job-provider.json')),'Denied requests never call payment provider');
  await assign('Vendor One · +13125550130');
  assert.equal((await post(vendor,'/vendor.php',{...stale,action:'job_preview'})).status(),404,'Regrant never revives old assignment token');
  fixture('vendor-http','schedule-overlay');await go(vendor,'/vendor.php');assert.match(await vendor.locator('main').innerText(),/2026-11-12 15:00/,'List uses current appointment');await go(vendor,url);await verified();assert.match(await vendor.locator('main').innerText(),/2026-11-12 15:00–17:00/);assert.match(await vendor.locator('main').innerText(),/Capture the courtyard/);assert.match(await vendor.locator('main').innerText(),/Isolated Onsite Contact/);fixture('vendor-http','restore-schedule');
  await go(vendor,url);await verified();await vendor.getByLabel('Service 1',{exact:true}).selectOption('drone');await verified();await vendor.getByRole('button',{name:'Save Additional Services',exact:true}).click();await vendor.getByText('Additional services saved. Confirm the agent’s verbal approval and complete the job when the onsite work is finished.',{exact:true}).waitFor();await verified();
  await layout(vendor,'vendor-onsite');
  const finish={...await fields(),action:'job_complete',agreed:'yes'};await onsite.locator('input[name=agreed]').check();await vendor.getByRole('button',{name:'Job Complete',exact:true}).click();await vendor.getByText('Customer Action Needed',{exact:true}).waitFor();assert.equal(await vendor.getByRole('link',{name:'Open Production',exact:true}).count(),0);assert.equal(await vendor.getByRole('button',{name:'Check / Recover Final Payment',exact:true}).count(),0);assert(!/1234567890/.test(await vendor.locator('main').innerText()),'Completed job no longer exposes lockbox');
  const bill=JSON.parse(fixture('vendor-http','bill'));assert.equal(bill.onsite_authorization.method,'vendor_attested_verbal');assert.equal(bill.onsite_authorization.vendor.name,'Vendor One');assert.equal(bill.commission_cents,2160);assert.equal(bill.extras[0].cents,12000);
  await post(vendor,'/vendor.php',finish);const provider=JSON.parse(fs.readFileSync(path.join(root,'data/job-provider.json')));assert.equal(provider.creates,1);assert.equal(provider.confirms,1,'Double completion cannot charge twice');
  await go(staff,'/staff-production.php');assert.match(await staff.locator('main').innerText(),/404 Service Example/,'Completed vendor job enters manager Production queue');
  await go(staff,'/staff-vendors.php');const editor=staff.locator('details').filter({has:staff.locator('summary').filter({hasText:'Vendor One'})});await editor.locator('summary').click();await editor.getByLabel('Account Status',{exact:true}).selectOption('0');await editor.getByRole('button',{name:'Save Vendor',exact:true}).click();await staff.getByText('Vendor account saved. Any previous sign-in sessions have ended. No text was sent.',{exact:true}).waitFor();
  await go(vendor,url);assert.equal(await vendor.getByRole('heading',{name:'Vendor Sign In',exact:true}).count(),1,'Disable revokes active vendor session');
  assert.equal(fixture('job-http','snapshot'),baseline,'Assignments, vendor management and closeout preserve original booking/calendar/CRM history');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Fatal error|Warning|Notice)/.test(logs),logs);
  console.log('portal-vendor-browser: PASS (manager enrollment; no-assignment login; phone-only separate sessions; explicit job ownership; manager/customer isolation; CSRF; reassign/regrant revocation; current appointment; onsite pricing/commission; named verbal closeout; duplicate-charge protection; Production handoff; disabled sessions; preserved records; responsive layout; synthetic providers only)');
 }finally{if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php){php.kill();await new Promise(r=>php.once('exit',r));}fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
