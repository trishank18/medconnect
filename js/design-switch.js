(function () {
  const darkModeKey = 'medconnect-dark-mode';
  function readPreference(key) {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  }
  function savePreference(key, value) {
    try { localStorage.setItem(key, value); } catch (_) { /* Theme control still works without storage. */ }
  }

  const savedDarkMode = readPreference(darkModeKey);
  const darkModeButton = document.createElement('button');
  darkModeButton.className = 'dark-mode-switch';
  darkModeButton.type = 'button';
  darkModeButton.innerHTML = '<span aria-hidden="true">◐</span><span class="dark-mode-switch-label">Dark mode</span>';

  const dashboardHeader = document.body.classList.contains('modern-dashboard')
    ? document.querySelector('.modern-overview-header')
    : null;
  const adminNav = document.body.classList.contains('admin-page')
    ? document.querySelector('.topbar nav')
    : null;
  if (dashboardHeader) dashboardHeader.appendChild(darkModeButton);
  else if (adminNav) adminNav.appendChild(darkModeButton);
  else document.body.appendChild(darkModeButton);

  function applyDarkMode(isDark) {
    document.body.classList.toggle('dark-theme', isDark);
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

  applyDarkMode(savedDarkMode ? savedDarkMode === 'dark' : true);
  darkModeButton.addEventListener('click', function () {
    const isDark = !document.body.classList.contains('dark-theme');
    savePreference(darkModeKey, isDark ? 'dark' : 'light');
    applyDarkMode(isDark);
  });
}());
