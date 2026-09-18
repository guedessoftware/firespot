<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/personal_data_crypto.php';
require_once __DIR__ . '/partner_entitlements.php';
require_once __DIR__ . '/partner_hotspots.php';

/**
 * Contrato de domínio da rede publicitária FireSpot.
 *
 * Este arquivo não carrega tags nem acessa banco ou RADIUS. Ele concentra as
 * decisões que precisam continuar verdadeiras mesmo quando novos provedores
 * forem adicionados.
 */

function fs_ad_platform_sources(): array
{
    return ['firespot_direct','partner_owned','google_backfill'];
}

function fs_ad_platform_source_label(string $source): string
{
    return [
        'firespot_direct'=>'Campanha direta FireSpot',
        'partner_owned'=>'Campanha própria do estabelecimento',
        'google_backfill'=>'Google Ad Manager',
    ][$source] ?? 'Origem desconhecida';
}

function fs_ad_platform_provider_label(string $provider): string
{
    return ['off'=>'Desligado','mock'=>'Provedor simulado','google_ad_manager'=>'Google Ad Manager'][$provider]??'Desconhecido';
}

function fs_ad_platform_placements(): array
{
    return ['welcome_banner','plans_banner','free_rewarded'];
}

function fs_ad_platform_transactional_stages(): array
{
    return ['checkout','payment_pending','payment_confirmed','access_release'];
}

function fs_ad_platform_placement_allowed(string $placement, string $journey, string $stage): bool
{
    if (!in_array($placement,fs_ad_platform_placements(),true)) return false;
    if (!in_array($journey,['paid','free','sponsored','hybrid'],true)) return false;
    if (in_array($stage,fs_ad_platform_transactional_stages(),true)) return false;
    if ($placement === 'free_rewarded') {
        return in_array($journey,['free','sponsored','hybrid'],true) && $stage === 'access_choice';
    }
    return in_array($stage,['welcome','plan_selection'],true);
}

function fs_ad_platform_provider_config(array $input): array
{
    $provider = strtolower(trim((string)($input['provider'] ?? 'off')));
    if (!in_array($provider,['off','mock','google_ad_manager'],true)) {
        throw new InvalidArgumentException('Provedor de publicidade inválido.');
    }
    $networkCode = trim((string)($input['network_code'] ?? ''));
    $testMode = !empty($input['test_mode']);
    if ($provider === 'google_ad_manager' && !preg_match('/^[0-9]{2,20}$/',$networkCode)) {
        throw new InvalidArgumentException('Informe um network code válido do Google Ad Manager.');
    }
    return [
        'provider'=>$provider,
        'network_code'=>$networkCode,
        'test_mode'=>$provider === 'mock' ? true : $testMode,
        'enabled'=>$provider !== 'off',
    ];
}

function fs_ad_platform_ad_unit_path(string $path, string $networkCode): string
{
    $path = trim($path);
    if ($path === '') throw new InvalidArgumentException('Informe a unidade de publicidade.');
    if (!preg_match('~^/[0-9]{2,20}/[A-Za-z0-9._/-]{1,180}$~',$path)) {
        throw new InvalidArgumentException('Unidade de publicidade inválida.');
    }
    if (!str_starts_with($path,'/'.$networkCode.'/')) {
        throw new InvalidArgumentException('A unidade não pertence à rede Google configurada.');
    }
    return $path;
}

function fs_ad_platform_reward_policy(array $input): array
{
    $ads = (int)($input['ad_count'] ?? 1);
    $minutes = (int)($input['reward_minutes'] ?? 0);
    if ($ads < 1 || $ads > 2) throw new InvalidArgumentException('A recompensa deve exigir um ou dois anúncios.');
    if ($minutes < 1 || $minutes > 120) throw new InvalidArgumentException('A recompensa deve conceder entre 1 e 120 minutos.');
    return ['ad_count'=>$ads,'reward_minutes'=>$minutes];
}

function fs_ad_platform_reward_disclosure(array $policy): string
{
    $policy = fs_ad_platform_reward_policy($policy);
    $ads = $policy['ad_count'];
    $minutes = $policy['reward_minutes'];
    return sprintf(
        'Assista a %d %s e receba %d %s de internet gratuita.',
        $ads,
        $ads === 1 ? 'anúncio' : 'anúncios',
        $minutes,
        $minutes === 1 ? 'minuto' : 'minutos'
    );
}

function fs_ad_platform_schema_ready(PDO $pdo): bool
{
    try {
        $required=['ad_platform_settings','ad_platform_placements','partner_ad_policies','hotspot_ad_policies','ad_platform_deliveries','platform_ad_revenue_daily'];
        $marks=implode(',',array_fill(0,count($required),'?'));
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)");
        $st->execute($required);
        return (int)$st->fetchColumn()===count($required);
    } catch (Throwable $error) {
        return false;
    }
}

function fs_ad_platform_safe_settings(): array
{
    return [
        'id'=>1,'provider'=>'off','enabled'=>0,'test_mode'=>1,
        'configuration_status'=>'incomplete','network_code'=>null,
        'privacy_mode'=>'non_personalized','revenue_owner'=>'firespot',
    ];
}

function fs_ad_platform_settings(PDO $pdo): array
{
    if(!fs_ad_platform_schema_ready($pdo))return fs_ad_platform_safe_settings();
    $row=$pdo->query('SELECT * FROM ad_platform_settings WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row?:fs_ad_platform_safe_settings();
}

function fs_ad_platform_placement_rows(PDO $pdo): array
{
    if(!fs_ad_platform_schema_ready($pdo))return [];
    return $pdo->query('SELECT * FROM ad_platform_placements ORDER BY priority,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_ad_platform_save_settings(PDO $pdo,array $input,int $adminId): array
{
    if(!fs_ad_platform_schema_ready($pdo))throw new RuntimeException('A migração da rede publicitária ainda não foi aplicada.');
    $config=fs_ad_platform_provider_config($input);
    $privacy=(string)($input['privacy_mode']??'non_personalized');
    if(!in_array($privacy,['non_personalized','consent_based'],true))throw new InvalidArgumentException('Tratamento de privacidade inválido.');
    $enabled=!empty($input['enabled'])&&$config['provider']!=='off';
    $paths=[];
    foreach(fs_ad_platform_placements() as $code){
        $path=trim((string)($input['placement_'.$code]??''));
        if($path!==''&&$config['provider']==='google_ad_manager')$path=fs_ad_platform_ad_unit_path($path,$config['network_code']);
        if(strlen($path)>220)throw new InvalidArgumentException('Unidade de publicidade muito longa.');
        $paths[$code]=[
            'path'=>$path?:null,
            'active'=>!empty($input['active_'.$code]),
        ];
    }
    if($enabled&&!array_filter($paths,static fn(array $item):bool=>$item['active']))throw new InvalidArgumentException('Ative ao menos um posicionamento.');
    if($enabled&&$config['provider']==='google_ad_manager'){
        foreach($paths as $code=>$item)if($item['active']&&empty($item['path']))throw new InvalidArgumentException('Informe a unidade Google para '.fs_ad_platform_placement_label($code).'.');
    }
    $status='incomplete';
    if($config['provider']==='mock')$status='test_ready';
    if($config['provider']==='google_ad_manager'){
        $status=$config['test_mode']?'test_ready':'production_ready';
        if(!$config['test_mode']&&trim((string)($input['production_confirmation']??''))!=='ATIVAR GOOGLE'){
            throw new InvalidArgumentException('Digite ATIVAR GOOGLE para sair do modo de teste.');
        }
        if(!$config['test_mode']&&(string)env('GOOGLE_AD_MANAGER_PRODUCTION_APPROVED','0')!=='1')throw new RuntimeException('A aprovação comercial e de política do Google ainda não foi confirmada no ambiente.');
        if(!$config['test_mode']&&(string)env('GOOGLE_AD_WALLED_GARDEN_VALIDATED','0')!=='1')throw new RuntimeException('A conectividade dos domínios Google ainda não foi validada no captive portal.');
        if(!$config['test_mode']&&$privacy==='consent_based'&&(string)env('GOOGLE_CMP_READY','0')!=='1')throw new RuntimeException('O modo baseado em consentimento exige uma CMP aprovada antes da produção.');
    }
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('UPDATE ad_platform_settings SET provider=?,enabled=?,test_mode=?,configuration_status=?,network_code=?,privacy_mode=?,revenue_owner=\'firespot\',updated_by_admin_id=?,updated_at=NOW() WHERE id=1');
        $st->execute([$config['provider'],$enabled?1:0,$config['test_mode']?1:0,$status,$config['network_code']?:null,$privacy,$adminId?:null]);
        $up=$pdo->prepare('UPDATE ad_platform_placements SET active=?,google_ad_unit_path=?,updated_at=NOW() WHERE code=?');
        foreach($paths as $code=>$item)$up->execute([$item['active']?1:0,$item['path'],$code]);
        $audit=$pdo->prepare("INSERT INTO system_integration_audit (provider,actor_id,action,metadata) VALUES ('google_ad_manager',?,'ad_platform.settings_updated',?)");
        $audit->execute([$adminId?:null,json_encode([
            'provider'=>$config['provider'],'enabled'=>$enabled,'test_mode'=>$config['test_mode'],
            'configuration_status'=>$status,'privacy_mode'=>$privacy,
            'active_placements'=>array_keys(array_filter($paths,static fn(array $item):bool=>$item['active'])),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    return fs_ad_platform_settings($pdo);
}

function fs_ad_platform_placement_label(string $code): string
{
    return [
        'welcome_banner'=>'o banner de boas-vindas',
        'plans_banner'=>'o banner de planos',
        'free_rewarded'=>'o acesso recompensado',
    ][$code]??'o posicionamento';
}

function fs_ad_platform_partner_policy_defaults(int $partnerId): array
{
    return [
        'partner_id'=>$partnerId,'state'=>'disabled','revenue_mode'=>'firespot_managed',
        'paid_banner_enabled'=>0,'rewarded_access_enabled'=>0,
        'allow_firespot_direct'=>1,'allow_partner_owned'=>1,'allow_google_backfill'=>1,
        'rewarded_ad_count'=>1,'reward_minutes'=>10,'max_rewards_per_device'=>1,
        'reward_window_minutes'=>1440,'reward_cooldown_minutes'=>1440,
        'privacy_mode'=>'inherit','version'=>1,
    ];
}

function fs_ad_platform_partner_policy(PDO $pdo,int $partnerId,?int $hotspotId=null): array
{
    if($partnerId<=0)return fs_ad_platform_partner_policy_defaults(0);
    $policy=fs_ad_platform_partner_policy_defaults($partnerId);
    if(!fs_ad_platform_schema_ready($pdo))return $policy;
    $st=$pdo->prepare('SELECT * FROM partner_ad_policies WHERE partner_id=? LIMIT 1');
    $st->execute([$partnerId]);
    $stored=$st->fetch(PDO::FETCH_ASSOC);
    if($stored)$policy=array_merge($policy,$stored);
    $policy['hotspot_id']=$hotspotId;
    $policy['inherited']=true;
    if(($hotspotId??0)>0){
        $st=$pdo->prepare('SELECT * FROM hotspot_ad_policies WHERE hotspot_id=? AND partner_id=? LIMIT 1');
        $st->execute([$hotspotId,$partnerId]);
        $override=$st->fetch(PDO::FETCH_ASSOC);
        if($override&&$override['mode']==='custom'){
            $map=[
                'enabled_override'=>'state','paid_banner_override'=>'paid_banner_enabled','rewarded_access_override'=>'rewarded_access_enabled',
                'allow_firespot_direct_override'=>'allow_firespot_direct','allow_partner_owned_override'=>'allow_partner_owned','allow_google_backfill_override'=>'allow_google_backfill',
                'rewarded_ad_count_override'=>'rewarded_ad_count','reward_minutes_override'=>'reward_minutes','max_rewards_per_device_override'=>'max_rewards_per_device',
                'reward_window_minutes_override'=>'reward_window_minutes','reward_cooldown_minutes_override'=>'reward_cooldown_minutes',
            ];
            foreach($map as $source=>$target){
                if($override[$source]===null)continue;
                $policy[$target]=$target==='state'?((int)$override[$source]===1?'enabled':'disabled'):$override[$source];
            }
            $policy['version']=(int)$override['version'];
            $policy['inherited']=false;
        }
    }
    return $policy;
}

function fs_ad_platform_policy_effective_enabled(array $platform,array $policy): bool
{
    return !empty($platform['enabled'])
        && in_array((string)($platform['configuration_status']??''),['test_ready','production_ready'],true)
        && (string)($policy['state']??'disabled')==='enabled';
}

function fs_ad_platform_policy_input(array $input): array
{
    $state=(string)($input['state']??'disabled');
    if(!in_array($state,['disabled','enabled'],true))throw new InvalidArgumentException('Situação da publicidade inválida.');
    $reward=fs_ad_platform_reward_policy([
        'ad_count'=>$input['rewarded_ad_count']??1,
        'reward_minutes'=>$input['reward_minutes']??10,
    ]);
    $cap=(int)($input['max_rewards_per_device']??1);
    $window=(int)($input['reward_window_minutes']??1440);
    $cooldown=(int)($input['reward_cooldown_minutes']??1440);
    if($cap<1||$cap>100)throw new InvalidArgumentException('O limite por dispositivo deve ficar entre 1 e 100.');
    if($window<60||$window>43200)throw new InvalidArgumentException('A janela deve ficar entre 60 minutos e 30 dias.');
    if($cooldown<0||$cooldown>43200)throw new InvalidArgumentException('O intervalo deve ficar entre 0 e 30 dias.');
    $privacy=(string)($input['privacy_mode']??'inherit');
    if(!in_array($privacy,['inherit','non_personalized','consent_based'],true))throw new InvalidArgumentException('Tratamento de privacidade inválido.');
    return [
        'state'=>$state,'revenue_mode'=>'firespot_managed',
        'paid_banner_enabled'=>!empty($input['paid_banner_enabled'])?1:0,
        'rewarded_access_enabled'=>!empty($input['rewarded_access_enabled'])?1:0,
        'allow_firespot_direct'=>!empty($input['allow_firespot_direct'])?1:0,
        'allow_partner_owned'=>!empty($input['allow_partner_owned'])?1:0,
        'allow_google_backfill'=>!empty($input['allow_google_backfill'])?1:0,
        'rewarded_ad_count'=>$reward['ad_count'],'reward_minutes'=>$reward['reward_minutes'],
        'max_rewards_per_device'=>$cap,'reward_window_minutes'=>$window,
        'reward_cooldown_minutes'=>$cooldown,'privacy_mode'=>$privacy,
    ];
}

function fs_ad_platform_save_partner_policy(PDO $pdo,int $partnerId,array $input,string $actorType,int $actorId): array
{
    if(!fs_ad_platform_schema_ready($pdo))throw new RuntimeException('A migração da rede publicitária ainda não foi aplicada.');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $policy=fs_ad_platform_policy_input($input);
    $actorType=in_array($actorType,['firespot','partner_admin','system'],true)?$actorType:'system';
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT active FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$partnerId]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
        $st=$pdo->prepare('INSERT INTO partner_ad_policies (partner_id,state,revenue_mode,paid_banner_enabled,rewarded_access_enabled,allow_firespot_direct,allow_partner_owned,allow_google_backfill,rewarded_ad_count,reward_minutes,max_rewards_per_device,reward_window_minutes,reward_cooldown_minutes,privacy_mode,version,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),revenue_mode=VALUES(revenue_mode),paid_banner_enabled=VALUES(paid_banner_enabled),rewarded_access_enabled=VALUES(rewarded_access_enabled),allow_firespot_direct=VALUES(allow_firespot_direct),allow_partner_owned=VALUES(allow_partner_owned),allow_google_backfill=VALUES(allow_google_backfill),rewarded_ad_count=VALUES(rewarded_ad_count),reward_minutes=VALUES(reward_minutes),max_rewards_per_device=VALUES(max_rewards_per_device),reward_window_minutes=VALUES(reward_window_minutes),reward_cooldown_minutes=VALUES(reward_cooldown_minutes),privacy_mode=VALUES(privacy_mode),version=version+1,updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id),updated_at=NOW()');
        $st->execute([$partnerId,$policy['state'],$policy['revenue_mode'],$policy['paid_banner_enabled'],$policy['rewarded_access_enabled'],$policy['allow_firespot_direct'],$policy['allow_partner_owned'],$policy['allow_google_backfill'],$policy['rewarded_ad_count'],$policy['reward_minutes'],$policy['max_rewards_per_device'],$policy['reward_window_minutes'],$policy['reward_cooldown_minutes'],$policy['privacy_mode'],$actorType,$actorId?:null]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    return fs_ad_platform_partner_policy($pdo,$partnerId);
}

function fs_ad_platform_save_hotspot_policy(PDO $pdo,int $partnerId,int $hotspotId,array $input,string $actorType,int $actorId): array
{
    if(!fs_ad_platform_schema_ready($pdo))throw new RuntimeException('A migração da rede publicitária ainda não foi aplicada.');
    if($partnerId<=0||$hotspotId<=0)throw new InvalidArgumentException('Ponto Hotspot inválido.');
    $mode=(string)($input['mode']??'inherit');
    if(!in_array($mode,['inherit','custom'],true))throw new InvalidArgumentException('Herança do ponto inválida.');
    $actorType=in_array($actorType,['firespot','partner_admin','system'],true)?$actorType:'system';
    $policy=$mode==='custom'?fs_ad_platform_policy_input($input):null;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT active FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE');$st->execute([$hotspotId,$partnerId]);
        if((int)$st->fetchColumn()!==1)throw new RuntimeException('Ponto não encontrado ou inativo.');
        $values=$mode==='custom'?[
            $policy['state']==='enabled'?1:0,$policy['paid_banner_enabled'],$policy['rewarded_access_enabled'],
            $policy['allow_firespot_direct'],$policy['allow_partner_owned'],$policy['allow_google_backfill'],
            $policy['rewarded_ad_count'],$policy['reward_minutes'],$policy['max_rewards_per_device'],
            $policy['reward_window_minutes'],$policy['reward_cooldown_minutes'],
        ]:array_fill(0,11,null);
        $sql='INSERT INTO hotspot_ad_policies (hotspot_id,partner_id,mode,enabled_override,paid_banner_override,rewarded_access_override,allow_firespot_direct_override,allow_partner_owned_override,allow_google_backfill_override,rewarded_ad_count_override,reward_minutes_override,max_rewards_per_device_override,reward_window_minutes_override,reward_cooldown_minutes_override,version,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),enabled_override=VALUES(enabled_override),paid_banner_override=VALUES(paid_banner_override),rewarded_access_override=VALUES(rewarded_access_override),allow_firespot_direct_override=VALUES(allow_firespot_direct_override),allow_partner_owned_override=VALUES(allow_partner_owned_override),allow_google_backfill_override=VALUES(allow_google_backfill_override),rewarded_ad_count_override=VALUES(rewarded_ad_count_override),reward_minutes_override=VALUES(reward_minutes_override),max_rewards_per_device_override=VALUES(max_rewards_per_device_override),reward_window_minutes_override=VALUES(reward_window_minutes_override),reward_cooldown_minutes_override=VALUES(reward_cooldown_minutes_override),version=version+1,updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id),updated_at=NOW()';
        $st=$pdo->prepare($sql);$st->execute(array_merge([$hotspotId,$partnerId,$mode],$values,[$actorType,$actorId?:null]));
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    return fs_ad_platform_partner_policy($pdo,$partnerId,$hotspotId);
}

function fs_ad_platform_secret(): string
{
    $secret=trim((string)env('AD_PLATFORM_PROOF_KEY',env('APP_KEY','')));
    if(strlen($secret)<32)$secret=hash('sha256',fs_personal_data_key());
    return $secret;
}

function fs_ad_platform_session_hash(): string
{
    $session=session_id();
    if($session==='')throw new RuntimeException('Sessão publicitária indisponível.');
    return hash_hmac('sha256',$session,fs_ad_platform_secret());
}

function fs_ad_platform_device_hash(array $context): ?string
{
    $identity=strtoupper(trim((string)($context['mac']??$context['did']??$context['ip']??'')));
    return $identity===''?null:hash_hmac('sha256',$identity,fs_ad_platform_secret());
}

function fs_ad_platform_runtime(PDO $pdo,array $partner,string $placement,string $journey,string $stage): array
{
    $result=['managed'=>false,'available'=>false,'reason'=>'PLATFORM_DISABLED','settings'=>fs_ad_platform_safe_settings(),'placement'=>null,'policy'=>fs_ad_platform_partner_policy_defaults((int)($partner['id']??0)),'sources'=>[],'is_simulation'=>true];
    $partnerId=(int)($partner['id']??0);$hotspotId=(int)(fs_partner_hotspot_id($partner)??0);
    if($partnerId<=0||$hotspotId<=0)return array_merge($result,['reason'=>'CONTEXT_UNRESOLVED']);
    if(!fs_ad_platform_schema_ready($pdo))return array_merge($result,['reason'=>'SCHEMA_PENDING']);
    if(!fs_partner_has_entitlement($pdo,$partnerId,'ad.inventory.manage',false))return array_merge($result,['reason'=>'PLAN_NOT_ELIGIBLE']);
    $result['managed']=true;
    if(!fs_ad_platform_placement_allowed($placement,$journey,$stage))return array_merge($result,['reason'=>'PLACEMENT_NOT_ALLOWED']);
    $settings=fs_ad_platform_settings($pdo);$policy=fs_ad_platform_partner_policy($pdo,$partnerId,$hotspotId);
    $result['settings']=$settings;$result['policy']=$policy;$result['is_simulation']=!empty($settings['test_mode'])||(string)$settings['provider']==='mock';
    if(!fs_ad_platform_policy_effective_enabled($settings,$policy))return array_merge($result,['reason'=>'POLICY_DISABLED']);
    if((string)$settings['provider']==='google_ad_manager'&&empty($settings['test_mode'])){
        if((string)env('GOOGLE_AD_MANAGER_PRODUCTION_APPROVED','0')!=='1')return array_merge($result,['reason'=>'PRODUCTION_NOT_APPROVED']);
        if((string)env('GOOGLE_AD_WALLED_GARDEN_VALIDATED','0')!=='1')return array_merge($result,['reason'=>'CAPTIVE_PORTAL_NOT_VALIDATED']);
        if((string)$settings['privacy_mode']==='consent_based'&&(string)env('GOOGLE_CMP_READY','0')!=='1')return array_merge($result,['reason'=>'CMP_NOT_READY']);
    }
    $st=$pdo->prepare('SELECT * FROM ad_platform_placements WHERE code=? AND active=1 LIMIT 1');$st->execute([$placement]);$placementRow=$st->fetch(PDO::FETCH_ASSOC);
    if(!$placementRow)return array_merge($result,['reason'=>'PLACEMENT_DISABLED']);
    $result['placement']=$placementRow;
    if($placement==='free_rewarded'&&empty($policy['rewarded_access_enabled']))return array_merge($result,['reason'=>'REWARDED_DISABLED']);
    if($placement!=='free_rewarded'&&empty($policy['paid_banner_enabled']))return array_merge($result,['reason'=>'BANNER_DISABLED']);
    foreach(fs_ad_platform_sources() as $source){
        $field=['firespot_direct'=>'allow_firespot_direct','partner_owned'=>'allow_partner_owned','google_backfill'=>'allow_google_backfill'][$source];
        if(!empty($policy[$field]))$result['sources'][]=$source;
    }
    if(!$result['sources'])return array_merge($result,['reason'=>'NO_SOURCE_ALLOWED']);
    $result['available']=true;$result['reason']='READY';
    return $result;
}

function fs_ad_platform_internal_source(array $ad): string
{
    if((int)($ad['campaign_id']??0)>0||empty($ad['partner_id']))return 'firespot_direct';
    return 'partner_owned';
}

/** Seleciona primeiro campanha FireSpot, depois peça própria e por fim Google. */
function fs_ad_platform_inventory_choice(array $runtime,array $eligibleAds=[]): ?array
{
    if(empty($runtime['available']))return null;
    $sources=(array)$runtime['sources'];
    foreach(['firespot_direct','partner_owned'] as $source){
        if(!in_array($source,$sources,true))continue;
        foreach($eligibleAds as $ad){
            if(fs_ad_platform_internal_source($ad)===$source)return ['source'=>$source,'provider'=>'internal','ad'=>$ad,'runtime'=>$runtime];
        }
    }
    if(in_array('google_backfill',$sources,true)){
        $provider=(string)($runtime['settings']['provider']??'off');
        if(in_array($provider,['mock','google_ad_manager'],true)){
            $path=trim((string)($runtime['placement']['google_ad_unit_path']??''));
            if($provider==='mock'||$path!=='')return ['source'=>'google_backfill','provider'=>$provider,'ad'=>null,'runtime'=>$runtime,'ad_unit_path'=>$path];
        }
    }
    return null;
}

function fs_ad_platform_delivery_by_token(PDO $pdo,string $token,bool $forUpdate=false): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/i',$token))return null;
    $sql='SELECT d.*,d.expires_at>NOW() proof_alive,TIMESTAMPDIFF(SECOND,COALESCE(d.last_reward_at,d.accepted_at),NOW()) proof_elapsed_seconds,p.code placement_code,p.format placement_format,p.google_ad_unit_path,s.provider platform_provider,s.enabled platform_enabled,s.test_mode platform_test_mode,s.configuration_status FROM ad_platform_deliveries d JOIN ad_platform_placements p ON p.id=d.placement_id JOIN ad_platform_settings s ON s.id=1 WHERE d.token_hash=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $st=$pdo->prepare($sql);$st->execute([hash('sha256',strtolower($token))]);$row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function fs_ad_platform_delivery_assert(array $delivery,int $partnerId,int $hotspotId): void
{
    if((int)$delivery['partner_id']!==$partnerId||(int)$delivery['hotspot_id']!==$hotspotId)throw new RuntimeException('A exibição pertence a outro ponto Hotspot.');
    if(!hash_equals((string)$delivery['session_hash'],fs_ad_platform_session_hash()))throw new RuntimeException('A exibição pertence a outra sessão.');
    if(empty($delivery['proof_alive']))throw new RuntimeException('A exibição publicitária expirou.');
}

function fs_ad_platform_reward_cap_assert(PDO $pdo,int $partnerId,int $hotspotId,?string $deviceHash,array $policy): void
{
    $identitySql=$deviceHash!==null?'device_hash=?':'session_hash=?';
    $identity=$deviceHash??fs_ad_platform_session_hash();
    $window=max(60,(int)$policy['reward_window_minutes']);$cap=max(1,(int)$policy['max_rewards_per_device']);$cooldown=max(0,(int)$policy['reward_cooldown_minutes']);
    $st=$pdo->prepare("SELECT COUNT(*) total,COALESCE(MAX(COALESCE(granted_at,ready_at))>DATE_SUB(NOW(),INTERVAL ? MINUTE),0) cooldown_active FROM ad_platform_deliveries WHERE partner_id=? AND hotspot_id=? AND {$identitySql} AND state IN ('ready','granted','consumed') AND created_at>=DATE_SUB(NOW(),INTERVAL ? MINUTE)");
    $st->execute([$cooldown,$partnerId,$hotspotId,$identity,$window]);$usage=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if((int)($usage['total']??0)>=$cap)throw new RuntimeException('Limite de acessos patrocinados atingido para esta janela.');
    if($cooldown>0&&!empty($usage['cooldown_active']))throw new RuntimeException('Aguarde o intervalo configurado antes de usar outro acesso patrocinado.');
}

function fs_ad_platform_delivery_begin(PDO $pdo,array $partner,array $choice,array $context=[]): array
{
    if(!fs_ad_platform_schema_ready($pdo))throw new RuntimeException('Rede publicitária indisponível.');
    $runtime=(array)($choice['runtime']??[]);$placement=(array)($runtime['placement']??[]);$policy=(array)($runtime['policy']??[]);
    $partnerId=(int)($partner['id']??0);$hotspotId=(int)(fs_partner_hotspot_id($partner)??0);$source=(string)($choice['source']??'');$provider=(string)($choice['provider']??'');
    if(empty($runtime['available'])||$partnerId<=0||$hotspotId<=0||!in_array($source,(array)($runtime['sources']??[]),true))throw new RuntimeException('Inventário publicitário não elegível.');
    $providerCode=$provider==='internal'?'internal':($provider==='mock'?'mock':'google_ad_manager');
    $isRewarded=(string)($placement['format']??'')==='rewarded';$deviceHash=fs_ad_platform_device_hash($context);
    if($isRewarded)fs_ad_platform_reward_cap_assert($pdo,$partnerId,$hotspotId,$deviceHash,$policy);
    $token=bin2hex(random_bytes(32));$public=bin2hex(random_bytes(16));$required=$isRewarded?max(1,min(2,(int)$policy['rewarded_ad_count'])):1;
    $configuredReward=(int)($context['reward_minutes']??$policy['reward_minutes']??10);$reward=$isRewarded?max(1,min(120,$configuredReward)):0;
    $privacy=(string)($policy['privacy_mode']??'inherit');if($privacy==='inherit')$privacy=(string)($runtime['settings']['privacy_mode']??'non_personalized');
    if($privacy==='consent_based'&&empty($context['personalization_consent']))$privacy='limited';
    if(!in_array($privacy,['non_personalized','consent_based','limited'],true))$privacy='non_personalized';
    $snapshot=['placement'=>(string)$placement['code'],'source'=>$source,'provider'=>$providerCode,'policy_version'=>(int)($policy['version']??1),'required_ad_count'=>$required,'reward_minutes'=>$reward,'revenue_owner'=>'firespot'];
    $st=$pdo->prepare("INSERT INTO ad_platform_deliveries (public_id,token_hash,source_code,provider_code,placement_id,partner_id,hotspot_id,campaign_id,custom_ad_id,journey,state,session_hash,device_hash,policy_version,policy_snapshot,required_ad_count,reward_minutes,privacy_treatment,is_simulation,access_username,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,'offered',?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");
    $ad=(array)($choice['ad']??[]);$st->execute([$public,hash('sha256',$token),$source,$providerCode,(int)$placement['id'],$partnerId,$hotspotId,(int)($ad['campaign_id']??0)?:null,(int)($ad['id']??0)?:null,(string)($context['journey']??'free'),fs_ad_platform_session_hash(),$deviceHash,(int)($policy['version']??1),json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$required,$reward,$privacy,!empty($runtime['is_simulation'])?1:0,substr(trim((string)($context['username']??'')),0,64)?:null]);
    return ['token'=>$token,'public_id'=>$public,'required_ad_count'=>$required,'reward_minutes'=>$reward,'provider'=>$providerCode,'is_simulation'=>!empty($runtime['is_simulation']),'ad_unit_path'=>(string)($choice['ad_unit_path']??''),'privacy_treatment'=>$privacy];
}

function fs_ad_platform_delivery_event(PDO $pdo,string $token,string $event,int $partnerId,int $hotspotId,?string $providerRequestId=null): array
{
    if(!in_array($event,['accept','impression','reward_granted','error'],true))throw new InvalidArgumentException('Evento publicitário inválido.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $delivery=fs_ad_platform_delivery_by_token($pdo,$token,true);if(!$delivery)throw new RuntimeException('Exibição publicitária inválida.');
        fs_ad_platform_delivery_assert($delivery,$partnerId,$hotspotId);
        if($event==='accept'){
            if((string)$delivery['placement_format']!=='rewarded')throw new RuntimeException('Este posicionamento não aceita recompensa.');
            if(!in_array((string)$delivery['state'],['offered','accepted'],true))throw new RuntimeException('Exibição não pode mais ser aceita.');
            $pdo->prepare("UPDATE ad_platform_deliveries SET state='accepted',accepted_at=COALESCE(accepted_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);$delivery['state']='accepted';
        }elseif($event==='impression'){
            if(!in_array((string)$delivery['state'],['offered','accepted'],true))throw new RuntimeException('Impressão duplicada ou encerrada.');
            $next=(string)$delivery['placement_format']==='banner'?'consumed':'accepted';
            $pdo->prepare("UPDATE ad_platform_deliveries SET state=?,accepted_at=COALESCE(accepted_at,NOW()),consumed_at=IF(?='consumed',COALESCE(consumed_at,NOW()),consumed_at),provider_request_id=COALESCE(provider_request_id,?),updated_at=NOW() WHERE id=?")->execute([$next,$next,$providerRequestId,(int)$delivery['id']]);$delivery['state']=$next;
        }elseif($event==='reward_granted'){
            if((string)$delivery['placement_format']!=='rewarded'||(string)$delivery['state']!=='accepted'||empty($delivery['accepted_at']))throw new RuntimeException('A exibição recompensada não foi aceita.');
            if(!isset($delivery['proof_elapsed_seconds'])||(int)$delivery['proof_elapsed_seconds']<2)throw new RuntimeException('A conclusão do anúncio foi informada antes do tempo mínimo de exibição.');
            $completed=min((int)$delivery['required_ad_count'],(int)$delivery['completed_ad_count']+1);$next=$completed>=(int)$delivery['required_ad_count']?'ready':'accepted';
            $pdo->prepare("UPDATE ad_platform_deliveries SET completed_ad_count=?,state=?,last_reward_at=NOW(),ready_at=IF(?='ready',COALESCE(ready_at,NOW()),ready_at),provider_request_id=COALESCE(provider_request_id,?),updated_at=NOW() WHERE id=?")->execute([$completed,$next,$next,$providerRequestId,(int)$delivery['id']]);$delivery['completed_ad_count']=$completed;$delivery['state']=$next;$delivery['last_reward_at']=date('Y-m-d H:i:s');
        }else{
            if(!in_array((string)$delivery['state'],['granted','consumed'],true))$pdo->prepare("UPDATE ad_platform_deliveries SET state='error',provider_request_id=COALESCE(provider_request_id,?),updated_at=NOW() WHERE id=?")->execute([$providerRequestId,(int)$delivery['id']]);$delivery['state']='error';
        }
        if($owns)$pdo->commit();return $delivery;
    }catch(Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_ad_platform_delivery_consume(PDO $pdo,string $token,int $partnerId,int $hotspotId): array
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $delivery=fs_ad_platform_delivery_by_token($pdo,$token,true);if(!$delivery)throw new RuntimeException('Comprovação publicitária inválida.');
        fs_ad_platform_delivery_assert($delivery,$partnerId,$hotspotId);
        if((int)$delivery['is_simulation']===1)throw new RuntimeException('O modo de teste não libera acesso real.');
        if(!in_array((string)$delivery['state'],['ready','granted'],true)||(int)$delivery['completed_ad_count']<(int)$delivery['required_ad_count'])throw new RuntimeException('O anúncio recompensado ainda não foi concluído.');
        $pdo->prepare("UPDATE ad_platform_deliveries SET state='granted',granted_at=COALESCE(granted_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);$delivery['state']='granted';
        if($owns)$pdo->commit();return $delivery;
    }catch(Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_ad_platform_delivery_attach_access(PDO $pdo,string $token,int $partnerId,int $hotspotId,string $username): void
{
    $username=substr(trim($username),0,64);if($username==='')throw new InvalidArgumentException('Acesso concedido inválido.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $delivery=fs_ad_platform_delivery_by_token($pdo,$token,true);if(!$delivery)throw new RuntimeException('Comprovação publicitária inválida.');
        fs_ad_platform_delivery_assert($delivery,$partnerId,$hotspotId);
        if(!in_array((string)$delivery['state'],['granted','consumed'],true))throw new RuntimeException('A exibição ainda não autorizou o acesso.');
        if(!empty($delivery['access_username'])&&!hash_equals((string)$delivery['access_username'],$username))throw new RuntimeException('A exibição já foi vinculada a outro acesso.');
        $pdo->prepare("UPDATE ad_platform_deliveries SET state='consumed',access_username=COALESCE(access_username,?),consumed_at=COALESCE(consumed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$username,(int)$delivery['id']]);
        if($owns)$pdo->commit();
    }catch(Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_ad_platform_delivery_summary(PDO $pdo,?int $partnerId=null,int $days=30): array
{
    $empty=['deliveries'=>0,'rewarded_ready'=>0,'accesses_granted'=>0,'errors'=>0,'simulations'=>0];
    if(!fs_ad_platform_schema_ready($pdo))return $empty;
    $days=max(1,min(366,$days));$where='d.created_at>=DATE_SUB(NOW(),INTERVAL ? DAY)';$params=[$days];
    if(($partnerId??0)>0){$where.=' AND d.partner_id=?';$params[]=$partnerId;}
    $st=$pdo->prepare("SELECT COUNT(*) deliveries,COALESCE(SUM(d.state IN ('ready','granted','consumed') AND p.format='rewarded'),0) rewarded_ready,COALESCE(SUM(d.state IN ('granted','consumed') AND p.format='rewarded'),0) accesses_granted,COALESCE(SUM(d.state IN ('error','rejected','expired')),0) errors,COALESCE(SUM(d.is_simulation=1),0) simulations FROM ad_platform_deliveries d JOIN ad_platform_placements p ON p.id=d.placement_id WHERE {$where}");
    $st->execute($params);return array_merge($empty,$st->fetch(PDO::FETCH_ASSOC)?:[]);
}

function fs_ad_platform_recent_deliveries(PDO $pdo,?int $partnerId=null,int $limit=50): array
{
    if(!fs_ad_platform_schema_ready($pdo))return [];
    $limit=max(1,min(200,$limit));$where='';$params=[];
    if(($partnerId??0)>0){$where='WHERE d.partner_id=?';$params[]=$partnerId;}
    $st=$pdo->prepare("SELECT d.public_id,d.source_code,d.provider_code,d.journey,d.state,d.completed_ad_count,d.required_ad_count,d.reward_minutes,d.is_simulation,d.created_at,d.consumed_at,p.name placement_name,partner.name partner_name,h.name hotspot_name FROM ad_platform_deliveries d JOIN ad_platform_placements p ON p.id=d.placement_id JOIN partners partner ON partner.id=d.partner_id JOIN partner_hotspots h ON h.id=d.hotspot_id {$where} ORDER BY d.id DESC LIMIT {$limit}");
    $st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_ad_platform_revenue_summary(PDO $pdo,?int $partnerId=null,int $days=30): array
{
    $empty=['impressions'=>0,'clicks'=>0,'rewarded_completed'=>0,'estimated_cents'=>0,'finalized_cents'=>0,'invalid_adjustment_cents'=>0];
    if(!fs_ad_platform_schema_ready($pdo))return $empty;
    $days=max(1,min(366,$days));$where='revenue_date>=DATE_SUB(CURRENT_DATE,INTERVAL ? DAY)';$params=[$days];
    if(($partnerId??0)>0){$where.=' AND partner_id=?';$params[]=$partnerId;}
    $st=$pdo->prepare("SELECT COALESCE(SUM(impressions),0) impressions,COALESCE(SUM(clicks),0) clicks,COALESCE(SUM(rewarded_completed),0) rewarded_completed,COALESCE(SUM(estimated_cents),0) estimated_cents,COALESCE(SUM(finalized_cents),0) finalized_cents,COALESCE(SUM(invalid_traffic_adjustment_cents),0) invalid_adjustment_cents FROM platform_ad_revenue_daily WHERE {$where}");
    $st->execute($params);return array_merge($empty,$st->fetch(PDO::FETCH_ASSOC)?:[]);
}
