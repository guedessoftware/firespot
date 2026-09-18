#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../partner_entitlements.php';
require_once __DIR__ . '/migration_framework.php';

date_default_timezone_set('America/Manaus');
$json = in_array('--json', $argv ?? [], true);
$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$commandOk = static function (array $command): bool {
    $process = proc_open($command, [
        0=>['file','/dev/null','r'],
        1=>['file','/dev/null','a'],
        2=>['file','/dev/null','a'],
    ], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) return false;
    return proc_close($process) === 0;
};
$serviceActive = static fn(string $unit): bool => $commandOk(['/usr/bin/systemctl','is-active','--quiet',$unit]);
$serviceFailed = static fn(string $unit): bool => $commandOk(['/usr/bin/systemctl','is-failed','--quiet',$unit]);

$settings = [];
$st = $app->query("SELECT skey,svalue FROM app_settings WHERE skey LIKE 'ops_job_%' OR skey LIKE 'ops_backup_%' OR skey LIKE 'hubsoft_capability_%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $settings[(string)$row['skey']] = (string)$row['svalue'];

$jobLimits = [
    'cleanup_guest_access'=>180,
    'courtesy_reconcile'=>180,
    'ad_reconcile'=>180,
    'subscriber_radius_reconcile'=>360,
    'subscriber_cleanup'=>600,
    'subscriber_entitlement_reconcile'=>1800,
    'subscriber_retention'=>129600,
    'privacy_cleanup'=>129600,
    'dashboard_concurrency'=>180,
    'hubsoft_cache'=>28800,
    'partner_infrastructure'=>300,
    'partner_analytics'=>1800,
];
$jobs = [];
foreach ($jobLimits as $name => $maximumAge) {
    $prefix = 'ops_job_' . $name . '_';
    $heartbeat = $settings[$prefix . 'heartbeat_at'] ?? '';
    $heartbeatAt = $heartbeat !== '' ? strtotime($heartbeat) : false;
    $age = $heartbeatAt === false ? null : max(0,time()-$heartbeatAt);
    $timerActive = $serviceActive('firespot-job-' . $name . '.timer');
    $serviceUnit=in_array($name,['partner_infrastructure','partner_analytics'],true)?'firespot-partner-worker@'.$name.'.service':'firespot-job@'.$name.'.service';
    $unitFailed = $serviceFailed($serviceUnit);
    $lastOk = ($settings[$prefix . 'last_ok'] ?? '') === '1';
    $fresh = $age !== null && $age <= $maximumAge;
    $jobs[$name] = [
        'state'=>$timerActive && !$unitFailed && $lastOk && $fresh ? 'ready' : 'pending',
        'timer_active'=>$timerActive,
        'unit_failed'=>$unitFailed,
        'last_ok'=>$lastOk,
        'heartbeat_at'=>$heartbeat !== '' ? $heartbeat : null,
        'age_seconds'=>$age,
        'duration_ms'=>isset($settings[$prefix . 'last_duration_ms']) ? (int)$settings[$prefix . 'last_duration_ms'] : null,
        'runner_state'=>$settings[$prefix . 'state'] ?? null,
    ];
}

$coa = $app->query("SELECT
    SUM(CASE WHEN status='paid' AND payment_access_mode='radius_preauth' AND COALESCE(radius_coa_status,'pending') NOT IN ('applied','manual_review') THEN 1 ELSE 0 END) automatic_queue,
    SUM(CASE WHEN status='paid' AND payment_access_mode='radius_preauth' AND radius_coa_status='manual_review' THEN 1 ELSE 0 END) manual_review,
    MAX(CASE WHEN status='paid' AND payment_access_mode='radius_preauth' AND COALESCE(radius_coa_status,'pending') NOT IN ('applied','manual_review') THEN radius_coa_attempts ELSE 0 END) max_automatic_attempts,
    MIN(CASE WHEN status='paid' AND payment_access_mode='radius_preauth' AND COALESCE(radius_coa_status,'pending') NOT IN ('applied','manual_review') THEN paid_at ELSE NULL END) oldest_automatic
    FROM guest_orders")->fetch(PDO::FETCH_ASSOC) ?: [];
$coaAutomatic = (int)($coa['automatic_queue'] ?? 0);
$coaManual = (int)($coa['manual_review'] ?? 0);

$diskTotal = (float)(disk_total_space('/') ?: 0);
$diskFree = (float)(disk_free_space('/') ?: 0);
$diskUsedPercent = $diskTotal > 0 ? round((1-($diskFree/$diskTotal))*100,1) : 100.0;

$coreServices = [];
foreach (['apache2.service','mariadb.service','freeradius.service','promo-worker.service'] as $service) {
    $coreServices[$service] = $serviceActive($service);
}

$liveCertificateReady = false;
$certificateDays = null;
if (function_exists('stream_socket_client') && function_exists('openssl_x509_parse')) {
    $context = stream_context_create(['ssl'=>[
        'capture_peer_cert'=>true,
        'verify_peer'=>true,
        'verify_peer_name'=>true,
        'peer_name'=>'firecdn.com.br',
        'SNI_enabled'=>true,
    ]]);
    $socket = @stream_socket_client('ssl://127.0.0.1:443',$socketError,$socketMessage,5,STREAM_CLIENT_CONNECT,$context);
    if (is_resource($socket)) {
        $parameters = stream_context_get_params($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate ? openssl_x509_parse($certificate) : false;
        $validTo = is_array($parsed) ? (int)($parsed['validTo_time_t'] ?? 0) : 0;
        $certificateDays = $validTo > 0 ? (int)floor(($validTo-time())/86400) : null;
        $liveCertificateReady = $validTo > time()+604800;
        fclose($socket);
    }
}
$aptCertbotActive = $serviceActive('certbot.timer');
$snapCertbotActive = $serviceActive('snap.certbot.renew.timer');
$certbotFailed = $serviceFailed('certbot.service') || $serviceFailed('snap.certbot.renew.service');
$staleRenewal = is_file('/etc/letsencrypt/renewal/firespot.firenetwork.com.br.conf');
$backupHeartbeat = $settings['ops_backup_last_ok_at'] ?? '';
$backupAt = $backupHeartbeat !== '' ? strtotime($backupHeartbeat) : false;
$backupAge = $backupAt === false ? null : max(0,time()-$backupAt);
$backupTimerActive = $serviceActive('firespot-backup.timer');
$hubsoftCapabilityAt = $settings['hubsoft_capability_last_at'] ?? '';
$hubsoftCapabilityTimestamp = $hubsoftCapabilityAt !== '' ? strtotime($hubsoftCapabilityAt) : false;
$hubsoftCapabilityAge = $hubsoftCapabilityTimestamp === false ? null : max(0,time()-$hubsoftCapabilityTimestamp);
$hubsoftCapabilityOk = ($settings['hubsoft_capability_last_ok'] ?? '') === '1';
$hubsoftCapabilityCode = $settings['hubsoft_capability_last_code'] ?? 'NOT_CHECKED';
$migrationInventory = fs_migration_inventory(dirname(__DIR__,2) . '/migrations');
$migrationLedger = fs_migration_ledger($app);
$migrationErrors = fs_migration_validate_ledger($migrationInventory,$migrationLedger);
$migrationPending = count(array_diff(array_keys($migrationInventory),array_keys($migrationLedger)));
$advancedSchemaReady=false;$infrastructureQueue=['queued'=>0,'failed'=>0];$analyticsLastAt='';$activeHotspotsWithoutNas=0;
$advancedIntegrity=['courtesy'=>0,'analytics_scope'=>0,'infrastructure_scope'=>0,'wallets'=>0,'apply_gate'=>0,'verification'=>0];
try{$activeHotspotsWithoutNas=(int)$app->query('SELECT COUNT(*) FROM partner_hotspots WHERE active=1 AND nas_id IS NULL')->fetchColumn();}catch(Throwable $ignored){}
try{
    $app->query('SELECT id FROM platform_plans LIMIT 0');$app->query('SELECT nas_id FROM partner_nas_ownerships LIMIT 0');$app->query('SELECT id FROM courtesy_policy_revisions LIMIT 0');$app->query('SELECT metric_date FROM partner_daily_metrics LIMIT 0');$app->query('SELECT metric_date FROM partner_portal_daily_events LIMIT 0');$app->query('SELECT access_token_validated_at FROM payment_wallets LIMIT 0');$advancedSchemaReady=true;
    $queue=$app->query("SELECT SUM(status IN ('queued','retry','running')) queued,SUM(status='failed') failed FROM hotspot_change_requests")->fetch(PDO::FETCH_ASSOC)?:[];$infrastructureQueue=['queued'=>(int)($queue['queued']??0),'failed'=>(int)($queue['failed']??0)];
    $analyticsLastAt=(string)($app->query("SELECT MAX(finished_at) FROM partner_analytics_runs WHERE status='succeeded'")->fetchColumn()?:'');
    $advancedIntegrity['courtesy']=(int)$app->query("SELECT COUNT(*) FROM courtesy_partner_policies p LEFT JOIN courtesy_policy_revisions r ON r.partner_id=p.partner_id AND r.state='published' WHERE r.id IS NULL OR NOT (p.enabled<=>r.enabled AND p.grant_minutes<=>r.grant_minutes AND p.credit_validity_minutes<=>r.credit_validity_minutes AND p.consumption_mode<=>r.consumption_mode AND p.auth_mode<=>r.auth_mode AND p.device_max_grants<=>r.device_max_grants AND p.device_period_minutes<=>r.device_period_minutes AND p.account_max_grants<=>r.account_max_grants AND p.account_period_minutes<=>r.account_period_minutes AND p.cooldown_after_end_minutes<=>r.cooldown_after_end_minutes)")->fetchColumn();
    foreach(['partner_portal_daily_events','partner_daily_metrics','partner_daily_metric_reasons'] as $table)$advancedIntegrity['analytics_scope']+=(int)$app->query("SELECT COUNT(*) FROM {$table} m LEFT JOIN partner_hotspots h ON h.id=m.hotspot_id WHERE h.id IS NULL OR h.partner_id<>m.partner_id")->fetchColumn();
    $requestScope="SELECT COUNT(*) FROM hotspot_change_requests r LEFT JOIN partner_nas_ownerships o ON o.nas_id=r.nas_id AND o.partner_id=r.partner_id LEFT JOIN partner_hotspots h ON h.id=r.hotspot_id WHERE o.nas_id IS NULL OR (r.hotspot_id IS NOT NULL AND (h.id IS NULL OR h.partner_id<>r.partner_id OR (r.operation='hotspot_deactivate' AND h.nas_id<>r.nas_id)))";
    $reservationScope="SELECT COUNT(*) FROM partner_network_reservations r LEFT JOIN partner_nas_ownerships o ON o.nas_id=r.nas_id AND o.partner_id=r.partner_id LEFT JOIN partner_hotspots h ON h.id=r.hotspot_id WHERE o.nas_id IS NULL OR (r.hotspot_id IS NOT NULL AND (h.id IS NULL OR h.partner_id<>r.partner_id OR (r.state='applied' AND h.nas_id<>r.nas_id)))";
    $foreignNasUse="SELECT COUNT(*) FROM partner_nas_ownerships o JOIN partner_hotspots h ON h.nas_id=o.nas_id AND h.active=1 WHERE h.partner_id<>o.partner_id";
    $advancedIntegrity['infrastructure_scope']=(int)$app->query("SELECT ({$requestScope}) + ({$reservationScope}) + ({$foreignNasUse})")->fetchColumn();
    $advancedIntegrity['wallets']=(int)$app->query("SELECT (SELECT COUNT(*) FROM payment_wallets WHERE active=1 AND access_token_validated_at IS NULL) + (SELECT COUNT(*) FROM (SELECT partner_id FROM payment_wallets WHERE partner_id IS NOT NULL AND active=1 GROUP BY partner_id HAVING COUNT(*)>1) duplicated) + (SELECT COUNT(*) FROM partners p LEFT JOIN payment_wallets w ON w.id=p.payment_wallet_id AND w.partner_id=p.id AND w.active=1 WHERE p.independent_billing=1 AND (p.payment_wallet_id IS NULL OR w.id IS NULL))")->fetchColumn();
    $applyGate=$app->query("SELECT f.enabled FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=1 AND f.feature_code='hotspots.apply' LIMIT 1")->fetchColumn();
    $advancedIntegrity['apply_gate']=$applyGate===false||(int)$applyGate!==0?1:0;
}catch(Throwable $ignored){if($advancedSchemaReady)$advancedIntegrity['verification']=1;}
$nasCredentialKeyReady=strlen(trim((string)env('NAS_CREDENTIAL_KEY','')))>=32;
$hotspotPilotApproved=fs_partner_hotspot_apply_pilot_approved($app);

$advancedIntegrityIssues=array_sum($advancedIntegrity);
$advancedDependenciesReady=$advancedSchemaReady&&$activeHotspotsWithoutNas===0&&$nasCredentialKeyReady&&$advancedIntegrityIssues===0&&($jobs['partner_infrastructure']['state']??'pending')==='ready'&&($jobs['partner_analytics']['state']??'pending')==='ready';
$advancedPortalState=!$advancedDependenciesReady?'pending':($infrastructureQueue['failed']>0?'warning':'ready');
$sections = [
    'jobs'=>[
        'state'=>count(array_filter($jobs,static fn(array $job): bool => $job['state'] !== 'ready'))===0 ? 'ready' : 'pending',
        'ready'=>count(array_filter($jobs,static fn(array $job): bool => $job['state'] === 'ready')),
        'total'=>count($jobs),
        'items'=>$jobs,
    ],
    'hubsoft'=>[
        'state'=>$hubsoftCapabilityOk && $hubsoftCapabilityAge !== null && $hubsoftCapabilityAge <= 604800 ? 'ready' : 'pending',
        'query_authorized'=>$hubsoftCapabilityOk,
        'last_code'=>$hubsoftCapabilityCode,
        'last_checked_at'=>$hubsoftCapabilityAt !== '' ? $hubsoftCapabilityAt : null,
        'age_seconds'=>$hubsoftCapabilityAge,
    ],
    'migrations'=>[
        'state'=>$migrationLedger && $migrationPending===0 && !$migrationErrors ? 'ready' : 'pending',
        'inventory_count'=>count($migrationInventory),
        'ledger_count'=>count($migrationLedger),
        'pending_count'=>$migrationPending,
        'checksum_errors'=>$migrationErrors,
        'latest_version'=>$migrationLedger ? max(array_keys($migrationLedger)) : null,
    ],
    'advanced_portal'=>[
        'state'=>$advancedPortalState,
        'schema_ready'=>$advancedSchemaReady,
        'nas_credential_key_ready'=>$nasCredentialKeyReady,
        'infrastructure_queue'=>$infrastructureQueue,
        'analytics_last_ok_at'=>$analyticsLastAt!==''?$analyticsLastAt:null,
        'active_hotspots_without_nas'=>$activeHotspotsWithoutNas,
        'hotspot_apply_pilot_approved'=>$hotspotPilotApproved,
        'integrity_issues'=>$advancedIntegrity,
    ],
    'coa'=>[
        'state'=>$coaAutomatic === 0 ? ($coaManual > 0 ? 'warning' : 'ready') : 'pending',
        'automatic_queue'=>$coaAutomatic,
        'manual_review'=>$coaManual,
        'max_automatic_attempts'=>(int)($coa['max_automatic_attempts'] ?? 0),
        'oldest_automatic'=>$coa['oldest_automatic'] ?? null,
    ],
    'capacity'=>[
        'state'=>$diskUsedPercent >= 90 ? 'pending' : ($diskUsedPercent >= 85 ? 'warning' : 'ready'),
        'root_used_percent'=>$diskUsedPercent,
        'root_free_gib'=>round($diskFree/1073741824,2),
    ],
    'services'=>[
        'state'=>in_array(false,$coreServices,true) ? 'pending' : 'ready',
        'items'=>$coreServices,
    ],
    'certificate'=>[
        'state'=>$liveCertificateReady && $snapCertbotActive && !$aptCertbotActive && !$staleRenewal ? 'ready' : 'pending',
        'live_valid'=>$liveCertificateReady,
        'days_remaining'=>$certificateDays,
        'snap_timer_active'=>$snapCertbotActive,
        'apt_timer_active'=>$aptCertbotActive,
        'stale_renewal_present'=>$staleRenewal,
        'failed_unit_active'=>$certbotFailed,
    ],
    'backup'=>[
        'state'=>$backupTimerActive && $backupAge !== null && $backupAge <= 129600 ? 'warning' : 'pending',
        'timer_active'=>$backupTimerActive,
        'last_ok_at'=>$backupHeartbeat !== '' ? $backupHeartbeat : null,
        'age_seconds'=>$backupAge,
        'last_size_bytes'=>isset($settings['ops_backup_last_size_bytes']) ? (int)$settings['ops_backup_last_size_bytes'] : null,
        'external_copy_confirmed'=>false,
    ],
];

if ($json) {
    echo json_encode(['generated_at'=>date(DATE_ATOM),'sections'=>$sections],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    printf("[%s] Jobs operacionais — %d/%d prontos\n",strtoupper($sections['jobs']['state']),$sections['jobs']['ready'],$sections['jobs']['total']);
    foreach ($jobs as $name => $job) {
        printf("  [%s] %s — timer=%s; unit=%s; heartbeat=%s; resultado=%s\n",strtoupper($job['state']),$name,$job['timer_active']?'ativo':'inativo',$job['unit_failed']?'falha':'ok',$job['heartbeat_at']??'ausente',$job['runner_state']??'ausente');
    }
    printf("[%s] HubSoft — consulta_clientes=%s; codigo=%s; verificado=%s\n",strtoupper($sections['hubsoft']['state']),$hubsoftCapabilityOk?'autorizada':'negada/nao validada',$hubsoftCapabilityCode,$hubsoftCapabilityAt!==''?$hubsoftCapabilityAt:'ausente');
    printf("[%s] Migrações — inventario=%d; ledger=%d; pendentes=%d; checksum_errors=%d\n",strtoupper($sections['migrations']['state']),count($migrationInventory),count($migrationLedger),$migrationPending,count($migrationErrors));
    printf("[%s] Portal avançado — schema=%s; chave_nas=%s; pontos_sem_nas=%d; integridade=%d; piloto_aplicacao=%s; fila=%d; falhas=%d; analytics=%s\n",strtoupper($sections['advanced_portal']['state']),$advancedSchemaReady?'ok':'pendente',$nasCredentialKeyReady?'ok':'pendente',$activeHotspotsWithoutNas,$advancedIntegrityIssues,$hotspotPilotApproved?'aprovado':'fechado',$infrastructureQueue['queued'],$infrastructureQueue['failed'],$analyticsLastAt!==''?$analyticsLastAt:'ausente');
    printf("[%s] CoA — fila automatica=%d; revisao manual=%d; max tentativas automaticas=%d\n",strtoupper($sections['coa']['state']),$coaAutomatic,$coaManual,$sections['coa']['max_automatic_attempts']);
    printf("[%s] Capacidade — disco raiz=%.1f%%; livre=%.2f GiB\n",strtoupper($sections['capacity']['state']),$diskUsedPercent,$sections['capacity']['root_free_gib']);
    printf("[%s] Servicos — %s\n",strtoupper($sections['services']['state']),implode('; ',array_map(static fn(string $name,bool $active):string=>$name.'='.($active?'ativo':'inativo'),array_keys($coreServices),array_values($coreServices))));
    printf("[%s] Certificado — TLS=%s; validade=%s dias; snap=%s; apt=%s; renovacao_obsoleta=%s\n",strtoupper($sections['certificate']['state']),$liveCertificateReady?'valido':'falhou',$certificateDays===null?'?':(string)$certificateDays,$snapCertbotActive?'ativo':'inativo',$aptCertbotActive?'ativo':'inativo',$staleRenewal?'sim':'nao');
    printf("[%s] Backup — timer=%s; ultimo=%s; copia_externa=nao confirmada\n",strtoupper($sections['backup']['state']),$backupTimerActive?'ativo':'inativo',$backupHeartbeat!==''?$backupHeartbeat:'ausente');
}

$blocking = count(array_filter($sections,static fn(array $section):bool=>$section['state']==='pending'));
exit($blocking === 0 ? 0 : 2);
