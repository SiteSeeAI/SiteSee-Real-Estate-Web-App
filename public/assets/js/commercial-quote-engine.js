/* Commercial client rates. Internal production costs are deliberately excluded. */
(function (root, factory) {
  const engine = factory();
  if (typeof module === 'object' && module.exports) module.exports = engine;
  else root.SiteSeeCommercialQuote = engine;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const subject = 'Commercial SiteSee Real Estate Quote';
  const categories = {
    small: { label: 'Small Commercial / Retail', min: 1, max: 10000 },
    mid: { label: 'Warehouse / Office', min: 10000, max: 50000 },
    large: { label: 'Factory / Industrial', min: 50000, max: 1000000 }
  };
  const services = {
    photo: { label: 'Property Photography', detail: 'Interior and exterior photography calculated from property size.' },
    drone: { label: 'Drone / Aerial Photos', detail: '$42 per finished image. Show the building, grounds and access.' },
    video: { label: 'Commercial Property Video', detail: '$1,500 per finished 1–3 minute video, with edited 4K footage and licensed audio.' },
    mp: { label: 'Matterport 3D Experience', detail: '$0.10 per sq ft, $199 minimum. Six months of hosting included.*' },
    floor: { label: 'Schematic Floor Plans', detail: '$150 per property layout set, delivered in PDF / JPEG.' }
  };
  const money = cents => (cents / 100).toLocaleString('en-US', { style:'currency', currency:'USD' });
  const videoDuration = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2,'0');
  function duration(minutes) {
    const n = Math.ceil(minutes / 5) * 5, h = Math.floor(n / 60), m = n % 60;
    return n ? (h ? h + ' hr' + (h > 1 ? 's' : '') : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '') : 'Confirmed With Your Appointment';
  }
  function photographyCents(sqft) {
    return Math.round(sqft <= 10000 ? Math.max(35000, sqft * 7.5) : 75000 + (sqft - 10000) * .875);
  }
  function calculate(input) {
    const cat = categories[input.category], sqft = Number(input.sqft);
    if (!cat || !Number.isInteger(sqft) || sqft < cat.min || sqft > cat.max) throw new Error('Enter a whole-number property size within the selected commercial category.');
    const chosen = new Set(['photo', ...(input.selected || [])]);
    chosen.forEach(key => { if (!services[key]) throw new Error('Unknown commercial service.'); });
    const images = Number(input.aerialImages), videos = Number(input.videos), seconds = Number(input.videoSeconds), plans = Number(input.plans);
    if (chosen.has('drone') && (!Number.isInteger(images) || images < 1 || images > 100)) throw new Error('Choose 1–100 aerial images.');
    if (chosen.has('video') && (!Number.isInteger(videos) || videos < 1 || videos > 20 || !Number.isInteger(seconds) || seconds < 60 || seconds > 180)) throw new Error('Choose 1–20 videos and a length from 1:00 to 3:00.');
    if (chosen.has('floor') && (!Number.isInteger(plans) || plans < 1 || plans > 20)) throw new Error('Choose 1–20 property layout sets.');
    const fees = { photo:photographyCents(sqft), drone:images * 4200, video:videos * 150000, mp:Math.max(19900,sqft * 10), floor:plans * 15000 };
    const reasons = [];
    const lines = [...chosen].map(key => {
      let label = services[key].label;
      if (key === 'drone') label += ' · ' + images + (images === 1 ? ' image' : ' images');
      if (key === 'video') label += ' · ' + videos + (videos === 1 ? ' video' : ' videos') + ' · ' + videoDuration(seconds) + ' each';
      if (key === 'floor') label += ' · ' + plans + (plans === 1 ? ' layout set' : ' layout sets');
      return {key,label,cents:fees[key],included:false,from:false};
    });
    const delivery = input.delivery || 'files';
    if (!['files','website','platform'].includes(delivery)) throw new Error('Choose how you would like your property media delivered.');
    const platformMonths = Number(input.platformMonths === undefined ? 6 : input.platformMonths);
    if (delivery === 'platform' && (!Number.isInteger(platformMonths) || platformMonths < 6 || platformMonths > 18)) throw new Error('Choose a platform term from 6 to 18 months.');
    if (delivery === 'website') lines.push({key:'website',label:'Property Website',cents:18500,included:false});
    if (delivery === 'platform') {
      lines.push({key:'platform-setup',label:'SiteSee Platform · Property Setup',cents:18500,included:false});
      lines.push({key:'platform-term',label:'SiteSee Platform · '+platformMonths+' Months at $25 / Month',cents:platformMonths*2500,included:false});
    }
    let subtotal = lines.reduce((sum,line) => sum + line.cents,0);
    const licenseEligible = true;
    const licenseType = input.licenseType || 'term', months = Number(input.licenseMonths === undefined ? 6 : input.licenseMonths);
    if (!['term','unlimited'].includes(licenseType)) throw new Error('Choose a license term or an unlimited photography license.');
    if (licenseType === 'term' && (!Number.isInteger(months) || months < 6 || months > 18)) throw new Error('Choose a license term from 6 to 18 months.');
    const photoBase = fees.photo + (chosen.has('drone') ? fees.drone : 0);
    const licenseCents = Math.round(licenseType === 'unlimited' ? photoBase / 2 : photoBase * months / 40);
    const licenseLabel = licenseType === 'unlimited' ? 'Unlimited Photography License' : months + '-Month Photography License';
    lines.push({key:'license',label:licenseLabel,cents:licenseCents,included:false});
    subtotal += licenseCents;
    if (chosen.has('video')) reasons.push('Video licensing is discussed separately; the photography license applies only to still and aerial images.');
    if (chosen.has('mp') && input.extendedHosting) reasons.push('Matterport hosting beyond the included six months requires a separate agreement.');
    const pending = reasons.length > 0;
    // Same scan-density estimate supplied for residential; commercial access is confirmed separately.
    const matterportMinutes = chosen.has('mp') ? sqft * 9 / 1000 : 0;
    const unestimated = ['photo','drone','video','floor'].filter(key => chosen.has(key));
    return { market:'commercial',sqft,category:cat.label,package:'Individual Commercial Services',packageCents:0,lines,subtotalCents:subtotal,totalCents:pending?null:subtotal,pending,pendingReasons:reasons,licenseEligible,licenseLabel,licenseMonths:licenseType==='term'?months:null,licenseCents,matterportIncludedMonths:chosen.has('mp')?6:0,extendedHosting:chosen.has('mp')&&!!input.extendedHosting,delivery,platformMonths:delivery==='platform'?platformMonths:null,photographyCents:fees.photo,licenseBaseCents:photoBase,photographyMinutes:0,matterportMinutes,knownMinutes:Math.ceil(matterportMinutes/5)*5,additionalCapture:unestimated,hasOnSite:[...chosen].some(key=>key!=='website'),services:[...chosen] };
  }
  function emailBody(quote,details,appointment) {
    const lines=[subject,'','Agent: '+details.first+' '+details.last,'Company: '+details.company,'Email: '+details.email,'Phone: '+details.phone,'Property: '+details.street+', '+details.city+', '+details.state+' '+details.zip,'Size: '+quote.sqft.toLocaleString()+' sq ft','Category: '+quote.category];
    quote.lines.forEach(line=>lines.push(line.label+': '+(line.included?'Included':line.cents===null?'To Be Quoted':(line.from?'From ':'')+money(line.cents))));
    lines.push('Delivery: '+({files:'Media Files Only',website:'Property Website',platform:'SiteSee Platform'}[quote.delivery]));
    if (quote.delivery === 'platform') lines.push('Platform pricing is proposed for review; setup and the selected term are included in this estimate. Matterport hosting is separate.');
    lines.push('','Estimated total: '+(quote.pending?'Custom Quote — priced items '+money(quote.subtotalCents):money(quote.totalCents)));
    quote.pendingReasons.forEach(reason=>lines.push(reason));
    if (quote.matterportIncludedMonths) lines.push('Matterport hosting: six months included. Photography licensing does not extend hosting or grant unlimited Matterport use.');
    lines.push('Known on-site time: '+duration(quote.knownMinutes));
    if(quote.additionalCapture.length)lines.push('Time to confirm for: '+quote.additionalCapture.map(key=>services[key].label).join(', '));
    if(appointment)lines.push('Preferred date: '+appointment.date,'Preferred time: '+appointment.time+' Central Time','Appointment requested, not confirmed.');
    lines.push('Exclude from mailing lists: '+details.optOut,'','Final property scope, licensing and appointment availability are confirmed by SiteSee.');
    return lines.join('\n');
  }
  return {subject,categories,services,money,videoDuration,duration,photographyCents,calculate,emailBody};
});
