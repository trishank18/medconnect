(function () {
  'use strict';

  const form = document.getElementById('hash-form');
  if (!form) return;

  const input = document.getElementById('hash-input');
  const algorithm = document.getElementById('hash-algorithm');
  const output = document.getElementById('hash-output');
  const status = document.getElementById('hash-status');
  const copyButton = document.getElementById('copy-button');
  let digest = '';

  function setStatus(message, state) {
    status.textContent = message;
    status.dataset.state = state || '';
  }

  function toHex(buffer) {
    return Array.from(new Uint8Array(buffer), function (byte) {
      return byte.toString(16).padStart(2, '0');
    }).join('');
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!window.crypto || !window.crypto.subtle || !window.TextEncoder) {
      digest = '';
      output.textContent = 'This browser does not support secure local hashing.';
      copyButton.disabled = true;
      setStatus('Open this page in a modern browser through localhost or HTTPS.', 'error');
      return;
    }

    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Generating…';
    setStatus('', '');
    try {
      const bytes = new TextEncoder().encode(input.value);
      const result = await window.crypto.subtle.digest(algorithm.value, bytes);
      digest = toHex(result);
      output.textContent = digest;
      copyButton.disabled = false;
      setStatus(algorithm.value + ' · ' + bytes.length + ' UTF-8 bytes', 'success');
    } catch (_) {
      digest = '';
      output.textContent = 'Hash generation failed.';
      copyButton.disabled = true;
      setStatus('The browser could not generate this digest. Try again or use a current browser.', 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Generate hash';
    }
  });

  copyButton.addEventListener('click', async function () {
    if (!digest) return;
    try {
      await navigator.clipboard.writeText(digest);
      setStatus('Digest copied to clipboard.', 'success');
    } catch (_) {
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(output);
      selection.removeAllRanges();
      selection.addRange(range);
      setStatus('Clipboard access is unavailable. The digest is selected; copy it manually.', '');
    }
  });

  document.getElementById('clear-button').addEventListener('click', function () {
    input.value = '';
    digest = '';
    output.textContent = 'Your hash will appear here.';
    copyButton.disabled = true;
    setStatus('', '');
    input.focus();
  });
}());
