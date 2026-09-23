(() => {
  'use strict';
  const form = document.getElementById('pricing-access-form');
  if (!form) return;
  const message = document.getElementById('pricing-access-message');
  const button = form.querySelector('button[type="submit"]');
  const required = new URLSearchParams(window.location.search).has('pricing_required');
  let security;
  const securityReady = window.SiteSeeTurnstile
    ? window.SiteSeeTurnstile.protect(form).then(controller => { security = controller; return controller; })
    : Promise.reject(new Error('Secure form protection did not load.'));

  securityReady.catch(error => {
    const status = form.querySelector('.form-security-status');
    status.textContent = error.message || 'Secure form protection is unavailable.';
  });

  if (required) {
    message.textContent = 'Verified pricing access is required. Submit the form and SiteSee will review your request.';
    message.hidden = false;
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    try {
      const guard = await securityReady;
      if (!guard.isVerified()) throw new Error('Complete the secure form check to continue.');
      button.disabled = true;
      button.textContent = 'Sending…';
      message.hidden = true;
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      });
      const data = await response.json().catch(() => ({ ok: false, message: 'The server returned an unreadable response.' }));
      if (!response.ok || !data.ok) throw new Error(data.message || 'We could not process your request.');
      form.innerHTML = '<div class="form-heading"><p class="eyebrow dark">Request Received</p><h2 tabindex="-1">We’ll review your information.</h2><p>No pricing has been released yet. If approved, you will receive a secure, time-limited pricing link by email.</p></div>';
      form.querySelector('h2').focus();
    } catch (error) {
      message.textContent = (error.message || 'We could not process your request.') + ' You may also email sales@sitesee.ai.';
      message.hidden = false;
      button.innerHTML = 'Request Pricing Access <span aria-hidden="true">↗</span>';
      if (security) security.reset();
    }
  });
})();
