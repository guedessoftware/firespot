// assets/js/ui.js
((N)=>{
  if (!N) return;
  if (N.__ui_inited) return;
  N.__ui_inited = true;

  N.ui = { init };

  function init() {
    initSidebar();
    initTheme();
    initUserMenu();
  }

  function initSidebar() {
    const body     = document.body;
    const aside    = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
  const btnOpen  = document.getElementById('toggle-menu');
  const btnDensity = document.getElementById('toggle-sidebar-density');
  if (!aside || !backdrop || !btnOpen) return;

  const mq = window.matchMedia('(max-width: 1120px)');

  const applyDesktopDensity = () => {
    if (mq.matches) {
      body.classList.remove('sidebar-collapsed');
      return;
    }
    const collapsed = localStorage.getItem('firespot-sidebar-collapsed') === '1';
    body.classList.toggle('sidebar-collapsed', collapsed);
    btnDensity?.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
    btnDensity?.setAttribute('title', collapsed ? 'Expandir menu' : 'Recolher menu');
  };

  const setMobileState = (open) => {
    if (!mq.matches) return; // só no mobile
    body.classList.toggle('sidebar-open', open);
    backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    body.style.overflow = open ? 'hidden' : '';
    btnOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  // estado inicial
  if (mq.matches) setMobileState(false);
  applyDesktopDensity();
  mq.addEventListener?.('change', () => {
    if (!mq.matches) {
      body.classList.remove('sidebar-open');
      body.style.overflow = '';
      backdrop.setAttribute('aria-hidden', 'true');
      btnOpen.setAttribute('aria-expanded', 'false');
    } else {
      setMobileState(false);
    }
    applyDesktopDensity();
  });

  // abrir/fechar com o botão ☰
  btnOpen.addEventListener('click', () => setMobileState(!body.classList.contains('sidebar-open')));
  // fechar com backdrop, ESC e clique nos links
  backdrop.addEventListener('click', () => setMobileState(false));
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMobileState(false); });
  aside.querySelectorAll('a[href]').forEach(a => {
    a.addEventListener('click', () => { if (mq.matches) setMobileState(false); });
  });

  btnDensity?.addEventListener('click', () => {
    if (mq.matches) return;
    const next = !body.classList.contains('sidebar-collapsed');
    body.classList.toggle('sidebar-collapsed', next);
    localStorage.setItem('firespot-sidebar-collapsed', next ? '1' : '0');
    btnDensity.setAttribute('aria-label', next ? 'Expandir menu' : 'Recolher menu');
    btnDensity.setAttribute('title', next ? 'Expandir menu' : 'Recolher menu');
  });
}


  function initTheme() {
    const btn  = document.getElementById('toggle-theme');
    const logo = document.getElementById('logo-img');

    const saved       = localStorage.getItem('firespot-theme');
    const bodyAttr    = document.body.getAttribute('data-theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const initial     = (saved || bodyAttr || (prefersDark ? 'dark' : 'light')).toLowerCase() === 'dark' ? 'dark' : 'light';

    applyTheme(initial);

    btn?.addEventListener('click', () => {
      const next = (document.body.getAttribute('data-theme') === 'dark') ? 'light' : 'dark';
      applyTheme(next);
    });

    function applyTheme(mode) {
      const m = (String(mode).toLowerCase() === 'dark') ? 'dark' : 'light';
      document.body.setAttribute('data-theme', m);
      document.documentElement.setAttribute('data-theme', m);
      localStorage.setItem('firespot-theme', m);
      if (btn) {
        const hasModernIcons = !!btn.querySelector('.fs-theme-icon');
        if (!hasModernIcons) btn.textContent = (m === 'dark') ? '☀️' : '🌙';
        btn.setAttribute('aria-label', m === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro');
        btn.setAttribute('title', m === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro');
      }

      // troca logo respeitando diretório relativo atual
      if (logo) {
        const current = logo.getAttribute('src') || 'assets/img/logo-light.png';
        const dir = current.includes('/') ? current.slice(0, current.lastIndexOf('/')) : 'assets/img';
        // A barra moderna é sempre escura; nela a marca clara permanece legível
        // nos dois temas.
        const file = logo.closest('.fs-brand') ? 'logo-dark.png' : ((m === 'dark') ? 'logo-dark.png' : 'logo-light.png');
        const next = `${dir}/${file}`;
        if (current !== next) logo.setAttribute('src', next);
      }
    }
  }

  function initUserMenu() {
    const menu = document.querySelector('.fs-user-menu');
    if (!menu) return;

    document.addEventListener('click', (event) => {
      if (menu.open && !menu.contains(event.target)) menu.open = false;
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menu.open) {
        menu.open = false;
        menu.querySelector('summary')?.focus();
      }
    });
  }
})(window.FIRESPOT);
