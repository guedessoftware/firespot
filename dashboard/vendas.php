<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';

$titulo = 'Vendas';
$pageId = 'vendas';
ob_start();
?>
<div class="sales-grid">
  <section class="sales-panels">
    <div class="card metrics-card">
      <div class="card-head">
        <span class="card-eyebrow">Visão geral</span>
        <h3 class="card-title">Resumo das vendas</h3>
      </div>
      <div id="sales-kpis" class="metrics-grid">
        <article class="metric-block">
          <span class="metric-label">Pagas</span>
          <strong id="kpi-paid-count" class="metric-value">—</strong>
          <span class="metric-sub">Qtde</span>
        </article>
        <article class="metric-block">
          <span class="metric-label">Receita</span>
          <strong id="kpi-paid-amount" class="metric-value">—</strong>
          <span class="metric-sub">R$</span>
        </article>
        <article class="metric-block">
          <span class="metric-label">Ticket médio</span>
          <strong id="kpi-aov" class="metric-value">—</strong>
          <span class="metric-sub">R$</span>
        </article>
        <article class="metric-block">
          <span class="metric-label">Pendentes</span>
          <strong id="kpi-pending" class="metric-value">—</strong>
          <span class="metric-sub">Aguardando</span>
        </article>
        <article class="metric-block metric-highlight">
          <span class="metric-label">PIX</span>
          <strong id="kpi-pix-count" class="metric-value">—</strong>
          <span class="metric-sub" id="kpi-pix-amount">R$ 0,00</span>
        </article>
        <article class="metric-block metric-highlight">
          <span class="metric-label">Cartão</span>
          <strong id="kpi-card-count" class="metric-value">—</strong>
          <span class="metric-sub" id="kpi-card-amount">R$ 0,00</span>
        </article>
        <article class="metric-block metric-outline">
          <span class="metric-label">Reembolsos</span>
          <strong id="kpi-refund-count" class="metric-value">—</strong>
          <span class="metric-sub" id="kpi-refund-amount">R$ 0,00</span>
        </article>
      </div>
    </div>

    <div class="card filters-card">
      <div class="card-head">
        <span class="card-eyebrow">Filtrar resultados</span>
        <h3 class="card-title">Período e status</h3>
      </div>
      <p class="filters-hint">Ajuste as datas, status ou host para refinar os pedidos listados abaixo. Os filtros são aplicados imediatamente.</p>
      <form id="sales-filters" class="filters">
        <label>De
          <input type="date" id="from">
        </label>
        <label>Até
          <input type="date" id="to">
        </label>
        <label>Status
          <select id="status">
            <option value="">Todos</option>
            <option value="paid">paid</option>
            <option value="pending">pending</option>
            <option value="cancelled">cancelled</option>
            <option value="refunded">refunded</option>
          </select>
        </label>
        <label>Host
          <input type="text" id="host" placeholder="código do host">
        </label>
        <div class="filters-actions">
          <button class="theme-btn" id="btn-apply" type="button">Aplicar</button>
          <a class="theme-btn" id="btn-export" href="#">Exportar CSV</a>
        </div>
      </form>
    </div>
  </section>

  <section class="card sales-chart-card">
    <div class="card-head">
      <span class="card-eyebrow">Histórico</span>
      <h3 class="card-title">Vendas por dia</h3>
    </div>
    <div class="chart-wrap"><canvas id="chartSales"></canvas></div>
  </section>

  <section class="card sales-table-card">
    <div class="card-head">
      <span class="card-eyebrow">Pedidos</span>
      <h3 class="card-title">Histórico de vendas</h3>
    </div>
    <div class="table-responsive">
      <table class="tabela" id="tbl-sales">
        <thead>
          <tr>
            <th>ID</th>
            <th>Ref</th>
            <th>Status</th>
            <th>Cliente</th>
            <th>Email</th>
            <th>Telefone</th>
            <th>Valor</th>
            <th>Método</th>
            <th>Min</th>
            <th>Host</th>
            <th>Criado</th>
            <th>Pago</th>
            <th>VIP aplicado</th>
            <th>Reembolso</th>
            <th class="sales-actions-column">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="15">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php
$conteudo = ob_get_clean();
include 'layout.php';
