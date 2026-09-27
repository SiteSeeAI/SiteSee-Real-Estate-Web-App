(() => {
  'use strict';
  const config = window.siteSeeCheckout || {};
  const form = document.getElementById('payment-consent');
  const errorBox = document.getElementById('payment-error');
  const status = document.getElementById('checkout-status');
  let busy = false;
  let checkout = null;
  const showError = (message) => {
    if (errorBox) { errorBox.textContent = message; errorBox.hidden = false; errorBox.focus(); }
  };
  // The bearer token stays in the authenticated form/session, not the address bar.
  if (config.reference && window.history.replaceState) {
    const clean = new URL(window.location.href);
    clean.searchParams.delete('token');
    window.history.replaceState(null, '', clean.pathname + clean.search);
  }
  const returnToStatus = () => {
    window.location.assign('booking-pay.php?result=success&reference=' + encodeURIComponent(config.reference));
  };
  if (form && config.enabled) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy || !form.reportValidity()) return;
      busy = true;
      const button = form.querySelector('button');
      const label = button.textContent;
      button.disabled = true;
      button.textContent = 'Preparing Secure Payment…';
      errorBox.hidden = true;
      try {
        if (typeof window.Stripe !== 'function') throw new Error('The secure payment form could not load. Please refresh this page and try again.');
        const response = await fetch('booking-pay.php', {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { Accept: 'application/json' }, body: new FormData(form)
        });
        if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Please refresh this page before continuing.');
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'The payment form is unavailable. Please try again.');
        if (result.mode === 'hosted') {
          const url = new URL(result.url);
          if (url.protocol !== 'https:' || url.hostname !== 'checkout.stripe.com') throw new Error('The payment link could not be verified.');
          window.location.assign(url.href);
          return;
        }
        if (result.mode === 'paid' || result.mode === 'pending') { returnToStatus(); return; }
        if (result.mode === 'expired') throw new Error('Your previous payment session expired. Select Continue again to open a fresh payment form.');
        if (result.mode !== 'embedded' || typeof result.clientSecret !== 'string') throw new Error('The payment form could not be prepared.');
        const stripe = window.Stripe(config.publishableKey);
        if (checkout) checkout.destroy();
        checkout = await stripe.createEmbeddedCheckoutPage({ fetchClientSecret: async () => result.clientSecret });
        checkout.mount('#stripe-checkout');
        form.hidden = true;
        status.textContent = 'Complete your deposit securely below.';
        document.getElementById('stripe-checkout').scrollIntoView({block:'nearest',behavior:'instant'});
      } catch (error) {
        if (checkout) { checkout.destroy(); checkout = null; }
        showError(error instanceof Error ? error.message : 'The payment form could not load. Please try again.');
        form.hidden = false;
      } finally {
        busy = false;
        button.disabled = false;
        button.textContent = label;
      }
    });
  }
  if (config.poll && config.reference && config.csrf) {
    let attempts = 0;
    const poll = async () => {
      attempts += 1;
      try {
        const body = new URLSearchParams({action:'payment_status',reference:config.reference,csrf:config.csrf});
        const response = await fetch('booking-pay.php', {method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body});
        if (!response.ok) return;
        const result = await response.json();
        if (result.paid === true) { window.location.reload(); return; }
      } catch (_) { /* Leave the explicit status link available. */ }
      if (attempts < 12) window.setTimeout(poll, 2500);
    };
    window.setTimeout(poll, 1500);
  }
})();
