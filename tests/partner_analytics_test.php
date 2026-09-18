<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/db.php';require_once __DIR__ . '/../app/partner_analytics.php';
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$metrics=fs_partner_analytics_empty_metrics();foreach(['portal_opens','options_views','checkout_views','sessions_count','unique_devices','new_visitors','returning_visitors','session_seconds','orders_paid','orders_pending','orders_failed','revenue_cents','coa_applied','coa_attention','courtesy_activated','courtesy_exhausted','subscriber_accesses','ad_impressions','ad_interests','operation_failures'] as $field)$expect(array_key_exists($field,$metrics),'Métrica obrigatória ausente: '.$field);
foreach(['device_mac','device_ip','username','email','phone','account_id'] as $pii)$expect(!array_key_exists($pii,$metrics),'Rollup inclui PII: '.$pii);
$expect(fs_partner_analytics_date('2026-09-07')==='2026-09-07','Data ISO válida recusada.');
foreach(['07/09/2026','2026-02-31',''] as $date){try{fs_partner_analytics_date($date);$invalid=false;}catch(InvalidArgumentException $e){$invalid=true;}$expect($invalid,'Data inválida aceita: '.$date);}
$pdo=db();$filters=fs_partner_analytics_filters($pdo,1,['period'=>'7']);$expect($filters['days']===7&&$filters['hotspot_id']===null,'Preset analítico de sete dias incorreto.');
$todayFilters=fs_partner_analytics_filters($pdo,1,['period'=>'1']);$expect($todayFilters['days']===1&&$todayFilters['from']===$todayFilters['to'],'Filtro analítico de um único dia está incorreto.');
$fortnightFilters=fs_partner_analytics_filters($pdo,1,['period'=>'14']);$expect($fortnightFilters['days']===14,'Preset analítico de quatorze dias está incorreto.');
$multiPartner=(int)$pdo->query('SELECT partner_id FROM partner_hotspots GROUP BY partner_id HAVING COUNT(*)>1 ORDER BY COUNT(*) DESC,partner_id LIMIT 1')->fetchColumn();
if($multiPartner>0){
    $allFilters=fs_partner_analytics_filters($pdo,$multiPartner,['period'=>'30']);$allReport=fs_partner_analytics_report($pdo,$multiPartner,$allFilters);$pointSum=0;
    $pointIds=$pdo->prepare('SELECT id FROM partner_hotspots WHERE partner_id=? ORDER BY id');$pointIds->execute([$multiPartner]);
    foreach($pointIds->fetchAll(PDO::FETCH_COLUMN)?:[] as $pointId){$pointFilters=fs_partner_analytics_filters($pdo,$multiPartner,['period'=>'30','hotspot_id'=>(string)$pointId]);$pointReport=fs_partner_analytics_report($pdo,$multiPartner,$pointFilters);$pointSum+=(int)$pointReport['overview']['unique_devices'];}
    $expect((int)$allReport['overview']['unique_devices']===$pointSum,'Visão geral não soma os dispositivos-dia de todos os pontos.');
}
try{fs_partner_analytics_filters($pdo,1,['period'=>'custom','from'=>'2026-01-01','to'=>'2026-12-31']);$quota=false;}catch(RuntimeException $e){$quota=true;}$expect($quota,'Período acima da cota foi aceito.');
$root=dirname(__DIR__);$migration=(string)file_get_contents($root.'/migrations/048_partner_analytics.sql');$domain=(string)file_get_contents($root.'/app/partner_analytics.php');$page=(string)file_get_contents($root.'/portal/host/pages/analytics.php');$export=(string)file_get_contents($root.'/portal/host/analytics_export.php');
foreach(['device_mac','device_ip','username','email','phone','account_id'] as $pii)$expect(strpos($migration,$pii)===false,'Schema do rollup materializa PII: '.$pii);
$expect(strpos($domain,'h.partner_id=m.partner_id')!==false,'Comparativo não reforça isolamento por estabelecimento.');
$expect(strpos($domain,'max_report_range_days')!==false,'Período analítico não respeita a cota do plano.');
$expect(strpos($domain,'partner_daily_metric_reasons')!==false,'Falhas analíticas não são agregadas por motivo.');
$expect(strpos($domain,'fs_partner_analytics_visitor_counts')!==false&&strpos($domain,'new_visitors')!==false&&strpos($domain,'returning_visitors')!==false,'Novas visitas e recorrência não são calculadas.');
$expect(strpos($domain,'COALESCE(paid_at,created_at)')!==false,'Pagamentos não são atribuídos ao período financeiro canônico.');
$expect(strpos($domain,'JOIN nas n ON n.id=?')===false,'Histórico de sessões ficou preso ao NAS atual depois de uma migração de ponto.');
$metricService=(string)file_get_contents($root.'/app/partner_portal_metrics.php');
$expect(strpos($metricService,'partner_portal_daily_events')!==false&&strpos($metricService,'device')===false&&strpos($metricService,'REMOTE_ADDR')===false,'Funil público armazena ou aceita identificador do visitante.');
$expect(strpos($metricService,'h.id=? AND h.partner_id=? AND h.active=1')!==false,'Funil público não confirma propriedade e atividade da instalação no banco.');
$expect(strpos($domain,"FROM partner_hotspots WHERE active=1")===false,'Rollup descartaria o histórico de instalações desativadas.');
foreach(['index.php','checkout.php','courtesy.php','subscriber.php'] as $portalFile)$expect(strpos((string)file_get_contents($root.'/portal-v3/'.$portalFile),'fs_partner_portal_metric_event')!==false,'Página não alimenta o funil agregado: '.$portalFile);
$expect(strpos($page,"\$_POST['partner_id']")===false&&strpos($page,'device_mac')===false&&strpos($page,'device_ip')===false,'Tela analítica aceita fronteira do navegador ou expõe PII.');
$expect(strpos($page,'$canViewFinance')!==false,'Receita analítica não respeita o papel financeiro.');
$expect(strpos($page,'host-line-chart')!==false&&strpos($page,'host-result-bars')!==false&&strpos($page,'host-analytics-point-card')!==false,'Tela analítica não oferece os gráficos de evolução, resultados e comparação por ponto.');
$expect(strpos($page,'Relatórios integrados')!==false&&strpos($page,'Planos mais escolhidos')!==false&&strpos($page,'Últimos acessos administrativos')!==false,'Relatórios complementares não foram consolidados em Métricas.');
$expect(strpos($page,'Dispositivos conectados por dia')!==false&&strpos($page,'Todos os pontos somados')!==false&&strpos($page,"chartPoints('sessions_count')")===false,'Gráfico principal ainda mistura sessões com a contagem diária de dispositivos.');
$expect(strpos($page,'host-chart-scope')!==false&&strpos($page,'$scopeUrl((int)$hotspot')!==false,'Gráfico não oferece seleção direta e isolada de cada ponto.');
$expect(strpos($page,'array_slice($dailyRows')!==false&&strpos($page,'daily_page')!==false&&strpos($page,'host-analytics-pager')!==false,'Evolução diária não possui paginação compacta no servidor.');
$expect(strpos($page,'value="1"')!==false&&strpos($page,'Personalizado')!==false&&strpos($page,'data-analytics-custom-date')!==false,'Filtros não permitem isolar hoje ou um intervalo personalizado.');
$expect(strpos($export,"'reports.export'")!==false&&strpos($export,'csrf_check')!==false,'Exportação não exige entitlement e CSRF.');
$expect(strpos((string)file_get_contents($root.'/app/cli/job_runner.php'),"'partner_analytics'")!==false,'Rollup não está na allowlist operacional.');
$rollupCli=(string)file_get_contents($root.'/app/cli/partner_analytics_rollup.php');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_partner_portal_finalize_root.sh');
$expect(strpos($rollupCli,'--backfill-days=')!==false&&strpos($finalizer,'--backfill-days=366')!==false,'Ativação não reconstrói o histórico permitido pelo plano.');
$smoke048=(string)file_get_contents($root.'/migrations/smoke_048.php');
$expect(strpos($smoke048,'LEFT JOIN partner_hotspots')!==false&&strpos($smoke048,'h.id IS NULL OR h.partner_id<>m.partner_id')!==false,'Smoke analítico não falha fechado diante de ponto ausente ou de outro estabelecimento.');
echo "OK: {$checks} verificações das métricas avançadas.\n";
