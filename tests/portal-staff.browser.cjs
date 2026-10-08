'use strict';
const {assertApplicationShell}=require('./application-shell.browser-checks.cjs');
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
  php=spawn('php',['-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','session.save_path='+path.join(temp,'sessions'),'-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail','-S','127.0.0.1:'+port,'-t',path.join(repo,'public'),path.join(__dirname,'fixtures/staff-http/router.php')],{env,stdio:['ignore','pipe','pipe']});php.stderr.on('data',b=>logs+=b.toString());
  proxy=https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))},(req,res)=>{const f=http.request({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});f.on('error',()=>{res.writeHead(503);res.end();});req.pipe(f);});
  await new Promise(resolve=>proxy.listen(tls,'127.0.0.1',resolve));
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  // A Pacific device still sees Central appointment dates and request times.
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1200,height:1000},timezoneId:'Pacific/Honolulu'});
  await context.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
  const page=await context.newPage();const errors=[],requestTrace=[];page.on('request',r=>{if(r.isNavigationRequest())requestTrace.push({method:r.method(),url:r.url(),origin:r.headers().origin});});page.on('pageerror',e=>errors.push(e.message));
  const goto=async(url='/staff-bookings.php')=>{for(let i=0;i<25;i++){const r=await page.goto(origin+url);if(r.status()!==503)return r;await page.waitForTimeout(100);}throw Error('Staff server unavailable '+logs);};
  const csrf=()=>page.locator('input[name=csrf]').first().inputValue();
  const post=async(data,extra={})=>page.request.post(origin+'/staff-bookings.php',{form:{csrf:await csrf(),...data},headers:{Origin:origin},maxRedirects:0,...extra});
  const first=await goto();assert.match(first.headers()['cache-control'],/no-store/);assert.match(first.headers()['content-security-policy'],/default-src 'none'/);
  await assertApplicationShell(page,'staff',false);
  assert.equal(first.headers()['referrer-policy'],'same-origin');assert.match(first.headers()['content-security-policy'],/font-src 'self'/);assert.equal(await page.getByRole('button',{name:'Sign In',exact:true}).count(),1);
  assert.equal((await page.request.post(origin+'/staff-bookings.php',{form:{action:'review_paid',reference:refs.paid}})).status(),403);
  assert.equal((await page.request.post(origin+'/staff-bookings.php',{form:{action:'lifecycle_approve_request',reference:refs.complete,request_id:'a'.repeat(32),agreed:'yes'}})).status(),403,'Anonymous requests cannot approve an appointment change');
  await page.getByLabel('Password',{exact:true}).fill('isolated-staff-password');await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.getByRole('heading',{name:'Recent Requests',exact:true}).waitFor({timeout:5000}).catch(async e=>{throw Error(e.message+' Page: '+await page.locator('body').innerText()+' Trace: '+JSON.stringify(requestTrace)+' Server: '+logs);});
  await assertApplicationShell(page,'staff',true);
  const cookies=await context.cookies();assert(cookies[0].secure&&cookies[0].httpOnly&&cookies[0].sameSite==='Strict');
  assert.match(await page.locator('.request-list').innerText(),/2026-10-02 9:00 AM Central/);
  const shots=process.env.PORTAL_SCREENSHOTS;
  const screenshot=async name=>{if(shots){fs.mkdirSync(shots,{recursive:true});await page.screenshot({path:path.join(shots,'staff-'+name+'.png'),fullPage:true});console.log('STAFF_SCREENSHOT:'+name);}};
  for(const width of [320,390,736,1200]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Request list overflow '+width);}
  await page.evaluate(()=>document.fonts.ready);assert(await page.evaluate(()=>document.fonts.check('16px Inter')&&document.fonts.check('22px Poppins')));
  await screenshot('list');
  // Keep all 19 original form contracts exact; new job and vendor forms have their own end-to-end suites.
  const contracts=()=>page.locator('form').evaluateAll(forms=>forms.filter(f=>f.id!=='job-onsite'&&!f.querySelector('input[name=action][value^=job_]')&&!f.querySelector('input[name=action][value^=vendor_]')).map(f=>({method:f.method,fields:[...f.querySelectorAll('input,select,textarea')].map(e=>({tag:e.tagName,type:e.type,name:e.name,value:e.value,required:e.required,min:e.getAttribute('min'),max:e.getAttribute('max'),maxLength:e.getAttribute('maxlength'),step:e.getAttribute('step'),checked:e.checked,options:e.tagName==='SELECT'?[...e.options].map(o=>o.value):null})),buttons:[...f.querySelectorAll('button')].map(e=>e.textContent.trim())})).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b))));
  const snapshot=setup('snapshot');
  for(const [name,ref]of Object.entries(refs)){
    const agentCase=name.startsWith('agent-');
    if(!agentCase)await goto('/staff-before.php?reference='+ref);const original=agentCase?null:await contracts();
    const response=await goto('/staff-bookings.php?reference='+ref);assert.equal(response.status(),200,name);if(!agentCase)assert.deepEqual(await contracts(),original,'All original forms, fields, amounts, fingerprints, required consent and button labels: '+name);
    assert.match(await page.locator('.facts').first().innerText(),/Central Time/);
    assert.equal(await page.locator('#request-details').getAttribute('open'),null,'Private access stays folded: '+name);
    for(const width of [320,390,736,1200]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow '+name+' '+width);}
    assert.equal(await page.locator('.booking-screen:not([hidden])').count(),1,'One screen at a time: '+name);
    assert.equal(await page.locator('[data-booking-screen="overview"]').isVisible(),true,'Bookings start with summary and next action');
    assert.equal(await page.locator('#job-onsite').isVisible(),false,'Closeout is hidden until its step is selected');
    if(await page.locator('#closeout-shortcut').count())assert.equal(await page.locator('#closeout-shortcut').getAttribute('open'),null,'Onsite shortcut starts collapsed');
    assert.equal(await page.locator('details.staff-fold[open]').evaluateAll(ds=>ds.filter(d=>d.getClientRects().length).length),0,'No recovery wall on overview');
    if(['pending-change','moved','cancelled-notice','cancelled'].includes(name))assert.equal(await page.locator('input[name=action][value=confirm_calendar],input[name=action][value=send_invitation],input[name=action][value=resume_invitation]').count(),0,'No new original actions after lifecycle work');
    const expectedStep=['paid','rush','unpaid','legacy','declined','other-recipient'].includes(name)?'review':
      ['reviewed-unlinked','submitted','draft','missing-evidence','agent-draft'].includes(name)?'readiness':
      ['reviewed','confirmed','uncertain','legacy-uncertain','agent-reviewed'].includes(name)?'calendar':'appointment';
    const nextLink=page.locator('[data-booking-screen="overview"] .step-primary');
    if(await nextLink.count())assert.match(await nextLink.getAttribute('href'),new RegExp('step='+expectedStep+'$'),'Next screen matches saved state: '+name);
    if(['paid','complete','draft','agent-complete'].includes(name))await screenshot(name+'-overview');
    if(await nextLink.count())await nextLink.click();
    const active=page.locator('.booking-screen:not([hidden])');
    if(['paid','rush'].includes(name))assert(await active.locator('form').first().isVisible());
    if(name==='legacy-uncertain')assert(await page.getByRole('button',{name:'Recheck Calendar Result',exact:true}).isVisible());
    if(name==='draft')assert(await page.getByRole('button',{name:'Repair & Send Saved Invitation',exact:true}).isVisible());
    if(agentCase){
      assert.match(await page.locator('.facts').first().innerText(),/info@1789media\.com/);
      assert.equal(await page.locator('#readiness').count(),1,'Agent readiness remains available');
      if(name==='agent-draft'){
        assert.match(await page.locator('#readiness').innerText(),/Send this saved TEST invitation to info@1789media\.com/);
        assert.doesNotMatch(await page.locator('#readiness').innerText(),/Send this saved TEST invitation to sales@/);
      }
      if(name==='agent-complete'){
        await goto('/staff-bookings.php?reference='+ref+'&step=readiness');
        await page.locator('#readiness>summary').click();
        await page.getByText('Saved Integration Status',{exact:true}).click();
        assert.match(await page.locator('#readiness').innerText(),/Unverified — confirm receipt directly with info@1789media\.com/);
        assert.equal(await page.locator('input[name=action][value=resume_invitation],input[name=action][value=send_invitation]').count(),0,'No send action for sent agent invitation');
        await page.setViewportSize({width:390,height:1000});await screenshot('agent-complete-mobile');
      }
    }
    if(['paid','complete','draft'].includes(name))await screenshot(name);
    if(name==='rush'){await page.setViewportSize({width:390,height:1000});await screenshot('rush-mobile');}
  }
  assert.equal(setup('snapshot'),snapshot,'Opening every old/new page and status leaves recorded data unchanged');
  assert(!fs.existsSync(path.join(current,'provider-blocked.txt')),'No provider calls on any GET');
  // Keyboard access to folded controls and no action until explicit submission.
  await goto('/staff-bookings.php?reference='+refs.complete+'&step=appointment');const management=page.locator('#manage-appointment>summary');await management.focus();await page.keyboard.press('Enter');assert(await page.getByRole('button',{name:'Reconcile Calendar',exact:true}).isVisible());
  await page.getByText('Cancel Appointment',{exact:true}).click();assert.equal(await page.locator('form').filter({has:page.locator('input[name=action][value=lifecycle_cancel]')}).locator('input[name=agreed]').isChecked(),false);
  assert.equal(setup('snapshot'),snapshot,'Expanding management changes no records');
  // All secondary screen choices are read-only; no hidden auto actions.
  for(const step of ['overview','readiness','calendar','appointment','onsite','details','unknown']){
    await goto('/staff-bookings.php?reference='+refs.complete+'&step='+step);
    assert.equal(await page.locator('.booking-screen:not([hidden])').count(),1,'One active screen '+step);
    if(step==='onsite'){
      assert.equal(await page.locator('#onsite-closeout').getAttribute('open'),null,'Onsite form starts collapsed even in its step');
      assert.equal(await page.locator('#job-onsite').isVisible(),false);
      await page.locator('#onsite-closeout>summary').click();
      assert.equal(await page.locator('#job-onsite').isVisible(),true);
    }
  }
  assert.equal(setup('snapshot'),snapshot,'Moving between steps or opening closeout changes no ledger');
  // Actual production alternative forms: blank picker, one chosen form, fresh consent per choice.
  const windowResponse=await goto('/staff-window-fixture.php');assert.equal(windowResponse.status(),200,'Window fixture '+await windowResponse.text());
  const picker=page.getByLabel('Customer-agreed arrival window',{exact:true});await picker.waitFor();
  assert.equal(await picker.inputValue(),'','No alternative window is defaulted');
  assert.equal(await page.locator('form[data-window-option]:visible').count(),0,'No consent form before explicit selection');
  const choiceValues=await picker.locator('option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean));
  await picker.selectOption(choiceValues[1]);
  let choiceForm=page.locator('form[data-window-option]:visible');
  assert.equal(await choiceForm.count(),1,'Only the chosen window has a visible confirmation');
  assert.equal(await choiceForm.locator('input[name=time]').inputValue(),'09:00','9–11 selection binds 9–11 POST, not first7–9');
  const consent=choiceForm.locator('input[name=agreed]');assert.equal(await consent.isChecked(),false);await consent.check();
  await picker.selectOption(choiceValues[0]);choiceForm=page.locator('form[data-window-option]:visible');
  assert.equal(await choiceForm.locator('input[name=time]').inputValue(),'07:00');
  assert.equal(await choiceForm.locator('input[name=agreed]').isChecked(),false,'Changing window clears earlier consent');
  await picker.selectOption(choiceValues[1]);
  assert.equal(await page.locator('form[data-window-option]:visible input[name=agreed]').isChecked(),false,'Returning to a former choice does not restore consent');
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  assert.equal(await picker.inputValue(),'','Browser page restoration resets the picker');
  assert.equal(await page.locator('form[data-window-option]:visible').count(),0,'Restoration cannot show a checked unrelated window');
  assert.equal(setup('snapshot'),snapshot,'Picker/consent changes perform no calendar/mail/database operation');
  // A rejected review keeps its correction form visible; successful review advances.
  await goto('/staff-bookings.php?reference='+refs.paid+'&step=review');
  const reviewForm=page.locator('form').filter({has:page.locator('input[name=action][value=review_paid]')});
  await reviewForm.evaluate(f=>f.submit());await page.getByRole('alert').waitFor();
  assert.equal(await page.locator('[data-booking-screen=review]').isVisible(),true,'Failed review stays on the review step');
  assert.equal(await page.getByRole('button',{name:'Save Test Review — No Invitation',exact:true}).isVisible(),true,'The form needing correction stays visible');
  assert.equal(setup('snapshot'),snapshot,'Rejected review preserves ledger');
  // Configuration failure must expose the blocked unsent invitation, not hide the reason.
  for(const root of [current,before]){const f=path.join(root,'booking-mail.json'),c=JSON.parse(fs.readFileSync(f));c.enabled=false;fs.writeFileSync(f,JSON.stringify(c));}
  await goto('/staff-bookings.php?reference='+refs.confirmed+'&step=readiness');assert.notEqual(await page.locator('#readiness').getAttribute('open'),null);assert.match(await page.locator('#readiness').innerText(),/prerequisite needs attention/);assert(await page.getByRole('button',{name:'Check Booking Readiness',exact:true}).isVisible());assert.equal(await page.locator('input[name=action][value=send_invitation]').count(),0);
  // Rejected POSTs stay rejected and keep their working section open with visible feedback.
  await goto('/staff-bookings.php?reference='+refs.reviewed);assert.equal((await post({action:'confirm_calendar',reference:refs.reviewed},{headers:{Origin:'https://untrusted.example'}})).status(),403);
  assert.equal((await post({action:'confirm_calendar',reference:refs.reviewed},{headers:{Origin:'null'}})).status(),403,'Opaque origin still rejected');
  const rejected=await post({action:'confirm_calendar',reference:refs.reviewed});assert.equal(rejected.status(),200);await page.goto(origin+'/staff-bookings.php?reference='+refs.reviewed+'&step=calendar');
  // Use a real browser POST so the returned error presentation can be inspected.
  await page.locator('form').filter({has:page.locator('input[name=action][value=confirm_calendar]')}).evaluate(f=>f.submit());
  await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/Confirm the customer-agreed/);assert.notEqual(await page.locator('#calendar-confirmation').getAttribute('open'),null);
  assert.equal(setup('snapshot'),snapshot,'Rejected consent/origin requests leave all bookings unchanged');
  assert(!fs.existsSync(path.join(current,'provider-blocked.txt')),'Rejected actions call no providers');
  const requestId=setup('seed-change-request');await goto('/staff-bookings.php?reference='+refs.complete);
  assert.match(await page.locator('[data-booking-screen=overview]').innerText(),/Review the customer’s requested window/);
  await page.locator('[data-booking-screen=overview] .step-primary').click();
  assert.notEqual(await page.locator('#manage-appointment').getAttribute('open'),null,'Customer requests open the manager review controls');
  assert(await page.getByRole('button',{name:'Approve Requested Window',exact:true}).isVisible());
  assert.match(await page.locator('#manage-appointment').innerText(),/Requested window:.*13:00–15:00/);
  const pendingSnapshot=setup('snapshot');
  assert.equal((await post({action:'lifecycle_approve_request',reference:refs.complete,request_id:requestId,agreed:'yes'},{headers:{Origin:'https://untrusted.example'}})).status(),403,'Manager approval retains same-origin protection');
  const approvalForm=page.locator('form').filter({has:page.locator('input[name=action][value=lifecycle_approve_request]')});
  assert.equal(await approvalForm.locator('input[name=agreed]').isChecked(),false);
  await approvalForm.evaluate(f=>f.submit());await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/Review and approve/);
  assert.equal(setup('snapshot'),pendingSnapshot,'Rejected approval never applies the calendar request');
  for(const width of [320,390,736,1200]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Request approval overflow '+width);}
  const decline=page.locator('form').filter({has:page.locator('input[name=action][value=lifecycle_reject_request]')});
  await decline.locator('input[name=agreed]').check();await decline.getByRole('button',{name:'Decline Requested Window',exact:true}).click();await page.locator('.note[role=status]').waitFor();
  assert.match(await page.locator('.note[role=status]').innerText(),/Requested window declined/);
  assert.equal(await page.getByRole('button',{name:'Approve Requested Window',exact:true}).count(),0);
  assert(!fs.existsSync(path.join(current,'provider-blocked.txt')),'Request review and decline do not contact providers');
  assert.deepEqual(errors,[]);assert(!/PHP (?:Fatal error|Warning|Notice)/.test(logs),logs);
  console.log('portal-staff: PASS (19 baseline form contracts + 3 external-agent cases; unchanged ledgers; no GET/provider calls; real HTTPS login/CSRF/origin/consent; state-selected screens; collapsed closeout; recovery; Central Time; keyboard; 320/390/736/1200 layout)');
 }finally{if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));if(php){php.kill();await new Promise(r=>php.once('exit',r));}fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
