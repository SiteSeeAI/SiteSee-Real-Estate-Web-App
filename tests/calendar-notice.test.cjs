const test = require('node:test');
const assert = require('node:assert/strict');
const notice = require('../public/portal-assets/booking-notice.js');
const cases = require('./calendar-notice-cases.json');
for (const c of cases) test(`notice boundary ${c.now} rush=${c.rush}`, () => {
  const limit = notice.cutoff(Date.parse(c.now)/1000, c.rush);
  assert.deepEqual(limit, {date:c.date,time:c.time});
  assert.equal(notice.firstDate(limit), c.first);
  const date={value:c.date}, time={options:['','07:00','09:00','11:00','13:00','15:00','17:00','13:30','15:30','17:30'].map(value=>({value,dataset:{}}))};
  notice.apply(date,time,limit);
  assert.equal(date.min,c.first);
  assert.deepEqual(time.options.filter(o=>o.value&&!o.disabled).map(o=>o.value).sort(),[...c.times].sort());
  assert.equal(notice.allowed('2020-01-01','17:00',limit),false);
  assert.equal(notice.allowed('2030-01-02','07:00',limit),true);
});
test('notice update retains provider-busy windows and does not replace chosen values', () => {
  const date={value:'2026-10-05'}, time={value:'09:00',options:[{value:'09:00',dataset:{calendarBusy:'true'}},{value:'11:00',dataset:{}}]};
  notice.apply(date,time,notice.cutoff(Date.parse('2026-10-01T14:00:00Z')/1000,false));
  assert.equal(time.options[0].disabled,true);assert.equal(time.options[1].disabled,false);
  assert.equal(time.value,'09:00');assert.equal(date.value,'2026-10-05');
});
function clockFixture(seed=1000000) {
  let elapsed=0;const events={},visibility={},requests=[],intervals=[];
  const env={performance:{now:()=>elapsed},AbortController,document:{hidden:false,addEventListener:(n,f)=>visibility[n]=f},
    addEventListener:(n,f)=>events[n]=f,setTimeout:()=>1,clearTimeout:()=>{},setInterval:f=>intervals.push(f),
    fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
  const clock=notice.createClock(seed,env);
  const respond=async (i,now,extra={})=>{requests[i].resolve({ok:true,json:async()=>({now,timezone:'America/Chicago',...extra})});await new Promise(r=>setImmediate(r));};
  return {clock,env,requests,events,visibility,respond,advance:n=>elapsed=n,tick:()=>intervals[0]()};
}
test('clock uses server seed, measured elapsed time and fresh no-store server response', async () => {
  const old=Date.now;Date.now=()=>{throw Error('Device wall clock used');};
  try {
    const f=clockFixture();assert.equal(f.clock.now(),1000000);f.advance(1200);assert.equal(f.clock.now(),1000001.2);
    await f.respond(0,2000000);assert.equal(f.clock.now(),2000001.2);
    assert.equal(f.requests[0].options.cache,'no-store');assert.equal(f.requests[0].url,'/booking-clock.php');
    f.advance(31000);f.tick();assert.equal(f.requests.length,2);
  } finally {Date.now=old;}
});
test('resume re-anchors a paused monotonic clock and ignores any pre-sleep response', async () => {
  const f=clockFixture();f.events.focus();assert.equal(f.clock.now(),null);
  assert.equal(f.requests[0].options.signal.aborted,true);
  await f.respond(0,1000000);assert.equal(f.clock.now(),null);
  await f.respond(1,1100000);assert.equal(f.clock.now(),1100000);
  f.env.document.hidden=true;f.visibility.visibilitychange();assert.equal(f.clock.now(),null);
  f.env.document.hidden=false;f.visibility.visibilitychange();await f.respond(2,1200000);assert.equal(f.clock.now(),1200000);
  f.events.pageshow({persisted:true});assert.equal(f.clock.now(),null);await f.respond(3,1300000);assert.equal(f.clock.now(),1300000);
});
test('unverified resumed clock prevents stale choices and automatically retries', async () => {
  const f=clockFixture();await f.respond(0,1000000);f.events.focus();
  await f.respond(1,1100000,{timezone:'UTC'});assert.equal(f.clock.now(),null);
  const date={},time={options:[]};notice.apply(date,time,null);assert.equal(date.disabled,true);assert.equal(time.disabled,true);
  f.advance(31000);f.tick();await f.respond(2,1100000);assert.equal(f.clock.now(),1100000);
});

test('same focused tab waking from sleep refreshes on picker interaction', async () => {
  const f=clockFixture();await f.respond(0,1000000);
  f.visibility.pointerover({target:{matches:()=>true}});
  f.visibility.pointerdown({target:{matches:()=>true}});
  f.visibility.focusin({target:{matches:()=>true}});
  assert.equal(f.requests.length,2,'Concurrent interaction requests are deduplicated');
  await f.respond(1,1200000);assert.equal(f.clock.now(),1200000);
});

test('selected holidays and Sunday windows match the server business policy',()=>{
 for(const date of ['2026-01-01','2026-04-03','2026-04-05','2026-05-25','2026-07-04','2026-09-07','2026-11-26','2026-12-25','2027-03-26','2027-03-28'])assert.deepEqual(notice.windowTimes(date),[],date);
 assert.deepEqual(notice.windowTimes('2026-10-11'),['13:30','15:30','17:30']);
 for(const date of ['2027-01-18','2027-06-19','2026-07-03'])assert(notice.windowTimes(date).length>0,'Other/observed holidays remain available');
 assert.equal(notice.firstDate({date:'2026-11-26',time:'07:00:00'}),'2026-11-27');
});
