(() => {
  'use strict';
  const form = document.querySelector('#job-onsite');
  if (!form) return;
  const config = JSON.parse(form.dataset.config), services = config.catalog.services;
  const list = document.querySelector('#job-service-items'), add = document.querySelector('#job-add-item');
  const save = document.querySelector('#job-save'), complete = document.querySelector('#job-complete');
  const agreed = form.elements.agreed, status = document.querySelector('#job-price-status');
  const money = cents => new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'}).format(cents / 100);
  let rows = config.items.map(item => ({...item, inputs: {...item.inputs}}));
  if (!rows.length) rows.push({service: '', inputs: {}});
  let revision = 0, timer, controller, ready = false, submitting = false;
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  const selectedItems = () => rows.filter(row => row.service || row.legacy_id).map(row => row.legacy_id
    ? {legacy_id: row.legacy_id} : {service: row.service, inputs: {...row.inputs}});
  function invalidate() {
    ready = false; revision++;
    agreed.checked = false; agreed.disabled = true; save.disabled = complete.disabled = true;
    form.elements.scope.value = ''; form.elements.items.value = JSON.stringify(selectedItems());
    status.textContent = 'Checking the current service prices…';
    for (const id of ['job-extra-total', 'job-total', 'job-due']) document.getElementById(id).textContent = '—';
    document.querySelector('#job-commission').value = '—';
    for (const row of rows) if (row.fee) row.fee.textContent = '—';
    clearTimeout(timer); if (controller) controller.abort();
    timer = setTimeout(preview, 180);
  }
  function selectOptions() {
    const chosen = rows.map(row => row.service).filter(Boolean);
    for (const row of rows) {
      if (!row.select) continue;
      row.select.replaceChildren(new Option('Choose A Service', ''));
      for (const [key, service] of Object.entries(services)) {
        if (key !== row.service && chosen.includes(key)) continue;
        const suffix = service.ordered ? ' · Already ordered' : '';
        row.select.add(new Option(service.label + suffix, key));
      }
      row.select.value = row.service;
    }
    add.hidden = chosen.length === Object.keys(services).length || rows.some(row => !row.service && !row.legacy_id) || rows.length >= 20;
  }
  function render() {
    list.replaceChildren();
    rows.forEach((row, index) => {
      const box = element('fieldset'); box.style.minWidth = '0';
      box.append(element('legend', 'Additional Service ' + (index + 1)));
      if (row.legacy_id) {
        const saved = config.saved.find(line => line.id === row.legacy_id);
        box.append(element('p', saved.label), element('p', 'Previously entered service · ' + money(saved.cents), 'help'));
      } else {
        const label = element('label', 'Service');
        row.select = element('select'); row.select.setAttribute('aria-label', 'Service ' + (index + 1));
        label.append(row.select); box.append(label);
        row.select.addEventListener('change', () => {
          row.service = row.select.value; row.inputs = {};
          render(); invalidate(); row.select.focus();
        });
        const service = services[row.service];
        if (service) {
          if (service.note) box.append(element('p', service.note, 'help'));
          if (service.ordered) box.append(element('p', 'This service was already ordered. This addition earns no onsite commission.', 'help'));
          for (const field of service.fields) {
            const fieldLabel = element('label', field.label);
            const input = element(field.type === 'select' ? 'select' : 'input');
            input.setAttribute('aria-label', field.label);
            if (field.type === 'select') {
              for (const [value, text] of Object.entries(field.options)) input.add(new Option(text, value));
            } else {
              input.type = 'number'; input.min = field.min; input.max = field.max; input.step = '1'; input.required = true;
            }
            input.value = row.inputs[field.key] ?? field.value;
            row.inputs[field.key] = input.value;
            fieldLabel.append(input); box.append(fieldLabel);
            input.addEventListener(field.type === 'select' ? 'change' : 'input', () => {
              row.inputs[field.key] = input.value;
              if (field.key === 'licenseType') {
                const term = box.querySelector('[data-license-term]');
                if (term) {term.hidden = input.value === 'unlimited'; term.querySelector('input').disabled = term.hidden;}
              }
              invalidate();
            });
            if (field.key === 'licenseMonths') {fieldLabel.dataset.licenseTerm = 'true'; fieldLabel.hidden = row.inputs.licenseType === 'unlimited'; input.disabled = fieldLabel.hidden;}
          }
        }
      }
      row.fee = element('p', '—'); row.fee.dataset.serviceFee = 'true'; box.append(row.fee);
      const remove = element('button', 'Remove Service'); remove.type = 'button';
      remove.addEventListener('click', () => {
        rows = rows.filter(candidate => candidate !== row);
        if (!rows.length) rows.push({service: '', inputs: {}});
        render(); invalidate();
      });
      box.append(remove); list.append(box);
    });
    selectOptions();
  }
  async function preview() {
    const ticket = revision;
    controller = new AbortController();
    try {
      const body = new FormData(form); body.set('action', 'job_preview'); body.delete('agreed');
      const response = await fetch('/staff-bookings.php', {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
      const result = await response.json();
      if (ticket !== revision) return;
      if (!response.ok || !result.bill) throw Error(result.error || 'Sign in again to check the current service prices.');
      const bill = result.bill;
      document.querySelector('#job-extra-total').textContent = money(bill.extras.reduce((sum, line) => sum + line.cents, 0));
      document.querySelector('#job-total').textContent = money(bill.total_cents);
      document.querySelector('#job-due').textContent = money(bill.due_cents);
      document.querySelector('#job-commission').value = money(bill.commission_cents);
      document.querySelector('#job-commission-field').hidden = !bill.extras.length;
      document.querySelector('#job-commission-note').hidden = !bill.extras.length;
      document.querySelector('#job-legacy-note').hidden = !bill.commission_unclassified;
      const monthly = document.querySelector('#job-monthly'); monthly.hidden = !bill.additional_monthly_cents;
      monthly.textContent = 'Separate platform billing: ' + money(bill.additional_monthly_cents) + '/month after publication. Excluded from this payment and commission.';
      for (const row of rows) {
        const priced = bill.extras.find(line => row.legacy_id ? line.id === row.legacy_id : line.service === row.service);
        row.fee.textContent = priced ? 'Service Fee: ' + money(priced.cents) + (priced.monthly_cents ? ' + ' + money(priced.monthly_cents) + '/month separately' : '') : '';
        if (priced?.details?.length) row.fee.append(element('span', ' · ' + priced.details.join(' · ')));
      }
      form.elements.scope.value = bill.scope;
      ready = true; agreed.disabled = false; save.disabled = complete.disabled = false;
      status.textContent = 'Fees verified. Confirm the displayed total with the agent before Job Complete.';
    } catch (error) {
      if (ticket !== revision || error.name === 'AbortError') return;
      status.textContent = error instanceof SyntaxError ? 'Your staff session needs to be refreshed. Reload and sign in again.' : error.message;
    }
  }
  add.addEventListener('click', () => {
    rows.push({service: '', inputs: {}}); render(); invalidate(); rows.at(-1).select.focus();
  });
  form.addEventListener('submit', event => {
    if (!ready || submitting || !event.submitter) {event.preventDefault(); return;}
    if (event.submitter === complete && !agreed.checked) {event.preventDefault(); agreed.focus(); return;}
    submitting = true;
  });
  render(); invalidate();
})();
