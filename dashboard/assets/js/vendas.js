(function(){
  if (document.body.dataset.page !== 'vendas') return;

  const fmtBRL = (v)=> new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((v||0));
  const qs = (sel)=>document.querySelector(sel);

  const from = qs('#from');
  const to   = qs('#to');
  const status = qs('#status');
  const host = qs('#host');
  const btnApply = qs('#btn-apply');
  const btnExport = qs('#btn-export');
  const filterForm = qs('#sales-filters');
  const kpiPixCount = qs('#kpi-pix-count');
  const kpiPixAmount = qs('#kpi-pix-amount');
  const kpiCardCount = qs('#kpi-card-count');
  const kpiCardAmount = qs('#kpi-card-amount');
  const kpiRefundCount = qs('#kpi-refund-count');
  const kpiRefundAmount = qs('#kpi-refund-amount');
  const REFUND_LABELS = {
    processed: 'processado',
    partial: 'parcial',
    failed: 'falhou',
    requested: 'solicitado',
    none: '—'
  };

  // defaults: últimos 7 dias
  const today = new Date();
  const fromD = new Date(today.getTime()-6*24*60*60*1000);
  from.value = from.value || fromD.toISOString().slice(0,10);
  to.value   = to.value   || today.toISOString().slice(0,10);

  let chart;
  let currentPage = 1;
  const PER_PAGE = 20; // Aumentado de 5 para 20
  function getCssVar(name){
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  const escapeHtml = (value)=> String(value ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');

  const formatCents = (centavos)=> fmtBRL((centavos||0)/100);
  const formatRefundStatus = (status)=>{
    const key = String(status || '').toLowerCase();
    return REFUND_LABELS[key] || key || '—';
  };

  function buildMethodParts(method, detail, installmentsRaw){
    const pm = String(method || '').toLowerCase();
    const normalized = pm.replace(/[^a-z0-9_-]/g, '') || 'unknown';
    const rawBase = pm === 'pix' ? 'PIX' : pm === 'card' ? 'Cartão' : (pm ? pm.toUpperCase() : '—');
    const baseLabel = escapeHtml(rawBase);
    const installments = parseInt(installmentsRaw, 10) || 0;
    const extrasHtml = [];
    const extrasPlain = [];
    if (detail && detail.toLowerCase() !== pm) {
      const detailUpper = detail.toUpperCase();
      extrasPlain.push(detailUpper);
      extrasHtml.push(escapeHtml(detailUpper));
    }
    if (installments > 1) {
      const installmentLabel = `${installments}x`;
      extrasPlain.push(installmentLabel);
      extrasHtml.push(escapeHtml(installmentLabel));
    }
    const badge = `<span class="method-badge method-${normalized}">${baseLabel}</span>`;
    const extraHtml = extrasHtml.length ? `<span class="method-extra">${extrasHtml.join(' · ')}</span>` : '';
    const plainLabel = extrasPlain.length ? `${rawBase} · ${extrasPlain.join(' · ')}` : rawBase;
    return { badge, extraHtml, textLabel: plainLabel };
  }

  function renderChart(rows){
    const ctx = document.getElementById('chartSales');
    if (!ctx) return;
    if (chart) chart.destroy();
    const text   = getCssVar('--text')   || '#222';
    const border = getCssVar('--border') || '#ddd';
    const accent = getCssVar('--accent') || '#10b981';
    const highlight = getCssVar('--orange') || '#f97316';

    const labels = rows.map(r=>r.date);
    const hasMethodSplit = rows.some(r=>Object.prototype.hasOwnProperty.call(r, 'pix_amount'));

    let datasets;
    if (hasMethodSplit) {
      datasets = [
        {
          label:'PIX',
          data: rows.map(r=>r.pix_amount || 0),
          borderColor: accent,
          backgroundColor: accent + '33',
          borderWidth:1,
        },
        {
          label:'Cartão',
          data: rows.map(r=>r.card_amount || 0),
          borderColor: highlight,
          backgroundColor: highlight + '33',
          borderWidth:1,
        }
      ];
    } else {
      datasets = [
        {
          label:'Receita (R$)',
          data: rows.map(r=>r.paid_amount || 0),
          borderColor: accent,
          backgroundColor: accent + '33',
          borderWidth:1,
        }
      ];
    }

    chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets
      },
      options: {
        responsive:true,
        maintainAspectRatio:false, // preenche a altura definida pela div .chart-wrap
        interaction:{mode:'index', intersect:false},
        plugins:{
          legend:{ labels:{ color:text } },
          tooltip:{
            callbacks:{
              label: (ctx)=>{
                const v = (ctx.parsed.y||0)/100; // centavos → reais
                return ctx.dataset.label+': '+ new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v);
              }
            }
          }
        },
        scales:{
          x:{ grid:{ color:border, drawOnChartArea:false }, ticks:{ color:text } },
          y:{ beginAtZero:true, grid:{ color:border }, ticks:{ color:text, callback:(v)=> new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((v||0)/100) } }
        }
      }
    });
  }

  async function loadAll(){
    // estado inicial: carregando a tabela
    const tbody = document.querySelector('#tbl-sales tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="15" class="muted">Carregando...</td></tr>';

    const params = new URLSearchParams({
      from: from.value, to: to.value, status: status.value, host: host.value,
      page: String(currentPage), per_page: String(PER_PAGE)
    });
    const [r1, r2, r3] = await Promise.all([
      fetch('api/vendas_resumo.php?'+params, {credentials:'same-origin'}),
      fetch('api/vendas_series.php?'+params, {credentials:'same-origin'}),
      fetch('api/vendas_list.php?'+params, {credentials:'same-origin'})
    ]);
    const [j1, j2, j3] = await Promise.all([r1.json().catch(()=>null), r2.json().catch(()=>null), r3.json().catch(()=>null)]);

    // KPIs
    qs('#kpi-paid-count').textContent = j1?.totals?.paid_count ?? '0';
    qs('#kpi-paid-amount').textContent = j1?.totals?.paid_amount_br ?? 'R$ 0,00';
    qs('#kpi-aov').textContent = j1?.totals?.aov_br ?? 'R$ 0,00';
    qs('#kpi-pending').textContent = j1?.totals?.pending_count ?? '0';
    const methodTotals = j1?.methods || {};
    const pixTotals = methodTotals.pix || null;
    const cardTotals = methodTotals.card || null;
    if (kpiPixCount) kpiPixCount.textContent = pixTotals?.count ?? '0';
    if (kpiPixAmount) kpiPixAmount.textContent = pixTotals?.amount_br ?? 'R$ 0,00';
    if (kpiCardCount) kpiCardCount.textContent = cardTotals?.count ?? '0';
    if (kpiCardAmount) kpiCardAmount.textContent = cardTotals?.amount_br ?? 'R$ 0,00';
    if (kpiRefundCount) kpiRefundCount.textContent = j1?.refunds?.count ?? '0';
    if (kpiRefundAmount) kpiRefundAmount.textContent = j1?.refunds?.amount_br ?? 'R$ 0,00';

    // Chart
    const seriesRows = j2?.rows || [];
    renderChart(seriesRows);

    // Table
    // Renderização da tabela: lida com erro/sem dados
    if (tbody) tbody.innerHTML = '';
    if (!r3.ok) {
      tbody.innerHTML = `<tr><td colspan="15" class="muted">Falha ao carregar pedidos (HTTP ${r3.status}).</td></tr>`;
      return;
    }
    if (!j3 || j3.ok===false) {
      const msg = (j3 && (j3.error || j3.err)) || 'Nenhum dado retornado.';
      tbody.innerHTML = `<tr><td colspan="15" class="muted">${escapeHtml(msg)}</td></tr>`;
      return;
    }
    const rows = j3.rows || [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="15" class="muted">Nenhum pedido encontrado para os filtros selecionados. Ajuste "De/Até" ou "Status".</td></tr>';
      renderPager(0, 0, 0);
      return;
    }
    rows.forEach(r=>{
      const tr = document.createElement('tr');
      const money = formatCents(r.amount_centavos || 0);
      const methodParts = buildMethodParts(r.payment_method, r.payment_method_detail, r.payment_installments);
      const methodHtml = methodParts.badge + (methodParts.extraHtml ? `<br>${methodParts.extraHtml}` : '');
      let refundInfo = '—';
      const refundStatus = r.refund_status || '';
      const refundAmount = r.refund_amount_centavos ? formatCents(r.refund_amount_centavos) : '';
      if (r.status === 'refunded' || (refundStatus && refundStatus !== 'none')) {
        const statusLabel = formatRefundStatus(refundStatus || 'processed');
        const parts = [escapeHtml(statusLabel)];
        if (refundAmount) parts.push(escapeHtml(refundAmount));
        if (r.refunded_at) parts.push(`<small>${escapeHtml(r.refunded_at)}</small>`);
        refundInfo = parts.join(' · ');
        if (r.refund_notes) {
          refundInfo += `<br><small>${escapeHtml(r.refund_notes)}</small>`;
        }
      }
      
      // Badge de status com cores (clicável para filtrar)
      const normalizedStatus = String(r.status || '').toLowerCase();
      let statusBadge = escapeHtml(normalizedStatus || '—');
      if (normalizedStatus === 'paid') statusBadge = '<span class="sales-status sales-status--paid" data-status="paid" title="Clique para filtrar">✓ pago</span>';
      else if (normalizedStatus === 'pending') statusBadge = '<span class="sales-status sales-status--pending" data-status="pending" title="Clique para filtrar">⏳ pendente</span>';
      else if (normalizedStatus === 'cancelled') statusBadge = '<span class="sales-status sales-status--cancelled" data-status="cancelled" title="Clique para filtrar">✕ cancelado</span>';
      else if (normalizedStatus === 'refunded') statusBadge = '<span class="sales-status sales-status--refunded" data-status="refunded" title="Clique para filtrar">↩︎ reembolsado</span>';
      
      // Botões de ação
      const orderId = Number.parseInt(r.id, 10) || 0;
      let actions = `<button class="theme-btn" data-action="view" data-id="${orderId}" title="Ver detalhes">👁️</button>`;
      if (normalizedStatus === 'pending') {
        actions += ` <button class="theme-btn" data-action="cancel" data-id="${orderId}" title="Cancelar">❌</button>`;
      }
      if (normalizedStatus === 'paid' && r.telefone) {
        actions += ` <button class="theme-btn" data-action="resend" data-id="${orderId}" title="Reenviar WhatsApp">📱</button>`;
      }
      if (r.mp_payment_id && (normalizedStatus === 'paid' || (normalizedStatus === 'refunded' && (refundStatus === 'partial' || refundStatus === 'requested')))) {
        actions += ` <button class="theme-btn" data-action="refund" data-id="${orderId}" title="Processar reembolso">↩️</button>`;
      }
      
      tr.innerHTML = `
        <td>${orderId || ''}</td>
        <td>${escapeHtml(r.external_ref || '')}</td>
        <td>${statusBadge}</td>
        <td>${escapeHtml((r.nome || '') + (r.cpf ? ' · ' + r.cpf : ''))}</td>
        <td><small>${escapeHtml(r.email || '—')}</small></td>
        <td><small>${escapeHtml(r.telefone || '—')}</small></td>
        <td>${escapeHtml(money)}</td>
        <td>${methodHtml}</td>
        <td>${escapeHtml(r.duracao_min || '')}</td>
        <td><small>${escapeHtml(r.host_code || '—')}</small></td>
        <td><small>${escapeHtml(r.created_at || '')}</small></td>
        <td><small>${escapeHtml(r.paid_at || '—')}</small></td>
        <td><small>${escapeHtml(r.vip_applied_at || '—')}</small></td>
        <td><small>${refundInfo}</small></td>
        <td>${actions}</td>
      `;
      tbody.appendChild(tr);
    });
    renderPager(j3.total||0, j3.page||1, j3.per_page||PER_PAGE);
  }

  function renderPager(total, page, per){
    let pager = document.getElementById('sales-pager');
    if (!pager){
      pager = document.createElement('div');
      pager.id = 'sales-pager';
      pager.className = 'pager';
      const tableWrap = document.querySelector('#tbl-sales')?.parentElement;
      tableWrap?.appendChild(pager);
    }
    if (!total){ pager.innerHTML = ''; return; }
    const pages = Math.max(1, Math.ceil(total/per));
    const canPrev = page>1; const canNext = page<pages;
    pager.innerHTML = `
      <div class="pager__inner">
        <button class="theme-btn" data-act="first" ${canPrev?'':'disabled'}>&laquo;</button>
        <button class="theme-btn" data-act="prev" ${canPrev?'':'disabled'}>&lsaquo;</button>
        <span class="pager__info">Página ${page} de ${pages} · ${total} registros</span>
        <button class="theme-btn" data-act="next" ${canNext?'':'disabled'}>&rsaquo;</button>
        <button class="theme-btn" data-act="last" ${canNext?'':'disabled'}>&raquo;</button>
      </div>`;
  }

  document.addEventListener('click', (ev)=>{
    // Filtro por status (clique no badge)
    const statusBadge = ev.target.closest('.status-badge');
    if (statusBadge) {
      const statusValue = statusBadge.dataset.status;
      if (status) {
        status.value = statusValue;
        currentPage = 1;
        loadAll();
      }
      return;
    }

    // Paginação
    const b = ev.target.closest('#sales-pager button[data-act]');
    if (b) {
      const act = b.dataset.act;
      const info = b.parentElement?.querySelector('.pager__info')?.textContent || '';
      const m = info.match(/Página\s+(\d+)\s+de\s+(\d+)/);
      let page = currentPage, pages = 1;
      if (m){ page = parseInt(m[1],10); pages = parseInt(m[2],10); }
      if (act==='first') currentPage = 1;
      else if (act==='prev') currentPage = Math.max(1, page-1);
      else if (act==='next') currentPage = Math.min(pages, page+1);
      else if (act==='last') currentPage = pages;
      loadAll();
      return;
    }

    // Ações em pedidos
    const actionBtn = ev.target.closest('button[data-action]');
    if (actionBtn) {
      const action = actionBtn.dataset.action;
      const orderId = parseInt(actionBtn.dataset.id, 10);
      if (!orderId) return;
      
      switch (action) {
        case 'cancel':
          if (confirm('Tem certeza que deseja cancelar este pedido?')) {
            executeAction(orderId, 'cancel');
          }
          break;
        case 'resend':
          if (confirm('Reenviar mensagem de WhatsApp para este cliente?')) {
            executeAction(orderId, 'resend_whatsapp');
          }
          break;
        case 'view':
          executeAction(orderId, 'view_details');
          break;
        case 'refund':
          if (confirm('Confirmar reembolso para este pedido?')) {
            const amountStr = prompt('Valor do reembolso em reais (deixe vazio para reembolsar o valor restante).', '');
            const payload = {};
            if (amountStr && amountStr.trim() !== '') {
              const sanitized = amountStr.replace(',', '.');
              const parsed = parseFloat(sanitized);
              if (Number.isFinite(parsed) && parsed > 0) {
                payload.amount_centavos = Math.round(parsed * 100);
              } else {
                alert('Valor inválido informado. Operação cancelada.');
                return;
              }
            }
            executeAction(orderId, 'refund', payload);
          }
          break;
      }
    }
  });

  async function executeAction(orderId, action, extraPayload = {}) {
    try {
      const csrf = document.querySelector('meta[name="firespot-csrf"]')?.content || '';
      const res = await fetch('api/vendas_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ order_id: orderId, action, csrf, ...extraPayload })
      });
      
      const data = await res.json();
      
      if (!res.ok || !data.ok) {
        alert('Erro: ' + (data.error || 'Falha na operação'));
        return;
      }
      
      if (action === 'view_details') {
        // Mostra modal com detalhes
        showOrderDetails(data.order);
      } else if (action === 'refund') {
        const info = data.refund;
        const message = data.message || 'Reembolso concluído';
        const amountText = info?.amount_br ? ` Valor: ${info.amount_br}` : '';
        alert(message + amountText);
        loadAll();
      } else {
        alert(data.message || 'Operação concluída');
        // Recarrega lista
        loadAll();
      }
    } catch (err) {
      console.error('Erro na ação:', err);
      alert('Erro ao executar ação: ' + err.message);
    }
  }

  function showOrderDetails(order) {
    const methodParts = buildMethodParts(order.payment_method, order.payment_method_detail, order.payment_installments);
    const methodLabel = methodParts.textLabel || 'N/A';

    let refundLabel = 'N/A';
    if (order.status === 'refunded' || (order.refund_status && order.refund_status !== 'none')) {
      const statusLabel = formatRefundStatus(order.refund_status || 'processed');
      const value = order.refund_amount_centavos ? formatCents(order.refund_amount_centavos) : '—';
      refundLabel = `${statusLabel} · ${value}`;
      if (order.refunded_at) {
        refundLabel += ` · ${order.refunded_at}`;
      }
      if (order.refund_notes) {
        refundLabel += ` · ${order.refund_notes}`;
      }
    }

    const details = `
ID: ${order.id}
Referência: ${order.external_ref}
Status: ${order.status}
Cliente: ${order.nome}
CPF: ${order.cpf}
Email: ${order.email}
Telefone: ${order.telefone}
Valor: ${fmtBRL((order.valor_centavos||0)/100)}
Duração: ${order.duracao_min} minutos
Método: ${methodLabel}
Host: ${order.host_code || 'N/A'}
Device MAC: ${order.device_mac || 'N/A'}
Device IP: ${order.device_ip || 'N/A'}
MP Payment ID: ${order.mp_payment_id || 'N/A'}
MP Refund ID: ${order.mp_refund_id || 'N/A'}
Criado em: ${order.created_at}
Pago em: ${order.paid_at || 'N/A'}
VIP aplicado em: ${order.vip_applied_at || 'N/A'}
Reembolso: ${refundLabel}
    `.trim();
    
    alert(details);
  }

  btnApply?.addEventListener('click', ()=>{
    currentPage = 1;
    loadAll();
  });
  filterForm?.addEventListener('submit',(event)=>{
    event.preventDefault();
    btnApply?.click();
  });

  btnExport?.addEventListener('click', (ev)=>{
    ev.preventDefault();
    const params = new URLSearchParams({ from: from.value, to: to.value, status: status.value, host: host.value });
    window.open('api/vendas_export.php?'+params,'_blank');
  });

  loadAll();
})();
