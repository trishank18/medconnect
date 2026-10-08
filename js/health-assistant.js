(function () {
  const form = document.getElementById('healthAssistantForm');
  const messagesElement = document.getElementById('healthAssistantMessages');
  const input = document.getElementById('healthAssistantInput');
  const submitButton = form?.querySelector('button[type="submit"]');
  if (!form || !messagesElement || !input) return;

  const messages = [];

  function addMessage(text, role) {
    const message = document.createElement('div');
    message.className = `health-assistant-message ${role}`;
    message.textContent = text;
    messagesElement.appendChild(message);
    messagesElement.scrollTop = messagesElement.scrollHeight;
    return message;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const question = input.value.trim();
    if (!question || submitButton.disabled) return;

    messages.push({ role: 'user', text: question });
    addMessage(question, 'user');
    input.value = '';
    input.disabled = true;
    submitButton.disabled = true;
    const loadingMessage = addMessage('Thinking...', 'model loading');

    try {
      const response = await fetch('gemini-chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: messages.slice(-12) })
      });
      const data = await response.json();
      loadingMessage.remove();
      if (!response.ok) throw new Error(data.error || 'Unable to reach the assistant.');

      messages.push({ role: 'model', text: data.reply });
      addMessage(data.reply, 'model');
    } catch (error) {
      loadingMessage.textContent = error.message;
      loadingMessage.classList.add('error');
    } finally {
      input.disabled = false;
      submitButton.disabled = false;
      input.focus();
    }
  });
})();