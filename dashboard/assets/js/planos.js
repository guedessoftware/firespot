// /dashboard/assets/js/planos.js
(() => {
  const $  = (s, root=document) => root.querySelector(s);
  const $$ = (s, root=document) => Array.from(root.querySelectorAll(s));
  const API = 'api/planos_data.php';

  function getCSRF(){
    const m = document.querySelector('meta[name="firespot-csrf"]');
    return m ? m.content : '';
  }
  const esc = (s)=>String(s ?? '').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const fmtMbpsFromKbps = (kbps) => {
    const n = Number(kbps || 0) / 1000;
    const dec = n >= 100 ? 0 : n >= 10 ? 1 : 1;
    return `${n.toFixed(dec)} Mbps`;
  };
  const fmtDur = (n)=> (n && Number(n)>0) ? `${Number(n)} min` : '—';
  const fmtBRL = (centavos)=> (Number(centavos||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const centsFromInput = (s) => {
    if (s == null) return 0;
    let x = String(s).trim().replace(/^R\$\s*/i,'');
    x = x.replace(/\./g,'').replace(',', '.');
    const f = parseFloat(x);
    return Number.isFinite(f) ? Math.round(f * 100) : 0;
  };

  async function apiList(params={}){
    const url = new URL(API, window.location.href);
    Object.entries(params).forEach(([k,v])=>{
      if (v!==undefined && v!=='') url.searchParams.set(k,v);
    });
    const r = await fetch(url.toString(), { cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP '+r.status);
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Falha');
    return j;
  }
  async function apiPost(action, data){
    const fd = new FormData();
    fd.set('action', action);
    fd.set('csrf', getCSRF());
    Object.entries(data||{}).forEach(([k,v])=> fd.set(k, v));
    const r = await fetch(API, { method: 'POST', body: fd });
    if (!r.ok) throw new Error('HTTP '+r.status);
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Falha');
    return j;
  }

  function renderTable(planos){
    const tb = $('#tbl-planos tbody');
    if (!tb) return;
    if (!Array.isArray(planos) || planos.length===0){
      tb.innerHTML = `<tr><td colspan="11">Nenhum plano encontrado</td></tr>`;
      return;
    }

    tb.innerHTML = planos.map(p => {
      const desc = p.descricao ? `<div class="plan-description">${esc(p.descricao)}</div>` : '';
      const ativoIcon = Number(p.ativo) ? '🟢' : '⚪';
      const toggleTitle = Number(p.ativo) ? 'Desativar' : 'Ativar';
      const toggleGlyph = Number(p.ativo) ? '⏸' : '▶';

      return `
        <tr data-id="${Number.parseInt(p.id,10)||0}"
            data-nome="${esc(p.nome)}"
            data-grupo="${esc(p.grupo||'')}"
            data-preco_centavos="${Number(p.preco_centavos)||0}"
            data-down_kbps="${Number(p.down_kbps)||0}"
            data-up_kbps="${Number(p.up_kbps)||0}"
            data-duracao_min="${Number(p.duracao_min)||0}"
            data-descricao="${esc(p.descricao||'')}"
            data-ativo="${Number(p.ativo)||0}"
            data-ordem="${Number(p.ordem)||0}"
            data-atualizado_em="${esc(p.atualizado_em||'')}"
        >
          <td>${Number.parseInt(p.id,10)||0}</td>
          <td><strong>${esc(p.nome)}</strong>${desc}</td>
          <td>${esc(p.grupo||'')}</td>
          <td>${esc(fmtBRL(p.preco_centavos))}</td>
          <td>${esc(fmtMbpsFromKbps(p.down_kbps))}</td>
          <td>${esc(fmtMbpsFromKbps(p.up_kbps))}</td>
          <td>${esc(fmtDur(p.duracao_min))}</td>
          <td>${ativoIcon}</td>
          <td>${Number(p.ordem)||0}</td>
          <td>${esc(p.atualizado_em||'')}</td>
          <td class="plan-row-actions">
            <button class="theme-btn btn-edit"   title="Editar">✎</button>
            <button class="theme-btn btn-toggle" title="${toggleTitle}">${toggleGlyph}</button>
            <button class="theme-btn btn-del"    title="Arquivar">🗑</button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function openModal(mode='new', data=null){
    $('#modal-title').textContent = mode==='edit' ? 'Editar Plano' : 'Novo Plano';
    $('#pl-id').value       = data?.id || '';
    $('#pl-nome').value     = data?.nome || '';
    $('#pl-grupo').value    = data?.grupo || '';
    $('#pl-preco').value    = data?.preco_centavos != null ? (Number(data.preco_centavos)/100).toLocaleString('pt-BR',{minimumFractionDigits:2}) : '';
    $('#pl-down').value     = data?.down_kbps != null ? Math.round(Number(data.down_kbps)/1000) : '';
    $('#pl-up').value       = data?.up_kbps   != null ? Math.round(Number(data.up_kbps)/1000)   : '';
    $('#pl-duracao').value  = data?.duracao_min || '';
    $('#pl-ordem').value    = data?.ordem != null ? Number(data.ordem) : 100;
    $('#pl-desc').value     = data?.descricao || '';
    $('#pl-ativo').checked  = data ? Number(data.ativo)===1 : true;

    document.body.classList.add('modal-open');
    const bd = document.getElementById('modal-planos');
    if (bd) { bd.classList.add('open'); bd.setAttribute('aria-hidden', 'false'); }
  }
  function closeModal(){
    const bd = document.getElementById('modal-planos');
    if (bd) { bd.classList.remove('open'); bd.setAttribute('aria-hidden', 'true'); }
    document.body.classList.remove('modal-open');
  }
  document.getElementById('modal-planos')?.addEventListener('click', (e)=>{
    if (e.target.id === 'modal-planos') closeModal();
  });
  document.getElementById('btn-new')?.addEventListener('click', () => openModal('new', null));

  async function load(){
    const q     = $('#f-q')?.value.trim() || '';
    const ativo = $('#f-ativo')?.value || '';
    const order = $('#f-order')?.value || 'atualizado_em';
    const j = await apiList({ action:'list', q, ativo, order });
    renderTable(j.planos);
  }

  function novoPlano(preset = {}) {
    const f = $('#form-plano');
    if (f && typeof f.reset === 'function') f.reset();
    openModal('new', {
      nome: preset.nome || '',
      grupo: preset.grupo || '',
      preco_centavos: preset.preco_centavos ?? null,
      down_kbps: preset.down_kbps ?? null,
      up_kbps: preset.up_kbps ?? null,
      duracao_min: preset.duracao_min ?? '',
      ordem: preset.ordem ?? 100,
      descricao: preset.descricao || '',
      ativo: preset.ativo ?? 1
    });
  }

  function bind(){
    $('#btn-apply')?.addEventListener('click', ()=> load().catch(console.error));
    $('#btn-reload')?.addEventListener('click', ()=> load().catch(console.error));
    $('#btn-new')?.addEventListener('click', () => novoPlano());
    $('#modal-close')?.addEventListener('click', closeModal);
    $('#btn-cancel')?.addEventListener('click', closeModal);
    $('#modal-planos')?.addEventListener('click', (e)=>{
      if (e.target.id === 'modal-planos') closeModal();
    });

    // salvar
    $('#form-plano')?.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const payload = {
        id:             $('#pl-id').value,
        nome:           $('#pl-nome').value.trim(),
        grupo:          $('#pl-grupo').value.trim(),
        preco_centavos: (()=>{ // aceita "preco" string também no backend
          const v = $('#pl-preco').value;
          let x = String(v).trim().replace(/^R\$\s*/i,'');
          x = x.replace(/\./g,'').replace(',', '.');
          const f = parseFloat(x);
          return Number.isFinite(f) ? Math.round(f*100) : 0;
        })(),
        down_kbps:      Math.max(0, (parseInt($('#pl-down').value,10) || 0) * 1000),
        up_kbps:        Math.max(0, (parseInt($('#pl-up').value,10)   || 0) * 1000),
        duracao_min:    Math.max(0, parseInt($('#pl-duracao').value,10) || 0),
        ordem:          Math.max(0, parseInt($('#pl-ordem').value,10)   || 100),
        descricao:      $('#pl-desc').value || '',
        ativo:          $('#pl-ativo').checked ? 1 : 0
      };
      const isEdit = payload.id && Number(payload.id)>0;
      try{
        await apiPost(isEdit ? 'update' : 'create', payload);
        closeModal();
        await load();
      }catch(err){
        console.error(err);
        alert('Falha ao salvar');
      }
    });

    // ações
    $('#tbl-planos')?.addEventListener('click', async (e)=>{
      const btn = e.target.closest('button');
      if (!btn) return;
      const tr = e.target.closest('tr');
      const id = tr ? Number(tr.dataset.id) : 0;
      if (!id) return;

      if (btn.classList.contains('btn-edit')) {
        const data = {
          id: id,
          nome: tr.dataset.nome || '',
          grupo: tr.dataset.grupo || '',
          preco_centavos: Number(tr.dataset.preco_centavos)||0,
          down_kbps: Number(tr.dataset.down_kbps)||0,
          up_kbps: Number(tr.dataset.up_kbps)||0,
          duracao_min: Number(tr.dataset.duracao_min)||0,
          descricao: tr.dataset.descricao || '',
          ativo: Number(tr.dataset.ativo)||0,
          ordem: Number(tr.dataset.ordem)||100
        };
        openModal('edit', data);
        return;
      }

      if (btn.classList.contains('btn-toggle')) {
        const makeActive = (tr.dataset.ativo === '1') ? 0 : 1;
        try {
          await apiPost('toggle', { id, ativo: makeActive });
          await load();
        } catch(err){ console.error(err); alert('Falha ao alterar status'); }
        return;
      }

      if (btn.classList.contains('btn-del')) {
        if (!confirm('Arquivar este plano global? O histórico será preservado.')) return;
        try {
          await apiPost('delete', { id });
          await load();
        } catch(err){ console.error(err); alert('Falha ao excluir'); }
        return;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    if ((document.body.dataset.page||'') !== 'planos') return;
    const section = new URLSearchParams(window.location.search).get('section') || 'firespot';
    if (section !== 'access') return;
    bind();
    load().catch(err=>{
      console.error(err);
      const tb = $('#tbl-planos tbody');
      if (tb) tb.innerHTML = `<tr><td colspan="11">Erro ao carregar</td></tr>`;
    });
  });
})();
