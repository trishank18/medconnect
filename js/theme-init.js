(function () {
  var storageKey = 'medconnect-dark-mode';
  var saved = null;
  try { saved = localStorage.getItem(storageKey); } catch (_) { /* Keep the default theme if storage is blocked. */ }
  var isDark = saved ? saved === 'dark' : true;
  var root = document.documentElement;

  root.classList.toggle('dark-theme', isDark);
  root.style.colorScheme = isDark ? 'dark' : 'light';

  window.medConnectTheme = {
    isDark: isDark,
    setDark: function (next) {
      this.isDark = next;
      root.classList.toggle('dark-theme', next);
      root.style.colorScheme = next ? 'dark' : 'light';
      if (document.body) {
        document.body.classList.toggle('dark-theme', next);
      }
      if (window.Chart) {
        window.Chart.defaults.color = next ? '#94a3b8' : '#64748b';
        window.Chart.defaults.borderColor = next ? '#263449' : '#dce6e7';
      }
      window.dispatchEvent(new CustomEvent('medconnect:themechange', { detail: { dark: next } }));
    }
  };

  if (document.body) {
    document.body.classList.toggle('dark-theme', isDark);
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.toggle('dark-theme', isDark);
    if (window.Chart) {
      window.Chart.defaults.color = isDark ? '#94a3b8' : '#64748b';
      window.Chart.defaults.borderColor = isDark ? '#263449' : '#dce6e7';
    }
  });
}());
