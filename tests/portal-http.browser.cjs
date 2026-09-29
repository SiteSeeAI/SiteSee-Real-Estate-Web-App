'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');const os=require('node:os');const path=require('node:path');
const http=require('node:http');const https=require('node:https');const net=require('node:net');
const {spawn,execFileSync}=require('node:child_process');const {chromium}=require('playwright');
const repo=path.resolve(__dirname,'..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-portal-http-'));
const privateRoot=path.join(temp,'private');fs.cpSync(path.join(repo,'_private'),privateRoot,{recursive:true});
const mail=path.join(temp,'mail.txt');
const sendmail=path.join(temp,'capture-mail.sh');fs.writeFileSync(sendmail,'#!/bin/sh\ncat >> "'+mail+'"\n',{mode:0o700});
const reserve=()=>new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});
const listen=(server,port)=>new Promise(resolve=>server.listen(port,'127.0.0.1',resolve));
(async()=>{
 let php,browser,proxy;let serverLog='';
 try {
  const phpPort=await reserve(),tlsPort=await reserve(),origin='https://127.0.0.1:'+tlsPort;
  const env={...process.env,PORTAL_TEST_PRIVATE:privateRoot,SITESEE_REAL_ESTATE_SITE_URL:origin,
    SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:'isolated-test-secret-never-used-in-production-12345',
    SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED:'1',SITESEE_REAL_ESTATE_PORTAL_TEST_ENABLED:'1',
    SITESEE_REAL_ESTATE_BOOKING_DB:path.join(privateRoot,'data','bookings.sqlite'),SITESEE_SMTP_HOST:''};
  const setup=(action)=>execFileSync('php',[path.join(__dirname,'fixtures/portal-http/setup.php'),action],{env,encoding:'utf8'});
  setup('seed');const baseline=setup('snapshot');
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem'),'-days','1','-subj','/CN=localhost'],{stdio:'ignore'});
  php=spawn('php',['-d','sendmail_path='+sendmail,'-S','127.0.0.1:'+phpPort,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/portal-http/router.php')],{env,stdio:['ignore','pipe','pipe']});
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
  const goto=async(p,url='/account.php')=>{let r;for(let i=0;i<25;i++){r=await p.goto(origin+url);if(r.status()!==503)return r;await p.waitForTimeout(100);}throw Error('PHP server not ready: '+serverLog);};
  const csrf=p=>p.locator('input[name=csrf]').first().inputValue();
  const post=async(p,data)=>p.request.post(origin+'/account.php',{form:{csrf:await csrf(p),...data},maxRedirects:0,headers:{Origin:origin}});
  const requestLink=async(p,email)=>{
    await goto(p);await p.getByLabel('Email Address',{exact:true}).fill(email);await p.getByRole('button',{name:'Email My Sign In Link'}).click();
    await p.getByRole('status').waitFor();
    const tokens=[...fs.readFileSync(mail,'utf8').matchAll(/view=verify#([a-f0-9]{64})/g)];return tokens.at(-1)[1];
  };
  const login=async(p,email)=>{
    const token=await requestLink(p,email);await goto(p,'/account.php?view=verify#'+token);
    await p.getByRole('button',{name:'Sign In',exact:true}).click();await p.getByRole('heading',{name:'My Orders',exact:true}).waitFor();return token;
  };
  const claim=async(p,code)=>{
    await p.getByText('Missing A Previous Order?',{exact:true}).click();await p.getByLabel('Private Order Link Or Code').fill(code);await p.getByRole('button',{name:'Add Previous Order',exact:true}).click();
  };
  const first=await goto(page);assert.match(first.headers()['cache-control'],/no-store/);assert.equal(first.headers()['referrer-policy'],'no-referrer');
  const cookies=await context.cookies();assert(cookies[0].secure&&cookies[0].httpOnly&&cookies[0].sameSite==='Lax');
  assert.equal((await page.request.post(origin+'/account.php',{form:{action:'request_login',email:'one@example.com'}})).status(),403);
  assert.equal((await page.request.post(origin+'/account.php',{form:{action:'request_login',email:'one@example.com',csrf:await csrf(page)},headers:{Origin:'https://evil.example'}})).status(),403);
  assert.equal((await page.request.put(origin+'/account.php')).status(),405);
  const unknown=await post(page,{action:'request_login',email:'unknown@example.com'});assert.equal(unknown.status(),303);assert(!fs.existsSync(mail));
  const token=await requestLink(page,'one@example.com');const anonymous=(await context.cookies())[0].value;
  // GET and email prefetch do not consume login.
  await goto(page,'/account.php?view=verify#'+token);assert.equal(new URL(page.url()).hash,'');
  await goto(two,'/account.php?view=verify#'+token);
  await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('heading',{name:'My Orders',exact:true}).waitFor();
  assert.notEqual((await context.cookies())[0].value,anonymous);assert(!(await page.content()).includes('101 Example Lane'),'No email-only historical attachment');
  await two.getByRole('button',{name:'Sign In',exact:true}).click();await two.getByRole('alert').waitFor();assert.match(await two.getByRole('alert').textContent(),/invalid or expired/);
  await claim(page,'AAAAAAAAAA.'+'1'.repeat(64));await page.getByRole('heading',{name:'101 Example Lane Chicago IL 60601'}).waitFor();
  await login(two,'two@example.com');await claim(two,'BBBBBBBBBB.'+'2'.repeat(64));assert(!(await two.content()).includes('101 Example Lane'));
  const denied=await goto(two,'/account.php?view=order&reference=AAAAAAAAAA');assert.equal(denied.status(),404);assert(!(await two.content()).includes('1234567890'));
  const absent=await goto(two,'/account.php?view=order&reference=DDDDDDDDDD');assert.equal(await absent.text(),await denied.text(),'Other and nonexistent order use identical response');
  await goto(two);await claim(two,'AAAAAAAAAA.'+'1'.repeat(64));assert.match(await two.getByRole('status').textContent(),/could not be added/);
  await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');assert((await page.content()).includes('1234567890'));
  for(const secret of ['9876543210','cus_private_','pi_private_', 'agent_token_hash', 'request_json'])assert(!(await page.content()).includes(secret),secret);
  await goto(page);await claim(page,origin+'/manage-appointment.php#CCCCCCCCCC.'+'4'.repeat(64));assert((await page.content()).includes('303 Unclaimed Road'));
  await goto(page,'/account.php?view=profile');await page.getByLabel('First Name',{exact:true}).fill('<script>alert(1)</script>');await page.getByLabel('Company',{exact:true}).fill('Saved Company');
  // Untrusted identity and role fields are ignored; only allowlisted contact fields can change.
  const profile=await post(page,{action:'save_profile',first_name:'<script>alert(1)</script>',company:'Saved Company',account_id:'f'.repeat(32),email:'two@example.com',approved:'1'});assert.equal(profile.status(),303);
  await goto(page,'/account.php?view=profile');assert.equal(await page.getByLabel('Company',{exact:true}).inputValue(),'Saved Company');assert((await page.content()).includes('&lt;script&gt;'));assert((await page.content()).includes('one@example.com'));
  await goto(two,'/account.php?view=profile');assert.equal(await two.getByLabel('Company',{exact:true}).inputValue(),'');
  const shots=process.env.PORTAL_SCREENSHOTS;
  if(shots)fs.mkdirSync(shots,{recursive:true});
  for(const view of ['','?view=order&reference=AAAAAAAAAA','?view=profile']){
    await goto(page,'/account.php'+view);
    for(const width of [320,390,736,1200]) {await page.setViewportSize({width,height:950});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow '+width+' '+view);}
    if(shots){const name=view.includes('order')?'account-order':view.includes('profile')?'account-profile':'account-orders';await page.screenshot({path:path.join(shots,name+'.png'),fullPage:true});console.log('PORTAL_VISUAL_'+name+':'+Buffer.from(await page.content()).toString('base64'));}
  }
  await goto(page);await page.setViewportSize({width:390,height:844});if(shots)await page.screenshot({path:path.join(shots,'account-mobile.png'),fullPage:true});
  await goto(page,'/account.php?view=profile');const retired=(await context.cookies())[0];await page.getByRole('button',{name:'Sign Out',exact:true}).click();await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  await context.addCookies([retired]);await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');assert(!(await page.content()).includes('1234567890'));
  setup('remove-approval');await login(page,'one@example.com');assert((await page.content()).includes('101 Example Lane'),'Approval persists after source removal');
  setup('expire-session');await goto(page,'/account.php?view=order&reference=AAAAAAAAAA');await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  await login(page,'one@example.com');setup('absolute-session');await goto(page);await page.getByRole('heading',{name:'Sign In',exact:true}).waitFor();
  const expired=await requestLink(page,'one@example.com');setup('expire-link');await goto(page,'/account.php?view=verify#'+expired);await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('alert').waitFor();
  // Existing two's session also expires; make a fresh session before checking disable on one.
  await login(two,'two@example.com');setup('disable-one');
  const before=fs.readFileSync(mail,'utf8');await post(page,{action:'request_login',email:'one@example.com'});assert.equal(fs.readFileSync(mail,'utf8'),before,'Disabled account mail suppressed');
  assert.equal(setup('snapshot'),baseline,'Bookings, scheduling, lifecycle and payment evidence unchanged');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Warning|Fatal|Parse)/.test(serverLog),serverLog);
  console.log('portal-http: PASS (actual HTTPS browser journey; two customers; both existing proof adapters; CSRF/origin; single use; expiry; logout replay; profile isolation; no financial/provider writes; 320/390/736/1200 layout)');

 } finally {
  if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php)php.kill();fs.rmSync(temp,{recursive:true,force:true});
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
