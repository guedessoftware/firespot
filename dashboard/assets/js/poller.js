// /dashboard/assets/js/poller.js  (SAFE)
((N) => {
  if (!N) return;

  const API_URL = (N.config && N.config.API_URL) || 'api/dashboard_data.php';
  const POLL_MS = (N.config && N.config.POLL_MS) || 8000;

  let timer = null;
  let busy = false;
  let lastKey = '';

  const fmtBytes = (n) => {
    const b = Number(n) || 0, u = ['B','KB','MB','GB','TB'];
    let i = 0, x = b;
    while (x >= 1024 && i < u.length - 1) { x /= 1024; i++; }
    return (i === 0 ? x : x.toFixed(1)) + ' ' + u[i];
  };

  const setText = (id, v) => {
    const el = document.getElementById(id);
    if (el) el.textContent = v;
  };

  function fillKPIs(k) {
    if (!k) return;
    setText('kpi-online',        String(k.onlineAgora ?? '—'));
    setText('kpi-users',         String(k.usuariosCadastrados ?? '—'));
    setText('kpi-devices30',     String(k.dispositivos30d ?? '—'));
    setText('kpi-devices-total', String(k.dispositivosTotais ?? '—'));
    setText('kpi-new7',          String(k.novosUsuarios7d ?? '—'));
    const traf = k.trafegoHojeBytes ?? 0;
    setText('kpi-trafego', fmtBytes(traf));
  }

  const esc = (s)=>String(s??'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));

  const RETURNING_PAGE_SIZE = 3;
  let returningPage = 0;
  let returningCache = [];

  function updateReturningPagination(total){
    const indicator = document.getElementById('device-returning-indicator');
    const prev = document.querySelector('[data-returning-act="prev"]');
    const next = document.querySelector('[data-returning-act="next"]');
    if (!indicator || !prev || !next) return;
    if (!total){
      indicator.textContent = '—';
      prev.disabled = true;
      next.disabled = true;
      return;
    }
    const pages = Math.max(1, Math.ceil(total / RETURNING_PAGE_SIZE));
    indicator.textContent = `${returningPage + 1}/${pages}`;
    prev.disabled = returningPage === 0;
    next.disabled = returningPage >= pages - 1;
  }

  function renderReturningList(){
    const topList = document.getElementById('device-insights-top');
    if (!topList) return;
    const total = returningCache.length;
    if (!total){
      topList.innerHTML = '<li class="muted">Sem recorrentes no período</li>';
      updateReturningPagination(0);
      return;
    }
    const pages = Math.max(1, Math.ceil(total / RETURNING_PAGE_SIZE));
    if (returningPage >= pages) returningPage = pages - 1;
    if (returningPage < 0) returningPage = 0;
    const start = returningPage * RETURNING_PAGE_SIZE;
    const subset = returningCache.slice(start, start + RETURNING_PAGE_SIZE);
    const rows = subset.map(item => {
      const visits = Number(item.visits || 0);
      const badge = `<span class="insight-top-visits">${visits}x</span>`;
      const ownerRaw = item.owner || item.username || '';
      const owner = ownerRaw ? esc(ownerRaw) : '—';
      const icon = esc(item.osIcon || '📶');
      const deviceLabel = esc(item.label || 'Dispositivo');
      const ago = item.lastSeenAgo ? esc(item.lastSeenAgo) : '';
      const metaParts = [`${icon} ${deviceLabel}`];
      if (ago) metaParts.push(ago);
      const meta = metaParts.join(' • ');
      return `
        <li>
          <div class="insight-top-line">
            <span class="insight-top-owner">${owner}</span>
            ${badge}
          </div>
          <div class="insight-top-meta muted">${meta || '&nbsp;'}</div>
        </li>`;
    });
    topList.innerHTML = rows.join('');
    updateReturningPagination(total);
  }

  let lastRates = new Map(); // memoiza bytes anteriores para estimar taxa momentânea

  function fillTable(rows){
    const tbody = document.querySelector('#tbl-online tbody');
    if (!tbody) return;

    if (!Array.isArray(rows) || rows.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9">Nenhum cliente online</td></tr>`;
      lastRates = new Map();
      return;
    }

    const now = Date.now();
    const nextRates = new Map();
    const html = [];

    for (const r of rows) {
      const key = r.username || r.nome || r.mac || r.ip || Math.random().toString(36).slice(2);
      const prev = lastRates.get(key);
      let upKbps = 0;
      let downKbps = 0;

      if (prev) {
        const dt = (now - prev.ts) / 1000;
        if (dt > 0) {
          const deltaIn  = Math.max(0, (Number(r.in)  || 0) - prev.in);
          const deltaOut = Math.max(0, (Number(r.out) || 0) - prev.out);
          downKbps = Math.round((deltaIn  * 8) / 1000 / dt);
          upKbps   = Math.round((deltaOut * 8) / 1000 / dt);
        }
      }

      nextRates.set(key, { in: Number(r.in) || 0, out: Number(r.out) || 0, ts: now });

      const hostOrigem = esc(r.hostOrigem || r.servidor || '—');
      const tempoOnline = esc(r.tempoOnline || '—');
      const tempoMeta = [];
      if (r.tempoSessao) tempoMeta.push(`Sessão: ${r.tempoSessao}`);
      if (r.tempoRestante) tempoMeta.push(`Restante: ${r.tempoRestante}`);
      if (r.tempoTotal) tempoMeta.push(`Total: ${r.tempoTotal}`);
      const tempoAttr = tempoMeta.length ? ` title="${esc(tempoMeta.join(' | '))}"` : '';
      const upTotal = fmtBytes(r.out ?? 0);
      const downTotal = fmtBytes(r.in ?? 0);
      const deviceIcon = r.dispositivo || '';
      const deviceLabelRaw = r.deviceLabel || '';
      const showDeviceLabel = deviceLabelRaw && String(deviceLabelRaw).toLowerCase() !== 'outros';
      const deviceLabelEsc = showDeviceLabel ? esc(deviceLabelRaw) : '';
      const deviceAttr = deviceLabelEsc ? ` title="${deviceLabelEsc}"` : '';
      const deviceCell = deviceIcon
        ? `${deviceIcon}${deviceLabelEsc ? ` <span class="device-label">${deviceLabelEsc}</span>` : ''}`
        : (deviceLabelEsc || '—');
      const perfilLabel = esc(r.perfil || '');

      const username = String(r.username || '');
      const mac = String(r.mac || '');
      const ip = String(r.ip || '');
      const radacctid = String(r.radacctid || '');
      const kickDisabled = (!username && !mac && !ip);
      const kickAttrs = `data-username="${esc(username)}" data-mac="${esc(mac)}" data-ip="${esc(ip)}" data-radacctid="${esc(radacctid)}"`;
      const kickBtn = `<button type="button" class="theme-btn btn-sm js-kick-session" ${kickAttrs}${kickDisabled ? ' disabled' : ''}>Derrubar</button>`;

      html.push(`<tr>
        <td>${esc(r.nome || r.username || '—')}</td>
        <td${deviceAttr}>${deviceCell}</td>
        <td>${hostOrigem}</td>
        <td${tempoAttr}>${tempoOnline}</td>
        <td>${upKbps} kbps <small class="muted">• ${upTotal}</small></td>
        <td>${downKbps} kbps <small class="muted">• ${downTotal}</small></td>
        <td>${perfilLabel || '—'}</td>
        <td>${String(r.visitas ?? 0)}</td>
        <td class="table-actions"><form>${kickBtn}</form></td>
      </tr>`);
    }

    tbody.innerHTML = html.join('');
    lastRates = nextRates;
  }

  function getCsrfToken(){
    const meta = document.querySelector('meta[name="firespot-csrf"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  async function kickSession(payload){
    const csrf = getCsrfToken();
    if (!csrf) throw new Error('CSRF ausente');
    const r = await fetch('api/kick_session.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf, ...payload })
    });
    const data = await r.json().catch(() => null);
    if (!r.ok || !data || !data.ok) {
      throw new Error((data && (data.message || data.error || data.err)) || ('HTTP ' + r.status));
    }
    return data;
  }

  document.addEventListener('click', async (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('.js-kick-session') : null;
    if (!btn) return;
    ev.preventDefault();

    const username = String(btn.getAttribute('data-username') || '').trim();
    const mac = String(btn.getAttribute('data-mac') || '').trim();
    const ip = String(btn.getAttribute('data-ip') || '').trim();
    const radacctidRaw = String(btn.getAttribute('data-radacctid') || '').trim();
    const radacctid = radacctidRaw && /^[0-9]+$/.test(radacctidRaw) ? Number(radacctidRaw) : 0;

    const who = username || mac || ip || 'cliente';
    if (!confirm(`Derrubar conexão de ${who}?`)) return;

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = '...';
    try {
      const res = await kickSession({ username, mac, ip, radacctid });
      const a0 = res && res.active ? Number(res.active.before || 0) : 0;
      const c0 = res && res.cookie ? Number(res.cookie.before || 0) : 0;
      if (a0 <= 0 && c0 <= 0) {
        if (!(res && res.radius && Number(res.radius.closed || 0) > 0)) {
          alert('Nenhuma sessão encontrada no MikroTik para derrubar (e não foi possível encerrar no RADIUS).');
        }
      }
      // deixa o poller remover da lista no próximo ciclo
    } catch (e) {
      alert('Falha ao derrubar: ' + (e && e.message ? e.message : String(e)));
    } finally {
      btn.textContent = oldText;
      btn.disabled = false;
    }
  }, { capture: true });

  const hash = (s)=>{ let h=0; for(let i=0;i<s.length;i++) h=((h<<5)-h+s.charCodeAt(i))|0; return String(h>>>0); };

  async function tick(){
    if (busy) return;
    busy = true;
    try{
      const r = await fetch(API_URL, { cache: 'no-store' });
      if (!r.ok) throw new Error('HTTP '+r.status);
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Falha');

      fillKPIs(data.kpis);
      fillTable(data.online);

      const h = (data.graficos?.porHora24h || []).map(x => [String(x.hora ?? x[0]), Number(x.qtd ?? x[1])||0]);
      const d = (data.graficos?.porDia7d   || []).map(x => [String(x.dia  ?? x[0]), Number(x.qtd ?? x[1])||0]);
      const pico = data.graficos?.picoPorHora12h || { labels: [], data: [] };
      const traf = data.graficos?.trafegoPorDia7d || { labels: [], data: [] };

      const k = hash(JSON.stringify({h,d,pico,traf}));
      if (k !== lastKey) {
        lastKey = k;
        N.charts?.update(h, d, pico, traf);
      }

      renderDeviceInsights(data.deviceInsights);
      renderNasHealth(data.nasHealth);

      const stamp = document.getElementById('last-refresh');
      if (stamp) stamp.textContent = new Date().toLocaleTimeString();
    }catch(e){
      console.error('[poller]', e);
      // não derruba nada
    }finally{
      busy = false;
      timer = setTimeout(tick, POLL_MS);
    }
  }

  N.poller = {
    start(){ clearTimeout(timer); timer = setTimeout(tick, 0); },
    stop(){ clearTimeout(timer); }
  };

  function renderDeviceInsights(payload){
    const osList = document.getElementById('device-insights-os');
    const topList = document.getElementById('device-insights-top');
    const stamp = document.getElementById('device-insights-updated');
    const totalNode = document.getElementById('device-insights-total');
    const returningNode = document.getElementById('device-insights-returning');
    const visitsNode = document.getElementById('device-insights-visits');
    if (!osList || !topList) return;

    const data = payload || {};
    const mix = Array.isArray(data.osMix) ? data.osMix : [];
    let total = Number(data.totalDevices ?? 0);
    if (!Number.isFinite(total) || total <= 0) {
      total = mix.reduce((acc, item) => acc + Number(item.count || 0), 0);
    }
    if (totalNode) {
      totalNode.textContent = total > 0 ? total.toLocaleString('pt-BR') : '—';
    }

    if (mix.length === 0) {
      osList.innerHTML = '<li class="muted">Sem dados recentes</li>';
    } else {
      const rows = mix.map(item => {
        const pct = Number(item.percent || 0);
        const pctText = total > 0 ? pct.toFixed(1).replace('.0','') + '%' : '0%';
        const icon = esc(item.icon || '📶');
        const label = esc(item.label || '—');
        const count = Number(item.count || 0);
        const width = Math.max(0, Math.min(100, pct));
        return `
          <li>
            <div class="insight-os-row">
              <span>${icon} ${label}</span>
              <span class="muted">${pctText} · ${count.toLocaleString('pt-BR')}</span>
            </div>
            <div class="insight-bar"><progress max="100" value="${width}" aria-label="${pctText}"></progress></div>
          </li>`;
      });
      osList.innerHTML = rows.join('');
    }

    const returning = Array.isArray(data.topReturning) ? data.topReturning : [];
    const returningCount = returning.length;
    if (returningNode) {
      returningNode.textContent = returningCount ? returningCount.toLocaleString('pt-BR') : '—';
    }
    if (visitsNode) {
      const visitSum = returning.reduce((acc, item) => acc + Number(item.visits || 0), 0);
      visitsNode.textContent = visitSum ? `${visitSum.toLocaleString('pt-BR')} visitas` : '—';
    }

    returningCache = returning;
    returningPage = 0;
    renderReturningList();

    if (stamp) {
      stamp.textContent = data.generatedAt ? new Date(data.generatedAt).toLocaleTimeString() : '—';
    }
  }

  document.addEventListener('click', (ev)=>{
    const btn = ev.target.closest('[data-returning-act]');
    if (!btn || btn.disabled) return;
    if (!returningCache.length) return;
    ev.preventDefault();
    const act = btn.dataset.returningAct;
    const total = returningCache.length;
    const pages = Math.max(1, Math.ceil(total / RETURNING_PAGE_SIZE));
    if (act === 'prev' && returningPage > 0) {
      returningPage -= 1;
      renderReturningList();
    } else if (act === 'next' && returningPage < pages - 1) {
      returningPage += 1;
      renderReturningList();
    }
  });

  function renderNasHealth(payload){
    const summary = document.getElementById('nas-health-summary');
    const list = document.getElementById('nas-health-list');
    const stamp = document.getElementById('nas-health-updated');
    if (!summary || !list) return;

    const data = payload || {};
    const counts = data.statusCounts || {};
    const ok = Number(counts.ok || 0);
    const err = Number(counts.error || 0);
    const unk = Number(counts.unknown || 0);

    summary.innerHTML = `
      <span class="health-pill pill-ok">OK ${ok}</span>
      <span class="health-pill pill-error">Falha ${err}</span>
      <span class="health-pill pill-unknown">? ${unk}</span>
    `;

    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length === 0) {
      list.innerHTML = '<p class="muted">Nenhum NAS cadastrado ou sem dados de saúde.</p>';
    } else {
      const rows = items.map(item => {
        const cls = statusClass(item.status);
        const label = esc(item.label || item.nasname || 'NAS');
        const statusLabel = readableStatus(item.status);
        const latency = item.latencyMs ? `${Number(item.latencyMs)} ms` : '—';
        const lastCheck = item.checkedAtIso ? new Date(item.checkedAtIso).toLocaleTimeString() : '—';
        const sessions = Number(item.activeSessions || 0);
        const hotspotHosts = item.hotspotHosts === null || typeof item.hotspotHosts === 'undefined'
          ? '—'
          : Number(item.hotspotHosts || 0);
        const lastSession = esc(item.lastSessionAgo || 'sem sessões recentes');
        const extra = [];
        if (item.statusMessage) extra.push(esc(item.statusMessage));
        if (item.errorDetail) extra.push(esc(item.errorDetail));
        const extraLine = extra.length ? `<div class="nas-health-extra muted">${extra.join(' • ')}</div>` : '';
        return `
          <div class="nas-health-item ${cls}">
            <div class="nas-health-head">
              <strong>${label}</strong>
              <span class="nas-health-status">${statusLabel}</span>
            </div>
            <div class="nas-health-meta">
              <span>Latência: ${latency}</span>
              <span>Check: ${lastCheck}</span>
              <span>Hosts: ${hotspotHosts}</span>
              <span>Sessões: ${sessions}</span>
            </div>
            <div class="nas-health-meta muted">
              <span>Última sessão: ${lastSession}</span>
            </div>
            ${extraLine}
          </div>`;
      });
      list.innerHTML = rows.join('');
    }

    if (stamp) {
      stamp.textContent = data.generatedAt ? new Date(data.generatedAt).toLocaleTimeString() : '—';
    }
  }

  function statusClass(status){
    const s = String(status || 'unknown').toLowerCase();
    if (s === 'ok') return 'is-ok';
    if (s === 'error') return 'is-error';
    return 'is-unknown';
  }

  function readableStatus(status){
    const s = String(status || 'unknown').toLowerCase();
    if (s === 'ok') return 'Online';
    if (s === 'error') return 'Indisponível';
    return 'Sem dados';
  }

  document.addEventListener('visibilitychange', ()=> {
    if (document.hidden) N.poller.stop(); else N.poller.start();
  }, { passive: true });

})(window.FIRESPOT);
