(() => {
  const form = document.getElementById('hubsoft-mapping-form');
  if (!form) return;
  const internal = document.getElementById('hubsoft-mapping-internal-id');
  const kind = document.getElementById('hubsoft-mapping-kind');
  const externalId = document.getElementById('hubsoft-mapping-id');
  const label = document.getElementById('hubsoft-mapping-label');
  const profile = document.getElementById('hubsoft-mapping-profile');
  const download = document.getElementById('hubsoft-mapping-download');
  const upload = document.getElementById('hubsoft-mapping-upload');
  const submit = document.getElementById('hubsoft-mapping-submit');
  const cancel = document.getElementById('hubsoft-mapping-cancel');
  const eligible = form.querySelector('[name="eligible_internet"]');
  const active = form.querySelector('[name="active"]');
  const profileRate = () => {
    const option = profile.options[profile.selectedIndex];
    download.value = option?.dataset.download || '10000';
    upload.value = option?.dataset.upload || '3000';
  };
  const reset = () => {
    internal.value = '0';
    form.reset();
    profileRate();
    submit.textContent = 'Salvar mapeamento';
    cancel.hidden = true;
  };
  profile.addEventListener('change', profileRate);
  cancel.addEventListener('click', reset);
  document.querySelectorAll('[data-use-mapping]').forEach((button) => button.addEventListener('click', () => {
    reset();
    kind.value = button.dataset.kind || 'service';
    externalId.value = button.dataset.externalId || '';
    label.value = button.dataset.label || '';
    form.scrollIntoView({behavior:'smooth',block:'center'});
    externalId.focus();
  }));
  document.querySelectorAll('[data-edit-mapping]').forEach((button) => button.addEventListener('click', () => {
    internal.value = button.dataset.id || '0';
    kind.value = button.dataset.kind || 'service';
    externalId.value = button.dataset.externalId || '';
    label.value = button.dataset.label || '';
    profile.value = button.dataset.profileId || profile.value;
    download.value = button.dataset.download || '10000';
    upload.value = button.dataset.upload || '3000';
    eligible.checked = button.dataset.eligible === '1';
    active.checked = button.dataset.active === '1';
    submit.textContent = 'Salvar alterações';
    cancel.hidden = false;
    form.scrollIntoView({behavior:'smooth',block:'center'});
    externalId.focus();
  }));
})();
