'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Existing HTTPS journeys call this before and after their real sign-in flow.
async function assertApplicationShell(page, role, authenticated) {
  const navigation = {
    customer: ['/account.php', '/account.php?view=profile', '/account.php?view=new'],
    vendor: ['/vendor.php'],
    staff: ['/staff-bookings.php', '/staff-production.php', '/staff-vendors.php']
  };
  assert.equal(await page.locator('body.site-shell.role-' + role).count(), 1);
  const nav = page.locator('body.site-shell > header > nav');
  assert.equal(await nav.count(), authenticated ? 1 : 0, role + ' navigation authentication');
  if (authenticated) assert.deepEqual(await nav.locator('a').evaluateAll(links => links.map(a => a.getAttribute('href'))), navigation[role]);
  assert.equal(await page.locator('a.skip').getAttribute('href'), '#content');
  assert.equal(await page.locator('#content').count(), 1);
  const css = '/portal-assets/application.css?v=unified-shell-r1';
  assert.equal(await page.locator('head link[rel=stylesheet][href="' + css + '"]').count(), 1);
  assert.equal((await page.request.get(new URL(css, page.url()).href)).status(), 200, 'shared stylesheet is served');
  await page.waitForFunction(href => [...document.styleSheets].some(sheet => {
    if (sheet.href !== href || sheet.disabled) return false;
    try { return sheet.cssRules.length > 0; } catch { return false; }
  }), new URL(css, page.url()).href, { timeout: 5000 });
  await page.waitForFunction(() => getComputedStyle(document.querySelector('body.site-shell > header')).display === 'flex', null, { timeout: 5000 });
  assert.equal(await page.locator('body.site-shell > header .brand').evaluate(brand => getComputedStyle(brand).fontSize), '30px', 'shared brand size is applied');
  assert.match(await page.locator('body.site-shell > .test-notice').innerText(), /payments remain in test mode/);
  await page.evaluate(() => document.fonts.ready);
  assert(await page.evaluate(() => document.fonts.check('16px Inter') && document.fonts.check('22px Poppins')), role + ' brand fonts');
  if (process.env.APPLICATION_SHELL_SHOTS) {
    fs.mkdirSync(process.env.APPLICATION_SHELL_SHOTS, {recursive:true});
    await page.screenshot({path:path.join(process.env.APPLICATION_SHELL_SHOTS, role + '-' + (authenticated ? 'authenticated' : 'anonymous') + '.png'), fullPage:true});
  }
  console.log('application-shell-browser: PASS (' + role + '; ' + (authenticated ? 'authenticated' : 'anonymous') + '; role navigation, shared CSS, skip target, TEST label, brand fonts)');
}
module.exports = { assertApplicationShell };
