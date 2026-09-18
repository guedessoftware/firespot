<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$required = ['hotspot_server_count','vlan_count','radius_hotspot_count','firespot_radius_count'];
$in = implode(',',array_fill(0,count($required),'?'));
$st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_health' AND COLUMN_NAME IN ({$in})");
$st->execute($required);
$found = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
$missing = array_values(array_diff($required,$found));
if ($missing) { fwrite(STDERR,'Smoke 029 falhou: ' . implode(', ',$missing) . " ausente(s).\n"); exit(1); }
echo "Smoke 029 OK: inventário RouterOS disponível.\n";
