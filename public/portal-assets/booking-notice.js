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
  function firstDate(limit) {
    if (limit.time <= '17:00:00') return limit.date;
    // Advance the date label, after calculating elapsed notice hours in Central Time.
    const next = new Date(limit.date + 'T12:00:00Z');
    next.setUTCDate(next.getUTCDate() + 1);
    return next.toISOString().slice(0, 10);
  }
  function allowed(date, time, limit) {
    return Boolean(date && time) && (date > limit.date || (date === limit.date && time + ':00' >= limit.time));
  }
  function apply(date, time, limit) {
    date.disabled = time.disabled = !limit;
    if (!limit) return;
    date.min = firstDate(limit);
    for (const option of time.options) option.disabled = option.dataset?.calendarBusy === 'true'
      || (Boolean(option.value && date.value) && !allowed(date.value, option.value, limit));
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
  return {cutoff, firstDate, allowed, apply, createClock, watch};
});
