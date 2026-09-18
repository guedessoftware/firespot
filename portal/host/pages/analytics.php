<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}
$metrics=$analyticsReport['overview']??fs_partner_analytics_empty_metrics();
$series=$analyticsReport['series']??[];
$byHotspot=$analyticsReport['by_hotspot']??[];
$formatBytes=static function(int $bytes):string{
    if($bytes>=1073741824)return number_format($bytes/1073741824,2,',','.').' GiB';
    if($bytes>=1048576)return number_format($bytes/1048576,1,',','.').' MiB';
    if($bytes>=1024)return number_format($bytes/1024,1,',','.').' KiB';
    return $bytes.' B';
};
$formatHours=static fn(int $seconds):string=>number_format($seconds/3600,1,',','.').' h';
$reasonLabels=[
    'expired'=>'Expirou antes da conclusão',
    'ENTITLEMENT_INACTIVE'=>'Benefício FIRENETWORK inativo',
    'MIKROTIK_LOGIN_NOT_CONFIRMED'=>'Login no Hotspot não foi confirmado',
    'OPERATION_FAILED'=>'Operação técnica não concluída',
    'failed'=>'Operação não concluída',
    'revoked'=>'Acesso revogado',
    'cancelled'=>'Operação cancelada',
];
$scopeLabels=['courtesy'=>'Cortesia','payment'=>'Pagamento','subscriber'=>'FIRENETWORK','operation'=>'Operação técnica'];
$orderStatusLabels=['paid'=>'Pago','pending'=>'Pendente','cancelled'=>'Cancelado','payment_failed'=>'Falhou','refunded'=>'Reembolsado'];
$period=(string)($_GET['period']??'30');if(!in_array($period,['1','7','14','30','90','custom'],true))$period='30';
$dailySize=(int)($_GET['daily_size']??10);if(!in_array($dailySize,[10,20,31],true))$dailySize=10;
$dailyRows=array_reverse($series);$dailyPages=max(1,(int)ceil(count($dailyRows)/$dailySize));
$dailyPage=max(1,min($dailyPages,(int)($_GET['daily_page']??1)));
$dailyRows=array_slice($dailyRows,($dailyPage-1)*$dailySize,$dailySize);
$pageUrl=static function(int $target)use($period,$dailySize):string{
    return '?'.http_build_query([
        'page'=>'analytics','period'=>$period,'from'=>$_GET['from']??'','to'=>$_GET['to']??'',
        'hotspot_id'=>$_GET['hotspot_id']??'','daily_size'=>$dailySize,'daily_page'=>$target,
    ],'','&',PHP_QUERY_RFC3986);
};
$rangeLabel=$analyticsFilters
    ?date('d/m/Y',strtotime((string)$analyticsFilters['from'])).' a '.date('d/m/Y',strtotime((string)$analyticsFilters['to']))
    :'Período indisponível';
$selectedHotspotId=(int)($analyticsFilters['hotspot_id']??0);$selectedHotspotName='';
foreach($partnerHotspots as $hotspot)if((int)$hotspot['id']===$selectedHotspotId){$selectedHotspotName=(string)$hotspot['name'];break;}
$chartScopeLabel=$selectedHotspotId>0&&$selectedHotspotName!==''?'Somente '.$selectedHotspotName:'Todos os pontos somados';
$scopeUrl=static function(int $hotspotId)use($period,$dailySize):string{
    return '?'.http_build_query([
        'page'=>'analytics','period'=>$period,'from'=>$_GET['from']??'','to'=>$_GET['to']??'',
        'hotspot_id'=>$hotspotId>0?$hotspotId:'','daily_size'=>$dailySize,
    ],'','&',PHP_QUERY_RFC3986);
};

$chartWidth=760;$chartHeight=230;$chartLeft=46;$chartRight=14;$chartTop=18;$chartBottom=38;
$chartPlotWidth=$chartWidth-$chartLeft-$chartRight;$chartPlotHeight=$chartHeight-$chartTop-$chartBottom;
$chartMax=1;foreach($series as $day)$chartMax=max($chartMax,(int)$day['unique_devices']);
$chartPoints=static function(string $key)use($series,$chartLeft,$chartTop,$chartPlotWidth,$chartPlotHeight,$chartMax):string{
    $count=count($series);$points=[];
    foreach($series as $index=>$day){$x=$count>1?$chartLeft+($index/($count-1))*$chartPlotWidth:$chartLeft+$chartPlotWidth/2;$y=$chartTop+(1-((int)$day[$key]/$chartMax))*$chartPlotHeight;$points[]=number_format($x,1,'.','').','.number_format($y,1,'.','');}
    return implode(' ',$points);
};
$chartLabels=[];$seriesCount=count($series);
if($seriesCount){$labelCount=min(6,$seriesCount);for($i=0;$i<$labelCount;$i++){$index=$labelCount===1?0:(int)round($i*($seriesCount-1)/($labelCount-1));$chartLabels[$index]=date('d/m',strtotime((string)$series[$index]['metric_date']));}}
$resultBars=[
    ['Vendas pagas',(int)$metrics['orders_paid'],'Pedidos confirmados no período'],
    ['Cortesias ativadas',(int)$metrics['courtesy_activated'],'Acessos de cortesia liberados'],
    ['Acessos FIRENETWORK',(int)$metrics['subscriber_accesses'],'Benefícios utilizados'],
    ['Anúncios concluídos',(int)$metrics['ad_completions'],'Exibições concluídas'],
];
$resultMax=max(1,...array_map(static fn(array $item):int=>$item[1],$resultBars));
$pointMax=max(1,...array_map(static fn(array $point):int=>(int)$point['unique_devices'],$byHotspot));
?>
<section class="stack host-analytics host-analytics-dashboard">
  <article class="card host-analytics-dashboard__header">
    <div class="host-analytics-dashboard__title">
      <div><span class="host-section-eyebrow">Desempenho do estabelecimento</span><h1>Métricas avançadas</h1><p class="subtle">Entenda movimento, recorrência, conversão e qualidade operacional por período e por ponto.</p></div>
      <?php if($analyticsReport&&partner_admin_role_has($role,'reports.export')&&fs_partner_has_entitlement($pdo,$partnerId,'reports.export',false)):?><a class="btn" href="/portal/host/analytics_export.php?<?=host_h(http_build_query(['csrf'=>csrf_token(),'period'=>$period,'from'=>$_GET['from']??'','to'=>$_GET['to']??'','hotspot_id'=>$_GET['hotspot_id']??'']))?>">Exportar CSV</a><?php endif;?>
    </div>
    <form method="get" class="host-analytics-filters" data-analytics-filters>
      <input type="hidden" name="page" value="analytics">
      <label>Período<select name="period" data-analytics-period><option value="1" <?=$period==='1'?'selected':''?>>Hoje</option><option value="7" <?=$period==='7'?'selected':''?>>7 dias</option><option value="14" <?=$period==='14'?'selected':''?>>14 dias</option><option value="30" <?=$period==='30'?'selected':''?>>30 dias</option><option value="90" <?=$period==='90'?'selected':''?>>90 dias</option><option value="custom" <?=$period==='custom'?'selected':''?>>Personalizado</option></select></label>
      <label>De<input type="date" name="from" value="<?=host_h($_GET['from']??'')?>" data-analytics-custom-date></label>
      <label>Até<input type="date" name="to" value="<?=host_h($_GET['to']??'')?>" data-analytics-custom-date></label>
      <label>Exibir ponto<select name="hotspot_id"><option value="">Todos os pontos somados</option><?php foreach($partnerHotspots as $hotspot):?><option value="<?=(int)$hotspot['id']?>" <?=$selectedHotspotId===(int)$hotspot['id']?'selected':''?>><?=host_h($hotspot['name'])?></option><?php endforeach;?></select></label>
      <label>Linhas por página<select name="daily_size"><option value="10" <?=$dailySize===10?'selected':''?>>10 dias</option><option value="20" <?=$dailySize===20?'selected':''?>>20 dias</option><option value="31" <?=$dailySize===31?'selected':''?>>31 dias</option></select></label>
      <button class="btn primary" type="submit">Aplicar filtros</button>
    </form>
    <div class="host-analytics-dashboard__range"><span class="pill ok"><?=host_h($rangeLabel)?></span><strong><?=host_h($chartScopeLabel)?></strong><span>Consulta limitada a <?=(int)$platformLimits['max_report_range_days']?> dias pelo plano.</span></div>
  </article>

  <?php if($analyticsError):?><div class="notice error"><?=host_h($analyticsError)?></div><?php elseif($analyticsReport):?>
  <div class="host-analytics-dashboard__kpis" aria-label="Indicadores principais">
    <article class="card host-analytics-kpi host-analytics-kpi--orange"><span>Sessões</span><strong><?=(int)$metrics['sessions_count']?></strong><small>Conexões iniciadas no período</small></article>
    <article class="card host-analytics-kpi host-analytics-kpi--blue"><span>Dispositivos conectados</span><strong><?=(int)$metrics['unique_devices']?></strong><small>Soma das contagens diárias no período</small></article>
    <article class="card host-analytics-kpi host-analytics-kpi--violet"><span>Tempo médio</span><strong><?=number_format((float)$metrics['average_session_minutes'],1,',','')?> <small>min</small></strong><small>Duração média de cada sessão</small></article>
    <article class="card host-analytics-kpi host-analytics-kpi--cyan"><span>Tráfego</span><strong class="host-analytics-kpi__text"><?=host_h($formatBytes((int)$metrics['input_bytes']+(int)$metrics['output_bytes']))?></strong><small>Download e upload contabilizados</small></article>
    <article class="card host-analytics-kpi host-analytics-kpi--green"><span>Vendas pagas</span><strong><?=(int)$metrics['orders_paid']?></strong><small><?=number_format((float)$metrics['conversion_rate'],1,',','')?>% dos pedidos<?php if($canViewFinance):?> · <?=host_h(host_money((int)$metrics['revenue_cents']))?><?php endif;?></small></article>
    <article class="card host-analytics-kpi <?=((int)$metrics['operation_failures']>0||(int)$metrics['coa_attention']>0)?'host-analytics-kpi--red':'host-analytics-kpi--green'?>"><span>Saúde operacional</span><strong class="host-analytics-kpi__text"><?=((int)$metrics['operation_failures']+(int)$metrics['coa_attention'])===0?'Sem alertas':((int)$metrics['operation_failures']+(int)$metrics['coa_attention']).' alertas'?></strong><small><?=(int)$metrics['operation_successes']?> operação(ões) concluída(s)</small></article>
  </div>

  <div class="host-analytics-dashboard__signals" aria-label="Composição dos resultados">
    <div><span>Novas visitas</span><strong><?=(int)$metrics['new_visitors']?></strong><small>primeira presença conhecida</small></div>
    <div><span>Recorrentes</span><strong><?=(int)$metrics['returning_visitors']?></strong><small>retorno após presença anterior</small></div>
    <div><span>Cortesias</span><strong><?=(int)$metrics['courtesy_activated']?></strong><small>de <?=(int)$metrics['courtesy_requests']?> solicitações</small></div>
    <div><span>FIRENETWORK</span><strong><?=(int)$metrics['subscriber_accesses']?></strong><small><?=(int)$metrics['subscriber_failures']?> falha(s)</small></div>
    <div><span>Anúncios</span><strong><?=(int)$metrics['ad_completions']?></strong><small>de <?=(int)$metrics['ad_impressions']?> impressões</small></div>
  </div>

  <div class="host-analytics-dashboard__charts">
    <article class="card host-analytics-chart">
      <div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Evolução</span><h2>Dispositivos conectados por dia</h2><p class="subtle">Cada aparelho conta uma vez por ponto em cada dia. No modo geral, os pontos são somados.</p></div><span class="pill ok"><?=host_h($chartScopeLabel)?></span></div>
      <nav class="host-chart-scope" aria-label="Filtrar gráfico por ponto"><span>Exibir:</span><a class="<?=$selectedHotspotId===0?'active':''?>" href="<?=host_h($scopeUrl(0))?>">Todos os pontos</a><?php foreach($partnerHotspots as $hotspot):?><a class="<?=$selectedHotspotId===(int)$hotspot['id']?'active':''?>" href="<?=host_h($scopeUrl((int)$hotspot['id']))?>"><?=host_h($hotspot['name'])?></a><?php endforeach;?></nav>
      <?php if($series):?>
      <svg class="host-line-chart" viewBox="0 0 <?=$chartWidth?> <?=$chartHeight?>" role="img" aria-labelledby="analytics-line-title analytics-line-desc" preserveAspectRatio="xMidYMid meet">
        <title id="analytics-line-title">Dispositivos conectados por dia — <?=host_h($chartScopeLabel)?></title><desc id="analytics-line-desc">Quantidade diária de dispositivos distintos em <?=host_h($chartScopeLabel)?> durante o período selecionado.</desc>
        <?php for($tick=0;$tick<=4;$tick++):$y=$chartTop+$tick*($chartPlotHeight/4);$value=(int)round($chartMax*(1-$tick/4));?><line class="host-line-chart__grid" x1="<?=$chartLeft?>" y1="<?=number_format($y,1,'.','')?>" x2="<?=$chartWidth-$chartRight?>" y2="<?=number_format($y,1,'.','')?>"></line><text class="host-line-chart__axis" x="<?=$chartLeft-8?>" y="<?=number_format($y+4,1,'.','')?>" text-anchor="end"><?=$value?></text><?php endfor;?>
        <?php foreach($chartLabels as $index=>$label):$x=$seriesCount>1?$chartLeft+($index/($seriesCount-1))*$chartPlotWidth:$chartLeft+$chartPlotWidth/2;?><text class="host-line-chart__axis" x="<?=number_format($x,1,'.','')?>" y="<?=$chartHeight-10?>" text-anchor="middle"><?=host_h($label)?></text><?php endforeach;?>
        <polygon class="host-line-chart__area" points="<?=$chartLeft?>,<?=$chartTop+$chartPlotHeight?> <?=host_h($chartPoints('unique_devices'))?> <?=$chartWidth-$chartRight?>,<?=$chartTop+$chartPlotHeight?>"></polygon>
        <polyline class="host-line-chart__line host-line-chart__line--devices" points="<?=host_h($chartPoints('unique_devices'))?>"></polyline>
        <?php if($seriesCount<=31):foreach($series as $index=>$day):$x=$seriesCount>1?$chartLeft+($index/($seriesCount-1))*$chartPlotWidth:$chartLeft+$chartPlotWidth/2;$y=$chartTop+(1-((int)$day['unique_devices']/$chartMax))*$chartPlotHeight;?><circle class="host-line-chart__point" cx="<?=number_format($x,1,'.','')?>" cy="<?=number_format($y,1,'.','')?>" r="4"><title><?=host_h(date('d/m/Y',strtotime((string)$day['metric_date'])))?>: <?=(int)$day['unique_devices']?> dispositivo(s)</title></circle><?php endforeach;endif;?>
      </svg>
      <?php else:?><div class="host-analytics-empty">Ainda não há série diária neste período.</div><?php endif;?>
    </article>
    <article class="card host-analytics-results">
      <div><span class="host-section-eyebrow">Resultados</span><h2>Entregas no período</h2></div>
      <div class="host-result-bars">
        <?php foreach($resultBars as [$label,$value,$description]):?><div class="host-result-bar"><div><span><?=host_h($label)?></span><strong><?=$value?></strong></div><progress max="<?=$resultMax?>" value="<?=$value?>" aria-label="<?=host_h($label)?>: <?=$value?>"></progress><small><?=host_h($description)?></small></div><?php endforeach;?>
      </div>
    </article>
  </div>

  <article class="card host-analytics-funnel">
    <div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Jornada do visitante</span><h2>Funil do portal</h2></div><span class="pill"><?=number_format((float)$metrics['conversion_rate'],1,',','')?>% dos pedidos pagos</span></div>
    <div class="host-funnel-steps">
      <?php foreach([
        ['Aberturas',(int)$metrics['portal_opens'],'Portal carregado'],
        ['Modalidades',(int)$metrics['options_views'],'Opções visualizadas'],
        ['Planos pagos',(int)$metrics['paid_option_views'],'Oferta paga aberta'],
        ['Checkout',(int)$metrics['checkout_views'],'Pagamento iniciado'],
        ['Pedidos',(int)$metrics['orders_created'],'Pedidos criados'],
        ['Pagos',(int)$metrics['orders_paid'],'Pagamentos confirmados'],
      ] as [$label,$value,$description]):?><div class="host-funnel-step"><span><?=host_h($label)?></span><strong><?=$value?></strong><small><?=host_h($description)?></small></div><?php endforeach;?>
    </div>
    <div class="host-funnel-outcomes"><span><strong><?=(int)$metrics['courtesy_selections']?></strong> escolhas de cortesia · <?=(int)$metrics['courtesy_activated']?> ativações</span><span><strong><?=(int)$metrics['subscriber_selections']?></strong> escolhas FIRENETWORK · <?=(int)$metrics['subscriber_accesses']?> acessos</span></div>
    <p class="subtle">Eventos de navegação e resultados comerciais são contados por fontes independentes. Pedidos anteriores ao início da medição do funil podem aparecer sem uma abertura correspondente.</p>
  </article>

  <article class="card host-analytics-breakdowns">
    <div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Relatórios integrados</span><h2>Detalhes do período</h2><p class="subtle">Vendas, preferências e campanhas acompanham o mesmo período e ponto selecionados acima.</p></div><span class="pill ok"><?=host_h($rangeLabel)?></span></div>
    <div class="host-analytics-breakdown-grid">
      <?php if(fs_portal_config_has_sales($portalConfig)):?>
      <section class="host-analytics-breakdown-card">
        <header><div><span>Comercial</span><h3>Situação das vendas</h3></div><strong><?=array_sum(array_map(static fn(array $row):int=>(int)$row['total'],$reportSales))?></strong></header>
        <ul><?php foreach($reportSales as $row):?><li><span><span class="pill <?=($row['status']??'')==='paid'?'ok':''?>"><?=host_h($orderStatusLabels[$row['status']]??ucfirst((string)$row['status']))?></span></span><strong><?=(int)$row['total']?><?php if($canViewSales):?><small><?=host_h(host_money((int)$row['amount']))?></small><?php endif;?></strong></li><?php endforeach;?><?php if(!$reportSales):?><li class="host-analytics-breakdown-empty">Nenhuma venda no período.</li><?php endif;?></ul>
      </section>
      <section class="host-analytics-breakdown-card">
        <header><div><span>Preferência</span><h3>Planos mais escolhidos</h3></div></header>
        <ol><?php foreach(array_slice($reportPlans,0,5) as $row):?><li><span><?=host_h($row['plan_name']?:'Plano sem nome')?></span><strong><?=(int)$row['total']?></strong></li><?php endforeach;?><?php if(!$reportPlans):?><li class="host-analytics-breakdown-empty">Nenhum pedido no período.</li><?php endif;?></ol>
      </section>
      <?php endif;?>
      <?php if((int)$portalConfig['promotional_ads_enabled']===1):?>
      <section class="host-analytics-breakdown-card">
        <header><div><span>Campanhas</span><h3>Desempenho dos anúncios</h3></div></header>
        <ul><?php foreach(array_slice($reportAds,0,5) as $row):?><li><span><b><?=host_h($row['title'])?></b><small><?=(int)$row['completed']?> concluída(s) · <?=(int)$row['interactions']?> interesse(s)</small></span><strong><?=(int)$row['impressions']?><small>impressões</small></strong></li><?php endforeach;?><?php if(!$reportAds):?><li class="host-analytics-breakdown-empty">Nenhum evento de campanha no período.</li><?php endif;?></ul>
      </section>
      <?php endif;?>
      <section class="host-analytics-breakdown-card">
        <header><div><span>Equipe</span><h3>Últimos acessos administrativos</h3></div></header>
        <ul><?php foreach(array_slice($reportMembers,0,5) as $member):?><li><span><b><?=host_h($member['name']?:$member['email'])?></b><small><?=host_h(partner_admin_roles()[$member['role']]??$member['role'])?></small></span><strong class="host-analytics-breakdown-date"><?=host_h(host_datetime($member['last_login_at']))?></strong></li><?php endforeach;?><?php if(!$reportMembers):?><li class="host-analytics-breakdown-empty">Nenhum membro ativo.</li><?php endif;?></ul>
      </section>
    </div>
  </article>

  <article class="card host-analytics-health">
    <div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Qualidade operacional</span><h2>Conciliação e falhas</h2></div></div>
    <div class="host-analytics-health__grid">
      <div><span>CoA aplicado</span><strong><?=(int)$metrics['coa_applied']?></strong><small>Sessões pagas promovidas</small></div>
      <div class="<?=((int)$metrics['coa_attention'])>0?'has-alert':''?>"><span>CoA requer atenção</span><strong><?=(int)$metrics['coa_attention']?></strong><small>Promoções para revisar</small></div>
      <div><span>Reembolsos</span><strong><?=(int)$metrics['refunds_count']?></strong><small>Pedidos devolvidos</small></div>
      <div><span>Leads consentidos</span><strong><?=(int)$metrics['leads_created']?></strong><small>Contatos autorizados</small></div>
    </div>
    <?php if(!empty($analyticsReport['reasons'])):?><div class="table-wrap host-analytics-issues"><table><thead><tr><th>Área</th><th>O que aconteceu</th><th>Código técnico</th><th>Total</th></tr></thead><tbody><?php foreach($analyticsReport['reasons'] as $reason):$reasonCode=(string)$reason['reason_code'];?><tr><td><span class="pill"><?=host_h($scopeLabels[$reason['reason_scope']]??$reason['reason_scope'])?></span></td><td><?=host_h($reasonLabels[$reasonCode]??ucfirst(strtolower(str_replace('_',' ',$reasonCode))))?></td><td><code><?=host_h($reasonCode)?></code></td><td><strong><?=(int)$reason['total']?></strong></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="host-analytics-empty">Nenhuma falha categorizada no período.</div><?php endif;?>
  </article>

  <article class="card host-analytics-daily">
    <div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Detalhamento</span><h2>Evolução diária</h2><p class="subtle">Dias mais recentes primeiro. Use “Hoje” ou um período personalizado para isolar uma data.</p></div><span class="pill"><?=count($series)?> dia(s)</span></div>
    <div class="table-wrap"><table><thead><tr><th>Dia</th><th>Sessões</th><th>Dispositivos</th><th>Perfil das visitas</th><th>Tempo</th><th>Tráfego</th><th>Acessos</th><th>Vendas</th><?php if($canViewFinance):?><th>Receita</th><?php endif;?></tr></thead><tbody><?php foreach($dailyRows as $day):?><tr><td><strong><?=host_h(date('d/m/Y',strtotime($day['metric_date'])))?></strong></td><td><?=(int)$day['sessions_count']?></td><td><?=(int)$day['unique_devices']?></td><td><span class="host-daily-split"><strong><?=(int)$day['new_visitors']?> novas</strong><small><?=(int)$day['returning_visitors']?> recorrentes</small></span></td><td><?=host_h($formatHours((int)$day['session_seconds']))?></td><td><?=host_h($formatBytes((int)$day['input_bytes']+(int)$day['output_bytes']))?></td><td><span class="host-daily-split"><strong><?=(int)$day['courtesy_activated']?> cortesias</strong><small><?=(int)$day['subscriber_accesses']?> FIRENETWORK</small></span></td><td><strong><?=(int)$day['orders_paid']?></strong> <small>de <?=(int)$day['orders_created']?></small></td><?php if($canViewFinance):?><td><?=host_h(host_money((int)$day['revenue_cents']))?></td><?php endif;?></tr><?php endforeach;?><?php if(!$dailyRows):?><tr><td colspan="<?=$canViewFinance?9:8?>">Sem dados diários neste período.</td></tr><?php endif;?></tbody></table></div>
    <?php if($dailyPages>1):?><nav class="host-analytics-pager" aria-label="Paginação da evolução diária"><a class="btn compact <?=$dailyPage<=1?'is-disabled':''?>" href="<?=$dailyPage>1?host_h($pageUrl($dailyPage-1)):'#'?>" <?=$dailyPage<=1?'aria-disabled="true" tabindex="-1"':''?>>Anterior</a><span>Página <strong><?=$dailyPage?></strong> de <?=$dailyPages?></span><a class="btn compact <?=$dailyPage>=$dailyPages?'is-disabled':''?>" href="<?=$dailyPage<$dailyPages?host_h($pageUrl($dailyPage+1)):'#'?>" <?=$dailyPage>=$dailyPages?'aria-disabled="true" tabindex="-1"':''?>>Próxima</a></nav><?php endif;?>
  </article>

  <?php if(count($byHotspot)>1):?><article class="card host-analytics-points"><div class="host-analytics-dashboard__section-heading"><div><span class="host-section-eyebrow">Múltiplos pontos</span><h2>Comparativo por ponto</h2></div><a href="?page=hotspots">Ver configurações</a></div><div class="host-analytics-point-grid"><?php foreach($byHotspot as $point):?><article class="host-analytics-point-card"><div><strong><?=host_h($point['name'])?></strong><small><?=host_h($point['code'])?></small></div><div class="host-analytics-point-card__volume"><span><?=(int)$point['unique_devices']?> dispositivos-dia</span><progress max="<?=$pointMax?>" value="<?=(int)$point['unique_devices']?>" aria-label="<?=host_h($point['name'])?>: <?=(int)$point['unique_devices']?> dispositivos-dia"></progress></div><dl><div><dt>Sessões</dt><dd><?=(int)$point['sessions_count']?></dd></div><div><dt>Novas</dt><dd><?=(int)$point['new_visitors']?></dd></div><div><dt>Recorrentes</dt><dd><?=(int)$point['returning_visitors']?></dd></div><div><dt>Cortesias</dt><dd><?=(int)$point['courtesy_activated']?></dd></div><div><dt>Vendas</dt><dd><?=(int)$point['orders_paid']?></dd></div><div><dt>Tráfego</dt><dd><?=host_h($formatBytes((int)$point['input_bytes']+(int)$point['output_bytes']))?></dd></div></dl></article><?php endforeach;?></div></article><?php endif;?>
  <?php endif;?>
</section>
