/* Isolated native-input checks using production templates/scripts; no providers. */
const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path'), http=require('node:http');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..'), read=p=>fs.readFileSync(path.join(root,p),'utf8');
let serverNow=Date.parse('2026-10-01T14:30:00Z')/1000;
function pricing() {
  let html=read('_private/views/pricing.php').split('<!doctype html>')[1];
  html=html.replace(/<\?php \$schedulePrefix = '([^']*)'; require __DIR__ \. '\/scheduling-fields.php'; \?>/g,(_,prefix)=>read('_private/views/scheduling-fields.php').replace(/<\?php[\s\S]*?\?>/,'').replaceAll('<?= $p ?>',prefix));
  return '<!doctype html>'+html.replaceAll('<?= time() ?>',String(serverNow)).replaceAll('<?= $calendarEnabled ? 1 : 0 ?>','0').replaceAll('<?= $calendarCsrf ?>','test').replace(/<\?[\s\S]*?\?>/g,'');
}
function portal() {
  const config={serverNow,csrf:'synthetic',details:{first:'Test',last:'Customer',company:'Example',phone:'3125550100',email:'sales@re.sitesee.ai'},seed:null};
  return '<!doctype html><div id="portal-wizard" data-config="'+JSON.stringify(config).replaceAll('"','&quot;')+'"><div id="sp-screen"></div></div>'+
    ['_private/pricing-assets/quote-engine.js','_private/pricing-assets/commercial-quote-engine.js','public/portal-assets/booking-notice.js','public/portal-assets/order.js'].map(p=>'<script src="/fixture/'+p+'"></script>').join('');
}
const assets={'quote-engine':'quote-engine.js','scheduling':'scheduling.js','availability':'availability.js','residential-form':'pricing.js','commercial-quote-engine':'commercial-quote-engine.js','commercial-form':'commercial-pricing.js','market-selector':'pricing-market.js'};
const server=http.createServer((req,res)=>{
  const u=new URL(req.url,'http://localhost');
  res.setHeader('Cache-Control','no-store');
  if(u.pathname==='/booking-clock.php'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({now:serverNow,timezone:'America/Chicago'}));return;}
  if(u.pathname==='/quote'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(pricing());return;}
  if(u.pathname==='/portal'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(portal());return;}
  let file;
  if(u.pathname==='/pricing-asset.php')file='_private/pricing-assets/'+assets[u.searchParams.get('asset')];
  if(u.pathname==='/portal-assets/booking-notice.js')file='public'+u.pathname;
  if(u.pathname.startsWith('/fixture/'))file=u.pathname.slice('/fixture/'.length);
  if(file && !file.includes('..') && fs.existsSync(path.join(root,file))){res.setHeader('Content-Type','application/javascript');res.end(read(file));return;}
  res.end('');
});
(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    for(const timezoneId of ['Asia/Tokyo','Pacific/Honolulu']) {
      const context=await browser.newContext({timezoneId});
      await context.addInitScript(()=>{const Original=Date;window.Date=class extends Original {constructor(...args){super(...(args.length?args:['2099-01-01T00:00:00Z']));}static now(){return Original.parse('2099-01-01T00:00:00Z');}};});
      const page=await context.newPage(),errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('Page error:',e.message);});
      await context.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
      serverNow=Date.parse('2026-10-01T14:30:00Z')/1000;await page.goto(origin+'/quote');
      for(const [market,prefix] of [['residential',''],['commercial','c-']]) {
        await page.locator('[name="property-type"][value="'+market+'"]').check();
        await page.locator('#'+prefix+'listing-street').fill('101 Example Street');
        await page.locator('#'+prefix+'listing-city').fill('Chicago');
        await page.locator('#'+prefix+'listing-state').selectOption('IL');
        await page.locator('#'+prefix+'listing-zip').fill('60601');
        await page.locator('#'+prefix+'open-estimate').click();
        await page.locator('#'+prefix+'review-estimate').click();
        const date=page.locator('#'+prefix+'shoot-date'),time=page.locator('#'+prefix+'shoot-time');
        await page.waitForFunction(id=>document.getElementById(id).min==='2026-10-04',prefix+'shoot-date');
        await date.fill('2026-10-03');assert(await date.evaluate(el=>el.validity.rangeUnderflow));
        await date.fill('2026-10-04');assert(!(await date.evaluate(el=>el.validity.rangeUnderflow)));
        assert.deepEqual(await time.locator('option:disabled').evaluateAll(os=>os.map(o=>o.value)),['07:00','09:00']);
        await time.selectOption('11:00');await page.locator('#'+prefix+'rush-requested').check();
        assert.equal(await date.getAttribute('min'),'2026-10-02');
        await date.fill('2026-10-02');await page.locator('#'+prefix+'rush-requested').uncheck();
        assert.equal(await date.getAttribute('min'),'2026-10-04');assert(await date.evaluate(el=>el.validity.rangeUnderflow));
      }
      // Server moves ahead while the device wall clock remains wrong. Refocus refreshes both markets.
      serverNow=Date.parse('2026-10-01T22:00:01Z')/1000;
      await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
      for(const id of ['shoot-date','c-shoot-date'])await page.waitForFunction(id=>document.getElementById(id).min==='2026-10-05'&&!document.getElementById(id).disabled,id);
      for(const market of ['residential','commercial']) {
        serverNow=Date.parse('2026-10-01T14:30:00Z')/1000;await page.goto(origin+'/portal');
        await page.locator(`[data-key=market][value=${market}]`).check();await page.getByRole('button',{name:'Next →'}).click();
        await page.getByLabel('Street address',{exact:true}).fill('101 Example Street');await page.getByLabel('City',{exact:true}).fill('Chicago');await page.getByLabel('ZIP code',{exact:true}).fill('60601');
        await page.getByRole('button',{name:'Next →'}).click();await page.getByRole('button',{name:'Next →'}).click();
        const date=page.getByLabel('Preferred date',{exact:true}),time=page.locator('[data-key=time]');
        assert.equal(await date.getAttribute('min'),'2026-10-04');
        await date.fill('2026-10-04');assert.deepEqual(await time.locator('option:disabled').evaluateAll(os=>os.map(o=>o.value)),['07:00','09:00']);
        await page.getByRole('button',{name:'Next →'}).click();assert.match(await page.locator('#sp-error').textContent(),/72.*hours/);
        await time.selectOption('11:00');await page.locator('[data-key=rushRequested]').check();assert.equal(await date.getAttribute('min'),'2026-10-02');
        await date.fill('2026-10-02');await page.locator('[data-key=rushRequested]').uncheck();assert(await date.evaluate(el=>el.validity.rangeUnderflow));
        await page.getByRole('button',{name:'Next →'}).click();assert.match(await page.locator('#sp-error').textContent(),/72.*hours/);
        serverNow=Date.parse('2026-10-01T22:00:01Z')/1000;await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
        await page.waitForFunction(()=>document.querySelector('[data-key=date]').min==='2026-10-05'&&!document.querySelector('[data-key=date]').disabled);
        // No full rerender, pricing change, or lost draft on clock refresh.
        assert.equal(await date.inputValue(),'2026-10-02');
      }
      assert.deepEqual(errors,[]);await context.close();
    }
    console.log('Calendar notice browser: PASS (both quote markets and portal markets; native date validity; partial days; rush; stale selections; wrong device clock; Tokyo/Honolulu; server refresh).');
  } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
