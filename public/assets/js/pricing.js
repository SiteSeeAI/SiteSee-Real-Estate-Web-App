/* Property-address gating and quote actions. No data is sent or stored by this page. */
(() => {
  'use strict';
  const Q = window.SiteSeeQuote;
  const form = document.getElementById('residential-quote');
  if (!form || !Q) return;
  const get = id => document.getElementById(id);
  const value = name => form.elements.namedItem(name).value.trim();
  const addressFields = [...get('address-fields').querySelectorAll('input,select')];
  const agentFields = [...get('agent-fields').querySelectorAll('input,select')];
  const selected = new Set(['photo']);
  let unlocked = false, reviewed = false, current = null;
  let packageMode = false, individualProperty = null;
  // Build each service once; package switches update state without removing controls.
  Object.entries(Q.services).forEach(([key, service]) => {
    const row = document.createElement('div'); row.className = 'quote-service';
    const top = document.createElement('div'); top.className = 'quote-row-top';
    const label = document.createElement('label');
    const check = document.createElement('input'); check.type = 'checkbox'; check.id = 'service-' + key; check.name = 'service-' + key; check.checked = selected.has(key); check.disabled = key === 'photo';
    const text = document.createElement('span'); text.textContent = service.label;
    const detail = document.createElement('small'); detail.id = 'detail-' + key; detail.textContent = service.detail; text.append(detail); label.append(check, text);
    const price = document.createElement('span'); price.className = 'quote-price'; price.id = 'price-' + key; top.append(label, price); row.append(top);
    if (key === 'mp') {
      const controls = document.createElement('div'); controls.className = 'quote-extra'; controls.id = 'matterport-controls'; controls.hidden = true;
      controls.innerHTML = '<div class="quote-size"><label for="matterport-sqft">Matterport Coverage · Square Feet</label><input id="matterport-sqft" name="matterportSqft" type="number" min="1" max="10000" step="1" placeholder="Enter area" required disabled></div><input id="matterport-slider" type="range" min="0" max="10000" step="1" value="0" aria-label="Matterport coverage in square feet" disabled><div class="quote-range-labels"><span>Enter the area to scan</span><span>10,000 sq ft maximum</span></div>';
      row.append(controls);
    }
    if (key === 'video') {
      const controls = document.createElement('div'); controls.className = 'quote-extra'; controls.id = 'video-controls'; controls.hidden = true;
      controls.innerHTML = '<div class="quote-video-length"><span>Video Length</span><div class="quote-video-fields"><label for="video-minutes">Minutes<input id="video-minutes" name="videoMinutes" type="number" min="1" max="3" step="1" value="1" required disabled></label><span aria-hidden="true">:</span><label for="video-seconds">Seconds<input id="video-seconds" name="videoSecondsPart" type="number" min="0" max="59" step="1" value="0" required disabled></label></div></div><input id="video-slider" type="range" min="60" max="180" step="1" value="60" aria-label="Video length in seconds" aria-valuetext="1 minute 0 seconds"><div class="quote-range-labels"><span>1 minute · $225</span><span>3 minutes · $350</span></div>';
      row.append(controls);
    }
    if (key === 'twilight') {
      const controls = document.createElement('div'); controls.className = 'quote-extra'; controls.id = 'twilight-controls'; controls.hidden = true;
      controls.innerHTML = '<div class="quote-size"><label for="twilight-images">Number Of Images</label><input id="twilight-images" name="images" type="number" min="1" max="100" step="1" value="1" required disabled></div>';
      row.append(controls);
    }
    get('quote-services').append(row);
    check.addEventListener('change', () => { if (key === 'photo' || check.checked) selected.add(key); else selected.delete(key); update(); });
  });
  function validateField(field) {
    field.setCustomValidity('');
    if (field.required && !field.value.trim()) field.setCustomValidity('Please complete this field.');
    if (field.name === 'phone' && field.value.replace(/\D/g, '').length < 10) field.setCustomValidity('Enter a phone number with at least 10 digits.');
    return field.validity.valid;
  }
  function validateFields(fields, report = false) {
    const invalid = fields.filter(field => !validateField(field));
    if (report && invalid.length) { invalid[0].reportValidity(); invalid[0].focus(); }
    return !invalid.length;
  }
  function addressText() { return value('street') + ', ' + value('city') + ', ' + value('state') + ' ' + value('zip'); }
  function syncAddress() {
    const valid = validateFields(addressFields);
    if (!valid) { unlocked = false; reviewed = false; current = null; }
    get('estimate-section').hidden = !unlocked;
    get('estimate-lock').hidden = unlocked;
    get('request-section').hidden = !unlocked || !reviewed;
    get('summary-locked').hidden = unlocked;
    get('summary-unlocked').hidden = !unlocked;
    get('open-estimate').setAttribute('aria-expanded', String(unlocked));
    get('review-estimate').setAttribute('aria-expanded', String(reviewed));
    get('open-estimate').textContent = unlocked ? 'Update Property Address ↓' : 'Continue To My Estimate ↓';
    get('request-status').hidden = true;
    if (unlocked) update();
  }
  function videoSeconds() {
    const minutes = get('video-minutes'), seconds = get('video-seconds');
    if (!minutes.value.trim() || !seconds.value.trim() || !minutes.validity.valid || !seconds.validity.valid) return NaN;
    return Number(minutes.value) * 60 + Number(seconds.value);
  }
  function inputState() {
    return { category: value('category'), package: value('package'), sqft: value('sqft'), matterportSqft: get('matterport-sqft').value, selected: [...selected], videoSeconds: videoSeconds(), images: get('twilight-images').value };
  }
  function syncPropertyMode() {
    const bundled = value('package') !== 'custom', size = get('property-sqft'), slider = get('property-slider');
    const categories = [...form.querySelectorAll('[name=category]')];
    if (bundled && !packageMode) individualProperty = { category: value('category'), sqft: size.value };
    if (bundled || packageMode) {
      categories.forEach(radio => { radio.checked = radio.value === individualProperty.category; });
      const cat = Q.categories[individualProperty.category];
      size.min = slider.min = bundled ? 0 : cat.min;
      size.max = slider.max = bundled ? 0 : cat.max;
      size.value = slider.value = bundled ? 0 : individualProperty.sqft;
      get('range-min').textContent = cat.min.toLocaleString() + ' sq ft';
      get('range-max').textContent = cat.max.toLocaleString() + ' sq ft';
    }
    size.disabled = slider.disabled = bundled;
    categories.forEach(radio => { radio.disabled = bundled; });
    get('category-options').classList.toggle('quote-categories-locked', bundled);
    get('category-options').setAttribute('aria-disabled', String(bundled));
    get('range-min').parentElement.hidden = bundled;
    packageMode = bundled;
  }
  function update() {
    syncPropertyMode();
    const state = inputState(), pack = Q.packages[state.package];
    const matterportActive = state.package !== 'custom' && selected.has('mp');
    get('matterport-controls').hidden = !matterportActive;
    get('matterport-sqft').disabled = get('matterport-slider').disabled = !matterportActive;
    const videoActive = selected.has('video') && !pack.includes.includes('video');
    get('video-controls').hidden = !videoActive; get('video-minutes').disabled = !videoActive; get('video-seconds').disabled = !videoActive; get('video-slider').disabled = !videoActive;
    get('twilight-controls').hidden = !selected.has('twilight'); get('twilight-images').disabled = !selected.has('twilight');
    get('request-status').hidden = true;
    Object.entries(Q.services).forEach(([key, service]) => {
      const included = pack.includes.includes(key), check = get('service-' + key), price = get('price-' + key);
      check.disabled = key === 'photo' || included; check.checked = key === 'photo' || included || selected.has(key);
      price.classList.toggle('quote-included', included);
      get('detail-' + key).textContent = included ? (key === 'video' ? pack.minutes + '-minute video · ' : key === 'photo' ? pack.photos + ' · ' : '') + 'Included in ' + pack.label : (key === 'photo' ? Q.categories[state.category].photos + ' · ' : '') + service.detail;
      if (included) price.textContent = 'Included';
      else if (service.cents) price.textContent = Q.money(service.cents);
      else {
        try { const alone = Q.calculate({ ...state, selected: [key] }); const line = alone.lines.find(item => item.key === key); price.textContent = line.cents === null ? 'Custom Quote' : Q.money(line.cents); }
        catch (_) { price.textContent = key === 'mp' && state.package !== 'custom' ? (matterportActive ? 'Enter area' : 'From $69.00') : '—'; }
      }
    });
    get('quote-address').textContent = addressText();
    get('summary-property').textContent = addressText() + ' · ' + (state.package === 'custom' ? Number(state.sqft).toLocaleString() + ' sq ft' : pack.label + ' Package');
    try {
      if (state.package === 'custom' && !get('property-sqft').validity.valid) throw new Error('Enter a whole-number property size within the selected category.');
      if (videoActive && (!Number.isInteger(state.videoSeconds) || state.videoSeconds < 60 || state.videoSeconds > 180)) throw new Error('Enter a video length from 1:00 to 3:00 in whole seconds.');
      current = Q.calculate(state);
      get('estimate-error').hidden = true;
      get('summary-total').textContent = current.pending ? 'Custom Quote' : Q.money(current.totalCents);
      get('estimate-inline-total').textContent = get('summary-total').textContent;
      get('summary-subtotal').hidden = !current.pending;
      get('summary-subtotal').textContent = 'Priced services: ' + Q.money(current.subtotalCents) + ', plus photography.';
      get('custom-quote-note').hidden = !current.pending;
      get('review-estimate').disabled = false;
      get('summary-lines').replaceChildren();
      const appendLine = (label, cost) => { const li = document.createElement('li'), a = document.createElement('span'), b = document.createElement('span'); a.textContent = label; b.textContent = cost; li.append(a,b); get('summary-lines').append(li); };
      if (current.packageCents) appendLine(current.package + ' Package', Q.money(current.packageCents));
      current.lines.forEach(line => appendLine(line.label, line.included ? 'Included' : line.cents === null ? 'To Confirm' : Q.money(line.cents)));
      get('summary-duration').textContent = current.knownMinutes ? 'About ' + Q.duration(current.knownMinutes) : current.additionalCapture.length ? 'Confirmed With Your Appointment' : 'No On-Site Visit Required';
      const parts = [];
      if (current.photographyMinutes) parts.push('Photography: ' + Q.duration(current.photographyMinutes));
      if (current.matterportMinutes) parts.push('Matterport: ' + Q.duration(current.matterportMinutes));
      if (current.videoMinutes) parts.push('Video: ' + Q.duration(current.videoMinutes));
      if (current.droneMinutes) parts.push('Drone / Aerial: ' + Q.duration(current.droneMinutes));
      get('summary-breakdown').textContent = parts.join(' · ');
      get('estimate-inline-time').textContent = 'Time on site: ' + get('summary-duration').textContent;
      get('summary-time-note').textContent = current.additionalCapture.length ? 'Time for ' + current.additionalCapture.map(k => Q.services[k].label).join(', ') + ' is not included above and will be confirmed with your appointment. Layout and access can affect time on site.' : 'Allow for property layout, access and readiness. Times are approximate and rounded up to five minutes.';
      get('date-label').textContent = current.hasOnSite ? 'Preferred Shoot Date (Required For A Request)' : 'Preferred Completion Date (Required For A Request)';
      get('time-label').textContent = current.hasOnSite ? 'Preferred Start Time · Central Time' : 'Preferred Contact Time · Central Time';
    } catch (error) {
      current = null;
      get('estimate-error').textContent = error.message; get('estimate-error').hidden = false;
      get('estimate-inline-total').textContent = '—'; get('estimate-inline-time').textContent = '';
      get('summary-total').textContent = 'Complete Your Selection'; get('summary-subtotal').hidden = true;
      get('summary-lines').replaceChildren(); get('summary-duration').textContent = '—'; get('summary-breakdown').textContent = ''; get('summary-time-note').textContent = ''; get('custom-quote-note').hidden = true;
      get('review-estimate').disabled = true;
    }
  }
  get('open-estimate').addEventListener('click', () => {
    if (!validateFields(addressFields, true)) return;
    unlocked = true; syncAddress(); get('estimate-title').focus();
  });
  addressFields.forEach(field => { field.addEventListener('input', syncAddress); field.addEventListener('change', syncAddress); });
  agentFields.forEach(field => field.addEventListener('input', () => { validateField(field); get('request-status').hidden = true; }));
  form.querySelectorAll('[name=category]').forEach(radio => radio.addEventListener('change', () => {
    if (value('package') !== 'custom') { update(); return; }
    const cat = Q.categories[value('category')], size = get('property-sqft'), slider = get('property-slider');
    size.min = slider.min = cat.min; size.max = slider.max = cat.max;
    size.value = slider.value = Math.min(cat.max, Math.max(cat.min, Number(size.value) || cat.min));
    get('range-min').textContent = cat.min.toLocaleString() + ' sq ft'; get('range-max').textContent = cat.max.toLocaleString() + ' sq ft'; update();
  }));
  form.querySelectorAll('[name=package]').forEach(radio => radio.addEventListener('change', update));
  get('property-sqft').addEventListener('input', () => { if (get('property-sqft').validity.valid) get('property-slider').value = get('property-sqft').value; update(); });
  get('property-slider').addEventListener('input', () => { get('property-sqft').value = get('property-slider').value; update(); });
  get('matterport-sqft').addEventListener('input', () => {
    if (get('matterport-sqft').validity.valid) get('matterport-slider').value = get('matterport-sqft').value;
    else if (!get('matterport-sqft').value) get('matterport-slider').value = 0;
    update();
  });
  get('matterport-slider').addEventListener('input', () => { get('matterport-sqft').value = Number(get('matterport-slider').value) ? get('matterport-slider').value : ''; update(); });
  const syncVideoSlider = () => {
    const seconds = videoSeconds();
    if (Number.isInteger(seconds) && seconds >= 60 && seconds <= 180) {
      get('video-slider').value = seconds;
      get('video-slider').setAttribute('aria-valuetext', Math.floor(seconds / 60) + ' minutes ' + (seconds % 60) + ' seconds');
    }
    update();
  };
  get('video-minutes').addEventListener('input', syncVideoSlider);
  get('video-seconds').addEventListener('input', syncVideoSlider);
  get('video-slider').addEventListener('input', () => {
    const seconds = Number(get('video-slider').value);
    get('video-minutes').value = Math.floor(seconds / 60); get('video-seconds').value = seconds % 60; syncVideoSlider();
  });
  get('twilight-images').addEventListener('input', update);
  get('review-estimate').addEventListener('click', () => {
    if (!unlocked || !validateFields(addressFields, true)) { syncAddress(); return; }
    update(); if (!current) return;
    reviewed = true; get('request-section').hidden = false; get('review-estimate').setAttribute('aria-expanded','true'); get('request-title').focus();
  });
  function centralNow() {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hourCycle:'h23' }).formatToParts(new Date());
    const p = Object.fromEntries(parts.map(x => [x.type,x.value]));
    return { date: p.year + '-' + p.month + '-' + p.day, time: p.hour + ':' + p.minute };
  }
  get('shoot-date').min = centralNow().date;
  const details = () => Object.fromEntries(['first','last','company','email','phone','street','city','state','zip','optOut'].map(key => [key,value(key)]));
  function prepare() {
    if (!unlocked || !validateFields(addressFields, true)) { syncAddress(); return null; }
    update(); if (!current) { get('estimate-title').focus(); return null; }
    if (!reviewed || !validateFields(agentFields, true)) return null;
    const date = get('shoot-date'), time = get('shoot-time'), now = centralNow(); date.min = now.date;
    date.setCustomValidity(''); time.setCustomValidity('');
    if (!date.checkValidity()) { date.reportValidity(); return null; }
    if (!time.checkValidity()) { time.reportValidity(); return null; }
    if (date.value === now.date && time.value <= now.time) { time.setCustomValidity('Choose a future time in Central Time.'); time.reportValidity(); return null; }
    const appointment = { date: date.value, time: time.value };
    return { quote: current, details: details(), appointment };
  }
  ['shoot-date','shoot-time'].forEach(id => get(id).addEventListener('input', () => { get(id).setCustomValidity(''); get('request-status').hidden = true; }));
  function showStatus(message) { get('request-status').textContent = message; get('request-status').hidden = false; }
  function openEmail(self) {
    const prepared = prepare(); if (!prepared) return;
    const body = Q.emailBody(prepared.quote, prepared.details, prepared.appointment);
    const recipient = self ? prepared.details.email : 'sales@sitesee.ai';
    const uri = 'mailto:' + encodeURIComponent(recipient) + '?subject=' + encodeURIComponent(Q.subject) + '&body=' + encodeURIComponent(body);
    showStatus('Your email draft is ready. Complete sending in your email app. If it did not open, use Copy Quote and paste it into a message with the subject “' + Q.subject + '”. No message has been sent by this page.');
    window.location.href = uri;
  }
  get('email-self').addEventListener('click', () => openEmail(true));
  get('copy-quote').addEventListener('click', async () => {
    const prepared = prepare(); if (!prepared) return;
    const text = Q.emailBody(prepared.quote, prepared.details, prepared.appointment);
    try { await navigator.clipboard.writeText(text); showStatus('Quote copied. Paste it into your email app when you are ready.'); }
    catch (_) {
      const textarea = document.createElement('textarea'); textarea.readOnly = true; textarea.value = text; textarea.setAttribute('aria-label','Quote text to copy'); textarea.rows = 14; textarea.style.width = '100%';
      get('request-status').replaceChildren(document.createTextNode('Select and copy your quote below.'), textarea); get('request-status').hidden = false; textarea.focus(); textarea.select();
    }
  });
  form.addEventListener('submit', event => { event.preventDefault(); openEmail(false); });
  get('open-estimate').disabled = false;
  // Back/forward restoration and autofill must be revalidated, never unlock by themselves.
  window.addEventListener('pageshow', syncAddress);
  syncAddress();
})();
