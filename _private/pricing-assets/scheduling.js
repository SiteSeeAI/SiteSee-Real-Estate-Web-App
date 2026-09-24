/* Conditional scheduling fields shared by residential and commercial quote forms. */
(() => {
  'use strict';
  window.SiteSeeScheduling = {
    attach(form, prefix) {
      const get = id => document.getElementById(prefix + id);
      const element = name => form.elements.namedItem(name);
      const value = name => element(name).value.trim();
      const toggle = (id, show, required = []) => {
        const section = get(id);
        section.hidden = !show;
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
        validate() {
          const fields = [...get('scheduling-fields').querySelectorAll('input,textarea')].filter(field => !field.disabled);
          for (const field of fields) {
            field.setCustomValidity('');
            if (field.required && field.type !== 'radio' && field.type !== 'checkbox' && !field.value.trim())
              field.setCustomValidity('Please complete this field.');
            if (['onsitePhone','additionalPhone'].includes(field.name) && field.value.trim() && field.value.replace(/\D/g, '').length < 10)
              field.setCustomValidity('Enter a phone number with at least 10 digits.');
            if (!field.checkValidity()) {
              field.reportValidity();
              field.focus();
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
