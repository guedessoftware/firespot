<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/partner_entitlements.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

if (!fs_partner_entitlements_schema_ready($pdo)) throw new RuntimeException('Schema de entitlements ausente.');

$planRows=$pdo->query("SELECT code,version,active FROM platform_plans WHERE code IN ('essential','legacy_self_service','multipoint_advanced') ORDER BY code,version")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$plans=[];foreach($planRows as $row)$plans[(string)$row['code']][(int)$row['version']]=(int)$row['active'];
foreach(['essential','legacy_self_service'] as $code)if(($plans[$code][1]??0)!==1)throw new RuntimeException('Plano obrigatório ausente ou inativo: '.$code);
if(!isset($plans['multipoint_advanced'][1]))throw new RuntimeException('Plano histórico Multipontos v1 ausente.');
$multipoint=$plans['multipoint_advanced'];$latestVersion=max(array_keys($multipoint));
if($latestVersion>=2){
    $activeVersions=array_keys(array_filter($multipoint,static fn(int $active):bool=>$active===1));
    if($multipoint[1]!==0||$activeVersions!==[$latestVersion])throw new RuntimeException('Sucessão versionada do plano Multipontos inválida.');
}elseif($multipoint[1]!==1){
    throw new RuntimeException('Plano Multipontos v1 deveria estar ativo antes de sua sucessão.');
}

$requiredAdvanced=[
    'portal.basic','branding.manage','guest_plans.manage','reports.basic',
    'reports.advanced','reports.export','finance.view','nas.view','nas.manage',
    'nas.prepare','nas.retire','hotspots.view','hotspots.draft.manage',
    'wallet.manage','courtesy.view','courtesy.manage',
    'courtesy.hotspot_override.manage','team.manage',
];
$statement=$pdo->prepare("SELECT f.feature_code FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=1 AND f.enabled=1");
$statement->execute();
$advanced=$statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($requiredAdvanced as $feature) {
    if (!in_array($feature,$advanced,true)) throw new RuntimeException('Entitlement avançado ausente: ' . $feature);
}
$applyGate=$pdo->query("SELECT f.enabled FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=1 AND f.feature_code='hotspots.apply' LIMIT 1")->fetchColumn();
if($applyGate===false||(int)$applyGate!==0)throw new RuntimeException('Aplicação remota não nasceu fechada até o piloto.');
$pilotGate=$pdo->query("SELECT svalue FROM app_settings WHERE skey='partner_hotspot_apply_pilot_approved' LIMIT 1")->fetchColumn();
if($pilotGate===false||!in_array((string)$pilotGate,['0','1'],true))throw new RuntimeException('Trava global do piloto físico ausente ou inválida.');
$bundledAddons=(int)$pdo->query("SELECT COUNT(*) FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=1 AND f.enabled=1 AND f.feature_code IN ('ads.manage','monetization.view')")->fetchColumn();
if($bundledAddons!==0)throw new RuntimeException('Publicidade ou monetização foi agregada automaticamente ao plano avançado.');

$withoutCurrent=(int)$pdo->query('SELECT COUNT(*) FROM partners p LEFT JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1 WHERE s.id IS NULL')->fetchColumn();
if ($withoutCurrent!==0) throw new RuntimeException('Há estabelecimento sem assinatura vigente.');
$duplicates=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT partner_id FROM partner_subscriptions WHERE is_current=1 GROUP BY partner_id HAVING COUNT(*)<>1) x')->fetchColumn();
if ($duplicates!==0) throw new RuntimeException('Há mais de uma assinatura vigente por estabelecimento.');

$badLimits=(int)$pdo->query("SELECT COUNT(*) FROM platform_plans WHERE code='multipoint_advanced' AND version=1 AND (max_nas<1 OR max_hotspots<2 OR max_admin_users<1 OR max_report_range_days<90)")->fetchColumn();
if ($badLimits!==0) throw new RuntimeException('Cotas do plano avançado inválidas.');

echo "Smoke 045 OK: catálogo, assinaturas, entitlements e cotas consistentes.\n";
