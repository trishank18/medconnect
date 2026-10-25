document.querySelectorAll('form[data-csrf-form]').forEach(function (form) {
  const formName = form.dataset.csrfForm;
  const field = form.querySelector('input[name="csrf_token"]');
  const submit = form.querySelector('[type="submit"]');
  if (!formName || !field) return;

  if (submit) submit.disabled = true;
  fetch('csrf-token.php?form=' + encodeURIComponent(formName), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' }
  }).then(function (response) {
    if (!response.ok) throw new Error('Unable to secure this form.');
    return response.json();
  }).then(function (data) {
    if (!data.token) throw new Error('Unable to secure this form.');
    field.value = data.token;
    if (submit) submit.disabled = false;
  }).catch(function () {
    const status = form.querySelector('[data-form-csrf-error]');
    if (status) status.textContent = 'This form could not be secured. Refresh the page and try again.';
  });
});
