#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../subscriber_radius.php';
$pdo=db();$expiredInvites=fs_subscriber_device_expire_invites($pdo);$expiredDevices=fs_subscriber_device_expire_devices($pdo);
$pdo->exec("UPDATE subscriber_login_challenges SET status='expired',target_encrypted='PURGED',code_hash=SHA2(CONCAT(code_hash,':expired'),256),updated_at=NOW() WHERE status='pending' AND expires_at<=NOW()");
$pdo->exec("UPDATE subscriber_trusted_devices SET revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW() WHERE expires_at<=NOW() AND revoked_at IS NULL");
$accounts=$pdo->query("SELECT DISTINCT g.account_id FROM subscriber_access_grants g JOIN subscriber_devices d ON d.id=g.device_id WHERE g.status='active' AND d.status<>'active'")->fetchAll(PDO::FETCH_COLUMN)?:[];$closed=0;foreach($accounts as $accountId)$closed+=(int)fs_subscriber_radius_reconcile_account($pdo,(int)$accountId)['ended'];$radiusCleanup=fs_subscriber_radius_cleanup($pdo);
echo json_encode(['expired_invites'=>$expiredInvites,'expired_devices'=>$expiredDevices,'closed_grants'=>$closed,'radius_cleaned'=>(int)$radiusCleanup['cleaned']],JSON_UNESCAPED_SLASHES),PHP_EOL;
