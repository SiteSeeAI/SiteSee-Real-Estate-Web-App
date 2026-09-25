const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
async function submit(file, action, response, options = {}) {
  const source = read('_private/pricing-assets/' + file);
  const start = source.indexOf('  async function submitToServer(action) {');
  const end = source.indexOf("  get('email-self').addEventListener", start);
  assert.ok(start >= 0 && end > start, 'Use the actual form submission function.');
  const result = { navigation: [], messages: [], sending: [], requests: [] };
  const context = {
    URL, JSON,
    window: { location: { href: 'https://re.sitesee.ai/pricing.php', assign: url => result.navigation.push(url) } },
    form: { action: 'https://re.sitesee.ai/quote-submit.php', elements: { namedItem: () => ({ value: '' }) } },
    prepare: () => options.invalid ? null : { details: {}, appointment: {} },
    inputState: () => ({}),
    setSending: value => result.sending.push(value),
    showStatus: (message, isError = false) => result.messages.push({ message, isError }),
    fetch: async (url, request) => {
      result.requests.push({url, request});
      if (options.networkError) throw new Error('Network unavailable');
      return { ok: options.httpOk !== false, json: async () => response };
    }
  };
  vm.createContext(context);
  vm.runInContext(source.slice(start, end), context);
  await context.submitToServer(action);
  return result;
}

for (const file of ['pricing.js', 'commercial-pricing.js']) {
  test(file + ': confirmed request opens receipt with its reference and copy status', async () => {
    for (const copySent of [true, false, undefined]) {
      const result = await submit(file, 'request_appointment', {
        ok: true, action: 'request_appointment', reference: 'B638F1E8E9', copy_sent: copySent, message: 'Request accepted'
      });
      assert.equal(result.navigation.length, 1);
      const target = new URL(result.navigation[0]);
      assert.equal(target.origin, 'https://re.sitesee.ai');
      assert.equal(target.pathname, '/request-received.html');
      assert.equal(target.searchParams.get('reference'), 'B638F1E8E9');
      assert.equal(target.searchParams.get('copy'), copySent === undefined ? null : copySent ? 'sent' : 'not-sent');
      assert.deepEqual([...target.searchParams.keys()].sort(), copySent === undefined ? ['reference'] : ['copy', 'reference']);
      assert.equal(result.requests.length, 1);
    }
  });
  test(file + ': rejected, incomplete and interrupted requests do not navigate', async () => {
    for (const [response, options] of [
      [{ ok: false, message: 'Session expired' }, {httpOk: false}],
      [{ ok: false, message: 'Choose a future time' }, {}],
      [{ ok: true, message: 'No confirmed reference' }, {}],
      [{ ok: true, action: 'request_appointment', reference: '../invalid' }, {}],
      [{}, {networkError: true}],
      [{}, {invalid: true}]
    ]) {
      const result = await submit(file, 'request_appointment', response, options);
      assert.equal(result.navigation.length, 0);
      if (response.ok === false || options.networkError) assert.equal(result.messages.at(-1).isError, true);
      if (options.invalid) assert.equal(result.requests.length, 0);
    }
  });
  test(file + ': emailing a quote stays on the pricing page', async () => {
    const result = await submit(file, 'email_quote', {ok:true, action:'email_quote', reference:'B638F1E8E9', message:'Quote sent'});
    assert.equal(result.navigation.length, 0);
    assert.equal(result.messages.at(-1).message, 'Quote sent Reference: B638F1E8E9.');
  });
}

function render(search) {
  const elements = new Map();
  const document = {
    title: 'Request Status | SiteSee Real Estate',
    getElementById: id => {
      if (!elements.has(id)) elements.set(id, {textContent:'', hidden:true});
      return elements.get(id);
    }
  };
  vm.runInNewContext(read('public/assets/js/request-received.js'), {window:{location:{search}}, document, URLSearchParams});
  return {document, elements};
}
test('receipt displays the reference and reports the actual copy status', () => {
  for (const copy of ['sent', 'not-sent']) {
    const {document, elements} = render('?reference=B638F1E8E9&copy='+copy);
    assert.equal(document.title, 'Request Received | SiteSee Real Estate');
    assert.equal(elements.get('receipt-reference').textContent, 'B638F1E8E9');
    assert.equal(elements.get('receipt-details').hidden, false);
    assert.match(elements.get('receipt-email').textContent, copy === 'sent' ? /also sent a copy/ : /couldn’t send the email copy/);
  }
  const {elements} = render('?reference=B638F1E8E9');
  assert.equal(elements.has('receipt-email'), false);
});
test('direct visits and malformed references do not claim a request was received', () => {
  for (const search of ['', '?reference=123', '?reference='+encodeURIComponent('<img src=x onerror=alert(1)>')]) {
    const {document, elements} = render(search);
    assert.equal(document.title, 'Request Status | SiteSee Real Estate');
    assert.equal(elements.size, 0);
  }
});
