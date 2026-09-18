#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../subscriber_provider.php';

$pdo=db();
$limit=100;
foreach(array_slice($argv,1) as $argument)if(preg_match('/^--limit=(\d+)$/',$argument,$match))$limit=max(1,min(500,(int)$match[1]));
if(!fs_subscriber_feature_enabled($pdo,'subscriber_access_enabled',false)){
    echo json_encode(['status'=>'disabled','processed'=>0],JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}

$st=$pdo->prepare("SELECT a.id FROM subscriber_accounts a JOIN subscriber_entitlements e ON e.account_id=a.id JOIN subscriber_external_links l ON l.account_id=a.id AND l.status<>'revoked' WHERE a.status='active' AND l.document_encrypted IS NOT NULL AND ((e.status IN ('active','grace') AND (e.valid_until IS NULL OR e.valid_until<=DATE_ADD(NOW(),INTERVAL 30 MINUTE))) OR (e.status IN ('suspended','error') AND e.updated_at<=DATE_SUB(NOW(),INTERVAL 120 MINUTE))) ORDER BY COALESCE(e.valid_until,e.updated_at),a.id LIMIT {$limit}");
$st->execute();$accountIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
$counts=[];$ok=0;
foreach($accountIds as $accountId){
    try{$result=fs_subscriber_refresh_account($pdo,$accountId);$code=(string)($result['result_code']??(!empty($result['ok'])?'OK':'UNKNOWN'));if(!empty($result['ok']))$ok++;}
    catch(Throwable $e){$code='UNEXPECTED_ERROR';error_log('[subscriber entitlement reconcile] account_refresh_failed');}
    $counts[$code]=($counts[$code]??0)+1;
}
ksort($counts);
echo json_encode(['status'=>'ok','processed'=>count($accountIds),'eligible'=>$ok,'results'=>$counts],JSON_UNESCAPED_SLASHES),PHP_EOL;
