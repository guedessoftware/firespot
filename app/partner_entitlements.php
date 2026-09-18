<?php

declare(strict_types=1);

/**
 * Autorização comercial do portal. Papéis e finalidade continuam sendo
 * verificados em partner_admin.php; este domínio responde somente pelo que o
 * estabelecimento contratou e pelas cotas do plano vigente.
 */

function fs_partner_entitlements_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM platform_plans LIMIT 0');
        $pdo->query('SELECT id FROM partner_subscriptions LIMIT 0');
        $pdo->query('SELECT id FROM partner_feature_overrides LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string,string> */
function fs_partner_feature_labels(): array
{
    return [
        'portal.basic'=>'Portal básico',
        'branding.manage'=>'Aparência',
        'portal.presentation.manage'=>'Modelos visuais e conteúdo do Portal V3',
        'guest_plans.manage'=>'Planos de acesso',
        'reports.basic'=>'Relatórios básicos',
        'reports.advanced'=>'Métricas avançadas',
        'reports.export'=>'Exportação de métricas',
        'finance.view'=>'Financeiro',
        'nas.view'=>'Consulta de NAS',
        'nas.manage'=>'Gestão de NAS próprio',
        'nas.prepare'=>'Preparação de NAS próprio',
        'nas.retire'=>'Aposentadoria de NAS próprio',
        'hotspots.view'=>'Consulta de pontos',
        'hotspots.draft.manage'=>'Rascunhos de pontos',
        'hotspots.apply'=>'Aplicação de pontos (exclusiva da FireSpot)',
        'wallet.manage'=>'Carteira do estabelecimento (Plano Máximo)',
        'courtesy.view'=>'Consulta de cortesia',
        'courtesy.manage'=>'Política comercial de cortesia',
        'courtesy.hotspot_override.manage'=>'Cortesia por ponto',
        'team.manage'=>'Equipe',
        'ads.manage'=>'Publicidade',
        'ad.inventory.manage'=>'Inventário publicitário por ponto (Plano Máximo)',
        'monetization.view'=>'Monetização',
    ];
}

/** @return array<string,string> */
function fs_partner_module_features(): array
{
    return [
        'summary'=>'portal.basic',
        'simulation'=>'portal.basic',
        'portal'=>'portal.presentation.manage',
        'theme'=>'branding.manage',
        'plans'=>'guest_plans.manage',
        'billing'=>'wallet.manage',
        'finance'=>'finance.view',
        'monetization'=>'monetization.view',
        'ads'=>'ads.manage',
        'reports'=>'reports.basic',
        'team'=>'team.manage',
        'infrastructure'=>'hotspots.view',
        'courtesy'=>'courtesy.view',
        'analytics'=>'reports.advanced',
        'audit'=>'portal.basic',
    ];
}

/**
 * O autosserviço existe somente quando o plano habilita a capacidade e cada
 * operação comprova a propriedade exclusiva do NAS. Equipamentos FireSpot
 * continuam fora desse fluxo.
 */
function fs_partner_nas_self_service_allowed(): bool
{
    return true;
}

function fs_partner_feature_is_central_only(string $featureCode): bool
{
    return false;
}

/** @return list<string> */
function fs_partner_legacy_fallback_features(): array
{
    // Usado somente durante a janela entre deploy do código e migração 045.
    return [
        'portal.basic','branding.manage','guest_plans.manage','reports.basic',
        'finance.view','wallet.manage','team.manage','ads.manage','monetization.view',
    ];
}

function fs_partner_subscription_status_allows(string $status, bool $mutation): bool
{
    if (in_array($status, ['trial','active'], true)) return true;
    if (!$mutation && in_array($status, ['grace','past_due','suspended'], true)) return true;
    return false;
}

function fs_partner_hotspot_apply_pilot_approved(PDO $pdo): bool
{
    try {
        $statement=$pdo->prepare("SELECT svalue FROM app_settings WHERE skey='partner_hotspot_apply_pilot_approved' LIMIT 1");
        $statement->execute();
        return trim((string)($statement->fetchColumn()?:''))==='1';
    } catch (Throwable $error) {
        return false;
    }
}

/** @return array<string,mixed>|null */
function fs_partner_current_subscription(PDO $pdo, int $partnerId): ?array
{
    if ($partnerId <= 0 || !fs_partner_entitlements_schema_ready($pdo)) return null;
    $statement = $pdo->prepare('SELECT s.*,p.code plan_code,p.version plan_version,p.name plan_name,p.internal_only,
            p.max_nas,p.max_hotspots,p.max_admin_users,p.max_report_range_days,p.custom_courtesy_overrides
        FROM partner_subscriptions s
        JOIN platform_plans p ON p.id=s.plan_id
        WHERE s.partner_id=? AND s.is_current=1
        ORDER BY s.id DESC LIMIT 1');
    $statement->execute([$partnerId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function fs_partner_active_feature_override(PDO $pdo, int $partnerId, string $featureCode): ?array
{
    $statement = $pdo->prepare('SELECT * FROM partner_feature_overrides
        WHERE partner_id=? AND feature_code=? AND valid_from<=NOW()
        ORDER BY id DESC LIMIT 1');
    $statement->execute([$partnerId,$featureCode]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if($row&&!empty($row['valid_until'])&&strtotime((string)$row['valid_until'])<=time())return null;
    return $row ?: null;
}

/** @return array<string,string> */
function fs_partner_override_catalog(): array
{
    return fs_partner_feature_labels()+[
        'quota.max_nas'=>'Cota de NAS próprios',
        'quota.max_hotspots'=>'Cota de pontos',
        'quota.max_admin_users'=>'Cota de usuários administrativos',
        'quota.max_report_range_days'=>'Limite de dias por relatório',
        'quota.custom_courtesy_overrides'=>'Cota de exceções de cortesia',
    ];
}

/** @return array<string,mixed> */
function fs_partner_create_feature_override(PDO $pdo, int $partnerId, string $featureCode, bool $enabled, $limitValue, int $validDays, string $reason, int $actorId): array
{
    $featureCode=trim($featureCode);$reason=trim($reason);
    if(!isset(fs_partner_override_catalog()[$featureCode]))throw new InvalidArgumentException('Função ou cota inválida.');
    if($reason===''||strlen($reason)>300)throw new InvalidArgumentException('Informe um motivo auditável com até 300 caracteres.');
    if($validDays<1||$validDays>3650)throw new InvalidArgumentException('A validade deve ficar entre 1 e 3650 dias.');
    $isQuota=str_starts_with($featureCode,'quota.');
    $limit=$limitValue===''||$limitValue===null?null:(int)$limitValue;
    if($isQuota&&(!$enabled||$limit===null||$limit<0))throw new InvalidArgumentException('Overrides de cota exigem um limite não negativo e efeito habilitado.');
    if(!$isQuota)$limit=null;
    if(!fs_partner_entitlements_schema_ready($pdo))throw new RuntimeException('A migração de planos ainda não foi aplicada.');
    if($featureCode==='hotspots.apply'&&$enabled&&!fs_partner_hotspot_apply_pilot_approved($pdo))throw new RuntimeException('Conclua e aprove o piloto físico antes de habilitar a aplicação remota para um estabelecimento.');
    $validUntil=date('Y-m-d H:i:s',time()+($validDays*86400));
    $transaction=!$pdo->inTransaction();if($transaction)$pdo->beginTransaction();
    try{
        $partner=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$partner->execute([$partnerId]);
        if(!$partner->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado.');
        $insert=$pdo->prepare("INSERT INTO partner_feature_overrides (partner_id,feature_code,enabled,limit_value,reason,valid_from,valid_until,created_by_type,created_by_id) VALUES (?,?,?,?,?,NOW(),?,'firespot',?)");
        $insert->execute([$partnerId,$featureCode,$enabled?1:0,$limit,$reason,$validUntil,$actorId>0?$actorId:null]);
        $id=(int)$pdo->lastInsertId();
        if($transaction)$pdo->commit();
        return ['id'=>$id,'feature_code'=>$featureCode,'enabled'=>$enabled?1:0,'limit_value'=>$limit,'valid_until'=>$validUntil];
    }catch(Throwable $error){if($transaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

/** @return list<array<string,mixed>> */
function fs_partner_feature_override_history(PDO $pdo, int $partnerId, int $limit=20): array
{
    if(!fs_partner_entitlements_schema_ready($pdo))return [];
    $limit=max(1,min(100,$limit));
    $statement=$pdo->prepare('SELECT id,feature_code,enabled,limit_value,reason,valid_from,valid_until,created_at FROM partner_feature_overrides WHERE partner_id=? ORDER BY id DESC LIMIT '.$limit);
    $statement->execute([$partnerId]);return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
}

/** @return array{allowed:bool,feature:string,source:string,status:string,plan_code:?string,reason:string} */
function fs_partner_entitlement(PDO $pdo, int $partnerId, string $featureCode, bool $mutation=false): array
{
    if ($partnerId <= 0 || !array_key_exists($featureCode, fs_partner_feature_labels())) {
        return ['allowed'=>false,'feature'=>$featureCode,'source'=>'invalid','status'=>'none','plan_code'=>null,'reason'=>'FEATURE_UNKNOWN'];
    }
    if (!fs_partner_entitlements_schema_ready($pdo)) {
        $allowed = in_array($featureCode, fs_partner_legacy_fallback_features(), true);
        return ['allowed'=>$allowed,'feature'=>$featureCode,'source'=>'migration_fallback','status'=>'legacy','plan_code'=>null,'reason'=>$allowed?'LEGACY_COMPATIBILITY':'SCHEMA_PENDING'];
    }
    if($featureCode==='hotspots.apply'&&!fs_partner_hotspot_apply_pilot_approved($pdo)){
        return ['allowed'=>false,'feature'=>$featureCode,'source'=>'pilot_gate','status'=>'pending','plan_code'=>null,'reason'=>'PILOT_PENDING'];
    }
    $subscription = fs_partner_current_subscription($pdo,$partnerId);
    if (!$subscription) {
        return ['allowed'=>false,'feature'=>$featureCode,'source'=>'subscription','status'=>'none','plan_code'=>null,'reason'=>'SUBSCRIPTION_MISSING'];
    }
    $status = (string)$subscription['status'];
    if($status==='ended'){
        if($mutation)return ['allowed'=>false,'feature'=>$featureCode,'source'=>'essential_fallback','status'=>$status,'plan_code'=>(string)$subscription['plan_code'],'reason'=>'SUBSCRIPTION_READ_ONLY'];
        $essential=$pdo->prepare("SELECT f.enabled FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='essential' AND p.active=1 AND f.feature_code=? ORDER BY p.version DESC LIMIT 1");$essential->execute([$featureCode]);$allowed=(int)($essential->fetchColumn()?:0)===1;
        return ['allowed'=>$allowed,'feature'=>$featureCode,'source'=>'essential_fallback','status'=>$status,'plan_code'=>(string)$subscription['plan_code'],'reason'=>$allowed?'ESSENTIAL_FALLBACK':'PLAN_DISABLED'];
    }
    if (!fs_partner_subscription_status_allows($status,$mutation)) {
        return ['allowed'=>false,'feature'=>$featureCode,'source'=>'subscription','status'=>$status,'plan_code'=>(string)$subscription['plan_code'],'reason'=>'SUBSCRIPTION_READ_ONLY'];
    }
    $override = fs_partner_active_feature_override($pdo,$partnerId,$featureCode);
    if ($override) {
        $allowed = (int)$override['enabled'] === 1;
        return ['allowed'=>$allowed,'feature'=>$featureCode,'source'=>'override','status'=>$status,'plan_code'=>(string)$subscription['plan_code'],'reason'=>$allowed?'OVERRIDE_ENABLED':'OVERRIDE_DISABLED'];
    }
    $statement = $pdo->prepare('SELECT enabled FROM platform_plan_features WHERE plan_id=? AND feature_code=? LIMIT 1');
    $statement->execute([(int)$subscription['plan_id'],$featureCode]);
    $allowed = (int)($statement->fetchColumn() ?: 0) === 1;
    return ['allowed'=>$allowed,'feature'=>$featureCode,'source'=>'plan','status'=>$status,'plan_code'=>(string)$subscription['plan_code'],'reason'=>$allowed?'PLAN_ENABLED':'PLAN_DISABLED'];
}

function fs_partner_has_entitlement(PDO $pdo, int $partnerId, string $featureCode, bool $mutation=false): bool
{
    return fs_partner_entitlement($pdo,$partnerId,$featureCode,$mutation)['allowed'];
}

/**
 * A entrada inicial no recebimento próprio é uma capacidade comercial do
 * plano máximo. Estabelecimentos legados que já operam de forma independente
 * continuam podendo manter a carteira existente, mas não usam esta função
 * para ativar um novo vínculo fora do plano.
 */
function fs_partner_independence_plan_allows_wallet_activation(PDO $pdo, int $partnerId, bool $mutation=false): bool
{
    $subscription=fs_partner_current_subscription($pdo,$partnerId);
    if(!$subscription||(string)($subscription['plan_code']??'')!=='multipoint_advanced')return false;
    if(!fs_partner_subscription_status_allows((string)($subscription['status']??''),$mutation))return false;
    return fs_partner_has_entitlement($pdo,$partnerId,'wallet.manage',$mutation);
}

function fs_partner_require_entitlement(PDO $pdo, int $partnerId, string $featureCode, bool $mutation=false): array
{
    $decision = fs_partner_entitlement($pdo,$partnerId,$featureCode,$mutation);
    if (!$decision['allowed']) {
        if ($decision['reason'] === 'PILOT_PENDING') {
            throw new RuntimeException('A aplicação remota aguarda a aprovação central do piloto físico.');
        }
        if ($decision['reason'] === 'SUBSCRIPTION_READ_ONLY') {
            throw new RuntimeException('O plano está em modo somente leitura. Regularize a assinatura para fazer alterações.');
        }
        throw new RuntimeException('Esta função não está incluída no plano vigente do estabelecimento.');
    }
    return $decision;
}

/** @return array<string,int> */
function fs_partner_plan_limits(PDO $pdo, int $partnerId): array
{
    $defaults = ['max_nas'=>0,'max_hotspots'=>1,'max_admin_users'=>1,'max_report_range_days'=>31,'custom_courtesy_overrides'=>0];
    $subscription = fs_partner_current_subscription($pdo,$partnerId);
    if (!$subscription) return $defaults;
    if((string)$subscription['status']==='ended'){
        $essential=$pdo->query("SELECT max_nas,max_hotspots,max_admin_users,max_report_range_days,custom_courtesy_overrides FROM platform_plans WHERE code='essential' AND active=1 ORDER BY version DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if($essential)$subscription=array_merge($subscription,$essential);
    }
    foreach ($defaults as $key=>$value) $defaults[$key]=max(0,(int)$subscription[$key]);
    foreach (array_keys($defaults) as $key) {
        $override = fs_partner_active_feature_override($pdo,$partnerId,'quota.' . $key);
        if ($override && (int)$override['enabled'] === 1 && $override['limit_value'] !== null) {
            $defaults[$key]=max(0,(int)$override['limit_value']);
        }
    }
    return $defaults;
}

function fs_partner_quota_usage(PDO $pdo, int $partnerId, string $quota): int
{
    if ($partnerId <= 0) return 0;
    if ($quota === 'max_hotspots') {
        try{$statement=$pdo->prepare("SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=? AND (active=1 OR management_state IN ('draft','queued','applying'))");}
        catch(Throwable $e){$statement=$pdo->prepare('SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=? AND active=1');}
    } elseif ($quota === 'max_admin_users') {
        $statement=$pdo->prepare("SELECT (SELECT COUNT(*) FROM partner_admin_memberships WHERE partner_id=? AND active=1)+(SELECT COUNT(*) FROM partner_admin_invitations WHERE partner_id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>NOW())");
    } elseif ($quota === 'max_nas') {
        try {$statement=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships WHERE partner_id=? AND management_mode='partner_owned' AND status<>'retired'");}
        catch (Throwable $e) {return 0;}
    } elseif ($quota === 'custom_courtesy_overrides') {
        try {$statement=$pdo->prepare("SELECT COUNT(DISTINCT hotspot_id) FROM courtesy_hotspot_policy_overrides WHERE partner_id=? AND state IN ('draft','published')");}
        catch (Throwable $e) {return 0;}
    } else {
        throw new InvalidArgumentException('Cota desconhecida.');
    }
    $params=$quota==='max_admin_users'?[$partnerId,$partnerId]:[$partnerId];
    try {$statement->execute($params);return (int)$statement->fetchColumn();}
    catch (Throwable $e) {return 0;}
}

function fs_partner_require_quota(PDO $pdo, int $partnerId, string $quota, int $additional=1): void
{
    $limits=fs_partner_plan_limits($pdo,$partnerId);
    if (!array_key_exists($quota,$limits)) throw new InvalidArgumentException('Cota desconhecida.');
    $limit=(int)$limits[$quota];
    $usage=fs_partner_quota_usage($pdo,$partnerId,$quota);
    if ($limit <= 0 || $usage + max(0,$additional) > $limit) {
        throw new RuntimeException('A cota contratada para esta função foi atingida.');
    }
}

/** @return list<array<string,mixed>> */
function fs_platform_plan_catalog(PDO $pdo, bool $includeInternal=false): array
{
    if (!fs_partner_entitlements_schema_ready($pdo)) return [];
    $sql='SELECT * FROM platform_plans WHERE active=1';
    if (!$includeInternal) $sql.=' AND internal_only=0';
    $sql.=' ORDER BY internal_only,code,version DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function fs_partner_assign_subscription(PDO $pdo, int $partnerId, string $planCode, string $status, string $reason, int $actorId): array
{
    $planCode=trim($planCode);
    $reason=trim($reason);
    if (!preg_match('/^[a-z0-9_]{2,64}$/',$planCode)) throw new InvalidArgumentException('Plano inválido.');
    if (!in_array($status,['trial','active','grace','past_due','suspended','ended'],true)) throw new InvalidArgumentException('Situação da assinatura inválida.');
    if ($reason==='') throw new InvalidArgumentException('Informe o motivo da alteração contratual.');
    if (!fs_partner_entitlements_schema_ready($pdo)) throw new RuntimeException('A migração de planos ainda não foi aplicada.');

    $ownsTransaction=!$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $partner=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
        $partner->execute([$partnerId]);
        if (!$partner->fetchColumn()) throw new RuntimeException('Estabelecimento não encontrado.');
        $plan=$pdo->prepare('SELECT * FROM platform_plans WHERE code=? AND active=1 ORDER BY version DESC LIMIT 1');
        $plan->execute([$planCode]);
        $plan=$plan->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new RuntimeException('Plano ativo não encontrado.');
        $current=$pdo->prepare('SELECT s.*,p.code plan_code FROM partner_subscriptions s JOIN platform_plans p ON p.id=s.plan_id WHERE s.partner_id=? AND s.is_current=1 LIMIT 1 FOR UPDATE');
        $current->execute([$partnerId]);
        $current=$current->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($current) $pdo->prepare('UPDATE partner_subscriptions SET is_current=0,ends_at=COALESCE(ends_at,NOW()),updated_at=NOW() WHERE id=?')->execute([(int)$current['id']]);
        $insert=$pdo->prepare('INSERT INTO partner_subscriptions (partner_id,plan_id,status,is_current,starts_at,created_by_type,created_by_id) VALUES (?,?,?,1,NOW(),\'firespot\',?)');
        $insert->execute([$partnerId,(int)$plan['id'],$status,$actorId>0?$actorId:null]);
        $subscriptionId=(int)$pdo->lastInsertId();
        $event=$pdo->prepare('INSERT INTO partner_subscription_events (partner_id,subscription_id,event_type,from_plan_code,to_plan_code,from_status,to_status,reason,actor_type,actor_id) VALUES (?,?,\'subscription_changed\',?,?,?,?,?,\'firespot\',?)');
        $event->execute([$partnerId,$subscriptionId,$current['plan_code']??null,(string)$plan['code'],$current['status']??null,$status,substr($reason,0,300),$actorId>0?$actorId:null]);
        if ($ownsTransaction) $pdo->commit();
        return ['subscription_id'=>$subscriptionId,'plan_code'=>(string)$plan['code'],'plan_name'=>(string)$plan['name'],'status'=>$status];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
