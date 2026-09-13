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
      const chosen = tabs.find(tab => '#' + tab.getAttribute('aria-controls') === location.hash);
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

  const labels = {
    'first-name': 'First Name', 'last-name': 'Last Name', 'company-name': 'Company Name',
    email: 'Email Address', phone: 'Phone Number',
    'preferred-communication': 'Preferred Communication', message: 'Project Details'
  };
  document.querySelectorAll('.inquiry-form').forEach(form => {
    const result = form.querySelector('.form-result');
    const summary = form.querySelector('.request-summary');
    const submit = form.querySelector('[type="submit"]');
    const company = form.querySelector('[name="company-name"]');
    const addRow = (label, value) => {
      const row = document.createElement('div');
      const term = document.createElement('dt');
      const detail = document.createElement('dd');
      term.textContent = label;
      detail.textContent = value;
      row.append(term, detail);
      summary.append(row);
    };
    const clearErrors = () => {
      form.querySelectorAll('.validation-message').forEach(item => item.remove());
      form.querySelectorAll('[aria-invalid]').forEach(item => {
        item.removeAttribute('aria-invalid');
        item.removeAttribute('aria-describedby');
      });
    };
    form.addEventListener('input', () => {
      if (company) company.setCustomValidity('');
      clearErrors();
      result.hidden = true;
      summary.replaceChildren();
    });
    form.addEventListener('change', () => { result.hidden = true; summary.replaceChildren(); });
    form.addEventListener('submit', event => {
      event.preventDefault();
      clearErrors();
      if (company && company.required) company.setCustomValidity(company.value.trim() ? '' : 'Enter your company name.');
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
      summary.replaceChildren();
      if (form.dataset.formKind === 'pricing') addRow('Subject', 'Real Estate Div. - Pricing request.');
      const data = new FormData(form);
      for (const [key, label] of Object.entries(labels)) {
        const value = data.get(key);
        if (typeof value === 'string' && value.trim()) addRow(label, value.trim());
      }
      if (form.dataset.formKind === 'pricing') addRow('Mailing-List Opt-Out', data.has('mailing-list-opt-out') ? 'Yes — exclude me from all mailing lists.' : 'Not selected');
      result.hidden = false;
      result.focus();
    });
    form.querySelector('.edit-request').addEventListener('click', () => {
      result.hidden = true;
      summary.replaceChildren();
      form.querySelector('input').focus();
    });
    // Enabling occurs only after the submit interception is in place.
    submit.disabled = false;
  });
})();
