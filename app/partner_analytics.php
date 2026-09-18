<?php

declare(strict_types=1);

require_once __DIR__ . '/partner_entitlements.php';
require_once __DIR__ . '/partner_hotspots.php';

function fs_partner_analytics_schema_ready(PDO $pdo): bool
{
    try{$pdo->query('SELECT metric_date FROM partner_daily_metrics LIMIT 0');return true;}catch(Throwable $e){return false;}
}

function fs_partner_analytics_date(string $value): string
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('America/Manaus'));
    $errors=DateTimeImmutable::getLastErrors();
    if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Data analítica inválida.');
    return $value;
}

/** @return array{from:string,to:string,hotspot_id:?int,days:int} */
function fs_partner_analytics_filters(PDO $pdo, int $partnerId, array $input): array
{
    $preset=(string)($input['period']??'30');$today=new DateTimeImmutable('today',new DateTimeZone('America/Manaus'));
    if(in_array($preset,['1','7','14','30','90'],true)){$days=(int)$preset;$to=$today;$from=$today->modify('-'.($days-1).' days');}
    else{$from=new DateTimeImmutable(fs_partner_analytics_date((string)($input['from']??'')),new DateTimeZone('America/Manaus'));$to=new DateTimeImmutable(fs_partner_analytics_date((string)($input['to']??'')),new DateTimeZone('America/Manaus'));if($to<$from)throw new InvalidArgumentException('A data final deve ser igual ou posterior à inicial.');$days=(int)$from->diff($to)->days+1;}
    $limit=fs_partner_plan_limits($pdo,$partnerId)['max_report_range_days'];if($days>$limit)throw new RuntimeException('O período selecionado excede o limite de '.$limit.' dias do plano.');
    $hotspotId=null;$raw=trim((string)($input['hotspot_id']??''));
    if($raw!==''){$hotspotId=(int)$raw;if($hotspotId<=0||!fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false))throw new InvalidArgumentException('O ponto selecionado não pertence ao estabelecimento.');}
    return ['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'hotspot_id'=>$hotspotId,'days'=>$days];
}

/** @return array<string,int> */
function fs_partner_analytics_empty_metrics(): array
{
    return ['sessions_count'=>0,'unique_devices'=>0,'new_visitors'=>0,'returning_visitors'=>0,'session_seconds'=>0,'input_bytes'=>0,'output_bytes'=>0,
        'portal_opens'=>0,'options_views'=>0,'paid_option_views'=>0,'checkout_views'=>0,'courtesy_selections'=>0,'subscriber_selections'=>0,
        'courtesy_requests'=>0,'courtesy_activated'=>0,'courtesy_denied_or_failed'=>0,'courtesy_exhausted'=>0,
        'orders_created'=>0,'orders_paid'=>0,'orders_pending'=>0,'orders_failed'=>0,'revenue_cents'=>0,'refunds_count'=>0,'coa_applied'=>0,'coa_attention'=>0,
        'subscriber_accesses'=>0,'subscriber_failures'=>0,'ad_impressions'=>0,'ad_completions'=>0,'ad_clicks'=>0,'ad_interests'=>0,'leads_created'=>0,
        'operation_successes'=>0,'operation_failures'=>0];
}

/**
 * Classifica somente em memória os identificadores já existentes no
 * accounting e devolve contagens. Nenhum MAC, IP ou hash individual é
 * persistido no schema analítico.
 * @return array{unique_devices:int,new_visitors:int,returning_visitors:int}
 */
function fs_partner_analytics_visitor_counts(PDO $pdo, int $partnerId, int $hotspotId, string $from, string $to): array
{
    $targetCredentials="SELECT radius_username username FROM guest_orders WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL
        UNION SELECT radius_username username FROM courtesy_grants WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL
        UNION SELECT radius_username username FROM subscriber_access_grants WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL";
    $daily=$pdo->prepare("SELECT DISTINCT r.callingstationid FROM radacct r JOIN ({$targetCredentials}) c ON c.username=r.username WHERE r.acctstarttime>=? AND r.acctstarttime<? AND NULLIF(TRIM(r.callingstationid),'') IS NOT NULL");
    $daily->execute([$partnerId,$hotspotId,$partnerId,$hotspotId,$partnerId,$hotspotId,$from,$to]);
    $devices=array_values(array_unique(array_map('strval',$daily->fetchAll(PDO::FETCH_COLUMN)?:[])));
    if(!$devices)return ['unique_devices'=>0,'new_visitors'=>0,'returning_visitors'=>0];

    $partnerCredentials="SELECT radius_username username FROM guest_orders WHERE partner_id=? AND radius_username IS NOT NULL
        UNION SELECT radius_username username FROM courtesy_grants WHERE partner_id=? AND radius_username IS NOT NULL
        UNION SELECT radius_username username FROM subscriber_access_grants WHERE partner_id=? AND radius_username IS NOT NULL";
    $firstSeen=[];
    foreach(array_chunk($devices,200) as $chunk){
        $placeholders=implode(',',array_fill(0,count($chunk),'?'));
        $query=$pdo->prepare("SELECT r.callingstationid,MIN(r.acctstarttime) first_seen FROM radacct r JOIN ({$partnerCredentials}) c ON c.username=r.username WHERE r.callingstationid IN ({$placeholders}) AND r.acctstarttime IS NOT NULL GROUP BY r.callingstationid");
        $query->execute(array_merge([$partnerId,$partnerId,$partnerId],$chunk));
        foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$firstSeen[(string)$row['callingstationid']]=(string)$row['first_seen'];
    }
    $new=0;$returning=0;
    foreach($devices as $device){if(isset($firstSeen[$device])&&$firstSeen[$device]<$from)$returning++;else $new++;}
    return ['unique_devices'=>count($devices),'new_visitors'=>$new,'returning_visitors'=>$returning];
}

function fs_partner_analytics_rollup_hotspot(PDO $pdo, array $hotspot, string $date): array
{
    $partnerId=(int)$hotspot['partner_id'];$hotspotId=(int)$hotspot['id'];$metrics=fs_partner_analytics_empty_metrics();$from=$date.' 00:00:00';$to=(new DateTimeImmutable($date,new DateTimeZone('America/Manaus')))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $created=$pdo->prepare("SELECT COUNT(*) orders_created,SUM(status='pending') orders_pending FROM guest_orders WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<?");$created->execute([$partnerId,$hotspotId,$from,$to]);foreach($created->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
    $orders=$pdo->prepare("SELECT SUM(status='paid') orders_paid,SUM(status IN ('payment_failed','cancelled')) orders_failed,COALESCE(SUM(CASE WHEN status='paid' THEN amount_cents ELSE 0 END),0) revenue_cents,SUM(status='refunded') refunds_count,SUM(radius_coa_status='applied') coa_applied,SUM(radius_coa_status IN ('failed','manual_review')) coa_attention FROM guest_orders WHERE partner_id=? AND hotspot_id=? AND COALESCE(paid_at,created_at)>=? AND COALESCE(paid_at,created_at)<?");$orders->execute([$partnerId,$hotspotId,$from,$to]);foreach($orders->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
    $courtesy=$pdo->prepare("SELECT COUNT(*) courtesy_requests,SUM(status IN ('active','exhausted','expired')) courtesy_activated,SUM(status IN ('failed','revoked')) courtesy_denied_or_failed,SUM(status='exhausted') courtesy_exhausted FROM courtesy_grants WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<?");$courtesy->execute([$partnerId,$hotspotId,$from,$to]);foreach($courtesy->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
    $subscriber=$pdo->prepare("SELECT COUNT(*) subscriber_accesses,SUM(status='failed') subscriber_failures FROM subscriber_access_grants WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<?");$subscriber->execute([$partnerId,$hotspotId,$from,$to]);foreach($subscriber->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
    $ads=$pdo->prepare("SELECT SUM(event='impression') ad_impressions,SUM(event='view_complete') ad_completions,SUM(event='destination_open') ad_clicks,SUM(event='interest_yes') ad_interests FROM custom_ads_events WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<?");$ads->execute([$partnerId,$hotspotId,$from,$to]);foreach($ads->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
    try{$portal=$pdo->prepare("SELECT SUM(event_code='portal_open') portal_opens,SUM(event_code='options_view') options_views,SUM(event_code='paid_options_view') paid_option_views,SUM(event_code='checkout_view') checkout_views,SUM(event_code='courtesy_selection') courtesy_selections,SUM(event_code='subscriber_selection') subscriber_selections FROM partner_portal_daily_events WHERE partner_id=? AND hotspot_id=? AND metric_date=?");$portal->execute([$partnerId,$hotspotId,$date]);foreach($portal->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;}catch(Throwable $error){}
    try{$leads=$pdo->prepare("SELECT COUNT(*) FROM ad_leads l JOIN ad_deliveries d ON d.id=l.delivery_id AND d.partner_id=l.partner_id WHERE l.partner_id=? AND d.hotspot_id=? AND l.created_at>=? AND l.created_at<?");$leads->execute([$partnerId,$hotspotId,$from,$to]);$metrics['leads_created']=(int)$leads->fetchColumn();}catch(Throwable $e){}
    try{$operations=$pdo->prepare("SELECT SUM(status='succeeded') operation_successes,SUM(status='failed') operation_failures FROM hotspot_change_requests WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<?");$operations->execute([$partnerId,$hotspotId,$from,$to]);foreach($operations->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;}catch(Throwable $e){}
    try{
        $sessions=$pdo->prepare("SELECT COUNT(DISTINCT r.radacctid) sessions_count,COALESCE(SUM(r.acctsessiontime),0) session_seconds,COALESCE(SUM(r.acctinputoctets),0) input_bytes,COALESCE(SUM(r.acctoutputoctets),0) output_bytes FROM radacct r JOIN (SELECT radius_username username FROM guest_orders WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL UNION SELECT radius_username FROM courtesy_grants WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL UNION SELECT radius_username FROM subscriber_access_grants WHERE partner_id=? AND hotspot_id=? AND radius_username IS NOT NULL) c ON c.username=r.username WHERE r.acctstarttime>=? AND r.acctstarttime<?");
        $sessions->execute([$partnerId,$hotspotId,$partnerId,$hotspotId,$partnerId,$hotspotId,$from,$to]);foreach($sessions->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$metrics[$key]=(int)$value;
        foreach(fs_partner_analytics_visitor_counts($pdo,$partnerId,$hotspotId,$from,$to) as $key=>$value)$metrics[$key]=$value;
    }catch(Throwable $e){error_log('[partner analytics accounting] hotspot_id='.$hotspotId.' code=ACCOUNTING_ROLLUP_FAILED');}
    return $metrics;
}

function fs_partner_analytics_refresh_reasons(PDO $pdo, int $partnerId, int $hotspotId, string $date, string $from, string $to): void
{
    $pdo->prepare('DELETE FROM partner_daily_metric_reasons WHERE metric_date=? AND partner_id=? AND hotspot_id=?')->execute([$date,$partnerId,$hotspotId]);
    $sources=[
        ['courtesy',"SELECT COALESCE(NULLIF(failure_code,''),status) reason_code,COUNT(*) total FROM courtesy_grants WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<? AND status IN ('failed','revoked') GROUP BY COALESCE(NULLIF(failure_code,''),status)"],
        ['payment',"SELECT COALESCE(NULLIF(payment_status_detail,''),status) reason_code,COUNT(*) total FROM guest_orders WHERE partner_id=? AND hotspot_id=? AND COALESCE(paid_at,created_at)>=? AND COALESCE(paid_at,created_at)<? AND status IN ('payment_failed','cancelled') GROUP BY COALESCE(NULLIF(payment_status_detail,''),status)"],
        ['subscriber',"SELECT COALESCE(NULLIF(failure_code,''),status) reason_code,COUNT(*) total FROM subscriber_access_grants WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<? AND status IN ('failed','revoked') GROUP BY COALESCE(NULLIF(failure_code,''),status)"],
        ['operation',"SELECT COALESCE(NULLIF(error_code,''),'OPERATION_FAILED') reason_code,COUNT(*) total FROM hotspot_change_requests WHERE partner_id=? AND hotspot_id=? AND created_at>=? AND created_at<? AND status='failed' GROUP BY COALESCE(NULLIF(error_code,''),'OPERATION_FAILED')"],
    ];
    $insert=$pdo->prepare('INSERT INTO partner_daily_metric_reasons (metric_date,partner_id,hotspot_id,reason_scope,reason_code,total,generated_at) VALUES (?,?,?,?,?,?,NOW())');
    foreach($sources as [$scope,$sql]){
        try{$query=$pdo->prepare($sql);$query->execute([$partnerId,$hotspotId,$from,$to]);foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$insert->execute([$date,$partnerId,$hotspotId,$scope,substr((string)$row['reason_code'],0,64),(int)$row['total']]);}
        catch(Throwable $error){error_log('[partner analytics reasons] hotspot_id='.$hotspotId.' scope='.$scope.' code=REASON_ROLLUP_FAILED');}
    }
}

function fs_partner_analytics_rollup(PDO $pdo, string $date): array
{
    if(!fs_partner_analytics_schema_ready($pdo))throw new RuntimeException('A migração analítica ainda não foi aplicada.');$date=fs_partner_analytics_date($date);
    $pdo->prepare("INSERT INTO partner_analytics_runs (metric_date,status) VALUES (?,'running')")->execute([$date]);$runId=(int)$pdo->lastInsertId();$count=0;
    try{
        // Pontos desativados continuam no rollup para preservar o histórico do
        // período em que estiveram operacionais.
        $hotspots=$pdo->query('SELECT id,partner_id,nas_id FROM partner_hotspots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $columns=array_keys(fs_partner_analytics_empty_metrics());$updates=[];foreach($columns as $column)$updates[]=$column.'=VALUES('.$column.')';
        $sql='INSERT INTO partner_daily_metrics (metric_date,partner_id,hotspot_id,'.implode(',',$columns).',generated_at) VALUES (?,?,?,'.implode(',',array_fill(0,count($columns),'?')).',NOW()) ON DUPLICATE KEY UPDATE '.implode(',',$updates).',generated_at=NOW()';$save=$pdo->prepare($sql);
        foreach($hotspots as $hotspot){$metrics=fs_partner_analytics_rollup_hotspot($pdo,$hotspot,$date);$save->execute(array_merge([$date,(int)$hotspot['partner_id'],(int)$hotspot['id']],array_values($metrics)));$from=$date.' 00:00:00';$to=(new DateTimeImmutable($date,new DateTimeZone('America/Manaus')))->modify('+1 day')->format('Y-m-d').' 00:00:00';fs_partner_analytics_refresh_reasons($pdo,(int)$hotspot['partner_id'],(int)$hotspot['id'],$date,$from,$to);$count++;}
        $pdo->prepare("UPDATE partner_analytics_runs SET status='succeeded',hotspot_count=?,finished_at=NOW() WHERE id=?")->execute([$count,$runId]);return ['date'=>$date,'hotspots'=>$count];
    }catch(Throwable $e){$pdo->prepare("UPDATE partner_analytics_runs SET status='failed',hotspot_count=?,finished_at=NOW(),error_code='ROLLUP_FAILED' WHERE id=?")->execute([$count,$runId]);throw $e;}
}

/** @return array<string,mixed> */
function fs_partner_analytics_report(PDO $pdo, int $partnerId, array $filters): array
{
    if(!fs_partner_analytics_schema_ready($pdo))throw new RuntimeException('As métricas avançadas ainda estão sendo preparadas.');
    $where='partner_id=:partner_id AND metric_date BETWEEN :from AND :to';$params=[':partner_id'=>$partnerId,':from'=>$filters['from'],':to'=>$filters['to']];if($filters['hotspot_id']){$where.=' AND hotspot_id=:hotspot_id';$params[':hotspot_id']=$filters['hotspot_id'];}
    $columns=array_keys(fs_partner_analytics_empty_metrics());$sums=[];foreach($columns as $column)$sums[]='COALESCE(SUM('.$column.'),0) '.$column;
    $statement=$pdo->prepare('SELECT '.implode(',',$sums).' FROM partner_daily_metrics WHERE '.$where);$statement->execute($params);$overview=$statement->fetch(PDO::FETCH_ASSOC)?:fs_partner_analytics_empty_metrics();foreach($overview as $key=>$value)$overview[$key]=(int)$value;
    $series=$pdo->prepare('SELECT metric_date,'.implode(',',$sums).' FROM partner_daily_metrics WHERE '.$where.' GROUP BY metric_date ORDER BY metric_date');$series->execute($params);$series=$series->fetchAll(PDO::FETCH_ASSOC)?:[];
    $compareWhere='m.partner_id=:partner_id AND m.metric_date BETWEEN :from AND :to';if($filters['hotspot_id'])$compareWhere.=' AND m.hotspot_id=:hotspot_id';
    $compareSums=[];foreach($columns as $column)$compareSums[]='COALESCE(SUM(m.'.$column.'),0) '.$column;
    $compare=$pdo->prepare('SELECT m.hotspot_id,h.name,h.code,'.implode(',',$compareSums).' FROM partner_daily_metrics m JOIN partner_hotspots h ON h.id=m.hotspot_id AND h.partner_id=m.partner_id WHERE '.$compareWhere.' GROUP BY m.hotspot_id,h.name,h.code ORDER BY sessions_count DESC,h.name');$compare->execute($params);$byHotspot=$compare->fetchAll(PDO::FETCH_ASSOC)?:[];
    $reasonWhere='partner_id=:partner_id AND metric_date BETWEEN :from AND :to';if($filters['hotspot_id'])$reasonWhere.=' AND hotspot_id=:hotspot_id';
    $reasonStatement=$pdo->prepare('SELECT reason_scope,reason_code,SUM(total) total FROM partner_daily_metric_reasons WHERE '.$reasonWhere.' GROUP BY reason_scope,reason_code ORDER BY reason_scope,total DESC,reason_code');$reasonStatement->execute($params);$reasons=$reasonStatement->fetchAll(PDO::FETCH_ASSOC)?:[];
    $overview['conversion_rate']=$overview['orders_created']>0?round($overview['orders_paid']*100/$overview['orders_created'],1):0.0;
    $overview['checkout_to_order_rate']=$overview['checkout_views']>0?round($overview['orders_created']*100/$overview['checkout_views'],1):0.0;
    $overview['average_session_minutes']=$overview['sessions_count']>0?round($overview['session_seconds']/60/$overview['sessions_count'],1):0.0;
    return ['overview'=>$overview,'series'=>$series,'by_hotspot'=>$byHotspot,'reasons'=>$reasons,'filters'=>$filters];
}
