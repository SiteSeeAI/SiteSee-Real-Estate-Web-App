'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');const os=require('node:os');const path=require('node:path');
const http=require('node:http');const https=require('node:https');const net=require('node:net');
const {spawn,execFileSync}=require('node:child_process');const {chromium}=require('playwright');
const repo=path.resolve(__dirname,'..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sitesee-portal-http-'));
const privateRoot=path.join(temp,'private');fs.cpSync(path.join(repo,'_private'),privateRoot,{recursive:true});
// Reuse code only; previous unit-test session directories are not fixture state.
fs.rmSync(path.join(privateRoot,'data'),{recursive:true,force:true});
const accountEntry=path.join(temp,'account.php');
fs.writeFileSync(accountEntry,fs.readFileSync(path.join(repo,'public/account.php'),'utf8').replaceAll('/home/sitesee/.sitesee-real-estate',privateRoot));
const releaseFlag=path.join(privateRoot,'portal-test.json');
fs.writeFileSync(releaseFlag,JSON.stringify({release:'portal-20260929-r2',stage:'TEST',enabled:true}),{mode:0o600});
// Replace provider boundaries only in the throwaway copy; production has no test override.
for(const [file,name] of [['portal-billing.php','portal_stripe'],['booking-lifecycle.php','booking_lifecycle_connection'],['portal-sms.php','portal_sms_send'],['portal-sms.php','portal_sms_check'],['portal-mail.php','portal_send_email_code']]){
 const target=path.join(privateRoot,'server',file);fs.writeFileSync(target,fs.readFileSync(target,'utf8').replace('function '+name+'(', 'function '+name+'_unused_fixture('));
}
const emailCapture=path.join(privateRoot,'data/email-fixture.json');
fs.writeFileSync(path.join(temp,'email-provider.php'),`<?php
function portal_send_email_code(string $email,string $code):bool {file_put_contents(getenv('PORTAL_TEST_PRIVATE').'/data/email-fixture.json',json_encode(['email'=>$email,'code'=>$code]));return true;}
`);
fs.writeFileSync(accountEntry,fs.readFileSync(accountEntry,'utf8').replace("declare(strict_types=1);","declare(strict_types=1); require "+JSON.stringify(path.join(temp,'email-provider.php'))+";"));
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
  php=spawn('php',['-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','sendmail_path='+sendmail,'-d','allow_url_fopen=0','-d','disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect','-S','127.0.0.1:'+phpPort,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/portal-http/router.php')],{env,stdio:['ignore','pipe','pipe']});
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
  await goto(page);
  const anon=await post(page,{action:'email_request',email:'new@example.com'});assert.match(await anon.text(),/Please sign in/);assert(!fs.existsSync(emailCapture));
  await login(page,'one@example.com');await claim(page,'AAAAAAAAAA.'+'1'.repeat(64));
  const baselineAfterClaim=setup('snapshot');
  await goto(page,'/account.php?view=profile');await page.getByRole('link',{name:'Change Email',exact:true}).click();
  await page.getByRole('heading',{name:'Change Email',exact:true}).waitFor();assert.equal(await page.locator('main button').count(),1);
  const bad=await page.request.post(origin+'/account.php',{form:{action:'email_request',email:'new@example.com',csrf:'wrong'},headers:{Origin:origin}});assert.equal(bad.status(),403);assert(!fs.existsSync(emailCapture));
  const foreign=await page.request.post(origin+'/account.php',{form:{action:'email_request',email:'new@example.com',csrf:await csrf(page)},headers:{Origin:'https://other.example'}});assert.equal(foreign.status(),403);assert(!fs.existsSync(emailCapture));
  await page.getByLabel('New Email Address',{exact:true}).fill('verified@example.com');await page.getByRole('button',{name:'Send Verification Code'}).click();
  await page.getByLabel('Verification Code',{exact:true}).waitFor();let sent=JSON.parse(fs.readFileSync(emailCapture,'utf8'));assert.equal(sent.email,'verified@example.com');
  const html=await page.content();assert(!html.includes(sent.code),'Code absent from HTML');assert(!/[a-f0-9]{64}/.test(html.replaceAll(await csrf(page),'')),'Challenge capability absent from HTML');
  assert.match(await page.locator('main').innerText(),/one@example.com/);
  const beforeGet=setup('snapshot');await goto(page,'/account.php?view=email&action=email_confirm&code='+sent.code);assert.equal(setup('snapshot'),beforeGet,'GET does not activate');
  await login(two,'two@example.com');await goto(two,'/account.php?view=email');assert.equal(await two.getByLabel('Verification Code',{exact:true}).count(),0);
  await post(two,{action:'email_confirm',code:sent.code,account_id:'a'.repeat(32)});await goto(two,'/account.php?view=profile');assert.match(await two.locator('main').innerText(),/two@example.com/);
  const sameContext=await browser.newContext({ignoreHTTPSErrors:true});await sameContext.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());const same=await sameContext.newPage();await login(same,'one@example.com');await goto(same,'/account.php?view=email');
  const crossSession=await post(same,{action:'email_confirm',code:sent.code});assert.match(await crossSession.text(),/could not be verified/);await sameContext.close();
  await goto(page,'/account.php?view=email');const wrong=sent.code==='00000000'?'11111111':'00000000';await page.getByLabel('Verification Code',{exact:true}).fill(wrong);await page.getByRole('button',{name:'Verify & Change Email'}).click();await page.getByRole('alert').waitFor();
  const shots=process.env.PORTAL_SCREENSHOTS;if(shots)fs.mkdirSync(shots,{recursive:true});
  for(const width of [320,390,736,1200]){await page.setViewportSize({width,height:950});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No email overflow '+width);}
  if(shots){await page.screenshot({path:path.join(shots,'email-code-desktop.png'),fullPage:true});console.log('EMAIL_VISUAL_code-desktop:'+(await page.screenshot({type:'jpeg',quality:60,fullPage:true})).toString('base64'));}
  await page.setViewportSize({width:390,height:844});if(shots){await page.screenshot({path:path.join(shots,'email-code-mobile.png'),fullPage:true});console.log('EMAIL_VISUAL_code-mobile:'+(await page.screenshot({type:'jpeg',quality:60,fullPage:true})).toString('base64'));}
  await page.getByLabel('Verification Code',{exact:true}).fill(sent.code);await page.getByRole('button',{name:'Verify & Change Email'}).press('Enter');await page.getByRole('heading',{name:'Account',exact:true}).waitFor();
  assert.match(await page.getByRole('status').innerText(),/verified and updated/);assert.match(await page.locator('main').innerText(),/verified@example.com/);
  const replay=await post(page,{action:'email_confirm',code:sent.code});assert.match(await replay.text(),/could not be verified/);
  await goto(page);assert.match(await page.locator('main').innerText(),/101 Example Lane/);assert.equal(setup('snapshot'),baselineAfterClaim,'Existing booking/calendar/payment rows untouched');
  await goto(page,'/account.php?view=new');assert.match(await page.content(),/verified@example.com/,'New-order email uses verified address');
  await goto(page,'/account.php?view=profile');await page.getByRole('button',{name:'Sign Out',exact:true}).click();await login(page,'one@example.com');await goto(page,'/account.php?view=profile');assert.match(await page.locator('main').innerText(),/verified@example.com/,'Original phone still signs in');
  await page.getByRole('link',{name:'Change Email',exact:true}).click();assert.equal(await page.getByLabel('New Email Address',{exact:true}).count(),1);
  if(shots)await page.screenshot({path:path.join(shots,'email-request-mobile.png'),fullPage:true});
  assert.equal(errors.length,0,JSON.stringify(errors));assert(!/Fatal error|Warning:|Notice:/.test(serverLog),serverLog);assert(!fs.existsSync(mail),'No native mail called');
  console.log('portal-email-browser: PASS (native HTTPS forms; CSRF/origin; phone login; account/session isolation; GET/replay; responsive code form; retained orders; new-order email; no external calls)');
 }finally{if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php){php.kill();await new Promise(r=>php.once('exit',r));}fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
