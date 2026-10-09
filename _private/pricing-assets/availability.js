/* Calendar guidance only. Staff approval is required; these choices reserve no time. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SiteSeeAvailability = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';
  const times = ['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '13:30', '15:30', '17:30'];
  const labels = ['7–9 AM', '9–11 AM', '11 AM–1 PM', '1–3 PM', '3–5 PM', '5–7 PM', '1:30–3:30 PM', '3:30–5:30 PM', '5:30–7:30 PM'];
  const unknown = 'We could not check the calendar. You can still submit your preferred window for SiteSee to review.';
  function validFeedback(data, payload) {
    if (!data || data.ok !== true) return false;
    if (data.state === 'manual_review') return typeof data.message === 'string';
    const validWindow = item => item && /^\d{4}-\d{2}-\d{2}$/.test(item.date) && times.includes(item.time)
      && item.end_time === String(Number(item.time.slice(0, 2)) + 2).padStart(2, '0') + ':' + item.time.slice(3,5);
    return data.state === 'checked' && data.date === payload.date && data.timezone === 'America/Chicago'
      && Number.isInteger(data.duration_minutes) && data.duration_minutes >= 15 && data.duration_minutes <= 1440
      && Array.isArray(data.date_windows) && data.date_windows.length <= 6
      && data.date_windows.every(item => validWindow(item) && item.date === payload.date)
      && Array.isArray(data.alternatives) && data.alternatives.length <= 6 && data.alternatives.every(validWindow)
      && data.selected_available === (payload.time ? data.date_windows.some(item => item.time === payload.time) : null);
  }
  /** Cancellation is advisory; the serial also discards responses that ignore abort. */
  function requester(fetcher, publish) {
    let serial = 0, controller = null;
    const invalidate = () => { serial++; if (controller) controller.abort(); };
    return {
      invalidate,
      async run(payload) {
        invalidate();
        const id = serial;
        controller = new AbortController();
        const active = controller;
        const timeout = setTimeout(() => active.abort(), 20000);
        publish({ state: 'loading' });
        try {
          const result = await fetcher(payload, active.signal);
          if (id !== serial) return;
          if (!validFeedback(result, payload)) throw new Error(unknown);
          publish(result);
        } catch (error) {
          if (id === serial) publish({ state: 'unknown', message: unknown });
        } finally {
          clearTimeout(timeout);
        }
      }
    };
  }
  function attach(form, prefix, market, getState) {
    if (form.dataset.calendarEnabled !== '1') return;
    const get = id => document.getElementById(prefix + id);
    const panel = get('availability-panel'), status = get('availability-status'), choices = get('availability-choices');
    const button = get('availability-retry'), date = get('shoot-date'), time = get('shoot-time'), rush = get('rush-requested');
    const optionLabels = new Map([...time.options].map(option => [option.value, option.textContent]));
    let key = '', debounce = null, expiry = null;
    panel.hidden = false;
    const clearChoices = () => {
      choices.replaceChildren();
      for (const option of time.options) {
        delete option.dataset.calendarBusy;
        option.textContent = optionLabels.get(option.value);
      }
      time.setCustomValidity('');
      form.dispatchEvent(new Event('sitesee:availability'));
    };
    const show = data => {
      clearChoices();
      button.disabled = data.state === 'loading';
      if (data.state === 'loading') { status.textContent = 'Checking available arrival windows…'; return; }
      if (data.state !== 'checked') { status.textContent = data.message || unknown; return; }
      const available = new Set(data.date_windows.map(window => window.time));
      for (const option of time.options) {
        if (option.value && !available.has(option.value)) {
          option.dataset.calendarBusy = 'true';
          option.textContent = optionLabels.get(option.value) + ' · Unavailable';
        }
      }
      form.dispatchEvent(new Event('sitesee:availability'));
      if (!data.date_windows.length) status.textContent = 'No eligible arrival windows on this date. Choose a suggested window on a later day.';
      else if (!time.value) status.textContent = 'Available arrival windows are shown in the list. Choose your preference; SiteSee approval is still required.';
      else if (data.selected_available) status.textContent = 'Your selected window appears available for the estimated shoot duration. SiteSee will review and confirm the appointment.';
      else status.textContent = 'Your selected window is unavailable for this shoot. Choose another window below or from the list.';
      if (data.selected_available !== true) {
        for (const window of data.alternatives) {
          const choice = document.createElement('button');
          choice.type = 'button'; choice.className = 'button secondary';
          const day = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' })
            .format(new Date(window.date + 'T12:00:00Z'));
          choice.textContent = day + ' · ' + labels[times.indexOf(window.time)] + ' Central';
          choice.addEventListener('click', () => {
            date.value = window.date; time.value = window.time;
            date.dispatchEvent(new Event('change', { bubbles: true }));
            time.dispatchEvent(new Event('change', { bubbles: true }));
            time.focus();
          });
          choices.append(choice);
        }
        if (!data.alternatives.length) status.textContent += ' No later windows were found in the next 14 days. Try a later date or contact SiteSee.';
      }
      expiry = setTimeout(() => refresh(true), 60000);
    };
    const loader = requester(async (payload, signal) => {
      const response = await fetch('booking-availability.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SiteSee-Availability': form.dataset.calendarCsrf },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok) throw new Error(unknown);
      return data;
    }, show);
    function refresh(force = false) {
      let payload;
      try { payload = { market, date: date.value, time: time.value, rush: rush.checked, state: getState() }; }
      catch (_) { payload = null; }
      const nextKey = JSON.stringify(payload);
      if (!force && key === nextKey) return;
      key = nextKey;
      clearTimeout(debounce); clearTimeout(expiry);
      loader.invalidate(); clearChoices(); button.disabled = false;
      if (!payload || !/^\d{4}-\d{2}-\d{2}$/.test(payload.date)) {
        status.textContent = 'Choose your services and a date to check available arrival windows.';
        return;
      }
      status.textContent = 'Checking available arrival windows…';
      debounce = setTimeout(() => loader.run(payload), 450);
    }
    form.addEventListener('input', () => refresh());
    form.addEventListener('change', () => refresh());
    button.addEventListener('click', () => refresh(true));
    window.addEventListener('pageshow', () => refresh(true));
    window.addEventListener('focus', () => refresh(true));
    refresh();
  }
  return { attach, requester, validFeedback };
});
