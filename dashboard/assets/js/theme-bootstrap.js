try {
  const savedTheme = localStorage.getItem('firespot-theme');
  const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.dataset.theme = savedTheme || preferredTheme;
} catch (_) {}
