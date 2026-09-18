<?php

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/lib/routeros.php';
require_once __DIR__ . '/nas_base_provisioning.php';

function fs_nas_health_schema_require(PDO $pdo): void
{
    runtime_schema_require($pdo,'nas_health',[
        'nas_id','status','checked_at','latency_ms','message','uptime','routeros_version',
        'board_model','cpu_load','memory_free','memory_total','interface_count',
        'hotspot_host_count','ppp_active_count','hotspot_server_count','vlan_count','radius_hotspot_count','firespot_radius_count',
        'last_success_at','last_error_at','error_detail'
    ]);
}

function fs_nas_health_record(PDO $pdo, int $nasId, array $data): void
{
    fs_nas_health_schema_require($pdo);
    $sql = "INSERT INTO nas_health
      (nas_id,status,checked_at,latency_ms,message,uptime,routeros_version,board_model,cpu_load,memory_free,memory_total,
       interface_count,hotspot_host_count,ppp_active_count,hotspot_server_count,vlan_count,radius_hotspot_count,firespot_radius_count,
       temperature,voltage,last_success_at,last_error_at,error_detail)
      VALUES
      (:nas_id,:status,:checked_at,:latency_ms,:message,:uptime,:routeros_version,:board_model,:cpu_load,:memory_free,:memory_total,
       :interface_count,:hotspot_host_count,:ppp_active_count,:hotspot_server_count,:vlan_count,:radius_hotspot_count,:firespot_radius_count,
       :temperature,:voltage,:last_success_at,:last_error_at,:error_detail)
      ON DUPLICATE KEY UPDATE
       status=VALUES(status),checked_at=VALUES(checked_at),latency_ms=VALUES(latency_ms),message=VALUES(message),
       uptime=COALESCE(VALUES(uptime),uptime),routeros_version=COALESCE(VALUES(routeros_version),routeros_version),
       board_model=COALESCE(VALUES(board_model),board_model),cpu_load=COALESCE(VALUES(cpu_load),cpu_load),
       memory_free=COALESCE(VALUES(memory_free),memory_free),memory_total=COALESCE(VALUES(memory_total),memory_total),
       interface_count=COALESCE(VALUES(interface_count),interface_count),
       hotspot_host_count=CASE WHEN VALUES(status)='ok' THEN VALUES(hotspot_host_count) ELSE hotspot_host_count END,
       ppp_active_count=CASE WHEN VALUES(status)='ok' THEN VALUES(ppp_active_count) ELSE ppp_active_count END,
       hotspot_server_count=CASE WHEN VALUES(status)='ok' THEN VALUES(hotspot_server_count) ELSE hotspot_server_count END,
       vlan_count=CASE WHEN VALUES(status)='ok' THEN VALUES(vlan_count) ELSE vlan_count END,
       radius_hotspot_count=CASE WHEN VALUES(status)='ok' THEN VALUES(radius_hotspot_count) ELSE radius_hotspot_count END,
       firespot_radius_count=CASE WHEN VALUES(status)='ok' THEN VALUES(firespot_radius_count) ELSE firespot_radius_count END,
       temperature=COALESCE(VALUES(temperature),temperature),voltage=COALESCE(VALUES(voltage),voltage),
       last_success_at=CASE WHEN VALUES(status)='ok' THEN VALUES(checked_at) ELSE last_success_at END,
       last_error_at=CASE WHEN VALUES(status)='error' THEN VALUES(checked_at) ELSE last_error_at END,
       error_detail=CASE WHEN VALUES(status)='ok' THEN NULL ELSE VALUES(error_detail) END";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':nas_id'=>$nasId,':status'=>$data['status'],':checked_at'=>$data['checked_at'],
        ':latency_ms'=>$data['latency_ms']??null,':message'=>$data['message']??null,
        ':uptime'=>$data['uptime']??null,':routeros_version'=>$data['routeros_version']??null,
        ':board_model'=>$data['board_model']??null,':cpu_load'=>$data['cpu_load']??null,
        ':memory_free'=>$data['memory_free']??null,':memory_total'=>$data['memory_total']??null,
        ':interface_count'=>$data['interface_count']??null,':hotspot_host_count'=>$data['hotspot_host_count']??null,
        ':ppp_active_count'=>$data['ppp_active_count']??null,
        ':hotspot_server_count'=>$data['hotspot_server_count']??null,':vlan_count'=>$data['vlan_count']??null,
        ':radius_hotspot_count'=>$data['radius_hotspot_count']??null,':firespot_radius_count'=>$data['firespot_radius_count']??null,
        ':temperature'=>$data['temperature']??null,':voltage'=>$data['voltage']??null,
        ':last_success_at'=>$data['last_success_at']??null,':last_error_at'=>$data['last_error_at']??null,
        ':error_detail'=>$data['error_detail']??null,
    ]);
}

function fs_nas_ppp_schema_require(PDO $pdo): void
{
    runtime_schema_require($pdo,'nas_ppp_active_sessions',[
        'id','nas_id','session_key','username','service','caller_id','address','uptime','session_id','synced_at'
    ]);
    runtime_schema_require($pdo,'nas_interfaces',['interface_type']);
}

function fs_routeros_parse_assignments(string $line): array
{
    $line = trim($line);
    if ($line === '') return [];
    $line = preg_replace('/^\d+\s+[A-Z ]*\s*/','',$line);
    preg_match_all('/([A-Za-z0-9\-]+)=(("[^"]*")|\S+)/',$line,$matches,PREG_SET_ORDER);
    $out = [];
    foreach ($matches as $match) {
        $value = $match[2];
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value,-1) === '"') $value = substr($value,1,-1);
        $out[$match[1]] = $value;
    }
    return $out;
}

function fs_routeros_parse_document(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/',$raw) ?: [] as $line) {
        $assignments = fs_routeros_parse_assignments($line);
        if ($assignments) {
            $out = array_merge($out,$assignments);
            continue;
        }
        // Menus de item único, como `/system resource print`, usam
        // `propriedade: valor` em vez do formato `propriedade=valor`.
        if (preg_match('/^\s*([A-Za-z0-9-]+)\s*:\s*(.*?)\s*$/',(string)$line,$match)) {
            $out[$match[1]] = $match[2];
        }
    }
    return $out;
}

function fs_routeros_interface_flags(string $line): array
{
    $line = trim($line);
    if (!preg_match('/^\d+\s+((?:[A-Z]\s+)*)(?=[A-Za-z0-9-]+=)/',$line,$match)) return [];
    $flags = preg_split('/\s+/',trim((string)$match[1])) ?: [];
    return array_values(array_filter($flags,static fn(string $flag): bool => $flag !== ''));
}

function fs_routeros_interface_is_dynamic(array $entry, array $flags): bool
{
    if (in_array('D',$flags,true)) return true;
    $name = trim((string)($entry['name'] ?? ''));
    $type = strtolower(trim((string)($entry['type'] ?? '')));
    if ($name !== '' && $name[0] === '<' && substr($name,-1) === '>') return true;
    return in_array($type,['pppoe-in','pptp-in','l2tp-in','sstp-in','ovpn-in'],true);
}

function fs_nas_sync_latency(string $host): ?float
{
    $ping = trim((string)shell_exec('command -v ping'));
    if ($ping === '') return null;
    $output = shell_exec(sprintf('%s -c 2 -n -W 2 %s 2>&1',escapeshellcmd($ping),escapeshellarg($host)));
    if (!is_string($output)) return null;
    if (preg_match('/=\s*[0-9.]+\/([0-9.]+)\/[0-9.]+\/[0-9.]+\s*ms/i',$output,$match)) return (float)$match[1];
    if (preg_match('/time[=<]([0-9.]+)\s*ms/i',$output,$match)) return (float)$match[1];
    return null;
}

function fs_nas_sync_interfaces(PDO $pdo, int $nasId, array $interfaces): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,interface_name FROM nas_interfaces WHERE nas_id=? FOR UPDATE');
        $st->execute([$nasId]);
        $existing = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $existing[(string)$row['interface_name']] = (int)$row['id'];

        $upsert = $pdo->prepare('INSERT INTO nas_interfaces (nas_id,interface_name,interface_type,vlan_id,ip_address,mac_address,description)
          VALUES (?,?,?,NULL,NULL,?,?) ON DUPLICATE KEY UPDATE interface_type=VALUES(interface_type),mac_address=VALUES(mac_address),description=VALUES(description),updated_at=NOW()');
        $seen = [];
        $created = 0;
        $updated = 0;
        foreach ($interfaces as $interface) {
            $name = trim((string)($interface['name'] ?? ''));
            if ($name === '' || strlen($name) > 64 || isset($seen[$name])) continue;
            $seen[$name] = true;
            $upsert->execute([$nasId,$name,mb_substr(trim((string)($interface['type'] ?? '')) ?: 'other',0,32),$interface['mac'] ?: null,$interface['comment'] ?: null]);
            isset($existing[$name]) ? $updated++ : $created++;
        }

        $removed = 0;
        $retained = 0;
        $used = $pdo->prepare('SELECT
          (SELECT COUNT(*) FROM partners WHERE nas_interface_id=?) +
          (SELECT COUNT(*) FROM partner_hotspots WHERE nas_interface_id=?)');
        $delete = $pdo->prepare('DELETE FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1');
        foreach ($existing as $name=>$interfaceId) {
            if (isset($seen[$name])) continue;
            $used->execute([$interfaceId,$interfaceId]);
            if ((int)$used->fetchColumn() > 0) {
                $retained++;
                continue;
            }
            $delete->execute([$interfaceId,$nasId]);
            $removed += $delete->rowCount();
        }
        if ($ownsTransaction) $pdo->commit();
        return ['created'=>$created,'updated'=>$updated,'removed'=>$removed,'retained'=>$retained,'total'=>count($seen)];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function fs_nas_sync_ppp_sessions(PDO $pdo, int $nasId, array $sessions, string $syncedAt): array
{
    fs_nas_ppp_schema_require($pdo);
    $pdo->prepare('DELETE FROM nas_ppp_active_sessions WHERE nas_id=?')->execute([$nasId]);
    $insert = $pdo->prepare('INSERT INTO nas_ppp_active_sessions
      (nas_id,session_key,username,service,caller_id,address,uptime,session_id,synced_at)
      VALUES (?,?,?,?,?,?,?,?,?)');
    $stored = 0;
    foreach ($sessions as $session) {
        $username = mb_substr(trim((string)($session['username'] ?? '')),0,128);
        if ($username === '') continue;
        $service = mb_substr(trim((string)($session['service'] ?? '')),0,32);
        $callerId = mb_substr(trim((string)($session['caller_id'] ?? '')),0,128);
        $address = mb_substr(trim((string)($session['address'] ?? '')),0,64);
        $uptime = mb_substr(trim((string)($session['uptime'] ?? '')),0,64);
        $sessionId = mb_substr(trim((string)($session['session_id'] ?? '')),0,64);
        $sessionKey = hash('sha256',implode('|',[$sessionId,$service,$username,$callerId,$address]));
        $insert->execute([$nasId,$sessionKey,$username,$service?:null,$callerId?:null,$address?:null,$uptime?:null,$sessionId?:null,$syncedAt]);
        $stored++;
    }
    return ['total'=>$stored];
}

/** Sincronização estritamente de leitura no RouterOS; somente o inventário local é alterado. */
function fs_nas_sync(PDO $pdo, int $nasId, ?callable $executor = null, ?callable $latencyMeasurer = null): array
{
    fs_nas_health_schema_require($pdo);
    fs_nas_base_schema_require($pdo);
    fs_nas_ppp_schema_require($pdo);
    $st = $pdo->prepare('SELECT * FROM nas WHERE id=? LIMIT 1');
    $st->execute([$nasId]);
    $nas = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nas) throw new InvalidArgumentException('NAS não encontrado.');
    $nas = fs_nas_credentials_for_operation($pdo,$nas);
    $connection = fs_nas_base_connection($nas);
    $run = $executor ?: static function (array $commands, array $connection): array { return ros_exec($commands,$connection); };
    $measure = $latencyMeasurer ?: static function (string $host): ?float { return fs_nas_sync_latency($host); };
    $checkedAt = (new DateTimeImmutable('now',new DateTimeZone('America/Manaus')))->format('Y-m-d H:i:s');
    $latency = $measure((string)$nas['nasname']);
    $started = microtime(true);
    $ownsLocalTransaction = false;

    try {
        $outputs = fs_nas_base_assert_command_result($run([
            '/interface print terse without-paging',
            '/system resource print',
            '/ip hotspot host print count-only',
            '/ip hotspot print count-only',
            '/interface vlan print count-only',
            '/radius print count-only where service~"hotspot"',
            '/radius print count-only where comment="' . FS_NAS_BASE_RADIUS_COMMENT . '"',
            ':put [/radius incoming get accept]',
            ':put [/radius incoming get port]',
            '/ppp active print terse without-paging',
        ],$connection),'sincronizar as informações do NAS','consulta de inventário');
        $resource = fs_routeros_parse_document((string)($outputs[1] ?? ''));
        $version = fs_nas_base_routeros_version((string)($resource['version'] ?? ''));
        $hostCount = fs_nas_base_count_output($outputs[2] ?? '','a quantidade de clientes Hotspot');
        $hotspotServerCount = fs_nas_base_count_output($outputs[3] ?? '','a quantidade de servidores Hotspot');
        $vlanCount = fs_nas_base_count_output($outputs[4] ?? '','a quantidade de VLANs');
        $radiusHotspotCount = fs_nas_base_count_output($outputs[5] ?? '','as entradas RADIUS de Hotspot');
        $firespotRadiusCount = fs_nas_base_count_output($outputs[6] ?? '','a entrada RADIUS FireSpot Base');
        $coaAccept = strtolower(trim((string)($outputs[7] ?? '')));
        $coaPort = trim((string)($outputs[8] ?? ''));
        $coaReady = in_array($coaAccept,['yes','true'],true) && ctype_digit($coaPort) && (int)$coaPort > 0;

        $pppSessions = [];
        foreach (preg_split('/\r?\n/',(string)($outputs[9] ?? '')) ?: [] as $line) {
            $entry = fs_routeros_parse_assignments((string)$line);
            if (empty($entry['name'])) continue;
            $pppSessions[] = [
                'username'=>(string)$entry['name'],'service'=>(string)($entry['service'] ?? 'ppp'),
                'caller_id'=>$entry['caller-id'] ?? null,'address'=>$entry['address'] ?? null,
                'uptime'=>$entry['uptime'] ?? null,'session_id'=>$entry['session-id'] ?? null,
            ];
        }

        $interfaces = [];
        $dynamicInterfaceCount = 0;
        foreach (preg_split('/\r?\n/',(string)($outputs[0] ?? '')) ?: [] as $line) {
            $flags = fs_routeros_interface_flags((string)$line);
            $entry = fs_routeros_parse_assignments($line);
            if (empty($entry['name'])) continue;
            if (fs_routeros_interface_is_dynamic($entry,$flags)) {
                $dynamicInterfaceCount++;
                continue;
            }
            $interfaces[] = [
                'name'=>(string)$entry['name'],'type'=>(string)($entry['type'] ?? 'interface'),
                'comment'=>$entry['comment'] ?? null,
                'running'=>in_array('R',$flags,true) ? 'yes' : ($entry['running'] ?? null),
                'disabled'=>in_array('X',$flags,true) ? 'yes' : ($entry['disabled'] ?? null),
                'mac'=>$entry['mac-address'] ?? null,
                'mtu'=>$entry['actual-mtu'] ?? ($entry['mtu'] ?? null),
            ];
        }
        if (!$interfaces) throw new RuntimeException('O RouterOS não retornou nenhuma interface válida.');

        $health = [];
        try {
            $healthResult = $run(['/system health print'],$connection);
            if (!empty($healthResult['ok'])) $health = fs_routeros_parse_document((string)($healthResult['out'][0] ?? ''));
        } catch (Throwable $ignored) {
            $health = [];
        }

        // As leituras SSH terminam antes da transação. A atualização do
        // inventário, tipo detectado, estado-base e saúde é atômica.
        $ownsLocalTransaction = !$pdo->inTransaction();
        if ($ownsLocalTransaction) $pdo->beginTransaction();
        $inventory = fs_nas_sync_interfaces($pdo,$nasId,$interfaces);
        $pppInventory = fs_nas_sync_ppp_sessions($pdo,$nasId,$pppSessions,$checkedAt);
        $pdo->prepare("UPDATE nas SET type='mikrotik',ports=? WHERE id=?")->execute([$inventory['total'],$nasId]);
        $pdo->prepare("INSERT INTO nas_base_provisioning (nas_id,status) VALUES (?,'pending') ON DUPLICATE KEY UPDATE nas_id=VALUES(nas_id)")
            ->execute([$nasId]);
        $pdo->prepare("UPDATE nas_base_provisioning SET coa_status=?,coa_port=?,coa_checked_at=NOW(),coa_error_code=? WHERE nas_id=?")
            ->execute([$coaReady?'ready':'error',$coaReady?(int)$coaPort:FS_NAS_BASE_COA_PORT,$coaReady?null:'COA_DISABLED',$nasId]);
        $effectiveLatency = $latency !== null ? (int)round($latency) : (int)round((microtime(true)-$started)*1000);
        $message = 'Sincronização concluída: ' . $inventory['total'] . ' interfaces estruturais';
        $message .= ' · ' . $pppInventory['total'] . ' sessões PPP ativas';
        if ($dynamicInterfaceCount > 0) $message .= ' · ' . $dynamicInterfaceCount . ' dinâmica(s) ignorada(s)';
        if ($inventory['retained'] > 0) $message .= ' · ' . $inventory['retained'] . ' vínculo(s) antigo(s) preservado(s)';
        fs_nas_health_record($pdo,$nasId,[
            'status'=>'ok','checked_at'=>$checkedAt,'latency_ms'=>$effectiveLatency,'message'=>$message,
            'uptime'=>$resource['uptime']??null,'routeros_version'=>$version['version'],
            'board_model'=>$resource['board-name']??null,'cpu_load'=>$resource['cpu-load']??null,
            'memory_free'=>$resource['free-memory']??null,'memory_total'=>$resource['total-memory']??null,
            'interface_count'=>$inventory['total'],'hotspot_host_count'=>$hostCount,
            'ppp_active_count'=>$pppInventory['total'],
            'hotspot_server_count'=>$hotspotServerCount,'vlan_count'=>$vlanCount,
            'radius_hotspot_count'=>$radiusHotspotCount,'firespot_radius_count'=>$firespotRadiusCount,
            'temperature'=>$health['temperature']??null,'voltage'=>$health['voltage']??null,
            'last_success_at'=>$checkedAt,'last_error_at'=>null,
        ]);
        if ($ownsLocalTransaction) $pdo->commit();
        return [
            'ok'=>true,'nasId'=>$nasId,'checkedAt'=>$checkedAt,'latencyMs'=>$effectiveLatency,
            'detectedType'=>'mikrotik','interfaces'=>$interfaces,'inventory'=>$inventory,'hotspotHosts'=>$hostCount,
            'ignoredDynamicInterfaces'=>$dynamicInterfaceCount,
            'pppSessions'=>$pppSessions,'pppInventory'=>$pppInventory,
            'routerosInventory'=>[
                'hotspotServers'=>$hotspotServerCount,'vlans'=>$vlanCount,'radiusHotspot'=>$radiusHotspotCount,
                'firespotRadius'=>$firespotRadiusCount,
                'coaReady'=>$coaReady,'coaPort'=>$coaReady?(int)$coaPort:null,
            ],
            'resource'=>[
                'uptime'=>$resource['uptime']??null,'version'=>$version['version'],'board'=>$resource['board-name']??null,
                'cpuLoad'=>$resource['cpu-load']??null,'freeMemory'=>$resource['free-memory']??null,'totalMemory'=>$resource['total-memory']??null,
            ],
            'health'=>['temperature'=>$health['temperature']??null,'voltage'=>$health['voltage']??null],
            'message'=>$message,
        ];
    } catch (Throwable $error) {
        if ($ownsLocalTransaction && $pdo->inTransaction()) $pdo->rollBack();
        fs_nas_health_record($pdo,$nasId,[
            'status'=>'error','checked_at'=>$checkedAt,'latency_ms'=>$latency !== null?(int)round($latency):null,
            'message'=>'Falha na sincronização','last_success_at'=>null,'last_error_at'=>$checkedAt,
            'error_detail'=>mb_substr($error->getMessage(),0,255),
        ]);
        throw $error;
    }
}
