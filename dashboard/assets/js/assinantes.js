(() => {
  'use strict';

  const form = document.getElementById('subscriber-rollout-form');
  if (!form) return;

  const guidance = document.getElementById('subscriber-rollout-guidance');
  const inputs = new Map();
  form.querySelectorAll('input[type="checkbox"][name]').forEach((input) => {
    inputs.set(input.name, input);
  });

  const requirements = (input) => (input.dataset.requires || '')
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);

  const dependents = new Map();
  inputs.forEach((input, name) => {
    requirements(input).forEach((requirement) => {
      if (!dependents.has(requirement)) dependents.set(requirement, []);
      dependents.get(requirement).push(name);
    });
  });

  const enableRequirements = (name, changed) => {
    const input = inputs.get(name);
    if (!input) return;
    requirements(input).forEach((requiredName) => {
      const required = inputs.get(requiredName);
      if (!required || required.disabled) return;
      if (!required.checked) {
        required.checked = true;
        changed.add(requiredName);
      }
      enableRequirements(requiredName, changed);
    });
  };

  const disableDependents = (name, changed) => {
    (dependents.get(name) || []).forEach((dependentName) => {
      const dependent = inputs.get(dependentName);
      if (!dependent || !dependent.checked) return;
      dependent.checked = false;
      changed.add(dependentName);
      disableDependents(dependentName, changed);
    });
  };

  const refreshCards = () => {
    inputs.forEach((input) => {
      const card = input.closest('.sub-flag');
      if (card) card.classList.toggle('is-selected', input.checked);
    });
  };

  inputs.forEach((input, name) => {
    input.addEventListener('change', () => {
      const changed = new Set();
      if (input.checked) enableRequirements(name, changed);
      else disableDependents(name, changed);
      refreshCards();
      if (guidance && changed.size > 0) {
        const message = input.checked
          ? 'As etapas anteriores necessárias foram marcadas automaticamente.'
          : 'Os recursos que dependiam desta etapa foram desmarcados.';
        const text = guidance.querySelector('span');
        if (text) text.textContent = message;
      }
    });
  });

  refreshCards();
})();

(() => {
  'use strict';

  const button = document.getElementById('hubsoft-cache-run');
  const statusText = document.getElementById('hubsoft-cache-run-status');
  if (!button || !statusText) return;

  const renderErrors = (errors) => {
    const box = document.getElementById('hubsoft-cache-errors');
    const list = document.getElementById('hubsoft-cache-error-list');
    if (!box || !list) return;
    list.replaceChildren();
    (Array.isArray(errors) ? errors.slice(0, 20) : []).forEach((message) => {
      const line = document.createElement('p');
      line.textContent = String(message);
      list.appendChild(line);
    });
    box.hidden = list.childElementCount === 0;
  };

  button.addEventListener('click', async () => {
    if (button.disabled) return;
    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Executando…';
    statusText.className = 'sub-run-status';
    statusText.textContent = 'Consultando o HubSoft…';

    try {
      const csrf = document.querySelector('meta[name="firespot-csrf"]')?.content || '';
      const response = await fetch('api/hubsoft_cache_run.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ csrf })
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Não foi possível concluir a sincronização.');
      }

      const cache = payload.status || {};
      const summary = cache.summary || {};
      const automaticToday = document.getElementById('hubsoft-cache-automatic-today');
      const manualToday = document.getElementById('hubsoft-cache-manual-today');
      const lastRun = document.getElementById('hubsoft-cache-last-run');
      const summaryText = document.getElementById('hubsoft-cache-summary');
      if (automaticToday) automaticToday.textContent = String(cache.automatic_runs_today ?? 0);
      if (manualToday) manualToday.textContent = String(cache.manual_runs_today ?? 0);
      if (lastRun) lastRun.textContent = cache.last_run_label || 'Agora';
      if (summaryText) {
        const notFound = Number(summary.not_found || 0);
        summaryText.textContent = `${summary.processed ?? 0} processados · ${summary.updated ?? 0} atualizados · ${summary.skipped ?? 0} ignorados${notFound > 0 ? ` · ${notFound} sem correspondência` : ''}`;
      }
      renderErrors(summary.errors);
      statusText.classList.add('is-success');
      statusText.textContent = payload.message ? `Concluída: ${payload.message}` : 'Sincronização concluída.';
    } catch (error) {
      statusText.classList.add('is-error');
      statusText.textContent = error instanceof Error ? error.message : 'Não foi possível concluir a sincronização.';
    } finally {
      button.disabled = false;
      button.textContent = originalLabel;
    }
  });
})();

(() => {
  'use strict';

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    const message = form?.dataset.confirm?.trim() || '';
    if (message && !window.confirm(message)) event.preventDefault();
  });
})();
