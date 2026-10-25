(function () {
  const storageKey = 'medconnect-design';
  const darkModeKey = 'medconnect-dark-mode';
  function readPreference(key) {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  }
  function savePreference(key, value) {
    try { localStorage.setItem(key, value); } catch (_) { /* Theme controls still work for this page. */ }
  }
  const savedDesign = readPreference(storageKey);
  const savedDarkMode = readPreference(darkModeKey);
  const body = document.body;
  const button = document.createElement('button');
  const darkModeButton = document.createElement('button');

  button.className = 'design-switch';
  button.type = 'button';
  button.innerHTML = '<span aria-hidden="true">◐</span><span class="design-switch-label">Glass design</span>';
  document.body.appendChild(button);

  darkModeButton.className = 'dark-mode-switch';
  darkModeButton.type = 'button';
  darkModeButton.innerHTML = '<span aria-hidden="true">☾</span><span class="dark-mode-switch-label">Dark mode</span>';
  const dashboardHeader = document.body.classList.contains('modern-dashboard')
    ? document.querySelector('.modern-overview-header')
    : null;
  const adminNav = document.body.classList.contains('admin-page')
    ? document.querySelector('.topbar nav')
    : null;
  if (adminNav) adminNav.appendChild(darkModeButton);
  else if (dashboardHeader) dashboardHeader.appendChild(darkModeButton);
  else document.body.appendChild(darkModeButton);

  function applyDesign(isGlass) {
    body.classList.toggle('glass-theme', isGlass);
    button.setAttribute('aria-pressed', String(isGlass));
    button.setAttribute('aria-label', isGlass ? 'Switch to minimal design' : 'Switch to glass design');
    button.querySelector('.design-switch-label').textContent = isGlass ? 'Minimal design' : 'Glass design';
  }

  function applyDarkMode(isDark) {
    body.classList.toggle('dark-theme', isDark);
    document.documentElement.classList.toggle('dark-theme', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    if (window.Chart) {
      window.Chart.defaults.color = isDark ? '#94a3b8' : '#64748b';
      window.Chart.defaults.borderColor = isDark ? '#263449' : '#dce6e7';
      Object.keys(window.Chart.instances || {}).forEach(function (key) {
        window.Chart.instances[key].update();
      });
    }
    darkModeButton.setAttribute('aria-pressed', String(isDark));
    darkModeButton.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
    darkModeButton.querySelector('.dark-mode-switch-label').textContent = isDark ? 'Light mode' : 'Dark mode';
    window.dispatchEvent(new CustomEvent('medconnect:themechange', { detail: { dark: isDark } }));
  }

  applyDesign(savedDesign === 'glass');
  applyDarkMode(savedDarkMode ? savedDarkMode === 'dark' : true);
  button.addEventListener('click', function () {
    const isGlass = !body.classList.contains('glass-theme');
    savePreference(storageKey, isGlass ? 'glass' : 'minimal');
    applyDesign(isGlass);
  });
  darkModeButton.addEventListener('click', function () {
    const isDark = !body.classList.contains('dark-theme');
    savePreference(darkModeKey, isDark ? 'dark' : 'light');
    applyDarkMode(isDark);
  });
}());
