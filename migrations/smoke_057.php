<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$required=[
    'ad_platform_settings'=>['provider','enabled','test_mode','configuration_status','privacy_mode','revenue_owner'],
    'ad_platform_placements'=>['code','format','journey_stage','google_ad_unit_path'],
    'partner_ad_policies'=>['partner_id','state','revenue_mode','rewarded_access_enabled','rewarded_ad_count','reward_minutes'],
    'hotspot_ad_policies'=>['hotspot_id','partner_id','mode','rewarded_access_override'],
    'ad_platform_deliveries'=>['public_id','token_hash','source_code','provider_code','placement_id','partner_id','hotspot_id','state','policy_snapshot','last_reward_at'],
    'platform_ad_revenue_daily'=>['dimension_key','revenue_date','provider_code','estimated_cents','finalized_cents','state'],
];
foreach($required as $table=>$columns){
    $marks=implode(',',array_fill(0,count($columns),'?'));
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ($marks)");
    $st->execute(array_merge([$table],$columns));
    if((int)$st->fetchColumn()!==count($columns))throw new RuntimeException('Contrato incompleto em '.$table.'.');
}

$settings=$pdo->query('SELECT * FROM ad_platform_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if(!$settings||$settings['provider']!=='off'||(int)$settings['enabled']!==0||(int)$settings['test_mode']!==1||$settings['revenue_owner']!=='firespot')throw new RuntimeException('Rede publicitária não nasceu em estado seguro.');
$placements=$pdo->query('SELECT code,active FROM ad_platform_placements ORDER BY code')->fetchAll(PDO::FETCH_ASSOC)?:[];
if(array_column($placements,'code')!==['free_rewarded','plans_banner','welcome_banner']||array_sum(array_map('intval',array_column($placements,'active')))!==0)throw new RuntimeException('Posicionamentos iniciais não estão completos e desligados.');
$missingPartners=(int)$pdo->query('SELECT COUNT(*) FROM partners p LEFT JOIN partner_ad_policies policy ON policy.partner_id=p.id WHERE policy.partner_id IS NULL')->fetchColumn();
if($missingPartners!==0)throw new RuntimeException('Existe estabelecimento sem política publicitária inicial.');
$enabledPartners=(int)$pdo->query("SELECT COUNT(*) FROM partner_ad_policies WHERE state<>'disabled' OR paid_banner_enabled<>0 OR rewarded_access_enabled<>0")->fetchColumn();
if($enabledPartners!==0)throw new RuntimeException('A migração ativou publicidade em estabelecimento existente.');
$missingPoints=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots point LEFT JOIN hotspot_ad_policies policy ON policy.hotspot_id=point.id WHERE policy.hotspot_id IS NULL')->fetchColumn();
if($missingPoints!==0)throw new RuntimeException('Existe ponto sem política publicitária inicial.');
$cross=(int)$pdo->query('SELECT COUNT(*) FROM hotspot_ad_policies policy JOIN partner_hotspots point ON point.id=policy.hotspot_id WHERE point.partner_id<>policy.partner_id')->fetchColumn();
if($cross!==0)throw new RuntimeException('Existe política publicitária ligada a outro estabelecimento.');
$custom=(int)$pdo->query("SELECT COUNT(*) FROM hotspot_ad_policies WHERE mode<>'inherit' OR enabled_override IS NOT NULL OR rewarded_access_override IS NOT NULL")->fetchColumn();
if($custom!==0)throw new RuntimeException('A migração criou exceção publicitária por ponto.');
$entitlement=(int)$pdo->query("SELECT COUNT(*) FROM platform_plan_features feature JOIN platform_plans plan ON plan.id=feature.plan_id WHERE plan.code='multipoint_advanced' AND plan.active=1 AND feature.feature_code='ad.inventory.manage' AND feature.enabled=1")->fetchColumn();
if($entitlement!==1)throw new RuntimeException('Plano Máximo não recebeu a gestão de inventário publicitário.');
$deliveries=(int)$pdo->query('SELECT COUNT(*) FROM ad_platform_deliveries')->fetchColumn();
$revenue=(int)$pdo->query('SELECT COUNT(*) FROM platform_ad_revenue_daily')->fetchColumn();
if($deliveries!==0||$revenue!==0)throw new RuntimeException('A migração criou entrega ou receita artificial.');

echo "Smoke 057 OK: rede publicitária central, políticas e recompensas instaladas sem ativar anúncios ou alterar RouterOS.\n";
