  document.addEventListener('DOMContentLoaded', () => {
    const editModal = document.getElementById('modal-edit');
    const infoModal = document.getElementById('modal-info');
    const closeEdit = document.getElementById('modal-close');
    const closeInfo = document.getElementById('info-close');
    const infoSync = document.getElementById('info-sync');
    const editForm = document.getElementById('form-edit');
    const syncFeedback = document.getElementById('nas-sync-feedback');
    const infoTabs = Array.from(document.querySelectorAll('[data-nas-tab]'));
    const infoPanels = Array.from(document.querySelectorAll('[data-nas-panel]'));
    const csrf = document.querySelector('meta[name="firespot-csrf"]')?.content || '';

    function setNasInfoTab(tabName, moveFocus = false) {
      const selected = infoTabs.some((tab) => tab.dataset.nasTab === tabName) ? tabName : 'overview';
      infoTabs.forEach((tab) => {
        const active = tab.dataset.nasTab === selected;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        if (active && moveFocus) tab.focus();
      });
      infoPanels.forEach((panel) => { panel.hidden = panel.dataset.nasPanel !== selected; });
    }

    infoTabs.forEach((tab, index) => {
      tab.addEventListener('click', () => setNasInfoTab(tab.dataset.nasTab || 'overview'));
      tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft','ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        const direction = event.key === 'ArrowRight' ? 1 : -1;
        const next = (index + direction + infoTabs.length) % infoTabs.length;
        setNasInfoTab(infoTabs[next].dataset.nasTab || 'overview', true);
      });
    });

    function showSyncFeedback(message, isError = false) {
      if (!syncFeedback) return;
      syncFeedback.textContent = message;
      syncFeedback.hidden = false;
      syncFeedback.classList.toggle('is-error', isError);
      syncFeedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    try {
      const storedFeedback = sessionStorage.getItem('firespotNasSyncFeedback');
      if (storedFeedback) {
        sessionStorage.removeItem('firespotNasSyncFeedback');
        showSyncFeedback(storedFeedback, false);
      }
    } catch (ignored) {}

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    }

    function renderInterfaces(list, placeholder = 'Nenhuma interface sincronizada para este NAS.') {
      const container = document.getElementById('info-interfaces');
      if (!container) return;
      if (!Array.isArray(list) || list.length === 0) {
        container.innerHTML = `<div class="muted nas-empty-row">${escapeHtml(placeholder)}</div>`;
        return;
      }
      const typeOrder = { ether: 1, bridge: 2, vlan: 3, eoip: 4, wg: 5, loopback: 6 };
      const ordered = [...list].sort((left, right) => {
        const leftRank = typeOrder[String(left.type || '').toLowerCase()] || 50;
        const rightRank = typeOrder[String(right.type || '').toLowerCase()] || 50;
        return leftRank - rightRank || String(left.name || '').localeCompare(String(right.name || ''),'pt-BR');
      });
      const rows = ordered.map((iface) => {
        const vlan = iface.vlan_id !== null && iface.vlan_id !== undefined ? iface.vlan_id : '—';
        const ip = iface.ip_address || iface.ip || '—';
        const mac = iface.mac_address || iface.mac || '—';
        const desc = iface.comment || iface.description || '—';
        const status = iface.disabled === 'yes' ? ' <small class="muted">desativada</small>' : '';
        return `<tr><td>${escapeHtml(iface.name || '—')}${status}</td><td><span class="nas-interface-type">${escapeHtml(iface.type || 'other')}</span></td><td>${escapeHtml(vlan)}</td><td>${escapeHtml(ip)}</td><td>${escapeHtml(mac)}</td><td>${escapeHtml(desc)}</td></tr>`;
      }).join('');
      container.innerHTML = `<table><thead><tr><th>Interface</th><th>Tipo</th><th>VLAN</th><th>IP</th><th>MAC</th><th>Descrição</th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    function renderPppSessions(list) {
      const container = document.getElementById('info-ppp-sessions');
      if (!container) return;
      if (!Array.isArray(list) || list.length === 0) {
        container.innerHTML = '<div class="muted nas-empty-row">Nenhuma sessão PPP ativa na última sincronização.</div>';
        return;
      }
      const rows = list.map((session) => `<tr><td>${escapeHtml(session.username || '—')}</td><td>${escapeHtml(session.service || 'ppp')}</td><td>${escapeHtml(session.address || '—')}</td><td>${escapeHtml(session.caller_id || '—')}</td><td>${escapeHtml(session.uptime || '—')}</td></tr>`).join('');
      container.innerHTML = `<table><thead><tr><th>Usuário</th><th>Serviço</th><th>Endereço</th><th>Origem</th><th>Uptime</th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    async function syncNas(nasId, button) {
      if (!nasId || !button || button.disabled) return;
      const original = button.textContent;
      button.disabled = true;
      button.textContent = 'Sincronizando…';
      showSyncFeedback('Consultando o MikroTik. Nenhuma configuração remota será alterada.', false);
      try {
        const response = await fetch('api/nas_interfaces_refresh.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
          body: `id=${encodeURIComponent(nasId)}&csrf=${encodeURIComponent(csrf)}`
        });
        const raw = await response.text();
        let payload;
        try { payload = raw ? JSON.parse(raw) : null; }
        catch (error) { throw new Error('O servidor retornou uma resposta inválida.'); }
        if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Não foi possível sincronizar o NAS.');
        const version = payload.resource?.version ? ` · RouterOS ${payload.resource.version}` : '';
        const message = `${payload.message || 'NAS sincronizado com sucesso.'}${version}.`;
        try { sessionStorage.setItem('firespotNasSyncFeedback', message); } catch (ignored) {}
        window.location.reload();
      } catch (error) {
        showSyncFeedback(error?.message || 'Não foi possível sincronizar o NAS.', true);
        button.disabled = false;
        button.textContent = original;
      }
    }

    function openModal(modal) {
      if (!modal) return;
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
    }

    function closeModal(modal) {
      if (!modal) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
      if (modal === editModal) {
        editForm.reset();
      }
    }

    document.querySelectorAll('.js-edit').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const row = btn.closest('tr');
        if (!row) return;
        try {
          const data = JSON.parse(row.dataset.nas);
          document.getElementById('edit-id').value = data.id;
          document.getElementById('edit-nasname').value = data.nasname || '';
          document.getElementById('edit-shortname').value = data.shortname || '';
          document.getElementById('edit-type').value = ['mikrotik', 'routeros'].includes(String(data.type || '').toLowerCase()) ? 'mikrotik' : 'other';
          document.getElementById('edit-ports').value = data.ports ?? '';
          document.getElementById('edit-secret').value = '';
          document.getElementById('edit-server').value = data.server || '';
          document.getElementById('edit-community').value = data.community || '';
          document.getElementById('edit-mgmt-user').value = data.mgmt_username || '';
          document.getElementById('edit-mgmt-pass').value = '';
          document.getElementById('edit-mgmt-port').value = data.mgmt_port ?? '';
          document.getElementById('edit-radius-server').value = data.radius_server_id || '';
          const policyFields={
            'edit-vlan-start':data.vlan_start,'edit-vlan-end':data.vlan_end,
            'edit-network-template':data.network_template,'edit-prefix-length':data.prefix_length,
            'edit-gateway-offset':data.gateway_offset,'edit-pool-start-offset':data.pool_start_offset,
            'edit-pool-end-reserve':data.pool_end_reserve,'edit-default-dns':data.default_dns_servers,
          };
          Object.entries(policyFields).forEach(([id,value])=>{const field=document.getElementById(id);if(field)field.value=value??'';});
          document.getElementById('edit-description').value = data.description || '';
          openModal(editModal);
        } catch (e) {
          console.error(e);
        }
      });
    });

    function openNasInfo(row) {
        if (!row) return;
        try {
          const data = JSON.parse(row.dataset.nas);
          const stats = data.stats || {};
          const interfaces = Array.isArray(data.interfaces) ? data.interfaces : [];
          const pppSessions = Array.isArray(data.ppp_sessions) ? data.ppp_sessions : [];
          document.getElementById('info-heading-name').textContent = `${data.shortname || data.nasname || 'NAS'} · ${data.nasname || 'endereço não informado'}`;
          document.getElementById('info-nasname').textContent = data.nasname || '—';
          document.getElementById('info-shortname').textContent = data.shortname || '—';
          document.getElementById('info-type').textContent = data.type || '—';
          const totalPorts = data.interface_count ?? interfaces.length ?? data.ports;
          document.getElementById('info-ports').textContent = totalPorts !== undefined && totalPorts !== null ? totalPorts : '—';
          document.getElementById('info-active').textContent = stats.active_sessions ?? 0;
          document.getElementById('info-total').textContent = stats.total_sessions ?? 0;
          document.getElementById('info-distinct').textContent = stats.distinct_devices ?? 0;
          document.getElementById('info-last-start').textContent = stats.last_start ?? '—';
          document.getElementById('info-last-stop').textContent = stats.last_stop ?? '—';
          document.getElementById('info-mgmt-user').textContent = data.mgmt_username || '—';
          document.getElementById('info-mgmt-port').textContent = data.mgmt_port ?? '—';
          document.getElementById('info-base-status').textContent = data.base_status === 'ready' ? 'Pronta' : (data.base_status === 'error' ? `Com erro — ${data.base_error || 'verifique a configuração'}` : (data.base_status === 'applying' ? 'Aplicando' : 'Pendente'));
          document.getElementById('info-routeros').textContent = data.health_routeros_version || data.base_routeros_version || '—';
          document.getElementById('info-radius').textContent = data.base_radius_host ? `${data.base_radius_name || 'RADIUS'} — ${data.base_radius_host}` : '—';
          document.getElementById('info-sync-at').textContent = data.health_checked_at ? `Atualizado em ${data.health_checked_at}` : 'Nunca sincronizado';
          document.getElementById('info-latency').textContent = data.latency_ms !== null && data.latency_ms !== undefined ? `${data.latency_ms} ms` : '—';
          document.getElementById('info-board').textContent = data.board_model || '—';
          document.getElementById('info-uptime').textContent = data.uptime || '—';
          const cpuLoad = data.cpu_load !== null && data.cpu_load !== undefined ? String(data.cpu_load) : '';
          document.getElementById('info-cpu').textContent = cpuLoad ? (cpuLoad.endsWith('%') ? cpuLoad : `${cpuLoad}%`) : '—';
          document.getElementById('info-memory').textContent = data.memory_free || '—';
          document.getElementById('info-hotspot-hosts').textContent = data.hotspot_host_count ?? '—';
          document.getElementById('info-ppp-active').textContent = data.ppp_active_count ?? pppSessions.length;
          document.getElementById('info-hotspot-servers').textContent = data.hotspot_server_count ?? '—';
          document.getElementById('info-vlans').textContent = data.vlan_count ?? '—';
          document.getElementById('info-radius-hotspot').textContent = data.radius_hotspot_count ?? '—';
          document.getElementById('info-firespot-radius').textContent = data.firespot_radius_count === null || data.firespot_radius_count === undefined
            ? 'Ainda não conferida'
            : (Number(data.firespot_radius_count) === 1 ? 'Confirmada' : (Number(data.firespot_radius_count) === 0 ? 'Ausente' : `${data.firespot_radius_count} entradas encontradas`));
          document.getElementById('info-coa').textContent = data.coa_status === 'ready'
            ? `Pronta · UDP ${data.coa_port || 3799}`
            : (data.coa_status === 'error' ? `Indisponível${data.coa_error_code ? ` · ${data.coa_error_code}` : ''}` : 'Ainda não conferida');
          document.getElementById('info-installations').textContent = data.installation_count ?? 0;
          document.getElementById('info-hardware-health').textContent = data.temperature || data.voltage ? `${data.temperature || '—'} · ${data.voltage || '—'}` : '—';
          document.getElementById('info-interface-count').textContent = String(interfaces.length);
          document.getElementById('info-ppp-count').textContent = String(pppSessions.length);
          const healthStatus = document.getElementById('info-health-status');
          const synchronized = data.health_status === 'ok' && Boolean(data.health_routeros_version || data.base_routeros_version);
          const failed = data.health_status === 'error';
          healthStatus.textContent = synchronized ? 'Sincronizado' : (failed ? 'Falha na consulta' : 'Sem sincronização');
          healthStatus.className = `nas-status ${synchronized ? 'is-ready' : (failed ? 'is-error' : 'is-pending')}`;
          infoSync.dataset.nasId = data.id;
          renderInterfaces(interfaces);
          renderPppSessions(pppSessions);
          setNasInfoTab('overview');
          openModal(infoModal);
        } catch (e) {
          console.error(e);
        }
    }

    document.querySelectorAll('.js-info').forEach(button => {
      button.addEventListener('click', () => openNasInfo(button.closest('.js-nas-row')));
    });
    document.querySelectorAll('.js-sync').forEach(button => {
      button.addEventListener('click', () => syncNas(Number(button.dataset.nasId || 0),button));
    });
    infoSync?.addEventListener('click', () => syncNas(Number(infoSync.dataset.nasId || 0),infoSync));

    closeEdit?.addEventListener('click', () => closeModal(editModal));
    closeInfo?.addEventListener('click', () => closeModal(infoModal));
    editModal?.addEventListener('click', ev => { if (ev.target === editModal) closeModal(editModal); });
    infoModal?.addEventListener('click', ev => { if (ev.target === infoModal) closeModal(infoModal); });
  });
