<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$pdo=db();$keys=['subscriber_access_enabled','subscriber_account_enabled','subscriber_invites_enabled','subscriber_radius_enabled','subscriber_authenticated_purchase_enabled'];
$in=implode(',',array_fill(0,count($keys),'?'));$st=$pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE skey IN ($in)");$st->execute($keys);if((int)$st->fetchColumn()!==count($keys))throw new RuntimeException('Gates de rollout incompletos.');echo "Smoke 036 OK: gates instalados.\n";
