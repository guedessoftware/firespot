<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/nas_credentials.php';
require_once __DIR__ . '/nas_base_provisioning.php';
require_once __DIR__ . '/partner_hotspots.php';
require_once __DIR__ . '/partner_entitlements.php';

function fs_partner_infrastructure_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT nas_id FROM partner_nas_ownerships LIMIT 0');
        $pdo->query('SELECT id FROM hotspot_change_requests LIMIT 0');
        $pdo->query('SELECT desired_config_version FROM partner_hotspots LIMIT 0');
        return true;
    } catch (Throwable $e) {return false;}
}

function fs_partner_nas_assignment_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT partner_id,nas_id,status FROM partner_nas_assignments LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_partner_nas_display_name_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT display_name FROM partner_nas_ownerships LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_partner_hotspot_configuration_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT requested_name FROM partner_hotspot_configuration_requests LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_partner_hotspot_configuration_dns_schema_ready(PDO $pdo): bool
{
    try{$pdo->query('SELECT requested_dns_servers FROM partner_hotspot_configuration_requests LIMIT 0');return true;}
    catch(Throwable $error){return false;}
}

/** @return array<int,array<string,mixed>> */
function fs_partner_hotspot_configuration_requests(PDO $pdo, int $partnerId): array
{
    if(!fs_partner_hotspot_configuration_schema_ready($pdo)||$partnerId<=0)return [];
    $requestedDns=fs_partner_hotspot_configuration_dns_schema_ready($pdo)?'request.requested_dns_servers':'point.dns_servers requested_dns_servers';
    $statement=$pdo->prepare("SELECT request.id,request.hotspot_id,request.requested_name,request.requested_nas_id,
            request.requested_nas_interface_id,{$requestedDns},request.reason,request.revision,request.state,request.submitted_at,
            equipment.shortname requested_nas_name,interface.interface_name requested_interface_name
        FROM partner_hotspot_configuration_requests request
        JOIN partner_hotspots point ON point.id=request.hotspot_id
        JOIN nas equipment ON equipment.id=request.requested_nas_id
        JOIN nas_interfaces interface ON interface.id=request.requested_nas_interface_id AND interface.nas_id=request.requested_nas_id
        WHERE request.partner_id=? AND request.state='submitted'
        ORDER BY request.hotspot_id,request.revision DESC");
    $statement->execute([$partnerId]);$requests=[];
    foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $request)$requests[(int)$request['hotspot_id']]=$request;
    return $requests;
}

/** @return array<string,mixed> */
function fs_partner_hotspot_configuration_request_save(PDO $pdo, int $partnerId, int $hotspotId, array $input, int $actorUserId): array
{
    if(!fs_partner_hotspot_configuration_schema_ready($pdo))throw new RuntimeException('A gestão versionada dos pontos ainda não foi instalada.');
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
    $name=preg_replace('/\s+/u',' ',trim((string)($input['name']??'')))?:'';
    $nameLength=function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name);
    if($name===''||$nameLength>150)throw new InvalidArgumentException('Informe o nome do ponto com até 150 caracteres.');
    $reason=preg_replace('/\s+/u',' ',trim((string)($input['reason']??'')))?:'';
    $reasonLength=function_exists('mb_strlen')?mb_strlen($reason,'UTF-8'):strlen($reason);
    if($reasonLength>300)throw new InvalidArgumentException('A observação deve possuir no máximo 300 caracteres.');
    $nasId=(int)($input['nas_id']??0);$interfaceId=(int)($input['nas_interface_id']??0);
    if($hotspotId<=0||$nasId<=0||$interfaceId<=0)throw new InvalidArgumentException('Selecione o ponto, o NAS e a interface-base.');

    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $point=$pdo->prepare("SELECT id,nas_id,nas_interface_id,name,dns_servers,active,management_state FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE");
        $point->execute([$hotspotId,$partnerId]);$current=$point->fetch(PDO::FETCH_ASSOC);
        if(!$current)throw new RuntimeException('Ponto não encontrado neste estabelecimento.');
        $dnsParts=preg_split('/[\s,;]+/',trim((string)($input['dns_servers']??$current['dns_servers']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[];
        if(!$dnsParts)throw new InvalidArgumentException('Informe ao menos um DNS upstream para o ponto.');
        foreach($dnsParts as $dns)if(!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('Informe somente IPv4 válidos no DNS upstream.');
        $dnsServers=implode(',',$dnsParts);
        if((int)$current['active']!==1)throw new RuntimeException('Use a edição direta para um ponto que ainda está em rascunho.');
        // A propriedade do NAS atual é a fronteira que impede o cliente de
        // solicitar alterações no ponto TESTE ou em qualquer NAS FireSpot.
        fs_partner_nas_assert_owned($pdo,$partnerId,(int)$current['nas_id'],false,true);
        fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,$nasId,true);
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1 FOR UPDATE');
        $interface->execute([$interfaceId,$nasId]);if(!$interface->fetchColumn())throw new InvalidArgumentException('A interface selecionada não pertence ao NAS escolhido.');
        if($name===(string)$current['name']&&$nasId===(int)$current['nas_id']&&$interfaceId===(int)($current['nas_interface_id']??0)&&$dnsServers===(string)$current['dns_servers'])throw new RuntimeException('Nenhuma alteração foi informada para este ponto.');

        $dnsSelect=fs_partner_hotspot_configuration_dns_schema_ready($pdo)?'requested_dns_servers':'NULL requested_dns_servers';
        $latest=$pdo->prepare("SELECT revision,requested_name,requested_nas_id,requested_nas_interface_id,{$dnsSelect},COALESCE(reason,'') reason,state FROM partner_hotspot_configuration_requests WHERE hotspot_id=? ORDER BY revision DESC LIMIT 1 FOR UPDATE");
        $latest->execute([$hotspotId]);$previous=$latest->fetch(PDO::FETCH_ASSOC)?:null;
        if($previous&&$previous['state']==='submitted'&&$name===(string)$previous['requested_name']&&$nasId===(int)$previous['requested_nas_id']&&$interfaceId===(int)$previous['requested_nas_interface_id']&&(!fs_partner_hotspot_configuration_dns_schema_ready($pdo)||$dnsServers===(string)$previous['requested_dns_servers'])&&$reason===(string)$previous['reason'])throw new RuntimeException('A solicitação pendente já contém exatamente esses dados.');
        $revision=(int)($previous['revision']??0)+1;
        $pdo->prepare("UPDATE partner_hotspot_configuration_requests SET state='superseded',updated_at=NOW() WHERE partner_id=? AND hotspot_id=? AND state='submitted'")
            ->execute([$partnerId,$hotspotId]);
        if(fs_partner_hotspot_configuration_dns_schema_ready($pdo)){
            $insert=$pdo->prepare("INSERT INTO partner_hotspot_configuration_requests
                (partner_id,hotspot_id,requested_name,requested_nas_id,requested_nas_interface_id,requested_dns_servers,reason,revision,state,requested_by_user_id)
                VALUES (?,?,?,?,?,?,?,?,'submitted',?)");
            $insert->execute([$partnerId,$hotspotId,$name,$nasId,$interfaceId,$dnsServers,$reason!==''?$reason:null,$revision,$actorUserId>0?$actorUserId:null]);
        }else{
            $insert=$pdo->prepare("INSERT INTO partner_hotspot_configuration_requests
                (partner_id,hotspot_id,requested_name,requested_nas_id,requested_nas_interface_id,reason,revision,state,requested_by_user_id)
                VALUES (?,?,?,?,?,?,?,'submitted',?)");
            $insert->execute([$partnerId,$hotspotId,$name,$nasId,$interfaceId,$reason!==''?$reason:null,$revision,$actorUserId>0?$actorUserId:null]);
        }
        $requestId=(int)$pdo->lastInsertId();
        if($ownsTransaction)$pdo->commit();
        return ['id'=>$requestId,'revision'=>$revision,'hotspot_id'=>$hotspotId,'nas_id'=>$nasId,'interface_id'=>$interfaceId];
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_hotspot_configuration_request_cancel(PDO $pdo, int $partnerId, int $hotspotId): int
{
    if(!fs_partner_hotspot_configuration_schema_ready($pdo))throw new RuntimeException('A gestão versionada dos pontos ainda não foi instalada.');
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $point=$pdo->prepare('SELECT nas_id FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE');$point->execute([$hotspotId,$partnerId]);$nasId=(int)($point->fetchColumn()?:0);
        if($nasId<=0)throw new RuntimeException('Ponto não encontrado neste estabelecimento.');
        fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $request=$pdo->prepare("SELECT id FROM partner_hotspot_configuration_requests WHERE partner_id=? AND hotspot_id=? AND state='submitted' LIMIT 1 FOR UPDATE");$request->execute([$partnerId,$hotspotId]);$requestId=(int)($request->fetchColumn()?:0);
        if($requestId<=0)throw new RuntimeException('Não existe solicitação pendente para este ponto.');
        $pdo->prepare("UPDATE partner_hotspot_configuration_requests SET state='cancelled',updated_at=NOW() WHERE id=? AND partner_id=? AND state='submitted'")->execute([$requestId,$partnerId]);
        if($ownsTransaction)$pdo->commit();return $requestId;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

/**
 * Registra somente a autorização contextual criada pela Central. Não cadastra,
 * consulta, prepara ou altera o equipamento.
 */
function fs_partner_nas_assignment_ensure(PDO $pdo, int $partnerId, int $nasId, ?int $actorId = null): void
{
    if(!fs_partner_nas_assignment_schema_ready($pdo))return;
    $statement=$pdo->prepare("INSERT INTO partner_nas_assignments (partner_id,nas_id,status,assignment_source,assigned_by_id) VALUES (?,?,'ready','firespot',?) ON DUPLICATE KEY UPDATE status='ready',assignment_source=IF(partner_nas_assignments.assignment_source='partner_owned','partner_owned','firespot'),assigned_by_id=VALUES(assigned_by_id),retired_at=NULL,updated_at=NOW()");
    $statement->execute([$partnerId,$nasId,$actorId&&$actorId>0?$actorId:null]);
}

function fs_partner_nas_owned_assignment_ensure(PDO $pdo, int $partnerId, int $nasId, ?int $actorId = null): void
{
    if(!fs_partner_nas_assignment_schema_ready($pdo))throw new RuntimeException('A atribuição de NAS ainda não foi instalada.');
    $statement=$pdo->prepare("INSERT INTO partner_nas_assignments (partner_id,nas_id,status,assignment_source,assigned_by_id) VALUES (?,?,'ready','partner_owned',?) ON DUPLICATE KEY UPDATE status='ready',assignment_source='partner_owned',assigned_by_id=VALUES(assigned_by_id),retired_at=NULL,updated_at=NOW()");
    $statement->execute([$partnerId,$nasId,$actorId&&$actorId>0?$actorId:null]);
}

/** @return array<string,mixed> */
function fs_partner_nas_assert_assigned(PDO $pdo, int $partnerId, int $nasId, bool $requireReady = true, bool $forUpdate = false): array
{
    if(!fs_partner_nas_assignment_schema_ready($pdo))throw new RuntimeException('A atribuição central de NAS ainda não foi instalada.');
    $sql="SELECT a.partner_id,a.nas_id,a.status assignment_status,n.shortname,n.type,b.status base_status,b.routeros_version,b.coa_status
        FROM partner_nas_assignments a
        JOIN nas n ON n.id=a.nas_id
        LEFT JOIN nas_base_provisioning b ON b.nas_id=a.nas_id
        WHERE a.partner_id=? AND a.nas_id=? AND a.status='ready' LIMIT 1";
    if($forUpdate&&$pdo->inTransaction())$sql.=' FOR UPDATE';
    $statement=$pdo->prepare($sql);$statement->execute([$partnerId,$nasId]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('O NAS não foi atribuído a este estabelecimento pela FireSpot.');
    if($requireReady&&(string)($row['base_status']??'')!=='ready')throw new RuntimeException('A FireSpot ainda não concluiu a preparação do NAS atribuído.');
    return $row;
}

/** @return array<string,mixed> */
function fs_partner_hotspot_assert_draft_nas(PDO $pdo, int $partnerId, int $nasId, bool $forUpdate=false): array
{
    // No plano com autogestão, novos rascunhos ficam somente em NAS do
    // próprio estabelecimento. Uma atribuição central continua sustentando
    // pontos legados, mas não pode receber novas instalações por POST forjado.
    if(fs_partner_has_entitlement($pdo,$partnerId,'nas.manage',true)){
        $owned=fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,$forUpdate);
        // Criar ou editar um rascunho não acessa o RouterOS. Exigimos que a
        // base já tenha sido preparada, mas uma indisponibilidade SSH momentânea
        // não deve esconder o modal nem impedir o planejamento do novo ponto.
        if((string)($owned['base_status']??'')!=='ready')throw new RuntimeException('Prepare a base do NAS antes de criar um ponto nele.');
        return $owned;
    }
    return fs_partner_nas_assert_assigned($pdo,$partnerId,$nasId,true,$forUpdate);
}

function fs_partner_ipv4_in_cidr(string $ip, string $cidr): bool
{
    $parts=explode('/',trim($cidr),2);
    if (count($parts)!==2||!filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||!ctype_digit($parts[1])) return false;
    $prefix=(int)$parts[1];
    if ($prefix<0||$prefix>32) return false;
    $ipLong=ip2long($ip);$networkLong=ip2long($parts[0]);
    if ($ipLong===false||$networkLong===false) return false;
    $mask=$prefix===0?0:(-1 << (32-$prefix));
    return (($ipLong & $mask)===($networkLong & $mask));
}

function fs_partner_nas_validate_address(string $address): string
{
    $address=trim($address);
    if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('Informe o endereço IPv4 de gerenciamento do NAS. Nomes DNS não são aceitos para evitar redirecionamento de destino.');
    }
    $isPublic=(bool)filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    if ($isPublic) return $address;
    $allowed=array_filter(array_map('trim',explode(',',(string)env('PARTNER_NAS_ALLOWED_CIDRS',''))));
    foreach ($allowed as $cidr) if (fs_partner_ipv4_in_cidr($address,$cidr)) return $address;
    throw new InvalidArgumentException('O endereço privado do NAS não pertence às redes de gerenciamento autorizadas pela FireSpot.');
}

function fs_partner_nas_validate_port($value): int
{
    $port=(int)$value;
    if ($port<1||$port>65535) throw new InvalidArgumentException('Informe uma porta SSH entre 1 e 65535.');
    return $port;
}

function fs_partner_default_radius_server_id(PDO $pdo): int
{
    $configured=0;
    try {
        $statement=$pdo->prepare("SELECT svalue FROM app_settings WHERE skey='partner_nas_default_radius_server_id' LIMIT 1");
        $statement->execute();$configured=(int)($statement->fetchColumn()?:0);
    } catch (Throwable $e) {$configured=0;}
    if ($configured>0) {
        $statement=$pdo->prepare('SELECT id FROM radius_servers WHERE id=? LIMIT 1');$statement->execute([$configured]);
        if ($statement->fetchColumn()) return $configured;
    }
    $rows=$pdo->query('SELECT id FROM radius_servers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($rows)!==1) throw new RuntimeException('Defina o destino RADIUS padrão para os NAS cadastrados por clientes.');
    return (int)$rows[0];
}

/** @return array<string,mixed> */
function fs_partner_nas_assert_owned(PDO $pdo, int $partnerId, int $nasId, bool $requireReady=false, bool $forUpdate=false): array
{
    $sql="SELECT o.*,n.nasname,n.shortname,n.type,n.mgmt_port,b.status base_status,b.routeros_version,b.coa_status,r.host radius_host
        FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id
        LEFT JOIN nas_base_provisioning b ON b.nas_id=o.nas_id
        LEFT JOIN radius_servers r ON r.id=b.radius_server_id
        WHERE o.partner_id=? AND o.nas_id=? AND o.management_mode='partner_owned' LIMIT 1";
    if ($forUpdate&&$pdo->inTransaction()) $sql.=' FOR UPDATE';
    $statement=$pdo->prepare($sql);$statement->execute([$partnerId,$nasId]);
    $row=$statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('NAS próprio não encontrado.');
    if ((string)$row['status']==='retired') throw new RuntimeException('O NAS está aposentado.');
    if ($requireReady&&((string)$row['status']!=='ready'||(string)($row['base_status']??'')!=='ready')) {
        throw new RuntimeException('Sincronize e prepare a base do NAS antes de usá-lo em um ponto.');
    }
    return $row;
}

/** @return list<array<string,mixed>> */
function fs_partner_nas_list(PDO $pdo, int $partnerId): array
{
    if (!fs_partner_infrastructure_schema_ready($pdo)) return [];
    $hasAssignments=fs_partner_nas_assignment_schema_ready($pdo);
    $displayName=fs_partner_nas_display_name_schema_ready($pdo)
        ? "COALESCE(NULLIF(o.display_name,''),n.shortname)"
        : 'n.shortname';
    $statement=$pdo->prepare("SELECT n.id,{$displayName} shortname,n.type,
            CASE WHEN o.management_mode='partner_owned' AND o.status<>'retired' THEN 'partner_owned' ELSE 'firespot_managed' END management_mode,
            CASE WHEN o.management_mode='partner_owned' AND o.status<>'retired' THEN n.nasname ELSE NULL END management_address,
            CASE WHEN o.management_mode='partner_owned' AND o.status<>'retired' AND o.credentials_ciphertext IS NOT NULL AND o.credentials_ciphertext<>'' THEN 1 ELSE 0 END credentials_configured,
            CASE WHEN o.management_mode='partner_owned' THEN o.status ELSE NULL END ownership_status,
            ".($hasAssignments?"COALESCE(a.status,'ready')":"'ready'")." assignment_status,
            n.mgmt_port,b.status base_status,b.routeros_version,b.radius_server_id,b.config_revision,b.coa_status,b.coa_port,
            r.name radius_server_name,r.host radius_host,r.port radius_port,
            h.status health_status,h.checked_at health_checked_at,h.latency_ms,h.interface_count,h.hotspot_host_count,
            COUNT(DISTINCT ph.id) hotspot_count
        FROM nas n
        ".($hasAssignments?'LEFT JOIN partner_nas_assignments a ON a.nas_id=n.id AND a.partner_id=?':'')."
        LEFT JOIN partner_nas_ownerships o ON o.nas_id=n.id AND o.partner_id=?
        LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id
        LEFT JOIN radius_servers r ON r.id=b.radius_server_id
        LEFT JOIN nas_health h ON h.nas_id=n.id
        LEFT JOIN partner_hotspots ph ON ph.nas_id=n.id AND ph.partner_id=? AND ph.management_state<>'retired'
        WHERE ".($hasAssignments?"(a.status='ready' OR ph.id IS NOT NULL OR (o.management_mode='partner_owned' AND o.status<>'retired'))":"(ph.id IS NOT NULL OR (o.management_mode='partner_owned' AND o.status<>'retired'))")."
        GROUP BY n.id,{$displayName},n.type,n.nasname,n.mgmt_port,o.management_mode,o.status,o.credentials_ciphertext,".($hasAssignments?'a.status,':'')."b.status,b.routeros_version,b.radius_server_id,b.config_revision,b.coa_status,b.coa_port,r.name,r.host,r.port,h.status,h.checked_at,h.latency_ms,h.interface_count,h.hotspot_host_count
        ORDER BY {$displayName},n.id");
    $statement->execute($hasAssignments?[$partnerId,$partnerId,$partnerId]:[$partnerId,$partnerId]);
    $rows=$statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach($rows as &$row){
        $policy=fs_nas_hotspot_policy($pdo,(int)$row['id']);
        $row=array_merge($row,$policy);
        $next=fs_partner_hotspot_next_vlan($pdo,(int)$row['id'],false,$policy);
        $row['next_vlan']=$next;
        $row['next_network']=$next!==null?fs_partner_hotspot_network_suggestion($next,$policy):null;
    }
    unset($row);
    return $rows;
}

/** Salva somente a política local do equipamento; não acessa o RouterOS. */
function fs_partner_nas_hotspot_policy_save(PDO $pdo, int $partnerId, int $nasId, array $input, int $actorUserId): array
{
    fs_partner_require_entitlement($pdo,$partnerId,'nas.manage',true);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $policy=fs_nas_hotspot_policy_save($pdo,$nasId,$input,'partner_admin',$actorUserId);
        if($ownsTransaction)$pdo->commit();
        return $policy;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_change_request_idempotency_key(int $partnerId, int $nasId, ?int $hotspotId, string $operation, string $source): string
{
    $source=trim($source);
    if($partnerId<=0||$nasId<=0||$source===''||strlen($source)>200)throw new InvalidArgumentException('Chave de idempotência inválida.');
    return hash('sha256',implode('|',[$partnerId,$nasId,$hotspotId??0,$operation,$source]));
}

function fs_partner_change_request_enqueue(PDO $pdo, int $partnerId, int $nasId, ?int $hotspotId, string $operation, array $payload, int $actorUserId, string $idempotencySource): int
{
    if (!in_array($operation,['nas_verify','nas_sync','nas_prepare','hotspot_apply','hotspot_deactivate','hotspot_cleanup'],true)) throw new InvalidArgumentException('Operação de infraestrutura inválida.');
    $idempotency=fs_partner_change_request_idempotency_key($partnerId,$nasId,$hotspotId,$operation,$idempotencySource);
    $encoded=$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null;
    if ($payload&&!is_string($encoded)) throw new RuntimeException('Não foi possível serializar a solicitação.');
    $statement=$pdo->prepare("INSERT INTO hotspot_change_requests (partner_id,nas_id,hotspot_id,operation,payload,status,idempotency_key,requested_by_user_id) VALUES (?,?,?,?,?,'queued',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $statement->execute([$partnerId,$nasId,$hotspotId,$operation,$encoded,$idempotency,$actorUserId>0?$actorUserId:null]);
    return (int)$pdo->lastInsertId();
}

function fs_partner_nas_assert_no_competing_request(PDO $pdo, int $partnerId, int $nasId, ?string $allowedIdempotencyKey=null): ?int
{
    if(!$pdo->inTransaction())throw new LogicException('A validação concorrente do NAS exige transação.');
    if($allowedIdempotencyKey!==null){
        $same=$pdo->prepare('SELECT id FROM hotspot_change_requests WHERE partner_id=? AND nas_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
        $same->execute([$partnerId,$nasId,$allowedIdempotencyKey]);$sameId=(int)($same->fetchColumn()?:0);
        if($sameId>0)return $sameId;
    }
    $pending=$pdo->prepare("SELECT id FROM hotspot_change_requests WHERE partner_id=? AND nas_id=? AND status IN ('queued','running','retry') ORDER BY id LIMIT 1 FOR UPDATE");
    $pending->execute([$partnerId,$nasId]);
    if($pending->fetchColumn())throw new RuntimeException('Já existe uma operação pendente para este NAS. Aguarde a conclusão antes de alterar credenciais ou enviar outra ação.');
    return null;
}

/**
 * Confirma que gateway e pool pertencem à mesma rede e que o gateway não
 * pode ser distribuído pelo DHCP. Retorna o CIDR canônico reservado.
 */
function fs_partner_hotspot_assert_network_consistent(array $configuration): string
{
    return fs_partner_hotspot_network_profile($configuration)['cidr'];
}

/**
 * Reserva a configuração desejada sob o mesmo lock do ponto. Banco,
 * inventário e a restrição única protegem contra duas filas concorrentes.
 */
function fs_partner_hotspot_reserve_configuration(PDO $pdo, int $partnerId, int $hotspotId, array $configuration, array $current): string
{
    if(!$pdo->inTransaction())throw new LogicException('A reserva de rede exige transação.');
    $nasId=(int)$configuration['nas_id'];$vlanId=(int)$configuration['vlan_id'];$cidr=fs_partner_hotspot_assert_network_consistent($configuration);
    $pdo->exec("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE state='reserved' AND expires_at IS NOT NULL AND expires_at<=NOW()");

    $hotspots=$pdo->prepare('SELECT * FROM partner_hotspots WHERE nas_id=? AND id<>? AND active=1 ORDER BY id FOR UPDATE');
    $hotspots->execute([$nasId,$hotspotId]);
    foreach($hotspots->fetchAll(PDO::FETCH_ASSOC)?:[] as $other){
        if((int)$other['vlan_id']===$vlanId)throw new RuntimeException('A VLAN escolhida já pertence a outro ponto deste NAS.');
        if(fs_partner_hotspot_assert_network_consistent($other)===$cidr)throw new RuntimeException('A rede escolhida já pertence a outro ponto deste NAS.');
    }

    $reservations=$pdo->prepare("SELECT id,hotspot_id,vlan_id,network_cidr,state FROM partner_network_reservations WHERE nas_id=? AND state IN ('reserved','applied') ORDER BY id FOR UPDATE");
    $reservations->execute([$nasId]);$ownReservation=null;
    foreach($reservations->fetchAll(PDO::FETCH_ASSOC)?:[] as $reservation){
        if((int)($reservation['hotspot_id']??0)===$hotspotId&&(int)$reservation['vlan_id']===$vlanId){$ownReservation=$reservation;continue;}
        if((int)$reservation['vlan_id']===$vlanId)throw new RuntimeException('A VLAN escolhida está reservada por outra operação.');
        if((string)$reservation['network_cidr']===$cidr&&(int)($reservation['hotspot_id']??0)!==$hotspotId)throw new RuntimeException('A rede escolhida está reservada por outra operação.');
    }

    $sameEffective=(int)($current['hotspot_active']??0)===1&&(int)($current['nas_id']??0)===$nasId&&(int)($current['vlan_id']??0)===$vlanId;
    if(!$sameEffective){
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE nas_id=? AND vlan_id=? LIMIT 1 FOR UPDATE');$interface->execute([$nasId,$vlanId]);
        if($interface->fetchColumn())throw new RuntimeException('A VLAN escolhida já existe no inventário do NAS. Sincronize o equipamento e escolha outra VLAN.');
    }

    if($ownReservation){
        $pdo->prepare("UPDATE partner_network_reservations SET partner_id=?,network_cidr=?,state='reserved',expires_at=DATE_ADD(NOW(),INTERVAL 2 DAY),updated_at=NOW() WHERE id=?")
            ->execute([$partnerId,$cidr,(int)$ownReservation['id']]);
    }else{
        $pdo->prepare("INSERT INTO partner_network_reservations (partner_id,nas_id,hotspot_id,vlan_id,network_cidr,state,expires_at) VALUES (?,?,?,?,?,'reserved',DATE_ADD(NOW(),INTERVAL 2 DAY))")
            ->execute([$partnerId,$nasId,$hotspotId,$vlanId,$cidr]);
    }
    return $cidr;
}

function fs_partner_nas_register(PDO $pdo, int $partnerId, array $input, int $actorUserId, string $idempotencySource): array
{
    if (!fs_partner_infrastructure_schema_ready($pdo)) throw new RuntimeException('A migração de infraestrutura ainda não foi aplicada.');
    fs_partner_require_entitlement($pdo,$partnerId,'nas.manage',true);
    fs_partner_require_quota($pdo,$partnerId,'max_nas',1);
    $name=trim((string)($input['name']??''));
    if ($name===''||strlen($name)>32) throw new InvalidArgumentException('Informe um nome de NAS com até 32 caracteres.');
    $address=fs_partner_nas_validate_address((string)($input['address']??''));
    $port=fs_partner_nas_validate_port($input['port']??22);
    $username=trim((string)($input['username']??''));
    $password=(string)($input['password']??'');
    $radiusSecret=(string)($input['radius_secret']??'');
    $ciphertext=fs_nas_credentials_encrypt($username,$password,$radiusSecret);
    $radiusServerId=fs_partner_default_radius_server_id($pdo);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM partners WHERE id=? AND active=1 LIMIT 1 FOR UPDATE');$lock->execute([$partnerId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
        fs_partner_require_quota($pdo,$partnerId,'max_nas',1);
        $duplicate=$pdo->prepare('SELECT id FROM nas WHERE nasname=? LIMIT 1 FOR UPDATE');$duplicate->execute([$address]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('Este endereço já está cadastrado no inventário FireSpot.');
        $shortname=substr('P'.$partnerId.'-'.preg_replace('/[^A-Za-z0-9_-]+/','-',$name),0,32);
        $insert=$pdo->prepare("INSERT INTO nas (nasname,shortname,type,secret,mgmt_username,mgmt_password,mgmt_port,description) VALUES (?,?, 'mikrotik', ?, ?, NULL, ?, ?)");
        // Um NAS ainda não preparado não pode autenticar no FreeRADIUS com
        // o segredo escolhido pelo estabelecimento. O valor efetivo permanece
        // somente no envelope criptografado e fs_nas_base_provision() o promove
        // localmente apenas depois de configurar e validar o mesmo segredo no
        // RouterOS. O placeholder aleatório nunca é revelado ao estabelecimento.
        $pendingRadiusSecret='pending-'.bin2hex(random_bytes(24));
        $insert->execute([$address,$shortname,$pendingRadiusSecret,$username,$port,'NAS próprio do estabelecimento #'.$partnerId]);
        $nasId=(int)$pdo->lastInsertId();
        if(fs_partner_nas_display_name_schema_ready($pdo)){
            $pdo->prepare("INSERT INTO partner_nas_ownerships (nas_id,partner_id,management_mode,status,credentials_ciphertext,credential_hint,display_name,created_by_type,created_by_id) VALUES (?,?,'partner_owned','pending',?,?,?, 'partner_admin',?)")
                ->execute([$nasId,$partnerId,$ciphertext,fs_credential_hint($password),$name,$actorUserId>0?$actorUserId:null]);
        }else{
            $pdo->prepare("INSERT INTO partner_nas_ownerships (nas_id,partner_id,management_mode,status,credentials_ciphertext,credential_hint,created_by_type,created_by_id) VALUES (?,?,'partner_owned','pending',?,?, 'partner_admin',?)")
                ->execute([$nasId,$partnerId,$ciphertext,fs_credential_hint($password),$actorUserId>0?$actorUserId:null]);
        }
        fs_partner_nas_owned_assignment_ensure($pdo,$partnerId,$nasId,$actorUserId);
        $pdo->prepare("INSERT INTO nas_base_provisioning (nas_id,radius_server_id,status) VALUES (?,?,'pending')")->execute([$nasId,$radiusServerId]);
        if(fs_nas_hotspot_policy_schema_ready($pdo))fs_nas_hotspot_policy_save($pdo,$nasId,fs_nas_hotspot_policy_default(),'partner_admin',$actorUserId);
        $requestId=fs_partner_change_request_enqueue($pdo,$partnerId,$nasId,null,'nas_verify',[],$actorUserId,$idempotencySource);
        if($ownsTransaction)$pdo->commit();
        return ['nas_id'=>$nasId,'request_id'=>$requestId,'status'=>'pending'];
    } catch (Throwable $e) {if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_nas_update_credentials(PDO $pdo, int $partnerId, int $nasId, array $input, int $actorUserId, string $idempotencySource): int
{
    fs_partner_require_entitlement($pdo,$partnerId,'nas.manage',true);
    $name=trim((string)($input['name']??''));
    if($name===''||strlen($name)>80)throw new InvalidArgumentException('Informe um nome de NAS com até 80 caracteres.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try {
        $ownership=fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $requestedAddress=trim((string)($input['address']??''));
        $address=hash_equals((string)$ownership['nasname'],$requestedAddress)&&filter_var($requestedAddress,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
            ? $requestedAddress
            : fs_partner_nas_validate_address($requestedAddress);
        $port=fs_partner_nas_validate_port($input['port']??22);
        $ciphertext=fs_nas_credentials_encrypt(trim((string)($input['username']??'')),(string)($input['password']??''),(string)($input['radius_secret']??''));
        $idempotencyKey=fs_partner_change_request_idempotency_key($partnerId,$nasId,null,'nas_verify',$idempotencySource);
        $existingId=fs_partner_nas_assert_no_competing_request($pdo,$partnerId,$nasId,$idempotencyKey);
        if($existingId!==null){if($ownsTransaction)$pdo->commit();return $existingId;}
        $currentCiphertext=trim((string)($ownership['credentials_ciphertext']??''));
        $currentCredentials=$currentCiphertext!==''?fs_nas_credentials_decrypt($currentCiphertext):null;
        $radiusChanged=$currentCredentials===null||!hash_equals((string)$currentCredentials['radius_secret'],(string)$input['radius_secret']);
        $duplicate=$pdo->prepare('SELECT id FROM nas WHERE nasname=? AND id<>? LIMIT 1 FOR UPDATE');$duplicate->execute([$address,$nasId]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('Este endereço já pertence a outro NAS.');
        $addressChanged=strcasecmp((string)$ownership['nasname'],$address)!==0;
        if($addressChanged){
            $active=$pdo->prepare('SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=? AND nas_id=? AND active=1');$active->execute([$partnerId,$nasId]);
            if((int)$active->fetchColumn()>0)throw new RuntimeException('Migre ou desative os pontos ativos antes de alterar o endereço do NAS.');
        }
        $resetHostKey=$addressChanged;
        // O shared secret efetivo do FreeRADIUS só é promovido depois que a
        // preparação remota confirma o novo valor no RouterOS.
        $pdo->prepare('UPDATE nas SET nasname=?,mgmt_username=?,mgmt_password=NULL,mgmt_port=? WHERE id=?')->execute([$address,trim((string)$input['username']),$port,$nasId]);
        $displayAssignment=fs_partner_nas_display_name_schema_ready($pdo)?'display_name=?,':'';
        $parameters=fs_partner_nas_display_name_schema_ready($pdo)
            ? [$name,$ciphertext,fs_credential_hint((string)$input['password']),$resetHostKey?1:0,$nasId,$partnerId]
            : [$ciphertext,fs_credential_hint((string)$input['password']),$resetHostKey?1:0,$nasId,$partnerId];
        $pdo->prepare("UPDATE partner_nas_ownerships SET {$displayAssignment}status='pending',credentials_ciphertext=?,credential_hint=?,host_key_fingerprint=IF(?=1,NULL,host_key_fingerprint),credential_version=credential_version+1,last_verified_at=NULL,updated_at=NOW() WHERE nas_id=? AND partner_id=?")
            ->execute($parameters);
        if($radiusChanged)$pdo->prepare("UPDATE nas_base_provisioning SET status='pending',last_error_code=NULL,last_error_detail=NULL WHERE nas_id=?")->execute([$nasId]);
        $requestId=fs_partner_change_request_enqueue($pdo,$partnerId,$nasId,null,'nas_verify',[],$actorUserId,$idempotencySource);
        if($ownsTransaction)$pdo->commit();return $requestId;
    } catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_nas_queue_operation(PDO $pdo, int $partnerId, int $nasId, string $operation, int $actorUserId, string $idempotencySource): int
{
    if(!in_array($operation,['nas_sync','nas_prepare'],true))throw new InvalidArgumentException('Operação de NAS não permitida por este fluxo.');
    $feature=$operation==='nas_prepare'?'nas.prepare':'nas.manage';
    fs_partner_require_entitlement($pdo,$partnerId,$feature,true);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $idempotencyKey=fs_partner_change_request_idempotency_key($partnerId,$nasId,null,$operation,$idempotencySource);
        $existingId=fs_partner_nas_assert_no_competing_request($pdo,$partnerId,$nasId,$idempotencyKey);
        if($existingId!==null){if($ownsTransaction)$pdo->commit();return $existingId;}
        $requestId=fs_partner_change_request_enqueue($pdo,$partnerId,$nasId,null,$operation,[],$actorUserId,$idempotencySource);
        if($ownsTransaction)$pdo->commit();return $requestId;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_nas_retire(PDO $pdo, int $partnerId, int $nasId, int $actorUserId): void
{
    fs_partner_require_entitlement($pdo,$partnerId,'nas.retire',true);
    $pdo->beginTransaction();
    try {
        fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $active=$pdo->prepare('SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=? AND nas_id=? AND active=1');$active->execute([$partnerId,$nasId]);
        if ((int)$active->fetchColumn()>0) throw new RuntimeException('Desative ou migre todos os pontos ativos antes de aposentar este NAS.');
        $pending=$pdo->prepare("SELECT COUNT(*) FROM hotspot_change_requests WHERE partner_id=? AND nas_id=? AND status IN ('queued','running','retry')");$pending->execute([$partnerId,$nasId]);
        if ((int)$pending->fetchColumn()>0) throw new RuntimeException('Aguarde as operações pendentes deste NAS.');
        $pdo->prepare("UPDATE partner_nas_ownerships SET status='retired',credentials_ciphertext=NULL,host_key_fingerprint=NULL,retired_at=NOW(),updated_at=NOW() WHERE partner_id=? AND nas_id=?")->execute([$partnerId,$nasId]);
        if(fs_partner_nas_assignment_schema_ready($pdo))$pdo->prepare("UPDATE partner_nas_assignments SET status='retired',retired_at=NOW(),updated_at=NOW() WHERE partner_id=? AND nas_id=? AND assignment_source='partner_owned'")->execute([$partnerId,$nasId]);
        $pdo->prepare('UPDATE nas SET secret=?,mgmt_username=NULL,mgmt_password=NULL WHERE id=?')->execute(['retired-'.bin2hex(random_bytes(12)),$nasId]);
        $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE partner_id=? AND nas_id=? AND state='reserved'")->execute([$partnerId,$nasId]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** @return list<array<string,mixed>> */
function fs_partner_change_requests(PDO $pdo, int $partnerId, int $limit=30, string $scope='all'): array
{
    if (!fs_partner_infrastructure_schema_ready($pdo)) return [];
    $limit=max(1,min(100,$limit));
    if(!in_array($scope,['all','nas','hotspots'],true))throw new InvalidArgumentException('Escopo de histórico inválido.');
    $scopeSql=$scope==='nas'?" AND LEFT(operation,4)='nas_'":($scope==='hotspots'?" AND LEFT(operation,8)='hotspot_'":'');
    $statement=$pdo->prepare('SELECT id,nas_id,hotspot_id,operation,status,attempt_count,max_attempts,error_code,error_detail,created_at,started_at,finished_at FROM hotspot_change_requests WHERE partner_id=?'.$scopeSql.' ORDER BY id DESC LIMIT '.$limit);
    $statement->execute([$partnerId]);return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_partner_hotspot_draft_create(PDO $pdo, int $partnerId, array $input): int
{
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
    fs_partner_require_quota($pdo,$partnerId,'max_hotspots',1);
    $nasId=(int)($input['nas_id']??0);$interfaceId=(int)($input['nas_interface_id']??0);
    $name=trim((string)($input['name']??''));if($name===''||strlen($name)>150)throw new InvalidArgumentException('Informe o nome do ponto.');
    $pdo->beginTransaction();
    try {
        $partner=$pdo->prepare('SELECT code,active FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$partner->execute([$partnerId]);$partner=$partner->fetch(PDO::FETCH_ASSOC);
        if(!$partner||(int)$partner['active']!==1)throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
        fs_partner_require_quota($pdo,$partnerId,'max_hotspots',1);
        fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,$nasId,true);
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1 FOR UPDATE');$interface->execute([$interfaceId,$nasId]);
        if(!$interface->fetchColumn())throw new InvalidArgumentException('Selecione uma interface sincronizada deste NAS.');
        $policy=fs_nas_hotspot_policy($pdo,$nasId,true);
        $vlan=fs_partner_hotspot_next_vlan($pdo,$nasId,true,$policy);if($vlan===null)throw new RuntimeException('Não há VLAN automática disponível na faixa deste NAS.');
        $network=fs_partner_hotspot_network_suggestion($vlan,$policy);
        $code=fs_partner_hotspot_suggest_code($pdo,(string)$partner['code'],$name);
        $radiusHost=fs_nas_base_radius_host($pdo,$nasId);
        $dnsName=strtolower($code.'.hotspot.internal');
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $insert=$pdo->prepare("INSERT INTO partner_hotspots (partner_id,code,name,nas_id,nas_interface_id,vlan_id,network_prefix_length,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,1,0,'draft')");
            $insert->execute([$partnerId,$code,$name,$nasId,$interfaceId,$vlan,$network['network_prefix_length'],$network['gateway_ip'],$network['pool_start'],$network['pool_end'],$network['dns_servers'],$dnsName,$radiusHost]);
        }else{
            $insert=$pdo->prepare("INSERT INTO partner_hotspots (partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,0,0,1,0,'draft')");
            $insert->execute([$partnerId,$code,$name,$nasId,$interfaceId,$vlan,$network['gateway_ip'],$network['pool_start'],$network['pool_end'],$network['dns_servers'],$dnsName,$radiusHost]);
        }
        $hotspotId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO partner_network_reservations (partner_id,nas_id,hotspot_id,vlan_id,network_cidr,state,expires_at) VALUES (?,?,?,?,?,'reserved',DATE_ADD(NOW(),INTERVAL 7 DAY))")
            ->execute([$partnerId,$nasId,$hotspotId,$vlan,$network['cidr']]);
        $pdo->commit();return $hotspotId;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_hotspot_draft_update(PDO $pdo, int $partnerId, int $hotspotId, array $input): void
{
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
    $name=trim((string)($input['name']??''));$nasId=(int)($input['nas_id']??0);$interfaceId=(int)($input['nas_interface_id']??0);
    if($name===''||strlen($name)>150)throw new InvalidArgumentException('Informe o nome do ponto.');
    $pdo->beginTransaction();
    try{
        $partnerLock=$pdo->prepare('SELECT code FROM partners WHERE id=? AND active=1 LIMIT 1 FOR UPDATE');$partnerLock->execute([$partnerId]);
        if(!$partnerLock->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
        $lock=$pdo->prepare("SELECT * FROM partner_hotspots WHERE id=? AND partner_id=? AND active=0 AND management_state='draft' AND applied_config_version=0 LIMIT 1 FOR UPDATE");$lock->execute([$hotspotId,$partnerId]);$draft=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$draft)throw new RuntimeException('Somente um rascunho ainda não aplicado pode ser editado por esta ação.');
        fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,$nasId,true);
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1 FOR UPDATE');$interface->execute([$interfaceId,$nasId]);
        if(!$interface->fetchColumn())throw new InvalidArgumentException('Selecione uma interface sincronizada do NAS escolhido.');
        $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);if(!$current)throw new RuntimeException('Rascunho não encontrado.');
        if((int)$draft['nas_id']===$nasId){
            $configuration=['gateway_ip'=>$draft['gateway_ip'],'pool_start'=>$draft['pool_start'],'pool_end'=>$draft['pool_end'],'network_prefix_length'=>$draft['network_prefix_length']??null,'dns_servers'=>$draft['dns_servers'],'nas_id'=>$nasId,'vlan_id'=>$draft['vlan_id']];
        }else{
            $policy=fs_nas_hotspot_policy($pdo,$nasId,true);
            $vlan=fs_partner_hotspot_next_vlan($pdo,$nasId,true,$policy);if($vlan===null)throw new RuntimeException('Não há VLAN automática disponível na faixa deste NAS.');
            $network=fs_partner_hotspot_network_suggestion($vlan,$policy);
            $configuration=['gateway_ip'=>$network['gateway_ip'],'pool_start'=>$network['pool_start'],'pool_end'=>$network['pool_end'],'network_prefix_length'=>$network['network_prefix_length'],'dns_servers'=>$network['dns_servers'],'nas_id'=>$nasId,'vlan_id'=>$vlan];
        }
        fs_partner_hotspot_reserve_configuration($pdo,$partnerId,$hotspotId,$configuration,$current);
        if((int)$draft['nas_id']!==$nasId)$pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND nas_id=? AND state='reserved'")->execute([$hotspotId,(int)$draft['nas_id']]);
        $radiusHost=fs_nas_base_radius_host($pdo,$nasId);
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,radius_ip=?,desired_config_version=desired_config_version+1,updated_at=NOW() WHERE id=? AND partner_id=? AND active=0 AND management_state='draft'")
                ->execute([$name,$nasId,$interfaceId,(int)$configuration['vlan_id'],(int)$configuration['network_prefix_length'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$radiusHost,$hotspotId,$partnerId]);
        }else{
            $pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,radius_ip=?,desired_config_version=desired_config_version+1,updated_at=NOW() WHERE id=? AND partner_id=? AND active=0 AND management_state='draft'")
                ->execute([$name,$nasId,$interfaceId,(int)$configuration['vlan_id'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$radiusHost,$hotspotId,$partnerId]);
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_hotspot_draft_discard(PDO $pdo, int $partnerId, int $hotspotId): void
{
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);$pdo->beginTransaction();
    try{
        $partnerLock=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$partnerLock->execute([$partnerId]);if(!$partnerLock->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado.');
        $lock=$pdo->prepare("SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? AND is_default=0 AND active=0 AND management_state='draft' AND applied_config_version=0 LIMIT 1 FOR UPDATE");$lock->execute([$hotspotId,$partnerId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Somente um rascunho secundário ainda não aplicado pode ser descartado.');
        $pdo->prepare("UPDATE partner_hotspots SET management_state='retired',updated_at=NOW() WHERE id=? AND partner_id=?")->execute([$hotspotId,$partnerId]);
        $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND partner_id=? AND state='reserved'")->execute([$hotspotId,$partnerId]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fs_partner_hotspot_queue_apply(PDO $pdo, int $partnerId, int $hotspotId, array $input, int $actorUserId, string $idempotencySource): int
{
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.apply',true);
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE');$lock->execute([$hotspotId,$partnerId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Ponto não encontrado.');
        $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);if(!$current)throw new RuntimeException('Ponto não encontrado.');
        if((int)$current['hotspot_active']===1){
            $currentOwnership=$pdo->prepare("SELECT 1 FROM partner_nas_ownerships WHERE partner_id=? AND nas_id=? AND management_mode='partner_owned' AND status<>'retired' LIMIT 1 FOR UPDATE");$currentOwnership->execute([$partnerId,(int)$current['nas_id']]);
            if(!$currentOwnership->fetchColumn())throw new RuntimeException('Este ponto ainda usa um NAS gerenciado pela FireSpot. A migração inicial para NAS próprio precisa ser acompanhada pela Central.');
        }
        $nasId=(int)($input['nas_id']??$current['nas_id']??0);fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,true,true);
        $idempotencyKey=fs_partner_change_request_idempotency_key($partnerId,$nasId,$hotspotId,'hotspot_apply',$idempotencySource);
        $existing=$pdo->prepare('SELECT id FROM hotspot_change_requests WHERE partner_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');$existing->execute([$partnerId,$idempotencyKey]);
        $existingId=(int)($existing->fetchColumn()?:0);
        if($existingId>0){$pdo->commit();return $existingId;}
        $pending=$pdo->prepare("SELECT id FROM hotspot_change_requests WHERE partner_id=? AND hotspot_id=? AND operation IN ('hotspot_apply','hotspot_deactivate') AND status IN ('queued','running','retry') ORDER BY id LIMIT 1 FOR UPDATE");
        $pending->execute([$partnerId,$hotspotId]);
        if($pending->fetchColumn())throw new RuntimeException('Já existe uma alteração de rede pendente para este ponto. Aguarde a conclusão antes de enviar outra.');
        $payload=fs_partner_hotspot_validate_payload($pdo,[
            'code'=>$current['hotspot_code'],'name'=>$input['name']??$current['hotspot_name'],'nas_id'=>$nasId,
            'nas_interface_id'=>$input['nas_interface_id']??$current['nas_interface_id'],'vlan_id'=>$input['vlan_id']??$current['vlan_id'],
            'network_prefix_length'=>$input['network_prefix_length']??$current['network_prefix_length']??null,
            'gateway_ip'=>$input['gateway_ip']??$current['gateway_ip'],'pool_start'=>$input['pool_start']??$current['pool_start'],'pool_end'=>$input['pool_end']??$current['pool_end'],
            'dns_servers'=>$input['dns_servers']??$current['dns_servers'],'dns_name'=>$input['dns_name']??$current['dns_name'],
            'radius_ip'=>fs_nas_base_radius_host($pdo,$nasId),'active'=>1,
        ]);
        fs_partner_hotspot_assert_operational($payload);
        fs_partner_hotspot_reserve_configuration($pdo,$partnerId,$hotspotId,$payload,$current);
        $configurationVersion=max(1,(int)($current['desired_config_version']??0)+1);
        $payload['configuration_version']=$configurationVersion;
        $requestId=fs_partner_change_request_enqueue($pdo,$partnerId,$nasId,$hotspotId,'hotspot_apply',['configuration'=>$payload],$actorUserId,$idempotencySource);
        $pdo->prepare("UPDATE partner_hotspots SET desired_config_version=?,management_state='queued',last_change_request_id=?,updated_at=NOW() WHERE id=? AND partner_id=?")
            ->execute([$configurationVersion,$requestId,$hotspotId,$partnerId]);
        $pdo->commit();return $requestId;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_partner_hotspot_queue_deactivate(PDO $pdo, int $partnerId, int $hotspotId, int $actorUserId, string $idempotencySource): int
{
    fs_partner_require_entitlement($pdo,$partnerId,'hotspots.apply',true);$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1 FOR UPDATE');$lock->execute([$hotspotId,$partnerId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Ponto não encontrado.');
        $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);
        if(!$current||(int)$current['hotspot_active']!==1)throw new RuntimeException('O ponto já está inativo.');
        if((int)$current['hotspot_is_default']===1)throw new RuntimeException('O ponto principal não pode ser desativado pelo portal.');
        $nasId=(int)$current['nas_id'];fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,false,true);
        $idempotencyKey=fs_partner_change_request_idempotency_key($partnerId,$nasId,$hotspotId,'hotspot_deactivate',$idempotencySource);
        $existing=$pdo->prepare('SELECT id FROM hotspot_change_requests WHERE partner_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');$existing->execute([$partnerId,$idempotencyKey]);$existingId=(int)($existing->fetchColumn()?:0);
        if($existingId>0){$pdo->commit();return $existingId;}
        $pending=$pdo->prepare("SELECT id FROM hotspot_change_requests WHERE partner_id=? AND hotspot_id=? AND operation IN ('hotspot_apply','hotspot_deactivate') AND status IN ('queued','running','retry') ORDER BY id LIMIT 1 FOR UPDATE");$pending->execute([$partnerId,$hotspotId]);
        if($pending->fetchColumn())throw new RuntimeException('Já existe uma alteração de rede pendente para este ponto.');
        $configurationVersion=max(1,(int)($current['desired_config_version']??0)+1);
        $requestId=fs_partner_change_request_enqueue($pdo,$partnerId,$nasId,$hotspotId,'hotspot_deactivate',['configuration_version'=>$configurationVersion],$actorUserId,$idempotencySource);
        $pdo->prepare("UPDATE partner_hotspots SET desired_config_version=?,management_state='queued',last_change_request_id=?,updated_at=NOW() WHERE id=? AND partner_id=?")->execute([$configurationVersion,$requestId,$hotspotId,$partnerId]);
        $pdo->commit();return $requestId;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
