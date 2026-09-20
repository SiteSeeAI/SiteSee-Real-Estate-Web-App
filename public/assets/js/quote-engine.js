/* SiteSee residential pricing. Prices are USD; internal totals use integer cents. */
(function (root, factory) {
  const engine = factory();
  if (typeof module === 'object' && module.exports) module.exports = engine;
  else root.SiteSeeQuote = engine;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const subject = 'Residential SiteSee Real Estate Quote';
  const categories = {
    small: { label: 'Small Home / Condo', min: 1, max: 1999 },
    average: { label: 'Average Home', min: 2000, max: 4000 },
    large: { label: 'Large Home', min: 4000, max: 5000 },
    luxury: { label: 'Luxury Home', min: 5000, max: 10000 }
  };
  const packages = {
    custom: { label: 'Individual Services', cents: 0, includes: [] },
    silver: { label: 'Silver', cents: 22000, includes: ['photo', 'website'], photos: '25 HDR photos' },
    gold: { label: 'Gold', cents: 49900, includes: ['photo', 'website', 'video', 'floor'], photos: '35 HDR photos', minutes: 1 },
    platinum: { label: 'Platinum', cents: 99500, includes: ['photo', 'website', 'video', 'floor', 'mp', 'drone'], photos: '50+ HDR photos', minutes: 2 }
  };
  const services = {
    photo: { label: 'Property Photography', detail: 'Interior and exterior coverage priced for your home.' },
    website: { label: 'Property Website', detail: 'A single-property landing page with lead capture.', cents: 6500 },
    drone: { label: 'Drone / Aerial Photos', detail: '5–10 high-resolution aerial photographs.', cents: 12000 },
    zillow: { label: 'Zillow 3D Home', detail: 'An interactive property experience for Zillow.', cents: 9500 },
    video: { label: 'Property Video', detail: 'Interior and exterior video, from one to three minutes.' },
    floor: { label: '2D Schematic Floor Plan', detail: 'Property layout with room measurements.', cents: 5000 },
    twilight: { label: 'Virtual Twilight', detail: 'Daylight-to-dusk image editing. $35 per image.' },
    mp: { label: 'Matterport 3D Experience', detail: '$0.06 per sq ft, with a $69 minimum.*' }
  };
  const money = cents => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
  function calculate(input) {
    const cat = categories[input.category], pack = packages[input.package];
    if (!cat || !pack) throw new Error('Choose a property category and a package or individual services.');
    const sqft = Number(input.sqft);
    if (!Number.isInteger(sqft) || sqft < cat.min || sqft > cat.max) throw new Error('Enter a whole-number size between ' + cat.min.toLocaleString() + ' and ' + cat.max.toLocaleString() + ' sq ft.');
    const chosen = new Set(['photo', ...pack.includes]);
    for (const key of input.selected || []) {
      if (!services[key]) throw new Error('Unknown service.');
      chosen.add(key);
    }
    const videoSeconds = Number(input.videoSeconds), images = Number(input.images);
    if (chosen.has('video') && !pack.includes.includes('video') && (!Number.isInteger(videoSeconds) || videoSeconds < 60 || videoSeconds > 180)) throw new Error('Choose a video length from 1:00 to 3:00 in whole seconds.');
    if (chosen.has('twilight') && (!Number.isSafeInteger(images) || images < 1 || images > 100)) throw new Error('Enter a whole number from 1 to 100 twilight images.');
    const photo = input.category === 'small' ? Math.max(15000, Math.round(sqft * 9.52)) : input.category === 'average' ? Math.round(24500 + (sqft - 2000) * 1.75) : null;
    const rates = { photo, website: 6500, drone: 12000, zillow: 9500, video: Math.round(22500 + (videoSeconds - 60) * 12500 / 120), floor: 5000, twilight: images * 3500, mp: Math.max(6900, sqft * 6) };
    let subtotal = pack.cents, pending = false;
    const lines = Array.from(chosen).map(key => {
      const included = pack.includes.includes(key);
      const cents = included ? 0 : rates[key];
      if (cents === null) pending = true; else subtotal += cents;
      let label = services[key].label;
      if (key === 'video') label += ' · ' + videoDuration(included ? pack.minutes * 60 : videoSeconds);
      if (key === 'twilight') label += ' · ' + images + (images === 1 ? ' image' : ' images');
      if (key === 'photo' && included) label += ' · ' + pack.photos;
      return { key, label, included, cents };
    });
    const photographyMinutes = chosen.has('photo') ? sqft * 35 / 1000 : 0;
    // 150 scans * 30 seconds + 15 minutes at 10,000 sq ft = 90 minutes.
    const matterportMinutes = chosen.has('mp') ? sqft * 9 / 1000 : 0;
    const videoMinutes = chosen.has('video') ? (pack.includes.includes('video') ? pack.minutes * 15 : videoSeconds * 15 / 60) : 0;
    const droneMinutes = chosen.has('drone') ? 20 : 0;
    const additionalCapture = ['zillow', 'floor'].filter(key => chosen.has(key));
    const knownMinutes = Math.ceil((photographyMinutes + matterportMinutes + videoMinutes + droneMinutes) / 5) * 5;
    return { sqft, category: cat.label, package: pack.label, packageCents: pack.cents, lines, subtotalCents: subtotal, totalCents: pending ? null : subtotal, pending, photographyMinutes, matterportMinutes, videoMinutes, droneMinutes, knownMinutes, additionalCapture, hasOnSite: knownMinutes > 0 || additionalCapture.length > 0 };
  }
  function videoDuration(seconds) {
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  }
  function duration(minutes) {
    const rounded = Math.ceil(minutes / 5) * 5;
    if (!rounded) return 'No on-site capture';
    const h = Math.floor(rounded / 60), m = rounded % 60;
    return (h ? h + ' hr' + (h === 1 ? '' : 's') : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '');
  }
  function emailBody(quote, details, appointment) {
    const lines = [subject, '', 'Agent: ' + details.first + ' ' + details.last, 'Company: ' + details.company, 'Email: ' + details.email, 'Phone: ' + details.phone, 'Property: ' + details.street + ', ' + details.city + ', ' + details.state + ' ' + details.zip, 'Property size: ' + quote.sqft.toLocaleString() + ' sq ft', 'Category: ' + quote.category, 'Selection: ' + quote.package];
    if (quote.packageCents) lines.push('Package: ' + money(quote.packageCents));
    quote.lines.forEach(line => lines.push(line.label + ': ' + (line.included ? 'Included' : line.cents === null ? 'Custom quote required' : money(line.cents))));
    lines.push('', 'Estimated total: ' + (quote.pending ? 'Custom quote required; priced items total ' + money(quote.subtotalCents) : money(quote.totalCents)));
    lines.push('Estimated Time On Site: ' + duration(quote.knownMinutes));
    for (const [key,label] of [['photographyMinutes','Photography'],['matterportMinutes','Matterport'],['videoMinutes','Video'],['droneMinutes','Drone / Aerial']]) if (quote[key]) lines.push(label + ': ' + duration(quote[key]));
    if (quote.additionalCapture.length) lines.push('Time to be confirmed for: ' + quote.additionalCapture.map(key => services[key].label).join(', '));
    if (appointment) lines.push('Preferred date: ' + appointment.date, 'Preferred time: ' + appointment.time + ' Central Time', 'Appointment is requested, not confirmed.');
    lines.push('Exclude from mailing lists: ' + details.optOut, '', 'Final property scope and appointment availability are confirmed by SiteSee.');
    return lines.join('\n');
  }
  return { subject, categories, packages, services, money, calculate, duration, videoDuration, emailBody };
});
