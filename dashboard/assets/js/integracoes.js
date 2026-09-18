(() => {
  'use strict';
  const button = document.getElementById('hubsoft-cache-run');
  const status = document.getElementById('hubsoft-cache-run-status');
  if (!button || !status) return;
  button.addEventListener('click', async () => {
    if (button.disabled) return;
    const previous = button.textContent;
    button.disabled = true;
    button.textContent = 'Executando…';
    status.textContent = 'Consultando o HubSoft…';
    try {
      const csrf = document.querySelector('meta[name="firespot-csrf"]')?.content || '';
      const response = await fetch('api/hubsoft_cache_run.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify({csrf})
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Não foi possível concluir a sincronização.');
      status.textContent = payload.message || 'Sincronização concluída.';
      const summary = payload.summary || {};
      const summaryElement = document.getElementById('hubsoft-cache-summary');
      if (summaryElement) summaryElement.textContent = `${Number(summary.processed || 0)} processados · ${Number(summary.updated || 0)} atualizados`;
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Falha na sincronização.';
    } finally {
      button.disabled = false;
      button.textContent = previous;
    }
  });
})();
