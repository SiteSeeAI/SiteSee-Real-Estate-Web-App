const test = require('node:test');
const assert = require('node:assert/strict');
const api = require('../_private/pricing-assets/availability.js');
const payload = {market:'residential',date:'2026-10-02',time:'09:00',rush:false,state:{}};
function result(date=payload.date) {
  return {ok:true,state:'checked',date,timezone:'America/Chicago',duration_minutes:70,selected_available:true,
    date_windows:[{date,time:'09:00',end_time:'11:00'}],alternatives:[]};
}
test('malformed or incomplete replies never appear available', () => {
  assert.equal(api.validFeedback(result(),payload),true);
  for (const bad of [null,{}, {...result(),ok:false}, {...result(),date:'2026-10-03'}, {...result(),date_windows:[]},
    {...result(),timezone:'UTC'}, {...result(),duration_minutes:1}, {...result(),alternatives:[{date:payload.date,time:'99:00'}]}]) {
    assert.equal(api.validFeedback(bad,payload),false);
  }
});
test('manual review has no affirmative availability', () => {
  assert.equal(api.validFeedback({ok:true,state:'manual_review',message:'Duration needs review.'},payload),true);
});
test('an older response cannot replace results after the date changes', async () => {
  const pending = [], states = [];
  const loader = api.requester((body,signal)=>new Promise(resolve=>pending.push({body,signal,resolve})),state=>states.push(state));
  const first = loader.run(payload);
  const secondPayload = {...payload,date:'2026-10-03'};
  const second = loader.run(secondPayload);
  assert.equal(pending[0].signal.aborted,true);
  pending[1].resolve(result(secondPayload.date)); await second;
  pending[0].resolve(result()); await first;
  assert.equal(states.at(-1).date,'2026-10-03');
  assert.equal(states.filter(x=>x.state==='checked').length,1);
});
test('changing services invalidates an in-flight result before a new request starts', async () => {
  let finish; const states=[];
  const loader=api.requester(()=>new Promise(resolve=>{finish=resolve;}),state=>states.push(state));
  const run=loader.run(payload); loader.invalidate(); finish(result()); await run;
  assert.equal(states.some(x=>x.state==='checked'),false);
});
test('provider errors and malformed successful responses render unknown', async () => {
  for (const fetcher of [async()=>{throw Error('private upstream details');},async()=>({ok:true})]) {
    const states=[];const loader=api.requester(fetcher,state=>states.push(state));
    await loader.run(payload);
    assert.equal(states.at(-1).state,'unknown');
    assert.doesNotMatch(states.at(-1).message,/private upstream/);
  }
});

test('form feedback offers a later day, selects it and sends only service state', async () => {
  const originals={window:global.window,document:global.document,fetch:global.fetch,setTimeout:global.setTimeout,clearTimeout:global.clearTimeout};
  const timers=new Map(); let serial=0;
  global.setTimeout=(fn,delay)=>{const id=++serial;timers.set(id,{fn,delay});return id;};
  global.clearTimeout=id=>timers.delete(id);
  class Element extends EventTarget {
    constructor(){super();this.dataset={};this.children=[];this.textContent='';this.value='';this.checked=false;this.disabled=false;this.hidden=true;}
    append(child){this.children.push(child);}
    replaceChildren(){this.children=[];}
    setCustomValidity(value){this.custom=value;}
    focus(){}
  }
  const form=new Element();form.dataset={calendarEnabled:'1',calendarCsrf:'test-csrf'};
  const nodes=new Map();
  const get=id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);};
  const date=get('shoot-date'),time=get('shoot-time');date.value='2026-09-30';time.value='09:00';
  time.options=['','07:00','09:00','11:00','13:00','15:00','17:00'].map(value=>Object.assign(new Element(),{value,textContent:value||'Select'}));
  for(const field of [date,time])field.addEventListener('change',()=>form.dispatchEvent(new Event('change')));
  const requests=[];
  global.window=new EventTarget();global.document={getElementById:get,createElement:()=>new Element()};
  global.fetch=async(url,options)=>{
    const body=JSON.parse(options.body);requests.push({url,options,body});
    return {ok:true,json:async()=>body.date==='2026-09-30'
      ? {...result(body.date),selected_available:false,date_windows:[],alternatives:[{date:'2026-10-01',time:'09:00',end_time:'11:00'}]}
      : result(body.date)};
  };
  const flush=async()=>{for(const [id,timer] of [...timers])if(timer.delay===450){timers.delete(id);timer.fn();}await new Promise(resolve=>setImmediate(resolve));};
  try {
    api.attach(form,'','residential',()=>({category:'average',package:'custom',sqft:2000,selected:['photo']}));
    await flush();
    assert.equal(get('availability-panel').hidden,false);
    assert.match(get('availability-status').textContent,/No eligible arrival windows/);
    assert.equal(time.options.find(x=>x.value==='09:00').dataset.calendarBusy,'true');
    const choice=get('availability-choices').children[0];assert.match(choice.textContent,/Oct 1/);
    choice.dispatchEvent(new Event('click'));await flush();
    assert.equal(date.value,'2026-10-01');assert.equal(time.value,'09:00');
    assert.match(get('availability-status').textContent,/appears available/);
    assert.deepEqual(Object.keys(requests.at(-1).body).sort(),['date','market','rush','state','time']);
    assert.equal(requests.at(-1).options.headers['X-SiteSee-Availability'],'test-csrf');
    date.value='';form.dispatchEvent(new Event('input'));
    assert.equal(time.options.some(option=>option.dataset.calendarBusy==='true'),false);
    assert.match(get('availability-status').textContent,/Choose your services and a date/);
  } finally {for(const [key,value] of Object.entries(originals)){if(value===undefined)delete global[key];else global[key]=value;}}
});
