<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/partner_hotspot_commercial.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if(!fs_partner_hotspot_commercial_schema_ready($pdo))throw new RuntimeException('Política comercial por ponto ausente.');
$missing=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots point LEFT JOIN partner_hotspot_commercial_policies policy ON policy.hotspot_id=point.id WHERE policy.hotspot_id IS NULL')->fetchColumn();
if($missing!==0)throw new RuntimeException('Existe ponto sem política comercial inicial.');
$cross=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspot_commercial_policies policy JOIN partner_hotspots point ON point.id=policy.hotspot_id WHERE point.partner_id<>policy.partner_id')->fetchColumn();
if($cross!==0)throw new RuntimeException('Existe política comercial ligada a outro estabelecimento.');
$invalid=(int)$pdo->query("SELECT COUNT(*) FROM partner_hotspot_commercial_policies WHERE paid_access_enabled NOT IN (0,1) OR payment_window_enabled NOT IN (0,1) OR courtesy_mode NOT IN ('disabled','direct','sponsored') OR payment_window_minutes NOT BETWEEN 1 AND 5 OR payment_window_daily_limit NOT BETWEEN 1 AND 12 OR payment_window_cooldown_minutes NOT BETWEEN 5 AND 60 OR payment_window_period_minutes NOT BETWEEN 60 AND 10080 OR (paid_access_enabled=0 AND payment_window_enabled=1)")->fetchColumn();
if($invalid!==0)throw new RuntimeException('Existe política comercial fora do contrato.');

echo "Smoke 056 OK: cobrança, cortesia e janela Pix individualizadas por ponto sem alterar o RouterOS.\n";
