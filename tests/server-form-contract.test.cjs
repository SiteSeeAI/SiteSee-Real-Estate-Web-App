const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const accessHtml = read('public/pricing-request.html');
const html = read('_private/views/pricing.php');
const residential = read('_private/pricing-assets/pricing.js');
const commercial = read('_private/pricing-assets/commercial-pricing.js');
const handler = read('_private/server/quote-submit.php');
const accessHandler = read('_private/server/pricing-request.php');
const approvalHandler = read('_private/server/pricing-approve.php');
const confirmationHandler = read('_private/server/pricing-confirm.php');
const protectedAssets = read('_private/server/pricing-asset.php');
const gateConfig = read('_private/real-estate-form-config.php');
const serverPricing = read('_private/real-estate-pricing.php');
const publicEntrypoints = {
  'public/contact-submit.php': '_private/server/contact-submit.php',
  'public/pricing-approve.php': '_private/server/pricing-approve.php',
  'public/pricing-asset.php': '_private/server/pricing-asset.php',
  'public/pricing-confirm.php': '_private/server/pricing-confirm.php',
  'public/pricing-logout.php': '_private/server/pricing-logout.php',
  'public/pricing-request.php': '_private/server/pricing-request.php',
  'public/pricing.php': '_private/views/pricing.php',
  'public/quote-submit.php': '_private/server/quote-submit.php',
};

test('the public pricing-request route replaces pricing.html and does not expose calculators', () => {
  assert.equal(fs.existsSync(path.join(root, 'public/pricing.html')), false);
  assert.match(accessHtml, /id="pricing-access-form"[^>]*action="pricing-request\.php"[^>]*method="post"/);
  for (const name of ['first_name', 'last_name', 'website', 'email', 'phone', 'company_fax']) {
    assert.match(accessHtml, new RegExp('name="' + name + '"'));
  }
  assert.equal(accessHtml.includes('id="residential-quote"'), false);
  assert.equal(accessHtml.includes('id="commercial-quote"'), false);
});

test('public PHP routes are minimal entrypoints into private implementations', () => {
  const loader = read('public/application-entry.php');
  const registry = read('_private/server/application.php');
  assert.ok(loader.includes('/home/sitesee/.sitesee-real-estate'), 'private root is preserved');
  assert.ok(loader.includes("'/server/application.php'"), 'loader requires private registry');
  for (const [publicPath, privatePath] of Object.entries(publicEntrypoints)) {
    const entrypoint = read(publicPath);
    assert.equal(fs.existsSync(path.join(root, privatePath)), true, privatePath);
    const route = path.basename(publicPath);
    const handler = privatePath.replace(/^_private\//, '');
    assert.ok(entrypoint.includes("require __DIR__ . '/application-entry.php';"), publicPath);
    assert.ok(entrypoint.includes("require site_application_route_target('" + route + "');"), publicPath);
    assert.ok(registry.includes("'" + route + "' => ['handler'=>'" + handler + "',"), publicPath + ' retains private handler');
    for (const privateMarker of ['real_estate_send_mail', 'real_estate_prepare_submission', 'readfile(', '<form']) {
      assert.equal(entrypoint.includes(privateMarker), false, publicPath + ' exposes ' + privateMarker);
    }
  }
});

test('both calculators retain their layout and submit only after verified access', () => {
  assert.match(html, /real_estate_pricing_has_access\(\)/);
  assert.match(html, /id="residential-quote"[^>]*action="quote-submit\.php"[^>]*method="post"/);
  assert.match(html, /id="commercial-quote"[^>]*action="quote-submit\.php"[^>]*method="post"/);
  assert.equal((html.match(/name="company_fax"/g) || []).length, 2);
  assert.equal(html.includes('Email actions open a prepared message'), false);
  assert.match(handler, /real_estate_pricing_has_access\(\)/);
});

test('residential and commercial actions post server-recalculable state', () => {
  for (const [source, market] of [[residential, 'residential'], [commercial, 'commercial']]) {
    assert.match(source, /fetch\(form\.action/);
    assert.match(source, /'Content-Type': 'application\/json'/);
    assert.match(source, new RegExp("market: '" + market + "'"));
    assert.match(source, /state: inputState\(\)/);
    assert.match(source, /submitToServer\('email_quote'\)/);
    assert.match(source, /submitToServer\('request_appointment'\)/);
    assert.equal(source.includes('mailto:'), false);
  }
});

test('server owns validation, pricing and delivery controls', () => {
  for (const text of ['real_estate_same_origin', 'real_estate_rate_allowed', 'application/json', 'real_estate_prepare_submission', 'real_estate_send_mail']) {
    assert.ok(handler.includes(text), text);
  }
  for (const text of [
    'Residential SiteSee Real Estate Quote',
    'Commercial SiteSee Real Estate Quote',
    'America/Chicago',
    '79500',
    '49900',
    '250000',
    "'photoMax'=>65",
    "'extraCents'=>2670",
    "'hostingPrepaid'",
  ]) {
    assert.ok(serverPricing.includes(text), text);
  }
});

test('pricing access follows the corporate manual-approval flow', () => {
  for (const text of ['real_estate_pricing_save_lead', 'real_estate_pricing_create_approval_token', 'Manual Review Required']) {
    assert.ok(accessHandler.includes(text), text);
  }
  assert.match(approvalHandler, /Approve &amp; Send Pricing Access/);
  assert.match(approvalHandler, /real_estate_pricing_create_access_token/);
  assert.match(confirmationHandler, /real_estate_pricing_validate_access_token/);
  assert.match(confirmationHandler, /Location: pricing\.php/);
  assert.match(gateConfig, /SITESEE_REAL_ESTATE_PRICING_GATE_SECRET/);
  assert.match(gateConfig, /SITESEE_REAL_ESTATE_SITE_URL/);
});

test('calculator scripts are served only through the verified asset endpoint', () => {
  for (const asset of ['quote-engine', 'residential-form', 'commercial-quote-engine', 'commercial-form', 'market-selector']) {
    assert.match(html, new RegExp('pricing-asset\\.php\\?asset=' + asset));
  }
  assert.match(protectedAssets, /real_estate_pricing_has_access\(\)/);
  for (const oldPath of [
    'public/assets/js/quote-engine.js',
    'public/assets/js/pricing.js',
    'public/assets/js/commercial-quote-engine.js',
    'public/assets/js/commercial-pricing.js',
    'public/assets/js/pricing-market.js',
  ]) {
    assert.equal(fs.existsSync(path.join(root, oldPath)), false, oldPath);
  }
});

test('the endpoint does not trust a browser-supplied price or email body', () => {
  assert.equal(handler.includes("payload['total']"), false);
  assert.equal(handler.includes("payload['plain']"), false);
  assert.equal(handler.includes("payload['subject']"), false);
  assert.match(serverPricing, /real_estate_residential_quote\(\$state\)/);
  assert.match(serverPricing, /real_estate_commercial_quote\(\$state\)/);
});
