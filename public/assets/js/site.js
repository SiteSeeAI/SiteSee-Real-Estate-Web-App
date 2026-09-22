/* SiteSee Real Estate · Design interactions only. No network requests or persistent form storage. */
(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const menu = document.querySelector('.menu-toggle');
  const navigation = document.getElementById('primary-navigation');
  if (menu && navigation) {
    menu.hidden = false;
    const closeMenu = () => {
      navigation.classList.remove('is-open');
      menu.setAttribute('aria-expanded', 'false');
    };
    menu.addEventListener('click', () => {
      const open = menu.getAttribute('aria-expanded') !== 'true';
      menu.setAttribute('aria-expanded', String(open));
      navigation.classList.toggle('is-open', open);
    });
    navigation.addEventListener('keydown', event => {
      if (event.key === 'Escape') { closeMenu(); menu.focus(); }
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.site-header')) closeMenu();
    });
    window.matchMedia('(min-width:991px)').addEventListener('change', closeMenu);
  }

  const tabs = [...document.querySelectorAll('[role="tab"]')];
  if (tabs.length) {
    const selectTab = (tab, changeHash = true) => {
      tabs.forEach(item => {
        const active = item === tab;
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
        document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
      });
      if (changeHash) history.replaceState(null, '', '#' + tab.getAttribute('aria-controls'));
    };
    const fromHash = () => {
      const hash = ['#residential', '#commercial'].includes(location.hash) ? '#photography' : location.hash;
      const chosen = tabs.find(tab => '#' + tab.getAttribute('aria-controls') === hash);
      if (chosen) selectTab(chosen, false);
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => selectTab(tab));
      tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next !== undefined) { event.preventDefault(); tabs[next].focus(); selectTab(tabs[next]); }
      });
    });
    window.addEventListener('hashchange', fromHash);
    fromHash();
  }

  document.querySelectorAll('.inquiry-form[data-form-kind="contact"]').forEach(form => {
    const result = form.querySelector('.form-result');
    const resultMessage = form.querySelector('.form-result-message');
    const submit = form.querySelector('[type="submit"]');
    const phone = form.querySelector('[name="phone"]');
    let security;
    const securityReady = window.SiteSeeTurnstile
      ? window.SiteSeeTurnstile.protect(form).then(controller => { security = controller; return controller; })
      : Promise.reject(new Error('Secure form protection did not load.'));
    securityReady.catch(error => {
      const status = form.querySelector('.form-security-status');
      status.textContent = error.message || 'Secure form protection is unavailable.';
    });

    const clearErrors = () => {
      form.querySelectorAll('.validation-message').forEach(item => item.remove());
      form.querySelectorAll('[aria-invalid]').forEach(item => {
        item.removeAttribute('aria-invalid');
        item.removeAttribute('aria-describedby');
      });
    };
    form.addEventListener('input', () => {
      if (phone) phone.setCustomValidity('');
      clearErrors();
      result.hidden = true;
    });
    form.addEventListener('change', () => { result.hidden = true; });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      clearErrors();
      const preference = form.querySelector('[name="preferred_communication"]:checked');
      if (phone && preference && ['Call', 'Text'].includes(preference.value)) {
        phone.setCustomValidity(phone.value.trim() ? '' : 'Enter a phone number for your selected communication preference.');
      }
      const invalid = [...form.querySelectorAll('input,textarea')].find(field => !field.validity.valid);
      if (invalid) {
        invalid.setAttribute('aria-invalid', 'true');
        const error = document.createElement('p');
        error.className = 'validation-message';
        error.id = invalid.id + '-error';
        error.textContent = invalid.validationMessage;
        invalid.setAttribute('aria-describedby', error.id);
        invalid.after(error);
        invalid.focus();
        return;
      }

      try {
        const guard = await securityReady;
        if (!guard.isVerified()) throw new Error('Complete the secure form check to continue.');
        submit.disabled = true;
        submit.textContent = 'Sending…';
        result.hidden = true;
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          body: new FormData(form),
          headers: { Accept: 'application/json' }
        });
        const data = await response.json().catch(() => ({ ok: false, message: 'The server returned an unreadable response.' }));
        if (!response.ok || !data.ok) throw new Error(data.message || 'We could not send your inquiry.');
        const reference = data.reference ? `<p class="form-reference">Reference: ${data.reference}</p>` : '';
        form.innerHTML = '<div class="form-heading"><p class="eyebrow dark">Inquiry Received</p><h2 tabindex="-1">Thank You For Contacting SiteSee.</h2><p>' + data.message + '</p>' + reference + '</div>';
        form.querySelector('h2').focus();
      } catch (error) {
        resultMessage.textContent = (error.message || 'We could not send your inquiry.') + ' You may also email sales@sitesee.ai.';
        result.hidden = false;
        result.focus();
        submit.innerHTML = 'Send Inquiry <span aria-hidden="true">↗</span>';
        if (security) security.reset();
      }
    });
  });
})();
