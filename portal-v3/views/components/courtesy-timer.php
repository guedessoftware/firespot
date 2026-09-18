<?php if(!empty($vm['preview']))return; ?>
<script>
(() => {
  const buttons = [...document.querySelectorAll('.courtesy-entry-button[aria-disabled="true"]')];
  const formatWait = seconds => {
    seconds = Math.max(1, Math.ceil(seconds));
    const days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), minutes = Math.floor(seconds % 3600 / 60);
    if (days > 0) return `${days}d ${String(hours).padStart(2, '0')}h`;
    if (hours > 0) return `${hours}h ${String(minutes).padStart(2, '0')}min`;
    if (minutes > 0) return `${minutes}min ${String(seconds % 60).padStart(2, '0')}s`;
    return `${seconds}s`;
  };
  let interval = null;
  const update = () => {
    const now = Math.floor(Date.now() / 1000); let waiting = false;
    buttons.forEach(button => {
      if (button.getAttribute('aria-disabled') !== 'true') return;
      const retryAt = Number(button.dataset.courtesyRetryAt || 0); if (!retryAt) return;
      const remaining = retryAt - now;
      if (remaining > 0) { waiting = true; button.textContent = `Disponível em ${formatWait(remaining)}`; return; }
      button.textContent = button.dataset.courtesyReadyLabel || 'Continuar';
      button.href = button.dataset.courtesyTarget; button.classList.remove('is-disabled');
      button.removeAttribute('aria-disabled'); button.removeAttribute('tabindex');
    });
    if (!waiting && interval) { clearInterval(interval); interval = null; } return waiting;
  };
  buttons.forEach(button => button.addEventListener('click', event => { if (button.getAttribute('aria-disabled') === 'true') event.preventDefault(); }));
  if (update()) interval = setInterval(update, 1000);
})();
</script>
