/* Conditional scheduling fields shared by residential and commercial quote forms. */
(() => {
  'use strict';
  // Shared first-error navigation for both quote forms.
  const errorRegion = field => field.type === 'radio'
    ? field.closest('fieldset') || field.parentElement
    : field.closest('.field, .quote-check, label, .quote-size') || field.parentElement;
  const inspect = field => {
    field.setCustomValidity('');
    if (field.required && !['radio', 'checkbox'].includes(field.type) && !field.value.trim())
      field.setCustomValidity('Please complete this field.');
    if (['phone', 'onsitePhone', 'additionalPhone'].includes(field.name) && field.value.trim() && field.value.replace(/\D/g, '').length < 10)
      field.setCustomValidity('Enter a phone number with at least 10 digits.');
    return field.validity.valid;
  };
  const clearRegion = region => {
    region.classList.remove('quote-field-error');
    region.querySelectorAll('[aria-invalid="true"]').forEach(field => field.removeAttribute('aria-invalid'));
    region.querySelectorAll('.quote-validation-message').forEach(message => message.remove());
  };
  window.SiteSeeValidation = {
    show(field) {
      const region = errorRegion(field);
      field.form.querySelectorAll('.quote-field-error').forEach(clearRegion);
      region.classList.add('quote-field-error');
      field.setAttribute('aria-invalid', 'true');
      const message = document.createElement('span');
      message.className = 'quote-validation-message';
      message.setAttribute('role', 'alert');
      message.textContent = field.validationMessage || 'Please complete this field.';
      region.append(message);
      field.focus({ preventScroll: true });
      region.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    },
    validate(form) {
      const fields = [...form.querySelectorAll('input,select,textarea')]
        .filter(field => field.willValidate && !field.closest('[hidden]'));
      fields.forEach(inspect);
      const first = fields.find(field => !field.validity.valid);
      if (first) { this.show(first); return false; }
      return true;
    },
    attach(form) {
      const clearCorrected = event => {
        const field = event.target;
        if (!field.matches('input,select,textarea')) return;
        const region = field.closest('.quote-field-error');
        if (!region) return;
        const fields = [...region.querySelectorAll('input,select,textarea')].filter(item => item.willValidate);
        if (fields.every(inspect)) clearRegion(region);
      };
      form.addEventListener('input', clearCorrected);
      form.addEventListener('change', clearCorrected);
    }
  };
  const cutoff = (serverSeconds, rush) => {
    const parts = new Intl.DateTimeFormat('en-US', {timeZone:'America/Chicago', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'})
      .formatToParts(new Date((serverSeconds + (rush ? 12 : 72) * 3600) * 1000));
    const p = Object.fromEntries(parts.map(x => [x.type, x.value]));
    return {date:p.year + '-' + p.month + '-' + p.day, time:p.hour + ':' + p.minute + ':' + p.second};
  };
  window.SiteSeeScheduling = {
    cutoff,
    attach(form, prefix) {
      window.SiteSeeValidation.attach(form);
      const get = id => document.getElementById(prefix + id);
      const time = get('shoot-time'), summary = get('arrival-window');
      const date = get('shoot-date'), rush = get('rush-requested'), leadHelp = get('lead-time-help');
      const serverEpoch = Number(form.dataset.serverNow), loadedAt = performance.now();
      const currentCutoff = () => cutoff(serverEpoch + Math.max(0, performance.now() - loadedAt) / 1000, rush.checked);
      const syncLimits = () => {
        const limit = currentCutoff();
        date.min = limit.date;
        for (const option of time.options) option.disabled = Boolean(option.value) && Boolean(date.value)
          && (date.value < limit.date || (date.value === limit.date && option.value + ':00' < limit.time));
        leadHelp.textContent = (rush.checked ? 'Rush: at least 12 hours’ notice, subject to approval.' : 'Standard: at least 72 hours’ notice.')
          + ' Earliest window must start on or after ' + limit.date + ' at ' + limit.time.slice(0, 5) + ' Central Time, using the server clock.';
      };
      const validateWindow = () => {
        syncLimits();
        date.setCustomValidity(''); time.setCustomValidity('');
        if (!date.checkValidity()) { window.SiteSeeValidation.show(date); return false; }
        const limit = currentCutoff();
        if (date.value < limit.date || (date.value === limit.date && time.value + ':00' < limit.time))
          time.setCustomValidity('Choose a window at least ' + (rush.checked ? '12' : '72') + ' hours after the current server time.');
        if (!time.checkValidity()) { window.SiteSeeValidation.show(time); return false; }
        return true;
      };
      date.addEventListener('input', syncLimits);
      date.addEventListener('change', syncLimits);
      rush.addEventListener('change', () => { date.setCustomValidity(''); time.setCustomValidity(''); syncLimits(); });
      window.addEventListener('pageshow', syncLimits);
      syncLimits();
      const showWindow = () => {
        const [hours, minutes] = time.value.split(':').map(Number);
        const end = hours * 60 + minutes + 120;
        summary.textContent = time.value && end < 1440
          ? 'Arrival between ' + time.value + ' and ' + String(Math.floor(end / 60)).padStart(2, '0') + ':' + String(end % 60).padStart(2, '0') + ' Central Time. Shoot duration is separate.'
          : 'Select a two-hour arrival window.';
      };
      time.addEventListener('input', showWindow);
      time.addEventListener('change', showWindow);
      window.addEventListener('pageshow', showWindow);
      showWindow();
      const element = name => form.elements.namedItem(name);
      const value = name => element(name).value.trim();
      const toggle = (id, show, required = []) => {
        const section = get(id);
        section.hidden = !show;
        if (!show) {
          if (section.classList.contains('quote-field-error')) clearRegion(section);
          section.querySelectorAll('.quote-field-error').forEach(clearRegion);
        }
        section.querySelectorAll('input,textarea').forEach(field => {
          field.disabled = !show;
          field.required = show && required.includes(field.name);
          if (!show) {
            if (field.type === 'checkbox' || field.type === 'radio') field.checked = false;
            else field.value = '';
            field.setCustomValidity('');
          }
        });
      };
      const sync = () => {
        const needsAccess = value('meetPhotographer') === 'No';
        toggle('access-fields', needsAccess, ['accessType']);
        const access = needsAccess ? value('accessType') : '';
        toggle('lockbox-fields', access === 'Lockbox', ['lockboxCode']);
        toggle('key-fields', access === 'Key', ['keyLocation']);
        toggle('onsite-fields', get('onsite-different').checked, ['onsiteName','onsiteEmail','onsitePhone']);
        toggle('additional-fields', get('additional-different').checked, ['additionalName','additionalEmail']);
      };
      form.querySelectorAll('[name=meetPhotographer],[name=accessType],[name=onsiteDifferent],[name=additionalDifferent]')
        .forEach(field => field.addEventListener('change', sync));
      window.addEventListener('pageshow', sync);
      sync();
      return {
        validateWindow,
        validate() {
          const fields = [...get('scheduling-fields').querySelectorAll('input,textarea')].filter(field => !field.disabled);
          for (const field of fields) {
            field.setCustomValidity('');
            if (field.required && field.type !== 'radio' && field.type !== 'checkbox' && !field.value.trim())
              field.setCustomValidity('Please complete this field.');
            if (['onsitePhone','additionalPhone'].includes(field.name) && field.value.trim() && field.value.replace(/\D/g, '').length < 10)
              field.setCustomValidity('Enter a phone number with at least 10 digits.');
            if (!field.checkValidity()) {
              window.SiteSeeValidation.show(field);
              return false;
            }
          }
          return true;
        },
        data() {
          return {
            meetPhotographer: value('meetPhotographer'),
            accessType: value('accessType'),
            lockboxCode: value('lockboxCode'),
            keyLocation: value('keyLocation'),
            specialRequests: value('specialRequests'),
            mustHaveShots: value('mustHaveShots'),
            onsiteDifferent: get('onsite-different').checked,
            onsiteName: value('onsiteName'),
            onsiteEmail: value('onsiteEmail'),
            onsitePhone: value('onsitePhone'),
            additionalDifferent: get('additional-different').checked,
            additionalName: value('additionalName'),
            additionalEmail: value('additionalEmail'),
            additionalPhone: value('additionalPhone'),
            cancellationAccepted: get('cancellation-accepted').checked
          };
        }
      };
    }
  };
})();


