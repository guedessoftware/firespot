<?php

declare(strict_types=1);

require_once __DIR__.'/_boot.php';
require_once __DIR__.'/../../app/monetization_export.php';

$pdo=host_db();
$context=partner_admin_require_feature_context($pdo,'ads.earnings.view','monetization','monetization.view',false);
if(!csrf_check((string)($_GET['csrf']??''))){http_response_code(403);exit('Sessão expirada.');}

try{
    $rows=fs_monetization_export_rows($pdo,(int)$context['partner_id'],(string)($_GET['from']??''),(string)($_GET['to']??''));
    partner_admin_audit($pdo,(int)$context['partner_id'],'partner_admin',(int)$context['user_id'],'monetization.exported','monetization_ledger',null,['rows'=>count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="firespot-monetizacao-'.date('Ymd-His').'.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    fs_monetization_write_csv($rows);
}catch(Throwable $e){error_log('[partner monetization export] '.get_class($e));http_response_code(400);echo 'Não foi possível gerar o relatório.';}
