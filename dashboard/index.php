<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/control_center_dashboard.php';
require_once __DIR__ . '/components/status-pill.php';

$management = null;
if (admin_has_capability('partners.view')) {
    $managementPdo = db();
    $managementPdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $managementPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $management = fs_control_center_management_overview($managementPdo);
}

$titulo = "Dashboard";
$pageId = "dashboard";
ob_start();
?>

<section class="fs-commandbar" aria-label="Atalhos operacionais">
  <div class="fs-commandbar__intro">
    <span class="fs-commandbar__signal" aria-hidden="true"></span>
    <div>
      <strong>Central de operações</strong>
      <small>Acesse rapidamente as rotinas mais usadas do FireSpot.</small>
    </div>
  </div>
  <nav class="fs-commandbar__actions" aria-label="Ações rápidas">
    <a href="usuarios.php">Gerenciar clientes</a>
    <a href="estabelecimento.php?mode=create">Novo estabelecimento</a>
    <a href="estabelecimentos.php">Gerenciar estabelecimentos</a>
    <a href="financeiro.php" class="is-primary">Consultar financeiro</a>
  </nav>
</section>

<?php if (is_array($management)): $partnerKpis=(array)$management['partners']; ?>
<div class="fs-section-heading">
  <div><span>Gestão</span><h2>Visão dos estabelecimentos</h2></div>
  <small>Pontos são contabilizados somente dentro de cada conta</small>
</div>
<section class="fs-cc-kpis fs-management-kpis" aria-label="Indicadores gerenciais dos estabelecimentos">
  <a class="card" href="estabelecimentos.php?status=active"><span>Estabelecimentos ativos</span><strong><?=(int)($partnerKpis['active']??0)?></strong><small>Abrir carteira de contas</small></a>
  <a class="card" href="estabelecimentos.php?points=multiple"><span>Multipontos</span><strong><?=(int)($partnerKpis['multipoint']??0)?></strong><small>Resumo, sem inventário global</small></a>
  <a class="card" href="estabelecimentos.php"><span>Contas com alerta</span><strong><?=(int)($partnerKpis['alerts']??0)?></strong><small>Alertas agregados dos pontos</small></a>
  <a class="card" href="planos.php?section=subscriptions"><span>Cotas próximas do limite</span><strong><?=(int)$management['quotas_near_limit']?></strong><small>80% ou mais de consumo</small></a>
  <a class="card" href="infraestrutura.php?section=jobs"><span>Solicitações pendentes</span><strong><?=(int)$management['infrastructure_pending']?></strong><small><?=(int)$management['infrastructure_failed']?> falha(s) para revisar</small></a>
  <a class="card" href="recebimentos.php?section=wallets"><span>Carteiras/webhooks</span><strong><?=(int)$management['wallet_alerts']?></strong><small>Credencial ou validação pendente</small></a>
</section>
<section class="card fs-management-plans" aria-labelledby="management-plans-title">
  <header class="card-header card-header--compact">
    <div><span class="card-eyebrow">Contratos atuais</span><h3 class="card-title" id="management-plans-title">Assinaturas por Plano FireSpot</h3></div>
    <a class="fs-text-link" href="planos.php?section=subscriptions">Ver assinaturas e cotas</a>
  </header>
  <div class="fs-management-plan-list">
    <?php foreach($management['plans'] as $plan):?>
      <div><span><?=fs_cc_escape($plan['name'])?></span><strong><?=(int)$plan['total']?></strong></div>
    <?php endforeach;?>
    <?php if(!$management['plans']):?><p class="muted">Nenhum estabelecimento ativo para resumir.</p><?php endif;?>
  </div>
</section>
<?php endif;?>

<div class="fs-section-heading">
  <div><span>Agora</span><h2>Indicadores operacionais</h2></div>
  <small>Atualização automática a cada poucos segundos</small>
</div>

<!-- KPIs -->
<div id="cards-kpis" class="content-grid">
  <div class="card"><h3>Online agora</h3><div id="kpi-online" class="kpi-value">—</div><small class="muted">Clientes autenticados</small></div>
  <div class="card"><h3>Usuários cadastrados</h3><div id="kpi-users" class="kpi-value">—</div><small class="muted">radcheck</small></div>
  <div class="card"><h3>Dispositivos (30 dias)</h3><div id="kpi-devices30" class="kpi-value">—</div><small class="muted">callingstationid distintos</small></div>
  <div class="card"><h3>Tráfego hoje</h3><div id="kpi-trafego" class="kpi-value" title="Fonte: kpis" data-source="kpis">0 B</div><small class="muted">Input + Output</small></div>
  <div class="card"><h3>Dispositivos totais</h3><div id="kpi-devices-total" class="kpi-value">—</div><small class="muted">histórico no radacct</small></div>
  <div class="card"><h3>Novos usuários (7 dias)</h3><div id="kpi-new7" class="kpi-value">—</div><small class="muted">primeira conexão</small></div>
</div>

<!-- Device insights + NAS health -->
<div class="fs-section-heading mt-16">
  <div><span>Diagnóstico</span><h2>Clientes e infraestrutura</h2></div>
</div>
<div class="insights-grid mt-16" id="insights-grid">
  <section class="card card-insight" id="card-device-insights" aria-labelledby="device-insights-title">
    <div class="card-header card-header--compact">
      <h3 id="device-insights-title" class="card-title">Top dispositivos</h3>
      <small class="muted" id="device-insights-updated">—</small>
    </div>
    <div class="device-insights-meta" aria-live="polite">
      <div class="meta-item">
        <span class="meta-label">Dispositivos únicos</span>
        <strong id="device-insights-total" class="meta-value">—</strong>
      </div>
      <div class="meta-item">
        <span class="meta-label">Recorrentes listados</span>
        <strong id="device-insights-returning" class="meta-value">—</strong>
      </div>
      <div class="meta-item">
        <span class="meta-label">Visitas somadas</span>
        <strong id="device-insights-visits" class="meta-value">—</strong>
      </div>
    </div>
    <div class="device-insights-grid">
      <section class="insight-block insight-block--os" aria-labelledby="insight-os-title">
        <h4 class="insight-subtitle" id="insight-os-title">Mix de OS (30 dias)</h4>
        <ul class="insight-os-list" id="device-insights-os">
          <li class="muted">Carregando...</li>
        </ul>
      </section>
      <section class="insight-block insight-block--returning" aria-labelledby="insight-returning-title">
        <h4 class="insight-subtitle" id="insight-returning-title">Visitantes recorrentes</h4>
        <ol class="insight-top-list" id="device-insights-top">
          <li class="muted">Carregando...</li>
        </ol>
        <div class="insight-pagination" aria-label="Paginação de visitantes recorrentes">
          <button type="button" class="insight-page-btn" data-returning-act="prev" aria-label="Página anterior" disabled>&lsaquo;</button>
          <span class="insight-page-indicator" id="device-returning-indicator">—</span>
          <button type="button" class="insight-page-btn" data-returning-act="next" aria-label="Próxima página" disabled>&rsaquo;</button>
        </div>
      </section>
    </div>
  </section>

  <section class="card card-insight" id="card-nas-health" aria-labelledby="nas-health-title">
    <div class="card-header card-header--compact">
      <h3 id="nas-health-title" class="card-title">Saúde dos NAS</h3>
      <small class="muted" id="nas-health-updated">—</small>
    </div>
    <div class="nas-health-summary" id="nas-health-summary">
      <span class="health-pill pill-ok">OK 0</span>
      <span class="health-pill pill-error">Falha 0</span>
      <span class="health-pill pill-unknown">? 0</span>
    </div>
    <div class="nas-health-list" id="nas-health-list">
      <p class="muted">Carregando...</p>
    </div>
  </section>
</div>

<!-- Tabela de Clientes Online -->
<div class="card mt-16">
  <div class="card-header">
    <div><span class="card-eyebrow">Sessões ativas</span><h3 class="card-title">Clientes online</h3></div>
    <a class="fs-text-link" href="usuarios.php">Ver todos os clientes</a>
  </div>
  <div class="table-responsive">
    <table class="tabela" id="tbl-online">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Dispositivo</th>
          <th>Host de Origem</th>
          <th>Tempo online</th>
          <th>Up (agora)</th>
          <th>Down (agora)</th>
          <th>Perfil</th>
          <th>Visitas</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="9">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Gráficos -->
<div class="fs-section-heading mt-16">
  <div><span>Histórico</span><h2>Comportamento da rede</h2></div>
  <a class="fs-text-link" href="relatorios.php">Abrir relatórios completos</a>
</div>
<div class="charts-grid mt-16">
  <figure class="card chart-card">
    <h3 class="chart-title">Usuários online por hora (últimas 12h)</h3>
    <div class="chart-wrap" role="img" aria-label="Gráfico de linhas com a quantidade de usuários online por hora nas últimas 12 horas">
      <canvas id="chartPico24h"></canvas>
    </div>
    <figcaption class="chart-caption">

      <small class="muted">* Fonte: radacct (sobreposição por janela de 1h)</small>
    </figcaption>
  </figure>

  <figure class="card chart-card">
    <h3 class="chart-title">Conexões por dia (últimos 7 dias)</h3>
    <div class="chart-wrap" role="img" aria-label="Gráfico de barras das conexões por dia nos últimos 7 dias">
      <canvas id="chartDia7"></canvas>
    </div>
    <figcaption class="chart-caption">
      <p class="chart-desc">
        Total de conexões iniciadas em cada dia, na janela dos últimos 7 dias. Dias sem conexões aparecem como <em>0</em>.
      </p>
    </figcaption>
  </figure>

  <figure class="card chart-card">
    <h3 class="chart-title">Pico de conexões por hora (últimas 12h)</h3>
    <div class="chart-wrap" role="img" aria-label="Gráfico de barras mostrando pico de conexões simultâneas por hora">
      <canvas id="chartPicoPorHora"></canvas>
    </div>
    <figcaption class="chart-caption">
      <p class="chart-desc">
        Máximo de usuários conectados simultaneamente em cada hora, nas últimas 12 horas.
      </p>
    </figcaption>
  </figure>

  <figure class="card chart-card">
    <h3 class="chart-title">Tráfego por hora (hoje)</h3>
    <div class="chart-wrap" role="img" aria-label="Gráfico de área mostrando consumo de banda por hora">
      <canvas id="chartTrafegoPorHora"></canvas>
    </div>
    <figcaption class="chart-caption">
      <p class="chart-desc">
        Consumo total de banda (upload + download) por hora, do dia atual. Valores em <strong>MB</strong>.
      </p>
    </figcaption>
  </figure>
</div>

<?php
$conteudo = ob_get_clean();
include "layout.php";
