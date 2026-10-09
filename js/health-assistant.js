(function () {
  const form = document.getElementById('healthAssistantForm');
  const messagesElement = document.getElementById('healthAssistantMessages');
  const input = document.getElementById('healthAssistantInput');
  const submitButton = form?.querySelector('button[type="submit"]');
  if (!form || !messagesElement || !input || form.dataset.bound === 'true') return;
  form.dataset.bound = 'true';
  const panel = form.closest('.health-assistant');
  const launcher = document.getElementById('healthAssistantLauncher');
  document.getElementById('healthAssistantClose')?.addEventListener('click', () => {
    panel?.classList.add('is-closed'); launcher?.classList.add('is-visible');
  });
  launcher?.addEventListener('click', () => {
    panel?.classList.remove('is-closed'); launcher.classList.remove('is-visible'); input.focus();
  });
  const messages = [];

  function addMessage(text, role) {
    const message = document.createElement('div');
    message.className = `health-assistant-message ${role}`;
    message.textContent = text;
    messagesElement.appendChild(message);
    messagesElement.scrollTop = messagesElement.scrollHeight;
    return message;
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const question = input.value.trim();
    if (!question || submitButton.disabled) return;
    addMessage(question, 'user');
    input.value = '';
    input.disabled = submitButton.disabled = true;
    const loading = addMessage('Looking that up…', 'model loading');
    const patientId = document.getElementById('healthAssistantPatient')?.value || '';
    try {
      const response = await fetch(form.dataset.chatEndpoint || 'gemini-chat.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': form.dataset.csrf || '' },
        body: JSON.stringify({ question, patient_id: patientId })
      });
      const data = await response.json();
      loading.remove();
      if (!response.ok) throw new Error(data.error || 'Unable to reach the assistant.');
      let reply = data.reply || 'No answer was returned.';
      if (Array.isArray(data.sources) && data.sources.length) {
        reply += '\n\nSources: ' + data.sources.map(s => `${s.document}${s.page ? `, p. ${s.page}` : ''}`).join('; ');
      }
      addMessage(reply, 'model');
    } catch (error) {
      loading.textContent = error.message;
      loading.classList.add('error');
    } finally {
      input.disabled = submitButton.disabled = false;
      input.focus();
    }
  });
})();
