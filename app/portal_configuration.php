<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/courtesy_rollout.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/payment_wallets.php';
require_once __DIR__ . '/partner_courtesy_management.php';

/**
 * Contrato funcional tipado do Portal V3.
 *
 * Presets apenas preenchem rascunhos. A jornada pública lê exclusivamente a
 * revisão publicada; recursos especializados (planos, carteira, anúncios e
 * política de cortesia) nunca são removidos por este domínio.
 */

function fs_portal_config_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,partner_id,state,subscriber_access_mode,courtesy_mode,paid_access_enabled,welcome_screen_enabled,single_option_direct_enabled FROM partner_portal_configurations LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_portal_config_presets(): array
{
    return [
        'free_quick' => [
            'code' => 'free_quick', 'version' => 1, 'label' => 'Gratuito rápido',
            'description' => 'Cortesia direta sem anúncio obrigatório e sem venda.',
            'subscriber_access_mode' => 'inherit', 'courtesy_mode' => 'direct',
            'paid_access_enabled' => 0, 'promotional_ads_enabled' => 0,
            'allow_global_ads' => 0, 'lead_capture_enabled' => 0,
        ],
        'free_sponsored' => [
            'code' => 'free_sponsored', 'version' => 1, 'label' => 'Gratuito patrocinado',
            'description' => 'Cortesia liberada depois da conclusão de um anúncio elegível.',
            'subscriber_access_mode' => 'inherit', 'courtesy_mode' => 'sponsored',
            'paid_access_enabled' => 0, 'promotional_ads_enabled' => 1,
            'allow_global_ads' => 0, 'lead_capture_enabled' => 0,
        ],
        'paid' => [
            'code' => 'paid', 'version' => 1, 'label' => 'Acesso pago',
            'description' => 'Venda de acesso sem cortesia comercial.',
            'subscriber_access_mode' => 'inherit', 'courtesy_mode' => 'disabled',
            'paid_access_enabled' => 1, 'promotional_ads_enabled' => 0,
            'allow_global_ads' => 0, 'lead_capture_enabled' => 0,
        ],
        'hybrid' => [
            'code' => 'hybrid', 'version' => 1, 'label' => 'Gratuito + pago',
            'description' => 'Cortesia direta e planos pagos na mesma experiência.',
            'subscriber_access_mode' => 'inherit', 'courtesy_mode' => 'direct',
            'paid_access_enabled' => 1, 'promotional_ads_enabled' => 0,
            'allow_global_ads' => 0, 'lead_capture_enabled' => 0,
        ],
    ];
}

function fs_portal_config_fields(): array
{
    return [
        'subscriber_access_mode','courtesy_mode','paid_access_enabled',
        'promotional_ads_enabled','allow_global_ads','lead_capture_enabled',
        'welcome_screen_enabled','single_option_direct_enabled',
    ];
}

function fs_portal_config_preset_fields(): array
{
    return [
        'subscriber_access_mode','courtesy_mode','paid_access_enabled',
        'promotional_ads_enabled','allow_global_ads','lead_capture_enabled',
    ];
}

function fs_portal_config_bool($value): int
{
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true) ? 1 : 0;
}

function fs_portal_config_normalize(array $values, ?array $base = null): array
{
    $base = $base ?: [
        'subscriber_access_mode' => 'inherit', 'courtesy_mode' => 'disabled',
        'paid_access_enabled' => 0, 'promotional_ads_enabled' => 0,
        'allow_global_ads' => 0, 'lead_capture_enabled' => 0,
        'welcome_screen_enabled' => 1, 'single_option_direct_enabled' => 0,
    ];
    $subscriber = strtolower(trim((string)($values['subscriber_access_mode'] ?? $base['subscriber_access_mode'] ?? 'inherit')));
    $courtesy = strtolower(trim((string)($values['courtesy_mode'] ?? $base['courtesy_mode'] ?? 'disabled')));
    if (!in_array($subscriber, ['inherit','allow','deny'], true)) throw new InvalidArgumentException('Modo do benefício FIRENETWORK inválido.');
    if (!in_array($courtesy, ['disabled','direct','sponsored'], true)) throw new InvalidArgumentException('Modo de cortesia inválido.');
    return [
        'subscriber_access_mode' => $subscriber,
        'courtesy_mode' => $courtesy,
        'paid_access_enabled' => fs_portal_config_bool($values['paid_access_enabled'] ?? $base['paid_access_enabled'] ?? 0),
        'promotional_ads_enabled' => fs_portal_config_bool($values['promotional_ads_enabled'] ?? $base['promotional_ads_enabled'] ?? 0),
        'allow_global_ads' => fs_portal_config_bool($values['allow_global_ads'] ?? $base['allow_global_ads'] ?? 0),
        'lead_capture_enabled' => fs_portal_config_bool($values['lead_capture_enabled'] ?? $base['lead_capture_enabled'] ?? 0),
        'welcome_screen_enabled' => fs_portal_config_bool($values['welcome_screen_enabled'] ?? $base['welcome_screen_enabled'] ?? 1),
        'single_option_direct_enabled' => fs_portal_config_bool($values['single_option_direct_enabled'] ?? $base['single_option_direct_enabled'] ?? 0),
    ];
}

function fs_portal_config_canonical(array $config): array
{
    return fs_portal_config_normalize($config);
}

function fs_portal_config_hash(array $config): string
{
    return hash('sha256', (string)json_encode(fs_portal_config_canonical($config), JSON_UNESCAPED_SLASHES));
}

function fs_portal_config_get(PDO $pdo, int $partnerId, string $state = 'published', bool $forUpdate = false): ?array
{
    if ($partnerId <= 0 || !in_array($state, ['draft','published'], true) || !fs_portal_config_schema_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM partner_portal_configurations WHERE partner_id=? AND state=? ORDER BY revision DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$partnerId,$state]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_portal_config_by_id(PDO $pdo, int $partnerId, int $id, bool $forUpdate = false): ?array
{
    if ($partnerId <= 0 || $id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM partner_portal_configurations WHERE partner_id=? AND id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$partnerId,$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_portal_config_history(PDO $pdo, int $partnerId, int $limit = 20): array
{
    $limit = max(1,min(100,$limit));
    $st = $pdo->prepare('SELECT * FROM partner_portal_configurations WHERE partner_id=? ORDER BY revision DESC LIMIT ' . $limit);
    $st->execute([$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_portal_config_matches_preset(array $config): ?array
{
    $canonical = fs_portal_config_canonical($config);
    foreach (fs_portal_config_presets() as $preset) {
        $presetCanonical = fs_portal_config_canonical($preset);
        $matches = true;
        foreach (fs_portal_config_preset_fields() as $field) {
            if ($canonical[$field] !== $presetCanonical[$field]) {
                $matches = false;
                break;
            }
        }
        if ($matches) return $preset;
    }
    return null;
}

function fs_portal_config_label(array $config): string
{
    $match = fs_portal_config_matches_preset($config);
    return $match ? (string)$match['label'] : 'Personalizado';
}

function fs_portal_config_next_revision(PDO $pdo, int $partnerId): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM partner_portal_configurations WHERE partner_id=?');
    $st->execute([$partnerId]);
    return max(1,(int)$st->fetchColumn());
}

function fs_portal_config_audit(PDO $pdo, int $partnerId, string $actorType, ?int $actorId, string $action, $targetId, array $metadata = []): void
{
    try {
        $actorType = in_array($actorType,['firespot','partner_admin','system'],true) ? $actorType : 'system';
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/secret|token|password|credential|document|phone|email/i',(string)$key)) continue;
            $safe[(string)$key] = is_scalar($value) || $value === null ? $value : '[structured]';
        }
        $st = $pdo->prepare('INSERT INTO partner_admin_audit (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash) VALUES (?,?,?,?,?,?,?,NULL)');
        $st->execute([$partnerId,$actorType,$actorId,substr($action,0,80),'portal_configuration',substr((string)$targetId,0,64),$safe ? json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) {
        error_log('[portal config audit] ' . $e->getMessage());
    }
}

function fs_portal_config_create_draft(PDO $pdo, int $partnerId, string $actorType = 'firespot', ?int $actorId = null): array
{
    if (!fs_portal_config_schema_ready($pdo)) throw new RuntimeException('A migração da configuração funcional ainda não foi aplicada.');
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $partnerLock = $pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
        $partnerLock->execute([$partnerId]);
        if (!$partnerLock->fetchColumn()) throw new InvalidArgumentException('Estabelecimento não encontrado.');
        $existing = fs_portal_config_get($pdo,$partnerId,'draft',true);
        if ($existing) {
            if ($owns) $pdo->commit();
            return $existing;
        }
        $published = fs_portal_config_get($pdo,$partnerId,'published',true);
        $values = $published ? fs_portal_config_canonical($published) : fs_portal_config_normalize([]);
        $revision = fs_portal_config_next_revision($pdo,$partnerId);
        $st = $pdo->prepare('INSERT INTO partner_portal_configurations
            (partner_id,revision,state,source,preset_code,preset_version,subscriber_access_mode,courtesy_mode,paid_access_enabled,promotional_ads_enabled,allow_global_ads,lead_capture_enabled,welcome_screen_enabled,single_option_direct_enabled,base_revision_id,created_by_type,created_by_id)
            VALUES (?,?,\'draft\',?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$partnerId,$revision,$published ? 'custom' : 'migration',$published['preset_code'] ?? null,$published['preset_version'] ?? null,
            $values['subscriber_access_mode'],$values['courtesy_mode'],$values['paid_access_enabled'],$values['promotional_ads_enabled'],$values['allow_global_ads'],$values['lead_capture_enabled'],
            $values['welcome_screen_enabled'],$values['single_option_direct_enabled'],
            $published['id'] ?? null,$actorType,$actorId]);
        $id = (int)$pdo->lastInsertId();
        fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.draft_created',$id,['revision'=>$revision]);
        $draft = fs_portal_config_by_id($pdo,$partnerId,$id) ?: [];
        if ($owns) $pdo->commit();
        return $draft;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_portal_config_save_draft(PDO $pdo, int $partnerId, array $input, string $actorType = 'firespot', ?int $actorId = null, bool $partnerScope = false): array
{
    $draft = fs_portal_config_create_draft($pdo,$partnerId,$actorType,$actorId);
    $current = fs_portal_config_canonical($draft);
    $allowedInput = [];
    foreach (fs_portal_config_fields() as $field) {
        if (array_key_exists($field,$input)) $allowedInput[$field] = $input[$field];
    }
    $next = fs_portal_config_normalize($allowedInput,$current);
    if ($partnerScope) {
        // O parceiro não controla a elegibilidade contratual FIRENETWORK nem
        // campanhas globais sem uma autorização central já publicada.
        $published = fs_portal_config_get($pdo,$partnerId,'published');
        $next['subscriber_access_mode'] = (string)($published['subscriber_access_mode'] ?? $current['subscriber_access_mode']);
        if ((int)($published['allow_global_ads'] ?? 0) !== 1) $next['allow_global_ads'] = 0;
    }
    $match = fs_portal_config_matches_preset($next);
    $source = $match ? 'preset' : 'custom';
    $presetCode = $match['code'] ?? ($draft['preset_code'] ?? null);
    $presetVersion = $match['version'] ?? ($draft['preset_version'] ?? null);
    $st = $pdo->prepare('UPDATE partner_portal_configurations SET source=?,preset_code=?,preset_version=?,subscriber_access_mode=?,courtesy_mode=?,paid_access_enabled=?,promotional_ads_enabled=?,allow_global_ads=?,lead_capture_enabled=?,welcome_screen_enabled=?,single_option_direct_enabled=?,validation_snapshot=NULL,validation_hash=NULL,validated_at=NULL,updated_at=NOW() WHERE id=? AND partner_id=? AND state=\'draft\'');
    $st->execute([$source,$presetCode,$presetVersion,$next['subscriber_access_mode'],$next['courtesy_mode'],$next['paid_access_enabled'],$next['promotional_ads_enabled'],$next['allow_global_ads'],$next['lead_capture_enabled'],$next['welcome_screen_enabled'],$next['single_option_direct_enabled'],(int)$draft['id'],$partnerId]);
    if ($st->rowCount() !== 1 && fs_portal_config_hash($draft) !== fs_portal_config_hash($next)) throw new RuntimeException('O rascunho foi alterado por outra sessão. Recarregue a tela.');
    fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.draft_saved',(int)$draft['id'],['revision'=>(int)$draft['revision'],'source'=>$source]);
    return fs_portal_config_by_id($pdo,$partnerId,(int)$draft['id']) ?: $draft;
}

function fs_portal_config_apply_preset(PDO $pdo, int $partnerId, string $presetCode, string $actorType = 'firespot', ?int $actorId = null): array
{
    $presets = fs_portal_config_presets();
    if (!isset($presets[$presetCode])) throw new InvalidArgumentException('Preset desconhecido.');
    $preset = $presets[$presetCode];
    $draft = fs_portal_config_create_draft($pdo,$partnerId,$actorType,$actorId);
    $st = $pdo->prepare('UPDATE partner_portal_configurations SET source=\'preset\',preset_code=?,preset_version=?,subscriber_access_mode=?,courtesy_mode=?,paid_access_enabled=?,promotional_ads_enabled=?,allow_global_ads=?,lead_capture_enabled=?,validation_snapshot=NULL,validation_hash=NULL,validated_at=NULL,updated_at=NOW() WHERE id=? AND partner_id=? AND state=\'draft\'');
    $st->execute([$preset['code'],$preset['version'],$preset['subscriber_access_mode'],$preset['courtesy_mode'],$preset['paid_access_enabled'],$preset['promotional_ads_enabled'],$preset['allow_global_ads'],$preset['lead_capture_enabled'],(int)$draft['id'],$partnerId]);
    fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.preset_applied',(int)$draft['id'],['preset'=>$presetCode,'version'=>(int)$preset['version']]);
    return fs_portal_config_by_id($pdo,$partnerId,(int)$draft['id']) ?: $draft;
}

function fs_portal_config_diff(?array $published, array $draft): array
{
    $before = fs_portal_config_normalize($published ?: []);
    $after = fs_portal_config_canonical($draft);
    $labels = [
        'subscriber_access_mode'=>'Benefício FIRENETWORK','courtesy_mode'=>'Cortesia',
        'paid_access_enabled'=>'Venda de acesso','promotional_ads_enabled'=>'Publicidade promocional',
        'allow_global_ads'=>'Campanhas globais','lead_capture_enabled'=>'Captação de interesse',
        'welcome_screen_enabled'=>'Tela de boas-vindas','single_option_direct_enabled'=>'Acesso direto com uma modalidade',
    ];
    $changes = [];
    foreach ($labels as $field => $label) {
        if ($before[$field] !== $after[$field]) $changes[] = ['field'=>$field,'label'=>$label,'from'=>$before[$field],'to'=>$after[$field]];
    }
    return $changes;
}

function fs_portal_config_setting_bool(PDO $pdo, string $key, bool $default = false): bool
{
    return fs_courtesy_setting_bool($pdo,$key,$default);
}

function fs_portal_config_subscriber_enabled(PDO $pdo, array $config): bool
{
    $mode = (string)($config['subscriber_access_mode'] ?? 'inherit');
    if ($mode === 'deny') return false;
    return fs_portal_config_setting_bool($pdo,'subscriber_access_enabled',false);
}

function fs_portal_config_active_ads(PDO $pdo, int $partnerId, bool $allowGlobal): int
{
    $sql = 'SELECT COUNT(*) FROM custom_ads WHERE active=1 AND (start_date IS NULL OR start_date<=CURRENT_DATE) AND (end_date IS NULL OR end_date>=CURRENT_DATE) AND (partner_id=?';
    if ($allowGlobal) $sql .= ' OR partner_id IS NULL';
    $sql .= ')';
    $st = $pdo->prepare($sql);
    $st->execute([$partnerId]);
    return (int)$st->fetchColumn();
}

function fs_portal_config_validation_item(string $code, string $label, string $level, string $message, string $group = 'dependencies'): array
{
    return ['code'=>$code,'label'=>$label,'level'=>$level,'ready'=>$level !== 'block','message'=>$message,'group'=>$group];
}

function fs_portal_config_validate(PDO $pdo, array $partner, array $config): array
{
    $config = fs_portal_config_canonical($config);
    $items = [];
    $partnerId = (int)($partner['id'] ?? 0);
    $subscriberMode=(string)$config['subscriber_access_mode'];
    $subscriber = $subscriberMode==='allow' || ($subscriberMode==='inherit'&&fs_portal_config_setting_bool($pdo,'subscriber_access_enabled',false));
    $courtesy = $config['courtesy_mode'] !== 'disabled';
    $sales = (int)$config['paid_access_enabled'] === 1;
    if (!$subscriber && !$courtesy && !$sales) {
        $items[] = fs_portal_config_validation_item('no_access','Formas de acesso','block','Ative ao menos benefício FIRENETWORK, cortesia ou venda.','access');
    }
    $items[] = fs_portal_config_validation_item('partner_active','Estabelecimento ativo',(int)($partner['active'] ?? 0) === 1 ? 'ready' : 'block',(int)($partner['active'] ?? 0) === 1 ? 'Estabelecimento ativo.' : 'Ative o estabelecimento.','dependencies');
    $items[] = fs_portal_config_validation_item('portal_v3','Portal V3',($partner['portal_mode'] ?? '') === 'v3' ? 'ready' : 'block',($partner['portal_mode'] ?? '') === 'v3' ? 'Portal V3 ativo.' : 'Selecione o Portal V3.','dependencies');
    try {
        $st=$pdo->prepare('SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=? AND active=1');$st->execute([$partnerId]);$hotspots=(int)$st->fetchColumn();
        $items[] = fs_portal_config_validation_item('active_hotspot','Instalação ativa',$hotspots>0?'ready':'block',$hotspots>0?"{$hotspots} instalação(ões) ativa(s).":'Cadastre e ative uma instalação FireSpot.','dependencies');
    } catch (Throwable $e) {
        $items[] = fs_portal_config_validation_item('active_hotspot','Instalação ativa','block','Não foi possível validar as instalações.','dependencies');
    }
    if ($subscriber) {
        $schema = fs_portal_config_schema_ready($pdo);
        $global = fs_portal_config_setting_bool($pdo,'subscriber_access_enabled',false);
        $items[] = fs_portal_config_validation_item('subscriber_schema','Benefício FIRENETWORK',$schema?'ready':'block',$schema?'Domínio de benefícios disponível.':'A migração do domínio de benefícios ainda não foi aplicada.','access');
        $items[] = fs_portal_config_validation_item('subscriber_resolution','Resolução HubSoft',$global?'ready':'block',$global?'Resolução de entitlement liberada.':'Libere a resolução HubSoft no rollout central.','dependencies');
        foreach(['subscriber_account_enabled'=>'Minha Conta','subscriber_invites_enabled'=>'Convites e aparelhos','subscriber_radius_enabled'=>'Acesso incluído no RADIUS'] as $gate=>$label){$ready=fs_portal_config_setting_bool($pdo,$gate,false);$items[]=fs_portal_config_validation_item($gate,$label,$ready?'ready':'block',$ready?"{$label} liberado.":"Libere {$label} no rollout central.",'dependencies');}
    }
    if ($courtesy) {
        try {
            $policy = fs_courtesy_policy_resolve($pdo,$partnerId);
            $policy['enabled'] = 1;
            $policy['requires_ad'] = $config['courtesy_mode'] === 'sponsored' ? 1 : 0;
            $errors = fs_courtesy_policy_validate(fs_courtesy_policy_normalize($policy));
            $items[] = fs_portal_config_validation_item('courtesy_policy','Política de cortesia',$errors?'block':'ready',$errors?'Revise: '.implode('; ',$errors):'Política especializada válida.','access');
            $radius = ($policy['enforcement_method'] ?? '') === 'radius' && fs_portal_config_setting_bool($pdo,'courtesy_radius_ready',false);
            $items[] = fs_portal_config_validation_item('courtesy_radius','FreeRADIUS',$radius?'ready':'block',$radius?'Execução RADIUS pronta.':'Cortesia exige FreeRADIUS pronto; MikroTik local não é aceito neste rollout.','dependencies');
            $rollout = fs_courtesy_rollout_resolve($pdo,$partnerId,'v3',$policy);
            $items[] = fs_portal_config_validation_item('courtesy_rollout','Rollout da cortesia',($rollout['effective_mode'] ?? '')==='enforce'?'ready':'block',($rollout['effective_mode'] ?? '')==='enforce'?'Portal V3 em enforce unificado.':'Ative o rollout unificado do Portal V3 e seus gates.','dependencies');
        } catch (Throwable $e) {
            $items[] = fs_portal_config_validation_item('courtesy_policy','Política de cortesia','block','Não foi possível validar a cortesia.','access');
        }
    }
    if ($config['courtesy_mode'] === 'sponsored' && (int)$config['promotional_ads_enabled'] !== 1) {
        $items[] = fs_portal_config_validation_item('sponsored_ads','Publicidade patrocinada','block','Cortesia patrocinada exige publicidade promocional.','access');
    }
    if ((int)$config['allow_global_ads'] === 1 && (int)$config['promotional_ads_enabled'] !== 1) {
        $items[] = fs_portal_config_validation_item('global_ads_dependency','Campanhas globais','block','Campanhas globais exigem publicidade promocional.','dependencies');
    }
    if ((int)$config['lead_capture_enabled'] === 1 && (int)$config['promotional_ads_enabled'] !== 1) {
        $items[] = fs_portal_config_validation_item('lead_dependency','Captação de interesse','block','Captação de interesse exige publicidade promocional.','dependencies');
    }
    if ((int)$config['promotional_ads_enabled'] === 1) {
        try {
            $ads = fs_portal_config_active_ads($pdo,$partnerId,(int)$config['allow_global_ads'] === 1);
            $mandatory = $config['courtesy_mode'] === 'sponsored';
            $level = $ads > 0 ? 'ready' : ($mandatory ? 'block' : 'warning');
            $items[] = fs_portal_config_validation_item('eligible_ad','Campanha elegível',$level,$ads>0?"{$ads} anúncio(s) elegível(is).":($mandatory?'Cadastre um anúncio elegível antes de publicar.':'Publicidade ficará sem conteúdo até existir campanha elegível.'),'dependencies');
        } catch (Throwable $e) {
            $items[] = fs_portal_config_validation_item('eligible_ad','Campanha elegível',$config['courtesy_mode']==='sponsored'?'block':'warning','Não foi possível consultar campanhas.','dependencies');
        }
    }
    if ($sales) {
        try {
            $plans = array_values(array_filter(fs_guest_plans($pdo,$partner),static fn(array $p):bool=>(int)$p['price_cents']>0&&(int)$p['duration_minutes']>0));
            $items[] = fs_portal_config_validation_item('paid_plans','Planos pagos',$plans?'ready':'block',$plans?count($plans).' plano(s) válido(s).':'Cadastre um plano ativo com preço e duração.','access');
        } catch (Throwable $e) {
            $items[] = fs_portal_config_validation_item('paid_plans','Planos pagos','block','Não foi possível validar os planos.','access');
        }
        try {
            $wallet = fs_wallet_for_partner($pdo,$partner,true);fs_wallet_assert_usable($wallet);
            $items[] = fs_portal_config_validation_item('paid_wallet','Recebimento','ready','Carteira utilizável.','dependencies');
        } catch (Throwable $e) {
            $items[] = fs_portal_config_validation_item('paid_wallet','Recebimento','block','Configure uma carteira de recebimento utilizável.','dependencies');
        }
    }
    $blocks = array_values(array_filter($items,static fn(array $item):bool=>$item['level']==='block'));
    $warnings = array_values(array_filter($items,static fn(array $item):bool=>$item['level']==='warning'));
    return ['ready'=>!$blocks,'config'=>$config,'items'=>$items,'blocks'=>$blocks,'warnings'=>$warnings,'validated_at'=>gmdate(DATE_ATOM)];
}

function fs_portal_config_legacy_purpose(array $config): string
{
    $courtesy = (string)$config['courtesy_mode'];
    $sales = (int)$config['paid_access_enabled'] === 1;
    if (!$sales && $courtesy === 'direct') return 'free';
    if (!$sales && $courtesy === 'sponsored') return 'sponsored';
    if ($sales && $courtesy === 'disabled') return 'paid';
    return 'hybrid';
}

function fs_portal_config_materialize(PDO $pdo, array $partner, array $config): void
{
    $partnerId = (int)$partner['id'];
    $config = fs_portal_config_canonical($config);
    $policy = fs_courtesy_policy_resolve($pdo,$partnerId);
    $policy['enabled'] = $config['courtesy_mode'] === 'disabled' ? 0 : 1;
    $policy['requires_ad'] = $config['courtesy_mode'] === 'sponsored' ? 1 : 0;
    fs_partner_courtesy_save_central($pdo,$partnerId,$policy,true);
    $legacyPurpose = fs_portal_config_legacy_purpose($config);
    $pdo->prepare('UPDATE partners SET access_purpose=?,ads_enabled=?,allow_global_ads=?,updated_at=NOW() WHERE id=?')
        ->execute([$legacyPurpose,$config['promotional_ads_enabled'],$config['allow_global_ads'],$partnerId]);
}

function fs_portal_config_publish(PDO $pdo, int $partnerId, string $actorType = 'firespot', ?int $actorId = null): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$partnerId]);$partner=$st->fetch(PDO::FETCH_ASSOC);
        if (!$partner) throw new InvalidArgumentException('Estabelecimento não encontrado.');
        $draft=fs_portal_config_get($pdo,$partnerId,'draft',true);
        if (!$draft) throw new RuntimeException('Nenhum rascunho disponível para publicação.');
        $published=fs_portal_config_get($pdo,$partnerId,'published',true);
        $validation=fs_portal_config_validate($pdo,$partner,$draft);
        $snapshot=json_encode($validation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',(string)$snapshot);
        $pdo->prepare('UPDATE partner_portal_configurations SET validation_snapshot=?,validation_hash=?,validated_at=NOW(),updated_at=NOW() WHERE id=?')
            ->execute([$snapshot,$hash,(int)$draft['id']]);
        if (empty($validation['ready'])) {
            throw new RuntimeException('A configuração não pode ser publicada: ' . implode(' ',array_map(static fn(array $i):string=>(string)$i['message'],$validation['blocks'])));
        }
        fs_portal_config_materialize($pdo,$partner,$draft);
        if ($published) $pdo->prepare("UPDATE partner_portal_configurations SET state='superseded',updated_at=NOW() WHERE id=? AND state='published'")->execute([(int)$published['id']]);
        $st=$pdo->prepare("UPDATE partner_portal_configurations SET state='published',validation_snapshot=?,validation_hash=?,validated_at=NOW(),published_at=NOW(),updated_at=NOW() WHERE id=? AND state='draft'");
        $st->execute([$snapshot,$hash,(int)$draft['id']]);
        if ($st->rowCount()!==1) throw new RuntimeException('O rascunho mudou durante a publicação.');
        fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.published',(int)$draft['id'],['revision'=>(int)$draft['revision'],'previous_revision'=>(int)($published['revision']??0),'validation_hash'=>$hash]);
        if($ownsTransaction)$pdo->commit();
        return fs_portal_config_by_id($pdo,$partnerId,(int)$draft['id']) ?: $draft;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_portal_config_discard_draft(PDO $pdo, int $partnerId, string $actorType = 'firespot', ?int $actorId = null): void
{
    $draft=fs_portal_config_get($pdo,$partnerId,'draft');
    if (!$draft) return;
    $st=$pdo->prepare("UPDATE partner_portal_configurations SET state='discarded',updated_at=NOW() WHERE id=? AND partner_id=? AND state='draft'");
    $st->execute([(int)$draft['id'],$partnerId]);
    fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.draft_discarded',(int)$draft['id'],['revision'=>(int)$draft['revision']]);
}

function fs_portal_config_rollback(PDO $pdo, int $partnerId, int $sourceId, string $actorType = 'firespot', ?int $actorId = null): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $source=fs_portal_config_by_id($pdo,$partnerId,$sourceId,true);
        if (!$source || !in_array((string)$source['state'],['superseded','published','archived'],true)) throw new InvalidArgumentException('Revisão de origem inválida para rollback.');
        fs_portal_config_discard_draft($pdo,$partnerId,$actorType,$actorId);
        $revision=fs_portal_config_next_revision($pdo,$partnerId);$c=fs_portal_config_canonical($source);
        $st=$pdo->prepare("INSERT INTO partner_portal_configurations (partner_id,revision,state,source,preset_code,preset_version,subscriber_access_mode,courtesy_mode,paid_access_enabled,promotional_ads_enabled,allow_global_ads,lead_capture_enabled,welcome_screen_enabled,single_option_direct_enabled,base_revision_id,created_by_type,created_by_id) VALUES (?,?,'draft','rollback',?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$partnerId,$revision,$source['preset_code'],$source['preset_version'],$c['subscriber_access_mode'],$c['courtesy_mode'],$c['paid_access_enabled'],$c['promotional_ads_enabled'],$c['allow_global_ads'],$c['lead_capture_enabled'],$c['welcome_screen_enabled'],$c['single_option_direct_enabled'],$sourceId,$actorType,$actorId]);
        fs_portal_config_audit($pdo,$partnerId,$actorType,$actorId,'portal_config.rollback_prepared',(int)$pdo->lastInsertId(),['source_revision'=>(int)$source['revision'],'target_revision'=>$revision]);
        $published=fs_portal_config_publish($pdo,$partnerId,$actorType,$actorId);if($ownsTransaction)$pdo->commit();return$published;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
}

function fs_portal_config_has_sales(array $config): bool
{
    return (int)($config['paid_access_enabled'] ?? 0) === 1;
}

function fs_portal_config_has_courtesy(array $config): bool
{
    return in_array((string)($config['courtesy_mode'] ?? 'disabled'),['direct','sponsored'],true);
}

function fs_portal_config_is_sponsored(array $config): bool
{
    return (string)($config['courtesy_mode'] ?? '') === 'sponsored';
}

function fs_portal_config_welcome_enabled(array $config): bool
{
    return (int)($config['welcome_screen_enabled'] ?? 1) === 1;
}

function fs_portal_config_single_option_direct_enabled(array $config): bool
{
    return (int)($config['single_option_direct_enabled'] ?? 0) === 1;
}

function fs_portal_config_single_visible_mode(array $visibility): ?string
{
    $visible = [];
    foreach (['courtesy','paid','subscriber'] as $mode) {
        if (!empty($visibility[$mode])) $visible[] = $mode;
    }
    return count($visible) === 1 ? $visible[0] : null;
}

function fs_portal_config_for_partner(PDO $pdo, array $partner, ?int $previewRevisionId = null): array
{
    $partnerId=(int)($partner['id']??0);
    if ($previewRevisionId !== null) {
        $preview=fs_portal_config_by_id($pdo,$partnerId,$previewRevisionId);
        if ($preview && $preview['state']==='draft') return function_exists('fs_partner_hotspot_commercial_apply_portal_config')?fs_partner_hotspot_commercial_apply_portal_config($preview,$partner):$preview;
    }
    $published=fs_portal_config_get($pdo,$partnerId,'published');
    if ($published) return function_exists('fs_partner_hotspot_commercial_apply_portal_config')?fs_partner_hotspot_commercial_apply_portal_config($published,$partner):$published;
    // Fallback estritamente transitório para bancos ainda sem backfill.
    $purpose=(string)($partner['access_purpose']??'paid');
    $map=['free'=>'free_quick','sponsored'=>'free_sponsored','paid'=>'paid','hybrid'=>'hybrid'];
    $fallback=fs_portal_config_presets()[$map[$purpose]??'paid'];
    return function_exists('fs_partner_hotspot_commercial_apply_portal_config')?fs_partner_hotspot_commercial_apply_portal_config($fallback,$partner):$fallback;
}

function fs_portal_config_module_allowed(PDO $pdo, array $partner, string $module): bool
{
    $config=fs_portal_config_for_partner($pdo,$partner);
    if (in_array($module,['plans','billing','finance'],true)) return fs_portal_config_has_sales($config);
    if ($module==='courtesy') return fs_portal_config_has_courtesy($config);
    if ($module==='ads') return (int)$config['promotional_ads_enabled']===1;
    return in_array($module,['summary','portal','theme','reports','team','monetization','infrastructure','analytics','audit'],true);
}
