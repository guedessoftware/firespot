(function () {
  if (document.body.dataset.page !== 'privacidade') return;

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('.js-log-toggle');
    if (toggle) {
      const target = document.getElementById(toggle.dataset.target || '');
      if (target) {
        target.hidden = !target.hidden;
        toggle.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
      }
    }

    const copy = event.target.closest('.js-copy-log');
    if (!copy) return;
    const id = String(copy.dataset.log || '').replace(/[^0-9]/g, '');
    const receipt = document.querySelector(`.payload-json[data-log-id="${id}"]`);
    if (!receipt) return;
    navigator.clipboard.writeText(receipt.textContent || '')
      .then(() => {
        copy.textContent = 'Copiado!';
        setTimeout(() => { copy.textContent = 'Copiar recibo JSON'; }, 1800);
      })
      .catch(() => { copy.textContent = 'Copie manualmente'; });
  });
})();
