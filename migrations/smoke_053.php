<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$displayName=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_nas_ownerships' AND COLUMN_NAME='display_name'")->fetchColumn();
if($displayName!==1)throw new RuntimeException('Nome editável do NAS próprio ausente.');
$sourceType=(string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_nas_assignments' AND COLUMN_NAME='assignment_source'")->fetchColumn();
if(!str_contains($sourceType,"'partner_owned'"))throw new RuntimeException('Atribuição não distingue NAS próprio.');

$plan=$pdo->query("SELECT id,active,max_nas,max_hotspots FROM platform_plans WHERE code='multipoint_advanced' AND version=3 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$plan||(int)$plan['active']!==1||(int)$plan['max_nas']!==10||(int)$plan['max_hotspots']!==25)throw new RuntimeException('Plano Multipontos v3 ou suas cotas estão incorretos.');
$oldActive=(int)$pdo->query("SELECT COUNT(*) FROM platform_plans WHERE code='multipoint_advanced' AND version<3 AND active=1")->fetchColumn();
if($oldActive!==0)throw new RuntimeException('Versão anterior do plano Multipontos permaneceu ativa.');
$features=$pdo->prepare("SELECT feature_code,enabled FROM platform_plan_features WHERE plan_id=? AND feature_code IN ('nas.view','nas.manage','nas.prepare','nas.retire','hotspots.view','hotspots.draft.manage','hotspots.apply')");
$features->execute([(int)$plan['id']]);$map=[];foreach($features->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['feature_code']]=(int)$row['enabled'];
foreach(['nas.view','hotspots.view','hotspots.draft.manage'] as $feature)if(($map[$feature]??0)!==1)throw new RuntimeException('Recurso de leitura/rascunho do plano Multipontos v3 ausente: '.$feature);
$nasMutationStates=array_map(static fn(string $feature):int=>(int)($map[$feature]??-1),['nas.manage','nas.prepare','nas.retire']);
if(count(array_unique($nasMutationStates))!==1||!in_array($nasMutationStates[0],[0,1],true))throw new RuntimeException('Capacidades de NAS ficaram em estado parcial durante a adoção.');
if(($map['hotspots.apply']??1)!==0)throw new RuntimeException('A aplicação remota de pontos foi liberada indevidamente.');

$invalidCurrent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions subscription JOIN platform_plans plan ON plan.id=subscription.plan_id WHERE subscription.is_current=1 AND plan.code='multipoint_advanced' AND (plan.version<>3 OR plan.active<>1)")->fetchColumn();
if($invalidCurrent!==0)throw new RuntimeException('Assinatura Multipontos vigente não recebeu o contrato v3.');

$partner=$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();
if(!$partner)throw new RuntimeException('Estabelecimento example_partner não encontrado para classificação confirmada.');
$partnerId=(int)$partner;
$owned=$pdo->prepare("SELECT n.shortname,o.management_mode,o.status,a.assignment_source
    FROM partner_nas_ownerships o
    JOIN nas n ON n.id=o.nas_id
    JOIN partner_nas_assignments a ON a.partner_id=o.partner_id AND a.nas_id=o.nas_id
    WHERE o.partner_id=? AND o.status<>'retired' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B') ORDER BY n.shortname");
$owned->execute([$partnerId]);$rows=$owned->fetchAll(PDO::FETCH_ASSOC)?:[];
$ownedNames=array_column($rows,'shortname');sort($ownedNames);
if($ownedNames!==['Demo-NAS-A','Demo-NAS-B'])throw new RuntimeException('A adoção de NAS do example_partner não corresponde aos dois equipamentos confirmados.');
foreach($rows as $row){
    if(!in_array($row['management_mode'],['firespot_dedicated','partner_owned'],true))throw new RuntimeException('NAS fora do estado seguro de adoção.');
    if($row['management_mode']==='partner_owned'&&$row['assignment_source']!=='partner_owned')throw new RuntimeException('NAS próprio sem atribuição coerente.');
    if($row['management_mode']==='firespot_dedicated'&&$row['assignment_source']==='partner_owned')throw new RuntimeException('Atribuição própria foi antecipada antes da validação segura.');
}

$central=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships ownership JOIN nas equipment ON equipment.id=ownership.nas_id WHERE ownership.partner_id=? AND equipment.shortname='FireSpot' AND ownership.management_mode='partner_owned'");
$central->execute([$partnerId]);if((int)$central->fetchColumn()!==0)throw new RuntimeException('O NAS FireSpot foi transferido indevidamente ao estabelecimento.');
$bindings=$pdo->prepare("SELECT h.name,n.shortname FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE') ORDER BY h.name");
$bindings->execute([$partnerId]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=(string)$row['shortname'];
if(($map['Principal']??'')!=='Demo-NAS-A'||($map['flutuante']??'')!=='Demo-NAS-B'||($map['TESTE']??'')!=='FireSpot')throw new RuntimeException('A associação original entre pontos e NAS foi alterada.');

$audits=$pdo->prepare("SELECT COUNT(*) FROM partner_admin_audit WHERE partner_id=? AND action IN ('nas.ownership_staged','nas.ownership_classified') AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.migration'))='53'");
$audits->execute([$partnerId]);if((int)$audits->fetchColumn()<2)throw new RuntimeException('Adoção dos NAS não possui evidências auditáveis.');

echo "Smoke 053 OK: Multipontos v3 e adoção segura dos dois NAS do example_partner; FireSpot/TESTE preservados.\n";
