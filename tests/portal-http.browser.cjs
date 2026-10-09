'use strict';
const {assertApplicationShell}=require('./application-shell.browser-checks.cjs');
const assert=require('node:assert/strict');
const fs=require('node:fs');const os=require('node:os');const path=require('node:path');
const http=require('node:http');const https=require('node:https');const net=require('node:net');
const {spawn,execFileSync}=require('node:child_process');const {chromium}=require('playwright');
const repo=path.resolve(__dirname,'..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-portal-http-'));
const privateRoot=path.join(temp,'private');fs.cpSync(path.join(repo,'_private'),privateRoot,{recursive:true});
// Reuse code only; previous unit-test session directories are not fixture state.
fs.rmSync(path.join(privateRoot,'data'),{recursive:true,force:true});
fs.writeFileSync(path.join(temp,'application-entry.php'),fs.readFileSync(path.join(repo,'public/application-entry.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',privateRoot));
const accountEntry=path.join(temp,'account.php');
fs.writeFileSync(accountEntry,fs.readFileSync(path.join(repo,'public/account.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',privateRoot));
const releaseFlag=path.join(privateRoot,'portal-test.json');
fs.writeFileSync(releaseFlag,JSON.stringify({release:'portal-20260929-r2',stage:'TEST',enabled:true}),{mode:0o600});
// Replace provider boundaries only in the throwaway copy; production has no test override.
for(const [file,name] of [['portal-billing.php','portal_stripe'],['booking-lifecycle.php','booking_lifecycle_connection'],['portal-sms.php','portal_sms_send'],['portal-sms.php','portal_sms_check']]){
 const target=path.join(privateRoot,'server',file);fs.writeFileSync(target,fs.readFileSync(target,'utf8').replace('function '+name+'(', 'function '+name+'_unused_fixture('));
}
const mail=path.join(temp,'mail.txt');
const sendmail=path.join(temp,'capture-mail.sh');fs.writeFileSync(sendmail,'#!/bin/sh\ncat >> "'+mail+'"\n',{mode:0o700});
const reserve=()=>new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});
const listen=(server,port)=>new Promise(resolve=>server.listen(port,'127.0.0.1',resolve));
(async()=>{
 let php,browser,proxy;let serverLog='';
 try {
  const phpPort=await reserve(),tlsPort=await reserve(),origin='https://127.0.0.1:'+tlsPort;
  const env={...process.env,PORTAL_TEST_PRIVATE:privateRoot,PORTAL_TEST_ACCOUNT:accountEntry,SITESEE_REAL_ESTATE_SITE_URL:origin,
    SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:'isolated-test-secret-never-used-in-production-12345',
    SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET:'sk_test_isolated1234567890123456',SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED:'1',
    SITESEE_REAL_ESTATE_BOOKING_DB:path.join(privateRoot,'data','bookings.sqlite'),SITESEE_SMTP_HOST:''};
  const setup=(action)=>execFileSync('php',[path.join(__dirname,'fixtures/portal-http/setup.php'),action],{env,encoding:'utf8'});
  setup('seed');const baseline=setup('snapshot');
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem'),'-days','1','-subj','/CN=localhost'],{stdio:'ignore'});
  php=spawn('php',['-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','sendmail_path='+sendmail,'-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect','-S','127.0.0.1:'+phpPort,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/portal-http/router.php')],{env,stdio:['ignore','pipe','pipe']});
  php.stderr.on('data',b=>serverLog+=b.toString());
  proxy=https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))},(req,res)=>{
    const forward=http.request({hostname:'127.0.0.1',port:phpPort,path:req.url,method:req.method,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});
    forward.on('error',()=>{res.writeHead(503);res.end();});req.pipe(forward);
  });await listen(proxy,tlsPort);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1200,height:950}});
  const twoContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
  // Synthetic tests may contact only their own local server. No mail/Stripe/calendar/CRM provider requests.
  for(const c of [context,twoContext])await c.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
  const page=await context.newPage(),two=await twoContext.newPage();const errors=[];
  page.on('pageerror',e=>errors.push(e.message));two.on('pageerror',e=>errors.push(e.message));
  const goto=async(p,url='/account.php')=>{let r;for(let i=0;i<25;i++){r=await p.goto(origin+url);if(!r)r=await p.reload();if(r.status()!==503)return r;await p.waitForTimeout(100);}throw Error('PHP server not ready: '+serverLog);};
  const csrf=p=>p.locator('input[name=csrf]').first().inputValue();
  const post=async(p,data)=>p.request.post(origin+'/account.php',{form:{csrf:await csrf(p),...data},maxRedirects:0,headers:{Origin:origin}});
  const phones={'one@example.com':'3125550100','two@example.com':'3125550101','sales@re.sitesee.ai':'3125550102'};
  const requestLink=async(p,email)=>{
    setup('reset-sms-rate'); // Core suite separately verifies all persistent throttles.
    await goto(p);await p.getByLabel('Cell Phone Number',{exact:true}).fill(phones[email]);await p.getByLabel('Text me a one-time sign-in code.',{exact:false}).check();await p.getByRole('button',{name:'Text My Sign In Code'}).click();
    await p.getByRole('heading',{name:'Enter Your Text Code'}).waitFor();
    return Object.values(JSON.parse(fs.readFileSync(path.join(privateRoot,'data/sms-fixture.json'),'utf8'))).at(-1).code;
  };
  const login=async(p,email)=>{
    const code=await requestLink(p,email);await p.getByLabel('Sign In Code',{exact:true}).fill(code);
    await p.getByRole('button',{name:'Sign In',exact:true}).click();await p.getByRole('heading',{name:'My Orders',exact:true}).waitFor();return code;
  };
  const claim=async(p,code)=>{
    await p.getByText('Missing A Previous Order?',{exact:true}).click();await p.getByLabel('Private Order Link Or Code').fill(code);await p.getByRole('button',{name:'Add Previous Order',exact:true}).click();
  };
  const first=await goto(page);assert.match(first.headers()['cache-control'],/no-store/);assert.equal(first.headers()['referrer-policy'],'same-origin');
  await assertApplicationShell(page,'customer',false);
  if(process.env.PORTAL_SCREENSHOTS)console.log('PORTAL_VISUAL_phone-signin:'+(await page.screenshot({type:'jpeg',quality:65,fullPage:true})).toString('base64'));
  const cookies=await context.cookies();assert(cookies[0].secure&&cookies[0].httpOnly&&cookies[0].sameSite==='Lax');
  assert.equal((await page.request.post(origin+'/account.php',{form:{action:'request_login',email:'one@example.com'}})).status(),403);
  assert.equal((await page.request.post(origin+'/account.php',{form:{action:'request_login',email:'one@example.com',csrf:await csrf(page)},headers:{Origin:'https://evil.example'}})).status(),403);
  assert.equal((await page.request.put(origin+'/account.php')).status(),405);
  assert.equal((await post(page,{action:'request_login',email:'one@example.com'})).status(),400,'Email route disabled');
  await goto(page);const unknown=await post(page,{action:'request_sms',phone:'3125550199',sms_consent:'yes'});assert.equal(unknown.status(),303);assert(!fs.existsSync(path.join(privateRoot,'data/sms-fixture.json')));assert(!fs.existsSync(mail));
  const token=await requestLink(page,'one@example.com');const anonymous=(await context.cookies())[0].value;
  if(process.env.PORTAL_SCREENSHOTS)console.log('PORTAL_VISUAL_phone-code:'+(await page.screenshot({type:'jpeg',quality:65,fullPage:true})).toString('base64'));
  // The code is session bound and never consumed by GET.
  await goto(page,'/account.php?view=verify#'+token);assert.equal(new URL(page.url()).hash,'');
  await goto(two,'/account.php?view=verify#'+token);
  await page.getByLabel('Sign In Code',{exact:true}).fill(token);await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('heading',{name:'My Orders',exact:true}).waitFor();
  await assertApplicationShell(page,'customer',true);
  assert.notEqual((await context.cookies())[0].value,anonymous);assert(!(await page.content()).includes('101 Example Lane'),'No email-only historical attachment');
  await two.getByLabel('Sign In Code',{exact:true}).fill(token);await two.getByRole('button',{name:'Sign In',exact:true}).click();await two.getByRole('alert').waitFor();assert.match(await two.getByRole('alert').textContent(),/could not be verified/);
  await claim(page,origin+'/booking-pay.php?reference=AAAAAAAAAA&token='+'1'.repeat(64));await page.getByRole('heading',{name:'101 Example Lane Chicago IL 60601'}).waitFor();
  await login(two,'two@example.com');await claim(two,'BBBBBBBBBB.'+'2'.repeat(64));assert(!(await two.content()).includes('101 Example Lane'));
  const denied=await goto(two,'/account.php?view=order&reference=AAAAAAAAAA');assert.equal(denied.status(),404);assert(!(await two.content()).includes('1234567890'));const deniedBody=await denied.text();
  const absent=await goto(two,'/account.php?view=order&reference=DDDDDDDDDD');assert.equal(await absent.text(),deniedBody,'Other and nonexistent order use identical response');
  await goto(two);await claim(two,'AAAAAAAAAA.'+'1'.repeat(64));assert.match(await two.getByRole('status').textContent(),/could not be added/);
  await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');await page.getByRole('link',{name:'Services & Access',exact:true}).click();assert((await page.content()).includes('1234567890'));
  for(const secret of ['9876543210','cus_private_','pi_private_', 'agent_token_hash', 'request_json'])assert(!(await page.content()).includes(secret),secret);
  await goto(page);await claim(page,origin+'/manage-appointment.php#CCCCCCCCCC.'+'4'.repeat(64));assert((await page.content()).includes('303 Unclaimed Road'));
  fs.unlinkSync(releaseFlag);assert.equal((await page.request.get(origin+'/account.php')).status(),503,'Private disable overrides inherited TEST flag');
  fs.writeFileSync(releaseFlag,JSON.stringify({release:'portal-20260929-r2',stage:'TEST',enabled:true}),{mode:0o600});
  await goto(page,'/account.php?view=new');assert.equal(await page.locator('#portal-wizard').count(),0,'Incomplete Account cannot open booking wizard');
  assert.equal(await page.locator('header a[href="/account.php?view=new"]').count(),0,'New Order waits for Account completion');
  assert.equal(await page.locator('input[name=first_name][required],input[name=last_name][required],input[name=company][required],input[name=phone][required]').count(),4);
  assert.equal((await post(page,{action:'review_order',payload:'{}'})).status(),422,'Forged review cannot bypass required Account');
  assert.equal((await post(page,{action:'save_profile',first_name:'Test',company:'Synthetic',phone:'3125550199'})).status(),400,'Server requires missing last name');
  await goto(page,'/account.php?view=profile');await page.getByLabel('First Name',{exact:true}).fill('<script>alert(1)</script>');await page.getByLabel('Company',{exact:true}).fill('Saved Company');
  // Untrusted identity and role fields are ignored; only allowlisted contact fields can change.
  const profile=await post(page,{action:'save_profile',first_name:'<script>alert(1)</script>',last_name:'Example',company:'Saved Company',phone:'3125550199',account_id:'f'.repeat(32),email:'two@example.com',approved:'1'});assert.equal(profile.status(),303);
  await goto(page,'/account.php?view=profile');assert.equal(await page.getByLabel('Company',{exact:true}).inputValue(),'Saved Company');assert.equal(await page.getByLabel('Contact Phone',{exact:true}).inputValue(),'3125550199');assert((await page.content()).includes('&lt;script&gt;'));assert((await page.content()).includes('one@example.com'));
  await goto(two,'/account.php?view=profile');assert.equal(await two.getByLabel('Company',{exact:true}).inputValue(),'');
  const shots=process.env.PORTAL_SCREENSHOTS;
  if(shots)fs.mkdirSync(shots,{recursive:true});
  for(const view of ['','?view=order&reference=AAAAAAAAAA','?view=profile']){
    await goto(page,'/account.php'+view);
    for(const width of [320,390,736,1200,1600]) {await page.setViewportSize({width,height:950});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow '+width+' '+view);}
    if(shots){const name=view.includes('order')?'account-order':view.includes('profile')?'account-profile':'account-orders';await page.evaluate(()=>document.fonts.ready);assert(await page.evaluate(()=>document.fonts.check('16px Inter') && document.fonts.check('21px Poppins')));await page.screenshot({path:path.join(shots,name+'.png'),fullPage:true});console.log('PORTAL_VISUAL_'+name+':'+(await page.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));}
  }
  await goto(page);await page.setViewportSize({width:390,height:844});if(shots){await page.screenshot({path:path.join(shots,'account-mobile.png'),fullPage:true});console.log('PORTAL_VISUAL_account-mobile:'+(await page.screenshot({type:'jpeg',quality:55,fullPage:true})).toString('base64'));}
  // Real account wizard: both markets, retained answers, server review, durable submit and owned deposit.
  await goto(page,'/account.php?view=profile');
  // Actual controller, HTTPS forms, owned calendar adapter and isolated provider boundaries.
  setup('seed-service');
  const serviceContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
  await serviceContext.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
  const service=await serviceContext.newPage();await login(service,'sales@re.sitesee.ai');
  for(const view of ['appointment','billing','balance'])assert.equal((await goto(two,'/account.php?view='+view+'&reference=DDDD000001')).status(),404);
  await goto(service,'/account.php?view=order&reference=DDDD000001&step=payment');
  const paymentPanel=service.locator('section.panel').filter({has:service.getByRole('heading',{name:'Payment',exact:true})});
  assert.match(await paymentPanel.innerText(),/Approved Rush Fee\s+\$59\.00/);
  assert.equal(await service.getByRole('link',{name:'Job & Deliverables',exact:true}).count(),0,'Job action waits for onsite trigger');
  assert.equal(await service.locator('.payment-action').count(),0,'No final payment control before onsite completion');
  for(const width of [320,390,736,1200,1600]){await service.setViewportSize({width,height:950});assert(await service.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Unpaid order overflow '+width);}
  if(shots)console.log('PORTAL_VISUAL_polish-unpaid:'+(await service.screenshot({type:'jpeg',quality:65,fullPage:true})).toString('base64'));
  await goto(service,'/account.php?view=job&reference=DDDD000001');
  assert.match(await service.locator('h1').innerText(),/Job Status/);
  await goto(service,'/account.php?view=balance&reference=DDDD000001');
  await service.getByRole('heading',{name:'Your Test Balance',exact:true}).waitFor();
  assert.equal(await service.locator('input[name=card_consent]').isChecked(),false,'Payment action retains fresh consent');
  await goto(service,'/account.php?view=billing&reference=DDDD000001');assert.equal(await service.getByRole('link',{name:'View Receipt'}).getAttribute('href'),'https://pay.stripe.com/receipts/payment/synthetic-http');
  if(shots)console.log('PORTAL_VISUAL_service-billing:'+(await service.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));
  await goto(service,'/account.php?view=balance&reference=DDDD000001&result=return');assert.match(await service.getByRole('status').textContent(),/not been recorded/);
  const balanceScope=await service.locator('input[name=scope]').inputValue();
  assert.equal((await post(service,{action:'balance_checkout',reference:'DDDD000001',scope:balanceScope})).status(),422);
  const checkout=await post(service,{action:'balance_checkout',reference:'DDDD000001',scope:balanceScope,card_consent:'yes'});assert.equal(checkout.status(),200,await checkout.text());assert.equal((await checkout.json()).mode,'hosted');
  if(shots)console.log('PORTAL_VISUAL_service-balance:'+(await service.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));
  await goto(service,'/account.php?view=order&reference=DDDD000001&step=payment');
  assert.match(await paymentPanel.innerText(),/Test Deposit Recorded/);
  assert.equal(await service.getByRole('link',{name:'Job & Deliverables',exact:true}).count(),0,'Open checkout does not unlock the delivery action');
  setup('service-balance-paid');await goto(service,'/account.php?view=order&reference=DDDD000001&step=payment');
  assert.equal(await paymentPanel.locator('.status').innerText(),'Test Balance Recorded');
  assert.match(await paymentPanel.innerText(),/Remaining Job Balance\s+\$0\.00/);
  assert.equal(await service.locator('.payment-action').count(),0,'Paid order cannot offer another payment');
  for(const width of [320,390,736,1200,1600]){await service.setViewportSize({width,height:950});assert(await service.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Paid order overflow '+width);}
  await service.setViewportSize({width:390,height:950});
  if(shots)console.log('PORTAL_VISUAL_polish-paid:'+(await service.screenshot({type:'jpeg',quality:65,fullPage:true})).toString('base64'));
  await goto(service);assert.match(await service.locator('article.order').innerText(),/Test Balance Recorded/);
  await goto(two);assert(!(await two.locator('main').innerText()).includes('DDDD000001'),'Paid status stays with its owner');
  await goto(service,'/account.php?view=appointment&reference=DDDD000001');
  await service.getByRole('link',{name:'Find available windows →',exact:true}).click();await service.getByRole('button',{name:'Find Available Windows'}).click();await service.getByRole('heading',{name:'Request A New Window'}).waitFor();
  const options=await service.locator('select[name=window] option').evaluateAll(os=>os.map(o=>o.value));const chosen=options.find(x=>x.endsWith('|13:00'));assert(chosen);await service.getByLabel('Arrival Window',{exact:true}).selectOption(chosen);await service.getByLabel('I want to request this arrival window for manager approval.',{exact:true}).check();
  await service.getByRole('button',{name:'Request New Window'}).click();await service.getByRole('status').waitFor();assert.match(await service.locator('main').textContent(),/Awaiting SiteSee manager approval/);assert.match(await service.locator('main').textContent(),/confirmed appointment remains unchanged/);assert.match(await service.locator('main').textContent(),/Requested window:.*13:00–15:00/);assert.equal(await service.getByRole('button',{name:'Confirm Cancellation'}).count(),0,'Pending request cannot bypass approval');
  if(shots)console.log('PORTAL_VISUAL_service-appointment:'+(await service.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));
  assert(await service.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Service mobile overflow');
  setup('service-approve-request');setup('service-notice-observed');await goto(service,'/account.php?view=appointment&reference=DDDD000001');await service.getByRole('link',{name:'Cancel appointment →',exact:true}).click();await service.getByLabel('I want to cancel this appointment.',{exact:true}).check();await service.getByRole('button',{name:'Confirm Cancellation'}).click();await service.getByText('Cancelled',{exact:true}).waitFor();
  await goto(service,'/account.php?view=order&reference=DDDD000001&step=payment');assert.equal(await service.locator('.payment-action').count(),0,'Cancelled order does not offer payment');
  await serviceContext.close();
  await goto(page,'/account.php?view=profile');await goto(two,'/account.php?view=profile');
  await post(page,{action:'save_profile',first_name:'Jordan',last_name:'Example',company:'Example Realty',phone:'3125550100'});
  const apiPost=(p,token,data)=>p.request.post(origin+'/account.php',{form:{csrf:token,...data},headers:{Origin:origin,Accept:'application/json'},maxRedirects:0});
  let residentialReference;
  for(const market of ['residential','commercial']){
    await goto(page,'/account.php?view=new');
    await page.locator(`[data-key=market][value=${market}]`).check();
    await page.getByRole('button',{name:'Next →',exact:true}).click();
    await page.getByLabel('Street address',{exact:true}).fill(market==='residential'?'415 New Example Lane':'700 Example Office');
    await page.getByLabel('City',{exact:true}).fill('Chicago');await page.getByLabel('ZIP code',{exact:true}).fill('60601');
    await page.getByRole('button',{name:'Back',exact:true}).click();await page.getByRole('button',{name:'Next →',exact:true}).click();
    assert.equal(await page.getByLabel('City',{exact:true}).inputValue(),'Chicago','Back keeps answers');
    await page.getByRole('button',{name:'Next →',exact:true}).click();
    if(market==='residential')await page.locator('[data-key=package][value=gold]').check();
    else {await page.locator('[data-service=platform]').check();await page.locator('[data-service=mp]').check();await page.getByLabel('Total hosting term · months',{exact:true}).fill('12');}
    const estimated=await page.locator('.sp-total strong').textContent();
    if(shots&&market==='commercial'){await page.setViewportSize({width:1200,height:950});await page.screenshot({path:path.join(shots,'purchase-services.png'),fullPage:true});console.log('PORTAL_VISUAL_purchase-services:'+(await page.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));}
    await page.getByRole('button',{name:'Next →',exact:true}).click();
    const date=new Date(Date.now()+10*86400000).toISOString().slice(0,10);await page.getByLabel('Preferred date',{exact:true}).fill(date);await page.locator('[data-key=time]').selectOption('09:00');
    await page.locator('[data-key=meetPhotographer][value=No]').check();await page.getByLabel('Lockbox code · 10 digits',{exact:true}).fill('1112223334');
    await page.getByLabel('I agree to the cancellation policy.',{exact:true}).check();
    if(market==='residential')setup('rotate-one-csrf'); // Simulate the token rotation after reauthentication while draft stays open.
    const reviewResponse=page.waitForResponse(r=>r.url()===origin+'/account.php'&&r.status()!==403&&r.request().postData()?.includes('review_order'),{timeout:10000}).catch(async e=>{throw Error(e.message+' Page: '+await page.locator('main').innerText()+' Errors: '+JSON.stringify(errors));});
    await page.getByRole('button',{name:'Next →',exact:true}).click();const reviewHttp=await reviewResponse;
    assert.equal(reviewHttp.status(),200,await reviewHttp.text());const review=await reviewHttp.json();
    await page.getByRole('heading',{name:'Review your order',exact:true}).waitFor();
    assert.equal('$'+(review.quote.totalCents/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}),estimated,'Actual server and engine price parity');
    for(const width of [320,390,736,1200,1600]){await page.setViewportSize({width,height:950});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Wizard overflow '+width);}
    if(shots&&market==='residential'){await page.screenshot({path:path.join(shots,'purchase-review.png'),fullPage:true});console.log('PORTAL_VISUAL_purchase-review:'+(await page.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));}
    await page.getByRole('button',{name:'Place order & continue to deposit',exact:true}).click();
    await page.getByRole('heading',{name:'Your Test Deposit',exact:true}).waitFor();
    if(shots&&market==='residential'){await page.screenshot({path:path.join(shots,'purchase-payment.png'),fullPage:true});console.log('PORTAL_VISUAL_purchase-payment:'+(await page.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));}
    const reference=new URL(page.url()).searchParams.get('reference');assert.match(reference,/^[A-F0-9]{20}$/);
    if(market==='residential')residentialReference=reference;
    const mailAfter=fs.readFileSync(mail,'utf8');
    const retry=await apiPost(page,await csrf(page),{action:'submit_order',review:review.review});assert.equal((await retry.json()).reference,reference);assert.equal(fs.readFileSync(mail,'utf8'),mailAfter,'HTTP retry does not resend notices');
    const wrong=await apiPost(two,await csrf(two),{action:'submit_order',review:review.review});assert.equal(wrong.status(),422);
    const deniedPay=await apiPost(two,await csrf(two),{action:'checkout',reference,card_consent:'yes'});assert.equal(deniedPay.status(),404);
    const noConsent=await apiPost(page,await csrf(page),{action:'checkout',reference});assert.equal(noConsent.status(),422);
    await goto(two,'/account.php?view=payment&reference='+reference);assert.match(await two.locator('main').textContent(),/not available/);await goto(two,'/account.php?view=profile');
    await goto(page,'/account.php?view=payment&result=success&reference='+reference);assert.match(await page.locator('main').textContent(),/not been recorded/);assert(!(await page.content()).includes('agent_token'));
    await goto(page,'/account.php?view=order&reference='+reference);assert.match(await page.locator('main').textContent(),/Deposit Not Recorded/);assert.match(await page.locator('main').textContent(),/Awaiting Staff Review/);
    assert.equal(await page.locator('.payment-action').count(),1,'One deposit action for '+market);
    await page.getByRole('link',{name:'Pay Test Deposit',exact:true}).press('Enter');await page.getByRole('heading',{name:'Your Test Deposit',exact:true}).waitFor();
  }
  assert.equal((await goto(page,'/account.php?view=new&again='+residentialReference)).status(),400,'Retired Order Again route cannot seed another order');
  await goto(page);assert.equal(await page.getByRole('link',{name:'Order Again',exact:true}).count(),0);
  if(shots){await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(shots,'purchase-mobile.png'),fullPage:true});console.log('PORTAL_VISUAL_purchase-mobile:'+(await page.screenshot({type:'jpeg',quality:45,fullPage:true})).toString('base64'));}
  await goto(page,'/account.php?view=profile');const retired=(await context.cookies())[0];await page.getByRole('button',{name:'Sign Out',exact:true}).click();await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  await context.addCookies([retired]);await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');assert(!(await page.content()).includes('1234567890'));
  setup('remove-approval');await login(page,'one@example.com');assert((await page.content()).includes('101 Example Lane'),'Approval persists after source removal');
  setup('expire-session');await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  await login(page,'one@example.com');setup('absolute-session');await goto(page);await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  const expired=await requestLink(page,'one@example.com');setup('expire-link');await goto(page,'/account.php?view=verify#'+expired);await page.getByLabel('Sign In Code',{exact:true}).fill(expired);await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('alert').waitFor();
  // Existing two's session also expires; make a fresh session before checking disable on one.
  await login(two,'two@example.com');setup('disable-one');
  const smsPath=path.join(privateRoot,'data/sms-fixture.json'),before=fs.readFileSync(smsPath,'utf8');await post(page,{action:'request_sms',phone:'3125550100',sms_consent:'yes'});assert.equal(fs.readFileSync(smsPath,'utf8'),before,'Disabled account SMS suppressed');
  assert.equal(setup('snapshot'),baseline,'Bookings, scheduling, lifecycle and payment evidence unchanged');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Warning|Fatal|Parse)/.test(serverLog),serverLog);
  console.log('portal-http: PASS (actual HTTPS phone-code journey; two customers; email routes disabled; both existing proof adapters; CSRF/origin; single use; expiry; logout replay; profile isolation; synthetic ordering; no external provider writes; 320/390/736/1200/1600 layout)');

 } finally {
  if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php)php.kill();fs.rmSync(temp,{recursive:true,force:true});
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
