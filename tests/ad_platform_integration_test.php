<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/ad_platform.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if(!fs_ad_platform_schema_ready($pdo)){echo "SKIP: integração da rede publicitária aguarda a migração 057.\n";exit(0);}

if(session_status()!==PHP_SESSION_ACTIVE){session_id('fsad'.bin2hex(random_bytes(12)));session_start();}
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$throws=static function(callable $callback,string $message)use($expect):void{try{$callback();$thrown=false;}catch(Throwable $error){$thrown=true;}$expect($thrown,$message);};

$candidate=$pdo->query("SELECT p.*,h.id hotspot_id,h.code hotspot_code,h.name hotspot_name FROM partners p JOIN partner_hotspots h ON h.partner_id=p.id AND h.active=1 JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1 JOIN platform_plans plan ON plan.id=s.plan_id JOIN platform_plan_features f ON f.plan_id=plan.id AND f.feature_code='ad.inventory.manage' AND f.enabled=1 WHERE p.active=1 AND plan.code='multipoint_advanced' ORDER BY p.id,h.is_default DESC,h.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$candidate){echo "SKIP: integração da rede publicitária exige estabelecimento ativo no Plano Máximo com ponto.\n";exit(0);}
$partnerId=(int)$candidate['id'];$hotspotId=(int)$candidate['hotspot_id'];
$partner=$candidate;$partner['hotspot_id']=$hotspotId;$partner['partner_hotspot_id']=$hotspotId;$partner['partner_hotspot_code']=$candidate['hotspot_code'];
$ledgerBaseline=$pdo->prepare("SELECT COUNT(*) FROM monetization_ledger WHERE partner_id=? AND source_type LIKE 'google%'");$ledgerBaseline->execute([$partnerId]);$ledgerGoogleBefore=(int)$ledgerBaseline->fetchColumn();

$pdo->beginTransaction();
try{
    $pdo->exec("UPDATE ad_platform_settings SET provider='mock',enabled=1,test_mode=1,configuration_status='test_ready',network_code=NULL,privacy_mode='non_personalized',revenue_owner='firespot' WHERE id=1");
    $pdo->exec("UPDATE ad_platform_placements SET active=1,google_ad_unit_path=NULL");
    $pdo->prepare("UPDATE partner_ad_policies SET state='enabled',paid_banner_enabled=1,rewarded_access_enabled=1,allow_firespot_direct=0,allow_partner_owned=0,allow_google_backfill=1,rewarded_ad_count=1,reward_minutes=10,max_rewards_per_device=1,reward_window_minutes=1440,reward_cooldown_minutes=0,privacy_mode='inherit',version=version+1 WHERE partner_id=?")->execute([$partnerId]);
    $pdo->prepare("UPDATE hotspot_ad_policies SET mode='inherit',enabled_override=NULL,paid_banner_override=NULL,rewarded_access_override=NULL WHERE hotspot_id=? AND partner_id=?")->execute([$hotspotId,$partnerId]);

    $runtime=fs_ad_platform_runtime($pdo,$partner,'free_rewarded','sponsored','access_choice');
    $expect(!empty($runtime['managed'])&&!empty($runtime['available'])&&$runtime['reason']==='READY','Plano Máximo e ponto elegível não ativaram o runtime simulado.');
    $choice=fs_ad_platform_inventory_choice($runtime,[]);
    $expect($choice&&$choice['provider']==='mock'&&$choice['source']==='google_backfill','Provedor simulado não ocupou o inventário remanescente.');

    $delivery=fs_ad_platform_delivery_begin($pdo,$partner,$choice,['mac'=>'02:10:20:30:40:50','journey'=>'sponsored','reward_minutes'=>10]);
    $expect(strlen($delivery['token'])===64&&!empty($delivery['is_simulation']),'Tentativa simulada não nasceu opaca e isolada.');
    $throws(static fn()=>fs_ad_platform_delivery_event($pdo,$delivery['token'],'accept',$partnerId,$hotspotId+999),'Outro ponto aceitou a tentativa recompensada.');
    $accepted=fs_ad_platform_delivery_event($pdo,$delivery['token'],'accept',$partnerId,$hotspotId);
    $expect($accepted['state']==='accepted','Adesão explícita não alterou o estado.');
    fs_ad_platform_delivery_event($pdo,$delivery['token'],'impression',$partnerId,$hotspotId,'mock-impression');
    $throws(static fn()=>fs_ad_platform_delivery_event($pdo,$delivery['token'],'reward_granted',$partnerId,$hotspotId,'mock-too-fast'),'Evento recompensado imediato foi aceito sem tempo mínimo de exibição.');
    $pdo->prepare('UPDATE ad_platform_deliveries SET accepted_at=DATE_SUB(NOW(),INTERVAL 3 SECOND) WHERE public_id=?')->execute([$delivery['public_id']]);
    $ready=fs_ad_platform_delivery_event($pdo,$delivery['token'],'reward_granted',$partnerId,$hotspotId,'mock-reward');
    $expect($ready['state']==='ready'&&(int)$ready['completed_ad_count']===1,'Evento recompensado não deixou a tentativa pronta.');
    $throws(static fn()=>fs_ad_platform_delivery_consume($pdo,$delivery['token'],$partnerId,$hotspotId),'Modo simulado liberou acesso real.');

    $pdo->prepare('UPDATE ad_platform_deliveries SET is_simulation=0 WHERE public_id=?')->execute([$delivery['public_id']]);
    $granted=fs_ad_platform_delivery_consume($pdo,$delivery['token'],$partnerId,$hotspotId);
    $expect($granted['state']==='granted','Tentativa pronta não foi consumida pelo servidor.');
    $again=fs_ad_platform_delivery_consume($pdo,$delivery['token'],$partnerId,$hotspotId);
    $expect($again['state']==='granted','Repetição idempotente perdeu a tentativa já autorizada.');
    fs_ad_platform_delivery_attach_access($pdo,$delivery['token'],$partnerId,$hotspotId,'fs-ad-integration');
    $stored=fs_ad_platform_delivery_by_token($pdo,$delivery['token']);
    $expect($stored['state']==='consumed'&&$stored['access_username']==='fs-ad-integration','A recompensa não foi vinculada ao acesso final.');
    $throws(static fn()=>fs_ad_platform_delivery_attach_access($pdo,$delivery['token'],$partnerId,$hotspotId,'different-access'),'Token consumido foi associado a outra credencial.');
    $throws(static fn()=>fs_ad_platform_delivery_begin($pdo,$partner,$choice,['mac'=>'02:10:20:30:40:50','journey'=>'sponsored']),'Limite por dispositivo não impediu uma segunda recompensa.');

    $second=fs_ad_platform_delivery_begin($pdo,$partner,$choice,['mac'=>'02:10:20:30:40:51','journey'=>'sponsored']);
    $expect(strlen($second['token'])===64,'Outro dispositivo elegível não recebeu tentativa própria.');

    $bannerRuntime=fs_ad_platform_runtime($pdo,$partner,'welcome_banner','paid','welcome');$bannerChoice=fs_ad_platform_inventory_choice($bannerRuntime,[]);
    $banner=fs_ad_platform_delivery_begin($pdo,$partner,$bannerChoice,['mac'=>'02:10:20:30:40:52','journey'=>'paid']);
    $bannerStored=fs_ad_platform_delivery_event($pdo,$banner['token'],'impression',$partnerId,$hotspotId,'mock-banner');
    $expect($bannerStored['state']==='consumed','Impressão discreta não foi encerrada sem recompensa.');
    $checkoutRuntime=fs_ad_platform_runtime($pdo,$partner,'free_rewarded','paid','checkout');
    $expect(empty($checkoutRuntime['available'])&&$checkoutRuntime['reason']==='PLACEMENT_NOT_ALLOWED','Checkout aceitou inventário recompensado em etapa transacional.');

    $summary=fs_ad_platform_delivery_summary($pdo,$partnerId,30);
    $expect((int)$summary['deliveries']>=3&&(int)$summary['accesses_granted']>=1,'Resumo operacional não reconciliou as entregas do ensaio.');

    $placementId=(int)$runtime['placement']['id'];$dimension=hash('sha256','integration-revenue-'.$partnerId.'-'.$hotspotId);
    $pdo->prepare("INSERT INTO platform_ad_revenue_daily (dimension_key,revenue_date,provider_code,placement_id,partner_id,hotspot_id,impressions,clicks,rewarded_completed,estimated_cents,finalized_cents,state) VALUES (?,CURRENT_DATE,'mock',?,?,?,?,2,1,125,120,'finalized')")->execute([$dimension,$placementId,$partnerId,$hotspotId,10]);
    $revenue=fs_ad_platform_revenue_summary($pdo,$partnerId,30);
    $expect((int)$revenue['impressions']>=10&&(int)$revenue['estimated_cents']>=125&&(int)$revenue['finalized_cents']>=120,'Receita agregada da FireSpot não reconciliou por estabelecimento.');
    $ledgerLeak=$pdo->prepare("SELECT COUNT(*) FROM monetization_ledger WHERE partner_id=? AND source_type LIKE 'google%'");$ledgerLeak->execute([$partnerId]);$ledgerGoogleAfter=(int)$ledgerLeak->fetchColumn();
    $expect($ledgerGoogleAfter===$ledgerGoogleBefore,'Receita Google vazou para o ledger do estabelecimento.');

    $other=$pdo->prepare('SELECT id,partner_id FROM partner_hotspots WHERE partner_id<>? ORDER BY id LIMIT 1');$other->execute([$partnerId]);$other=$other->fetch(PDO::FETCH_ASSOC);
    if($other)$throws(static fn()=>fs_ad_platform_save_hotspot_policy($pdo,$partnerId,(int)$other['id'],['mode'=>'inherit'],'system',0),'Política aceitou ponto de outro estabelecimento.');

    $pdo->rollBack();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}

echo "OK: {$checks} verificações transacionais da rede publicitária, revertidas sem RADIUS ou RouterOS.\n";
