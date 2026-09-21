/* Explicit market choice keeps residential and commercial selections separate. */
(() => {
  const choices = [...document.querySelectorAll('[name="property-type"]')];
  if (!choices.length) return;
  function selectMarket(market) {
    if (!['residential','commercial'].includes(market)) return;
    choices.forEach(choice => { choice.checked = choice.value === market; });
    document.getElementById('residential-market').hidden = market !== 'residential';
    document.getElementById('commercial-market').hidden = market !== 'commercial';
    const url = new URL(window.location.href); url.searchParams.set('type',market);
    try { history.replaceState(null,'',url.href); } catch (_) { /* Some local-file previews restrict history changes. */ }
  }
  choices.forEach(choice => choice.addEventListener('change',()=>selectMarket(choice.value)));
  const initial = new URLSearchParams(window.location.search).get('type');
  if (initial) selectMarket(initial);
})();

