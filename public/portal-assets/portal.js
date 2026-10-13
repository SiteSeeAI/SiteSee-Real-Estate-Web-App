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

// Same account, same order, read-only section navigation. Native POST forms keep their guards.
let orderNavigation;
document.addEventListener('click', async event => {
  const link=event.target.closest('a[data-inline-order]'), workspace=link?.closest('[data-order-workspace]');
  if(!workspace || event.button!==0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  const url=new URL(link.href);
  if(url.origin!==location.origin || url.pathname!=='/account.php' || url.searchParams.get('reference')!==workspace.dataset.reference || !['order','appointment','job','billing'].includes(url.searchParams.get('view'))) return;
  event.preventDefault();orderNavigation?.abort();const controller=new AbortController();orderNavigation=controller;
  const panel=workspace.querySelector('[data-order-panel]');panel.setAttribute('aria-busy','true');
  try {
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
    const page=new DOMParser().parseFromString(await response.text(),'text/html'), replacement=page.querySelector('[data-order-workspace]');
    // Secure payment scripts need the full original CSP and initialization; use the normal route.
    if(!response.ok || !replacement || replacement.dataset.reference!==workspace.dataset.reference || replacement.querySelector('script')) {location.assign(url);return;}
    if(controller.signal.aborted) return;
    const next=document.importNode(replacement,true);workspace.replaceWith(next);history.pushState(null,'',url);
    document.dispatchEvent(new Event('sitesee:order-panel'));
    next.querySelector('[data-order-panel]').focus({preventScroll:true});
  } catch(error) {if(error.name!=='AbortError')location.assign(url);}
});
window.addEventListener('popstate',()=>{if(document.querySelector('[data-order-workspace]'))location.reload();});
function initializeReportDates(){
  const period=document.querySelector('[data-report-period]'),dates=document.querySelector('[data-report-dates]');
  if(!period||!dates)return;
  const update=()=>{dates.hidden=period.value!=='custom';for(const input of dates.querySelectorAll('input'))input.disabled=dates.hidden;};
  period.addEventListener('change',update);update();
}
function initializeArrivalConsent(){
  const select=document.getElementById('arrival-window');if(!select)return;
  const ready=select.dataset.consentReady;select.dataset.consentReady='true';select.value='';const agreed=select.closest('form').querySelector('[name=agreed]');if(agreed)agreed.checked=false;
  if(!ready)select.addEventListener('change',()=>{if(agreed)agreed.checked=false;});
}
initializeReportDates();window.addEventListener('pageshow',initializeArrivalConsent);document.addEventListener('sitesee:order-panel',initializeArrivalConsent);
