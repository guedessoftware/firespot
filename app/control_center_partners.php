<?php

declare(strict_types=1);

require_once __DIR__ . '/partner_central.php';
require_once __DIR__ . '/partner_entitlements.php';
require_once __DIR__ . '/partner_hotspots.php';
require_once __DIR__ . '/partner_infrastructure.php';
require_once __DIR__ . '/nas_base_provisioning.php';
require_once __DIR__ . '/partner_admin.php';
require_once __DIR__ . '/partner_finance.php';
require_once __DIR__ . '/partner_courtesy_management.php';
require_once __DIR__ . '/portal_skin.php';

function fs_control_center_filter_enum($value, array $allowed, string $fallback = 'all'): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value,$allowed,true) ? $value : $fallback;
}

/** @return array{q:string,status:string,plan:string,portal:string,billing:string,points:string,owners:int,page:int,per_page:int} */
function fs_control_center_partner_filters(array $input): array
{
    $query = preg_replace('/\s+/u',' ',trim((string)($input['q'] ?? ''))) ?: '';
    return [
        'q'=>function_exists('mb_substr') ? mb_substr($query,0,100,'UTF-8') : substr($query,0,100),
        'status'=>fs_control_center_filter_enum($input['status'] ?? 'all',['all','active','inactive']),
        'plan'=>preg_match('/^[a-z0-9_]{2,64}$/',(string)($input['plan'] ?? '')) ? (string)$input['plan'] : 'all',
        'portal'=>fs_control_center_filter_enum($input['portal'] ?? 'all',['all','inherit','classic','v2','v3']),
        'billing'=>fs_control_center_filter_enum($input['billing'] ?? 'all',['all','global','independent']),
        'points'=>fs_control_center_filter_enum($input['points'] ?? 'all',['all','none','single','multiple']),
        'owners'=>(string)($input['owners'] ?? '') === '1' ? 1 : 0,
        'page'=>max(1,(int)($input['page'] ?? 1)),
        'per_page'=>max(10,min(100,(int)($input['per_page'] ?? 25))),
    ];
}

/** @return array{where:string,params:list<mixed>} */
function fs_control_center_partner_where(array $filters): array
{
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(p.name LIKE ? OR p.code LIKE ?)';
        $search = '%' . str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']) . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if ($filters['status'] === 'active') $where[] = 'p.active=1';
    elseif ($filters['status'] === 'inactive') $where[] = 'p.active=0';
    if ($filters['plan'] !== 'all') {
        $where[] = 'COALESCE(ps.plan_code,\'\')=?';
        $params[] = $filters['plan'];
    }
    if ($filters['portal'] !== 'all') {
        $where[] = 'p.portal_mode=?';
        $params[] = $filters['portal'];
    }
    if ($filters['billing'] === 'global') $where[] = 'COALESCE(p.independent_billing,0)=0';
    elseif ($filters['billing'] === 'independent') $where[] = 'COALESCE(p.independent_billing,0)=1';
    if ($filters['points'] === 'none') $where[] = 'COALESCE(hs.points_total,0)=0';
    elseif ($filters['points'] === 'single') $where[] = 'COALESCE(hs.points_total,0)=1';
    elseif ($filters['points'] === 'multiple') $where[] = 'COALESCE(hs.points_total,0)>1';
    return ['where'=>$where ? ' WHERE ' . implode(' AND ',$where) : '','params'=>$params];
}

function fs_control_center_partner_from_sql(bool $includeOwners = false): string
{
    $owners = $includeOwners ? "
        LEFT JOIN (
            SELECT m.partner_id,
                GROUP_CONCAT(CONCAT(COALESCE(NULLIF(u.name,''),u.email),' · ',m.role) ORDER BY (m.role='owner') DESC,u.name,u.email SEPARATOR ', ') responsible_summary
            FROM partner_admin_memberships m
            JOIN host_users u ON u.id=m.user_id AND u.active=1
            WHERE m.active=1
            GROUP BY m.partner_id
        ) owners ON owners.partner_id=p.id" : '';
    return " FROM partners p
        LEFT JOIN (
            SELECT h.partner_id,
                COUNT(*) points_total,
                SUM(CASE WHEN h.active=1 THEN 1 ELSE 0 END) points_active,
                SUM(CASE WHEN h.management_state='draft' THEN 1 ELSE 0 END) points_draft,
                COUNT(DISTINCT h.nas_id) nas_total,
                SUM(CASE WHEN h.active=1 AND (h.nas_id IS NULL OR COALESCE(nh.status,'unknown') NOT IN ('ok','online')) THEN 1 ELSE 0 END) point_alerts
            FROM partner_hotspots h
            LEFT JOIN nas_health nh ON nh.nas_id=h.nas_id
            GROUP BY h.partner_id
        ) hs ON hs.partner_id=p.id
        LEFT JOIN (
            SELECT s.partner_id,s.status,pf.code plan_code,pf.name plan_name,pf.version plan_version,
                   pf.max_hotspots,pf.max_nas
            FROM partner_subscriptions s
            JOIN platform_plans pf ON pf.id=s.plan_id
            WHERE s.is_current=1
        ) ps ON ps.partner_id=p.id" . $owners;
}

/** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,filters:array<string,mixed>} */
function fs_control_center_partners(PDO $pdo, array $input = []): array
{
    $filters = fs_control_center_partner_filters($input);
    $clause = fs_control_center_partner_where($filters);
    $includeOwners = (int)$filters['owners'] === 1;
    $from = fs_control_center_partner_from_sql($includeOwners);

    $count = $pdo->prepare('SELECT COUNT(*)' . $from . $clause['where']);
    $count->execute($clause['params']);
    $total = (int)$count->fetchColumn();
    $pages = max(1,(int)ceil($total / $filters['per_page']));
    $filters['page'] = min($filters['page'],$pages);
    $offset = ($filters['page'] - 1) * $filters['per_page'];

    $sql = "SELECT p.id,p.code,p.name,p.active,p.portal_mode,p.access_purpose,
            COALESCE(p.independent_billing,0) independent_billing,
            COALESCE(p.self_service_enabled,0) self_service_enabled,
            p.updated_at,
            COALESCE(hs.points_total,0) points_total,
            COALESCE(hs.points_active,0) points_active,
            COALESCE(hs.points_draft,0) points_draft,
            COALESCE(hs.nas_total,0) nas_total,
            COALESCE(hs.point_alerts,0) point_alerts,
            ps.status subscription_status,ps.plan_code,ps.plan_name,ps.plan_version,
            ps.max_hotspots,ps.max_nas," . ($includeOwners ? 'owners.responsible_summary' : 'NULL responsible_summary') . $from . $clause['where'] .
        ' ORDER BY p.active DESC,p.updated_at DESC,p.name,p.id LIMIT ' . (int)$filters['per_page'] . ' OFFSET ' . (int)$offset;
    $statement = $pdo->prepare($sql);
    $statement->execute($clause['params']);

    return [
        'rows'=>$statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total'=>$total,
        'page'=>$filters['page'],
        'pages'=>$pages,
        'filters'=>$filters,
    ];
}

/** @return list<array<string,mixed>> */
function fs_control_center_partner_export_rows(PDO $pdo, array $input = [], bool $includeOwners = false): array
{
    $filters = fs_control_center_partner_filters($input);
    $filters['owners'] = $includeOwners ? 1 : 0;
    $clause = fs_control_center_partner_where($filters);
    $from = fs_control_center_partner_from_sql($includeOwners);
    $sql = "SELECT p.code,p.name,p.active,p.portal_mode,
            COALESCE(p.independent_billing,0) independent_billing,
            COALESCE(p.self_service_enabled,0) self_service_enabled,
            COALESCE(hs.points_total,0) points_total,
            COALESCE(hs.points_active,0) points_active,
            COALESCE(hs.points_draft,0) points_draft,
            COALESCE(hs.nas_total,0) nas_total,
            COALESCE(hs.point_alerts,0) point_alerts,
            ps.status subscription_status,ps.plan_code,ps.plan_name,ps.plan_version," .
            ($includeOwners ? 'owners.responsible_summary' : 'NULL responsible_summary') .
            $from . $clause['where'] . ' ORDER BY p.name,p.id';
    $statement = $pdo->prepare($sql);
    $statement->execute($clause['params']);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_control_center_csv_cell($value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',(string)$value) ?? '';
    return preg_match('/^[=+\-@\t\r]/u',$value) ? "'" . $value : $value;
}

function fs_control_center_partner_list_return($candidate): string
{
    $candidate = trim((string)$candidate);
    if ($candidate === '') return 'estabelecimentos.php';
    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || ($parts['path'] ?? '') !== 'estabelecimentos.php') {
        return 'estabelecimentos.php';
    }
    $query = [];
    parse_str((string)($parts['query'] ?? ''),$query);
    $filters = fs_control_center_partner_filters($query);
    $safe = [];
    foreach (['q','status','plan','portal','billing','points'] as $key) {
        if ($filters[$key] !== '' && $filters[$key] !== 'all') $safe[$key] = $filters[$key];
    }
    if ((int)$filters['owners'] === 1) $safe['owners'] = 1;
    if ((int)$filters['page'] > 1) $safe['page'] = (int)$filters['page'];
    if ((int)$filters['per_page'] !== 25) $safe['per_page'] = (int)$filters['per_page'];
    return 'estabelecimentos.php' . ($safe ? '?' . http_build_query($safe) : '');
}

/** @return array{total:int,active:int,inactive:int,multipoint:int,alerts:int,independent_billing:int,self_service:int} */
function fs_control_center_partner_kpis(PDO $pdo): array
{
    $row = $pdo->query("SELECT COUNT(*) total,
            SUM(CASE WHEN p.active=1 THEN 1 ELSE 0 END) active,
            SUM(CASE WHEN p.active=0 THEN 1 ELSE 0 END) inactive,
            SUM(CASE WHEN COALESCE(hs.points_total,0)>1 THEN 1 ELSE 0 END) multipoint,
            SUM(CASE WHEN COALESCE(hs.point_alerts,0)>0 THEN 1 ELSE 0 END) alerts,
            SUM(CASE WHEN COALESCE(p.independent_billing,0)=1 THEN 1 ELSE 0 END) independent_billing,
            SUM(CASE WHEN COALESCE(p.self_service_enabled,0)=1 THEN 1 ELSE 0 END) self_service" .
        fs_control_center_partner_from_sql())->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['total','active','inactive','multipoint','alerts','independent_billing','self_service'] as $key) {
        $row[$key] = (int)($row[$key] ?? 0);
    }
    return $row;
}

/** @return array<string,mixed> */
function fs_control_center_partner_context(PDO $pdo, int $partnerId): array
{
    $partner = partner_central_partner($pdo,$partnerId);
    $hotspotStatement = $pdo->prepare("SELECT h.*,n.nasname,n.shortname,i.interface_name,
            nh.status nas_health_status,nh.checked_at nas_health_checked_at,
            (SELECT a.action FROM partner_admin_audit a
             WHERE a.partner_id=h.partner_id AND a.target_type='partner_hotspot'
               AND a.target_id=CAST(h.id AS CHAR)
               AND a.action IN ('hotspot.applied','hotspot.apply_failed')
             ORDER BY a.created_at DESC,a.id DESC LIMIT 1) last_apply_action,
            (SELECT a.created_at FROM partner_admin_audit a
             WHERE a.partner_id=h.partner_id AND a.target_type='partner_hotspot'
               AND a.target_id=CAST(h.id AS CHAR)
               AND a.action IN ('hotspot.applied','hotspot.apply_failed')
             ORDER BY a.created_at DESC,a.id DESC LIMIT 1) last_apply_at
        FROM partner_hotspots h
        LEFT JOIN nas n ON n.id=h.nas_id
        LEFT JOIN nas_interfaces i ON i.id=h.nas_interface_id
        LEFT JOIN nas_health nh ON nh.nas_id=h.nas_id
        WHERE h.partner_id=?
        ORDER BY h.is_default DESC,h.active DESC,h.name,h.id");
    $hotspotStatement->execute([$partnerId]);
    $hotspots = $hotspotStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $subscription = fs_partner_current_subscription($pdo,$partnerId);
    $limits = fs_partner_plan_limits($pdo,$partnerId);
    $usage = [];
    foreach (['max_hotspots','max_nas','max_admin_users','custom_courtesy_overrides'] as $quota) {
        $usage[$quota] = fs_partner_quota_usage($pdo,$partnerId,$quota);
    }
    return [
        'partner'=>$partner,
        'hotspots'=>$hotspots,
        'configuration_requests'=>fs_partner_hotspot_configuration_requests($pdo,$partnerId),
        'subscription'=>$subscription,
        'limits'=>$limits,
        'usage'=>$usage,
        'apply_gate'=>fs_partner_entitlement($pdo,$partnerId,'hotspots.apply',true),
    ];
}

/** @return array{steps:list<array<string,mixed>>,recent:list<array<string,mixed>>} */
function fs_control_center_partner_onboarding(PDO $pdo, int $partnerId, array $context): array
{
    $partner = (array)($context['partner'] ?? []);
    $subscription = (array)($context['subscription'] ?? []);
    $hotspots = (array)($context['hotspots'] ?? []);
    $purpose = (string)($partner['access_purpose'] ?? '');
    $portalMode = (string)($partner['portal_mode'] ?? 'inherit');
    $journey = fs_portal_config_get($pdo,$partnerId,'draft') ?: fs_portal_config_get($pdo,$partnerId,'published');
    $experienceReady = is_array($journey) || (in_array($purpose,['free','sponsored','paid','hybrid'],true) && $portalMode !== 'inherit');

    $mainPointReady = false;
    foreach ($hotspots as $hotspot) {
        if ((int)($hotspot['is_default'] ?? 0) !== 1) continue;
        $mainPointReady = (int)($hotspot['nas_id'] ?? 0) > 0
            && (int)($hotspot['nas_interface_id'] ?? 0) > 0
            && (int)($hotspot['vlan_id'] ?? 0) > 0
            && trim((string)($hotspot['gateway_ip'] ?? '')) !== ''
            && trim((string)($hotspot['pool_start'] ?? '')) !== ''
            && trim((string)($hotspot['pool_end'] ?? '')) !== ''
            && trim((string)($hotspot['radius_ip'] ?? '')) !== '';
        break;
    }

    $statement = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM courtesy_partner_policies WHERE partner_id=?) courtesy_ready,
        EXISTS(SELECT 1 FROM partner_payment_plans WHERE partner_id=? AND active=1) own_access_plan,
        EXISTS(SELECT 1 FROM payment_wallets WHERE active=1 AND partner_id IS NULL AND access_token_validated_at IS NOT NULL AND webhook_secret_validated_at IS NOT NULL) global_wallet_ready,
        EXISTS(SELECT 1 FROM payment_wallets WHERE active=1 AND partner_id=? AND access_token_validated_at IS NOT NULL AND webhook_secret_validated_at IS NOT NULL) own_wallet_ready,
        EXISTS(SELECT 1 FROM partner_portal_themes WHERE partner_id=?) identity_ready,
        EXISTS(SELECT 1 FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id WHERE m.partner_id=? AND m.active=1 AND u.active=1 AND m.role=\'owner\') owner_ready');
    $statement->execute([$partnerId,$partnerId,$partnerId,$partnerId,$partnerId]);
    $readiness = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    $globalAccessPlan = (int)$pdo->query('SELECT EXISTS(SELECT 1 FROM planos WHERE ativo=1)')->fetchColumn() === 1;
    $paidRequired = is_array($journey) ? !empty($journey['paid_access_enabled']) : in_array($purpose,['paid','hybrid'],true);
    $courtesyRequired = is_array($journey) ? (string)($journey['courtesy_mode'] ?? 'disabled') !== 'disabled' : in_array($purpose,['free','sponsored','hybrid'],true);
    $paidDependenciesReady = !$paidRequired
        || (((int)($partner['independent_billing'] ?? 0) === 1 ? !empty($readiness['own_wallet_ready']) : !empty($readiness['global_wallet_ready']))
            && (!empty($readiness['own_access_plan']) || $globalAccessPlan));
    $accessReady = !$courtesyRequired || !empty($readiness['courtesy_ready']);
    $identityAndOwnerReady = !empty($readiness['identity_ready']) && !empty($readiness['owner_ready']);

    $candidateReady = false;
    if (fs_portal_skin_schema_ready($pdo)) {
        $candidate = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM partner_portal_presentations WHERE partner_id=? AND state='candidate')");
        $candidate->execute([$partnerId]);
        $candidateReady = (int)$candidate->fetchColumn() === 1;
    }

    $steps = [
        ['number'=>1,'label'=>'Cadastro e contrato','complete'=>!empty($subscription),'section'=>'contract','detail'=>!empty($subscription)?'Plano FireSpot atribuído.':'Atribua uma assinatura.'],
        ['number'=>2,'label'=>'Experiência','complete'=>$experienceReady,'section'=>'portal','detail'=>$experienceReady?'Jornada funcional definida em rascunho ou publicada.':'Defina a jornada funcional do portal.'],
        ['number'=>3,'label'=>'Ponto inicial','complete'=>$mainPointReady,'section'=>'points','detail'=>$mainPointReady?'Ponto principal associado pela FireSpot.':'Associe e complete o ponto principal.'],
        ['number'=>4,'label'=>'Acesso','complete'=>$accessReady,'section'=>'courtesy','detail'=>$accessReady?'Regra de acesso compatível.':'Revise cortesia e regras do visitante.'],
        ['number'=>5,'label'=>'Negócio','complete'=>$paidDependenciesReady,'section'=>'finance','detail'=>$paidDependenciesReady?'Dependências financeiras compatíveis.':'Complete carteira e plano de acesso.'],
        ['number'=>6,'label'=>'Identidade e equipe','complete'=>$identityAndOwnerReady,'section'=>!empty($readiness['identity_ready'])?'team':'portal','detail'=>$identityAndOwnerReady?'Identidade e responsável definidos.':'Complete identidade e primeiro responsável.'],
        ['number'=>7,'label'=>'Revisão e candidato','complete'=>$candidateReady,'section'=>'portal','detail'=>$candidateReady?'Candidato pronto; portal publicado inalterado.':(fs_portal_skin_schema_ready($pdo)?'Revise as prévias e marque o candidato.':'Aguarda a migração visual 050.')],
    ];

    $recent = $pdo->prepare('SELECT action,target_type,created_at FROM partner_admin_audit WHERE partner_id=? ORDER BY id DESC LIMIT 6');
    $recent->execute([$partnerId]);
    return ['steps'=>$steps,'recent'=>$recent->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

/** @return array{nas:list<array<string,mixed>>,interfaces:array<int,list<array<string,mixed>>>,next_network:array<int,array<string,mixed>>} */
function fs_control_center_nas_catalog(PDO $pdo): array
{
    $nas = $pdo->query("SELECT n.id,n.nasname,n.shortname,b.status base_status,
            r.name radius_name,r.host radius_host
        FROM nas n
        LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id
        LEFT JOIN radius_servers r ON r.id=b.radius_server_id
        ORDER BY n.nasname,n.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $interfaces = [];
    foreach ($pdo->query('SELECT id,nas_id,interface_name,interface_type,vlan_id FROM nas_interfaces ORDER BY nas_id,interface_name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $interface) {
        $nasId=(int)$interface['nas_id'];
        $type=trim((string)($interface['interface_type']??'')) ?: 'other';
        $interfaces[$nasId][]=[
            'id'=>(int)$interface['id'],'name'=>(string)$interface['interface_name'],'type'=>$type,
            'vlan_id'=>$interface['vlan_id']!==null?(int)$interface['vlan_id']:null,
            'recommended'=>in_array($type,['ether','bridge'],true),
        ];
    }
    $nextNetwork=[];
    foreach($nas as &$row){
        $nasId=(int)$row['id'];
        $policy=fs_nas_hotspot_policy($pdo,$nasId,false);$row=array_merge($row,$policy);
        $vlan=fs_partner_hotspot_next_vlan($pdo,$nasId,false,$policy);
        if($vlan!==null)$nextNetwork[$nasId]=fs_partner_hotspot_network_suggestion($vlan,$policy)+[
            'radius_host'=>(string)($row['radius_host']??''),
            'base_status'=>(string)($row['base_status']??'pending'),
        ];
    }
    unset($row);
    return ['nas'=>$nas,'interfaces'=>$interfaces,'next_network'=>$nextNetwork];
}

/** @return array<string,mixed> */
function fs_control_center_point_payload(PDO $pdo, array $input, ?array $current=null): array
{
    $nasId=(int)($input['nas_id']??0);
    if($nasId<=0)throw new InvalidArgumentException('Selecione o NAS do ponto.');
    $code=trim((string)($current['hotspot_code']??$current['code']??$input['code']??''));
    return [
        'code'=>$code,
        'name'=>trim((string)($input['name']??'')),
        'nas_id'=>$nasId,
        'nas_interface_id'=>(int)($input['nas_interface_id']??0),
        'vlan_id'=>$input['vlan_id']??($current['vlan_id']??null),
        'network_prefix_length'=>$input['network_prefix_length']??($current['network_prefix_length']??null),
        'gateway_ip'=>$input['gateway_ip']??($current['gateway_ip']??null),
        'pool_start'=>$input['pool_start']??($current['pool_start']??null),
        'pool_end'=>$input['pool_end']??($current['pool_end']??null),
        'dns_servers'=>$input['dns_servers']??($current['dns_servers']??'1.1.1.1,8.8.8.8'),
        'dns_name'=>$input['dns_name']??($current['dns_name']??''),
        'radius_ip'=>fs_nas_base_radius_host($pdo,$nasId),
        'active'=>isset($input['active'])?1:0,
    ];
}

function fs_control_center_point_draft_create(PDO $pdo, int $partnerId, array $input, ?int $actorId = null): int
{
    $partner=partner_central_partner($pdo,$partnerId);
    fs_partner_require_quota($pdo,$partnerId,'max_hotspots',1);
    $name=trim((string)($input['name']??''));
    if($name===''||strlen($name)>150)throw new InvalidArgumentException('Informe o nome do ponto com até 150 caracteres.');
    $nasId=(int)($input['nas_id']??0);$interfaceId=(int)($input['nas_interface_id']??0);
    if($nasId<=0||$interfaceId<=0)throw new InvalidArgumentException('Selecione o NAS e uma interface-base sincronizada.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT code FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$partnerId]);
        $partnerCode=(string)($lock->fetchColumn()?:'');if($partnerCode==='')throw new RuntimeException('Estabelecimento não encontrado.');
        fs_partner_require_quota($pdo,$partnerId,'max_hotspots',1);
        $nas=$pdo->prepare('SELECT id FROM nas WHERE id=? LIMIT 1 FOR UPDATE');$nas->execute([$nasId]);if(!$nas->fetchColumn())throw new InvalidArgumentException('NAS não encontrado.');
        fs_partner_nas_assignment_ensure($pdo,$partnerId,$nasId,$actorId);
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1 FOR UPDATE');$interface->execute([$interfaceId,$nasId]);if(!$interface->fetchColumn())throw new InvalidArgumentException('A interface selecionada não pertence ao NAS.');
        $policy=fs_nas_hotspot_policy($pdo,$nasId,true);
        $vlan=fs_partner_hotspot_next_vlan($pdo,$nasId,true,$policy);if($vlan===null)throw new RuntimeException('Não há VLAN automática disponível na faixa deste NAS.');
        $network=fs_partner_hotspot_network_suggestion($vlan,$policy);
        $code=fs_partner_hotspot_suggest_code($pdo,$partnerCode,$name);
        $radiusHost=fs_nas_base_radius_host($pdo,$nasId);
        $dnsServers=trim((string)($input['dns_servers']??'')) ?: (string)$policy['default_dns_servers'];
        foreach(preg_split('/[\s,;]+/',$dnsServers,-1,PREG_SPLIT_NO_EMPTY)?:[] as $dns)if(!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('Informe somente IPv4 válidos no DNS upstream.');
        $dnsName=strtolower($code.'.hotspot.internal');
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $insert=$pdo->prepare("INSERT INTO partner_hotspots
                (partner_id,code,name,nas_id,nas_interface_id,vlan_id,network_prefix_length,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,1,0,'draft')");
            $insert->execute([$partnerId,$code,$name,$nasId,$interfaceId,$vlan,$network['network_prefix_length'],$network['gateway_ip'],$network['pool_start'],$network['pool_end'],$dnsServers,$dnsName,$radiusHost]);
        }else{
            $insert=$pdo->prepare("INSERT INTO partner_hotspots
                (partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,0,1,0,'draft')");
            $insert->execute([$partnerId,$code,$name,$nasId,$interfaceId,$vlan,$network['gateway_ip'],$network['pool_start'],$network['pool_end'],$dnsServers,$dnsName,$radiusHost]);
        }
        $hotspotId=(int)$pdo->lastInsertId();
        fs_partner_hotspot_reserve_configuration($pdo,$partnerId,$hotspotId,[
            'nas_id'=>$nasId,'vlan_id'=>$vlan,'network_prefix_length'=>$network['network_prefix_length'],'gateway_ip'=>$network['gateway_ip'],
            'pool_start'=>$network['pool_start'],'pool_end'=>$network['pool_end'],
        ],['hotspot_active'=>0]);
        if($ownsTransaction)$pdo->commit();
        return $hotspotId;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_control_center_point_draft_update(PDO $pdo, int $partnerId, int $hotspotId, array $input, ?int $actorId = null): void
{
    $name=trim((string)($input['name']??''));
    if($name===''||strlen($name)>150)throw new InvalidArgumentException('Informe o nome do ponto com até 150 caracteres.');
    $nasId=(int)($input['nas_id']??0);$interfaceId=(int)($input['nas_interface_id']??0);
    if($nasId<=0||$interfaceId<=0)throw new InvalidArgumentException('Selecione o NAS e uma interface-base sincronizada.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare("SELECT * FROM partner_hotspots WHERE id=? AND partner_id=? AND is_default=0 AND active=0 AND management_state='draft' AND applied_config_version=0 LIMIT 1 FOR UPDATE");$lock->execute([$hotspotId,$partnerId]);$draft=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$draft)throw new RuntimeException('Somente um rascunho secundário ainda não aplicado pode ser alterado.');
        $nas=$pdo->prepare('SELECT id FROM nas WHERE id=? LIMIT 1 FOR UPDATE');$nas->execute([$nasId]);if(!$nas->fetchColumn())throw new InvalidArgumentException('NAS não encontrado.');
        fs_partner_nas_assignment_ensure($pdo,$partnerId,$nasId,$actorId);
        $interface=$pdo->prepare('SELECT id FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1 FOR UPDATE');$interface->execute([$interfaceId,$nasId]);if(!$interface->fetchColumn())throw new InvalidArgumentException('A interface selecionada não pertence ao NAS.');
        $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);if(!$current)throw new RuntimeException('Rascunho não encontrado.');
        if((int)$draft['nas_id']===$nasId){
            $configuration=['nas_id'=>$nasId,'vlan_id'=>(int)$draft['vlan_id'],'network_prefix_length'=>$draft['network_prefix_length']??null,'gateway_ip'=>$draft['gateway_ip'],'pool_start'=>$draft['pool_start'],'pool_end'=>$draft['pool_end']];
        }else{
            $policy=fs_nas_hotspot_policy($pdo,$nasId,true);
            $vlan=fs_partner_hotspot_next_vlan($pdo,$nasId,true,$policy);if($vlan===null)throw new RuntimeException('Não há VLAN automática disponível na faixa deste NAS.');
            $network=fs_partner_hotspot_network_suggestion($vlan,$policy);
            $configuration=['nas_id'=>$nasId,'vlan_id'=>$vlan,'network_prefix_length'=>$network['network_prefix_length'],'gateway_ip'=>$network['gateway_ip'],'pool_start'=>$network['pool_start'],'pool_end'=>$network['pool_end']];
        }
        fs_partner_hotspot_reserve_configuration($pdo,$partnerId,$hotspotId,$configuration,$current);
        if((int)$draft['nas_id']!==$nasId)$pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND nas_id=? AND state='reserved'")->execute([$hotspotId,(int)$draft['nas_id']]);
        $radiusHost=fs_nas_base_radius_host($pdo,$nasId);
        $dnsServers=trim((string)($input['dns_servers']??$draft['dns_servers']??'')) ?: (string)fs_nas_hotspot_policy($pdo,$nasId,true)['default_dns_servers'];
        foreach(preg_split('/[\s,;]+/',$dnsServers,-1,PREG_SPLIT_NO_EMPTY)?:[] as $dns)if(!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('Informe somente IPv4 válidos no DNS upstream.');
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,radius_ip=?,desired_config_version=desired_config_version+1,updated_at=NOW() WHERE id=? AND partner_id=? AND active=0 AND management_state='draft'")
                ->execute([$name,$nasId,$interfaceId,(int)$configuration['vlan_id'],(int)$configuration['network_prefix_length'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$dnsServers,$radiusHost,$hotspotId,$partnerId]);
        }else{
            $pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,radius_ip=?,desired_config_version=desired_config_version+1,updated_at=NOW() WHERE id=? AND partner_id=? AND active=0 AND management_state='draft'")
                ->execute([$name,$nasId,$interfaceId,(int)$configuration['vlan_id'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$dnsServers,$radiusHost,$hotspotId,$partnerId]);
        }
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_control_center_point_draft_discard(PDO $pdo, int $partnerId, int $hotspotId): void
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare("SELECT id FROM partner_hotspots WHERE id=? AND partner_id=? AND is_default=0 AND active=0 AND management_state='draft' AND applied_config_version=0 LIMIT 1 FOR UPDATE");$lock->execute([$hotspotId,$partnerId]);if(!$lock->fetchColumn())throw new RuntimeException('Somente um rascunho secundário ainda não aplicado pode ser descartado.');
        $pdo->prepare("UPDATE partner_hotspots SET management_state='retired',updated_at=NOW() WHERE id=? AND partner_id=?")->execute([$hotspotId,$partnerId]);
        $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND partner_id=? AND state='reserved'")->execute([$hotspotId,$partnerId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_control_center_next_partner_code(PDO $pdo): string
{
    $maximum=$pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM partners WHERE code REGEXP '^[0-9]{8,}$'")->fetchColumn();
    $candidate=max(10000000,(int)($maximum?:0)+1);
    for($attempt=0;$attempt<10000;$attempt++,$candidate++){
        $code=(string)$candidate;
        $check=$pdo->prepare('SELECT 1 FROM partners WHERE code=? LIMIT 1');$check->execute([$code]);
        if(!$check->fetchColumn())return $code;
    }
    throw new RuntimeException('Não foi possível reservar um código para o estabelecimento.');
}

function fs_control_center_partner_create_draft(PDO $pdo, array $input, int $actorId): int
{
    $name=preg_replace('/\s+/u',' ',trim((string)($input['name']??'')))?:'';
    $nameLength=function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name);
    if($name===''||$nameLength>150)throw new InvalidArgumentException('Informe o nome do estabelecimento com até 150 caracteres.');
    $planCode=trim((string)($input['platform_plan_code']??'essential'));
    $subscriptionStatus=(string)($input['subscription_status']??'trial');
    if(!in_array($subscriptionStatus,['trial','active'],true))throw new InvalidArgumentException('O cadastro inicial aceita assinatura em teste ou ativa.');
    $reason=trim((string)($input['subscription_reason']??''));
    if($reason==='')$reason='Cadastro inicial pela Central';
    $selfService=isset($input['self_service_enabled'])?1:0;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $code=fs_control_center_next_partner_code($pdo);
        $insert=$pdo->prepare("INSERT INTO partners (code,name,self_service_enabled,dns_servers,dns_name,active) VALUES (?,?,?,'1.1.1.1,8.8.8.8',?,0)");
        $insert->execute([$code,$name,$selfService,strtolower($code.'.hotspot.internal')]);
        $partnerId=(int)$pdo->lastInsertId();
        fs_partner_hotspot_sync_default_from_partner($pdo,$partnerId);
        $subscription=fs_partner_assign_subscription($pdo,$partnerId,$planCode,$subscriptionStatus,$reason,$actorId);
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'partner.draft_created','partner',$partnerId,[
            'code'=>$code,'plan_code'=>$subscription['plan_code'],'subscription_status'=>$subscriptionStatus,'self_service_enabled'=>$selfService,
        ]);
        if($ownsTransaction)$pdo->commit();
        return $partnerId;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_control_center_partner_save_registration(PDO $pdo, int $partnerId, array $input, int $actorId): void
{
    $name=preg_replace('/\s+/u',' ',trim((string)($input['name']??'')))?:'';
    $nameLength=function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name);
    if($name===''||$nameLength>150)throw new InvalidArgumentException('Informe o nome do estabelecimento com até 150 caracteres.');
    $active=isset($input['active'])?1:0;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT name,active FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$partnerId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$current)throw new RuntimeException('Estabelecimento não encontrado.');
        if($active===1){
            $point=$pdo->prepare("SELECT id FROM partner_hotspots WHERE partner_id=? AND is_default=1 AND nas_id IS NOT NULL AND nas_interface_id IS NOT NULL AND vlan_id IS NOT NULL AND gateway_ip IS NOT NULL AND pool_start IS NOT NULL AND pool_end IS NOT NULL AND radius_ip IS NOT NULL LIMIT 1 FOR UPDATE");
            $point->execute([$partnerId]);
            if(!$point->fetchColumn())throw new RuntimeException('Configure completamente o ponto principal e seu NAS antes de ativar o estabelecimento.');
        }
        $pdo->prepare('UPDATE partners SET name=?,active=?,updated_at=NOW() WHERE id=?')->execute([$name,$active,$partnerId]);
        fs_partner_hotspot_sync_default_from_partner($pdo,$partnerId);
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'partner.registration_updated','partner',$partnerId,[
            'name_from'=>(string)$current['name'],'name_to'=>$name,'active_from'=>(int)$current['active'],'active_to'=>$active,
        ]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_control_center_partner_deactivate(PDO $pdo, int $partnerId, int $actorId): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$partnerId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado.');
        $dependencies=[];
        foreach(['partner_admin_memberships'=>'administradores','guest_orders'=>'pedidos','courtesy_grants'=>'concessões','custom_ads'=>'anúncios'] as $table=>$label){
            $statement=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE partner_id=?");$statement->execute([$partnerId]);$count=(int)$statement->fetchColumn();if($count>0)$dependencies[]="{$count} {$label}";
        }
        $pdo->prepare('UPDATE partners SET active=0,self_service_enabled=0,updated_at=NOW() WHERE id=?')->execute([$partnerId]);
        $pdo->prepare('UPDATE partner_hotspots SET active=0,updated_at=NOW() WHERE partner_id=?')->execute([$partnerId]);
        $pdo->prepare('UPDATE partner_admin_memberships SET active=0,updated_at=NOW() WHERE partner_id=?')->execute([$partnerId]);
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'partner.deactivated','partner',$partnerId,['dependencies'=>$dependencies]);
        if($ownsTransaction)$pdo->commit();
        return $dependencies;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

/** @return array{plans:list<array<string,mixed>>,features:list<array<string,mixed>>,events:list<array<string,mixed>>,overrides:list<array<string,mixed>>} */
function fs_control_center_partner_contract(PDO $pdo, int $partnerId): array
{
    $subscription=fs_partner_current_subscription($pdo,$partnerId);
    $features=[];
    if($subscription){
        $statement=$pdo->prepare('SELECT feature_code,enabled FROM platform_plan_features WHERE plan_id=? ORDER BY feature_code');
        $statement->execute([(int)$subscription['plan_id']]);$features=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $events=$pdo->prepare('SELECT event_type,from_plan_code,to_plan_code,from_status,to_status,reason,actor_type,created_at FROM partner_subscription_events WHERE partner_id=? ORDER BY id DESC LIMIT 30');
    $events->execute([$partnerId]);
    return [
        'plans'=>fs_platform_plan_catalog($pdo,true),
        'features'=>$features,
        'events'=>$events->fetchAll(PDO::FETCH_ASSOC)?:[],
        'overrides'=>fs_partner_feature_override_history($pdo,$partnerId,30),
    ];
}

/** @return array{members:list<array<string,mixed>>,invitations:list<array<string,mixed>>} */
function fs_control_center_partner_team(PDO $pdo, int $partnerId): array
{
    partner_central_partner($pdo,$partnerId);
    return ['members'=>partner_admin_members($pdo,$partnerId),'invitations'=>partner_admin_pending_invitations($pdo,$partnerId)];
}

/** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} */
function fs_control_center_partner_audit(PDO $pdo, int $partnerId, array $input=[]): array
{
    partner_central_partner($pdo,$partnerId);
    $page=max(1,(int)($input['audit_page']??1));$perPage=30;
    $action=preg_match('/^[a-z0-9_.-]{1,80}$/i',(string)($input['audit_action']??''))?(string)$input['audit_action']:'';
    $where=' WHERE partner_id=?';$params=[$partnerId];
    if($action!==''){$where.=' AND action=?';$params[]=$action;}
    $count=$pdo->prepare('SELECT COUNT(*) FROM partner_admin_audit'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
    $statement=$pdo->prepare('SELECT id,actor_type,actor_id,action,target_type,target_id,created_at FROM partner_admin_audit'.$where.' ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.$offset);
    $statement->execute($params);
    return ['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'page'=>$page,'pages'=>$pages];
}

/** @return array{own:list<array<string,mixed>>,global:list<array<string,mixed>>,using_fallback:bool} */
function fs_control_center_partner_access_plans(PDO $pdo, int $partnerId): array
{
    partner_central_partner($pdo,$partnerId);
    $statement=$pdo->prepare('SELECT id,name,description,price_cents,duration_minutes,download_kbps,upload_kbps,sort_order,active,created_at,updated_at FROM partner_payment_plans WHERE partner_id=? ORDER BY sort_order,id');
    $statement->execute([$partnerId]);$own=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    $global=$pdo->query('SELECT id,nome name,descricao description,preco_centavos price_cents,duracao_min duration_minutes,down_kbps download_kbps,up_kbps upload_kbps,ordem sort_order,ativo active FROM planos WHERE ativo=1 ORDER BY ordem,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    $activeOwn=array_filter($own,static fn(array $plan):bool=>(int)$plan['active']===1&&(int)$plan['price_cents']>0&&(int)$plan['duration_minutes']>0);
    return ['own'=>$own,'global'=>$global,'using_fallback'=>!$activeOwn];
}

/** @return array<string,mixed> */
function fs_control_center_partner_portal(PDO $pdo, int $partnerId): array
{
    $partner=partner_central_partner($pdo,$partnerId);
    $draftConfig=fs_portal_config_get($pdo,$partnerId,'draft');
    $publishedConfig=fs_portal_config_get($pdo,$partnerId,'published');
    $configValidation=null;
    if($draftConfig){
        $prospective=$partner;$prospective['portal_mode']='v3';
        $configValidation=fs_portal_config_validate($pdo,$prospective,$draftConfig);
    }
    $schemaReady=fs_portal_skin_schema_ready($pdo);
    return [
        'schema_ready'=>$schemaReady,
        'skins'=>fs_portal_skin_catalog($pdo,true),
        'migration'=>fs_portal_migration_state($pdo,$partnerId),
        'functional'=>['draft'=>$draftConfig,'published'=>$publishedConfig,'validation'=>$configValidation,'presets'=>fs_portal_config_presets()],
        'presentation'=>[
            'draft'=>$schemaReady?fs_portal_presentation_get($pdo,$partnerId,'draft'):null,
            'candidate'=>$schemaReady?fs_portal_presentation_get($pdo,$partnerId,'candidate'):null,
            'published'=>$schemaReady?fs_portal_presentation_get($pdo,$partnerId,'published'):null,
        ],
        'legacy_theme'=>portal_theme_get($pdo,$partner,[]),
    ];
}

/** @return array{wallets:list<array<string,mixed>>,overview:array<string,int>,filters:array<string,mixed>,global_ready:bool} */
function fs_control_center_partner_finance(PDO $pdo, int $partnerId, array $input=[]): array
{
    $partner=partner_central_partner($pdo,$partnerId);
    $filters=partner_finance_filters($input);
    $global=fs_global_wallet();
    return [
        'wallets'=>fs_wallets_for_partner($pdo,$partnerId,false),
        'overview'=>partner_finance_overview($pdo,$partnerId,$filters),
        'filters'=>$filters,
        'global_ready'=>trim((string)($global['public_key']??''))!==''&&trim((string)($global['access_token']??''))!=='',
    ];
}

/** @return array<string,mixed> */
function fs_control_center_partner_courtesy(PDO $pdo, int $partnerId): array
{
    partner_central_partner($pdo,$partnerId);
    $overrides=fs_partner_courtesy_overrides($pdo,$partnerId);$overrideMap=[];
    foreach($overrides as $override)$overrideMap[(int)$override['hotspot_id']][(string)$override['state']]=$override;
    return [
        'effective'=>fs_courtesy_policy_resolve($pdo,$partnerId),
        'draft'=>fs_partner_courtesy_revision($pdo,$partnerId,'draft'),
        'history'=>fs_partner_courtesy_history($pdo,$partnerId,20),
        'override_map'=>$overrideMap,
        'presets'=>fs_partner_courtesy_presets(),
        'manage_gate'=>fs_partner_entitlement($pdo,$partnerId,'courtesy.manage',true),
        'override_gate'=>fs_partner_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',true),
    ];
}
