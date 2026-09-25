const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
function setup(values, reduced = false) {
  const events = {}, regions = [], fields = [];
  const form = {
    querySelectorAll: selector => selector === '.quote-field-error' ? regions.filter(r=>r.classList.contains('quote-field-error')) : fields,
    addEventListener: (name, callback) => {events[name]=callback;}
  };
  for (const settings of values) {
    const classes = new Set();
    const region = {
      messages: [], fields: [],
      classList:{add:x=>classes.add(x), remove:x=>classes.delete(x), contains:x=>classes.has(x)},
      querySelectorAll(selector) {
        if (selector === '.quote-validation-message') return [...this.messages];
        if (selector === '[aria-invalid="true"]') return this.fields.filter(f=>f.attributes['aria-invalid']==='true');
        return this.fields;
      },
      append(message) { message.region=this; this.messages.push(message); },
      scrollIntoView(options) { this.scroll=options; }
    };
    const field = {
      form, type:'text', name:'first', required:true, value:'', willValidate:true, custom:'', nativeValid:true, attributes:{},
      ...settings,
      setCustomValidity(message) { this.custom=message; },
      get validity() {return {valid:!this.custom && this.nativeValid && (!this.required || ['radio','checkbox'].includes(this.type) || this.value.trim()!=='')};},
      get validationMessage() {return this.custom || 'Please complete this field.';},
      closest(selector) {return selector==='[hidden]' ? (this.hidden ? region : null) : selector==='.quote-field-error' ? (classes.has('quote-field-error') ? region : null) : region;},
      setAttribute(key,value) {this.attributes[key]=value;},
      removeAttribute(key) {delete this.attributes[key];},
      focus(options) {this.focused=options;},
      matches() {return true;}
    };
    region.fields.push(field); fields.push(field); regions.push(region);
  }
  const document = {createElement:()=>({setAttribute(){}, remove(){this.region.messages=this.region.messages.filter(x=>x!==this);}})};
  const window = {matchMedia:()=>({matches:reduced})};
  vm.runInNewContext(fs.readFileSync(path.join(root,'_private/pricing-assets/scheduling.js'),'utf8'), {window,document});
  window.SiteSeeValidation.attach(form);
  return {api:window.SiteSeeValidation, form, fields, regions, events};
}
test('submit finds the first visible enabled invalid field in document order', () => {
  const s=setup([{willValidate:false}, {hidden:true}, {value:'Valid'}, {}, {}]);
  assert.equal(s.api.validate(s.form), false);
  assert.equal(s.regions[3].classList.contains('quote-field-error'), true);
  assert.equal(s.regions[4].classList.contains('quote-field-error'), false);
  assert.equal(s.fields[3].focused.preventScroll, true);
  assert.equal(s.regions[3].scroll.block, 'center');
  assert.equal(s.regions[3].scroll.behavior, 'smooth');
  assert.equal(s.fields[3].attributes['aria-invalid'], 'true');
  assert.equal(s.regions[3].messages.length, 1);
});
test('correcting the missed field clears its red state and message', () => {
  const s=setup([{}]); s.api.validate(s.form);
  s.fields[0].value='Ava'; s.events.input({target:s.fields[0]});
  assert.equal(s.regions[0].classList.contains('quote-field-error'), false);
  assert.equal(s.regions[0].messages.length, 0);
  assert.equal(s.fields[0].attributes['aria-invalid'], undefined);
  assert.equal(s.api.validate(s.form), true);
});
test('optional empty mailing preference passes and short phone numbers are highlighted', () => {
  const s=setup([{name:'optOut', type:'select-one', required:false}, {name:'phone', value:'123'}]);
  assert.equal(s.api.validate(s.form), false);
  assert.equal(s.regions[0].classList.contains('quote-field-error'), false);
  assert.match(s.regions[1].messages[0].textContent, /10 digits/);
});
test('native radio, checkbox and date constraints remain enforced with reduced-motion scrolling', () => {
  for (const type of ['radio','checkbox','date']) {
    const s=setup([{type,nativeValid:false,value:'invalid'}],true);
    assert.equal(s.api.validate(s.form),false);
    assert.equal(s.regions[0].scroll.behavior,'auto');
  }
});
test('both forms default optional mailing preference to No', () => {
  const html=fs.readFileSync(path.join(root,'_private/views/pricing.php'),'utf8');
  const selects=[...html.matchAll(/<select[^>]*name="optOut"[^>]*>[\s\S]*?<\/select>/g)].map(x=>x[0]);
  assert.equal(selects.length,2);
  for(const select of selects) {
    assert.equal(/\brequired\b/.test(select),false);
    assert.match(select,/<option value="No" selected>No — updates are welcome<\/option>/);
  }
});
