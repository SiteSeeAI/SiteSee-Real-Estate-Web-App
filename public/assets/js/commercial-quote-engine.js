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
    platform: { label: 'SiteSee Platform', detail: '$49 per month per property. Present your media and individual 360° views in a SiteSee Experience.' },
    mp: { label: 'Matterport 3D Experience', detail: '$0.10 per scanned sq ft, $199 minimum. Choose the areas you want scanned. Six months of hosting included.*' },
    views360: { label: 'Single 360° Views', detail: '$25 per photo, added to your Matterport project as views within a SiteSee Experience. Requires Matterport and a SiteSee platform subscription.' },
    drone: { label: 'Drone / Aerial Photos', detail: '$42 per finished image. Show the building, grounds and access.' },
    video: { label: 'Cinematic B2B Video Walkthrough', detail: 'Choose your finished video length, from 1:00 to 3:00. Minimum investment: $500 per video.' },
    floor: { label: 'Schematic Floor Plans', detail: '$150 per property layout set, delivered in PDF / JPEG.' }
  };
  const money = cents => (cents / 100).toLocaleString('en-US', { style:'currency', currency:'USD' });
  const videoDuration = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2,'0');
  function duration(minutes) {
    const n = Math.ceil(minutes / 5) * 5, h = Math.floor(n / 60), m = n % 60;
    return n ? (h ? h + ' hr' + (h > 1 ? 's' : '') : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '') : 'Confirmed With Your Appointment';
  }
  function photographyCents(sqft, category) {
    if (category === 'small') return Math.round(Math.max(35000, sqft * 7.5));
    if (category === 'mid') return 75000 + (sqft - 10000) * 5;
    if (category === 'large') return 250000 + (sqft - 50000) * 5;
    throw new Error('Choose a commercial property category.');
  }
  function videoCents(seconds) { return Math.max(50000, Math.round(seconds * 833.3)); }
  function licenseFeeCents(base, type, months) { return Math.round(type === 'unlimited' ? base / 2 : base * Math.max(0, months - 6) / 40); }
  function calculate(input) {
    const cat = categories[input.category], sqft = Number(input.sqft);
    if (!cat || !Number.isInteger(sqft) || sqft < cat.min || sqft > cat.max) throw new Error('Enter a whole-number property size within the selected commercial category.');
    const chosen = new Set(['photo', ...(input.selected || [])]);
    chosen.forEach(key => { if (!services[key]) throw new Error('Unknown commercial service.'); });
    const images = Number(input.aerialImages), videos = Number(input.videos), seconds = Number(input.videoSeconds), plans = Number(input.plans), views = Number(input.views360);
    const mpSqft = Number(input.matterportSqft === undefined ? sqft : input.matterportSqft);
    const platform = chosen.has('platform'), platformMonths = Number(input.platformMonths === undefined ? 6 : input.platformMonths);
    if (platform && (!Number.isInteger(platformMonths) || platformMonths < 6 || platformMonths > 18)) throw new Error('Choose a platform term from 6 to 18 months.');
    if (chosen.has('views360') && !platform) throw new Error('Single 360° views require a SiteSee platform subscription.');
    if (chosen.has('views360') && !chosen.has('mp')) throw new Error('Select Matterport before adding individual 360° views.');
    if (chosen.has('views360') && (!Number.isInteger(views) || views < 1 || views > 100)) throw new Error('Choose 1–100 individual 360° photos.');
    if (chosen.has('mp') && (!Number.isInteger(mpSqft) || mpSqft < 1 || mpSqft > sqft)) throw new Error('Matterport coverage must be a whole number from 1 sq ft to the property’s total square footage.');
    if (chosen.has('drone') && (!Number.isInteger(images) || images < 1 || images > 100)) throw new Error('Choose 1–100 aerial images.');
    if (chosen.has('video') && (!Number.isInteger(videos) || videos < 1 || videos > 20 || !Number.isInteger(seconds) || seconds < 60 || seconds > 180)) throw new Error('Choose 1–20 videos and a length from 1:00 to 3:00.');
    if (chosen.has('floor') && (!Number.isInteger(plans) || plans < 1 || plans > 20)) throw new Error('Choose 1–20 property layout sets.');
    const fees = { photo:photographyCents(sqft,input.category), platform:platformMonths * 4900, drone:images * 4200, video:videos * videoCents(seconds), mp:Math.max(19900,mpSqft * 10), views360:views * 2500, floor:plans * 15000 };
    const reasons = [];
    const lines = Object.keys(services).filter(key => chosen.has(key)).map(key => {
      let label = services[key].label;
      if (key === 'platform') label += ' · ' + platformMonths + ' Months at $49 / Month';
      if (key === 'mp') label += ' · ' + mpSqft.toLocaleString('en-US') + ' sq ft scanned';
      if (key === 'views360') label += ' · ' + views + (views === 1 ? ' photo' : ' photos');
      if (key === 'drone') label += ' · ' + images + (images === 1 ? ' image' : ' images');
      if (key === 'video') label += ' · ' + videos + (videos === 1 ? ' video' : ' videos') + ' · ' + videoDuration(seconds) + ' each';
      if (key === 'floor') label += ' · ' + plans + (plans === 1 ? ' layout set' : ' layout sets');
      return {key,label,cents:fees[key],included:false,from:false};
    });
    const delivery = input.delivery || 'files';
    if (!['files','website'].includes(delivery)) throw new Error('Choose media files or an independent property website.');
    if (platform && delivery === 'website') throw new Error('The SiteSee platform already presents your property media; deselect it to choose an independent property website.');
    if (delivery === 'website') lines.push({key:'website',label:'Independent Property Website',cents:17500,included:false});
    let subtotal = lines.reduce((sum,line) => sum + line.cents,0);
    const licenseEligible = true;
    const licenseType = input.licenseType || 'term', months = Number(input.licenseMonths === undefined ? 6 : input.licenseMonths);
    if (!['term','unlimited'].includes(licenseType)) throw new Error('Choose an extended license or an unlimited media license.');
    if (licenseType === 'term' && (!Number.isInteger(months) || months < 6 || months > 18)) throw new Error('Choose a total license term from 6 to 18 months.');
    // Still photography, aerial images and video only. Matterport and 360° views remain separate.
    const mediaBase = fees.photo + (chosen.has('drone') ? fees.drone : 0) + (chosen.has('video') ? fees.video : 0);
    const licenseCents = licenseFeeCents(mediaBase,licenseType,months);
    const licenseLabel = licenseType === 'unlimited' ? 'Unlimited Media License' : months + '-Month Media License · First Six Months Included';
    lines.push({key:'license',label:licenseLabel,cents:licenseCents,included:licenseCents===0});
    subtotal += licenseCents;
    if (chosen.has('mp') && input.extendedHosting) reasons.push('Matterport hosting beyond the included six months requires a separate agreement.');
    const pending = reasons.length > 0;
    const matterportMinutes = chosen.has('mp') ? mpSqft * 9 / 1000 : 0;
    const photographyMinutes = sqft * 35 / 1000;
    const videoMinutes = chosen.has('video') ? videos * seconds * 15 / 60 : 0;
    const droneMinutes = chosen.has('drone') ? 20 : 0;
    const knownMinutes = Math.ceil((photographyMinutes + matterportMinutes + videoMinutes + droneMinutes) / 5) * 5;
    const unestimated = ['floor','views360'].filter(key => chosen.has(key));
    return { market:'commercial',sqft,matterportSqft:chosen.has('mp')?mpSqft:0,views360:chosen.has('views360')?views:0,category:cat.label,package:'Individual Commercial Services',packageCents:0,lines,subtotalCents:subtotal,totalCents:pending?null:subtotal,pending,pendingReasons:reasons,licenseEligible,licenseLabel,licenseMonths:licenseType==='term'?months:null,licenseCents,matterportIncludedMonths:chosen.has('mp')?6:0,extendedHosting:chosen.has('mp')&&!!input.extendedHosting,delivery:platform?'platform':delivery,platformMonths:platform?platformMonths:null,photographyCents:fees.photo,licenseBaseCents:mediaBase,photographyMinutes,matterportMinutes,videoMinutes,droneMinutes,knownMinutes,additionalCapture:unestimated,hasOnSite:true,services:[...chosen] };
  }
  function emailBody(quote,details,appointment) {
    const lines=[subject,'','Agent: '+details.first+' '+details.last,'Company: '+details.company,'Email: '+details.email,'Phone: '+details.phone,'Property: '+details.street+', '+details.city+', '+details.state+' '+details.zip,'Photography area: '+quote.sqft.toLocaleString()+' sq ft','Category: '+quote.category];
    quote.lines.forEach(line=>lines.push(line.label+': '+(line.included?'Included':line.cents===null?'To Be Quoted':(line.from?'From ':'')+money(line.cents))));
    lines.push('Delivery: '+({files:'Media Files Only',website:'Independent Property Website',platform:'SiteSee Platform'}[quote.delivery]));
    if (quote.delivery === 'platform') lines.push('SiteSee platform: $49 per month; the full selected term is included in this estimate. Matterport hosting is separate.');
    if (quote.views360) lines.push('Individual 360° photos are placed as views within the subscribed SiteSee Experience.');
    lines.push('Media licensing covers still photography, aerial images and videography. First six months included; extended terms charge only additional months. Matterport, individual 360° views, floor plans, website and platform fees are excluded from the license surcharge.');
    lines.push('','Estimated total: '+(quote.pending?'Custom Quote — priced items '+money(quote.subtotalCents):money(quote.totalCents)));
    quote.pendingReasons.forEach(reason=>lines.push(reason));
    if (quote.matterportIncludedMonths) lines.push('Matterport hosting: six months included. Media licensing does not extend hosting or grant unlimited Matterport use.');
    lines.push('Estimated Time On Site: '+duration(quote.knownMinutes));
    for (const [key,label] of [['photographyMinutes','Photography'],['matterportMinutes','Matterport'],['videoMinutes','Video'],['droneMinutes','Drone / Aerial']]) if (quote[key]) lines.push(label+': '+duration(quote[key]));
    if(quote.additionalCapture.length)lines.push('Time to confirm for: '+quote.additionalCapture.map(key=>services[key].label).join(', '));
    if(appointment)lines.push('Preferred date: '+appointment.date,'Preferred time: '+appointment.time+' Central Time','Appointment requested, not confirmed.');
    lines.push('Exclude from mailing lists: '+details.optOut,'','Final property scope, licensing and appointment availability are confirmed by SiteSee.');
    return lines.join('\n');
  }
  return {subject,categories,services,money,videoDuration,duration,photographyCents,videoCents,licenseFeeCents,calculate,emailBody};
});
