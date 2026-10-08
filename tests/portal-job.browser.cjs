'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const http=require('node:http'),https=require('node:https'),net=require('node:net');
const {spawn,execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const repo=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-portal-http-'));
const root=path.join(temp,'private');fs.cpSync(path.join(repo,'_private'),root,{recursive:true});fs.rmSync(path.join(root,'data'),{recursive:true,force:true});
fs.writeFileSync(path.join(temp,'application-entry.php'),fs.readFileSync(path.join(repo,'public/application-entry.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',root));
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
  const env={...process.env,PORTAL_TEST_PRIVATE:root,PORTAL_TEST_ACCOUNT:entry,SITESEE_REAL_ESTATE_SITE_URL:origin,SITESEE_REAL_ESTATE_BOOKING_DB:path.join(root,'data/bookings.sqlite'),SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET:'sk_test_isolated1234567890123456',SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:'isolated-job-browser-secret-1234567890'};
  const fixture=(which,action)=>execFileSync('php',[path.join(__dirname,'fixtures',which,'setup.php'),action],{env,encoding:'utf8'});
  fixture('portal-http','seed');fixture('portal-http','seed-service');env.SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH=fixture('job-http','prepare');const baseline=fixture('job-http','snapshot');
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem'),'-days','1','-subj','/CN=localhost'],{stdio:'ignore'});
  fs.mkdirSync(path.join(temp,'sessions'),{mode:0o700});
  php=spawn('php',['-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','session.save_path='+path.join(temp,'sessions'),'-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail','-S','127.0.0.1:'+port,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/job-http/router.php')],{env,stdio:['ignore','pipe','pipe']});php.stderr.on('data',b=>logs+=b.toString());
  proxy=https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))},(req,res)=>{const f=http.request({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});f.on('error',()=>{res.writeHead(503);res.end();});req.pipe(f);});await new Promise(r=>proxy.listen(tls,'127.0.0.1',r));
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const contexts=[],pages=[];
  for(let i=0;i<3;i++){
   const c=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1200,height:950}});contexts.push(c);
   await c.route('**/*',async r=>{
    if(r.request().url().startsWith(origin))return r.continue();
    // Minimal synthetic Stripe UI boundary: records the exact secret and return URL; never pays.
    if(r.request().url().startsWith('https://js.stripe.com/'))return r.fulfill({contentType:'application/javascript',body:"window.Stripe=()=>({elements:o=>{window.fixtureSecret=o.clientSecret;return {create:()=>({mount:s=>{document.querySelector(s).textContent='Synthetic secure payment field';}})}},confirmPayment:async o=>{window.fixtureReturn=o.confirmParams.return_url;return {error:{message:'Synthetic bank verification required'}}}});"});
    return r.abort();
   });pages.push(await c.newPage());
  }
  const [staff,customer,foreign]=pages,errors=[];pages.forEach(p=>p.on('pageerror',e=>errors.push(e.message)));
  const go=async(p,url)=>{for(let i=0;i<25;i++){const r=await p.goto(origin+url);if(r.status()!==503)return r;await p.waitForTimeout(100);}throw Error(logs);};
  const csrf=p=>p.locator('input[name=csrf]').first().inputValue();
  const post=async(p,url,data,headers={Origin:origin})=>p.request.post(origin+url,{form:{csrf:await csrf(p),...data},headers,maxRedirects:0});
  const jobUrl='/account.php?view=job&reference='+ref,staffUrl='/staff-bookings.php?reference='+ref,prodUrl='/staff-production.php?reference='+ref;
  const layout=async(p,name)=>{for(const width of [320,390,736,1200]){await p.setViewportSize({width,height:950});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),name+' overflow '+width);}if(process.env.PORTAL_SCREENSHOTS){fs.mkdirSync(process.env.PORTAL_SCREENSHOTS,{recursive:true});await p.setViewportSize({width:390,height:950});await p.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'job-'+name+'.png'),fullPage:true});}};
  await go(staff,'/staff-production.php');assert.equal(await staff.getByRole('button',{name:'Sign In',exact:true}).count(),1);
  assert.equal((await post(staff,'/staff-production.php',{action:'job_production_save',reference:ref,revision:'0'})).status(),403);
  await staff.getByLabel('Password',{exact:true}).fill('isolated-staff-password');await staff.getByRole('button',{name:'Sign In',exact:true}).click();await staff.getByRole('heading',{name:'Production Queue',exact:true}).waitFor();
  const login=async(p,phone)=>{fixture('portal-http','reset-sms-rate');await go(p,'/account.php');await p.getByLabel('Cell Phone Number',{exact:true}).fill(phone);await p.getByLabel('Text me a one-time sign-in code.',{exact:false}).check();await p.getByRole('button',{name:'Text My Sign In Code'}).click();await p.getByRole('heading',{name:'Enter Your Text Code'}).waitFor();const code=Object.values(JSON.parse(fs.readFileSync(path.join(root,'data/sms-fixture.json')))).at(-1).code;await p.getByLabel('Sign In Code',{exact:true}).fill(code);await p.getByRole('button',{name:'Sign In',exact:true}).click();await p.getByRole('heading',{name:'My Orders',exact:true}).waitFor().catch(async e=>{throw Error(e.message+' Page: '+await p.locator('body').innerText()+' PHP: '+logs);});};
  await login(customer,'3125550102');await login(foreign,'3125550101');
  const denied=await go(foreign,jobUrl);assert.equal(denied.status(),400);const denial=await denied.text();const absent=await go(foreign,'/account.php?view=job&reference=FFFFFFFFFF');assert.equal(absent.status(),400);assert.equal(await absent.text(),denial,'Foreign and missing jobs use the same generic response');
  await go(staff,staffUrl+'&step=onsite');
  await staff.locator('#onsite-closeout>summary').click();
  const verified=()=>staff.getByText('Fees verified. Confirm the displayed total with the agent before Job Complete.',{exact:true}).waitFor();
  const onsite=staff.locator('#job-onsite');await verified();
  const keys=['photo','platform','website','drone','zillow','video','floor','twilight','mp'];
  assert.deepEqual(await staff.getByLabel('Service 1',{exact:true}).locator('option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean)),keys,'Full residential catalog includes preordered/package services');
  assert(await staff.locator('#job-add-item').isHidden());
  for(let i=0;i<keys.length;i++){
   if(i)await staff.getByRole('button',{name:'Add Additional Service Item',exact:true}).click();
   const select=staff.getByLabel('Service '+(i+1),{exact:true});
   const remaining=await select.locator('option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean));
   assert.deepEqual(remaining,keys.slice(i),'New pick list excludes every previously selected key');
   await select.selectOption(keys[i]);
   if(keys[i]==='photo')await staff.getByLabel('Photography Coverage (SQF)',{exact:true}).fill('1200');
   if(keys[i]==='mp')await staff.getByLabel('Matterport Coverage (SQF)',{exact:true}).fill('2500');
  }
  await verified();assert(await staff.locator('#job-add-item').isHidden(),'No add button when all services are chosen');
  while(await onsite.getByRole('button',{name:'Remove Service',exact:true}).count()>1)await onsite.getByRole('button',{name:'Remove Service',exact:true}).last().click();
  await staff.getByLabel('Service 1',{exact:true}).selectOption('drone');
  await staff.getByRole('button',{name:'Add Additional Service Item',exact:true}).click();await staff.getByLabel('Service 2',{exact:true}).selectOption('mp');await staff.getByLabel('Matterport Coverage (SQF)',{exact:true}).fill('2500');
  await staff.getByRole('button',{name:'Add Additional Service Item',exact:true}).click();await staff.getByLabel('Service 3',{exact:true}).selectOption('video');await staff.getByLabel('Number Of Minutes',{exact:true}).fill('2');
  await verified();assert.equal(await staff.locator('#job-extra-total').innerText(),'$557.50');assert.equal(await staff.locator('#job-commission').inputValue(),'$21.60','Only new drone earns commission; original MP and package video excluded');
  await onsite.locator('input[name=agreed]').check();await staff.getByLabel('Number Of Minutes',{exact:true}).fill('3');assert(!(await onsite.locator('input[name=agreed]').isChecked()),'Editing fees clears verbal attestation');await verified();
  await staff.getByLabel('Number Of Minutes',{exact:true}).fill('2');await verified();await layout(staff,'onsite');
  if(process.env.PORTAL_SCREENSHOTS){await staff.setViewportSize({width:390,height:2200});await onsite.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'onsite-picker-mobile.png')});}
  assert(!fs.existsSync(path.join(root,'data/job-provider.json')),'Preview never charges');
  await staff.getByRole('button',{name:'Save Additional Services',exact:true}).click();await staff.getByText('Additional services saved. Confirm the agent’s verbal approval and complete the job when the onsite work is finished.',{exact:true}).waitFor({timeout:5000}).catch(async e=>{throw Error(e.message+' Staff: '+await staff.locator('main').innerText()+' PHP: '+logs);});await verified();
  await go(customer,jobUrl);assert.match(await customer.locator('main').innerText(),/Drone \/ Aerial Photos/);assert(!fs.existsSync(path.join(root,'data/job-provider.json')),'Saving never charges; no agent portal approval submitted');
  const scope=await onsite.locator('input[name=scope]').inputValue(),items=await onsite.locator('input[name=items]').inputValue(),draft_scope=await onsite.locator('input[name=draft_scope]').inputValue();
  const closeout={action:'job_complete',reference:ref,scope,items,draft_scope,agreed:'yes'};
  assert.equal((await post(staff,'/staff-bookings.php',closeout,{Origin:'https://untrusted.example'})).status(),403);
  await onsite.locator('input[name=agreed]').check();await onsite.getByRole('button',{name:'Job Complete',exact:true}).click();await staff.getByText('Customer Action Needed',{exact:true}).waitFor();await layout(staff,'payment-recovery');
  await post(staff,'/staff-bookings.php',closeout);await staff.getByRole('button',{name:'Check / Recover Final Payment',exact:true}).click();
  let provider=JSON.parse(fs.readFileSync(path.join(root,'data/job-provider.json')));assert.equal(provider.creates,1);assert.equal(provider.confirms,1);
  fixture('job-http','recovery-ready');await go(customer,jobUrl);assert.match(await customer.locator('main').innerText(),/Production/);await customer.locator('#job-payment input[name=agreed]').check();await customer.getByRole('button',{name:'Continue To Secure Payment',exact:true}).click();await customer.getByRole('button',{name:'Pay Final Test Balance',exact:true}).waitFor();assert.equal(await customer.evaluate(()=>window.fixtureSecret),'synthetic_http_job_secret');
  await customer.getByRole('button',{name:'Pay Final Test Balance',exact:true}).click();await customer.getByText('Synthetic bank verification required',{exact:true}).waitFor();assert.equal(await customer.evaluate(()=>window.fixtureReturn),origin+jobUrl);await layout(customer,'customer-payment');
  await go(staff,prodUrl);await staff.getByLabel('Photo Download URL',{exact:true}).fill('https://deliverables.example/photos');await staff.getByRole('button',{name:'Save Progress',exact:true}).click();await go(customer,jobUrl);assert(!(await customer.content()).includes('https://deliverables.example/photos'),'Draft remains private');
  await go(staff,prodUrl);await staff.getByLabel('Independent Website URL',{exact:true}).fill('https://property.example/');await staff.getByLabel('Video Download URL',{exact:true}).fill('https://deliverables.example/video');await staff.getByLabel('SiteSee Platform URL',{exact:true}).fill('https://platform.example/property');await staff.getByLabel('Floor Plan Download URL',{exact:true}).fill('https://deliverables.example/floor');await staff.getByText('Other Deliverables',{exact:true}).click();await staff.getByLabel('Deliverable 1 Name',{exact:true}).fill('Matterport Experience');await staff.getByLabel('Deliverable 1 URL',{exact:true}).fill('https://matterport.example/property');await layout(staff,'production');
  await staff.getByLabel('All ordered deliverables are complete and the links have been checked.',{exact:true}).check();await staff.getByRole('button',{name:'Production Complete',exact:true}).click();await go(customer,jobUrl);assert(!(await customer.content()).includes('https://deliverables.example/photos'),'Unpaid finished links remain private');
  fixture('job-http','paid');await go(customer,jobUrl);assert.equal(await customer.getByRole('link',{name:'Photo Download',exact:true}).getAttribute('href'),'https://deliverables.example/photos');assert.match(await customer.locator('main').innerText(),/\$0\.00/);assert.equal(await customer.getByRole('link',{name:'Matterport Experience',exact:true}).count(),1);await layout(customer,'delivered');
  await go(staff,prodUrl);await staff.getByLabel('Photo Download URL',{exact:true}).fill('https://deliverables.example/unfinished');await staff.getByRole('button',{name:'Save Progress',exact:true}).click();await go(customer,jobUrl);assert.equal(await customer.getByRole('link',{name:'Photo Download',exact:true}).getAttribute('href'),'https://deliverables.example/photos','Published set survives later draft edits');
  fixture('job-http','refund');await go(customer,jobUrl);assert.equal(await customer.getByRole('link',{name:'Photo Download',exact:true}).count(),0);assert.match(await customer.locator('main').innerText(),/Under Billing Review/);
  await go(customer,'/account.php?view=billing&reference='+ref);assert.equal(await customer.getByRole('link',{name:'View Receipt',exact:true}).count(),2,'Deposit and final receipts stay accessible during review');
  assert.equal(fixture('job-http','snapshot'),baseline,'Original booking, calendar, CRM and mail records unchanged');provider=JSON.parse(fs.readFileSync(path.join(root,'data/job-provider.json')));assert.equal(provider.creates,1);assert.equal(provider.confirms,1);
  fixture('job-http','commercial-preview');await go(staff,staffUrl+'&step=onsite');await staff.locator('#onsite-closeout>summary').click();await verified();
  const commercialKeys=['photo','platform','mp','views360','drone','video','floor','website'];
  assert.deepEqual(await staff.getByLabel('Service 1',{exact:true}).locator('option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean)),commercialKeys);
  for(let i=0;i<commercialKeys.length;i++){
   if(i)await staff.getByRole('button',{name:'Add Additional Service Item',exact:true}).click();
   await staff.getByLabel('Service '+(i+1),{exact:true}).selectOption(commercialKeys[i]);
   if(commercialKeys[i]==='mp'){
    await staff.getByLabel('Matterport Coverage (SQF)',{exact:true}).fill('2000');await staff.getByLabel('Matterport Hosting (Months)',{exact:true}).fill('18');await staff.getByLabel('Hosting Payment',{exact:true}).selectOption('yes');
   }
   if(commercialKeys[i]==='video')await staff.getByLabel('Number Of Minutes',{exact:true}).fill('2');
  }
  await verified();assert(await staff.locator('#job-add-item').isHidden());
  const droneRow=onsite.locator('fieldset').filter({has:staff.getByLabel('Number Of Aerial Images',{exact:true})});
  await droneRow.getByLabel('Media License',{exact:true}).selectOption('term');await droneRow.getByLabel('License Term (Months)',{exact:true}).fill('');await droneRow.getByLabel('Media License',{exact:true}).selectOption('unlimited');await verified();
  assert(await droneRow.getByLabel('License Term (Months)',{exact:true}).isHidden());assert(await droneRow.getByLabel('License Term (Months)',{exact:true}).isDisabled());
  assert.equal(await staff.locator('#job-extra-total').innerText(),'$3,591.82');assert.equal(await staff.locator('#job-commission').inputValue(),'$286.55','Commercial subscriptions, licensing, hosting and original photo excluded');
  await layout(staff,'commercial-onsite');
  provider=JSON.parse(fs.readFileSync(path.join(root,'data/job-provider.json')));assert.equal(provider.creates,1);assert.equal(provider.confirms,1,'Commercial previews never call providers');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Fatal error|Warning|Notice)/.test(logs),logs);
  console.log('portal-job-browser: PASS (real HTTPS staff/phone login; full repeatable catalog; server prices; commission exclusions; verbal closeout; Job Complete; one payment; decline recovery UI; drafts; unpaid release gate; paid links; refund review; preserved records; 320/390/736/1200 layout; synthetic providers only)');
 }finally{if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php){php.kill();await new Promise(r=>php.once('exit',r));}fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
