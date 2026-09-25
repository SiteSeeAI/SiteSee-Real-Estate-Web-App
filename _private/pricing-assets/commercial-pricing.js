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
  const scheduling = window.SiteSeeScheduling.attach(form, 'c-');
  const selected = new Set(['photo']);
  let unlocked = false, reviewed = false, current = null;
  const quantity = (id,label,min,max,step,initial,format='number') => '<div class="quote-size"><label for="c-'+id+'">'+label+'</label><output for="c-'+id+'" data-range-value data-format="'+format+'">'+(format==='duration'?Q.videoDuration(initial):initial)+'</output></div><input id="c-'+id+'" name="'+id+'" type="range" min="'+min+'" max="'+max+'" step="'+step+'" value="'+initial+'" disabled>';
  Object.entries(Q.services).filter(([key])=>key!=='photo').forEach(([key,service]) => {
    const row = document.createElement('div'); row.className='quote-service';row.id='c-row-'+key;row.hidden=key==='views360';
    const top = document.createElement('div'); top.className='quote-row-top';
    const label = document.createElement('label'), check = document.createElement('input');
    check.type='checkbox';check.id='c-service-'+key;check.name='service-'+key;check.checked=selected.has(key);
    const text=document.createElement('span');text.textContent=service.label;
    const detail=document.createElement('small');detail.id='c-detail-'+key;detail.textContent=service.detail;text.append(detail);label.append(check,text);
    const price=document.createElement('span');price.id='c-price-'+key;price.className='quote-price';top.append(label,price);row.append(top);
    let controls='';
    if(key==='platform')controls=quantity('platform-months','Platform Term · Months',6,18,1,6)+'<div class="quote-range-labels"><span>6 months</span><span>18 months</span></div><p class="quote-note">$49 per month. Your estimate includes the full selected term; six months is $294. Matterport capture and hosting remain separate. An independent property website is available separately for $175.</p>';
    if(key==='mp')controls='<div class="quote-size"><label for="c-matterport-sqft">Area To Scan · Square Feet</label><output id="c-matterport-area" for="c-matterport-sqft">5,000 sq ft</output></div><input id="c-matterport-sqft" name="matterport-sqft" type="range" min="1" max="10000" step="1" value="5000" disabled><div class="quote-range-labels"><span>1 sq ft</span><span id="c-matterport-max">10,000 sq ft</span></div><p class="quote-note">Scan only the areas you need. Photography coverage follows the category selected above. Add individual 360° views for other spaces with a SiteSee platform subscription.</p>';
    if(key==='views360')controls=quantity('views360-count','Individual 360° Photos',1,100,1,1);
    if(key==='drone')controls=quantity('aerial-images','Finished Aerial Images',1,100,1,1);
    if(key==='floor')controls=quantity('plan-sets','Property Layout Sets',1,20,1,1);
    if(key==='video')controls=quantity('video-count','Number Of Finished Videos',1,20,1,1)+quantity('video-duration','Length Of Each Video',60,180,1,60,'duration')+'<p class="quote-note">Your price updates with the finished video length. One-minute minimum; $500 minimum per video.</p>';
    if(controls){const extra=document.createElement('div');extra.className='quote-extra';extra.id='c-'+key+'-controls';extra.hidden=true;extra.innerHTML=controls;row.append(extra);}
    get('quote-services').append(row);
    check.addEventListener('change',()=>{
      if(check.checked)selected.add(key);else selected.delete(key);
      if(key==='website')form.querySelectorAll('[name=delivery]').forEach(option=>{option.checked=option.value===(check.checked?'website':'files');});
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
    if (report && invalid.length) { window.SiteSeeValidation.show(invalid[0]); }
    return !invalid.length;
  }
  function addressText() { return value('street') + (value('unit') ? ', ' + value('unit') : '') + ', ' + value('city') + ', ' + value('state') + ' ' + value('zip'); }
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
    return Number(get('video-duration').value);
  }
  function inputState() {
    return {category:value('category'),photoCount:get('photo-slider').value,matterportSqft:get('matterport-sqft').value,views360:get('views360-count').value,selected:[...selected],videoSeconds:videoSeconds(),videos:get('video-count').value,aerialImages:get('aerial-images').value,delivery:value('delivery'),platformMonths:get('platform-months').value,plans:get('plan-sets').value,licenseType:value('licenseType'),licenseMonths:get('license-months').value,hostingMonths:get('hosting-months').value,hostingPrepaid:get('hosting-prepaid').checked};
  }
  function update() {
    const platform=selected.has('platform');
    const matterport=selected.has('mp');
    if(!platform||!matterport)selected.delete('views360');
    get('row-views360').hidden=!matterport;
    get('row-views360').style.opacity=platform?'':'0.6';
    get('service-views360').disabled=!platform||!matterport;
    get('service-views360').setAttribute('aria-describedby','c-detail-views360');
    const website=form.querySelector('[name=delivery][value=website]');
    website.disabled=false;
    if(website.checked)selected.add('website');else selected.delete('website');
    get('website-note').textContent='$175 for a dedicated listing website. Also available with the SiteSee platform; charged once.';
    const cat=Q.categories[value('category')];
    get('photo-slider').min=cat.photoMin;
    get('photo-slider').max=cat.photoMax;
    get('photo-range-min').textContent=cat.photoMin+' photos';
    get('photo-range-max').textContent=cat.photoMax+' photos maximum';
    get('photo-inclusions').textContent=cat.photoMin+'–'+cat.photosIncluded+' photos included. Additional photos above '+cat.photosIncluded+' are '+Q.money(cat.extraPhotoCents)+' each. Maximum '+cat.photoMax+' total photos.';
    const scanLimit=Math.min(cat.max,20000);
    get('matterport-sqft').max=scanLimit;
    get('matterport-max').textContent=scanLimit.toLocaleString()+' sq ft';
    get('matterport-area').textContent=Number(get('matterport-sqft').value).toLocaleString()+' sq ft';
    get('matterport-sqft').setAttribute('aria-valuetext',get('matterport-area').textContent);
    form.querySelectorAll('output[data-range-value]').forEach(output=>{
      const slider=document.getElementById(output.getAttribute('for')),n=Number(slider.value);
      output.textContent=output.getAttribute('data-format')==='duration'?Q.videoDuration(n):n.toLocaleString();
      if(output.getAttribute('data-format')==='duration')slider.setAttribute('aria-valuetext',Math.floor(n/60)+' minutes '+n%60+' seconds');
    });
    ['platform','mp','views360','drone','video','floor'].forEach(key=>{
      const active=selected.has(key),controls=get(key+'-controls');controls.hidden=!active;controls.querySelectorAll('input').forEach(el=>{el.disabled=!active;});
    });
    const licenseEligible=['photo','drone','video'].some(key=>selected.has(key));
    get('license-fields').hidden=!licenseEligible;
    get('license-fields').querySelectorAll('input').forEach(el=>{el.disabled=!licenseEligible;});
    const term=licenseEligible&&value('licenseType')==='term';get('license-term-controls').hidden=!term;get('license-months').disabled=!term;
    get('hosting-controls').hidden=!selected.has('mp');get('hosting-controls').querySelectorAll('input').forEach(el=>{el.disabled=!selected.has('mp');});
    get('request-status').hidden=true;
    const state=inputState();
    Object.entries(Q.services).filter(([key])=>key!=='photo').forEach(([key,service])=>{
      get('service-'+key).checked=selected.has(key);
      try {const alone=Q.calculate({...state,selected:key==='views360'?['platform','mp','views360']:[key],delivery:'files',licenseType:'term',licenseMonths:6,hostingMonths:6,hostingPrepaid:false});const line=alone.lines.find(line=>line.key===key);get('price-'+key).textContent=key==='platform'?'$49 / month':(line.from?'From ':'')+Q.money(line.cents);}
      catch(_){get('price-'+key).textContent='—';}
    });
    get('quote-address').textContent=addressText();get('summary-property').textContent=addressText()+' · '+cat.label;
    try {
      current=Q.calculate(state);
      get('hosting-calculation').textContent=current.hostingExtraMonths?Q.money(current.hostingCents)+' for '+current.hostingExtraMonths+' additional months '+(current.hostingPrepaid?'paid in advance.':'billed at '+Q.money(current.hostingMonthlyCents)+' per month.'):'First six months included · No additional hosting charge.';
      get('photography-price').textContent=Q.money(current.photographyCents);
      get('photo-addition').textContent=current.extraPhotos?current.extraPhotos+' additional photos · '+Q.money(current.extraPhotoCents):'No additional photography charge.';
      get('license-calculation').textContent=Q.money(current.licenseBaseCents)+' in photography, aerial images and video × '+(state.licenseType==='unlimited'?'50%':('30% ÷ 12 × '+Math.max(0,Number(state.licenseMonths)-6)+' additional months'))+' = '+Q.money(current.licenseCents)+(state.licenseType==='term'?'. First six months included.':'');
      get('estimate-error').hidden=true;get('review-estimate').disabled=false;
      get('summary-total').textContent=current.pending?'Custom Quote':Q.money(current.totalCents);get('estimate-inline-total').textContent=get('summary-total').textContent;
      get('summary-subtotal').hidden=!current.pending;get('summary-subtotal').textContent='Priced items: '+Q.money(current.subtotalCents)+'. Final total requires confirmation.';
      get('custom-quote-note').hidden=!current.pending;get('custom-quote-note').textContent=current.pendingReasons.join(' ');
      get('summary-lines').replaceChildren();
      current.lines.forEach(line=>{const li=document.createElement('li'),a=document.createElement('span'),b=document.createElement('span');a.textContent=line.label;b.textContent=line.included?'Included':line.cents===null?'To Quote':(line.from?'From ':'')+Q.money(line.cents);li.append(a,b);get('summary-lines').append(li);});
      get('summary-duration').textContent=current.knownMinutes?'About '+Q.durationRange(current.knownMinutes,current.knownMinutesMax):current.hasOnSite?'Confirmed With Your Appointment':'No On-Site Visit Required';
      const parts=[];for(const [key,label] of [['photographyMinutes','Photography'],['matterportMinutes','Matterport'],['videoMinutes','Video'],['droneMinutes','Drone / Aerial']])if(current[key])parts.push(label+': '+(key==='photographyMinutes'?Q.durationRange(current[key],current.photographyMinutesMax):Q.duration(current[key])));get('summary-breakdown').textContent=parts.join(' · ');
      get('estimate-inline-time').textContent='Time on site: '+get('summary-duration').textContent;
      get('summary-time-note').textContent=current.additionalCapture.length?'Time for '+current.additionalCapture.map(key=>Q.services[key].label).join(', ')+' is not included above and will be confirmed with your appointment. Layout and access can affect time on site.':'Allow for property layout, access and readiness. Times are approximate and rounded up to five minutes.';
      get('summary-time-note').textContent+=' Photography time reflects the selected category’s size range.';
      get('date-label').textContent=current.hasOnSite?'Preferred Shoot Date (Required For A Request)':'Preferred Completion Date (Required For A Request)';get('time-label').textContent=current.hasOnSite?'Preferred Start Time · Central Time':'Preferred Contact Time · Central Time';
    }catch(error){current=null;get('photo-addition').textContent='';get('hosting-calculation').textContent='';get('photography-price').textContent='—';get('license-calculation').textContent='';get('estimate-error').hidden=false;get('estimate-error').textContent=error.message;get('summary-total').textContent='Complete Your Selection';get('estimate-inline-total').textContent='—';get('summary-subtotal').hidden=true;get('summary-lines').replaceChildren();get('summary-duration').textContent='—';get('summary-breakdown').textContent='';get('summary-time-note').textContent='';get('estimate-inline-time').textContent='';get('custom-quote-note').hidden=true;get('review-estimate').disabled=true;}
  }
  get('open-estimate').addEventListener('click', () => {
    if (!validateFields(addressFields, true)) return;
    unlocked = true; syncAddress(); get('estimate-title').focus();
  });
  addressFields.forEach(field => { field.addEventListener('input', syncAddress); field.addEventListener('change', syncAddress); });
  agentFields.forEach(field => field.addEventListener('input', () => { validateField(field); get('request-status').hidden = true; }));
  form.querySelectorAll('[name=category]').forEach(radio => radio.addEventListener('change', () => {
    const cat = Q.categories[value('category')];
    get('photo-slider').min=cat.photoMin;
    get('photo-slider').max=cat.photoMax;
    get('photo-slider').value=cat.photosIncluded;
    if(Number(get('matterport-sqft').value)>Math.min(cat.max,20000))get('matterport-sqft').value=Math.min(cat.max,20000);
    update();
  }));

  ['photo-slider','matterport-sqft','video-duration','aerial-images','plan-sets','video-count','views360-count','license-months','platform-months','hosting-months'].forEach(id=>get(id).addEventListener('input',update));
  form.querySelectorAll('[name=licenseType],[name=delivery]').forEach(el=>el.addEventListener('change',update));
  get('hosting-prepaid').addEventListener('change',update);
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
  const details = () => Object.fromEntries(['first','last','company','email','phone','street','unit','propertyId','city','state','zip','optOut'].map(key => [key,value(key)]));
  function prepare(action = 'email_quote') {
    if (!unlocked || !validateFields(addressFields, true)) { syncAddress(); return null; }
    update(); if (!current) { get('estimate-title').focus(); return null; }
    if (!reviewed || !validateFields(agentFields, true)) return null;
    const date = get('shoot-date'), time = get('shoot-time'), now = centralNow(); date.min = now.date;
    date.setCustomValidity(''); time.setCustomValidity('');
    if (!date.checkValidity()) { window.SiteSeeValidation.show(date); return null; }
    if (!time.checkValidity()) { window.SiteSeeValidation.show(time); return null; }
    if (date.value === now.date && time.value <= now.time) { time.setCustomValidity('Choose a future time in Central Time.'); window.SiteSeeValidation.show(time); return null; }
    if (action === 'request_appointment' && !scheduling.validate()) return null;
    const appointment = { date: date.value, time: time.value, ...(action === 'request_appointment' ? scheduling.data() : {}) };
    return { quote: current, details: details(), appointment };
  }
  ['shoot-date','shoot-time'].forEach(id => get(id).addEventListener('input', () => { get(id).setCustomValidity(''); get('request-status').hidden = true; }));
  function showStatus(message, isError = false) {
    const status = get('request-status');
    status.textContent = message;
    status.classList.toggle('quote-status-error', isError);
    status.hidden = false;
  }
  // Keep completed actions in memory across Back/Forward cache restoration.
  const completedActions = new Map();
  let busyAction = '';
  function actionKey(action) {
    const snapshot = { state: inputState(), details: details(), date: get('shoot-date').value, time: get('shoot-time').value };
    if (action === 'request_appointment') snapshot.scheduling = scheduling.data();
    return action + ':' + JSON.stringify(snapshot);
  }
  function refreshActionButtons() {
    const buttons = [
      ['email-self', 'email_quote', 'Email My Quote To Me', 'Sending Email…', 'Email Sent ✓'],
      ['copy-quote', 'copy_quote', 'Copy Quote', 'Copying…', 'Copied ✓'],
      ['request-shoot', 'request_appointment', 'Request My Preferred Date', 'Sending Request…', 'Request Received ✓']
    ];
    for (const [id, action, ready, working, complete] of buttons) {
      const button = get(id), done = completedActions.has(actionKey(action));
      const state = busyAction === action ? 'working' : done ? 'complete' : 'ready';
      const label = state === 'working' ? working : state === 'complete' ? complete : ready;
      if (button.textContent !== label) button.textContent = label;
      button.dataset.actionState = state;
      button.setAttribute('aria-busy', String(busyAction === action));
      button.disabled = Boolean(busyAction) || (done && action !== 'copy_quote');
    }
  }
  function setSending(sending, action = '') {
    busyAction = sending ? action : '';
    refreshActionButtons();
  }
  function openReceipt(data) {
    const receipt = new URL('request-received.html', window.location.href);
    receipt.searchParams.set('reference', data.reference);
    if (typeof data.copy_sent === 'boolean') receipt.searchParams.set('copy', data.copy_sent ? 'sent' : 'not-sent');
    // Remove the submitted form from this history entry.
    window.location.replace(receipt.href);
  }
  async function submitToServer(action) {
    if (busyAction) return;
    try {
      const key = actionKey(action), previous = completedActions.get(key);
      if (previous) {
        if (action === 'request_appointment') openReceipt(previous);
        else showStatus('This quote has already been emailed. Reference: ' + previous.reference + '.');
        return;
      }
      if (action === 'request_appointment' && !window.SiteSeeValidation.validate(form)) return;
      const prepared = prepare(action); if (!prepared) return;
      const sentKey = actionKey(action), emailKey = actionKey('email_quote');
      setSending(true, action);
      showStatus(action === 'email_quote' ? 'Sending your quote…' : 'Sending your preferred-date request…');
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({
          version: 1,
          action,
          market: 'commercial',
          companyFax: form.elements.namedItem('company_fax').value,
          details: prepared.details,
          appointment: prepared.appointment,
          state: inputState()
        })
      });
      const data = await response.json().catch(() => ({ ok:false, message:'The server returned an unreadable response.' }));
      if (!response.ok || !data.ok) throw new Error(data.message || 'We could not send your quote.');
      if (data.action === action && /^[A-F0-9]{10}$/.test(data.reference || '')) {
        completedActions.set(sentKey, data);
        if (action === 'request_appointment') {
          if (data.copy_sent === true) completedActions.set(emailKey, data);
          openReceipt(data);
          return;
        }
      }
      showStatus(data.message + (data.reference ? ' Reference: ' + data.reference + '.' : ''));
    } catch (error) {
      showStatus(error.message || 'We could not send your quote. Please try again or email sales@sitesee.ai.', true);
    } finally {
      setSending(false);
    }
  }
  async function copyQuote() {
    if (busyAction) return;
    try {
      const prepared = prepare(); if (!prepared) return;
      const key = actionKey('copy_quote');
      const text = Q.emailBody(prepared.quote, prepared.details, prepared.appointment);
      completedActions.delete(key);
      setSending(true, 'copy_quote');
      try {
        await navigator.clipboard.writeText(text);
        completedActions.set(key, true);
        showStatus('Quote copied. Paste it into your email app when you are ready.');
      } catch (_) {
        const textarea = document.createElement('textarea'); textarea.readOnly = true; textarea.value = text; textarea.setAttribute('aria-label','Quote text to copy'); textarea.rows = 14; textarea.style.width = '100%';
        get('request-status').replaceChildren(document.createTextNode('Select and copy your quote below.'), textarea); get('request-status').hidden = false; textarea.focus(); textarea.select();
      }
    } catch (error) {
      showStatus(error.message || 'We could not prepare your quote. Please check your details and try again.', true);
    } finally {
      setSending(false);
    }
  }
  get('email-self').addEventListener('click', () => submitToServer('email_quote'));
  get('copy-quote').addEventListener('click', copyQuote);
  form.addEventListener('submit', event => { event.preventDefault(); submitToServer('request_appointment'); });
  get('open-estimate').disabled = false;
  // Back/forward restoration and autofill must be revalidated, never unlock by themselves.
  form.addEventListener('input', refreshActionButtons);
  form.addEventListener('change', refreshActionButtons);
  window.addEventListener('pageshow', () => { syncAddress(); refreshActionButtons(); });
  syncAddress();
  refreshActionButtons();
})();
