const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../_private/pricing-assets/scheduling.js', 'utf8');
function fixture(iso = '2026-09-25T14:00:00Z') {
  let elapsed = 0;
  const nodes = new Map(), events = {};
  const get = id => {
    if (!nodes.has(id)) nodes.set(id, {
      value:'', checked:false, textContent:'', custom:'', min:'', listeners:{},
      options:['','07:00','09:00','11:00','13:00','15:00','17:00'].map(value=>({value, disabled:false})),
      setCustomValidity(s) { this.custom=s; },
      checkValidity() { return !this.custom && !!this.value && (!this.min || this.value >= this.min); },
      addEventListener(event, fn) { (this.listeners[event] ||= []).push(fn); },
      querySelectorAll() { return []; }, classList:{contains:()=>false},
    });
    return nodes.get(id);
  };
  const form = { dataset:{serverNow:String(Date.parse(iso)/1000)},
    addEventListener(){}, querySelectorAll:()=>[], elements:{namedItem:name=>({value:name==='meetPhotographer'?'Yes':''})} };
  const window = {addEventListener:(event, fn)=>(events[event] ||= []).push(fn)};
  const context = {window, document:{getElementById:get}, performance:{now:()=>elapsed}, Intl, Date};
  vm.runInNewContext(source, context);
  const errors=[];
  window.SiteSeeValidation.show = field => errors.push(field);
  const api = window.SiteSeeScheduling.attach(form, '');
  return {get, api, errors, cutoff:window.SiteSeeScheduling.cutoff, advance:ms=>{elapsed=ms;}, change:id=>get(id).listeners.change.forEach(fn=>fn())};
}
test('72-hour and 12-hour cutoffs derive from the server timestamp', () => {
  const f=fixture();
  assert.equal(f.get('shoot-date').min, '2026-09-28');
  f.get('rush-requested').checked=true; f.change('rush-requested');
  assert.equal(f.get('shoot-date').min, '2026-09-25');
  assert.match(f.get('lead-time-help').textContent, /21:00 Central/);
});
test('boundary window expires as server-relative elapsed time advances', () => {
  const f=fixture();
  f.get('shoot-date').value='2026-09-28'; f.get('shoot-time').value='09:00';
  assert.equal(f.api.validateWindow(), true);
  f.advance(1000);
  assert.equal(f.api.validateWindow(), false);
  assert.equal(f.get('shoot-time').options.find(x=>x.value==='09:00').disabled, true);
  f.get('shoot-time').value='11:00';
  assert.equal(f.api.validateWindow(), true);
});
test('switching from rush to standard revalidates the earlier selection', () => {
  const f=fixture();
  f.get('rush-requested').checked=true; f.change('rush-requested');
  f.get('shoot-date').value='2026-09-26'; f.get('shoot-time').value='07:00';
  assert.equal(f.api.validateWindow(), true);
  f.get('rush-requested').checked=false; f.change('rush-requested');
  assert.equal(f.api.validateWindow(), false);
});
test('cutoffs use elapsed hours across spring and fall clock changes', () => {
  const f=fixture();
  const spring=f.cutoff(Date.parse('2027-03-11T15:00:00Z')/1000,false);
  assert.equal(spring.date,'2027-03-14'); assert.equal(spring.time,'10:00:00');
  const fall=f.cutoff(Date.parse('2027-11-04T14:00:00Z')/1000,false);
  assert.equal(fall.date,'2027-11-07'); assert.equal(fall.time,'08:00:00');
});
