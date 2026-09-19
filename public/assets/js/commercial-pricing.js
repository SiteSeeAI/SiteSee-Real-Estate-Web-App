/* Commercial quote form: separate rates, service selections and licensing. */
(() => {
  'use strict';
  const Q = window.SiteSeeCommercialQuote;
  const form = document.getElementById('commercial-quote');
  if (!form || !Q) return;
  const get = id => document.getElementById('c-' + id);
  const value = name => form.elements.namedItem(name).value.trim();
  const addressFields = [...get('address-fields').querySelectorAll('input,select')];
  const agentFields = [...get('agent-fields').querySelectorAll('input,select')];
  const selected = new Set(['photo']);
  let unlocked = false, reviewed = false, current = null;
  const quantity = (id,label,min,max,step,initial) => '<div class="quote-size"><label for="c-'+id+'">'+label+'</label><input id="c-'+id+'" name="'+id+'" type="number" min="'+min+'" max="'+max+'" step="'+step+'" value="'+initial+'" required disabled></div>';
  Object.entries(Q.services).forEach(([key,service]) => {
    const row = document.createElement('div'); row.className='quote-service';
    const top = document.createElement('div'); top.className='quote-row-top';
    const label = document.createElement('label'), check = document.createElement('input');
    check.type='checkbox';check.id='c-service-'+key;check.name='service-'+key;check.checked=selected.has(key);
    const text=document.createElement('span');text.textContent=service.label;
    const detail=document.createElement('small');detail.id='c-detail-'+key;detail.textContent=service.detail;text.append(detail);label.append(check,text);
    const price=document.createElement('span');price.id='c-price-'+key;price.className='quote-price';top.append(label,price);row.append(top);
    let controls='';
    if(key==='hourly')controls=quantity('labor-hours','Hours On Site',1,5,.5,5);
    if(key==='drone')controls=quantity('aerial-images','Finished Aerial Images',1,100,1,10);
    if(key==='floor')controls=quantity('plan-sets','Property Layout Sets',1,20,1,1);
    if(key==='video')controls=quantity('video-count','Number Of Finished Videos',1,20,1,1)+'<div class="quote-video-length"><span>Length Of Each Video</span><div class="quote-video-fields"><label for="c-video-minutes">Minutes<input id="c-video-minutes" type="number" min="1" max="3" step="1" value="1" required disabled></label><span aria-hidden="true">:</span><label for="c-video-seconds">Seconds<input id="c-video-seconds" type="number" min="0" max="59" step="1" value="0" required disabled></label></div></div><input id="c-video-slider" type="range" min="60" max="180" step="1" value="60" aria-label="Commercial video duration in seconds" aria-valuetext="1 minute 0 seconds"><p class="quote-note">$1,500 per finished video includes your chosen length from 1:00 to 3:00.</p>';
    if(controls){const extra=document.createElement('div');extra.className='quote-extra';extra.id='c-'+key+'-controls';extra.hidden=true;extra.innerHTML=controls;row.append(extra);}
    get('quote-services').append(row);
    check.addEventListener('change',()=>{
      if(check.checked){selected.add(key);if(key==='photo')selected.delete('hourly');if(key==='hourly')selected.delete('photo');}else selected.delete(key);
      update();
    });
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
    return {category:value('category'),sqft:value('sqft'),selected:[...selected],videoSeconds:videoSeconds(),videos:get('video-count').value,aerialImages:get('aerial-images').value,hours:get('labor-hours').value,plans:get('plan-sets').value,licenseType:value('licenseType'),licenseMonths:get('license-months').value,extendedHosting:get('extended-hosting').checked};
  }
  function update() {
    ['hourly','drone','video','floor'].forEach(key=>{
      const active=selected.has(key),controls=get(key+'-controls');controls.hidden=!active;controls.querySelectorAll('input').forEach(el=>{el.disabled=!active;});
    });
    const licenseEligible=['photo','drone','video'].some(key=>selected.has(key));
    get('license-fields').hidden=!licenseEligible;
    get('license-fields').querySelectorAll('input').forEach(el=>{el.disabled=!licenseEligible;});
    const term=licenseEligible&&value('licenseType')==='term';get('license-term-controls').hidden=!term;get('license-months').disabled=!term;get('license-slider').disabled=!term;
    get('hosting-controls').hidden=!selected.has('mp');get('extended-hosting').disabled=!selected.has('mp');
    get('request-status').hidden=true;
    const state=inputState();
    Object.entries(Q.services).forEach(([key,service])=>{
      get('service-'+key).checked=selected.has(key);
      try {const alone=Q.calculate({...state,selected:[key],licenseType:'term',licenseMonths:6,extendedHosting:false});const line=alone.lines.find(line=>line.key===key);get('price-'+key).textContent=(line.from?'From ':'')+Q.money(line.cents);}
      catch(_){get('price-'+key).textContent='—';}
    });
    get('quote-address').textContent=addressText();get('summary-property').textContent=addressText()+' · '+Number(state.sqft).toLocaleString()+' sq ft';
    try {
      if(!get('property-sqft').validity.valid)throw new Error('Enter a whole-number property size within the commercial category.');
      current=Q.calculate(state);
      get('estimate-error').hidden=true;get('review-estimate').disabled=false;
      get('summary-total').textContent=current.pending?'Custom Quote':Q.money(current.totalCents);get('estimate-inline-total').textContent=get('summary-total').textContent;
      get('summary-subtotal').hidden=!current.pending;get('summary-subtotal').textContent='Priced items: '+Q.money(current.subtotalCents)+'. Final total requires confirmation.';
      get('custom-quote-note').hidden=!current.pending;get('custom-quote-note').textContent=current.pendingReasons.join(' ');
      get('summary-lines').replaceChildren();
      current.lines.forEach(line=>{const li=document.createElement('li'),a=document.createElement('span'),b=document.createElement('span');a.textContent=line.label;b.textContent=line.included?'Included':line.cents===null?'To Quote':(line.from?'From ':'')+Q.money(line.cents);li.append(a,b);get('summary-lines').append(li);});
      get('summary-duration').textContent=current.knownMinutes?'About '+Q.duration(current.knownMinutes)+(current.additionalCapture.length?' for timed services':''):current.hasOnSite?'Confirmed With Your Appointment':'No On-Site Visit Required';
      const parts=[];if(current.matterportMinutes)parts.push('Matterport: '+Q.duration(current.matterportMinutes));if(current.laborMinutes)parts.push('Creative labor: '+Q.duration(current.laborMinutes));get('summary-breakdown').textContent=parts.join(' · ');
      get('estimate-inline-time').textContent='Time on site: '+get('summary-duration').textContent;
      get('summary-time-note').textContent=current.additionalCapture.length?'Time for '+current.additionalCapture.map(key=>Q.services[key].label).join(', ')+' will be confirmed. Building layout, escorts and access affect the schedule.':'Time is approximate. Building layout, escorts and access affect the schedule.';
      get('date-label').textContent=current.hasOnSite?'Preferred Shoot Date (Required For A Request)':'Preferred Completion Date (Required For A Request)';get('time-label').textContent=current.hasOnSite?'Preferred Start Time · Central Time':'Preferred Contact Time · Central Time';
    }catch(error){current=null;get('estimate-error').hidden=false;get('estimate-error').textContent=error.message;get('summary-total').textContent='Complete Your Selection';get('estimate-inline-total').textContent='—';get('summary-subtotal').hidden=true;get('summary-lines').replaceChildren();get('summary-duration').textContent='—';get('summary-breakdown').textContent='';get('summary-time-note').textContent='';get('estimate-inline-time').textContent='';get('custom-quote-note').hidden=true;get('review-estimate').disabled=true;}
  }
  get('open-estimate').addEventListener('click', () => {
    if (!validateFields(addressFields, true)) return;
    unlocked = true; syncAddress(); get('estimate-title').focus();
  });
  addressFields.forEach(field => { field.addEventListener('input', syncAddress); field.addEventListener('change', syncAddress); });
  agentFields.forEach(field => field.addEventListener('input', () => { validateField(field); get('request-status').hidden = true; }));
  form.querySelectorAll('[name=category]').forEach(radio => radio.addEventListener('change', () => {
    const cat = Q.categories[value('category')], size = get('property-sqft'), slider = get('property-slider');
    size.min = slider.min = cat.min; size.max = slider.max = cat.max;
    size.value = slider.value = Math.min(cat.max, Math.max(cat.min, Number(size.value) || cat.min));
    get('range-min').textContent = cat.min.toLocaleString() + ' sq ft'; get('range-max').textContent = cat.max.toLocaleString() + ' sq ft'; update();
  }));

  get('property-sqft').addEventListener('input', () => { if (get('property-sqft').validity.valid) get('property-slider').value = get('property-sqft').value; update(); });
  get('property-slider').addEventListener('input', () => { get('property-sqft').value = get('property-slider').value; update(); });
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
  ['labor-hours','aerial-images','plan-sets','video-count'].forEach(id=>get(id).addEventListener('input',update));
  form.querySelectorAll('[name=licenseType]').forEach(el=>el.addEventListener('change',update));
  get('license-months').addEventListener('input',()=>{if(get('license-months').validity.valid)get('license-slider').value=get('license-months').value;update();});
  get('license-slider').addEventListener('input',()=>{get('license-months').value=get('license-slider').value;update();});
  get('extended-hosting').addEventListener('change',update);
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
  function prepare(appointmentRequired) {
    if (!unlocked || !validateFields(addressFields, true)) { syncAddress(); return null; }
    update(); if (!current) { get('estimate-title').focus(); return null; }
    if (!reviewed || !validateFields(agentFields, true)) return null;
    let appointment;
    if (appointmentRequired) {
      const date = get('shoot-date'), time = get('shoot-time'), now = centralNow(); date.min = now.date;
      date.setCustomValidity(''); time.setCustomValidity('');
      if (!date.checkValidity()) { date.reportValidity(); return null; }
      if (!time.checkValidity()) { time.reportValidity(); return null; }
      if (date.value === now.date && time.value <= now.time) { time.setCustomValidity('Choose a future time in Central Time.'); time.reportValidity(); return null; }
      appointment = { date: date.value, time: time.value };
    }
    return { quote: current, details: details(), appointment };
  }
  ['shoot-date','shoot-time'].forEach(id => get(id).addEventListener('input', () => { get(id).setCustomValidity(''); get('request-status').hidden = true; }));
  function showStatus(message) { get('request-status').textContent = message; get('request-status').hidden = false; }
  function openEmail(self) {
    const prepared = prepare(!self); if (!prepared) return;
    const body = Q.emailBody(prepared.quote, prepared.details, prepared.appointment);
    const recipient = self ? prepared.details.email : 'sales@sitesee.ai';
    const uri = 'mailto:' + encodeURIComponent(recipient) + '?subject=' + encodeURIComponent(Q.subject) + '&body=' + encodeURIComponent(body);
    showStatus('Your email draft is ready. Complete sending in your email app. If it did not open, use Copy Quote and paste it into a message with the subject “' + Q.subject + '”. No message has been sent by this page.');
    window.location.href = uri;
  }
  get('email-self').addEventListener('click', () => openEmail(true));
  get('copy-quote').addEventListener('click', async () => {
    const prepared = prepare(false); if (!prepared) return;
    const date = get('shoot-date'), time = get('shoot-time');
    if (date.value && time.value && date.validity.valid && time.validity.valid) prepared.appointment = { date:date.value, time:time.value };
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
