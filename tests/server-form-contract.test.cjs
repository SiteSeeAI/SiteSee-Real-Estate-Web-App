const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const html = read('public/pricing.html');
const residential = read('public/assets/js/pricing.js');
const commercial = read('public/assets/js/commercial-pricing.js');
const handler = read('public/pricing-request.php');
const serverPricing = read('_private/real-estate-pricing.php');

test('both pricing forms use the server endpoint without changing form ids', () => {
  assert.match(html, /id="residential-quote"[^>]*action="pricing-request\.php"[^>]*method="post"/);
  assert.match(html, /id="commercial-quote"[^>]*action="pricing-request\.php"[^>]*method="post"/);
  assert.equal((html.match(/name="company_fax"/g) || []).length, 2);
  assert.equal(html.includes('Email actions open a prepared message'), false);
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

test('the endpoint does not trust a browser-supplied price or email body', () => {
  assert.equal(handler.includes("payload['total']"), false);
  assert.equal(handler.includes("payload['plain']"), false);
  assert.equal(handler.includes("payload['subject']"), false);
  assert.match(serverPricing, /real_estate_residential_quote\(\$state\)/);
  assert.match(serverPricing, /real_estate_commercial_quote\(\$state\)/);
});

