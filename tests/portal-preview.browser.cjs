/* Optional browser check: NODE_PATH=<Playwright modules> node tests/portal-preview.browser.cjs */
const assert = require('node:assert/strict');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {chromium} = require('playwright');
(async () => {
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const page=await browser.newPage({viewport:{width:1024,height:1000}});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(pathToFileURL(path.resolve(__dirname,'../documents/portal/preview.html')).href);
    await page.getByRole('heading',{name:'My Orders',exact:true}).waitFor();
    if(process.env.PORTAL_SCREENSHOTS)await page.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'portal-orders.png'),fullPage:true});
    await page.getByRole('button',{name:'＋ New Order'}).click();
    await page.getByRole('button',{name:'Next →'}).click();
    await page.getByLabel('Street address').fill('<script>example</script>');
    await page.getByLabel('City',{exact:true}).fill('Chicago');
    await page.getByLabel('ZIP code').fill('60601');
    await page.getByRole('button',{name:'Next →'}).click();
    await page.getByRole('radio',{name:'Gold $499.00 · 35 HDR photos'}).check();
    assert.equal(await page.locator('.sp-total strong').textContent(),'$499.00');
    await page.getByRole('button',{name:'Back',exact:true}).click();
    assert.equal(await page.getByLabel('Street address').inputValue(),'<script>example</script>');
    await page.getByRole('button',{name:'Next →'}).click();
    assert(await page.getByRole('radio',{name:'Gold $499.00 · 35 HDR photos'}).isChecked());
    await page.getByRole('button',{name:'Next →'}).click();
    await page.getByLabel('Preferred date',{exact:true}).fill('2026-10-08');
    await page.getByLabel('Phone',{exact:true}).fill('3125550100');
    await page.getByRole('checkbox',{name:'I agree to the cancellation policy.'}).check();
    await page.getByRole('button',{name:'Next →'}).click();
    await page.getByRole('heading',{name:'Review your order'}).waitFor();
    assert((await page.locator('#sp-screen').textContent()).includes('<script>example</script>'));
    assert.equal(await page.locator('#sp-screen script').count(),0,'User text remains escaped');
    await page.getByRole('button',{name:'Continue to test deposit'}).click();
    assert.equal(await page.locator('.sp-total strong').textContent(),'$249.50');
    await page.getByRole('button',{name:'Back',exact:true}).click();
    await page.getByRole('button',{name:'Edit services'}).click();
    await page.getByRole('button',{name:'Back',exact:true}).click();
    await page.getByRole('button',{name:'Back',exact:true}).click();
    await page.getByRole('radio',{name:/^Commercial/}).check();
    await page.getByRole('button',{name:'Next →'}).click();
    await page.getByRole('button',{name:'Next →'}).click();
    assert.equal(await page.locator('.sp-total strong').textContent(),'$750.00');
    await page.locator('[data-service="views360"]').check();
    await page.getByRole('button',{name:'Next →'}).click();
    assert.match(await page.locator('#sp-error').textContent(),/require a SiteSee platform/);
    await page.locator('[data-service="platform"]').check();
    await page.locator('[data-service="mp"]').check();
    assert.equal(await page.locator('.sp-total strong').textContent(),'$1,569.00');
    if(process.env.PORTAL_SCREENSHOTS)await page.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'portal-services.png'),fullPage:true});
    for(const width of [320,390,736,1024]) {
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`No horizontal overflow at ${width}`);
    }
    await page.getByRole('button',{name:'My Orders',exact:true}).first().click();
    await page.setViewportSize({width:390,height:844});
    if(process.env.PORTAL_SCREENSHOTS)await page.screenshot({path:path.join(process.env.PORTAL_SCREENSHOTS,'portal-mobile.png'),fullPage:true});
    assert.deepEqual(errors,[]);
    console.log('portal-preview: PASS (navigation, preserved answers, canonical prices, dependencies, escaped input, responsive widths)');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
