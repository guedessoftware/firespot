<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$table=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspot_configuration_requests'")->fetchColumn();
if($table!==1)throw new RuntimeException('Tabela de solicitações de configuração dos pontos ausente.');
$columns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspot_configuration_requests' AND COLUMN_NAME IN ('partner_id','hotspot_id','requested_name','requested_nas_id','requested_nas_interface_id','reason','revision','state','requested_by_user_id','active_hotspot_id')")->fetchColumn();
if($columns!==10)throw new RuntimeException('Schema da solicitação de configuração incompleto.');
$indexes=(int)$pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspot_configuration_requests' AND INDEX_NAME IN ('uq_partner_hotspot_configuration_revision','uq_partner_hotspot_configuration_active','idx_partner_hotspot_configuration_partner','idx_partner_hotspot_configuration_target_nas')")->fetchColumn();
if($indexes!==4)throw new RuntimeException('Unicidade ou índices da solicitação de configuração ausentes.');

$invalidScope=(int)$pdo->query("SELECT COUNT(*)
    FROM partner_hotspot_configuration_requests request
    JOIN partner_hotspots point ON point.id=request.hotspot_id
    LEFT JOIN partner_nas_ownerships current_owner ON current_owner.partner_id=request.partner_id AND current_owner.nas_id=point.nas_id AND current_owner.management_mode='partner_owned' AND current_owner.status<>'retired'
    LEFT JOIN partner_nas_ownerships target_owner ON target_owner.partner_id=request.partner_id AND target_owner.nas_id=request.requested_nas_id AND target_owner.management_mode='partner_owned' AND target_owner.status<>'retired'
    LEFT JOIN nas_interfaces interface ON interface.id=request.requested_nas_interface_id AND interface.nas_id=request.requested_nas_id
    WHERE request.partner_id<>point.partner_id OR current_owner.nas_id IS NULL OR target_owner.nas_id IS NULL OR interface.id IS NULL")->fetchColumn();
if($invalidScope!==0)throw new RuntimeException('Solicitação de configuração cruza estabelecimento, NAS próprio ou interface.');
$duplicates=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT hotspot_id FROM partner_hotspot_configuration_requests WHERE state='submitted' GROUP BY hotspot_id HAVING COUNT(*)>1) duplicate_requests")->fetchColumn();
if($duplicates!==0)throw new RuntimeException('Um ponto possui mais de uma solicitação ativa.');

$partner=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();
$bindings=$pdo->prepare("SELECT h.name,n.shortname FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE')");
$bindings->execute([$partner]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=(string)$row['shortname'];
if(($map['Principal']??'')!=='Demo-NAS-A'||($map['flutuante']??'')!=='Demo-NAS-B'||($map['TESTE']??'')!=='FireSpot')throw new RuntimeException('A migração de solicitações alterou os pontos efetivos.');

echo "Smoke 054 OK: configuração versionada sem mutação dos pontos ou do RouterOS.\n";
