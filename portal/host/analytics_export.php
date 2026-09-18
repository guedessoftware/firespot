<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';require_once __DIR__ . '/../../app/partner_analytics.php';
$pdo=host_db();
try{
    $context=partner_admin_require_feature_context($pdo,'reports.export','analytics','reports.export',false);
    if(!csrf_check($_GET['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue o painel.');
    $partnerId=(int)$context['partner_id'];$filters=fs_partner_analytics_filters($pdo,$partnerId,$_GET);$report=fs_partner_analytics_report($pdo,$partnerId,$filters);
    partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$context['user_id'],'analytics.exported','partner_daily_metrics',null,['from'=>$filters['from'],'to'=>$filters['to'],'hotspot_id'=>$filters['hotspot_id']]);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="firespot-metricas-'.$filters['from'].'-'.$filters['to'].'.csv"');
    $output=fopen('php://output','w');fwrite($output,"\xEF\xBB\xBF");fputcsv($output,['data','aberturas_portal','modalidades','planos_pagos','checkout','sessoes','dispositivos_dia','novas_visitas_dia','visitas_recorrentes_dia','minutos_conectados','download_bytes','upload_bytes','cortesias_escolhidas','cortesias_ativadas','cortesias_falhas','pedidos','pagos','pendentes','falhas_pagamento','receita_centavos','coa_aplicado','coa_atencao','acessos_firenetwork','falhas_firenetwork','impressoes','conclusoes','interesses','cliques'],';');foreach($report['series'] as $row)fputcsv($output,[$row['metric_date'],$row['portal_opens'],$row['options_views'],$row['paid_option_views'],$row['checkout_views'],$row['sessions_count'],$row['unique_devices'],$row['new_visitors'],$row['returning_visitors'],round((int)$row['session_seconds']/60),$row['output_bytes'],$row['input_bytes'],$row['courtesy_selections'],$row['courtesy_activated'],$row['courtesy_denied_or_failed'],$row['orders_created'],$row['orders_paid'],$row['orders_pending'],$row['orders_failed'],$row['revenue_cents'],$row['coa_applied'],$row['coa_attention'],$row['subscriber_accesses'],$row['subscriber_failures'],$row['ad_impressions'],$row['ad_completions'],$row['ad_interests'],$row['ad_clicks']],';');fclose($output);
}catch(Throwable $e){http_response_code(403);header('Content-Type: text/plain; charset=UTF-8');echo partner_admin_public_error($e,'Não foi possível exportar as métricas.');}
