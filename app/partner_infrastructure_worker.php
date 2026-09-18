<?php

declare(strict_types=1);

require_once __DIR__ . '/partner_infrastructure.php';
require_once __DIR__ . '/nas_sync.php';
require_once __DIR__ . '/partner_admin.php';

function fs_partner_infrastructure_worker_token(): string
{
    $bytes=random_bytes(16);
    $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
}

function fs_partner_infrastructure_safe_error_code(Throwable $error): string
{
    if ($error instanceof RosHostKeyException) return 'SSH_HOST_KEY_CHANGED';
    if ($error instanceof FsNasBaseProvisioningException) return substr($error->errorCodeName(),0,64);
    if ($error instanceof InvalidArgumentException) return 'VALIDATION_FAILED';
    if ($error instanceof RuntimeException) return 'OPERATION_FAILED';
    return 'UNEXPECTED_FAILURE';
}

function fs_partner_infrastructure_safe_error_detail(string $code): string
{
    if($code==='VALIDATION_FAILED')return 'A configuração foi recusada pela validação de segurança. Revise os campos e tente novamente.';
    if($code==='WORKER_TIMEOUT')return 'A execução anterior foi interrompida e atingiu o limite de tentativas.';
    if(str_contains($code,'HOST_KEY'))return 'A chave SSH do NAS mudou ou ainda não foi confirmada. A operação foi bloqueada para revisão de segurança.';
    if(str_contains($code,'AUTH'))return 'O NAS recusou a autenticação. Atualize as credenciais e execute uma nova verificação.';
    if(str_contains($code,'VERSION'))return 'A versão do RouterOS não foi aceita para esta operação.';
    if(str_contains($code,'CONNECTION')||str_contains($code,'TIMEOUT'))return 'O NAS não respondeu dentro das condições seguras. Verifique a conectividade de gerenciamento.';
    if($code==='OPERATION_FAILED')return 'O equipamento não confirmou a operação. A configuração anterior foi preservada quando aplicável.';
    return 'A operação não pôde ser concluída. Use o código seguro para acionar o suporte FireSpot.';
}

/** @return array{fingerprint:string,nas:array<string,mixed>} */
function fs_partner_nas_verify_host_key(PDO $pdo, int $partnerId, int $nasId): array
{
    $ownership=fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,false);
    $statement=$pdo->prepare('SELECT * FROM nas WHERE id=? LIMIT 1');$statement->execute([$nasId]);
    $nas=$statement->fetch(PDO::FETCH_ASSOC);if(!$nas)throw new RuntimeException('NAS não encontrado.');
    $nas=fs_nas_credentials_for_operation($pdo,$nas);$connection=fs_nas_base_connection($nas,true);
    if (!function_exists('ssh2_connect')||!function_exists('ssh2_fingerprint')) throw new RuntimeException('A extensão SSH2 não está disponível no worker.');
    $session=@ssh2_connect($connection['host'],$connection['port']);
    if(!$session)throw new RuntimeException('Não foi possível abrir a conexão SSH com o NAS.');
    $fingerprint=ros_ssh2_host_key_fingerprint($session);
    $stored=trim((string)($ownership['host_key_fingerprint']??''));
    if($stored!==''&&!hash_equals(ros_normalize_host_key_fingerprint($stored),ros_normalize_host_key_fingerprint($fingerprint)))throw new RosHostKeyException('A chave SSH do NAS mudou. A operação foi bloqueada para revisão de segurança.');
    // TOFU controlado: fixa a chave antes de qualquer nova conexão. Assim,
    // sincronização, preparação, comandos e SFTP validam exatamente a chave
    // observada nesta conexão inicial, mesmo se a sincronização falhar.
    $pin=$pdo->prepare("UPDATE partner_nas_ownerships SET host_key_fingerprint=?,last_verified_at=NOW(),updated_at=NOW() WHERE partner_id=? AND nas_id=? AND management_mode='partner_owned' AND status<>'retired'");
    $pin->execute([$fingerprint,$partnerId,$nasId]);
    $confirm=$pdo->prepare("SELECT host_key_fingerprint FROM partner_nas_ownerships WHERE partner_id=? AND nas_id=? AND management_mode='partner_owned' AND status<>'retired' LIMIT 1");
    $confirm->execute([$partnerId,$nasId]);$pinned=(string)($confirm->fetchColumn()?:'');
    if($pinned===''||!hash_equals(ros_normalize_host_key_fingerprint($fingerprint),ros_normalize_host_key_fingerprint($pinned)))throw new RuntimeException('Não foi possível fixar a chave SSH do NAS.');
    unset($session);
    return ['fingerprint'=>$fingerprint,'nas'=>$nas];
}

/** @return array<string,mixed>|null */
function fs_partner_infrastructure_claim(PDO $pdo): ?array
{
    $token=fs_partner_infrastructure_worker_token();
    $pdo->beginTransaction();
    try {
        $row=$pdo->query("SELECT * FROM hotspot_change_requests WHERE status IN ('queued','retry') AND available_at<=NOW() ORDER BY id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->commit();return null;}
        $statement=$pdo->prepare("UPDATE hotspot_change_requests SET status='running',worker_token=?,attempt_count=attempt_count+1,started_at=NOW(),error_code=NULL,error_detail=NULL,updated_at=NOW() WHERE id=? AND status IN ('queued','retry')");
        $statement->execute([$token,(int)$row['id']]);
        if($statement->rowCount()!==1){$pdo->rollBack();return null;}
        $pdo->commit();$row['worker_token']=$token;$row['attempt_count']=(int)$row['attempt_count']+1;return $row;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_infrastructure_restore_failed_reservation(PDO $pdo, array $request): void
{
    if((string)($request['operation']??'')!=='hotspot_apply'||empty($request['hotspot_id']))return;
    $payload=json_decode((string)($request['payload']??''),true);$configuration=is_array($payload)?($payload['configuration']??null):null;
    if(!is_array($configuration))return;
    $partnerId=(int)$request['partner_id'];$hotspotId=(int)$request['hotspot_id'];$targetNas=(int)($configuration['nas_id']??0);$targetVlan=(int)($configuration['vlan_id']??0);
    if($targetNas<=0||$targetVlan<=0)return;
    $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);
    if($current&&(int)$current['hotspot_active']===1&&(int)$current['nas_id']===$targetNas&&(int)$current['vlan_id']===$targetVlan){
        try{$cidr=fs_partner_hotspot_assert_network_consistent($current);$pdo->prepare("UPDATE partner_network_reservations SET network_cidr=?,state='applied',expires_at=NULL,updated_at=NOW() WHERE partner_id=? AND nas_id=? AND hotspot_id=? AND vlan_id=? AND state='reserved'")->execute([$cidr,$partnerId,$targetNas,$hotspotId,$targetVlan]);}catch(Throwable $ignored){}
        return;
    }
    if($current&&(int)$current['hotspot_active']===1){
        $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE partner_id=? AND nas_id=? AND hotspot_id=? AND vlan_id=? AND state='reserved'")->execute([$partnerId,$targetNas,$hotspotId,$targetVlan]);
    }else{
        $pdo->prepare("UPDATE partner_network_reservations SET expires_at=DATE_ADD(NOW(),INTERVAL 7 DAY),updated_at=NOW() WHERE partner_id=? AND nas_id=? AND hotspot_id=? AND vlan_id=? AND state='reserved'")->execute([$partnerId,$targetNas,$hotspotId,$targetVlan]);
    }
}

/** Recupera claims abandonados sem reiniciar uma operação ainda ativa. */
function fs_partner_infrastructure_recover_stale(PDO $pdo, int $staleMinutes=15): int
{
    $staleMinutes=max(13,min(120,$staleMinutes));$pdo->beginTransaction();$count=0;
    try{
        $rows=$pdo->query("SELECT * FROM hotspot_change_requests WHERE status='running' AND started_at<DATE_SUB(NOW(),INTERVAL {$staleMinutes} MINUTE) ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $row){
            $retry=(int)$row['attempt_count']<(int)$row['max_attempts'];$status=$retry?'retry':'failed';$detail=fs_partner_infrastructure_safe_error_detail('WORKER_TIMEOUT');
            $pdo->prepare("UPDATE hotspot_change_requests SET status=?,available_at=NOW(),finished_at=IF(?='failed',NOW(),NULL),worker_token=NULL,error_code='WORKER_TIMEOUT',error_detail=?,updated_at=NOW() WHERE id=? AND status='running'")->execute([$status,$status,$detail,(int)$row['id']]);
            if((string)$row['operation']==='nas_prepare')$pdo->prepare("UPDATE partner_nas_ownerships SET status=?,updated_at=NOW() WHERE partner_id=? AND nas_id=? AND status<>'retired'")->execute([$retry?'verified':'error',(int)$row['partner_id'],(int)$row['nas_id']]);
            elseif((string)$row['operation']==='nas_verify'&&!$retry)$pdo->prepare("UPDATE partner_nas_ownerships SET status='error',updated_at=NOW() WHERE partner_id=? AND nas_id=? AND status<>'retired'")->execute([(int)$row['partner_id'],(int)$row['nas_id']]);
            elseif(!$retry&&!empty($row['hotspot_id'])&&(string)$row['operation']!=='hotspot_cleanup')$pdo->prepare("UPDATE partner_hotspots SET management_state='error',updated_at=NOW() WHERE partner_id=? AND id=?")->execute([(int)$row['partner_id'],(int)$row['hotspot_id']]);
            if(!$retry)fs_partner_infrastructure_restore_failed_reservation($pdo,$row);$count++;
        }
        $pdo->commit();return $count;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_infrastructure_defer_claim(PDO $pdo, array $request): void
{
    $statement=$pdo->prepare("UPDATE hotspot_change_requests SET status='retry',available_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE),attempt_count=GREATEST(attempt_count-1,0),worker_token=NULL,started_at=NULL,updated_at=NOW() WHERE id=? AND worker_token=? AND status='running'");
    $statement->execute([(int)$request['id'],(string)$request['worker_token']]);
}

function fs_partner_infrastructure_complete(PDO $pdo, array $request): void
{
    $statement=$pdo->prepare("UPDATE hotspot_change_requests SET status='succeeded',finished_at=NOW(),worker_token=NULL,error_code=NULL,error_detail=NULL,updated_at=NOW() WHERE id=? AND worker_token=? AND status='running'");
    $statement->execute([(int)$request['id'],(string)$request['worker_token']]);
    if($statement->rowCount()!==1)throw new RuntimeException('A posse da solicitação operacional foi perdida.');
}

function fs_partner_infrastructure_fail(PDO $pdo, array $request, Throwable $error): void
{
    $attempt=(int)$request['attempt_count'];$max=(int)$request['max_attempts'];$retry=$attempt<$max;
    $status=$retry?'retry':'failed';$delay=min(30,max(1,$attempt*$attempt*2));
    $code=fs_partner_infrastructure_safe_error_code($error);
    $detail=fs_partner_infrastructure_safe_error_detail($code);
    $statement=$pdo->prepare("UPDATE hotspot_change_requests SET status=?,available_at=IF(?=1,DATE_ADD(NOW(),INTERVAL ? MINUTE),available_at),finished_at=IF(?=1,NULL,NOW()),worker_token=NULL,error_code=?,error_detail=?,updated_at=NOW() WHERE id=? AND worker_token=? AND status='running'");
    $statement->execute([$status,$retry?1:0,$delay,$retry?1:0,$code,$detail,(int)$request['id'],(string)$request['worker_token']]);
    if((string)$request['operation']==='nas_verify'){
        $pdo->prepare("UPDATE partner_nas_ownerships SET status='error',updated_at=NOW() WHERE partner_id=? AND nas_id=? AND status<>'retired'")->execute([(int)$request['partner_id'],(int)$request['nas_id']]);
    }elseif((string)$request['operation']==='nas_prepare'){
        $nextStatus=$retry?'verified':'error';$pdo->prepare("UPDATE partner_nas_ownerships SET status=?,updated_at=NOW() WHERE partner_id=? AND nas_id=? AND status<>'retired'")->execute([$nextStatus,(int)$request['partner_id'],(int)$request['nas_id']]);
    }elseif(!$retry&&$request['hotspot_id']&&(string)$request['operation']!=='hotspot_cleanup'){
        $pdo->prepare("UPDATE partner_hotspots SET management_state='error',updated_at=NOW() WHERE partner_id=? AND id=?")->execute([(int)$request['partner_id'],(int)$request['hotspot_id']]);
    }
    if(!$retry)fs_partner_infrastructure_restore_failed_reservation($pdo,$request);
}

/**
 * Processa uma solicitação. Handlers injetáveis permitem provar fila,
 * isolamento e transições sem acessar um equipamento real.
 * @param array<string,callable> $handlers
 */
function fs_partner_infrastructure_process_one(PDO $pdo, array $handlers=[]): ?array
{
    if(!fs_partner_infrastructure_schema_ready($pdo))throw new RuntimeException('A migração de infraestrutura ainda não foi aplicada.');
    fs_partner_infrastructure_recover_stale($pdo);
    $request=fs_partner_infrastructure_claim($pdo);if(!$request)return null;
    $partnerId=(int)$request['partner_id'];$nasId=(int)$request['nas_id'];$operation=(string)$request['operation'];
    $lockName='firespot:nas:'.$nasId;
    try{$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);}
    catch(Throwable $lockError){fs_partner_infrastructure_defer_claim($pdo,$request);throw $lockError;}
    if((int)$lock->fetchColumn()!==1){fs_partner_infrastructure_defer_claim($pdo,$request);return ['id'=>(int)$request['id'],'operation'=>$operation,'status'=>'deferred','result'=>[]];}
    try {
        fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,false);
        $feature=['nas_verify'=>'nas.manage','nas_sync'=>'nas.manage','nas_prepare'=>'nas.prepare','hotspot_apply'=>'hotspots.apply','hotspot_deactivate'=>'hotspots.apply'][$operation]??null;
        if($feature!==null)fs_partner_require_entitlement($pdo,$partnerId,$feature,true);
        if(isset($handlers[$operation])){
            $result=$handlers[$operation]($pdo,$request);
        }elseif($operation==='nas_verify'){
            $pdo->prepare("UPDATE partner_nas_ownerships SET status='verifying',updated_at=NOW() WHERE partner_id=? AND nas_id=?")->execute([$partnerId,$nasId]);
            $verified=fs_partner_nas_verify_host_key($pdo,$partnerId,$nasId);
            $result=fs_nas_sync($pdo,$nasId);
            $pdo->prepare("UPDATE partner_nas_ownerships o LEFT JOIN nas_base_provisioning b ON b.nas_id=o.nas_id SET o.status=IF(b.status='ready','ready','verified'),o.last_synced_at=NOW(),o.updated_at=NOW() WHERE o.partner_id=? AND o.nas_id=? AND o.status<>'retired'")
                ->execute([$partnerId,$nasId]);
        }elseif($operation==='nas_sync'){
            fs_partner_nas_verify_host_key($pdo,$partnerId,$nasId);
            $result=fs_nas_sync($pdo,$nasId);
            $pdo->prepare("UPDATE partner_nas_ownerships o LEFT JOIN nas_base_provisioning b ON b.nas_id=o.nas_id SET o.status=IF(b.status='ready','ready','verified'),o.last_synced_at=NOW(),o.updated_at=NOW() WHERE o.partner_id=? AND o.nas_id=? AND o.status<>'retired'")->execute([$partnerId,$nasId]);
        }elseif($operation==='nas_prepare'){
            $ownership=fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,false);
            if(!in_array((string)$ownership['status'],['verified','ready'],true))throw new RuntimeException('Verifique o NAS antes de preparar a base.');
            fs_partner_nas_verify_host_key($pdo,$partnerId,$nasId);
            $pdo->prepare("UPDATE partner_nas_ownerships SET status='preparing',updated_at=NOW() WHERE partner_id=? AND nas_id=?")->execute([$partnerId,$nasId]);
            $result=fs_nas_base_provision($pdo,$nasId);
            $pdo->prepare("UPDATE partner_nas_ownerships SET status='ready',last_verified_at=NOW(),updated_at=NOW() WHERE partner_id=? AND nas_id=?")->execute([$partnerId,$nasId]);
        }elseif(in_array($operation,['hotspot_apply','hotspot_deactivate','hotspot_cleanup'],true)){
            fs_partner_nas_verify_host_key($pdo,$partnerId,$nasId);
            if(!function_exists('fs_hotspot_apply_change_request'))require_once __DIR__ . '/hotspot_apply.php';
            $result=fs_hotspot_apply_change_request($pdo,$request);
        }else throw new RuntimeException('Operação não reconhecida pelo worker.');
        fs_partner_infrastructure_complete($pdo,$request);
        try{partner_admin_audit($pdo,$partnerId,'system',null,$operation.'.succeeded',$request['hotspot_id']?'partner_hotspot':'nas',$request['hotspot_id']?:$nasId,['request_id'=>(int)$request['id']]);}catch(Throwable $auditError){error_log('[partner infrastructure audit] request_id='.(int)$request['id'].' status=failed');}
        return ['id'=>(int)$request['id'],'operation'=>$operation,'status'=>'succeeded','result'=>$result??[]];
    }catch(Throwable $error){
        fs_partner_infrastructure_fail($pdo,$request,$error);
        try{partner_admin_audit($pdo,$partnerId,'system',null,$operation.'.failed',$request['hotspot_id']?'partner_hotspot':'nas',$request['hotspot_id']?:$nasId,['request_id'=>(int)$request['id'],'error_code'=>fs_partner_infrastructure_safe_error_code($error)]);}catch(Throwable $ignored){}
        error_log('[partner infrastructure] request_id='.(int)$request['id'].' code='.fs_partner_infrastructure_safe_error_code($error).' class='.get_class($error));
        throw $error;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}
    }
}
