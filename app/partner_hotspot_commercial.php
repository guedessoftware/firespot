<?php

declare(strict_types=1);

require_once __DIR__.'/partner_hotspots.php';
require_once __DIR__.'/partner_entitlements.php';

/** @return array<string,mixed> */
function fs_partner_hotspot_commercial_defaults(array $partner,array $portalConfig): array
{
    $paid=(int)($portalConfig['paid_access_enabled']??0)===1;
    $courtesy=(string)($portalConfig['courtesy_mode']??'disabled');
    if(!in_array($courtesy,['disabled','direct','sponsored'],true))$courtesy='disabled';
    return [
        'paid_access_enabled'=>$paid?1:0,'courtesy_mode'=>$courtesy,'payment_window_enabled'=>$paid?1:0,
        'payment_window_minutes'=>max(1,min(5,(int)($partner['payment_window_minutes']??2))),
        'payment_window_daily_limit'=>max(1,min(12,(int)($partner['payment_window_daily_limit']??3))),
        'payment_window_cooldown_minutes'=>max(5,min(60,(int)($partner['payment_window_cooldown_minutes']??10))),
        'payment_window_period_minutes'=>max(60,min(10080,(int)($partner['payment_window_period_minutes']??1440))),
        'policy_source'=>'partner_default',
    ];
}

/** @return array<string,mixed> */
function fs_partner_hotspot_commercial_validate(array $input): array
{
    $paid=!empty($input['paid_access_enabled'])?1:0;
    $courtesy=strtolower(trim((string)($input['courtesy_mode']??'disabled')));
    if(!in_array($courtesy,['disabled','direct','sponsored'],true))throw new InvalidArgumentException('Selecione uma modalidade de cortesia válida.');
    $window=!empty($input['payment_window_enabled'])?1:0;
    if(!$paid&&$window)throw new InvalidArgumentException('A janela Pix só pode ser ativada quando este ponto oferece acesso pago.');
    $minutes=(int)($input['payment_window_minutes']??0);$limit=(int)($input['payment_window_daily_limit']??0);
    $cooldown=(int)($input['payment_window_cooldown_minutes']??0);$periodHours=(int)($input['payment_window_period_hours']??0);
    if($minutes<1||$minutes>5)throw new InvalidArgumentException('A duração da janela Pix deve ficar entre 1 e 5 minutos.');
    if($limit<1||$limit>12)throw new InvalidArgumentException('O limite deve ficar entre 1 e 12 tentativas por período.');
    if($cooldown<5||$cooldown>60)throw new InvalidArgumentException('O intervalo deve ficar entre 5 e 60 minutos.');
    if($periodHours<1||$periodHours>168)throw new InvalidArgumentException('A renovação deve ficar entre 1 e 168 horas.');
    return ['paid_access_enabled'=>$paid,'courtesy_mode'=>$courtesy,'payment_window_enabled'=>$window,'payment_window_minutes'=>$minutes,'payment_window_daily_limit'=>$limit,'payment_window_cooldown_minutes'=>$cooldown,'payment_window_period_minutes'=>$periodHours*60];
}

/** @return array<string,mixed> */
function fs_partner_hotspot_commercial_policy(PDO $pdo,int $partnerId,int $hotspotId,array $partner,array $portalConfig,bool $forUpdate=false): array
{
    $defaults=fs_partner_hotspot_commercial_defaults($partner,$portalConfig);
    if(!fs_partner_hotspot_commercial_schema_ready($pdo))return $defaults;
    $sql='SELECT policy.* FROM partner_hotspot_commercial_policies policy JOIN partner_hotspots point ON point.id=policy.hotspot_id AND point.partner_id=policy.partner_id WHERE policy.partner_id=? AND policy.hotspot_id=? LIMIT 1';
    if($forUpdate&&$pdo->inTransaction())$sql.=' FOR UPDATE';
    $statement=$pdo->prepare($sql);$statement->execute([$partnerId,$hotspotId]);$policy=$statement->fetch(PDO::FETCH_ASSOC);
    return $policy?array_replace($defaults,$policy,['policy_source'=>'hotspot']):$defaults;
}

/** @return array<int,array<string,mixed>> */
function fs_partner_hotspot_commercial_policies(PDO $pdo,int $partnerId,array $partner,array $portalConfig): array
{
    $result=[];foreach(fs_partner_hotspots_for_partner($pdo,$partnerId,false) as $point)$result[(int)$point['id']]=fs_partner_hotspot_commercial_policy($pdo,$partnerId,(int)$point['id'],$partner,$portalConfig);return$result;
}

/** @return array<string,mixed> */
function fs_partner_hotspot_commercial_save(PDO $pdo,int $partnerId,int $hotspotId,array $input,string $actorType,?int $actorId): array
{
    if(!fs_partner_hotspot_commercial_schema_ready($pdo))throw new RuntimeException('A migração 056 ainda não foi aplicada.');
    if(!in_array($actorType,['firespot','partner_admin','system'],true))throw new InvalidArgumentException('Autor inválido para a política do ponto.');
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',true);
    fs_partner_require_entitlement($pdo,$partnerId,'wallet.manage',true);
    $point=$pdo->prepare("SELECT point.id FROM partner_hotspots point JOIN partner_nas_ownerships ownership ON ownership.nas_id=point.nas_id AND ownership.partner_id=point.partner_id AND ownership.management_mode='partner_owned' AND ownership.status<>'retired' WHERE point.id=? AND point.partner_id=? AND point.active=1 LIMIT 1".($pdo->inTransaction()?' FOR UPDATE':''));
    $point->execute([$hotspotId,$partnerId]);if(!$point->fetchColumn())throw new RuntimeException('Somente pontos ativos em NAS do estabelecimento podem ter sua política alterada por este painel.');
    $policy=fs_partner_hotspot_commercial_validate($input);
    $pdo->prepare("INSERT INTO partner_hotspot_commercial_policies (hotspot_id,partner_id,paid_access_enabled,courtesy_mode,payment_window_enabled,payment_window_minutes,payment_window_daily_limit,payment_window_cooldown_minutes,payment_window_period_minutes,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE partner_id=VALUES(partner_id),paid_access_enabled=VALUES(paid_access_enabled),courtesy_mode=VALUES(courtesy_mode),payment_window_enabled=VALUES(payment_window_enabled),payment_window_minutes=VALUES(payment_window_minutes),payment_window_daily_limit=VALUES(payment_window_daily_limit),payment_window_cooldown_minutes=VALUES(payment_window_cooldown_minutes),payment_window_period_minutes=VALUES(payment_window_period_minutes),updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id),updated_at=NOW()")
        ->execute([$hotspotId,$partnerId,$policy['paid_access_enabled'],$policy['courtesy_mode'],$policy['payment_window_enabled'],$policy['payment_window_minutes'],$policy['payment_window_daily_limit'],$policy['payment_window_cooldown_minutes'],$policy['payment_window_period_minutes'],$actorType,$actorId&&$actorId>0?$actorId:null]);
    return array_replace($policy,['hotspot_id'=>$hotspotId,'partner_id'=>$partnerId,'policy_source'=>'hotspot']);
}

/** Aplica ao contexto resolvido os parâmetros operacionais da janela Pix. */
function fs_partner_hotspot_commercial_apply_context(array $context): array
{
    if((int)($context['hotspot_commercial_loaded']??0)!==1)return$context;
    foreach(['payment_window_enabled','payment_window_minutes','payment_window_daily_limit','payment_window_cooldown_minutes','payment_window_period_minutes'] as $field){$key='hotspot_'.$field;if(array_key_exists($key,$context))$context[$field]=$context[$key];}
    return$context;
}

/** Aplica somente modalidades comerciais; identidade e apresentação continuam no Portal V3 único. */
function fs_partner_hotspot_commercial_apply_portal_config(array $config,array $context): array
{
    if((int)($context['hotspot_commercial_loaded']??0)!==1)return$config;
    $config['paid_access_enabled']=(int)($context['hotspot_paid_access_enabled']??0);
    $mode=(string)($context['hotspot_courtesy_mode']??'disabled');
    $config['courtesy_mode']=in_array($mode,['disabled','direct','sponsored'],true)?$mode:'disabled';
    return$config;
}
