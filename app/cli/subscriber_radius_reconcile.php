#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../db.php';require_once __DIR__.'/../subscriber_radius.php';
$app=db();$accounts=$app->query("SELECT DISTINCT account_id FROM subscriber_access_grants WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN)?:[];$ended=0;$failed=0;foreach($accounts as $accountId){$result=fs_subscriber_radius_reconcile_account($app,(int)$accountId);$ended+=(int)$result['ended'];$failed+=(int)($result['failed']??0);}$cleanup=fs_subscriber_radius_cleanup($app);echo json_encode(['accounts'=>count($accounts),'ended'=>$ended,'login_not_confirmed'=>$failed,'cleaned'=>$cleanup['cleaned']],JSON_UNESCAPED_SLASHES),PHP_EOL;
