'use strict';
(() => {
  const form = document.querySelector('#job-payment');
  if (!form) return;
  const button = form.querySelector('button');
  const error = document.querySelector('#job-payment-error');
  let stripe, elements;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    button.disabled = true; error.textContent = '';
    try {
      if (!elements) {
        const response = await fetch('/account.php', { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'Payment is unavailable. Refresh and try again.');
        if (result.mode === 'paid' || result.mode === 'pending') { location.reload(); return; }
        if (result.mode !== 'payment' || !result.clientSecret) throw new Error('Payment needs a SiteSee review.');
        stripe = window.Stripe(form.dataset.key);
        elements = stripe.elements({ clientSecret: result.clientSecret });
        elements.create('payment').mount('#job-payment-element');
        button.textContent = 'Pay Final Test Balance';
      } else {
        const result = await stripe.confirmPayment({ elements, confirmParams: { return_url: location.origin + '/account.php?view=job&reference=' + encodeURIComponent(form.elements.reference.value) } });
        if (result.error) throw new Error(result.error.message || 'Please check your payment details.');
      }
    } catch (failure) { error.textContent = failure.message || 'Payment needs a SiteSee review.'; }
    finally { button.disabled = false; }
  });
})();
