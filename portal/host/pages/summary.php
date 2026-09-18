<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}
$overview=$summaryDashboard['overview']??fs_partner_dashboard_empty()['overview'];
$dashboardPoints=$summaryDashboard['points']??[];
$onlineSessions=$summaryDashboard['online']??[];
$formatBytes=static function(int $bytes):string{
    if($bytes>=1073741824)return number_format($bytes/1073741824,2,',','.').' GiB';
    if($bytes>=1048576)return number_format($bytes/1048576,1,',','.').' MiB';
    if($bytes>=1024)return number_format($bytes/1024,1,',','.').' KiB';
    return $bytes.' B';
};
$formatDuration=static function(int $seconds):string{
    $seconds=max(0,$seconds);$hours=intdiv($seconds,3600);$minutes=intdiv($seconds%3600,60);
    if($hours>0)return $hours.'h '.str_pad((string)$minutes,2,'0',STR_PAD_LEFT).'min';
    if($minutes>0)return $minutes.' min';
    return '< 1 min';
};
$maxPointVisitors=max(1,...array_map(static fn(array $point):int=>(int)$point['visitors_today'],$dashboardPoints));
$subscriptionStatus=(string)($platformSubscription['status']??'');
$subscriptionReady=$platformSubscription&&in_array($subscriptionStatus,['trial','active'],true);
?>
<section class="stack host-summary-dashboard">
  <article class="card host-summary-dashboard__hero">
    <div>
      <span class="host-section-eyebrow">Hoje no estabelecimento</span>
      <h1>Visão geral</h1>
      <p class="subtle">Acompanhe o movimento dos pontos Hotspot e as conexões ativas em um único lugar.</p>
    </div>
    <div class="host-summary-dashboard__hero-actions">
      <?php if($summaryDashboardEnabled):?><span class="host-summary-dashboard__updated">Atualizado às <?=host_h(date('H:i',strtotime((string)$summaryDashboard['updated_at'])))?></span><?php endif;?>
      <a class="btn" href="?page=summary">Atualizar</a>
      <?php if($summaryDashboardEnabled):?><a class="btn primary" href="?page=analytics">Ver métricas completas</a><?php endif;?>
    </div>
  </article>

  <?php if(!$summaryDashboardEnabled):?>
    <article class="card host-summary-dashboard__locked">
      <div><span class="pill">Resumo básico</span><h2>Painel operacional não incluído</h2><p class="subtle">Métricas em tempo real e comparativo por ponto fazem parte do plano Multipontos e Gestão Avançada.</p></div>
      <strong><?=(int)$overview['active_hotspots']?> ponto(s) ativo(s)</strong>
    </article>
  <?php elseif($summaryDashboardError):?>
    <div class="notice error"><?=host_h($summaryDashboardError)?></div>
  <?php else:?>
    <div class="host-summary-dashboard__kpis" aria-label="Indicadores operacionais de hoje">
      <article class="card host-summary-kpi host-summary-kpi--online"><span>Online agora</span><strong><?=(int)$overview['online_sessions']?></strong><small><?=((int)$overview['online_sessions']===1)?'conexão ativa':'conexões ativas'?></small></article>
      <article class="card host-summary-kpi"><span>Pessoas hoje</span><strong><?=(int)$overview['visitors_today']?></strong><small>dispositivos distintos</small></article>
      <article class="card host-summary-kpi"><span>Conexões hoje</span><strong><?=(int)$overview['sessions_today']?></strong><small>sessões iniciadas</small></article>
      <article class="card host-summary-kpi"><span>Tráfego hoje</span><strong class="host-summary-kpi__text"><?=host_h($formatBytes((int)$overview['traffic_today']))?></strong><small>das conexões iniciadas hoje</small></article>
      <article class="card host-summary-kpi"><span>Pontos operando</span><strong><?=(int)$overview['active_hotspots']?> <small>de <?=(int)$overview['total_hotspots']?></small></strong><small>instalações ativas</small></article>
      <?php if(is_array($summaryDashboard['commerce']??null)):?><article class="card host-summary-kpi"><span>Vendas hoje</span><strong><?=(int)$summaryDashboard['commerce']['sales_today']?></strong><small><?=host_h(host_money((int)$summaryDashboard['commerce']['revenue_today']))?> brutos</small></article><?php endif;?>
    </div>

    <div class="host-summary-dashboard__workspace">
      <article class="card host-summary-dashboard__points">
        <div class="host-summary-dashboard__section-heading">
          <div><span class="host-section-eyebrow">Múltiplos pontos</span><h2>Movimento por ponto</h2></div>
          <a href="?page=hotspots">Administrar pontos</a>
        </div>
        <div class="host-summary-points" role="list">
          <?php foreach($dashboardPoints as $point):?>
            <article class="host-summary-point" role="listitem">
              <div class="host-summary-point__identity">
                <span class="host-summary-point__status <?=$point['active']?'is-online':''?>" aria-label="<?=$point['active']?'Ponto ativo':'Ponto inativo'?>"></span>
                <div><strong><?=host_h($point['name'])?></strong><small><?=host_h($point['code'])?> · <?=$point['active']?'Ativo':'Inativo'?></small></div>
              </div>
              <div class="host-summary-point__activity">
                <div><span>Pessoas hoje</span><strong><?=(int)$point['visitors_today']?></strong></div>
                <progress max="<?=$maxPointVisitors?>" value="<?=(int)$point['visitors_today']?>" aria-label="<?=(int)$point['visitors_today']?> pessoa(s) hoje em <?=host_h($point['name'])?>"></progress>
              </div>
              <dl class="host-summary-point__facts">
                <div><dt>Agora</dt><dd><?=(int)$point['online_sessions']?></dd></div>
                <div><dt>Conexões</dt><dd><?=(int)$point['sessions_today']?></dd></div>
                <div><dt>Tráfego</dt><dd><?=host_h($formatBytes((int)$point['traffic_today']))?></dd></div>
              </dl>
            </article>
          <?php endforeach;?>
          <?php if(!$dashboardPoints):?><p class="host-summary-dashboard__empty">Nenhum ponto Hotspot cadastrado.</p><?php endif;?>
        </div>
      </article>

      <article class="card host-summary-dashboard__online">
        <div class="host-summary-dashboard__section-heading">
          <div><span class="host-section-eyebrow">Neste momento</span><h2>Quem está online</h2></div>
          <span class="pill ok"><?=(int)$overview['online_sessions']?> agora</span>
        </div>
        <p class="subtle">As sessões são identificadas por modalidade e ponto; dados pessoais, MAC e IP permanecem protegidos.</p>
        <div class="host-online-list" role="list">
          <?php foreach($onlineSessions as $session):?>
            <article class="host-online-session" role="listitem">
              <span class="host-online-session__index" aria-hidden="true"><?=(int)$session['position']?></span>
              <div class="host-online-session__identity"><strong>Cliente conectado</strong><small><?=host_h($session['hotspot_name'])?> · <?=host_h($session['access_type'])?></small></div>
              <div class="host-online-session__metric"><span>Online</span><strong><?=host_h($formatDuration((int)$session['connected_seconds']))?></strong></div>
              <div class="host-online-session__metric"><span>Tráfego</span><strong><?=host_h($formatBytes((int)$session['traffic_bytes']))?></strong></div>
            </article>
          <?php endforeach;?>
          <?php if(!$onlineSessions):?><div class="host-summary-dashboard__empty"><strong>Ninguém conectado agora</strong><span>As novas sessões aparecerão aqui quando o RADIUS iniciar o accounting.</span></div><?php endif;?>
        </div>
      </article>
    </div>
  <?php endif;?>

  <article class="card host-summary-dashboard__account">
    <div class="host-summary-dashboard__account-main">
      <div><span class="host-section-eyebrow">Conta e configuração</span><strong><?=host_h($platformSubscription['plan_name']??'Ativação pendente')?></strong><small><?=host_h($platformStatusLabels[$subscriptionStatus]??'Migração pendente')?> · <?=host_h($purpose)?></small></div>
      <span class="pill <?=$subscriptionReady?'ok':'warning'?>"><?=$subscriptionReady?'Plano ativo':'Requer atenção'?></span>
    </div>
    <details class="host-summary-dashboard__details">
      <summary>Ver limites e estado do portal</summary>
      <?php if(!$platformSubscription):?><p class="subtle">A ativação do catálogo e das cotas ainda está pendente.</p><?php else:?>
        <div class="host-summary-dashboard__limits">
          <?php foreach($platformQuotaLabels as $quota=>$label):$limit=(int)$platformLimits[$quota];$usage=(int)($platformQuotaUsage[$quota]??0);?><div><span><?=host_h($label)?></span><strong><?=$limit>0?$usage.' / '.$limit:'Não incluído'?></strong></div><?php endforeach;?>
          <div><span>Período analítico</span><strong><?=(int)$platformLimits['max_report_range_days']?> dias</strong></div>
          <div><span>Portal</span><strong><?=($context['portal_mode']??'inherit')==='v3'?'V3 unificado':'Compatibilidade'?></strong></div>
          <div><span>Cortesia</span><strong><?=fs_portal_config_has_courtesy($portalConfig)?'Disponível':'Não oferecida'?></strong></div>
          <div><span>Recebimento</span><strong><?php if(!fs_portal_config_has_sales($portalConfig)):?>Não aplicável<?php elseif((int)$context['independent_billing']===1):?>Carteira própria<?php else:?>FireSpot<?php endif;?></strong></div>
        </div>
      <?php endif;?>
    </details>
    <a class="btn" href="?page=portal">Configurar e simular portal</a>
  </article>

  <?php if($readiness&&!$readiness['ready']):?><article class="card"><h2>Configuração incompleta</h2><ul class="danger-text"><?php foreach($readiness['blocks'] as $item):?><li><?=host_h($item['message'])?></li><?php endforeach;?></ul></article><?php endif;?>
</section>
