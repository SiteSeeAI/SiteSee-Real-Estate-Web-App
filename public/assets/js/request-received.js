/* Display only the non-sensitive reference returned by the preferred-date request. */
(() => {
  'use strict';
  const params = new URLSearchParams(window.location.search);
  const reference = params.get('reference') || '';
  if (!/^[A-F0-9]{10}$/.test(reference)) return;
  const get = id => document.getElementById(id);
  document.title = 'Request Received | SiteSee Real Estate';
  get('receipt-title').textContent = 'We’ve Received Your Request.';
  get('receipt-intro').textContent = 'Thank you. Your preferred-date request has been received by SiteSee.';
  get('receipt-reference').textContent = reference;
  get('receipt-mark').hidden = false;
  get('receipt-details').hidden = false;
  const copy = params.get('copy');
  if (copy === 'sent' || copy === 'not-sent') {
    get('receipt-email').textContent = copy === 'sent'
      ? 'We’ve also sent a copy of your request to your email address.'
      : 'Your request has been received, but we couldn’t send the email copy. Keep the reference above for your records.';
    get('receipt-email').hidden = false;
  }
})();
