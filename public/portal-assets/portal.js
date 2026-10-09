'use strict';
// Remove obsolete email-link fragments; codes are entered only in the SMS form.
if(location.hash)history.replaceState(null,'',location.pathname+location.search);
// A changed or restored request needs a fresh, explicit window and consent.
const requestedWindow=document.getElementById('arrival-window');
if(requestedWindow?.name==='window'){
  const form=requestedWindow.closest('form'), consent=form.querySelector('input[name=agreed]');
  requestedWindow.addEventListener('change',()=>{if(consent)consent.checked=false;});
  window.addEventListener('pageshow',()=>{requestedWindow.value='';if(consent)consent.checked=false;});
}
