/* Commercial client rates. Internal production costs are deliberately excluded. */
(function (root, factory) {
  const engine = factory();
  if (typeof module === 'object' && module.exports) module.exports = engine;
  else root.SiteSeeCommercialQuote = engine;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const subject = 'Commercial SiteSee Real Estate Quote';
  const categories = {
    small: { label: 'Small Commercial / Retail', min: 1, max: 9999, cents: 75000 },
    mid: { label: 'Warehouse / Office', min: 10000, max: 49999, cents: 120000 },
    large: { label: 'Factory / Industrial', min: 50000, max: 1000000, cents: 250000 }
  };
  const services = {
    photo: { label: 'Commercial Property Photography', detail: 'Interior and exterior coverage for one property. Scope determines the photography tier.' },
    hourly: { label: 'Hourly Creative Labor', detail: '$190 per hour, up to five hours. An alternative to property photography; deliverables are scoped separately.' },
    drone: { label: 'Drone / Aerial Photos', detail: '$42 per finished image. Show the building, grounds and access.' },
    video: { label: 'Commercial Property Video', detail: '$1,500 per finished 1–3 minute video, with edited 4K footage and licensed audio.' },
    mp: { label: 'Matterport 3D Experience', detail: '$0.10 per sq ft, $199 minimum. Six months of hosting included.*' },
    floor: { label: 'Schematic Floor Plans', detail: '$150 per property layout set, delivered in PDF / JPEG.' },
    website: { label: 'Property Website', detail: 'One dedicated listing website with media, property details and lead capture.', cents: 18500 }
  };
  const money = cents => (cents / 100).toLocaleString('en-US', { style:'currency', currency:'USD' });
  const videoDuration = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2,'0');
  function duration(minutes) {
    const n = Math.ceil(minutes / 5) * 5, h = Math.floor(n / 60), m = n % 60;
    return n ? (h ? h + ' hr' + (h > 1 ? 's' : '') : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '') : 'Confirmed With Your Appointment';
  }
  function calculate(input) {
    const cat = categories[input.category], sqft = Number(input.sqft);
    if (!cat || !Number.isInteger(sqft) || sqft < cat.min || sqft > cat.max) throw new Error('Enter a whole-number property size within the selected commercial category.');
    const chosen = new Set(input.selected || []);
    if (!chosen.size) throw new Error('Choose at least one commercial service.');
    chosen.forEach(key => { if (!services[key]) throw new Error('Unknown commercial service.'); });
    if (chosen.has('photo') && chosen.has('hourly')) throw new Error('Choose property photography or hourly creative labor, not both.');
    const images = Number(input.aerialImages), hours = Number(input.hours), videos = Number(input.videos), seconds = Number(input.videoSeconds), plans = Number(input.plans);
    if (chosen.has('drone') && (!Number.isInteger(images) || images < 1 || images > 100)) throw new Error('Choose 1–100 aerial images.');
    if (chosen.has('hourly') && (!Number.isFinite(hours) || hours < 1 || hours > 5 || !Number.isInteger(hours * 2))) throw new Error('Choose 1–5 hours in half-hour increments.');
    if (chosen.has('video') && (!Number.isInteger(videos) || videos < 1 || videos > 20 || !Number.isInteger(seconds) || seconds < 60 || seconds > 180)) throw new Error('Choose 1–20 videos and a length from 1:00 to 3:00.');
    if (chosen.has('floor') && (!Number.isInteger(plans) || plans < 1 || plans > 20)) throw new Error('Choose 1–20 property layout sets.');
    const fees = { photo:cat.cents, hourly:Math.round(hours * 19000), drone:images * 4200, video:videos * 150000, mp:Math.max(19900,sqft * 10), floor:plans * 15000, website:18500 };
    const reasons = [];
    if (chosen.has('photo') && input.category === 'large') reasons.push('Factory / industrial photography starts at $2,500; final scope needs confirmation.');
    if (chosen.has('hourly')) reasons.push('Hourly creative labor covers on-site time; final deliverables and any production fees need confirmation.');
    const lines = [...chosen].map(key => {
      let label = services[key].label;
      if (key === 'drone') label += ' · ' + images + (images === 1 ? ' image' : ' images');
      if (key === 'hourly') label += ' · ' + hours + (hours === 1 ? ' hour' : ' hours');
      if (key === 'video') label += ' · ' + videos + (videos === 1 ? ' video' : ' videos') + ' · ' + videoDuration(seconds) + ' each';
      if (key === 'floor') label += ' · ' + plans + (plans === 1 ? ' layout set' : ' layout sets');
      return {key,label,cents:fees[key],included:false,from:key === 'photo' && input.category === 'large'};
    });
    let subtotal = lines.reduce((sum,line) => sum + line.cents,0);
    const licenseEligible = chosen.has('photo') || chosen.has('drone') || chosen.has('video');
    const licenseType = input.licenseType || 'term', months = Number(input.licenseMonths === undefined ? 6 : input.licenseMonths);
    let licenseCents = 0, licenseLabel = 'No Photography License Selected';
    if (licenseEligible) {
      if (!['term','unlimited'].includes(licenseType)) throw new Error('Choose a license term or an unlimited photography license.');
      if (licenseType === 'term') {
        if (!Number.isInteger(months) || months < 6 || months > 18) throw new Error('Choose a license term from 6 to 18 months.');
        licenseLabel = months + '-Month Media License';
        if (months > 6) {licenseCents = null;reasons.push('The premium for a '+months+'-month license will be quoted separately.');}
        lines.push({key:'license',label:licenseLabel,cents:licenseCents,included:months === 6});
      } else {
        licenseLabel = 'Unlimited Photography License';
        const photoBase = (chosen.has('photo') ? fees.photo : 0) + (chosen.has('drone') ? fees.drone : 0);
        if (!photoBase || chosen.has('video') || (chosen.has('photo') && input.category === 'large')) {
          licenseCents = null;reasons.push('Unlimited licensing for this media mix requires a separate scope and price.');
        } else {licenseCents = Math.round(photoBase * .5);subtotal += licenseCents;}
        lines.push({key:'license',label:licenseLabel+' · Photography Only',cents:licenseCents,included:false});
      }
    }
    if (chosen.has('mp') && input.extendedHosting) reasons.push('Matterport hosting beyond the included six months requires a separate agreement.');
    const pending = reasons.length > 0;
    // Same scan-density estimate supplied for residential; commercial access is confirmed separately.
    const matterportMinutes = chosen.has('mp') ? sqft * 9 / 1000 : 0;
    const laborMinutes = chosen.has('hourly') ? hours * 60 : 0;
    const unestimated = ['photo','drone','video','floor'].filter(key => chosen.has(key));
    return { market:'commercial',sqft,category:cat.label,package:'Individual Commercial Services',packageCents:0,lines,subtotalCents:subtotal,totalCents:pending?null:subtotal,pending,pendingReasons:reasons,licenseEligible,licenseLabel,licenseMonths:licenseType==='term'?months:null,licenseCents,matterportIncludedMonths:chosen.has('mp')?6:0,extendedHosting:chosen.has('mp')&&!!input.extendedHosting,photographyMinutes:0,matterportMinutes,laborMinutes,knownMinutes:Math.ceil((matterportMinutes+laborMinutes)/5)*5,additionalCapture:unestimated,hasOnSite:[...chosen].some(key=>key!=='website'),services:[...chosen] };
  }
  function emailBody(quote,details,appointment) {
    const lines=[subject,'','Agent: '+details.first+' '+details.last,'Company: '+details.company,'Email: '+details.email,'Phone: '+details.phone,'Property: '+details.street+', '+details.city+', '+details.state+' '+details.zip,'Size: '+quote.sqft.toLocaleString()+' sq ft','Category: '+quote.category];
    quote.lines.forEach(line=>lines.push(line.label+': '+(line.included?'Included':line.cents===null?'To Be Quoted':(line.from?'From ':'')+money(line.cents))));
    lines.push('','Estimated total: '+(quote.pending?'Custom Quote — priced items '+money(quote.subtotalCents):money(quote.totalCents)));
    quote.pendingReasons.forEach(reason=>lines.push(reason));
    if (quote.matterportIncludedMonths) lines.push('Matterport hosting: six months included. Photography licensing does not extend hosting or grant unlimited Matterport use.');
    lines.push('Known on-site time: '+duration(quote.knownMinutes));
    if(quote.additionalCapture.length)lines.push('Time to confirm for: '+quote.additionalCapture.map(key=>services[key].label).join(', '));
    if(appointment)lines.push('Preferred date: '+appointment.date,'Preferred time: '+appointment.time+' Central Time','Appointment requested, not confirmed.');
    lines.push('Exclude from mailing lists: '+details.optOut,'','Final property scope, licensing and appointment availability are confirmed by SiteSee.');
    return lines.join('\n');
  }
  return {subject,categories,services,money,videoDuration,duration,calculate,emailBody};
});
