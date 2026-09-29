'use strict';
// Keep email tokens out of request URLs and history. A human click consumes the link.
const input = document.getElementById('login-code');
if (input) {
  const code = location.hash.slice(1);
  history.replaceState(null, '', location.pathname + location.search);
  if (/^[a-f0-9]{64}$/.test(code)) {
    input.value = code;
    input.type = 'hidden';
    document.getElementById('code-label').hidden = true;
    document.getElementById('code-help').hidden = true;
  }
}
// Restore from back/forward cache only after the server checks the session again.
addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
