// /dashboard/assets/js/main.js
((N)=>{
  // Se o namespace ainda não existe, não faz nada (ordem de scripts)
  if (!N) return;

  // Aplica o tema o quanto antes (fallback caso ui.js ainda não tenha rodado)
  function applyThemeEarly() {
    try {
      const saved = localStorage.getItem('firespot-theme');
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      const mode = (saved || (prefersDark ? 'dark' : 'light')).toLowerCase() === 'dark' ? 'dark' : 'light';
      document.body.setAttribute('data-theme', mode);
      document.documentElement.setAttribute('data-theme', mode);
    } catch {}
  }

  // Espera por uma condição (ex.: N.ui existir)
  function waitFor(cond, timeout = 2000, step = 50) {
    return new Promise((resolve, reject) => {
      const start = Date.now();
      (function loop() {
        if (cond()) return resolve(true);
        if (Date.now() - start >= timeout) return reject(new Error('waitFor timeout'));
        setTimeout(loop, step);
      })();
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    // 1) Tema já aplicado imediatamente
    applyThemeEarly();

    // 2) Garante que o módulo de UI esteja carregado e inicializado
    try {
      // se ui.js já carregou, ótimo; se não, esperamos um pouco
      await waitFor(() => !!N.ui?.init, 1500).catch(()=>{ /* se não vier, seguimos sem travar */ });
      N.ui?.init();
    } catch (e) {
      console.warn('[main] ui.init não disponível:', e);
    }

    // 3) Só roda o dashboard nesta página
    const page = document.body.dataset.page || '';
    if (page !== 'dashboard') return;

    // 4) O endpoint relativo pertence ao próprio dashboard; evita uma
    // requisição HEAD redundante antes da primeira carga dos indicadores.

    // Espera o Chart.js estar disponível (se o carregamento atrasar)
    try {
      await waitFor(() => typeof window.Chart === 'function', 2000).catch(()=>{});
    } catch {}

    N.charts?.init();
    N.poller?.start();
  });
})(window.FIRESPOT);
