(function () {
  const storageKey = 'medconnect-design';
  const savedDesign = localStorage.getItem(storageKey);
  const body = document.body;
  const button = document.createElement('button');

  button.className = 'design-switch';
  button.type = 'button';
  button.innerHTML = '<span aria-hidden="true">◐</span><span class="design-switch-label">Glass design</span>';
  document.body.appendChild(button);

  function applyDesign(isGlass) {
    body.classList.toggle('glass-theme', isGlass);
    button.setAttribute('aria-pressed', String(isGlass));
    button.setAttribute('aria-label', isGlass ? 'Switch to minimal design' : 'Switch to glass design');
    button.querySelector('.design-switch-label').textContent = isGlass ? 'Minimal design' : 'Glass design';
  }

  applyDesign(savedDesign === 'glass');
  button.addEventListener('click', function () {
    const isGlass = !body.classList.contains('glass-theme');
    localStorage.setItem(storageKey, isGlass ? 'glass' : 'minimal');
    applyDesign(isGlass);
  });
}());