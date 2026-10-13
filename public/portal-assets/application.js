'use strict';

const compactWorkspace = window.matchMedia('(max-width:1179px)');
for (const details of document.querySelectorAll('.summary-more')) {
  const resizeSummary = () => { details.open = !compactWorkspace.matches; };
  resizeSummary(); compactWorkspace.addEventListener('change', resizeSummary);
}

// GET-only list preferences. Changing one group preserves the other group's page.
for (const form of document.querySelectorAll('form[data-auto-list]')) {
  form.querySelector('.list-options-hint').textContent = 'Changes update automatically.';
  for (const select of form.querySelectorAll('select')) {
    select.addEventListener('change', () => {
      const page = form.elements.namedItem(select.name.replace('_size', '_page'));
      if (select.name.endsWith('_size') && page) page.value = '1';
      form.querySelector('[aria-live]').textContent = 'Updating orders…';
      form.requestSubmit();
    });
  }
}

// Presentation only. Navigation selects an existing form; submission still needs
// its original fields, consent, CSRF token and server-side state validation.
for (const panel of document.querySelectorAll('[data-focus-actions]')) {
  if (panel.closest('[hidden]')) continue;
  const configured = panel.hasAttribute('data-focus-primary') ? JSON.parse(panel.dataset.focusPrimary) : null;
  const entries = [...panel.querySelectorAll('form')].filter(form => !form.hasAttribute('data-window-option')).map(form => {
    const action = form.querySelector('[name=action]')?.value;
    const button = form.querySelector('button');
    if (panel.dataset.focusKind === 'appointment' && configured && ['lifecycle_windows', 'lifecycle_reschedule', 'lifecycle_cancel'].includes(action) && !configured.includes(action)) {
      form.hidden = true; return null;
    }
    return action && button ? {form, action, label: button.textContent.trim()} : null;
  }).filter(Boolean);
  if (!entries.length) continue;
  const identityFields = ['request_id', 'message_id', 'contact_id', 'notice_revision'];
  for (const entry of entries) {
    entry.identity = Object.fromEntries(identityFields.map(name => [name, entry.form.querySelector('[name=' + name + ']')?.value]).filter(([, value]) => value !== undefined && value !== ''));
    entry.key = entry.action + (Object.keys(entry.identity).length ? '.' + JSON.stringify(entry.identity) : '');
  }
  const task = new URL(location.href).searchParams.get('task'), posted = panel.dataset.currentAction;
  const postedIdentity = JSON.parse(panel.dataset.currentIdentity || '{}');
  const matchesPost = entry => entry.action === posted && JSON.stringify(entry.identity) === JSON.stringify(postedIdentity);
  const contacts = entries.filter(entry => entry.action === 'workflow_link');
  const checkedContacts = panel.dataset.focusKind === 'readiness' && posted === 'workflow_check' && contacts.length > 0;
  let selected = checkedContacts ? (contacts.length === 1 ? contacts[0] : null) : entries.find(entry => entry.key === task && (!posted || matchesPost(entry))) || (posted ? entries.find(matchesPost) : (!task && entries.length === 1 ? entries[0] : null));
  const menu = document.createElement('nav');
  menu.className = 'action-menu';
  menu.setAttribute('aria-label', 'Choose booking action');
  const more = document.createElement('details'), moreMenu = document.createElement('nav'), summary = document.createElement('summary');
  summary.textContent = 'Other actions and saved status';
  moreMenu.className = 'action-menu'; moreMenu.setAttribute('aria-label', 'Other booking actions');
  more.append(summary, moreMenu);
  const priorities = {
    review: ['review_paid', 'approve', 'decline_rush', 'reschedule_link', 'vendor_assign', 'vendor_revoke'],
    readiness: entries.some(e => e.action === 'resume_invitation') ? ['resume_invitation'] : entries.some(e => e.action === 'workflow_link') ? ['workflow_link'] : ['workflow_check', 'workflow_recover'],
    calendar: ['confirm_calendar', 'reconcile_calendar', 'send_invitation'],
    appointment: entries.some(e => e.action === 'lifecycle_approve_request') ? ['lifecycle_approve_request', 'lifecycle_reject_request'] : entries.some(e => e.action === 'lifecycle_close_order') ? ['lifecycle_close_order'] : ['lifecycle_windows', 'lifecycle_cancel'],
    customer: entries.some(e => e.action === 'appointment_reschedule') ? ['appointment_reschedule'] : ['appointment_windows', 'appointment_cancel']
  };
  const primary = checkedContacts ? ['workflow_link'] : configured || priorities[panel.dataset.focusKind] || [];
  const labels = {lifecycle_windows: 'Choose another arrival window', lifecycle_cancel: 'Cancel appointment', appointment_windows: 'Find available windows', appointment_cancel: 'Cancel appointment', confirm_calendar: 'Confirm appointment', send_invitation: 'Send calendar invitation'};
  for (const entry of entries) {
    const link = document.createElement('a'), url = new URL(location.href);
    url.searchParams.set('task', entry.key);
    url.hash = 'current-action';
    link.href = url.pathname + url.search + url.hash;
    const identityLabel = entry.form.querySelector('p')?.textContent.trim() || entry.form.closest('details')?.querySelector('summary')?.textContent.trim() || Object.values(entry.identity).join(' · ');
    link.textContent = (labels[entry.action] || entry.label) + (entry.key !== entry.action ? ' · ' + identityLabel : '') + ' →';
    link.addEventListener('click', event => selectAction(event, entry, link));
    (primary.includes(entry.action) ? menu : moreMenu).append(link);
  }
  const choice = document.createElement('section');
  choice.className = 'action-choice';
  function renderSelection(focus = false) {
    choice.replaceChildren(); choice.hidden = false;
    for (const entry of entries) entry.form.hidden = entry !== selected;
    let heading;
    if (selected) {
      heading = document.createElement('h3');
      heading.id = 'current-action';
      heading.textContent = selected.label;
      const back = document.createElement('a'), url = new URL(location.href);
      url.searchParams.delete('task'); url.hash = '';
      back.href = url.pathname + url.search;
      back.textContent = '← Choose a different action';
      back.addEventListener('click', event => selectAction(event, null, back));
      if (entries.length > 1) choice.append(back, heading);
      else choice.hidden = true;
      for (let ancestor = selected.form.parentElement; ancestor && ancestor !== panel; ancestor = ancestor.parentElement) {
        if (ancestor.tagName === 'DETAILS') ancestor.open = true;
      }
    } else {
      heading = document.createElement('h3');
      heading.id = 'current-action';
      heading.textContent = checkedContacts ? 'Verify the customer’s CRM contact' : 'Choose the next action';
      choice.append(heading, menu);
      if (moreMenu.childElementCount) choice.append(more);
    }
    // A chooser is revealed only within the explicit alternatives action.
    for (const group of panel.querySelectorAll('[data-window-choices]')) {
      group.hidden = !selected || !['lifecycle_windows', 'lifecycle_reschedule', 'check_windows', 'select_window'].includes(selected.action);
    }
    // Fold action groups that do not belong to the selected task. Evidence-only
    // disclosures remain available; choosing or opening them writes no data.
    for (const detail of panel.querySelectorAll('details')) {
      const contained = entries.filter(entry => detail.contains(entry.form));
      if (contained.length) detail.hidden = !selected || !detail.contains(selected.form);
    }
    if (focus && !choice.hidden) { heading.tabIndex = -1; heading.focus(); }
  }
  function selectAction(event, entry, link) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (entry && selected !== entry) for (const checkbox of entry.form.querySelectorAll('input[type=checkbox]')) checkbox.checked = false;
    if (selected !== entry) for (const group of panel.querySelectorAll('[data-window-choices]')) {
      const picker = group.querySelector('[data-window-picker]');
      if (picker) picker.value = '';
      for (const form of group.querySelectorAll('form[data-window-option]')) {
        form.hidden = true;
        for (const checkbox of form.querySelectorAll('input[type=checkbox]')) checkbox.checked = false;
      }
    }
    selected = entry;
    // Keep the returned report and its native, server-validated forms in this
    // document. A GET reload would discard ephemeral contact/history choices.
    history.replaceState(null, '', link.href);
    renderSelection(true);
  }
  panel.prepend(choice);
  renderSelection();
  panel.dataset.focusReady = 'true';
}
