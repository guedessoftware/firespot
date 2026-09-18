<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$pdo=db();$keys=['subscriber_retention_challenge_days','subscriber_retention_challenge_record_days','subscriber_retention_network_context_days','subscriber_retention_trusted_device_days','subscriber_retention_audit_origin_days','subscriber_retention_inactive_identifier_days'];
$in=implode(',',array_fill(0,count($keys),'?'));$st=$pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE skey IN ($in)");$st->execute($keys);if((int)$st->fetchColumn()!==count($keys))throw new RuntimeException('Política de retenção incompleta.');echo "Smoke 037 OK: política de retenção instalada.\n";
