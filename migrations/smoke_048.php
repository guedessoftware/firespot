<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach(['partner_portal_daily_events','partner_daily_metrics','partner_daily_metric_reasons','partner_analytics_runs'] as $table){$statement=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$statement->execute([$table]);if((int)$statement->fetchColumn()!==1)throw new RuntimeException('Tabela analítica ausente: '.$table);}
$pii=['device_mac','device_ip','username','phone','email','account_id'];foreach(['partner_portal_daily_events','partner_daily_metrics','partner_daily_metric_reasons'] as $table){$statement=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$statement->execute([$table]);$columns=$statement->fetchAll(PDO::FETCH_COLUMN)?:[];foreach($pii as $column)if(in_array($column,$columns,true))throw new RuntimeException('PII materializada no rollup: '.$table.'.'.$column);}
$metricColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_daily_metrics'")->fetchAll(PDO::FETCH_COLUMN)?:[];
foreach(['new_visitors','returning_visitors'] as $column)if(!in_array($column,$metricColumns,true))throw new RuntimeException('Métrica de recorrência ausente: '.$column);
foreach(['partner_portal_daily_events','partner_daily_metrics','partner_daily_metric_reasons'] as $table){$cross=(int)$pdo->query("SELECT COUNT(*) FROM {$table} m LEFT JOIN partner_hotspots h ON h.id=m.hotspot_id WHERE h.id IS NULL OR h.partner_id<>m.partner_id")->fetchColumn();if($cross!==0)throw new RuntimeException('Rollup analítico cruza estabelecimentos: '.$table);}
echo "Smoke 048 OK: rollup diário anônimo disponível.\n";
