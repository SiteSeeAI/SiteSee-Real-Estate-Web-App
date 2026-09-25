const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
function fixture(file, response, options = {}) {
  const source = read('_private/pricing-assets/' + file);
  const start = source.indexOf('  const completedActions = new Map();');
  const end = source.indexOf("  get('email-self').addEventListener", start);
  assert.ok(start >= 0 && end > start, 'Use the actual button-state and submission functions.');
  const result = { navigation: [], messages: [], requests: [], copies: [], elements: new Map(), options };
  function get(id) {
    if (!result.elements.has(id)) result.elements.set(id, {
      textContent:'', value:'', hidden:true, disabled:false, dataset:{}, style:{}, attributes:{},
      setAttribute(key,value) { this.attributes[key] = value; },
      replaceChildren(...children) { this.children = children; },
      focus() {}, select() {}
    });
    return result.elements.get(id);
  }
  get('shoot-date').value = '2099-01-15'; get('shoot-time').value = '10:00';
  result.state = {category:'average', selected:['photo']};
  result.details = {email:'agent@example.com', street:'123 Example Street'};
  const context = {
    URL, JSON, get,
    window: { location: { href: 'https://re.sitesee.ai/pricing.php', replace: url => result.navigation.push(url) } },
    form: { action: 'https://re.sitesee.ai/quote-submit.php', elements: { namedItem: () => ({ value: '' }) } },
    prepare: () => {
      if (options.prepareError) throw new Error('Quote preparation failed');
      return options.invalid ? null : { quote:{}, details:result.details, appointment:{} };
    },
    inputState: () => result.state,
    details: () => result.details,
    scheduling: {data: () => ({meetPhotographer:'Yes', cancellationAccepted:true})},
    Q: {emailBody: () => 'Prepared quote text'},
    navigator: {clipboard:{writeText: async text => {
      result.copies.push(text);
      if (options.copyError) throw new Error('Clipboard unavailable');
      if (options.copyWait) await options.copyWait;
    }}},
    document: {createElement: () => get('manual-copy'), createTextNode: text => text},
    showStatus: (message, isError = false) => result.messages.push({ message, isError }),
    fetch: async (url, request) => {
      result.requests.push({url, request});
      if (options.networkError) throw new Error('Network unavailable');
      if (options.fetchWait) await options.fetchWait;
      return { ok: options.httpOk !== false, json: async () => options.response || response };
    }
  };
  vm.createContext(context);
  vm.runInContext(source.slice(start, end), context);
  context.refreshActionButtons();
  result.context = context; result.get = get;
  return result;
}
async function submit(file, action, response, options = {}) {
  const result = fixture(file, response, options);
  await result.context.submitToServer(action);
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

for (const file of ['pricing.js', 'commercial-pricing.js']) {
  const receipt = {ok:true, action:'email_quote', reference:'B638F1E8E9', message:'Quote sent'};
  test(file + ': sent button stays distinct and unchanged quotes cannot be emailed twice', async () => {
    const result = fixture(file, receipt);
    await result.context.submitToServer('email_quote');
    assert.equal(result.get('email-self').textContent, 'Email Sent ✓');
    assert.equal(result.get('email-self').dataset.actionState, 'complete');
    assert.equal(result.get('email-self').disabled, true);
    await result.context.submitToServer('email_quote');
    assert.equal(result.requests.length, 1);
    result.get('email-self').disabled = false; // Simulate browser-restored DOM controls.
    result.context.refreshActionButtons();
    assert.equal(result.get('email-self').disabled, true);
    result.details.street = '456 Different Street';
    result.context.refreshActionButtons();
    assert.equal(result.get('email-self').disabled, false);
    assert.equal(result.get('email-self').textContent, 'Email My Quote To Me');
    result.details.street = '123 Example Street';
    result.context.refreshActionButtons();
    assert.equal(result.get('email-self').textContent, 'Email Sent ✓');
  });
  test(file + ': rapid clicks and other actions are blocked while email is sending', async () => {
    let release;
    const fetchWait = new Promise(resolve => { release = resolve; });
    const result = fixture(file, receipt, {fetchWait});
    const first = result.context.submitToServer('email_quote');
    assert.equal(result.get('email-self').textContent, 'Sending Email…');
    assert.equal(result.get('email-self').attributes['aria-busy'], 'true');
    assert.equal(result.get('copy-quote').disabled, true);
    await result.context.submitToServer('email_quote');
    await result.context.submitToServer('request_appointment');
    await result.context.copyQuote();
    assert.equal(result.requests.length, 1);
    assert.equal(result.copies.length, 0);
    release(); await first;
    assert.equal(result.get('email-self').textContent, 'Email Sent ✓');
  });
  test(file + ': a changed quote during sending does not inherit the earlier sent state', async () => {
    let release;
    const result = fixture(file, receipt, {fetchWait:new Promise(resolve => {release=resolve;})});
    const pending = result.context.submitToServer('email_quote');
    result.details.email = 'new@example.com';
    release(); await pending;
    assert.equal(result.get('email-self').disabled, false);
    result.details.email = 'agent@example.com';
    result.context.refreshActionButtons();
    assert.equal(result.get('email-self').disabled, true);
  });
  test(file + ': copy shows progress and completion without sending email', async () => {
    let release;
    const result = fixture(file, receipt, {copyWait:new Promise(resolve => {release=resolve;})});
    const pending = result.context.copyQuote();
    assert.equal(result.get('copy-quote').textContent, 'Copying…');
    await result.context.copyQuote();
    assert.equal(result.copies.length, 1);
    release(); await pending;
    assert.equal(result.get('copy-quote').textContent, 'Copied ✓');
    assert.equal(result.get('copy-quote').disabled, false);
    assert.equal(result.requests.length, 0);
    result.state.category = 'large';
    result.context.refreshActionButtons();
    assert.equal(result.get('copy-quote').textContent, 'Copy Quote');
  });
  test(file + ': clipboard rejection presents manual text without claiming it was copied', async () => {
    const result = fixture(file, receipt, {copyError:true});
    await result.context.copyQuote();
    assert.equal(result.get('copy-quote').textContent, 'Copy Quote');
    assert.equal(result.get('manual-copy').value, 'Prepared quote text');
    assert.equal(result.get('request-status').hidden, false);
  });
  test(file + ': failures re-enable actions and preparation errors become visible', async () => {
    const result = fixture(file, receipt, {networkError:true});
    await result.context.submitToServer('email_quote');
    assert.equal(result.get('email-self').disabled, false);
    assert.equal(result.get('email-self').textContent, 'Email My Quote To Me');
    result.options.networkError = false;
    result.options.prepareError = true;
    await result.context.submitToServer('email_quote');
    assert.equal(result.messages.at(-1).isError, true);
    assert.match(result.messages.at(-1).message, /preparation failed/);
    result.options.prepareError = false;
    await result.context.submitToServer('email_quote');
    assert.equal(result.get('email-self').textContent, 'Email Sent ✓');
  });
  test(file + ': accepted appointment locks its request and any emailed quote copy', async () => {
    const result = fixture(file, {...receipt, action:'request_appointment', copy_sent:true});
    await result.context.submitToServer('request_appointment');
    assert.equal(result.get('request-shoot').textContent, 'Request Received ✓');
    assert.equal(result.get('request-shoot').disabled, true);
    assert.equal(result.get('email-self').textContent, 'Email Sent ✓');
    await result.context.submitToServer('request_appointment');
    await result.context.submitToServer('email_quote');
    assert.equal(result.requests.length, 1);
    assert.equal(result.navigation.length, 2);
    assert.equal(result.navigation[0], result.navigation[1]);
  });
}
