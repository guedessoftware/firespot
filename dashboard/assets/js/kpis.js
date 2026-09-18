// assets/js/kpis.js
((N)=>{
  if (!N) return;

  // conversor robusto para número (aceita "1,234,567", " 123 ", etc.)
  const toNum = (v) => {
    if (v == null) return null;
    if (typeof v === 'string') {
      const s = v.trim().replace(/,/g, '');
      const n = Number(s);
      return Number.isFinite(n) ? n : null;
    }
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  };

  // tenta diferentes nomes de campo vindos do backend
  function pickTraffic(k) {
    if (!k) k = {};
    let total =
      toNum(k.trafegoHojeBytes) ??
      toNum(k.trafegoHoje) ??
      toNum(k.trafego_hoje_bytes) ??
      null;

    let down =
      toNum(k.trafegoHojeDown) ??
      toNum(k.trafego_hoje_down) ??
      null;

    let up =
      toNum(k.trafegoHojeUp) ??
      toNum(k.trafego_hoje_up) ??
      null;

    let source = 'kpis';
    if (total == null && (down != null || up != null)) {
      total = (down || 0) + (up || 0);
      source = 'kpis (down/up)';
    }

    return { total, down, up, source };
  }

  // início do dia no fuso America/Manaus (timestamp ms)
  function startOfTodayManaus() {
    const nowManaus = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Manaus' }));
    nowManaus.setHours(0, 0, 0, 0);
    return nowManaus.getTime();
  }

  N.updateKPIs = function(k, onlineRows) {
    const kpis = k || {};

    // KPIs básicos
    setText('#kpi-online',         kpis.onlineAgora);
    setText('#kpi-users',          kpis.usuariosCadastrados);
    setText('#kpi-devices30',      kpis.dispositivos30d);
    setText('#kpi-devices-total',  kpis.dispositivosTotais);
    setText('#kpi-new7',           kpis.novosUsuarios7d);

    // ---- Tráfego hoje
    const el = document.getElementById('kpi-trafego');
    if (!el) { console.warn('[KPIs] #kpi-trafego não encontrado no DOM'); return; }

    let { total, down, up, source } = pickTraffic(kpis);

    // Fallback com corte por "hoje" (America/Manaus) se valor do backend for ausente/zero
    const cutoff = startOfTodayManaus();
    const rowsSrc = Array.isArray(onlineRows) ? onlineRows : [];
    const rowsForToday = rowsSrc.filter(r => {
      // tente estes campos de timestamp (ajuste se usar outro nome no backend)
      const ts = r?.acctstarttime || r?.started_at || r?.start || r?.login_at;
      if (!ts) return true; // sem timestamp -> mantém (comportamento anterior)
      const t = new Date(ts).getTime();
      return Number.isFinite(t) ? t >= cutoff : true;
    });

    let sumIn  = rowsForToday.reduce((acc, r) => acc + (toNum(r?.in)  || 0), 0);
    let sumOut = rowsForToday.reduce((acc, r) => acc + (toNum(r?.out) || 0), 0);
    const fallbackTotal = sumIn + sumOut;

    // usa fallback se total inválido OU total <= 0 e fallback > 0
    if ((total == null || total <= 0) && fallbackTotal > 0) {
      total = fallbackTotal;
      if (down == null) down = sumIn;
      if (up   == null) up   = sumOut;
      source = (source === 'kpis' || (source||'').startsWith('kpis'))
        ? `${source} (0→fallback:online)`
        : 'fallback:online';
    }

    // pinta o card
    if (total == null) {
      el.textContent = '0 B';
      el.title = 'Sem dados de tráfego hoje';
      el.dataset.source = 'none';
    } else {
      el.textContent = N.utils.formatBytes(total);
      const parts = [];
      parts.push(`Total: ${N.utils.formatBytes(total)}`);
      if (down != null) parts.push(`Down: ${N.utils.formatBytes(down)}`);
      if (up   != null) parts.push(`Up: ${N.utils.formatBytes(up)}`);
      parts.push(`Fonte: ${source}`);
      el.title = parts.join(' | ');
      el.dataset.source = source;
    }
  };

  function setText(sel, val) {
    const el = document.querySelector(sel);
    if (el) el.textContent = (val ?? '—');
  }
})(window.FIRESPOT);

//$trafegoHojeBytes = trafegoHojeBytes($pdo);
