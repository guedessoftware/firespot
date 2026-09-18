<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/admin_auth.php';
require_once __DIR__.'/../../app/db.php';
require_once __DIR__.'/../../app/monetization_export.php';

admin_require_page();
admin_require_capability('monetization.view');
if(!csrf_check((string)($_GET['csrf']??''))){http_response_code(403);exit('Sessão expirada.');}

try{
    $partnerId=max(0,(int)($_GET['partner_id']??0))?:null;
    $rows=fs_monetization_export_rows(db(),$partnerId,(string)($_GET['from']??''),(string)($_GET['to']??''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="firespot-monetizacao-'.date('Ymd-His').'.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    fs_monetization_write_csv($rows);
}catch(Throwable $e){error_log('[monetization export] '.get_class($e));http_response_code(400);echo 'Não foi possível gerar o relatório.';}
