<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/partner_entitlements.php';

/** @return list<string> */
function fs_partner_courtesy_commercial_fields(): array
{
    return ['enabled','grant_minutes','credit_validity_minutes','consumption_mode','auth_mode','device_max_grants','device_period_minutes','account_max_grants','account_period_minutes','cooldown_after_end_minutes'];
}

/** @return array<string,array<string,mixed>> */
function fs_partner_courtesy_presets(): array
{
    return [
        'balanced'=>[
            'label'=>'Equilibrado diário','description'=>'30 minutos, uma vez por dispositivo a cada 24 horas e nova tentativa 24 horas após terminar.',
            'enabled'=>'1','grant_minutes'=>'30','credit_validity_minutes'=>'1440','consumption_mode'=>'online','auth_mode'=>'anonymous',
            'device_max_grants'=>'1','device_period_minutes'=>'1440','account_max_grants'=>'','account_period_minutes'=>'','cooldown_after_end_minutes'=>'1440',
        ],
        'frequent'=>[
            'label'=>'Visita frequente','description'=>'60 minutos, até duas concessões por dispositivo em 24 horas e intervalo de 6 horas após terminar.',
            'enabled'=>'1','grant_minutes'=>'60','credit_validity_minutes'=>'1440','consumption_mode'=>'online','auth_mode'=>'anonymous',
            'device_max_grants'=>'2','device_period_minutes'=>'1440','account_max_grants'=>'','account_period_minutes'=>'','cooldown_after_end_minutes'=>'360',
        ],
        'weekly'=>[
            'label'=>'Benefício semanal','description'=>'60 minutos corridos, uma vez por conta a cada 7 dias e intervalo de 7 dias após terminar.',
            'enabled'=>'1','grant_minutes'=>'60','credit_validity_minutes'=>'10080','consumption_mode'=>'elapsed','auth_mode'=>'account',
            'device_max_grants'=>'','device_period_minutes'=>'','account_max_grants'=>'1','account_period_minutes'=>'10080','cooldown_after_end_minutes'=>'10080',
        ],
    ];
}

function fs_partner_courtesy_preset(string $code): array
{
    $presets=fs_partner_courtesy_presets();
    if(!isset($presets[$code]))throw new InvalidArgumentException('Preset de cortesia inválido.');
    $preset=$presets[$code];unset($preset['label'],$preset['description']);
    return $preset;
}

function fs_partner_courtesy_schema_ready(PDO $pdo): bool
{
    try{$pdo->query('SELECT id FROM courtesy_policy_revisions LIMIT 0');$pdo->query('SELECT id FROM courtesy_hotspot_policy_overrides LIMIT 0');return true;}
    catch(Throwable $e){return false;}
}

function fs_partner_courtesy_lock_partner(PDO $pdo, int $partnerId): void
{
    $lock=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
    $lock->execute([$partnerId]);
    if(!$lock->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado.');
}

function fs_partner_courtesy_same_commercial_policy(array $left, array $right): bool
{
    $left=fs_courtesy_policy_normalize($left);$right=fs_courtesy_policy_normalize($right);
    foreach(fs_partner_courtesy_commercial_fields() as $field)if($left[$field]!==$right[$field])return false;
    return true;
}

/**
 * Materializa no histórico comercial uma alteração efetiva feita pela Central.
 * Rascunhos do estabelecimento são preservados e usuários da Central não são
 * gravados nas FKs de host_users.
 *
 * @return array<string,mixed>|null
 */
function fs_partner_courtesy_record_effective_revision(PDO $pdo, int $partnerId): ?array
{
    if(!fs_partner_courtesy_schema_ready($pdo))return null;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        $effective=fs_courtesy_policy_resolve($pdo,$partnerId);
        $publishedStatement=$pdo->prepare("SELECT * FROM courtesy_policy_revisions WHERE partner_id=? AND state='published' ORDER BY revision DESC LIMIT 1 FOR UPDATE");
        $publishedStatement->execute([$partnerId]);$published=$publishedStatement->fetch(PDO::FETCH_ASSOC)?:null;
        if($published&&fs_partner_courtesy_same_commercial_policy($published,$effective)){
            if($owns)$pdo->commit();
            return $published;
        }
        $next=$pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM courtesy_policy_revisions WHERE partner_id=?');$next->execute([$partnerId]);$revision=max(1,(int)$next->fetchColumn());
        if($published)$pdo->prepare("UPDATE courtesy_policy_revisions SET state='superseded',updated_at=NOW() WHERE id=? AND partner_id=? AND state='published'")->execute([(int)$published['id'],$partnerId]);
        $columns=fs_partner_courtesy_commercial_fields();$values=[];foreach($columns as $field)$values[]=$effective[$field];
        $sql="INSERT INTO courtesy_policy_revisions (partner_id,revision,state,".implode(',',$columns).",published_at) VALUES (?,?,'published',".implode(',',array_fill(0,count($columns),'?')).',NOW())';
        $pdo->prepare($sql)->execute(array_merge([$partnerId,$revision],$values));
        $id=(int)$pdo->lastInsertId();
        if($owns)$pdo->commit();
        $row=$pdo->prepare('SELECT * FROM courtesy_policy_revisions WHERE id=? AND partner_id=? LIMIT 1');$row->execute([$id,$partnerId]);
        return $row->fetch(PDO::FETCH_ASSOC)?:null;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** @return array<string,mixed> */
function fs_partner_courtesy_save_central(PDO $pdo, int $partnerId, array $values, bool $syncLegacy=true): array
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        $saved=fs_courtesy_policy_save($pdo,$partnerId,$values,$syncLegacy);
        fs_partner_courtesy_record_effective_revision($pdo,$partnerId);
        if($owns)$pdo->commit();
        return $saved;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_courtesy_sync_legacy_central(PDO $pdo, int $partnerId): bool
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        if(!fs_courtesy_sync_legacy_partner($pdo,$partnerId)){
            if($owns)$pdo->commit();
            return false;
        }
        fs_partner_courtesy_record_effective_revision($pdo,$partnerId);
        if($owns)$pdo->commit();
        return true;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_courtesy_input(array $input, array $base): array
{
    $result=$base;
    $result['enabled']=isset($input['enabled'])?1:0;
    foreach(['grant_minutes','credit_validity_minutes','device_period_minutes','account_period_minutes','cooldown_after_end_minutes'] as $field){
        if(array_key_exists($field,$input))$result[$field]=$input[$field]===''?null:(int)$input[$field];
    }
    foreach(['device_max_grants','account_max_grants'] as $field)if(array_key_exists($field,$input))$result[$field]=$input[$field]===''?null:(int)$input[$field];
    foreach(['consumption_mode','auth_mode'] as $field)if(array_key_exists($field,$input))$result[$field]=(string)$input[$field];
    $normalized=fs_courtesy_policy_normalize($result);$errors=fs_courtesy_policy_validate($normalized);
    if($errors)throw new InvalidArgumentException(implode('; ',$errors));
    return $normalized;
}

/** @return array{policy:array<string,mixed>,groups:list<string>,values:array<string,mixed>} */
function fs_partner_courtesy_override_input(array $input, array $base): array
{
    $available=fs_courtesy_override_group_fields();$requested=$input['override_groups']??[];
    if(!is_array($requested))$requested=[];$groups=[];foreach($requested as $group)if(isset($available[$group])&&!in_array($group,$groups,true))$groups[]=$group;
    if(!$groups)throw new InvalidArgumentException('Selecione ao menos um grupo para sobrescrever neste ponto.');
    $normalizedInput=$input;
    if(!in_array('enabled',$groups,true)){if((int)$base['enabled']===1)$normalizedInput['enabled']='1';else unset($normalizedInput['enabled']);}
    $policy=fs_partner_courtesy_input($normalizedInput,$base);$values=array_fill_keys(fs_partner_courtesy_commercial_fields(),null);
    foreach($groups as $group)foreach($available[$group] as $field)$values[$field]=$policy[$field];
    return ['policy'=>$policy,'groups'=>$groups,'values'=>$values];
}

/** @return array<string,mixed>|null */
function fs_partner_courtesy_revision(PDO $pdo, int $partnerId, string $state): ?array
{
    if(!in_array($state,['draft','published'],true)||!fs_partner_courtesy_schema_ready($pdo))return null;
    $statement=$pdo->prepare('SELECT * FROM courtesy_policy_revisions WHERE partner_id=? AND state=? ORDER BY revision DESC LIMIT 1');$statement->execute([$partnerId,$state]);$row=$statement->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

/** @return list<array<string,mixed>> */
function fs_partner_courtesy_history(PDO $pdo, int $partnerId, int $limit=20): array
{
    if(!fs_partner_courtesy_schema_ready($pdo))return [];$limit=max(1,min(100,$limit));
    $statement=$pdo->prepare("SELECT * FROM courtesy_policy_revisions WHERE partner_id=? AND state IN ('published','superseded') ORDER BY revision DESC LIMIT ".$limit);
    $statement->execute([$partnerId]);return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_partner_courtesy_restore_draft(PDO $pdo, int $partnerId, int $revisionId, int $actorUserId): array
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.manage',true);
    $statement=$pdo->prepare("SELECT * FROM courtesy_policy_revisions WHERE id=? AND partner_id=? AND state IN ('published','superseded') LIMIT 1");
    $statement->execute([$revisionId,$partnerId]);$source=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$source)throw new RuntimeException('Revisão histórica de cortesia não encontrada.');
    $input=[];foreach(fs_partner_courtesy_commercial_fields() as $field){if($field==='enabled'){if((int)$source[$field]===1)$input[$field]='1';continue;}$input[$field]=$source[$field]===null?'':(string)$source[$field];}
    $draft=fs_partner_courtesy_save_draft($pdo,$partnerId,$input,$actorUserId);$draft['restored_from_revision']=(int)$source['revision'];return $draft;
}

function fs_partner_courtesy_save_draft(PDO $pdo, int $partnerId, array $input, int $actorUserId): array
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.manage',true);
    if(!fs_partner_courtesy_schema_ready($pdo))throw new RuntimeException('A migração de versões da cortesia ainda não foi aplicada.');
    $pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        $effective=fs_courtesy_policy_resolve($pdo,$partnerId);$policy=fs_partner_courtesy_input($input,$effective);
        $draft=fs_partner_courtesy_revision($pdo,$partnerId,'draft');
        $values=[];foreach(fs_partner_courtesy_commercial_fields() as $field)$values[]=$policy[$field];
        if($draft){$sql='UPDATE courtesy_policy_revisions SET '.implode('=?,',fs_partner_courtesy_commercial_fields()).'=?,updated_at=NOW() WHERE id=? AND partner_id=?';$values[]=(int)$draft['id'];$values[]=$partnerId;$pdo->prepare($sql)->execute($values);$id=(int)$draft['id'];}
        else{$next=$pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM courtesy_policy_revisions WHERE partner_id=?');$next->execute([$partnerId]);$revision=(int)$next->fetchColumn();$columns=fs_partner_courtesy_commercial_fields();$sql='INSERT INTO courtesy_policy_revisions (partner_id,revision,state,'.implode(',',$columns).',created_by_user_id) VALUES (?,?,\'draft\','.implode(',',array_fill(0,count($columns),'?')).',?)';$pdo->prepare($sql)->execute(array_merge([$partnerId,$revision],$values,[$actorUserId>0?$actorUserId:null]));$id=(int)$pdo->lastInsertId();}
        $pdo->commit();return fs_partner_courtesy_revision($pdo,$partnerId,'draft')??['id'=>$id];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_courtesy_publish(PDO $pdo, int $partnerId, int $actorUserId): array
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.manage',true);$pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        $statement=$pdo->prepare("SELECT * FROM courtesy_policy_revisions WHERE partner_id=? AND state='draft' LIMIT 1 FOR UPDATE");$statement->execute([$partnerId]);$draft=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$draft)throw new RuntimeException('Não há rascunho de cortesia para publicar.');
        $current=fs_courtesy_policy_resolve($pdo,$partnerId);foreach(fs_partner_courtesy_commercial_fields() as $field)$current[$field]=$draft[$field];
        fs_courtesy_policy_save($pdo,$partnerId,$current,true);
        $pdo->prepare("UPDATE courtesy_policy_revisions SET state='superseded',updated_at=NOW() WHERE partner_id=? AND state='published'")->execute([$partnerId]);
        $pdo->prepare("UPDATE courtesy_policy_revisions SET state='published',published_by_user_id=?,published_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=? AND state='draft'")->execute([$actorUserId>0?$actorUserId:null,(int)$draft['id'],$partnerId]);
        $pdo->commit();return fs_courtesy_policy_resolve($pdo,$partnerId);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** @return list<array<string,mixed>> */
function fs_partner_courtesy_overrides(PDO $pdo, int $partnerId): array
{
    if(!fs_partner_courtesy_schema_ready($pdo))return [];
    $statement=$pdo->prepare("SELECT o.*,h.name hotspot_name,h.code hotspot_code FROM courtesy_hotspot_policy_overrides o JOIN partner_hotspots h ON h.id=o.hotspot_id AND h.partner_id=o.partner_id WHERE o.partner_id=? AND o.state IN ('draft','published') ORDER BY h.name,o.state");$statement->execute([$partnerId]);return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_partner_courtesy_override_save_draft(PDO $pdo, int $partnerId, int $hotspotId, array $input, int $actorUserId): array
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',true);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        fs_partner_courtesy_lock_partner($pdo,$partnerId);
        $lock=$pdo->prepare('SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? AND active=1 LIMIT 1 FOR UPDATE');$lock->execute([$hotspotId,$partnerId]);if(!$lock->fetchColumn())throw new RuntimeException('Ponto ativo não encontrado neste estabelecimento.');
        $base=fs_courtesy_policy_resolve($pdo,$partnerId);$overrideInput=fs_partner_courtesy_override_input($input,$base);$mask=implode(',',$overrideInput['groups']);
        $existing=$pdo->prepare("SELECT * FROM courtesy_hotspot_policy_overrides WHERE partner_id=? AND hotspot_id=? AND state='draft' LIMIT 1 FOR UPDATE");$existing->execute([$partnerId,$hotspotId]);$draft=$existing->fetch(PDO::FETCH_ASSOC);
        $any=$pdo->prepare("SELECT COUNT(*) FROM courtesy_hotspot_policy_overrides WHERE partner_id=? AND hotspot_id=? AND state IN ('draft','published')");$any->execute([$partnerId,$hotspotId]);if((int)$any->fetchColumn()===0)fs_partner_require_quota($pdo,$partnerId,'custom_courtesy_overrides',1);
        $fields=fs_partner_courtesy_commercial_fields();$values=[];foreach($fields as $field)$values[]=$overrideInput['values'][$field];
        if($draft){$sql='UPDATE courtesy_hotspot_policy_overrides SET '.implode('=?,',$fields).'=?,override_mask=?,updated_at=NOW() WHERE id=? AND partner_id=?';$values[]=$mask;$values[]=(int)$draft['id'];$values[]=$partnerId;$pdo->prepare($sql)->execute($values);$id=(int)$draft['id'];}
        else{$next=$pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM courtesy_hotspot_policy_overrides WHERE partner_id=? AND hotspot_id=?');$next->execute([$partnerId,$hotspotId]);$revision=(int)$next->fetchColumn();$sql='INSERT INTO courtesy_hotspot_policy_overrides (partner_id,hotspot_id,revision,state,'.implode(',',$fields).',override_mask,created_by_user_id) VALUES (?,?,?,\'draft\','.implode(',',array_fill(0,count($fields),'?')).',?,?)';$pdo->prepare($sql)->execute(array_merge([$partnerId,$hotspotId,$revision],$values,[$mask,$actorUserId>0?$actorUserId:null]));$id=(int)$pdo->lastInsertId();}
        if($ownsTransaction)$pdo->commit();return ['id'=>$id,'hotspot_id'=>$hotspotId,'state'=>'draft'];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_courtesy_override_publish(PDO $pdo, int $partnerId, int $hotspotId, int $actorUserId): void
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',true);$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{fs_partner_courtesy_lock_partner($pdo,$partnerId);$hotspot=$pdo->prepare('SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? AND active=1 LIMIT 1 FOR UPDATE');$hotspot->execute([$hotspotId,$partnerId]);if(!$hotspot->fetchColumn())throw new RuntimeException('Ponto ativo não encontrado neste estabelecimento.');$statement=$pdo->prepare("SELECT id FROM courtesy_hotspot_policy_overrides WHERE partner_id=? AND hotspot_id=? AND state='draft' LIMIT 1 FOR UPDATE");$statement->execute([$partnerId,$hotspotId]);$id=(int)($statement->fetchColumn()?:0);if($id<=0)throw new RuntimeException('Não há rascunho deste ponto para publicar.');$pdo->prepare("UPDATE courtesy_hotspot_policy_overrides SET state='superseded',updated_at=NOW() WHERE partner_id=? AND hotspot_id=? AND state='published'")->execute([$partnerId,$hotspotId]);$pdo->prepare("UPDATE courtesy_hotspot_policy_overrides SET state='published',published_by_user_id=?,published_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=?")->execute([$actorUserId>0?$actorUserId:null,$id,$partnerId]);if($ownsTransaction)$pdo->commit();}
    catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_courtesy_override_retire(PDO $pdo, int $partnerId, int $hotspotId): void
{
    fs_partner_require_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',true);$pdo->beginTransaction();
    try{fs_partner_courtesy_lock_partner($pdo,$partnerId);$hotspot=$pdo->prepare('SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE');$hotspot->execute([$hotspotId,$partnerId]);if(!$hotspot->fetchColumn())throw new RuntimeException('O ponto não pertence ao estabelecimento.');$statement=$pdo->prepare("UPDATE courtesy_hotspot_policy_overrides SET state=IF(state='draft','discarded','retired'),updated_at=NOW() WHERE partner_id=? AND hotspot_id=? AND state IN ('draft','published')");$statement->execute([$partnerId,$hotspotId]);if($statement->rowCount()<1)throw new RuntimeException('Override ativo não encontrado.');$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
