/* Design preview only. Reuses canonical quote engines; never calls a server. */
(() => {
  'use strict';
  const root = document.getElementById('sitesee-portal-preview');
  const screen = root.querySelector('#sp-screen');
  const money = cents => SiteSeeQuote.money(cents);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const steps = ['Property type', 'Property details', 'Services & pricing', 'Scheduling & access', 'Review'];
  const draft = {
    market:'residential', residential:{category:'small',package:'custom',sqft:1500,selected:['photo'],matterportSqft:1500,videoSeconds:60,images:1},
    commercial:{category:'small',selected:['photo'],photoCount:30,aerialImages:5,videos:1,videoSeconds:60,plans:1,views360:1,matterportSqft:5000,platformMonths:6,hostingMonths:6,hostingPrepaid:false,delivery:'files',licenseType:'term',licenseMonths:6},
    details:{street:'',unit:'',city:'',state:'IL',zip:'',propertyId:'',first:'Jordan',last:'Example',company:'Example Realty',email:'jordan@example.com',phone:'',optOut:'Yes'},
    appointment:{date:'',time:'09:00',rushRequested:false,meetPhotographer:'Yes',accessType:'Lockbox',lockbox:'',keyLocation:'',specialRequests:'',mustHaveShots:'',onsiteDifferent:false,onsiteName:'',onsiteEmail:'',onsitePhone:'',additionalDifferent:false,additionalName:'',additionalEmail:'',additionalPhone:'',cancellationAccepted:false}
  };
  let view = 'orders', step = 0, past = false, example = 'current';
  const emptyAppointment = structuredClone(draft.appointment);
  const defaultResidential = structuredClone(draft.residential);
  const defaultCommercial = structuredClone(draft.commercial);
  const state = () => draft[draft.market];
  const engine = () => draft.market === 'residential' ? SiteSeeQuote : SiteSeeCommercialQuote;
  function quote() { try { return engine().calculate(state()); } catch (_) { return null; } }
  const crumb = label => `<div class="sp-crumb"><button class="sp-link" type="button" data-view="orders">My Orders</button><span aria-hidden="true">/</span><span>${esc(label)}</span></div>`;
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
    const q = quote();
    return `<div class="sp-total"><div>Estimated job total<small>Final scope and availability require SiteSee approval.${q?.platformMonthlyCents ? ' Residential platform: '+money(q.platformMonthlyCents)+'/month, separate from this total. No subscription is activated here.' : ''}</small></div><strong>${q ? money(q.totalCents) : '—'}</strong></div>`;
  }
  function lineItems(q) {
    return `<ul class="sp-lines">${q.packageCents ? `<li><span>${esc(q.package)} package</span><span>${money(q.packageCents)}</span></li>` : ''}${q.lines.map(l => `<li><span>${esc(l.label)}</span><span>${l.included ? 'Included' : money(l.cents)+(draft.market==='residential' && l.key==='platform' ? '/mo' : '')}</span></li>`).join('')}</ul>`;
  }
  function renderOrders() {
    return `<h1>My Orders</h1><p class="sp-lead">Your properties, appointments, and payments in one place.</p><div class="sp-tabs" aria-label="Order history"><button type="button" data-history="current" aria-pressed="${!past}">Current orders</button><button type="button" data-history="past" aria-pressed="${past}">Past orders & receipts</button></div>${past ? `<article class="sp-order"><div><h2>28 Sample Avenue</h2><p>Chicago, IL · Residential photography</p><span class="sp-status">Appointment cancelled</span><p>September 17 · View payment and credit details inside</p></div><button type="button" data-example="past">View order →</button></article>` : `<article class="sp-order"><div><h2>214 Example Lane</h2><p>Chicago, IL · Gold package</p><span class="sp-status">Appointment confirmed</span><p>October 6 · 9–11 AM Central</p></div><button type="button" data-example="current">View order →</button></article><article class="sp-order"><div><h2>805 Demonstration Court</h2><p>Chicago, IL · Commercial photography</p><span class="sp-status">Deposit received · Awaiting confirmation</span><p>Requested October 8 · SiteSee is reviewing your appointment</p></div><button type="button" data-example="review">View order →</button></article>`}<p class="sp-note">Invoices and receipts are kept with each order.</p>`;
  }
  function renderDetail() {
    const isPast = example === 'past', pending = example === 'review';
    const address = isPast ? '28 Sample Avenue' : pending ? '805 Demonstration Court' : '214 Example Lane';
    return `${crumb('Order details')}<h1>${address}</h1><p class="sp-lead">Chicago, IL · ${pending ? 'Commercial photography' : isPast ? 'Residential photography' : 'Gold package'}</p><section class="sp-work"><div class="sp-review"><h2>${isPast ? 'Appointment cancelled' : pending ? 'Waiting for SiteSee confirmation' : 'Your appointment'}</h2><p>${isPast ? 'Your former appointment is no longer reserved.' : pending ? 'Your test deposit was received. SiteSee still needs to approve the request and confirm the calendar appointment.' : 'Tuesday, October 6 · 9–11 AM Central\nYour appointment is confirmed.'}</p>${!isPast && !pending ? '<div class="sp-actions"><button type="button" data-view="reschedule">Change appointment</button><button class="sp-link" type="button" data-view="cancel">Cancel appointment</button></div>' : ''}</div><div class="sp-review"><h2>Payment & documents</h2><ul class="sp-lines"><li><span>${pending ? 'Quoted' : 'Approved'} job total</span><span>${pending ? '$750.00' : isPast ? '$150.00' : '$499.00'}</span></li><li><span>Test deposit received</span><span>${pending ? '$375.00' : isPast ? '$75.00' : '$249.50'}</span></li><li><span>${isPast ? 'Cancellation adjustment' : 'Remaining balance'}</span><span>${isPast ? 'Awaiting review' : pending ? '$375.00' : '$249.50'}</span></li></ul><p>${isPast ? 'Any refund or credit is reviewed separately. Cancellation does not automatically issue a refund.' : pending ? 'Balance payment becomes available after the required approval.' : 'Pay the approved balance when it becomes due.'}</p><div class="sp-actions">${!isPast && !pending ? '<button type="button" data-view="balance">Pay balance · TEST</button>' : ''}<button type="button" class="sp-link" data-view="receipt">View receipt</button><button type="button" class="sp-link" data-view="billing">Billing details</button></div></div><div class="sp-review"><h2>Services</h2><p>${pending ? 'Commercial photography · 30 photos' : isPast ? 'Residential photography' : '35 HDR photos · Property website\n1-minute property video · 2D floor plan'}</p></div><div class="sp-actions"><button type="button" data-reorder>Order again</button><span class="sp-note">Review current pricing and choose a new date.</span></div></section>`;
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
    html += '<h2 style="margin-top:28px">Services</h2>';
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
      html += `<fieldset style="margin-top:24px"><legend>Media usage license</legend>${choices('price','licenseType',[['term','Fixed term','First six months included'],['unlimited','Unlimited','50% of eligible photography, aerial and video charges']])}</fieldset>`;
      if (s.licenseType==='term') html += `<div class="sp-grid">${field('price','licenseMonths','License term · months','number','min="6" max="18"')}</div>`;
      if (s.selected.includes('mp')) html += `<div class="sp-review"><h3>Matterport hosting</h3><p>Six months included. Additional months: $6.99 monthly or $4.99 paid in advance. The selected term is included in this estimate.</p>${check('price','hostingPrepaid','Pay hosting in advance')}<div class="sp-grid">${field('price','hostingMonths','Total hosting term · months','number','min="6" max="18"')}</div></div>`;
    }
    return html + '<p class="sp-note">Platform and hosting selections retain the existing pricing terms. This preview does not activate subscriptions.</p>';
  }
  function renderSchedule() {
    const a = draft.appointment;
    let html = `<div class="sp-grid">${field('appointment','date','Preferred date','date')}<label class="sp-field">Arrival window · Central<select data-group="appointment" data-key="time">${[['07:00','7–9 AM'],['09:00','9–11 AM'],['11:00','11 AM–1 PM'],['13:00','1–3 PM'],['15:00','3–5 PM'],['17:00','5–7 PM']].map(([v,l])=>`<option value="${v}" ${a.time===v?'selected':''}>${l}</option>`).join('')}</select></label></div>${check('appointment','rushRequested','Request rush scheduling · $59 if approved')}<p class="sp-note">Standard requests need 72 hours’ notice. Rush requests need at least 12 hours. The rush fee is added to the remaining balance only after approval. Availability is illustrative in this preview.</p><fieldset><legend>Will you meet the photographer?</legend>${choices('appointment','meetPhotographer',[['Yes','Yes, I’ll be there'],['No','No, use property access']])}</fieldset>`;
    if (a.meetPhotographer==='No') {
      html += `<fieldset><legend>Property access</legend>${choices('appointment','accessType',[['Lockbox','Lockbox'],['Key','Key pickup']])}</fieldset>`;
      html += a.accessType==='Lockbox' ? field('appointment','lockbox','Lockbox code · 10 digits','text','inputmode="numeric" maxlength="10"') : field('appointment','keyLocation','Key location','text','maxlength="150"');
    }
    html += `<div class="sp-grid" style="margin-top:22px">${field('appointment','specialRequests','Special requests · optional','text','maxlength="250"')}${field('appointment','mustHaveShots','Must-have shots · optional','text','maxlength="250"')}</div>${check('appointment','onsiteDifferent','Someone else is the on-site contact')}`;
    if (a.onsiteDifferent) html += `<div class="sp-grid">${field('appointment','onsiteName','On-site contact name')}${field('appointment','onsiteEmail','On-site email','email')}${field('appointment','onsitePhone','On-site phone','tel')}</div>`;
    html += check('appointment','additionalDifferent','Add another contact');
    if (a.additionalDifferent) html += `<div class="sp-grid">${field('appointment','additionalName','Additional contact name')}${field('appointment','additionalEmail','Additional email','email')}${field('appointment','additionalPhone','Additional phone · optional','tel')}</div>`;
    html += `<div class="sp-review"><h3>Your contact information</h3><div class="sp-grid">${field('details','first','First name')}${field('details','last','Last name')}${field('details','company','Company')}${field('details','phone','Phone','tel')}</div><p style="margin-top:12px">Account email: ${esc(draft.details.email)}</p></div>${check('appointment','cancellationAccepted','I agree to the cancellation policy.')}<p class="sp-note">At least 24 hours before the appointment: paid deposit refunded. Under 24 hours: deposit credited toward one rescheduled shoot. Refunds and credits require processing.</p>`;
    return html;
  }
  function renderReview() {
    const q = quote(), d=draft.details, a=draft.appointment;
    return `<section class="sp-review"><header><h2>Property</h2><button type="button" class="sp-link" data-step="1">Edit property</button></header><p>${esc(d.street)}${d.unit ? ', '+esc(d.unit) : ''}\n${esc(d.city)}, ${esc(d.state)} ${esc(d.zip)}</p></section><section class="sp-review"><header><h2>Services & pricing</h2><button type="button" class="sp-link" data-step="2">Edit services</button></header>${q ? lineItems(q) : '<p>Return to services to complete your selection.</p>'}</section><section class="sp-review"><header><h2>Appointment request</h2><button type="button" class="sp-link" data-step="3">Edit schedule</button></header><p>${esc(a.date)} · ${esc(a.time)} Central\n${a.rushRequested ? 'Rush requested · $59 only if approved, not collected with the deposit' : 'Standard scheduling'}\n${a.meetPhotographer==='Yes' ? 'You will meet the photographer.' : 'Access: '+esc(a.accessType)}</p><p>Your requested date is not confirmed until SiteSee approves the appointment.</p></section><section class="sp-review"><h2>What happens next</h2><p>Pay the test deposit, then SiteSee reviews scope, price, availability, and any rush request. A confirmed calendar invitation follows approval.</p><p>No live charges or subscriptions are created.</p></section>`;
  }
  function renderNew() {
    let content='';
    if(step===0) content=choices('root','market',[['residential','Residential','Homes, condos, and residential listings'],['commercial','Commercial','Retail, office, warehouse, and industrial properties']]);
    if(step===1) content=`<div class="sp-grid"><div class="sp-full">${field('details','street','Street address','text','autocomplete="street-address" maxlength="180"')}</div>${field('details','unit','Unit / suite · optional','text','maxlength="100"')}${field('details','propertyId','Property ID · optional','text','maxlength="80"')}${field('details','city','City','text','maxlength="100"')}${field('details','state','State · two-letter code','text','maxlength="2"')}${field('details','zip','ZIP code','text','inputmode="numeric" maxlength="10"')}</div>`;
    if(step===2) content=renderServices();
    if(step===3) content=renderSchedule();
    if(step===4) content=renderReview();
    return `${crumb('New Order')}<div class="sp-progress" aria-label="Step ${step+1} of 5">${steps.map((_,i)=>`<span class="${i<=step?'sp-done':''}"></span>`).join('')}</div><p class="sp-step">Step ${step+1} of 5 · ${steps[step]}</p><h1>${['What type of property?','Where is the property?','Choose your services','Plan your appointment','Review your order'][step]}</h1><p class="sp-lead">${['Start with the property you’re preparing to market.','We’ll use this address for the appointment.','Choose a package or build the media collection you need.','Choose your preferred window and tell us how to access the property.','Check the details before continuing to the test deposit.'][step]}</p><form class="sp-work" id="sp-form" novalidate>${content}${step>=2?total():''}<p class="sp-error" id="sp-error" role="alert" hidden></p><div class="sp-actions"><button type="button" data-back>${step===0?'My Orders':'Back'}</button><button type="submit" class="sp-primary">${step===4?'Continue to test deposit':'Next →'}</button></div></form>`;
  }
  function renderPayment(balance=false) {
    const q=quote(), amount=balance?24950:Math.ceil((q?.totalCents||0)/2);
    return `${crumb(balance?'Balance payment':'Test deposit')}<h1>${balance?'Pay your balance':'Your test deposit'}</h1><p class="sp-lead">${balance?'Complete payment for the approved scope.':'Your appointment still requires SiteSee confirmation after payment.'}</p><section class="sp-work"><div class="sp-total" style="border:0;margin:0;padding:0"><div>${balance?'Approved balance':'50% test deposit'}<small>Stripe TEST mode · No real charge</small></div><strong>${money(amount)}</strong></div><div class="sp-review"><h2>Secure payment</h2><p>The integrated Stripe payment form will appear here in the working portal. This design preview does not collect card details.</p></div><div class="sp-actions"><button type="button" ${balance?'data-view="detail"':'data-step="4"'}>Back</button><button type="button" class="sp-primary" data-view="confirmation">Preview confirmation</button></div></section>`;
  }
  function renderOther() {
    if(view==='account') return `${crumb('Account')}<h1>Account</h1><p class="sp-lead">Your sign-in and contact information.</p><section class="sp-work"><h2>Jordan Example</h2><p>jordan@example.com</p><p class="sp-note">Sign-in links verify your email. Historical orders require verified ownership before they appear here.</p><button type="button" data-view="signin">Preview sign-in</button></section>`;
    if(view==='signin') return `<h1>Welcome back</h1><p class="sp-lead">Sign in to see your orders and book another service.</p><form class="sp-work" data-login><label class="sp-field">Email address<input name="email" type="email" autocomplete="email" required placeholder="you@company.com"></label><div class="sp-actions"><span class="sp-note">No password to remember.</span><button class="sp-primary" type="submit">Email sign-in link</button></div></form>`;
    if(view==='check-email') return `<h1>Check your email</h1><p class="sp-lead">If this address has access, we’ll send a one-time sign-in link. It expires after 15 minutes.</p><p class="sp-note">Preview only: no email was sent.</p><button type="button" data-view="signin">Use another email</button>`;
    if(view==='confirmation') return `${crumb('Request received')}<h1>Your request is with SiteSee</h1><p class="sp-lead">This is the confirmation-screen preview.</p><section class="sp-work"><h2>Appointment awaiting confirmation</h2><p>After a verified test deposit, SiteSee reviews your price, requested window, and any rush request. Your calendar invitation confirms the appointment.</p><p class="sp-note">A browser return from Stripe alone will never mark an order paid.</p><button type="button" class="sp-primary" data-view="orders">Go to My Orders</button></section>`;
    if(view==='billing') return `${crumb('Billing details')}<h1>Billing details</h1><p class="sp-lead">Payment methods and billing information for this order.</p><section class="sp-work"><h2>Manage securely with Stripe</h2><p>The working portal will open the verified Stripe customer’s test billing session. It will include payment methods, billing details, and applicable invoices.</p><p class="sp-note">No administrative controls, new subscriptions, or live payments.</p><button type="button" data-view="detail">Back to order</button></section>`;
    if(view==='receipt') return `${crumb('Receipt')}<h1>Payment receipt</h1><p class="sp-lead">Receipts will come from the verified payment attached to this order.</p><section class="sp-work"><p>This preview contains no real payment documents.</p><button type="button" data-view="detail">Back to order</button></section>`;
    if(view==='reschedule') return `${crumb('Change appointment')}<h1>Choose a new window</h1><p class="sp-lead">Your current appointment stays reserved until the change is confirmed.</p><section class="sp-work"><div class="sp-grid">${field('appointment','date','New preferred date','date')}</div><p class="sp-note">Available windows will be checked through the existing SiteSee scheduling service.</p><button type="button" data-view="detail">Back to order</button></section>`;
    if(view==='cancel') return `${crumb('Cancel appointment')}<h1>Cancel this appointment?</h1><p class="sp-lead">Review the cancellation policy before confirming.</p><section class="sp-work"><p>At least 24 hours before the appointment: paid deposit refunded. Under 24 hours: deposit credited toward one rescheduled shoot.</p><p>Refund or credit processing is separate from releasing the appointment.</p><p class="sp-note">Cancellation is disabled in this preview.</p><button type="button" data-view="detail">Keep appointment</button></section>`;
    return '';
  }
  function render() {
    screen.innerHTML=view==='orders'?renderOrders():view==='detail'?renderDetail():view==='new'?renderNew():view==='payment'?renderPayment():view==='balance'?renderPayment(true):renderOther();
  }
  function validateStep() {
    const d=draft.details,a=draft.appointment;
    if(step===1 && (!d.street.trim()||!d.city.trim()||!(/^[A-Za-z]{2}$/).test(d.state)||!(/^\d{5}(?:-\d{4})?$/).test(d.zip))) throw Error('Enter the street, city, two-letter state, and ZIP code.');
    if(step===2) engine().calculate(state());
    if(step===3) {
      if(!a.date) throw Error('Choose a preferred date.');
      if(!d.first.trim()||!d.last.trim()||!d.company.trim()||d.phone.replace(/\D/g,'').length<10) throw Error('Complete your name, company, and phone number.');
      if(a.meetPhotographer==='No' && (a.accessType==='Lockbox' ? !(/^\d{10}$/).test(a.lockbox) : !a.keyLocation.trim())) throw Error(a.accessType==='Lockbox'?'Enter the 10-digit lockbox code.':'Enter the key pickup location.');
      if(a.onsiteDifferent && (!a.onsiteName.trim()||!a.onsiteEmail.includes('@')||a.onsitePhone.replace(/\D/g,'').length<10)) throw Error('Complete the on-site contact information.');
      if(a.additionalDifferent && (!a.additionalName.trim()||!a.additionalEmail.includes('@'))) throw Error('Complete the additional contact name and email.');
      if(!a.cancellationAccepted) throw Error('Accept the cancellation policy to continue.');
    }
  }
  root.addEventListener('click', event => {
    const b=event.target.closest('button');if(!b)return;
    if(b.dataset.view){view=b.dataset.view;render();}
    if(b.dataset.history){past=b.dataset.history==='past';render();}
    if(b.dataset.example){example=b.dataset.example;view='detail';render();}
    if(b.hasAttribute('data-step')){step=Number(b.dataset.step);view='new';render();}
    if(b.hasAttribute('data-back')){if(step>0)step--;else view='orders';render();}
    if(b.hasAttribute('data-reorder')){
      draft.market=example==='review'?'commercial':'residential';
      draft.residential=structuredClone(defaultResidential);draft.commercial=structuredClone(defaultCommercial);
      if(example==='current')draft.residential.package='gold';
      draft.details.street=example==='current'?'214 Example Lane':example==='review'?'805 Demonstration Court':'28 Sample Avenue';
      draft.details.unit='';draft.details.propertyId='';draft.details.city='Chicago';draft.details.state='IL';draft.details.zip='60601';
      draft.appointment=structuredClone(emptyAppointment);view='new';step=1;render();
    }
  });
  function saveInput(input){
    const group=input.dataset.group,key=input.dataset.key;if(!group||!key)return;
    const obj=group==='root'?draft:group==='price'?state():draft[group];
    obj[key]=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value;
  }
  root.addEventListener('input',event=>saveInput(event.target));
  root.addEventListener('change',event=>{
    const input=event.target;
    if(input.dataset.service){const chosen=new Set(state().selected);input.checked?chosen.add(input.dataset.service):chosen.delete(input.dataset.service);state().selected=[...chosen];if(draft.market==='commercial' && input.dataset.service==='website')state().delivery=input.checked?'website':'files';render();return;}
    saveInput(input);
    if(input.dataset.group==='price' && input.dataset.key==='category'){
      const cat=engine().categories[state().category];if(draft.market==='residential')state().sqft=Math.min(cat.max,Math.max(cat.min,state().sqft));else state().photoCount=cat.photosIncluded;
    }
    if(input.dataset.group==='price'||input.type==='radio'||input.type==='checkbox')render();
  });
  root.addEventListener('submit',event=>{
    event.preventDefault();
    if(event.target.hasAttribute('data-login')){if(event.target.reportValidity()){view='check-email';render();}return;}
    try{validateStep();if(step<4)step++;else view='payment';render();}
    catch(error){const target=screen.querySelector('#sp-error');target.textContent=error.message;target.hidden=false;}
  });
  render();
})();
