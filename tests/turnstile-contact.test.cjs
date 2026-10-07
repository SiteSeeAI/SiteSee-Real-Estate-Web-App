const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const contactHtml = read('public/contact.html');
const pricingHtml = read('public/pricing-request.html');
const siteJs = read('public/assets/js/site.js');
const pricingJs = read('public/assets/js/pricing-access.js');
const turnstileJs = read('public/assets/js/turnstile.js');
const config = read('_private/real-estate-form-config.php');
const contactHandler = read('_private/server/contact-submit.php');
const contactEntrypoint = read('public/contact-submit.php');
const pricingHandler = read('_private/server/pricing-request.php');

test('contact and pricing access forms require Cloudflare Turnstile', () => {
  assert.match(contactHtml, /action="contact-submit\.php"[^>]*method="post"/);
  assert.match(contactHtml, /data-turnstile data-action="real_estate_contact"/);
  assert.match(contactHtml, /<button class="button primary" type="submit" disabled>/);
  assert.match(contactHtml, /assets\/js\/turnstile\.js/);

  assert.match(pricingHtml, /action="pricing-request\.php"[^>]*method="post"/);
  assert.match(pricingHtml, /data-turnstile data-action="real_estate_pricing"/);
  assert.match(pricingHtml, /<button class="button primary" type="submit" disabled>/);
  assert.match(pricingHtml, /assets\/js\/turnstile\.js/);
});

test('Turnstile follows the Corporate explicit-rendering pattern', () => {
  assert.match(turnstileJs, /challenges\.cloudflare\.com\/turnstile\/v0\/api\.js\?render=explicit/);
  assert.match(turnstileJs, /__SITESEE_AUDIT_SITEKEY__/);
  assert.equal(turnstileJs.includes('turnstile-config.php'), false);
  assert.match(turnstileJs, /api\.render/);
  assert.match(turnstileJs, /'response-field': false/);
  assert.match(turnstileJs, /token\.name = 'cf-turnstile-response'/);
  assert.match(turnstileJs, /'expired-callback'/);
  assert.match(turnstileJs, /state\.api\.reset\(state\.widgetId\)/);
});

test('both submission endpoints enforce independent Turnstile actions', () => {
  assert.match(pricingHandler, /real_estate_verify_turnstile\([\s\S]*'real_estate_pricing'/);
  assert.match(contactHandler, /real_estate_verify_turnstile\([\s\S]*'real_estate_contact'/);
  assert.match(config, /https:\/\/challenges\.cloudflare\.com\/turnstile\/v0\/siteverify/);
  assert.match(config, /\/home\/sitesee\/\.sitesee-audit-guard\/config\.json/);
  assert.match(config, /re\.sitesee\.ai/);
  assert.match(config, /real_estate_form_delivery_allowed/);
  assert.match(config, /delivery-ip/);
  assert.match(config, /delivery-email/);
  assert.match(config, /hostname-mismatch/);
  assert.match(config, /action-mismatch/);
  assert.equal(config.includes("getenv('SITESEE_TURNSTILE"), false);
});

test('contact form posts to the server instead of displaying a design preview', () => {
  assert.match(siteJs, /\.inquiry-form\[data-form-kind="contact"\]/);
  assert.match(siteJs, /fetch\(form\.action/);
  assert.match(siteJs, /body: new FormData\(form\)/);
  assert.equal(contactHtml.includes('Design preview'), false);
  assert.equal(contactHtml.includes('has not sent or stored'), false);
  assert.match(contactHandler, /real_estate_same_origin/);
  assert.match(contactHandler, /real_estate_form_delivery_allowed/);
  assert.match(contactHandler, /real_estate_send_mail/);
  assert.match(contactHandler, /company_fax/);
});

test('public contact entrypoint does not expose delivery implementation', () => {
  assert.match(contactEntrypoint, /require __DIR__ \. '\/application-entry\.php';/);
  assert.match(contactEntrypoint, /require site_application_route_target\('contact-submit\.php'\);/);
  assert.match(read('_private/server/application.php'), /'contact-submit\.php' => \['handler'=>'server\/contact-submit\.php'/);
  assert.match(read('public/application-entry.php'), /\/home\/sitesee\/\.sitesee-real-estate/);
  for (const marker of ['real_estate_send_mail', 'real_estate_turnstile_config', 'salesHtml', '<form']) {
    assert.equal(contactEntrypoint.includes(marker), false, marker);
  }
});

test('client scripts reset single-use challenges after submission errors', () => {
  assert.match(siteJs, /if \(security\) security\.reset\(\)/);
  assert.match(pricingJs, /if \(security\) security\.reset\(\)/);
});
