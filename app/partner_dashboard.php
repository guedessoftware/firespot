<?php

declare(strict_types=1);

/**
 * Nome deterministico do servidor Hotspot criado pelo FireSpot. A mesma
 * normalizacao e usada pelo provisionamento, sem consultar o RouterOS.
 */
function fs_partner_dashboard_server_name(string $publicCode, int $hotspotId): string
{
    $code=strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',$publicCode));
    return 'hs_'.($code!==''?substr($code,0,48):'HOTSPOT'.$hotspotId);
}

/** @return array<string,mixed> */
function fs_partner_dashboard_empty(): array
{
    return [
        'updated_at'=>(new DateTimeImmutable('now',new DateTimeZone('America/Manaus')))->format(DateTimeInterface::ATOM),
        'overview'=>[
            'online_sessions'=>0,
            'visitors_today'=>0,
            'sessions_today'=>0,
            'traffic_today'=>0,
            'active_hotspots'=>0,
            'total_hotspots'=>0,
        ],
        'commerce'=>null,
        'points'=>[],
        'online'=>[],
    ];
}

/**
 * Painel operacional de leitura. A atribuicao da sessao exige simultaneamente
 * o servidor Hotspot deterministico e o NAS atualmente efetivo do ponto. Isso
 * evita que um Called-Station-Id isolado atravesse a fronteira do
 * estabelecimento. Identificadores RADIUS, MAC e IP nunca saem desta consulta.
 *
 * @return array<string,mixed>
 */
function fs_partner_dashboard(PDO $pdo, int $partnerId, int $onlineLimit=8, bool $includeSales=false): array
{
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento invalido.');
    $result=fs_partner_dashboard_empty();
    $onlineLimit=max(1,min(20,$onlineLimit));

    $statement=$pdo->prepare("SELECT h.id,h.name,h.code,h.active,h.management_state,
            n.nasname
        FROM partner_hotspots h
        LEFT JOIN nas n ON n.id=COALESCE(h.active_nas_id,h.nas_id)
        WHERE h.partner_id=?
        ORDER BY h.is_default DESC,h.active DESC,h.name,h.id");
    $statement->execute([$partnerId]);
    $hotspots=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    $result['overview']['total_hotspots']=count($hotspots);
    $result['overview']['active_hotspots']=count(array_filter($hotspots,static fn(array $row):bool=>(int)$row['active']===1));

    $mapParts=[];$mapParams=[];$metricsByHotspot=[];
    foreach($hotspots as $hotspot){
        $hotspotId=(int)$hotspot['id'];
        $metricsByHotspot[$hotspotId]=[
            'id'=>$hotspotId,
            'name'=>(string)$hotspot['name'],
            'code'=>(string)$hotspot['code'],
            'active'=>(int)$hotspot['active']===1,
            'management_state'=>(string)$hotspot['management_state'],
            'online_sessions'=>0,
            'visitors_today'=>0,
            'sessions_today'=>0,
            'traffic_today'=>0,
            'last_activity_at'=>null,
        ];
        $nasIp=trim((string)($hotspot['nasname']??''));
        if($nasIp==='')continue;
        $mapParts[]='SELECT CAST(? AS UNSIGNED) hotspot_id,? server_name,? nas_ip';
        array_push($mapParams,$hotspotId,fs_partner_dashboard_server_name((string)$hotspot['code'],$hotspotId),$nasIp);
    }

    $today=(new DateTimeImmutable('today',new DateTimeZone('America/Manaus')))->format('Y-m-d H:i:s');
    $tomorrow=(new DateTimeImmutable($today,new DateTimeZone('America/Manaus')))->modify('+1 day')->format('Y-m-d H:i:s');
    if($includeSales){
        $sales=$pdo->prepare("SELECT COUNT(*) sales_today,COALESCE(SUM(amount_cents),0) revenue_today
            FROM guest_orders WHERE partner_id=? AND status='paid' AND paid_at>=? AND paid_at<?");
        $sales->execute([$partnerId,$today,$tomorrow]);$commerce=$sales->fetch(PDO::FETCH_ASSOC)?:[];
        $result['commerce']=['sales_today'=>(int)($commerce['sales_today']??0),'revenue_today'=>(int)($commerce['revenue_today']??0)];
    }
    if(!$mapParts){$result['points']=array_values($metricsByHotspot);return $result;}
    $mapSql=implode(' UNION ALL ',$mapParts);
    $visitorExpression="COALESCE(NULLIF(TRIM(r.callingstationid),''),CONCAT('session:',r.radacctid))";
    $activeExpression="r.acctstarttime<=NOW() AND (r.acctstoptime IS NULL OR r.acctstoptime>NOW())";

    $joinSql=" FROM ({$mapSql}) point_map
        LEFT JOIN radacct r ON r.calledstationid=point_map.server_name AND r.nasipaddress=point_map.nas_ip ";
    $overview=$pdo->prepare("SELECT
            COUNT(DISTINCT CASE WHEN {$activeExpression} THEN r.radacctid END) online_sessions,
            COUNT(DISTINCT CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN {$visitorExpression} END) visitors_today,
            COUNT(DISTINCT CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN r.radacctid END) sessions_today,
            COALESCE(SUM(CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN COALESCE(r.acctinputoctets,0)+COALESCE(r.acctoutputoctets,0) ELSE 0 END),0) traffic_today
            {$joinSql}");
    $overview->execute(array_merge([$today,$tomorrow,$today,$tomorrow,$today,$tomorrow],$mapParams));
    foreach($overview->fetch(PDO::FETCH_ASSOC)?:[] as $key=>$value)$result['overview'][$key]=(int)$value;

    $byPoint=$pdo->prepare("SELECT point_map.hotspot_id,
            COUNT(DISTINCT CASE WHEN {$activeExpression} THEN r.radacctid END) online_sessions,
            COUNT(DISTINCT CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN {$visitorExpression} END) visitors_today,
            COUNT(DISTINCT CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN r.radacctid END) sessions_today,
            COALESCE(SUM(CASE WHEN r.acctstarttime>=? AND r.acctstarttime<? THEN COALESCE(r.acctinputoctets,0)+COALESCE(r.acctoutputoctets,0) ELSE 0 END),0) traffic_today,
            MAX(COALESCE(r.acctupdatetime,r.acctstoptime,r.acctstarttime)) last_activity_at
            {$joinSql} GROUP BY point_map.hotspot_id");
    $byPoint->execute(array_merge([$today,$tomorrow,$today,$tomorrow,$today,$tomorrow],$mapParams));
    foreach($byPoint->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $id=(int)$row['hotspot_id'];if(!isset($metricsByHotspot[$id]))continue;
        foreach(['online_sessions','visitors_today','sessions_today','traffic_today'] as $key)$metricsByHotspot[$id][$key]=(int)$row[$key];
        $metricsByHotspot[$id]['last_activity_at']=$row['last_activity_at']?:null;
    }
    $result['points']=array_values($metricsByHotspot);

    $online=$pdo->prepare("SELECT point_map.hotspot_id,r.acctstarttime,
            COALESCE(r.acctupdatetime,r.acctstarttime) last_accounting_at,
            GREATEST(0,TIMESTAMPDIFF(SECOND,r.acctstarttime,NOW())) connected_seconds,
            COALESCE(r.acctinputoctets,0)+COALESCE(r.acctoutputoctets,0) traffic_bytes,
            CASE
              WHEN r.username LIKE 'cty\\_%' THEN 'Cortesia'
              WHEN r.username LIKE 'gst\\_%' THEN 'Acesso pago'
              WHEN r.username LIKE 'fsn\\_%' THEN 'FIRENETWORK'
              ELSE 'Cliente identificado'
            END access_type
            FROM ({$mapSql}) point_map
            JOIN radacct r ON r.calledstationid=point_map.server_name AND r.nasipaddress=point_map.nas_ip
            WHERE {$activeExpression}
            ORDER BY r.acctstarttime DESC,r.radacctid DESC LIMIT {$onlineLimit}");
    $online->execute($mapParams);
    $pointNames=[];foreach($hotspots as $hotspot)$pointNames[(int)$hotspot['id']]=(string)$hotspot['name'];
    foreach($online->fetchAll(PDO::FETCH_ASSOC)?:[] as $index=>$row){
        $id=(int)$row['hotspot_id'];
        $result['online'][]=[
            'position'=>$index+1,
            'hotspot_id'=>$id,
            'hotspot_name'=>$pointNames[$id]??'Ponto Hotspot',
            'access_type'=>(string)$row['access_type'],
            'started_at'=>$row['acctstarttime']?:null,
            'last_accounting_at'=>$row['last_accounting_at']?:null,
            'connected_seconds'=>(int)$row['connected_seconds'],
            'traffic_bytes'=>(int)$row['traffic_bytes'],
        ];
    }
    return $result;
}
