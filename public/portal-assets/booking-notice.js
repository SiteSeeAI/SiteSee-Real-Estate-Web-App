/* Notice limits only. The server still validates and approves every appointment. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SiteSeeBookingNotice = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';
  function cutoff(serverSeconds, rush) {
    const parts = new Intl.DateTimeFormat('en-US', {timeZone:'America/Chicago', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'})
      .formatToParts(new Date((Math.floor(serverSeconds) + (rush ? 12 : 72) * 3600) * 1000));
    const p = Object.fromEntries(parts.map(x => [x.type, x.value]));
    return {date:p.year + '-' + p.month + '-' + p.day, time:p.hour + ':' + p.minute + ':' + p.second};
  }
  function day(date) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
    const d = new Date(date + 'T12:00:00Z');
    return Number.isFinite(d.getTime()) && d.toISOString().slice(0,10) === date ? d : null;
  }
  function easter(year) {
    const a=year%19,b=Math.floor(year/100),c=year%100,d=Math.floor(b/4),e=b%4,
      f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30,
      i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451),v=h+l-7*m+114;
    return new Date(Date.UTC(year,Math.floor(v/31)-1,v%31+1,12));
  }
  function closedDay(date) {
    const d=day(date); if (!d) return true;
    if (['01-01','07-04','12-25'].includes(date.slice(5))) return true;
    const month=d.getUTCMonth(),n=d.getUTCDate(),w=d.getUTCDay(),e=easter(d.getUTCFullYear());
    if (date===e.toISOString().slice(0,10)) return true;
    e.setUTCDate(e.getUTCDate()-2); if (date===e.toISOString().slice(0,10)) return true;
    return (month===4 && w===1 && n>=25) || (month===8 && w===1 && n<=7) || (month===10 && w===4 && n>=22 && n<=28);
  }
  function windowTimes(date) {
    if (closedDay(date)) return [];
    return day(date).getUTCDay()===0 ? ['13:30','15:30','17:30'] : ['07:00','09:00','11:00','13:00','15:00','17:00'];
  }
  function firstDate(limit) {
    const d=day(limit.date);
    for (let i=0;i<370;i++) {
      const date=d.toISOString().slice(0,10), times=windowTimes(date);
      if (times.some(time=>date>limit.date || time+':00'>=limit.time)) return date;
      d.setUTCDate(d.getUTCDate()+1);
    }
    return limit.date;
  }
  function allowed(date, time, limit) {
    return Boolean(limit && date && time) && windowTimes(date).includes(time)
      && (date > limit.date || (date === limit.date && time + ':00' >= limit.time));
  }
  function apply(date, time, limit) {
    date.disabled = time.disabled = !limit;
    if (!limit) return;
    date.min = firstDate(limit);
    const times=date.value ? windowTimes(date.value) : [];
    for (const option of time.options) {
      option.hidden=Boolean(option.value && date.value && !times.includes(option.value));
      option.disabled=option.hidden || option.dataset?.calendarBusy==='true'
        || (Boolean(option.value && date.value) && !allowed(date.value,option.value,limit));
    }
  }
  function createClock(seed, env = globalThis) {
    let epoch = Number(seed), anchor = env.performance.now(), trusted = Number.isFinite(epoch) && epoch > 0;
    let pending = null, lastAttempt = -Infinity;
    const listeners = new Set();
    const now = () => trusted ? epoch + Math.max(0, env.performance.now() - anchor) / 1000 : null;
    const publish = () => listeners.forEach(fn => fn(now()));
    function refresh(reawakened = false) {
      // performance.now can pause during OS sleep. Re-anchor to the server on return.
      if (reawakened) {
        trusted = false;
        if (pending) pending.controller.abort();
        pending = null;
        publish();
      }
      if (pending) return pending.promise;
      const started = env.performance.now(), controller = new env.AbortController();
      lastAttempt = started;
      const timeout = env.setTimeout(() => controller.abort(), 8000);
      const active = {controller};
      pending = active;
      active.promise = (async () => {
        try {
          const response = await env.fetch('/booking-clock.php', {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{Accept:'application/json'}});
          const data = await response.json();
          if (pending !== active) return;
          if (!response.ok || data.timezone !== 'America/Chicago' || !Number.isFinite(data.now) || data.now <= 0) throw Error('Clock unavailable');
          // Include transit time conservatively; never use the customer's wall clock.
          epoch = data.now; anchor = started; trusted = true;
        } catch (_) {
          // An unverified clock after sleep stays unavailable; the timer retries silently.
        } finally { env.clearTimeout(timeout); if (pending === active) { pending = null; publish(); } }
      })();
      return active.promise;
    }
    // Some systems wake with the tab still focused/visible. Refresh as the customer
    // approaches a date/window control as well; do not consume their picker gesture.
    for (const event of ['pointerover', 'pointerdown', 'focusin']) env.document.addEventListener(event, e => {
      if (e.target?.matches('input[type="date"], select')) refresh();
    });
    env.addEventListener('focus', () => refresh(true));
    env.addEventListener('pageshow', event => { if (event.persisted) refresh(true); });
    env.document.addEventListener('visibilitychange', () => {
      if (env.document.hidden) { trusted = false; publish(); }
      else refresh(true);
    });
    env.setInterval(() => {
      if (env.document.hidden) return;
      publish();
      if (env.performance.now() - lastAttempt >= 30000) refresh();
    }, 1000);
    refresh();
    return {now, refresh, subscribe(fn) { listeners.add(fn); fn(now()); }};
  }
  let sharedClock;
  function watch(seed, listener) {
    if (!sharedClock) sharedClock = createClock(seed);
    sharedClock.subscribe(listener);
    return sharedClock;
  }
  return {cutoff, firstDate, allowed, apply, closedDay, windowTimes, createClock, watch};
});
