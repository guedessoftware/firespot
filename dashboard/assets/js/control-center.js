(() => {
  'use strict';

  const node = document.getElementById('cc-nas-catalog');
  if (!node) return;
  let catalog = {};
  try { catalog = JSON.parse(node.textContent || '{}'); } catch (_) { return; }
  const interfaces = catalog.interfaces || {};
  const networks = catalog.next_network || {};

  const fillInterfaces = (form, keepSelection = false) => {
    const nas = form.querySelector('.js-cc-nas');
    const target = form.querySelector('.js-cc-interface');
    if (!nas || !target) return;
    const wanted = keepSelection ? String(target.dataset.selected || target.value || '') : '';
    target.replaceChildren();
    const first = document.createElement('option');
    first.value = '';
    first.textContent = nas.value ? 'Selecione a interface' : 'Selecione primeiro o NAS';
    target.append(first);
    (interfaces[nas.value] || []).forEach(item => {
      const option = document.createElement('option');
      option.value = String(item.id);
      option.textContent = `${item.name} · ${item.type}${item.recommended ? ' · recomendada' : ''}`;
      if (wanted && option.value === wanted) option.selected = true;
      target.append(option);
    });
    const preview = form.querySelector('.js-cc-network-preview');
    if (!preview) return;
    const network = networks[nas.value];
    preview.textContent = network
      ? `Reserva prevista: VLAN ${network.vlan_id} · rede ${network.cidr} · gateway ${network.gateway_ip} · pool ${network.pool_start} — ${network.pool_end} · DNS ${network.dns_servers}. RADIUS herdado da base do NAS.`
      : 'Este NAS não possui outra VLAN automática disponível ou ainda não tem base RADIUS associada.';
  };

  document.querySelectorAll('.js-cc-point-form').forEach(form => {
    const nas = form.querySelector('.js-cc-nas');
    if (!nas) return;
    nas.addEventListener('change', () => fillInterfaces(form,false));
    fillInterfaces(form,true);
  });
})();
