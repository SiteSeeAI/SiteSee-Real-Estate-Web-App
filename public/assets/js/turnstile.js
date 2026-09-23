(() => {
  'use strict';

  // The Corporate server installer replaces this marker with the public key
  // already installed for the SiteSee Audit managed widget.
  const SITESEE_SITEKEY = '__SITESEE_AUDIT_SITEKEY__';
  const states = new WeakMap();
  const protectedForms = new Set();
  let apiPromise;

  const loadScript = () => {
    if (window.turnstile && typeof window.turnstile.render === 'function') {
      return Promise.resolve(window.turnstile);
    }
    if (apiPromise) return apiPromise;
    apiPromise = new Promise((resolve, reject) => {
      let finished = false;
      let polling;
      let deadline;
      let ownScript;
      const finish = error => {
        if (finished) return;
        finished = true;
        window.clearInterval(polling);
        window.clearTimeout(deadline);
        if (error) {
          if (ownScript) ownScript.remove();
          reject(error);
        } else {
          resolve(window.turnstile);
        }
      };
      const ready = () => {
        if (window.turnstile && typeof window.turnstile.render === 'function') finish();
      };
      polling = window.setInterval(ready, 100);
      deadline = window.setTimeout(() => finish(new Error('Secure form protection could not load.')), 15000);
      if (!document.querySelector('script[src^="https://challenges.cloudflare.com/turnstile/v0/api.js"]')) {
        ownScript = document.createElement('script');
        ownScript.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        ownScript.async = true;
        ownScript.addEventListener('load', ready);
        ownScript.addEventListener('error', () => finish(new Error('Secure form protection could not load.')));
        document.head.append(ownScript);
      }
      ready();
    }).catch(error => {
      apiPromise = undefined;
      throw error;
    });
    return apiPromise;
  };

  const protect = async form => {
    if (states.has(form)) return states.get(form).controller;
    const container = form.querySelector('[data-turnstile]');
    const status = form.querySelector('.form-security-status');
    const submit = form.querySelector('[type="submit"]');
    if (!container || !status || !submit) throw new Error('The secure form controls are incomplete.');

    submit.disabled = true;
    status.textContent = 'Loading secure form protection…';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'cf-turnstile-response';
    token.value = '';
    form.append(token);
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'text-button form-security-retry';
    retry.textContent = 'Retry Verification';
    retry.hidden = true;
    status.after(retry);

    const state = { api: null, widgetId: null, verified: false, token, retry, status, submit, controller: null };
    const failed = () => {
      state.verified = false;
      token.value = '';
      submit.disabled = true;
      status.textContent = 'Secure form protection could not finish. Please retry.';
      retry.hidden = false;
    };
    const reset = () => {
      state.verified = false;
      token.value = '';
      submit.disabled = true;
      status.textContent = 'Please complete verification again.';
      retry.hidden = true;
      try {
        if (state.api && state.widgetId !== null) state.api.reset(state.widgetId);
      } catch (error) {
        failed();
      }
    };
    state.controller = { isVerified: () => state.verified && token.value !== '', reset };
    states.set(form, state);
    protectedForms.add(form);

    const api = await loadScript();
    state.api = api;
    state.widgetId = api.render(container, {
      sitekey: SITESEE_SITEKEY,
      action: container.dataset.action,
      theme: 'light',
      size: 'flexible',
      'response-field': false,
      callback: value => {
        token.value = value;
        state.verified = true;
        status.textContent = 'Secure form check complete.';
        retry.hidden = true;
        submit.disabled = false;
      },
      'expired-callback': () => {
        token.value = '';
        state.verified = false;
        status.textContent = 'Refreshing secure form protection…';
        submit.disabled = true;
      },
      'timeout-callback': failed,
      'error-callback': () => {
        failed();
        return true;
      }
    });
    retry.addEventListener('click', () => {
      retry.hidden = true;
      reset();
    });
    return state.controller;
  };

  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    protectedForms.forEach(form => {
      const state = states.get(form);
      if (state) state.controller.reset();
    });
  });

  window.SiteSeeTurnstile = { protect };
})();
