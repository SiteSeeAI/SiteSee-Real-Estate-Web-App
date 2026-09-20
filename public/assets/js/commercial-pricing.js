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
    if(key==='mp')controls=quantity('matterport-sqft','Area To Scan · Square Feet',1,5000,1,5000)+'<div class="quote-range-labels"><span>1 sq ft</span><span id="c-matterport-max">5,000 sq ft</span></div><p class="quote-note">Scan only the areas you need. Photography coverage follows the category selected above. Add individual 360° views for other spaces with a SiteSee platform subscription.</p>';
    if(key==='views360')controls=quantity('views360-count','Individual 360° Photos',1,100,1,1);
    if(key==='drone')controls=quantity('aerial-images','Finished Aerial Images',1,100,1,1);
    if(key==='floor')controls=quantity('plan-sets','Property Layout Sets',1,20,1,1);
    if(key==='video')controls=quantity('video-count','Number Of Finished Videos',1,20,1,1)+'<div class="quote-video-length"><span>Length Of Each Video</span><div class="quote-video-fields"><label for="c-video-minutes">Minutes<input id="c-video-minutes" type="number" min="1" max="3" step="1" value="1" required disabled></label><span aria-hidden="true">:</span><label for="c-video-seconds">Seconds<input id="c-video-seconds" type="number" min="0" max="59" step="1" value="0" required disabled></label></div></div><p class="quote-note">Your price updates with the finished video length. One-minute minimum; $500 minimum per video.</p>';
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
    return {category:value('category'),photoCount:get('photo-count').value,matterportSqft:get('matterport-sqft').value,views360:get('views360-count').value,selected:[...selected],videoSeconds:videoSeconds(),videos:get('video-count').value,aerialImages:get('aerial-images').value,delivery:value('delivery'),platformMonths:get('platform-months').value,plans:get('plan-sets').value,licenseType:value('licenseType'),licenseMonths:get('license-months').value,hostingMonths:get('hosting-months').value,hostingPrepaid:get('hosting-prepaid').checked};
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
    get('photo-count').min=get('photo-slider').min=cat.photoMin;
    get('photo-range-min').textContent=cat.photoMin+' photos';
    get('photo-inclusions').textContent=cat.photoMin+'–'+cat.photosIncluded+' photos included. Additional photos above '+cat.photosIncluded+' are '+Q.money(cat.extraPhotoCents)+' each. Maximum 100 total photos.';
    get('matterport-sqft').max=cat.max;
    get('matterport-max').textContent=cat.max.toLocaleString()+' sq ft';
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
    get('photo-count').value=get('photo-slider').value=cat.photosIncluded;
    if(Number(get('matterport-sqft').value)>cat.max)get('matterport-sqft').value=cat.max;
    update();
  }));

  get('photo-count').addEventListener('input',()=>{if(get('photo-count').validity.valid)get('photo-slider').value=get('photo-count').value;update();});
  get('photo-slider').addEventListener('input',()=>{get('photo-count').value=get('photo-slider').value;update();});
  ['matterport-sqft','video-minutes','video-seconds','aerial-images','plan-sets','video-count','views360-count','license-months','platform-months','hosting-months'].forEach(id=>get(id).addEventListener('input',update));
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
