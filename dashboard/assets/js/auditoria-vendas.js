(function(){
  if (document.body.dataset.page !== 'aud_vendas') return;
  const btn = document.getElementById('btn-run');
  const age = document.getElementById('age');
  const verify = document.getElementById('verify');
  const tbPaid = document.getElementById('tb-paid');
  const tbPend = document.getElementById('tb-pending');
  document.getElementById('audit-sales-filters')?.addEventListener('submit',(event)=>{
    event.preventDefault();
    load();
  });

  function textCell(row, value) {
    const cell = document.createElement('td');
    cell.textContent = String(value ?? '');
    row.appendChild(cell);
    return cell;
  }

  function emptyRow(body, columns) {
    const row = document.createElement('tr');
    const cell = textCell(row, 'Nenhum registro.');
    cell.colSpan = columns;
    body.appendChild(row);
  }

  function money(value) {
    const cents = Number(value);
    return (Number.isFinite(cents) ? cents / 100 : 0).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
  }

  function operationButton(reference, kind, label) {
    const button = document.createElement('button');
    button.className = 'theme-btn';
    button.type = 'button';
    button.textContent = label;
    button.dataset[kind] = String(reference ?? '');
    return button;
  }

  function paidRow(order) {
    const row = document.createElement('tr');
    textCell(row, `ID ${order.id ?? ''}`);
    textCell(row, `Ref ${order.external_ref ?? ''}`);
    textCell(row, order.nome);
    textCell(row, money(order.valor_centavos));
    textCell(row, `${order.duracao_min ?? 0} min`);
    textCell(row, `Pago: ${order.paid_at ?? ''}`);
    const action = document.createElement('td');
    action.appendChild(operationButton(order.external_ref, 'ref', 'Aplicar VIP'));
    row.appendChild(action);
    return row;
  }

  function pendingRow(order) {
    const row = document.createElement('tr');
    textCell(row, `ID ${order.id ?? ''}`);
    textCell(row, `Ref ${order.external_ref ?? ''}`);
    textCell(row, order.nome);
    textCell(row, money(order.valor_centavos));
    textCell(row, `${order.duracao_min ?? 0} min`);
    textCell(row, `Criado: ${order.created_at ?? ''}`);
    const payment = order.mp_status
      ? `${order.mp_payment_id ?? ''} · MP: ${order.mp_status}`
      : (order.mp_payment_id ?? '');
    textCell(row, payment);
    const action = document.createElement('td');
    action.appendChild(operationButton(order.external_ref, 'promote', 'Verificar e aplicar'));
    row.appendChild(action);
    return row;
  }

  async function load(){
    const params = new URLSearchParams({ min_age_min: age.value, verify_mp: verify.checked ? '1' : '0' });
    const r = await fetch('api/vendas_auditoria.php?'+params, {credentials:'same-origin'});
    const j = await r.json().catch(()=>null);
    tbPaid.replaceChildren();
    const paid = j?.paid_without_vip || [];
    if (!paid.length) emptyRow(tbPaid, 7);
    paid.forEach(order => tbPaid.appendChild(paidRow(order)));

    tbPend.replaceChildren();
    const pend = j?.pending_stuck || [];
    if (!pend.length) emptyRow(tbPend, 8);
    pend.forEach(order => tbPend.appendChild(pendingRow(order)));
  }

  document.addEventListener('click', async (ev)=>{
    const b = ev.target.closest('button'); if (!b) return;
    const ref = b.dataset.ref || b.dataset.promote; if (!ref) return;
    b.disabled = true; b.textContent = 'Processando...';
    try{
      const csrf = document.querySelector('meta[name="firespot-csrf"]')?.content || '';
      const r = await fetch('../portal/api/vip_promote.php', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body: JSON.stringify({ ref, csrf }) });
      const j = await r.json().catch(()=>null);
      if (j && j.ok) { b.textContent = 'Feito'; setTimeout(load, 1200); }
      else { b.textContent = 'Falhou'; }
    }catch(_){ b.textContent = 'Erro'; }
  });

  btn?.addEventListener('click', load);
  load();
})();
