<?php

declare(strict_types=1);

/** @return array<string,array{label:string,max_age:int}> */
function fs_infrastructure_job_definitions(): array
{
    return [
        'cleanup_guest_access'=>['label'=>'Limpeza de acessos temporários','max_age'=>180],
        'courtesy_reconcile'=>['label'=>'Conciliação de cortesia','max_age'=>180],
        'ad_reconcile'=>['label'=>'Conciliação de publicidade','max_age'=>180],
        'subscriber_radius_reconcile'=>['label'=>'RADIUS de assinantes','max_age'=>360],
        'subscriber_cleanup'=>['label'=>'Limpeza de assinantes','max_age'=>600],
        'subscriber_entitlement_reconcile'=>['label'=>'Benefícios HubSoft','max_age'=>1800],
        'subscriber_retention'=>['label'=>'Retenção de assinantes','max_age'=>129600],
        'privacy_cleanup'=>['label'=>'Limpeza de privacidade','max_age'=>129600],
        'dashboard_concurrency'=>['label'=>'Concorrência do painel','max_age'=>180],
        'hubsoft_cache'=>['label'=>'Cache auxiliar HubSoft','max_age'=>28800],
        'partner_infrastructure'=>['label'=>'Solicitações de infraestrutura','max_age'=>300],
        'partner_analytics'=>['label'=>'Métricas dos estabelecimentos','max_age'=>1800],
    ];
}

/** @return array<string,array<string,mixed>> */
function fs_infrastructure_jobs(PDO $pdo): array
{
    $rows=$pdo->query("SELECT skey,svalue FROM app_settings WHERE skey LIKE 'ops_job_%'")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $settings=[];
    foreach($rows as $row)$settings[(string)$row['skey']]=(string)$row['svalue'];
    $now=time();
    $jobs=[];
    foreach(fs_infrastructure_job_definitions() as $name=>$definition){
        $prefix='ops_job_'.$name.'_';
        $heartbeat=(string)($settings[$prefix.'heartbeat_at']??'');
        $timestamp=$heartbeat!==''?strtotime($heartbeat):false;
        $age=$timestamp===false?null:max(0,$now-$timestamp);
        $lastOk=($settings[$prefix.'last_ok']??'')==='1';
        $fresh=$age!==null&&$age<=(int)$definition['max_age'];
        $jobs[$name]=[
            'label'=>$definition['label'],
            'state'=>$lastOk&&$fresh?'ready':($lastOk?'stale':'failed'),
            'heartbeat_at'=>$heartbeat!==''?$heartbeat:null,
            'age_seconds'=>$age,
            'last_ok'=>$lastOk,
            'runner_state'=>(string)($settings[$prefix.'state']??'not_recorded'),
            'duration_ms'=>isset($settings[$prefix.'last_duration_ms'])?(int)$settings[$prefix.'last_duration_ms']:null,
            'exit_code'=>isset($settings[$prefix.'last_exit_code'])?(int)$settings[$prefix.'last_exit_code']:null,
        ];
    }
    return $jobs;
}

function fs_infrastructure_local_service_state(string $unit): string
{
    if(!in_array($unit,['freeradius.service'],true)||!is_executable('/usr/bin/systemctl')||!function_exists('proc_open'))return'unknown';
    $process=@proc_open(['/usr/bin/systemctl','is-active',$unit],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))return'unknown';
    $output=trim((string)stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    proc_close($process);
    return in_array($output,['active','inactive','failed','activating','deactivating'],true)?$output:'unknown';
}

/** @return array<string,mixed> */
function fs_infrastructure_summary(PDO $pdo): array
{
    $nas=$pdo->query("SELECT COUNT(*) total,SUM(CASE WHEN h.status='ok' THEN 1 ELSE 0 END) healthy,SUM(CASE WHEN b.status='ready' THEN 1 ELSE 0 END) prepared FROM nas n LEFT JOIN nas_health h ON h.nas_id=n.id LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id")->fetch(PDO::FETCH_ASSOC)?:[];
    $radius=$pdo->query('SELECT COUNT(*) destinations FROM radius_servers')->fetch(PDO::FETCH_ASSOC)?:[];
    $accounting=$pdo->query('SELECT SUM(acctstoptime IS NULL) active_sessions,MAX(COALESCE(acctupdatetime,acctstoptime,acctstarttime)) last_accounting FROM radacct')->fetch(PDO::FETCH_ASSOC)?:[];
    $requests=$pdo->query("SELECT SUM(status IN ('queued','retry','running')) pending,SUM(status='failed') failed FROM hotspot_change_requests")->fetch(PDO::FETCH_ASSOC)?:[];
    $messageQueue=$pdo->query("SELECT SUM(status IN ('pending','sending')) pending,SUM(status='error') failed FROM promo_queue")->fetch(PDO::FETCH_ASSOC)?:[];
    return[
        'nas_total'=>(int)($nas['total']??0),
        'nas_healthy'=>(int)($nas['healthy']??0),
        'nas_prepared'=>(int)($nas['prepared']??0),
        'radius_destinations'=>(int)($radius['destinations']??0),
        'radius_active_sessions'=>(int)($accounting['active_sessions']??0),
        'radius_last_accounting'=>$accounting['last_accounting']??null,
        'infrastructure_pending'=>(int)($requests['pending']??0),
        'infrastructure_failed'=>(int)($requests['failed']??0),
        'messages_pending'=>(int)($messageQueue['pending']??0),
        'messages_failed'=>(int)($messageQueue['failed']??0),
        'freeradius_service'=>fs_infrastructure_local_service_state('freeradius.service'),
    ];
}

/** @return list<array<string,mixed>> */
function fs_infrastructure_safe_events(PDO $pdo,int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    $events=[];
    $requests=$pdo->query("SELECT r.operation,r.status,r.error_code,r.attempt_count,r.updated_at,p.name partner_name FROM hotspot_change_requests r JOIN partners p ON p.id=r.partner_id WHERE r.status IN ('failed','retry') ORDER BY r.updated_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($requests as $row)$events[]=['at'=>$row['updated_at'],'domain'=>'Infraestrutura','event'=>$row['operation'],'state'=>$row['status'],'code'=>$row['error_code']?:'OPERATION_RETRY','context'=>$row['partner_name'],'attempts'=>(int)$row['attempt_count']];
    $integrations=$pdo->query("SELECT provider,action,created_at FROM system_integration_audit ORDER BY created_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($integrations as $row)$events[]=['at'=>$row['created_at'],'domain'=>'Integração','event'=>$row['action'],'state'=>'recorded','code'=>'AUDIT_EVENT','context'=>$row['provider'],'attempts'=>null];
    usort($events,static fn(array $left,array $right):int=>strcmp((string)$right['at'],(string)$left['at']));
    return array_slice($events,0,$limit);
}
