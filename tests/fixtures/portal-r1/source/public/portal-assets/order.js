/* Guided account ordering. Canonical engines estimate; the server validates and prices every submission. */
(() => {
  'use strict';
  const root = document.getElementById('portal-wizard');
  if (!root) return;
  const config=JSON.parse(root.dataset.config);
  const screen = root.querySelector('#sp-screen');
  const money = cents => SiteSeeQuote.money(cents);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const steps = ['Property type', 'Property details', 'Services & pricing', 'Scheduling & access', 'Review'];
  const draft = {
    market:'residential', residential:{category:'small',package:'custom',sqft:1500,selected:['photo'],matterportSqft:1500,videoSeconds:60,images:1},
    commercial:{category:'small',selected:['photo'],photoCount:30,aerialImages:5,videos:1,videoSeconds:60,plans:1,views360:1,matterportSqft:5000,platformMonths:6,hostingMonths:6,hostingPrepaid:false,delivery:'files',licenseType:'term',licenseMonths:6},
    details:{street:'',unit:'',city:'',state:'IL',zip:'',propertyId:'',first:'',last:'',company:'',email:'',phone:'',optOut:'No'},
    appointment:{date:'',time:'09:00',rushRequested:false,meetPhotographer:'Yes',accessType:'Lockbox',lockboxCode:'',keyLocation:'',specialRequests:'',mustHaveShots:'',onsiteDifferent:false,onsiteName:'',onsiteEmail:'',onsitePhone:'',additionalDifferent:false,additionalName:'',additionalEmail:'',additionalPhone:'',cancellationAccepted:false}
  };
  Object.assign(draft.details,config.details);
  if(config.seed){draft.market=config.seed.market;Object.assign(draft.details,config.seed.details);Object.assign(draft[draft.market],config.seed.state);}
  let step = config.seed ? 1 : 0, review=null, busy=false;
  const state = () => draft[draft.market];
  const engine = () => draft.market === 'residential' ? SiteSeeQuote : SiteSeeCommercialQuote;
  function quote() { try { return engine().calculate(state()); } catch (_) { return null; } }
  const crumb = label => `<div class="sp-crumb"><a href="/account.php">My Orders</a><span aria-hidden="true">/</span><span>${esc(label)}</span></div>`;
  function field(group, key, label, type = 'text', extra = '') {
    const obj = group === 'price' ? state() : draft[group];
    return `<label class="sp-field">${esc(label)}<input type="${type}" data-group="${group}" data-key="${key}" value="${esc(obj[key])}" ${extra}></label>`;
  }
  function check(group, key, label) {
    const obj = group === 'price' ? state() : draft[group];
    return `<label class="sp-check"><input type="checkbox" data-group="${group}" data-key="${key}" ${obj[key] ? 'checked' : ''}><span>${esc(label)}</span></label>`;
  }
  function choices(group, key, items) {
    const obj = group === 'root' ? draft : group === 'price' ? state() : draft[group];
    return `<div class="sp-grid">${items.map(([value,label,detail]) => `<label class="sp-choice"><input type="radio" name="${group}-${key}" data-group="${group}" data-key="${key}" value="${esc(value)}" ${obj[key] === value ? 'checked' : ''}><span><strong>${esc(label)}</strong>${detail ? `<small>${esc(detail)}</small>` : ''}</span></label>`).join('')}</div>`;
  }
  function total() {
    const q = review?.quote || quote();
    return `<div class="sp-total"><div>Estimated job total<small>Final scope and availability require SiteSee approval.${q?.platformMonthlyCents ? ' Residential platform: '+money(q.platformMonthlyCents)+'/month, separate from this total. No subscription is activated here.' : ''}</small></div><strong>${q ? money(q.totalCents) : '—'}</strong></div>`;
  }
  function lineItems(q) {
    return `<ul class="sp-lines">${q.packageCents ? `<li><span>${esc(q.package)} package</span><span>${money(q.packageCents)}</span></li>` : ''}${q.lines.map(l => `<li><span>${esc(l.label)}</span><span>${l.included ? 'Included' : money(l.cents)+(draft.market==='residential' && l.key==='platform' ? '/mo' : '')}</span></li>`).join('')}</ul>`;
  }
  function renderServices() {
    const s = state(), e = engine(), residential = draft.market === 'residential';
    const bundled = residential && s.package !== 'custom';
    const included = residential ? e.packages[s.package].includes : ['photo'];
    let html = '';
    if (residential) html += `<fieldset><legend>Package or individual services</legend>${choices('price','package',Object.entries(e.packages).map(([k,p])=>[k,p.label,p.cents ? money(p.cents)+' · '+p.photos : 'Choose services for your property']))}</fieldset>`;
    if (!bundled) html += `<fieldset><legend>Property category</legend>${choices('price','category',Object.entries(e.categories).map(([k,c])=>[k,c.label, residential ? c.min.toLocaleString()+'–'+c.max.toLocaleString()+' sq ft' : c.photosIncluded+' photos included']))}</fieldset>`;
    if (residential && !bundled) html += `<div class="sp-grid">${field('price','sqft','Property size · sq ft','number',`min="${e.categories[s.category].min}" max="${e.categories[s.category].max}" step="1"`)}</div>`;
    if (!residential) html += `<div class="sp-grid">${field('price','photoCount','Finished photographs','number',`min="${e.categories[s.category].photoMin}" max="${e.categories[s.category].photoMax}" step="1"`)}</div>`;
    html += '<h2>Services</h2>';
    Object.entries(e.services).forEach(([key, service]) => {
      const locked = key === 'photo' || included.includes(key);
      const selected = locked || s.selected.includes(key) || (!residential && key==='website' && s.delivery==='website');
      html += `<div class="sp-service"><label class="sp-check"><input type="checkbox" data-service="${key}" ${selected ? 'checked' : ''} ${locked ? 'disabled' : ''}><span>${esc(service.label)}${locked ? (included.includes(key) ? ' · Included' : ' · Required') : ''}<small>${esc(service.detail)}</small></span></label>`;
      let extra = '';
      if (selected && !locked) {
        if (key==='video') extra += field('price','videoSeconds','Video length · seconds','number','min="60" max="180" step="1"') + (!residential ? field('price','videos','Number of videos','number','min="1" max="20"') : '');
        if (key==='twilight') extra += field('price','images','Twilight images','number','min="1" max="100"');
        if (key==='mp' && (bundled || !residential)) extra += field('price','matterportSqft','Matterport area · sq ft','number',`min="1" max="${residential ? 10000 : Math.min(20000,e.categories[s.category].max)}"`);
        if (!residential && key==='platform') extra += field('price','platformMonths','Platform term · months','number','min="6" max="18"');
        if (!residential && key==='drone') extra += field('price','aerialImages','Finished aerial images','number','min="1" max="100"');
        if (!residential && key==='floor') extra += field('price','plans','Property layout sets','number','min="1" max="20"');
        if (!residential && key==='views360') extra += field('price','views360','Individual 360° views','number','min="1" max="100"');
      }
      if (extra) html += `<div class="sp-extra sp-grid">${extra}</div>`;
      html += '</div>';
    });
    if (!residential) {
      html += `<fieldset><legend>Media usage license</legend>${choices('price','licenseType',[['term','Fixed term','First six months included'],['unlimited','Unlimited','50% of eligible photography, aerial and video charges']])}</fieldset>`;
      if (s.licenseType==='term') html += `<div class="sp-grid">${field('price','licenseMonths','License term · months','number','min="6" max="18"')}</div>`;
      if (s.selected.includes('mp')) html += `<div class="sp-review"><h3>Matterport hosting</h3><p>Six months included. Additional months: $6.99 monthly or $4.99 paid in advance. The selected term is included in this estimate.</p>${check('price','hostingPrepaid','Pay hosting in advance')}<div class="sp-grid">${field('price','hostingMonths','Total hosting term · months','number','min="6" max="18"')}</div></div>`;
    }
    return html + '<p class="sp-note">Platform and hosting selections retain the existing pricing terms. No subscription is activated with this order.</p>';
  }
  function renderSchedule() {
    const a = draft.appointment;
    let html = `<div class="sp-grid">${field('appointment','date','Preferred date','date')}<label class="sp-field">Arrival window · Central<select data-group="appointment" data-key="time">${[['07:00','7–9 AM'],['09:00','9–11 AM'],['11:00','11 AM–1 PM'],['13:00','1–3 PM'],['15:00','3–5 PM'],['17:00','5–7 PM']].map(([v,l])=>`<option value="${v}" ${a.time===v?'selected':''}>${l}</option>`).join('')}</select></label></div>${check('appointment','rushRequested','Request rush scheduling · $59 if approved')}<p class="sp-note">Standard requests need 72 hours’ notice. Rush requests need at least 12 hours. The rush fee is added to the remaining balance only after approval. Availability is checked before review; your window still requires confirmation.</p><fieldset><legend>Will you meet the photographer?</legend>${choices('appointment','meetPhotographer',[['Yes','Yes, I’ll be there'],['No','No, use property access']])}</fieldset>`;
    if (a.meetPhotographer==='No') {
      html += `<fieldset><legend>Property access</legend>${choices('appointment','accessType',[['Lockbox','Lockbox'],['Key','Key pickup']])}</fieldset>`;
      html += a.accessType==='Lockbox' ? field('appointment','lockboxCode','Lockbox code · 10 digits','text','inputmode="numeric" maxlength="10"') : field('appointment','keyLocation','Key location','text','maxlength="150"');
    }
    html += `<div class="sp-grid">${field('appointment','specialRequests','Special requests · optional','text','maxlength="250"')}${field('appointment','mustHaveShots','Must-have shots · optional','text','maxlength="250"')}</div>${check('appointment','onsiteDifferent','Someone else is the on-site contact')}`;
    if (a.onsiteDifferent) html += `<div class="sp-grid">${field('appointment','onsiteName','On-site contact name')}${field('appointment','onsiteEmail','On-site email','email')}${field('appointment','onsitePhone','On-site phone','tel')}</div>`;
    html += check('appointment','additionalDifferent','Add another contact');
    if (a.additionalDifferent) html += `<div class="sp-grid">${field('appointment','additionalName','Additional contact name')}${field('appointment','additionalEmail','Additional email','email')}${field('appointment','additionalPhone','Additional phone · optional','tel')}</div>`;
    html += `<div class="sp-review"><h3>Your contact information</h3><div class="sp-grid">${field('details','first','First name')}${field('details','last','Last name')}${field('details','company','Company')}${field('details','phone','Phone','tel')}</div><p>Account email: ${esc(draft.details.email)}</p></div>${check('appointment','cancellationAccepted','I agree to the cancellation policy.')}<p class="sp-note">At least 24 hours before the appointment: paid deposit refunded. Under 24 hours: deposit credited toward one rescheduled shoot. Refunds and credits require processing.</p>`;
    return html + check('details','optOutFlag','Exclude me from mailing lists');
  }
  function renderReview() {
    const q = review?.quote || quote(), d=draft.details, a=draft.appointment;
    return `<section class="sp-review"><header><h2>Property</h2><button type="button" class="sp-link" data-step="1">Edit property</button></header><p>${esc(d.street)}${d.unit ? ', '+esc(d.unit) : ''}\n${esc(d.city)}, ${esc(d.state)} ${esc(d.zip)}</p></section><section class="sp-review"><header><h2>Services & pricing</h2><button type="button" class="sp-link" data-step="2">Edit services</button></header>${q ? lineItems(q) : '<p>Return to services to complete your selection.</p>'}</section><section class="sp-review"><header><h2>Appointment request</h2><button type="button" class="sp-link" data-step="3">Edit schedule</button></header><p>${esc(a.date)} · ${esc(a.time)}${review?.appointment?.windowEnd?'–'+esc(review.appointment.windowEnd):''} Central\n${a.rushRequested ? 'Rush requested · $59 only if approved, not collected with the deposit' : 'Standard scheduling'}\n${a.meetPhotographer==='Yes' ? 'You will meet the photographer.' : 'Access: '+esc(a.accessType)}</p><p>Your requested date is not confirmed until SiteSee approves the appointment.</p></section><section class="sp-review"><h2>What happens next</h2><p>Pay the test deposit, then SiteSee reviews scope, price, availability, and any rush request. A confirmed calendar invitation follows approval.</p><p>No live charges or subscriptions are created.</p></section>`;
  }
  function renderNew() {
    let content='';
    if(step===0) content=choices('root','market',[['residential','Residential','Homes, condos, and residential listings'],['commercial','Commercial','Retail, office, warehouse, and industrial properties']]);
    if(step===1) content=`<div class="sp-grid"><div class="sp-full">${field('details','street','Street address','text','autocomplete="street-address" maxlength="180"')}</div>${field('details','unit','Unit / suite · optional','text','maxlength="100"')}${field('details','propertyId','Property ID · optional','text','maxlength="80"')}${field('details','city','City','text','maxlength="100"')}${field('details','state','State · two-letter code','text','maxlength="2"')}${field('details','zip','ZIP code','text','inputmode="numeric" maxlength="10"')}</div>`;
    if(step===2) content=renderServices();
    if(step===3) content=renderSchedule();
    if(step===4) content=renderReview()+`<p class="notice">${esc(review?.availability?.message || 'SiteSee will review availability.')}</p>`;
    return `${crumb('New Order')}<div class="sp-progress" aria-label="Step ${step+1} of 5">${steps.map((_,i)=>`<span class="${i<=step?'sp-done':''}"></span>`).join('')}</div><p class="sp-step">Step ${step+1} of 5 · ${steps[step]}</p><h2 tabindex="-1" id="wizard-heading">${['What type of property?','Where is the property?','Choose your services','Plan your appointment','Review your order'][step]}</h2><p class="sp-lead">${['Start with the property you’re preparing to market.','We’ll use this address for the appointment.','Choose a package or build the media collection you need.','Choose your preferred window and tell us how to access the property.','Check the details before continuing to the test deposit.'][step]}</p><form class="sp-work" id="sp-form" novalidate>${content}${step>=2?total():''}<p class="sp-error" id="sp-error" role="alert" hidden></p><div class="sp-actions"><button type="button" data-back>${step===0?'My Orders':'Back'}</button><button type="submit" class="sp-primary">${step===4?'Place order & continue to deposit':'Next →'}</button></div></form>`;
  }
  function validateStep() {
    const d=draft.details,a=draft.appointment;
    if(step===1 && (!d.street.trim()||!d.city.trim()||!(/^[A-Za-z]{2}$/).test(d.state)||!(/^\d{5}(?:-\d{4})?$/).test(d.zip))) throw Error('Enter the street, city, two-letter state, and ZIP code.');
    if(step===2) engine().calculate(state());
    if(step===3) {
      if(!a.date) throw Error('Choose a preferred date.');
      if(!d.first.trim()||!d.last.trim()||!d.company.trim()||d.phone.replace(/\D/g,'').length<10) throw Error('Complete your name, company, and phone number.');
      if(a.meetPhotographer==='No' && (a.accessType==='Lockbox' ? !(/^\d{10}$/).test(a.lockboxCode) : !a.keyLocation.trim())) throw Error(a.accessType==='Lockbox'?'Enter the 10-digit lockbox code.':'Enter the key pickup location.');
      if(a.onsiteDifferent && (!a.onsiteName.trim()||!a.onsiteEmail.includes('@')||a.onsitePhone.replace(/\D/g,'').length<10)) throw Error('Complete the on-site contact information.');
      if(a.additionalDifferent && (!a.additionalName.trim()||!a.additionalEmail.includes('@'))) throw Error('Complete the additional contact name and email.');
      if(!a.cancellationAccepted) throw Error('Accept the cancellation policy to continue.');
    }
  }
  async function api(action, data={}) {
    const body=new URLSearchParams({csrf:config.csrf,action,...data});
    const send=()=>fetch('/account.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body});
    let response=await send();
    if(response.status===403){
      const refresh=await fetch('/account.php?view=session',{credentials:'same-origin',cache:'no-store'});
      const session=await refresh.json();
      if(!refresh.ok)throw Error(session.error);
      if(session.email!==config.details.email)throw Error('Sign in with the original account in another tab, then retry. Your answers are still here.');
      config.csrf=session.csrf;body.set('csrf',config.csrf);response=await send();
    }
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Please sign in again in another tab, then retry this order.');
    const result=await response.json();if(!response.ok)throw Error(result.error||'Please try again.');return result;
  }
  function render(focus=false) {
    screen.innerHTML=renderNew();
    if(focus)screen.querySelector('#wizard-heading').focus();
  }
  function error(message){const el=screen.querySelector('#sp-error');el.textContent=message;el.hidden=false;el.tabIndex=-1;el.focus();}
  root.addEventListener('click',event=>{
    const b=event.target.closest('button');if(!b||busy)return;
    if(b.hasAttribute('data-step')){step=Number(b.dataset.step);review=null;render(true);}
    if(b.hasAttribute('data-back')){if(step>0){step--;review=null;render(true);}else location.assign('/account.php');}
  });
  function saveInput(input){
    const group=input.dataset.group,key=input.dataset.key;if(!group||!key)return;
    const obj=group==='root'?draft:group==='price'?state():draft[group];
    obj[key]=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value;
    if(key==='optOutFlag')draft.details.optOut=input.checked?'Yes':'No';
    review=null;
  }
  draft.details.optOutFlag=draft.details.optOut==='Yes';
  root.addEventListener('input',event=>{saveInput(event.target);if(event.target.dataset.group==='price'){const current=screen.querySelector('.sp-total');if(current)current.outerHTML=total();}});
  root.addEventListener('change',event=>{
    const input=event.target;
    if(input.dataset.service){const chosen=new Set(state().selected);input.checked?chosen.add(input.dataset.service):chosen.delete(input.dataset.service);state().selected=[...chosen];if(draft.market==='commercial' && input.dataset.service==='website')state().delivery=input.checked?'website':'files';review=null;render();return;}
    saveInput(input);
    if(input.dataset.group==='price' && input.dataset.key==='category'){
      const cat=engine().categories[state().category];if(draft.market==='residential')state().sqft=Math.min(cat.max,Math.max(cat.min,state().sqft));else state().photoCount=cat.photosIncluded;
    }
    if(input.type==='radio'||input.type==='checkbox'){
      const group=input.dataset.group,key=input.dataset.key;render();
      if(group&&key)screen.querySelector(`[data-group="${group}"][data-key="${key}"]`)?.focus();
    }
  });
  root.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;
    try{
      validateStep();
      if(step<3){step++;render(true);return;}
      busy=true;screen.querySelectorAll('button').forEach(b=>b.disabled=true);
      if(step===3){
        review=await api('review_order',{payload:JSON.stringify({market:draft.market,state:state(),details:draft.details,appointment:draft.appointment})});
        step=4;render(true);
      }else{
        if(!review)throw Error('Return to scheduling and review your order again.');
        const result=await api('submit_order',{review:review.review});
        location.assign('/account.php?view=payment&reference='+encodeURIComponent(result.reference));
      }
    }catch(e){error(e.message);}
    finally{busy=false;screen.querySelectorAll('button').forEach(b=>b.disabled=false);}
  });
  render();
})();
