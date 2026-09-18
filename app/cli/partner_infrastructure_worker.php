#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../partner_infrastructure_worker.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$processed=0;$failed=0;
for($i=0;$i<5;$i++){
    try{$result=fs_partner_infrastructure_process_one($pdo);if($result===null)break;$processed++;}
    catch(Throwable $error){$failed++;error_log('[partner_infrastructure_worker] code='.fs_partner_infrastructure_safe_error_code($error).' class='.get_class($error));}
}
echo 'processed='.$processed.' safely_recorded_failures='.$failed.PHP_EOL;
exit(0);
