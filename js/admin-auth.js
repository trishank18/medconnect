(function () {
  'use strict';

  document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.getElementById(button.getAttribute('aria-controls'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.textContent = show ? 'Hide' : 'Show';
      button.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' ' + (input.dataset.passwordName || 'password'));
      button.setAttribute('aria-pressed', String(show));
    });
  });
}());
