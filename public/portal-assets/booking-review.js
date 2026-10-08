'use strict';

// Presentation only. Original window forms and their server validation remain intact.
function resetWindowChoices() {
  for (const group of document.querySelectorAll('[data-window-choices]')) {
    const picker = group.querySelector('[data-window-picker]');
    if (!picker) continue;
    picker.value = '';
    for (const form of group.querySelectorAll('form[data-window-option]')) {
      form.hidden = true;
      for (const consent of form.querySelectorAll('input[type=checkbox]')) consent.checked = false;
    }
    picker.closest('label').hidden = false;
  }
}

for (const group of document.querySelectorAll('[data-window-choices]')) {
  const picker = group.querySelector('[data-window-picker]');
  if (!picker) continue;
  picker.addEventListener('change', () => {
    for (const form of group.querySelectorAll('form[data-window-option]')) {
      form.hidden = form.dataset.windowOption !== picker.value;
      for (const consent of form.querySelectorAll('input[type=checkbox]')) consent.checked = false;
    }
  });
}
resetWindowChoices();
window.addEventListener('pageshow', resetWindowChoices);
