// assets/js/table-online-fixed.js
((N)=>{
  if (!N) return;

  const lastByUser = new Map(); // username -> {in,out,ts}
  let lastTableKey = '';

  N.updateTableOnline = function(rows) {
    const tbody = document.querySelector('#tbl-online tbody');
    if (!tbody) return;

    const now = Date.now();
    const list = Array.isArray(rows) ? rows : [];

    let html = '';
    for (const r of list) {
      const key = r.username || r.nome || r.mac || Math.random().toString(36).slice(2);

      // deltas para taxa atual (kbps)
      let upKbps = 0, downKbps = 0;
      const prev = lastByUser.get(key);
      if (prev) {
        const dt = (now - prev.ts) / 1000;
        if (dt > 0) {
          const din  = Math.max(0, (Number(r.in)  || 0) - prev.in);
          const dout = Math.max(0, (Number(r.out) || 0) - prev.out);
          // down = din (download), up = dout (upload)
          downKbps = Math.round((din  * 8) / 1000 / dt);
          upKbps   = Math.round((dout * 8) / 1000 / dt);
        }
      }
      lastByUser.set(key, { in: Number(r.in)||0, out: Number(r.out)||0, ts: now });

      // totais acumulados da sessão (bytes)
      const downTotal = N.utils.formatBytes(Number(r.in)  || 0);
      const upTotal   = N.utils.formatBytes(Number(r.out) || 0);

      // Ordem correta das colunas:
      // 1. Nome, 2. Dispositivo, 3. Host de Origem, 4. Tempo online, 5. Up, 6. Down, 7. Perfil, 8. Visitas, 9. Ações
      const tempoMeta = [];
      if (r.tempoSessao) tempoMeta.push(`Sessão: ${r.tempoSessao}`);
      if (r.tempoRestante) tempoMeta.push(`Restante: ${r.tempoRestante}`);
      if (r.tempoTotal) tempoMeta.push(`Total: ${r.tempoTotal}`);
      const tempoAttr = tempoMeta.length ? ` title="${N.utils.escapeHtml(tempoMeta.join(' | '))}"` : '';

      const username = String(r.username || '');
      const mac = String(r.mac || '');
      const ip = String(r.ip || '');
      const radacctid = String(r.radacctid || '');
      const kickDisabled = (!username && !mac && !ip);
      const kickAttrs = `data-username="${N.utils.escapeHtml(username)}" data-mac="${N.utils.escapeHtml(mac)}" data-ip="${N.utils.escapeHtml(ip)}" data-radacctid="${N.utils.escapeHtml(radacctid)}"`;
      const kickBtn = `<button type="button" class="theme-btn btn-sm js-kick-session" ${kickAttrs}${kickDisabled ? ' disabled' : ''}>Derrubar</button>`;

      html += `
        <tr>
          <td>${N.utils.escapeHtml(r.nome || r.username || '-')}</td>
          <td>${r.dispositivo || '💻'}</td>
          <td>${N.utils.escapeHtml(r.hostOrigem || r.servidor || '-')}</td>
          <td${tempoAttr}>${N.utils.escapeHtml(r.tempoOnline || '-')}</td>
          <td>${upKbps} kbps <small class="muted">• ${upTotal}</small></td>
          <td>${downKbps} kbps <small class="muted">• ${downTotal}</small></td>
          <td>${N.utils.escapeHtml(r.perfil || '')}</td>
          <td>${r.visitas ?? 0}</td>
          <td class="table-actions"><form>${kickBtn}</form></td>
        </tr>`;
    }

    if (list.length === 0) {
      html = `<tr><td colspan="9">Nenhum cliente online no momento.</td></tr>`;
    }

    const keyHtml = N.utils.hashString(html);
    if (keyHtml !== lastTableKey) { tbody.innerHTML = html; lastTableKey = keyHtml; }
  };
})(window.FIRESPOT);