<?php

declare(strict_types=1);

require_once __DIR__ . '/partner_network.php';

/**
 * Instalações técnicas de um estabelecimento.
 *
 * A origem técnica e a política comercial permanecem separadas no banco, mas
 * este serviço entrega um único contexto imutável da instalação ao portal.
 */

function fs_partner_hotspots_schema_ready(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspots'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fs_partner_hotspot_commercial_schema_ready(PDO $pdo): bool
{
    try{$pdo->query('SELECT hotspot_id,partner_id,paid_access_enabled,courtesy_mode,payment_window_enabled,payment_window_minutes,payment_window_daily_limit,payment_window_cooldown_minutes,payment_window_period_minutes FROM partner_hotspot_commercial_policies LIMIT 0');return true;}
    catch(Throwable $error){return false;}
}

function fs_nas_hotspot_policy_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT network_template FROM nas_hotspot_allocation_policies LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_partner_hotspot_network_prefix_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT network_prefix_length FROM partner_hotspots LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_nas_hotspot_radius_host(PDO $pdo, int $nasId): string
{
    $statement=$pdo->prepare('SELECT r.host FROM nas_base_provisioning base JOIN radius_servers r ON r.id=base.radius_server_id WHERE base.nas_id=? LIMIT 1');
    $statement->execute([$nasId]);$host=trim((string)($statement->fetchColumn()?:''));
    if($host===''||!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('O NAS ainda não possui um destino RADIUS IPv4 válido na base FireSpot.');
    return $host;
}

/** @return array<string,mixed> */
function fs_nas_hotspot_policy_default(bool $legacyFallback=false): array
{
    return [
        'nas_id'=>0,
        'vlan_start'=>$legacyFallback?101:100,
        'vlan_end'=>200,
        'network_template'=>'10.{vlan}.0.0',
        'prefix_length'=>$legacyFallback?16:24,
        'gateway_offset'=>1,
        'pool_start_offset'=>2,
        'pool_end_reserve'=>1,
        'default_dns_servers'=>'1.1.1.1,8.8.8.8',
    ];
}

/** @return array<string,mixed> */
function fs_nas_hotspot_policy_validate(array $input): array
{
    $vlanStart=(int)($input['vlan_start']??0);$vlanEnd=(int)($input['vlan_end']??0);
    if($vlanStart<1||$vlanStart>255||$vlanEnd<$vlanStart||$vlanEnd>255)throw new InvalidArgumentException('A faixa automática de VLAN deve ficar entre 1 e 255 e possuir início menor ou igual ao fim.');
    $template=strtolower(trim((string)($input['network_template']??'')));
    $parts=explode('.',$template);
    if(count($parts)!==4||$parts[1]!=='{vlan}'||$parts[2]!=='0'||$parts[3]!=='0'||!ctype_digit($parts[0])||(int)$parts[0]!==10)throw new InvalidArgumentException('Use o modelo privado 10.{vlan}.0.0; a VLAN ocupa o segundo octeto da rede.');
    $prefix=(int)($input['prefix_length']??0);
    if($prefix<16||$prefix>30)throw new InvalidArgumentException('A máscara de cada ponto deve ficar entre /16 e /30.');
    $gatewayOffset=(int)($input['gateway_offset']??0);$poolStartOffset=(int)($input['pool_start_offset']??0);$poolEndReserve=(int)($input['pool_end_reserve']??0);
    $size=2**(32-$prefix);$lastUsable=$size-1-$poolEndReserve;
    if($gatewayOffset<1||$poolStartOffset<1||$poolEndReserve<1||$gatewayOffset>=$size-1||$poolStartOffset>$lastUsable||($gatewayOffset>=$poolStartOffset&&$gatewayOffset<=$lastUsable))throw new InvalidArgumentException('Os offsets de gateway e pool não cabem na máscara escolhida ou distribuem o gateway pelo DHCP.');
    $dnsParts=preg_split('/[\s,;]+/',trim((string)($input['default_dns_servers']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(!$dnsParts)throw new InvalidArgumentException('Informe ao menos um DNS upstream padrão para os novos pontos.');
    foreach($dnsParts as $dns)if(!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('A política do NAS aceita somente IPv4 no DNS upstream.');
    return [
        'nas_id'=>(int)($input['nas_id']??0),'vlan_start'=>$vlanStart,'vlan_end'=>$vlanEnd,
        'network_template'=>$template,'prefix_length'=>$prefix,'gateway_offset'=>$gatewayOffset,
        'pool_start_offset'=>$poolStartOffset,'pool_end_reserve'=>$poolEndReserve,
        'default_dns_servers'=>implode(',',$dnsParts),
    ];
}

/** @return array<string,mixed> */
function fs_nas_hotspot_policy(PDO $pdo, int $nasId, bool $forUpdate=false): array
{
    if($nasId<=0)throw new InvalidArgumentException('NAS inválido para a política de pontos.');
    if(!fs_nas_hotspot_policy_schema_ready($pdo))return array_replace(fs_nas_hotspot_policy_default(true),['nas_id'=>$nasId]);
    $sql='SELECT * FROM nas_hotspot_allocation_policies WHERE nas_id=? LIMIT 1';
    if($forUpdate&&$pdo->inTransaction())$sql.=' FOR UPDATE';
    $statement=$pdo->prepare($sql);$statement->execute([$nasId]);$policy=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$policy)throw new RuntimeException('O NAS ainda não possui política de VLAN e endereçamento.');
    return array_replace(fs_nas_hotspot_policy_validate($policy),['nas_id'=>$nasId]);
}

/** @return array<string,mixed> */
function fs_nas_hotspot_policy_save(PDO $pdo, int $nasId, array $input, string $actorType, ?int $actorId): array
{
    if(!fs_nas_hotspot_policy_schema_ready($pdo))throw new RuntimeException('A política de pontos por NAS ainda não foi instalada.');
    if(!in_array($actorType,['firespot','partner_admin','system'],true))throw new InvalidArgumentException('Autor da política inválido.');
    $policy=fs_nas_hotspot_policy_validate(array_replace($input,['nas_id'=>$nasId]));
    $nas=$pdo->prepare('SELECT id FROM nas WHERE id=? LIMIT 1'.($pdo->inTransaction()?' FOR UPDATE':''));$nas->execute([$nasId]);if(!$nas->fetchColumn())throw new RuntimeException('NAS não encontrado.');
    $save=$pdo->prepare("INSERT INTO nas_hotspot_allocation_policies
        (nas_id,vlan_start,vlan_end,network_template,prefix_length,gateway_offset,pool_start_offset,pool_end_reserve,default_dns_servers,updated_by_type,updated_by_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vlan_start=VALUES(vlan_start),vlan_end=VALUES(vlan_end),network_template=VALUES(network_template),prefix_length=VALUES(prefix_length),gateway_offset=VALUES(gateway_offset),pool_start_offset=VALUES(pool_start_offset),pool_end_reserve=VALUES(pool_end_reserve),default_dns_servers=VALUES(default_dns_servers),updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id),updated_at=NOW()");
    $save->execute([$nasId,$policy['vlan_start'],$policy['vlan_end'],$policy['network_template'],$policy['prefix_length'],$policy['gateway_offset'],$policy['pool_start_offset'],$policy['pool_end_reserve'],$policy['default_dns_servers'],$actorType,$actorId&&$actorId>0?$actorId:null]);
    return $policy;
}

/** @return array{prefix:int,cidr:string,gateway_ip:string,pool_start:string,pool_end:string} */
function fs_partner_hotspot_network_profile(array $configuration): array
{
    foreach(['gateway_ip','pool_start','pool_end'] as $field)if(!filter_var((string)($configuration[$field]??''),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('A rede do ponto possui endereço IPv4 inválido.');
    $prefix=(int)($configuration['network_prefix_length']??0);
    if($prefix===0)$prefix=(int)partner_network_profile((string)$configuration['gateway_ip'],(string)$configuration['pool_start'],(string)$configuration['pool_end'])['prefix'];
    if($prefix<16||$prefix>30)throw new InvalidArgumentException('A máscara IPv4 do ponto deve ficar entre /16 e /30.');
    $gatewayLong=ip2long((string)$configuration['gateway_ip']);$startLong=ip2long((string)$configuration['pool_start']);$endLong=ip2long((string)$configuration['pool_end']);
    if($gatewayLong===false||$startLong===false||$endLong===false)throw new InvalidArgumentException('A rede do ponto possui endereço IPv4 inválido.');
    $bits=32-$prefix;$size=2**$bits;$mask=(-1<<$bits);$networkLong=$gatewayLong&$mask;$broadcast=$networkLong+$size-1;
    foreach([$gatewayLong,$startLong,$endLong] as $value)if($value<=$networkLong||$value>=$broadcast)throw new InvalidArgumentException('Gateway e pool precisam usar endereços válidos da mesma sub-rede do ponto.');
    if(($startLong&$mask)!==$networkLong||($endLong&$mask)!==$networkLong)throw new InvalidArgumentException('Gateway e pool precisam pertencer à mesma sub-rede do ponto.');
    if($startLong>$endLong)throw new InvalidArgumentException('O início do pool não pode ser posterior ao final.');
    if($gatewayLong>=$startLong&&$gatewayLong<=$endLong)throw new InvalidArgumentException('O gateway deve ficar fora do intervalo distribuído pelo DHCP.');
    return ['prefix'=>$prefix,'cidr'=>long2ip($networkLong).'/'.$prefix,'gateway_ip'=>(string)$configuration['gateway_ip'],'pool_start'=>(string)$configuration['pool_start'],'pool_end'=>(string)$configuration['pool_end']];
}

function fs_partner_hotspot_merge_context(array $row): array
{
    if (!array_key_exists('hotspot_id',$row)) return $row;
    $row['partner_code'] = (string)($row['code'] ?? '');
    $row['partner_name'] = (string)($row['name'] ?? '');
    $row['hotspot_id'] = (int)$row['hotspot_id'];
    $row['hotspot_code'] = (string)($row['hotspot_code'] ?? '');
    $row['hotspot_name'] = (string)($row['hotspot_name'] ?? '');
    $row['hotspot_is_default'] = (int)($row['hotspot_is_default'] ?? 0);
    $row['hotspot_active'] = (int)($row['hotspot_active'] ?? 0);
    foreach (['nas_id','nas_interface_id','vlan_id','network_prefix_length','gateway_ip','pool_start','pool_end','dns_servers','dns_name','radius_ip'] as $field) {
        $key = 'hotspot_' . $field;
        if (array_key_exists($key,$row)) $row[$field] = $row[$key];
    }
    if((int)($row['hotspot_commercial_loaded']??0)===1){
        foreach(['payment_window_enabled','payment_window_minutes','payment_window_daily_limit','payment_window_cooldown_minutes','payment_window_period_minutes'] as $field){$key='hotspot_'.$field;if(array_key_exists($key,$row))$row[$field]=$row[$key];}
    }
    return $row;
}

function fs_partner_hotspot_select_sql(PDO $pdo): string
{
    $networkPrefix=fs_partner_hotspot_network_prefix_schema_ready($pdo)?',h.network_prefix_length hotspot_network_prefix_length':'';
    $commercial=fs_partner_hotspot_commercial_schema_ready($pdo)
        ? ",IF(commercial.hotspot_id IS NULL,0,1) hotspot_commercial_loaded,commercial.paid_access_enabled hotspot_paid_access_enabled,commercial.courtesy_mode hotspot_courtesy_mode,commercial.payment_window_enabled hotspot_payment_window_enabled,commercial.payment_window_minutes hotspot_payment_window_minutes,commercial.payment_window_daily_limit hotspot_payment_window_daily_limit,commercial.payment_window_cooldown_minutes hotspot_payment_window_cooldown_minutes,commercial.payment_window_period_minutes hotspot_payment_window_period_minutes"
        : ',0 hotspot_commercial_loaded';
    $commercialJoin=fs_partner_hotspot_commercial_schema_ready($pdo)?' LEFT JOIN partner_hotspot_commercial_policies commercial ON commercial.hotspot_id=h.id AND commercial.partner_id=h.partner_id':'';
    return "SELECT p.*,
        h.id hotspot_id,h.code hotspot_code,h.name hotspot_name{$networkPrefix}{$commercial},
        h.nas_id hotspot_nas_id,h.nas_interface_id hotspot_nas_interface_id,
        h.vlan_id hotspot_vlan_id,h.gateway_ip hotspot_gateway_ip,
        h.pool_start hotspot_pool_start,h.pool_end hotspot_pool_end,
        h.dns_servers hotspot_dns_servers,h.dns_name hotspot_dns_name,
        h.radius_ip hotspot_radius_ip,h.is_default hotspot_is_default,
        h.active hotspot_active,h.desired_config_version,h.applied_config_version,
        h.management_state,h.created_at hotspot_created_at,h.updated_at hotspot_updated_at
      FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id{$commercialJoin}";
}

function fs_partner_hotspot_resolve(PDO $pdo, string $code, bool $requireV3 = true, bool $activeOnly = true): ?array
{
    $code = trim($code);
    if ($code === '') return null;
    if (!fs_partner_hotspots_schema_ready($pdo)) return null;

    $sql = fs_partner_hotspot_select_sql($pdo) . ' WHERE h.code=? AND p.active=1';
    if ($activeOnly) $sql .= ' AND h.active=1';
    if ($requireV3) $sql .= " AND p.portal_mode='v3'";
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return fs_partner_hotspot_merge_context($row);

    // Compatibilidade temporária: código antigo do partner resolve somente o
    // hotspot padrão. A ocorrência é marcada para observação do rollout.
    $fallback = fs_partner_hotspot_select_sql($pdo) . ' WHERE h.is_default=1 AND p.active=1 AND (p.code=?';
    $params = [$code];
    if (ctype_digit($code)) {
        $fallback .= ' OR p.id=?';
        $params[] = (int)$code;
    }
    $fallback .= ')';
    if ($activeOnly) $fallback .= ' AND h.active=1';
    if ($requireV3) $fallback .= " AND p.portal_mode='v3'";
    $fallback .= ' LIMIT 1';
    $st = $pdo->prepare($fallback);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['_hotspot_legacy_fallback'] = true;
    error_log('[partner_hotspots] legacy_code_fallback partner_id=' . (int)$row['id']);
    return fs_partner_hotspot_merge_context($row);
}

function fs_partner_hotspot_by_id(PDO $pdo, int $hotspotId, ?int $partnerId = null, bool $activeOnly = false): ?array
{
    if ($hotspotId <= 0 || !fs_partner_hotspots_schema_ready($pdo)) return null;
    $sql = fs_partner_hotspot_select_sql($pdo) . ' WHERE h.id=?';
    $params = [$hotspotId];
    if ($partnerId !== null) {
        $sql .= ' AND h.partner_id=?';
        $params[] = $partnerId;
    }
    if ($activeOnly) $sql .= ' AND h.active=1 AND p.active=1';
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? fs_partner_hotspot_merge_context($row) : null;
}

function fs_partner_hotspot_default(PDO $pdo, int $partnerId, bool $activeOnly = false): ?array
{
    if ($partnerId <= 0 || !fs_partner_hotspots_schema_ready($pdo)) return null;
    $sql = fs_partner_hotspot_select_sql($pdo) . ' WHERE h.partner_id=? AND h.is_default=1';
    if ($activeOnly) $sql .= ' AND h.active=1 AND p.active=1';
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$partnerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? fs_partner_hotspot_merge_context($row) : null;
}

function fs_partner_hotspots_for_partner(PDO $pdo, int $partnerId, bool $activeOnly = false): array
{
    if ($partnerId <= 0 || !fs_partner_hotspots_schema_ready($pdo)) return [];
    $sql = 'SELECT h.*,n.nasname,n.shortname,i.interface_name
        FROM partner_hotspots h
        LEFT JOIN nas n ON n.id=h.nas_id
        LEFT JOIN nas_interfaces i ON i.id=h.nas_interface_id
        WHERE h.partner_id=?';
    if ($activeOnly) $sql .= ' AND h.active=1';
    $sql .= ' ORDER BY h.is_default DESC,h.active DESC,h.name,h.id';
    $st = $pdo->prepare($sql);
    $st->execute([$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_partner_hotspot_public_code(array $context): string
{
    return trim((string)($context['hotspot_code'] ?? $context['code'] ?? ''));
}

function fs_partner_hotspot_id(array $context): ?int
{
    $id = (int)($context['hotspot_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function fs_partner_hotspot_for_operation(PDO $pdo, int $partnerId, ?int $hotspotId): ?array
{
    if ($hotspotId !== null && $hotspotId > 0) return fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);
    return fs_partner_hotspot_default($pdo,$partnerId,false);
}

function fs_partner_hotspot_validate_code(string $code): string
{
    $code = trim($code);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/',$code)) {
        throw new InvalidArgumentException('Use de 1 a 64 letras, números, hífen ou sublinhado no código do ponto.');
    }
    return $code;
}

function fs_partner_hotspot_slug(string $value): string
{
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        if (is_string($converted)) $value = $converted;
    } elseif (function_exists('iconv')) {
        $converted = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if (is_string($converted)) $value = strtolower($converted);
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/','-',$value) ?: '';
    return trim($value,'-');
}

function fs_partner_hotspot_suggest_code(PDO $pdo, string $partnerCode, string $name, ?int $excludeHotspotId = null): string
{
    $prefix = fs_partner_hotspot_slug($partnerCode);
    $slug = fs_partner_hotspot_slug($name);
    if ($prefix === '') $prefix = 'estabelecimento';
    if ($slug === '') $slug = 'instalacao';
    $base = substr($prefix . '-' . $slug,0,64);
    $base = rtrim($base,'-');
    $candidate = $base;
    for ($suffix = 1; $suffix <= 9999; $suffix++) {
        $sql = 'SELECT id FROM partner_hotspots WHERE code=?';
        $params = [$candidate];
        if ($excludeHotspotId !== null && $excludeHotspotId > 0) {
            $sql .= ' AND id<>?';
            $params[] = $excludeHotspotId;
        }
        $sql .= ' LIMIT 1' . ($pdo->inTransaction() ? ' FOR UPDATE' : '');
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if (!$st->fetchColumn()) return fs_partner_hotspot_validate_code($candidate);
        $tail = '-' . ($suffix + 1);
        $candidate = rtrim(substr($base,0,64-strlen($tail)),'-') . $tail;
    }
    throw new RuntimeException('Não foi possível gerar um código público livre para este ponto.');
}

function fs_partner_hotspot_next_vlan(PDO $pdo, int $nasId, bool $forUpdate = false, ?array $policy = null): ?int
{
    if ($nasId <= 0) return null;
    $policy=fs_nas_hotspot_policy_validate($policy??fs_nas_hotspot_policy($pdo,$nasId,$forUpdate));
    $vlanStart=(int)$policy['vlan_start'];$vlanEnd=(int)$policy['vlan_end'];
    $used = [];$usedNetworks=[];
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $st = $pdo->prepare('SELECT vlan_id FROM partners WHERE nas_id=? AND vlan_id BETWEEN ? AND ?' . $suffix);
    $st->execute([$nasId,$vlanStart,$vlanEnd]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vlan) $used[(int)$vlan] = true;
    if (fs_partner_hotspots_schema_ready($pdo)) {
        $st = $pdo->prepare('SELECT * FROM partner_hotspots WHERE nas_id=? AND active=1 AND vlan_id BETWEEN ? AND ?' . $suffix);
        $st->execute([$nasId,$vlanStart,$vlanEnd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $hotspot){
            $used[(int)$hotspot['vlan_id']] = true;
            try{$usedNetworks[fs_partner_hotspot_network_profile($hotspot)['cidr']]=true;}catch(Throwable $ignored){}
        }
    }
    try {
        $st=$pdo->prepare("SELECT vlan_id,network_cidr FROM partner_network_reservations WHERE nas_id=? AND state IN ('reserved','applied')".$suffix);
        $st->execute([$nasId]);foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $reservation){$used[(int)$reservation['vlan_id']]=true;$usedNetworks[(string)$reservation['network_cidr']]=true;}
    } catch(Throwable $ignored) {}
    $st = $pdo->prepare('SELECT vlan_id FROM nas_interfaces WHERE nas_id=? AND vlan_id BETWEEN ? AND ?' . $suffix);
    $st->execute([$nasId,$vlanStart,$vlanEnd]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vlan) $used[(int)$vlan] = true;
    for ($vlan = $vlanStart; $vlan <= $vlanEnd; $vlan++){
        if(isset($used[$vlan]))continue;
        try{$candidate=fs_partner_hotspot_network_suggestion($vlan,$policy);}catch(InvalidArgumentException $ignored){continue;}
        if(!isset($usedNetworks[$candidate['cidr']]))return $vlan;
    }
    return null;
}

function fs_partner_hotspot_network_suggestion(int $vlanId, ?array $policy = null): array
{
    $policy=fs_nas_hotspot_policy_validate($policy??fs_nas_hotspot_policy_default());
    if($vlanId<(int)$policy['vlan_start']||$vlanId>(int)$policy['vlan_end'])throw new InvalidArgumentException('A VLAN está fora da faixa automática definida no NAS.');
    $networkText=str_replace('{vlan}',(string)$vlanId,(string)$policy['network_template']);
    $networkLong=ip2long($networkText);if($networkLong===false)throw new InvalidArgumentException('O modelo de rede do NAS não gerou um endereço IPv4 válido.');
    $prefix=(int)$policy['prefix_length'];$bits=32-$prefix;$size=2**$bits;$mask=(-1<<$bits);$canonicalNetwork=$networkLong&$mask;
    if($canonicalNetwork!==$networkLong)throw new InvalidArgumentException('A VLAN não inicia um bloco válido para a máscara configurada no NAS.');
    return [
        'vlan_id'=>$vlanId,
        'network_prefix_length'=>$prefix,
        'gateway_ip'=>long2ip($canonicalNetwork+(int)$policy['gateway_offset']),
        'pool_start'=>long2ip($canonicalNetwork+(int)$policy['pool_start_offset']),
        'pool_end'=>long2ip($canonicalNetwork+$size-1-(int)$policy['pool_end_reserve']),
        'dns_servers'=>(string)$policy['default_dns_servers'],
        'cidr'=>long2ip($canonicalNetwork).'/'.$prefix,
    ];
}

function fs_partner_hotspot_validate_payload(PDO $pdo, array $data): array
{
    $code = fs_partner_hotspot_validate_code((string)($data['code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || strlen($name) > 150) throw new InvalidArgumentException('Informe um nome de ponto com até 150 caracteres.');
    $nasId = (int)($data['nas_id'] ?? 0) ?: null;
    $interfaceId = (int)($data['nas_interface_id'] ?? 0) ?: null;
    if ($nasId !== null) {
        $st = $pdo->prepare('SELECT 1 FROM nas WHERE id=? LIMIT 1');
        $st->execute([$nasId]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException('NAS não encontrado.');
    }
    if ($interfaceId !== null) {
        if ($nasId === null) throw new InvalidArgumentException('Selecione o NAS da interface.');
        $st = $pdo->prepare('SELECT nas_id FROM nas_interfaces WHERE id=? LIMIT 1');
        $st->execute([$interfaceId]);
        if ((int)$st->fetchColumn() !== $nasId) throw new InvalidArgumentException('A interface selecionada não pertence ao NAS.');
    }
    $vlanId = (int)($data['vlan_id'] ?? 0) ?: null;
    if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) throw new InvalidArgumentException('A VLAN deve estar entre 1 e 4094.');
    foreach (['gateway_ip','pool_start','pool_end','radius_ip'] as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '' && !filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Endereço IPv4 inválido em ' . $field . '.');
        $data[$field] = $value !== '' ? $value : null;
    }
    $networkPrefix=(int)($data['network_prefix_length']??0);
    if($networkPrefix===0&&$data['gateway_ip']&&$data['pool_start']&&$data['pool_end']){
        $networkPrefix=(int)partner_network_profile((string)$data['gateway_ip'],(string)$data['pool_start'],(string)$data['pool_end'])['prefix'];
    }
    if($networkPrefix<16||$networkPrefix>30)throw new InvalidArgumentException('A máscara IPv4 do ponto deve ficar entre /16 e /30.');
    $dnsServers = trim((string)($data['dns_servers'] ?? ''));
    if ($dnsServers !== '') {
        foreach (preg_split('/[\s,;]+/',$dnsServers,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $dns) {
            if (!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Informe somente endereços IPv4 válidos nos servidores DNS.');
        }
    }
    $dnsName = partner_network_dns_name((string)($data['dns_name'] ?? ''), $code);
    return [
        'code'=>$code,'name'=>$name,'nas_id'=>$nasId,'nas_interface_id'=>$interfaceId,'vlan_id'=>$vlanId,'network_prefix_length'=>$networkPrefix,
        'gateway_ip'=>$data['gateway_ip'],'pool_start'=>$data['pool_start'],'pool_end'=>$data['pool_end'],
        'dns_servers'=>$dnsServers !== '' ? $dnsServers : null,'dns_name'=>$dnsName,
        'radius_ip'=>$data['radius_ip'],'active'=>!empty($data['active']) ? 1 : 0,
    ];
}

function fs_partner_hotspot_create(PDO $pdo, int $partnerId, array $data): int
{
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido.');
    $partnerSql = 'SELECT code,active FROM partners WHERE id=? LIMIT 1' . ($pdo->inTransaction() ? ' FOR UPDATE' : '');
    $partnerSt = $pdo->prepare($partnerSql);
    $partnerSt->execute([$partnerId]);
    $partner = $partnerSt->fetch(PDO::FETCH_ASSOC);
    if (!$partner || (int)$partner['active'] !== 1) throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
    $nasId = (int)($data['nas_id'] ?? 0);
    if ($nasId <= 0) throw new InvalidArgumentException('Selecione o NAS do ponto.');
    $policy=fs_nas_hotspot_policy($pdo,$nasId,$pdo->inTransaction());
    $expectedVlan = fs_partner_hotspot_next_vlan($pdo,$nasId,$pdo->inTransaction(),$policy);
    if ($expectedVlan === null) throw new RuntimeException('O NAS selecionado já utiliza toda a faixa automática de VLAN configurada.');
    $submittedVlan = (int)($data['vlan_id'] ?? 0);
    if ($submittedVlan > 0 && $submittedVlan !== $expectedVlan) {
        throw new RuntimeException('A próxima VLAN disponível no NAS mudou para ' . $expectedVlan . '. Selecione o NAS novamente para atualizar a sugestão.');
    }
    $network = fs_partner_hotspot_network_suggestion($expectedVlan,$policy);
    $data = array_merge($data,$network);
    $data['radius_ip']=fs_nas_hotspot_radius_host($pdo,$nasId);
    $data['code'] = fs_partner_hotspot_suggest_code($pdo,(string)$partner['code'],(string)($data['name'] ?? ''));
    if (trim((string)($data['dns_servers'] ?? '')) === '') $data['dns_servers'] = (string)$policy['default_dns_servers'];
    if (trim((string)($data['dns_name'] ?? '')) === '') $data['dns_name'] = strtolower($data['code'] . '.hotspot.internal');
    $payload = fs_partner_hotspot_validate_payload($pdo,$data);
    if ($payload['active'] !== 1) throw new InvalidArgumentException('Um novo ponto deve ser criado ativo.');
    fs_partner_hotspot_assert_operational($payload);
    if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
        $st = $pdo->prepare('INSERT INTO partner_hotspots
            (partner_id,code,name,nas_id,nas_interface_id,vlan_id,network_prefix_length,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,1)');
        $st->execute([$partnerId,$payload['code'],$payload['name'],$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['network_prefix_length'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip']]);
    }else{
        $st = $pdo->prepare('INSERT INTO partner_hotspots
            (partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1)');
        $st->execute([$partnerId,$payload['code'],$payload['name'],$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip']]);
    }
    return (int)$pdo->lastInsertId();
}

function fs_partner_hotspot_assert_operational(array $payload): void
{
    if ((int)($payload['active'] ?? 0) !== 1) return;
    foreach (['nas_id','nas_interface_id','vlan_id','gateway_ip','pool_start','pool_end','dns_servers','radius_ip'] as $required) {
        if (($payload[$required] ?? null) === null || $payload[$required] === '') {
            throw new InvalidArgumentException('Complete a configuração técnica antes de ativar o ponto.');
        }
    }
    fs_partner_hotspot_network_profile($payload);
    $poolStart = sprintf('%u',ip2long((string)$payload['pool_start']));
    $poolEnd = sprintf('%u',ip2long((string)$payload['pool_end']));
    if ((float)$poolStart > (float)$poolEnd) throw new InvalidArgumentException('O início do pool não pode ser posterior ao final.');
}

function fs_partner_hotspot_update(PDO $pdo, int $partnerId, int $hotspotId, array $data): void
{
    $current = fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);
    if (!$current) throw new RuntimeException('Ponto não encontrado.');
    $requestedNasId=(int)($data['nas_id']??0);if($requestedNasId>0)$data['radius_ip']=fs_nas_hotspot_radius_host($pdo,$requestedNasId);
    $payload = fs_partner_hotspot_validate_payload($pdo,$data);
    if ($payload['code'] !== (string)$current['hotspot_code']) throw new InvalidArgumentException('O código público do ponto é permanente. Crie outro ponto se precisar de uma nova URL.');
    if ((int)$current['hotspot_is_default'] === 1) {
        if ((int)($current['active'] ?? 0) === 1 && $payload['active'] !== 1) {
            throw new InvalidArgumentException('O ponto principal não pode ser desativado enquanto o estabelecimento estiver ativo.');
        }
        $payload['active'] = (int)($current['active'] ?? 0) === 1 ? 1 : 0;
    }
    fs_partner_hotspot_assert_operational($payload);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $st = $pdo->prepare('UPDATE partner_hotspots SET code=?,name=?,nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,active=?,updated_at=NOW() WHERE id=? AND partner_id=?');
            $st->execute([$payload['code'],$payload['name'],$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['network_prefix_length'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip'],$payload['active'],$hotspotId,$partnerId]);
        }else{
            $st = $pdo->prepare('UPDATE partner_hotspots SET code=?,name=?,nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,active=?,updated_at=NOW() WHERE id=? AND partner_id=?');
            $st->execute([$payload['code'],$payload['name'],$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip'],$payload['active'],$hotspotId,$partnerId]);
        }
        if ((int)$current['hotspot_is_default'] === 1) {
            if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
                $st = $pdo->prepare('UPDATE partners SET nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,updated_at=NOW() WHERE id=?');
                $st->execute([$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['network_prefix_length'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip'],$partnerId]);
            }else{
                $st = $pdo->prepare('UPDATE partners SET nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,updated_at=NOW() WHERE id=?');
                $st->execute([$payload['nas_id'],$payload['nas_interface_id'],$payload['vlan_id'],$payload['gateway_ip'],$payload['pool_start'],$payload['pool_end'],$payload['dns_servers'],$payload['dns_name'],$payload['radius_ip'],$partnerId]);
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_partner_hotspot_sync_default_from_partner(PDO $pdo, int $partnerId): void
{
    if (!fs_partner_hotspots_schema_ready($pdo)) return;
    $partner = $pdo->prepare('SELECT active,nas_id FROM partners WHERE id=? LIMIT 1');
    $partner->execute([$partnerId]);
    $partnerState = $partner->fetch(PDO::FETCH_ASSOC);
    if (!$partnerState) throw new RuntimeException('Estabelecimento não encontrado.');
    if ((int)($partnerState['active'] ?? 0) === 1 && (int)($partnerState['nas_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('Todo estabelecimento ativo precisa estar associado a um NAS.');
    }
    $prefixExpression='p.network_prefix_length';
    $prefixColumn=fs_partner_hotspot_network_prefix_schema_ready($pdo)?',network_prefix_length':'';
    $prefixValue=fs_partner_hotspot_network_prefix_schema_ready($pdo)?','.$prefixExpression:'';
    $st = $pdo->prepare("INSERT INTO partner_hotspots
        (partner_id,code,name,nas_id,nas_interface_id,vlan_id{$prefixColumn},gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active)
        SELECT p.id,p.code,'Principal',p.nas_id,
          CASE WHEN i.id IS NOT NULL AND i.nas_id=p.nas_id THEN p.nas_interface_id ELSE NULL END,
          p.vlan_id{$prefixValue},p.gateway_ip,p.pool_start,p.pool_end,p.dns_servers,p.dns_name,p.radius_ip,1,p.active
        FROM partners p LEFT JOIN nas_interfaces i ON i.id=p.nas_interface_id
        WHERE p.id=? AND NOT EXISTS (SELECT 1 FROM partner_hotspots h WHERE h.partner_id=p.id AND h.is_default=1)");
    $st->execute([$partnerId]);
    $prefixUpdate=fs_partner_hotspot_network_prefix_schema_ready($pdo)?'h.network_prefix_length='.$prefixExpression.',':'';
    $st = $pdo->prepare('UPDATE partner_hotspots h JOIN partners p ON p.id=h.partner_id
        LEFT JOIN nas_interfaces i ON i.id=p.nas_interface_id SET
        h.code=p.code,h.nas_id=p.nas_id,
        h.nas_interface_id=CASE WHEN i.id IS NOT NULL AND i.nas_id=p.nas_id THEN p.nas_interface_id ELSE NULL END,h.vlan_id=p.vlan_id,
        '.$prefixUpdate.'h.gateway_ip=p.gateway_ip,h.pool_start=p.pool_start,h.pool_end=p.pool_end,h.dns_servers=p.dns_servers,
        h.dns_name=p.dns_name,h.radius_ip=p.radius_ip,h.active=p.active,h.updated_at=NOW()
        WHERE h.partner_id=? AND h.is_default=1');
    $st->execute([$partnerId]);
}
