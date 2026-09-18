// assets/js/charts.js — 12h (L→R) + tema-aware + simples
((N) => {
  if (!N) return;

  let chart12h = null;
  let chart7d  = null;
  let chartPico = null;
  let chartTrafego = null;
  let mo = null; // MutationObserver para tema

  N.charts = {
    init() {
      if (!window.Chart) return;

      const c12 = document.getElementById('chartPico24h'); // mantém o mesmo ID do canvas
      const c7  = document.getElementById('chartDia7');
      const cPico = document.getElementById('chartPicoPorHora');
      const cTraf = document.getElementById('chartTrafegoPorHora');
      if (!c12 || !c7) return;

      const colors = readThemeColors();

      const baseOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            titleColor: colors.text,
            bodyColor: colors.text,
            backgroundColor: colors.tooltipBg,
            borderColor: colors.grid,
            borderWidth: 1
          }
        },
        scales: {
          x: {
            ticks: { color: colors.textMuted },
            grid:  { color: colors.grid }
          },
          y: {
            beginAtZero: true,
            ticks: { color: colors.textMuted },
            grid:  { color: colors.grid }
          }
        }
      };

      // ---- Gráfico de horas (últimas 12h, esquerda→direita) ----
      chart12h = new Chart(c12.getContext('2d'), {
        type: 'line',
        data: {
          labels: labels12hRolling(),                 // 12 labels
          datasets: [{
            label: 'Sessões por hora (12h)',
            data: new Array(12).fill(0),              // 12 pontos
            tension: 0.25,
            borderWidth: 2,
            borderColor: colors.accent,
            pointRadius: 2,
            pointBackgroundColor: colors.accent
          }]
        },
        options: baseOpts
      });

      // ---- Gráfico de dias (últimos 7 dias) ----
      chart7d = new Chart(c7.getContext('2d'), {
        type: 'bar',
        data: {
          labels: labels7dRolling(),
          datasets: [{
            label: 'Conexões por dia (7d)',
            data: new Array(7).fill(0),
            borderWidth: 0,
            backgroundColor: colors.accentSoft
          }]
        },
        options: baseOpts
      });

      // ---- Gráfico de pico de conexões por hora (12h) ----
      if (cPico) {
        chartPico = new Chart(cPico.getContext('2d'), {
          type: 'bar',
          data: {
            labels: labels12hRolling(),
            datasets: [{
              label: 'Pico de conexões por hora',
              data: new Array(12).fill(0),
              borderWidth: 0,
              backgroundColor: colors.accentSoft
            }]
          },
          options: baseOpts
        });
      }

      // ---- Gráfico de tráfego por hora (hoje) ----
      if (cTraf) {
        chartTrafego = new Chart(cTraf.getContext('2d'), {
          type: 'line',
          data: {
            labels: [],
            datasets: [{
              label: 'Tráfego (MB)',
              data: [],
              tension: 0.25,
              borderWidth: 2,
              borderColor: colors.accent,
              backgroundColor: withAlpha(colors.accent, 0.1),
              fill: true,
              pointRadius: 2,
              pointBackgroundColor: colors.accent
            }]
          },
          options: baseOpts
        });
      }

      // Observa troca de tema (data-theme no <body>)
      observeTheme(() => applyThemeToCharts(chart12h, chart7d, chartPico, chartTrafego));
    },

    /**
     * Atualiza os gráficos.
     * @param {Array} hPairs - [['YYYY-MM-DD HH:mm:ss', qtd], ...] ou [{hora, qtd}, ...]
     * @param {Array} dPairs - [['YYYY-MM-DD', qtd], ...]           ou [{dia,  qtd}, ...]
     * @param {Object} picoPorHora - {labels: [], data: []}
     * @param {Object} trafegoPorDia - {labels: [], data: []}
     */
    update(hPairs, dPairs, picoPorHora, trafegoPorDia) {
      if (!chart12h || !chart7d) return;

      // -------- 12h (ordem: antigo → recente) --------
      const series12 = build12hSeries(hPairs || []);
      chart12h.data.labels           = series12.labels;
      chart12h.data.datasets[0].data = series12.values;
      chart12h.update();

      // -------- 7d (ordem: antigo → recente) --------
      const lab7  = labels7dRolling();
      const keys7 = keys7dRolling();
      const map7  = Object.create(null);
      (Array.isArray(dPairs) ? dPairs : []).forEach(p => {
        const raw = Array.isArray(p) ? p[0] : (p?.dia ?? p?.[0]);
        const qtd = Array.isArray(p) ? p[1] : (p?.qtd ?? p?.[1]);
        const d = new Date(String(raw));
        if (!isFinite(d)) return;
        const key = isoDayKey(d);
        map7[key] = (map7[key] || 0) + (Number(qtd) || 0);
      });
      chart7d.data.labels            = lab7;
      chart7d.data.datasets[0].data  = keys7.map(k => (map7[k] || 0));
      chart7d.update();

      // -------- Pico por hora (12h) --------
      if (chartPico && picoPorHora && picoPorHora.labels && picoPorHora.data) {
        chartPico.data.labels           = picoPorHora.labels;
        chartPico.data.datasets[0].data = picoPorHora.data;
        chartPico.update();
      }

      // -------- Tráfego por hora (hoje) --------
      if (chartTrafego && trafegoPorDia && trafegoPorDia.labels && trafegoPorDia.data) {
        chartTrafego.data.labels           = trafegoPorDia.labels;
        chartTrafego.data.datasets[0].data = trafegoPorDia.data;
        chartTrafego.update();
      }
    },

    destroy() {
      mo?.disconnect(); mo = null;
      chart12h?.destroy(); chart12h = null;
      chart7d ?.destroy(); chart7d  = null;
      chartPico?.destroy(); chartPico = null;
      chartTrafego?.destroy(); chartTrafego = null;
    }
  };

  // ================= Tema =================
  function readThemeColors() {
    const cs = getComputedStyle(document.documentElement);
    const text       = (cs.getPropertyValue('--texto') || cs.getPropertyValue('--text') || '#333').trim();
    const textMuted  = withAlpha(text, 0.75);
    const grid       = withAlpha(text, 0.15);
    const tooltipBg  = withAlpha('#000', 0.75);
    const accent     = (cs.getPropertyValue('--menu-accent') || cs.getPropertyValue('--accent') || '#4f8cff').trim();
    const accentSoft = withAlpha(accent, 0.65);
    return { text, textMuted, grid, tooltipBg, accent, accentSoft };
  }
  function applyThemeToCharts(...charts) {
    const c = readThemeColors();
    charts.forEach(ch => {
      if (!ch) return;
      const o = ch.options;
      // tooltip
      o.plugins.tooltip.titleColor = c.text;
      o.plugins.tooltip.bodyColor  = c.text;
      o.plugins.tooltip.backgroundColor = c.tooltipBg;
      o.plugins.tooltip.borderColor = c.grid;
      // scales
      o.scales.x.ticks.color = c.textMuted;
      o.scales.y.ticks.color = c.textMuted;
      o.scales.x.grid.color  = c.grid;
      o.scales.y.grid.color  = c.grid;
      // dataset
      if (ch.config.type === 'line') {
        const ds = ch.data.datasets[0];
        ds.borderColor = c.accent;
        ds.pointBackgroundColor = c.accent;
      } else {
        const ds = ch.data.datasets[0];
        ds.backgroundColor = c.accentSoft;
      }
      ch.update('none');
    });
  }
  function observeTheme(onChange) {
    const target = document.body || document.documentElement;
    mo = new MutationObserver((muts) => {
      for (const m of muts) {
        if (m.type === 'attributes' && m.attributeName === 'data-theme') {
          onChange();
          break;
        }
      }
    });
    mo.observe(target, { attributes: true });
  }

  // ================= Helpers (datas/labels) =================
  // Gera labels das últimas 12h (antigo → recente) no fuso local
  function labels12hRolling() {
    const out = [], now = new Date();
    for (let i = 11; i >= 0; i--) {
      const t = new Date(now.getTime() - i * 3600 * 1000);
      out.push(String(t.getHours()).padStart(2, '0') + 'h');
    }
    return out;
  }

  // Normaliza a série de horas para 12 pontos usando chave 'YYYY-MM-DD HH'
  function build12hSeries(pairs) {
    const now = new Date();
    const keys = []; // 12 chaves 'YYYY-MM-DD HH' em ordem crescente
    for (let i = 11; i >= 0; i--) {
      const t = new Date(now.getTime() - i * 3600 * 1000);
      keys.push(hourKey(t));
    }
    const bucket = Object.create(null);
    (Array.isArray(pairs) ? pairs : []).forEach(p => {
      const raw = Array.isArray(p) ? p[0] : (p?.hora ?? p?.[0]);
      const qtd = Array.isArray(p) ? p[1] : (p?.qtd  ?? p?.[1]);
      const d = new Date(String(raw));
      if (!isFinite(d)) return;
      const key = hourKey(d);
      bucket[key] = (bucket[key] || 0) + (Number(qtd) || 0);
    });
    const labels = keys.map(k => k.slice(11, 13) + 'h');
    const values = keys.map(k => bucket[k] || 0);
    return { labels, values };
  }

  // 'YYYY-MM-DD HH' (local)
  function hourKey(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hh  = String(d.getHours()).padStart(2, '0');
    return `${y}-${m}-${day} ${hh}`;
  }

  // 7 dias
  function labels7dRolling() {
    const out = [], now = new Date();
    for (let i = 6; i >= 0; i--) {
      const t = new Date(now.getTime() - i * 24 * 3600 * 1000);
      out.push(formatDDMM(t));
    }
    return out;
  }
  function keys7dRolling() {
    const out = [], now = new Date();
    for (let i = 6; i >= 0; i--) {
      const t = new Date(now.getTime() - i * 24 * 3600 * 1000);
      out.push(isoDayKey(t));
    }
    return out;
  }
  function isoDayKey(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }
  function formatDDMM(d) {
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}/${mm}`;
  }

  // cores com alpha
  function withAlpha(color, alpha) {
    const c = (color || '').trim();
    if (!c) return `rgba(0,0,0,${alpha})`;
    if (c.startsWith('rgba')) {
      return c.replace(/rgba\(([^)]+)\)/, (_, inner) => {
        const parts = inner.split(',').map(s => s.trim());
        return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
      });
    }
    if (c.startsWith('rgb')) {
      const parts = c.match(/\d+/g);
      if (parts && parts.length >= 3) return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
    }
    if (c.startsWith('#')) {
      const { r, g, b } = hexToRgb(c);
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }
    return c;
  }
  function hexToRgb(hex) {
    let h = hex.replace('#', '').trim();
    if (h.length === 3) h = h.split('').map(x => x + x).join('');
    const num = parseInt(h, 16);
    return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
  }
})(window.FIRESPOT);
