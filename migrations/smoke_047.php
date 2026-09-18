<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach(['courtesy_policy_revisions','courtesy_hotspot_policy_overrides'] as $table){$statement=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$statement->execute([$table]);if((int)$statement->fetchColumn()!==1)throw new RuntimeException('Tabela de revisão ausente: '.$table);}
$duplicate=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT partner_id FROM courtesy_policy_revisions WHERE state='published' GROUP BY partner_id HAVING COUNT(*)>1) x")->fetchColumn();
if($duplicate!==0)throw new RuntimeException('Há mais de uma política publicada por estabelecimento.');
$missingPublished=(int)$pdo->query("SELECT COUNT(*) FROM courtesy_partner_policies p LEFT JOIN courtesy_policy_revisions r ON r.partner_id=p.partner_id AND r.state='published' WHERE r.id IS NULL")->fetchColumn();
if($missingPublished!==0)throw new RuntimeException('Há política efetiva sem revisão comercial publicada.');
$divergent=(int)$pdo->query("SELECT COUNT(*) FROM courtesy_partner_policies p JOIN courtesy_policy_revisions r ON r.partner_id=p.partner_id AND r.state='published' WHERE NOT (p.enabled<=>r.enabled AND p.grant_minutes<=>r.grant_minutes AND p.credit_validity_minutes<=>r.credit_validity_minutes AND p.consumption_mode<=>r.consumption_mode AND p.auth_mode<=>r.auth_mode AND p.device_max_grants<=>r.device_max_grants AND p.device_period_minutes<=>r.device_period_minutes AND p.account_max_grants<=>r.account_max_grants AND p.account_period_minutes<=>r.account_period_minutes AND p.cooldown_after_end_minutes<=>r.cooldown_after_end_minutes)")->fetchColumn();
if($divergent!==0)throw new RuntimeException('A revisão publicada diverge da política comercial efetiva.');
$crossPartner=(int)$pdo->query('SELECT COUNT(*) FROM courtesy_hotspot_policy_overrides o JOIN partner_hotspots h ON h.id=o.hotspot_id WHERE h.partner_id<>o.partner_id')->fetchColumn();
if($crossPartner!==0)throw new RuntimeException('Override de cortesia cruza estabelecimentos.');
$mask=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_hotspot_policy_overrides' AND COLUMN_NAME='override_mask'")->fetchColumn();
if($mask!==1)throw new RuntimeException('Máscara explícita de herança da cortesia ausente.');
echo "Smoke 047 OK: revisões e overrides de cortesia consistentes.\n";
