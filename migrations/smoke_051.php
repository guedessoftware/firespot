<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_nas_assignments'")->fetchColumn();
if($exists!==1)throw new RuntimeException('Tabela de atribuições de NAS ausente.');
$orphan=(int)$pdo->query("SELECT COUNT(*) FROM partner_hotspots h LEFT JOIN partner_nas_assignments a ON a.partner_id=h.partner_id AND a.nas_id=h.nas_id WHERE h.nas_id IS NOT NULL AND a.nas_id IS NULL")->fetchColumn();
if($orphan>0)throw new RuntimeException('Ponto existente sem atribuição contextual de NAS.');
$plan=$pdo->query("SELECT id,max_nas,max_hotspots,active FROM platform_plans WHERE code='multipoint_advanced' AND version=2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$successor=(int)$pdo->query("SELECT COUNT(*) FROM platform_plans WHERE code='multipoint_advanced' AND version>2 AND active=1")->fetchColumn();
if(!$plan||((int)$plan['active']!==1&&$successor===0)||(int)$plan['max_nas']!==0||(int)$plan['max_hotspots']<2)throw new RuntimeException('Contrato histórico do plano Multipontos v2 inválido.');
$features=$pdo->prepare("SELECT feature_code,enabled FROM platform_plan_features WHERE plan_id=? AND feature_code IN ('portal.presentation.manage','hotspots.draft.manage','hotspots.apply','nas.manage','nas.prepare','nas.retire')");
$features->execute([(int)$plan['id']]);$map=[];foreach($features->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['feature_code']]=(int)$row['enabled'];
if(($map['portal.presentation.manage']??0)!==1||($map['hotspots.draft.manage']??0)!==1)throw new RuntimeException('Recursos avançados do estabelecimento ausentes.');
foreach(['hotspots.apply','nas.manage','nas.prepare','nas.retire'] as $feature)if(($map[$feature]??1)!==0)throw new RuntimeException('Autogestão técnica indevida: '.$feature);
$column=(string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_presentations' AND COLUMN_NAME='created_by_type'")->fetchColumn();
if(!str_contains($column,"'partner_admin'"))throw new RuntimeException('Autoria visual do estabelecimento ausente.');
echo "Smoke 051 OK: governança histórica, pontos contextuais e plano Multipontos v2 preservados.\n";
