
  (function () {
    const btnExport = document.getElementById('btn-export');
    if (!btnExport) return;

    function val(id) {
      const el = document.getElementById(id);
      return (el && typeof el.value === 'string') ? el.value.trim() : '';
    }

    btnExport.addEventListener('click', function () {
      const qs = new URLSearchParams({
        q:      val('f-q') || '',
        group:  val('f-group') || '',
        online: val('f-status') || '',
        order:  val('f-order') || 'last_seen',
        dir:    val('f-dir')   || 'desc'
      });

      const url = 'api/users_export.php?' + qs.toString();
      // abre em nova aba; se preferir download direto use window.location = url;
      window.open(url, '_blank');
    });
  })();

(function () {
  // ===== Endpoints =====
  const API_LIST = 'api/users_list.php';
  const API_GET  = 'api/user_get.php';
  const API_SAVE = 'api/user_save.php';
  const API_DEL  = 'api/user_delete.php';
  const API_HISTORY = 'api/user_history.php';
  const API_STATS_REFRESH = 'api/users_stats_refresh.php';
  var meta = document.querySelector('meta[name="firespot-csrf"]');
  const CSRF = meta ? meta.getAttribute('content') : '';

  // ===== Helpers =====
  function $(s, p){ return (p||document).querySelector(s); }
  function $all(s, p){ return Array.prototype.slice.call((p||document).querySelectorAll(s)); }
  function fmtHM(sec){
    sec = +sec || 0; var h = Math.floor(sec/3600), m = Math.floor((sec%3600)/60);
    return h>0 ? (h+'h '+String(m).padStart(2,'0')+'m') : (m+'m');
  }
  function fmtDurationSec(sec){
    sec = Number(sec);
    if (!Number.isFinite(sec) || sec < 0) return '—';
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = Math.floor(sec % 60);
    var out = [];
    if (h) out.push(h + 'h');
    if (m) out.push(m + 'm');
    if (!out.length) out.push(s + 's');
    return out.join(' ');
  }
  function fmtBytes(bytes){
    bytes = Number(bytes);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    var units = ['B','KB','MB','GB','TB'];
    var idx = 0;
    var value = bytes;
    while (value >= 1024 && idx < units.length - 1) {
      value /= 1024;
      idx++;
    }
    var digits = idx === 0 ? 0 : 2;
    return value.toFixed(digits) + ' ' + units[idx];
  }
  function escHtml(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  function debounce(fn, ms){
    var t; return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(null,a); }, ms); };
  }

  // ===== State =====
  var page=1, pages=1, perPage=25, statsRefreshRunning=false;
  var params = { q:'', group:'', online:'', order:'last_seen', dir:'desc' };

  // ===== Elements =====
  var gridBody   = $('#grid-body');
  var statsInline= $('#stats-inline');
  var pgInfo     = $('#pg-info');
  var pgPrev     = $('#pg-prev');
  var pgNext     = $('#pg-next');
  var pgSummary  = $('#pg-summary');

  // Filters
  var fQ     = $('#f-q');
  var fGroup = $('#f-group');
  var fStatus= $('#f-status');
  var fOrder = $('#f-order');
  var fDir   = $('#f-dir');
  var fPer   = $('#f-per');
  var btnReload = $('#btn-reload');
  var btnAdd    = $('#btn-add');

  // Modal
  var modal  = $('#modal-user');
  var muTitle= $('#mu-title');
  var muForm = $('#mu-form');
  var muMode = $('#mu-mode');
  var fUser  = $('#mu-username');
  var fNome  = $('#mu-nome');
  var fTel   = $('#mu-telefone');
  var fEmail = $('#mu-email');
  var fNasc  = $('#mu-nascimento');
  var fSexo  = $('#mu-sexo');
  var fSenha = $('#mu-senha');
  var fGroupE= $('#mu-group');
  var fMax   = $('#mu-maxmin');
  var fExcl  = $('#mu-exclusive');
  var btnClose = $('#mu-close');
  var btnCancel= $('#mu-cancel');
  var historyModal = $('#modal-history');
  var historyClose = $('#mh-close');
  var historyTitle = $('#mh-title');
  var historySummary = $('#mh-summary');
  var historyOrders = $('#mh-orders');
  var historySessions = $('#mh-sessions');

  // ===== Load =====
  async function load(){
    var qs = new URLSearchParams({
      q: params.q, group: params.group, online: params.online,
      order: params.order, dir: params.dir, page: page, per_page: perPage
    });
    gridBody.innerHTML = '<tr><td colspan="8" class="muted">Carregando…</td></tr>';
    const r = await fetch(API_LIST + '?' + qs.toString(), { cache:'no-store', credentials:'same-origin' });
    const j = await r.json();

    if (!j.ok) throw new Error(j.error || 'Falha ao carregar');

    var onlineCount = j.rows.filter(function(x){ return !!x.online; }).length;
    if (statsInline) statsInline.textContent = onlineCount + ' online nesta página • ' + j.total + ' no total';
    if (pgSummary) pgSummary.textContent = j.rows.length + ' itens em ' + j.per_page + ' por página';

    // datalist grupos
    var dset = {};
    j.rows.forEach(function(r){
      (r.groups || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean)
        .forEach(function(g){ dset[g]=true; });
    });
    var dl = $('#dl-groups');
    if (dl){
      dl.replaceChildren();
      Object.keys(dset).sort().forEach(function(group){
        var option = document.createElement('option');
        option.value = group;
        dl.appendChild(option);
      });
    }

    // tabela
    if (!j.rows.length){
      gridBody.innerHTML = '<tr><td colspan="8" class="muted">Nenhum registro encontrado.</td></tr>';
    } else {
      gridBody.innerHTML = j.rows.map(function(row){
        var dot = row.online ? '🟢' : '⚪';
        var allowed = row.allowed || 0, used = row.used || 0;
        var remaining = Math.max(0, allowed - used);
        var pct = allowed > 0 ? Math.min(100, Math.round(remaining * 100 / allowed)) : 0;
        var safeUsername = escHtml(row.username || '');
        function cell(label, html){ return '<td data-label="'+escHtml(label)+'">'+html+'</td>'; }
        return [
          '<tr>',
            cell('Status', '<span class="status-dot" title="'+(row.online?'Online':'Offline')+'">'+dot+'</span>'),
            cell('Usuário', safeUsername),
            cell('Nome', escHtml((row.nome && row.nome!=='—') ? row.nome : '—')),
            cell('Grupo(s)', escHtml(row.groups || '—')),
            cell('VIP restante',
              '<div class="users-vip-stack">' +
                '<div class="vip-bar" title="Restante: '+fmtHM(remaining)+' • Total: '+fmtHM(allowed)+'">' +
                  '<span data-progress="'+pct+'"></span>' +
                '</div>' +
                '<small class="muted">'+fmtHM(remaining)+(allowed?(' • total '+fmtHM(allowed)):'')+'</small>' +
              '</div>'
            ),
            cell('Visitas (30d)', String(Number.parseInt(row.visits30, 10) || 0)),
            cell('Último visto', escHtml(row.last_seen || '—')),
            cell('Ações',
              '<div class="users-row-actions">' +
                '<button class="theme-btn" data-action="history" data-username="'+safeUsername+'" type="button" title="Histórico">📜</button>' +
                '<button class="theme-btn" data-action="edit" data-username="'+safeUsername+'" type="button" title="Editar">✏️</button>' +
                '<button class="theme-btn" data-action="del" data-username="'+safeUsername+'" type="button" title="Excluir">🗑️</button>' +
              '</div>'
            ),
          '</tr>'
        ].join('');
      }).join('');
      gridBody.querySelectorAll('[data-progress]').forEach(function(bar){
        var percent = Math.max(0,Math.min(100,parseInt(bar.dataset.progress,10)||0));
        bar.style.width = percent + '%';
      });
    }

    // paginação
    page = j.page; pages = j.pages; perPage = j.per_page;
    if (pgInfo){
      pgInfo.dataset.page = page;
      pgInfo.dataset.pages = pages;
      pgInfo.textContent = 'Página ' + page + ' de ' + pages;
    }
    if (pgPrev) pgPrev.disabled = page <= 1;
    if (pgNext) pgNext.disabled = page >= pages;
    refreshStatsIfStale(j.stats || null);
  }

  function refreshStatsIfStale(stats){
    if (!stats || !stats.stale || statsRefreshRunning) return;
    statsRefreshRunning = true;
    fetch(API_STATS_REFRESH, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
      credentials: 'same-origin',
      body: 'csrf=' + encodeURIComponent(CSRF)
    })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (data && data.ok && data.refreshed) return load();
      })
      .catch(function(){})
      .finally(function(){ statsRefreshRunning = false; });
  }

  // ===== Filtros =====
  var applyFilters = debounce(function(){ page=1; load().catch(function(e){ alert(e.message); }); }, 300);
  if (fQ)     fQ.addEventListener('input', function(e){ params.q = e.target.value.trim(); applyFilters(); });
  if (fGroup) fGroup.addEventListener('change', function(e){ params.group = e.target.value.trim(); applyFilters(); });
  if (fStatus)fStatus.addEventListener('change', function(e){ params.online = e.target.value; applyFilters(); });
  if (fOrder) fOrder.addEventListener('change', function(e){ params.order = e.target.value; applyFilters(); });
  if (fDir)   fDir.addEventListener('change', function(e){ params.dir   = e.target.value; applyFilters(); });
  if (fPer)   fPer.addEventListener('change', function(e){ perPage = parseInt(e.target.value,10)||25; page=1; load().catch(function(e){ alert(e.message); }); });
  if (btnReload) btnReload.addEventListener('click', function(){ load().catch(function(e){ alert(e.message); }); });

  // ===== Paginação =====
  if (pgPrev) pgPrev.addEventListener('click', function(){ if (page>1){ page--; load().catch(function(e){ alert(e.message); }); } });
  if (pgNext) pgNext.addEventListener('click', function(){ if (page<pages){ page++; load().catch(function(e){ alert(e.message); }); } });

  // ===== Modal helpers =====
  function openCreate(){
    if (muMode) muMode.value = 'create';
    if (muTitle) muTitle.textContent = 'Novo cliente';
    if (muForm && muForm.reset) muForm.reset();
    [fUser,fNome,fTel,fEmail,fNasc,fSexo,fSenha,fGroupE,fMax].forEach(function(i){ if(i) i.value=''; });
    if (fExcl) fExcl.checked = false;
    if (modal) modal.setAttribute('aria-hidden','false');
    if (fUser) setTimeout(function(){ fUser.focus(); }, 0);
  }
  function openEdit(user){
    if (muMode) muMode.value = 'update';
    if (muTitle) muTitle.textContent = 'Editar cliente';
    if (muForm && muForm.reset) muForm.reset();
    if (fUser)   fUser.value   = user.username || '';
    if (fNome)   fNome.value   = user.nome || '';
    if (fTel)    fTel.value    = user.telefone || '';
    if (fEmail)  fEmail.value  = user.email || '';
    if (fNasc)   fNasc.value   = user.nascimento || '';
    if (fSexo)   fSexo.value   = user.sexo || '';
    if (fGroupE) fGroupE.value = user.group || '';
    if (fMax)    fMax.value    = user.max_minutes || '';
    if (fSenha)  fSenha.value  = '';
    if (fExcl)   fExcl.checked = false;
    if (modal) modal.setAttribute('aria-hidden','false');
    if (fNome) setTimeout(function(){ fNome.focus(); }, 0);
  }
  function closeModal(){ if (modal) modal.setAttribute('aria-hidden','true'); }

  // backdrop fecha
  if (modal) modal.addEventListener('click', function(ev){
    var card = ev.target.closest('.card');
    if (!card) closeModal();
  });
  if (btnClose)  btnClose.addEventListener('click', closeModal);
  if (btnCancel) btnCancel.addEventListener('click', closeModal);

  // Botão Novo
  if (btnAdd) btnAdd.addEventListener('click', function(e){
    e.preventDefault(); e.stopPropagation();
    openCreate();
  });

  // ===== Ações da tabela =====
  var grid = $('#grid');
  if (grid) grid.addEventListener('click', async function(ev){
    var btn = ev.target.closest('button[data-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-action');
    var username = btn.getAttribute('data-username') || '';
    if (!username) return;

    if (action === 'history') {
      openHistory(username);
      return;
    }

    if (action === 'edit'){
      try{
        const r = await fetch(API_GET + '?u=' + encodeURIComponent(username), { credentials:'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Falha ao carregar');
        openEdit(j.user);
      } catch(e){ alert(e.message); }
      return;
    }

    if (action === 'del'){
      if (!confirm('Confirma EXCLUSÃO definitiva do usuário '+username+'?')) return;
      try{
        const r = await fetch(API_DEL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          credentials: 'same-origin',
          body: JSON.stringify({ username: username, confirm: true, _csrf: CSRF })
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Falha ao excluir');
        alert('Excluído com sucesso.');
        load().catch(function(e){ alert(e.message); });
      } catch(e){ alert(e.message); }
      return;
    }
  });

  function closeHistory(){
    if (historyClose && document.activeElement === historyClose) {
      historyClose.blur();
    }
    if (historyModal) {
      historyModal.setAttribute('aria-hidden','true');
    }
    setTimeout(function(){
      if (btnAdd && document.body.contains(btnAdd)) {
        btnAdd.focus({ preventScroll: true });
      }
    }, 10);
  }

  async function openHistory(username){
    if (!historyModal) return;
    if (historyTitle) historyTitle.textContent = 'Histórico do cliente • ' + username;
    if (historySummary) historySummary.innerHTML = '<span class="muted">Carregando…</span>';
    if (historyOrders) historyOrders.innerHTML = '<span class="muted">Carregando…</span>';
    if (historySessions) historySessions.innerHTML = '<span class="muted">Carregando…</span>';
    historyModal.setAttribute('aria-hidden', 'false');

    try {
      const resp = await fetch(API_HISTORY + '?u=' + encodeURIComponent(username), { credentials: 'same-origin', cache: 'no-store' });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Falha ao carregar histórico');
      renderHistory(username, data);
    } catch (err) {
      if (historySummary) historySummary.innerHTML = '<span class="muted">Erro: ' + escHtml(err.message) + '</span>';
      if (historyOrders) historyOrders.innerHTML = '';
      if (historySessions) historySessions.innerHTML = '';
    }
  }

  function renderHistory(username, payload) {
    var info = payload.user || null;
    if (historyTitle) {
      if (info && info.nome) {
        historyTitle.textContent = info.nome + ' • ' + username;
      } else {
        historyTitle.textContent = 'Histórico do cliente • ' + username;
      }
    }
    if (historySummary) {
      if (!info) {
        historySummary.innerHTML = '<span class="muted">Nenhum cadastro completo encontrado para o usuário ' + escHtml(username) + '.</span>';
      } else {
        var meta = [];
        if (info.nome) meta.push('<strong>Nome:</strong> ' + escHtml(info.nome));
        meta.push('<strong>CPF:</strong> ' + escHtml(username));
        if (info.email) meta.push('<strong>E-mail:</strong> ' + escHtml(info.email));
        if (info.telefone) meta.push('<strong>Telefone:</strong> ' + escHtml(info.telefone));
        if (info.nascimento) meta.push('<strong>Nascimento:</strong> ' + escHtml(info.nascimento));
        if (info.created_at) meta.push('<strong>Cadastrado em:</strong> ' + escHtml(info.created_at));
        if (info.vip_ativo !== null) meta.push('<strong>VIP ativo:</strong> ' + (info.vip_ativo ? 'Sim' : 'Não'));
        if (info.vip_expira) meta.push('<strong>VIP expira:</strong> ' + escHtml(info.vip_expira));
        historySummary.innerHTML = '<div class="history-meta">' + meta.map(function(line){ return '<span>' + line + '</span>'; }).join('') + '</div>';
      }
    }

    if (historyOrders) {
      var orders = Array.isArray(payload.orders) ? payload.orders : [];
      if (!orders.length) {
        historyOrders.innerHTML = '<span class="muted">Nenhuma compra registrada.</span>';
      } else {
        var rows = orders.map(function(o){
          var payParts = [];
          if (o.payment_method) payParts.push(String(o.payment_method).toUpperCase());
          if (o.payment_method_detail) payParts.push(o.payment_method_detail);
          if (o.payment_installments && o.payment_installments > 1) payParts.push('x' + o.payment_installments);
          var payLabel = payParts.length ? payParts.join(' ') : '—';
          var hostLabel = o.host_code || o.partner_code || '—';
          var planLabel = o.plano_nome || o.plano_grupo || '—';
          return '<tr>' +
            '<td>' + escHtml(o.created_at || '—') + '</td>' +
            '<td>' + escHtml(planLabel) + '</td>' +
            '<td>' + escHtml(o.valor_formatado) + '</td>' +
            '<td>' + escHtml(o.status) + '</td>' +
            '<td>' + escHtml(o.paid_at || '—') + '</td>' +
            '<td>' + escHtml(o.applied_at || '—') + '</td>' +
            '<td>' + escHtml(payLabel) + '</td>' +
            '<td>' + escHtml(hostLabel) + '</td>' +
          '</tr>';
        }).join('');
        historyOrders.innerHTML = '<table class="tabela compact">' +
          '<thead><tr>' +
            '<th>Criação</th><th>Plano</th><th>Valor</th><th>Status</th><th>Pago em</th><th>Aplicado em</th><th>Pagamento</th><th>Host/Parceiro</th>' +
          '</tr></thead><tbody>' + rows + '</tbody></table>';
      }
    }

    if (historySessions) {
      var sessions = Array.isArray(payload.sessions) ? payload.sessions : [];
      if (!sessions.length) {
        historySessions.innerHTML = '<span class="muted">Nenhum acesso encontrado.</span>';
      } else {
        var rowsSess = sessions.map(function(s){
          var nasLabel = s.nas_name || s.nas_identifier || s.nas_ip || '—';
          return '<tr>' +
            '<td>' + escHtml(s.start || '—') + '</td>' +
            '<td>' + escHtml(s.stop || '—') + '</td>' +
            '<td>' + escHtml(fmtDurationSec(s.duration)) + '</td>' +
            '<td>' + escHtml(fmtBytes(s.output_octets)) + '</td>' +
            '<td>' + escHtml(fmtBytes(s.input_octets)) + '</td>' +
            '<td>' + escHtml(nasLabel) + '</td>' +
            '<td>' + escHtml(s.framed_ip || '—') + '</td>' +
            '<td>' + escHtml(s.mac || '—') + '</td>' +
            '<td>' + escHtml(s.terminate_cause || '—') + '</td>' +
          '</tr>';
        }).join('');
        historySessions.innerHTML = '<table class="tabela compact">' +
          '<thead><tr>' +
            '<th>Início</th><th>Término</th><th>Duração</th><th>Download</th><th>Upload</th><th>NAS</th><th>IP</th><th>MAC</th><th>Motivo saída</th>' +
          '</tr></thead><tbody>' + rowsSess + '</tbody></table>';
      }
    }
  }

  if (historyModal) {
    historyModal.addEventListener('click', function(ev){
      var card = ev.target.closest('.card');
      if (!card) closeHistory();
    });
  }
  if (historyClose) historyClose.addEventListener('click', closeHistory);
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') closeHistory();
  });

  // ===== Salvar (create/update) =====
  if (muForm) muForm.addEventListener('submit', async function(e){
    e.preventDefault();
    var payload = {
      mode: (muMode && muMode.value) || 'create',
      username: (fUser && fUser.value ? fUser.value : '').replace(/\D+/g,''),
      nome: (fNome && fNome.value ? fNome.value.trim() : ''),
      telefone: (fTel && fTel.value ? fTel.value.replace(/\D+/g,'') : ''),
      email: (fEmail && fEmail.value ? fEmail.value.trim() : ''),
      nascimento: (fNasc && fNasc.value ? fNasc.value.trim() : ''),
      sexo: (fSexo && fSexo.value ? fSexo.value.trim() : ''),
      senha: (fSenha && fSenha.value) ? fSenha.value : '',
      group: (fGroupE && fGroupE.value ? fGroupE.value.trim() : ''),
      exclusive: !!(fExcl && fExcl.checked),
      max_minutes: (fMax && fMax.value ? fMax.value.trim() : ''),
      _csrf: CSRF
    };
    try{
      const r = await fetch(API_SAVE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Falha ao salvar');
      closeModal();
      load().catch(function(e){ alert(e.message); });
    } catch(e){ alert(e.message); }
  });

  // ===== Start =====
  load().catch(function(e){
    gridBody.innerHTML = '<tr><td colspan="8" class="muted">Erro: '+escHtml(e.message)+'</td></tr>';
  });
})();
