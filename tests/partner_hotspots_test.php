<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/partner_hotspots.php';
require_once __DIR__ . '/../app/guest_access.php';
require_once __DIR__ . '/../app/courtesy_policy.php';
require_once __DIR__ . '/../app/partner_finance.php';
require_once __DIR__ . '/../app/portal_simulator.php';

$checks = 0;
function partner_hotspot_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

partner_hotspot_expect(fs_partner_hotspots_schema_ready($pdo),'A migração 027 não está disponível.');
$partnerCount = (int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$defaultCount = (int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots WHERE is_default=1')->fetchColumn();
partner_hotspot_expect($partnerCount === $defaultCount,'Cada estabelecimento deve possuir exatamente uma instalação principal.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM guest_orders WHERE hotspot_id IS NULL')->fetchColumn() === 0,'Existe venda sem origem técnica.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM ad_deliveries WHERE hotspot_id IS NULL')->fetchColumn() === 0,'Existe entrega publicitária sem origem técnica.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM guest_orders o JOIN partner_hotspots h ON h.id=o.hotspot_id WHERE h.partner_id<>o.partner_id')->fetchColumn() === 0,'Existe venda vinculada a hotspot de outro estabelecimento.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM courtesy_grants g JOIN partner_hotspots h ON h.id=g.hotspot_id WHERE h.partner_id<>g.partner_id')->fetchColumn() === 0,'Existe cortesia vinculada a hotspot de outro estabelecimento.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM custom_ads_events e JOIN partner_hotspots h ON h.id=e.hotspot_id WHERE h.partner_id<>e.partner_id')->fetchColumn() === 0,'Existe evento publicitário vinculado a hotspot de outro estabelecimento.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM ad_deliveries d JOIN partner_hotspots h ON h.id=d.hotspot_id WHERE h.partner_id<>d.partner_id')->fetchColumn() === 0,'Existe entrega publicitária vinculada a hotspot de outro estabelecimento.');
partner_hotspot_expect((int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id WHERE h.is_default=1 AND (BINARY h.code<>BINARY p.code OR NOT(h.nas_id<=>p.nas_id) OR NOT(h.vlan_id<=>p.vlan_id) OR NOT(h.gateway_ip<=>p.gateway_ip) OR NOT(h.pool_start<=>p.pool_start) OR NOT(h.pool_end<=>p.pool_end) OR NOT(h.dns_servers<=>p.dns_servers) OR NOT(h.dns_name<=>p.dns_name) OR NOT(h.radius_ip<=>p.radius_ip))')->fetchColumn() === 0,'A instalação principal divergiu dos campos técnicos preservados no estabelecimento.');

$default = $pdo->query('SELECT h.id FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id WHERE h.is_default=1 AND h.active=1 AND p.active=1 AND h.nas_id IS NOT NULL ORDER BY h.id LIMIT 1')->fetchColumn();
partner_hotspot_expect($default !== false,'Não há instalação principal ativa para o teste transacional.');
$defaultContext = fs_partner_hotspot_by_id($pdo,(int)$default,null,true);
partner_hotspot_expect(is_array($defaultContext),'A instalação principal não foi resolvida.');
$partnerId = (int)$defaultContext['id'];
$resolved = fs_partner_hotspot_resolve($pdo,(string)$defaultContext['hotspot_code'],false,true);
partner_hotspot_expect(is_array($resolved) && (int)$resolved['hotspot_id'] === (int)$defaultContext['hotspot_id'] && (int)$resolved['id'] === $partnerId,'O código público não resolveu o contexto correto.');
$otherPartnerId = (int)$pdo->query('SELECT id FROM partners WHERE id<>'.(int)$partnerId.' ORDER BY id LIMIT 1')->fetchColumn();
partner_hotspot_expect($otherPartnerId > 0,'É necessário outro estabelecimento para validar isolamento.');
partner_hotspot_expect(fs_partner_hotspot_by_id($pdo,(int)$defaultContext['hotspot_id'],$otherPartnerId,false) === null,'Um estabelecimento conseguiu consultar a instalação de outro.');

$layoutSource = (string)file_get_contents(__DIR__ . '/../dashboard/layout.php');
$compatibilitySource = (string)file_get_contents(__DIR__ . '/../dashboard/hosts.php');
$legacyPageSource = (string)file_get_contents(__DIR__ . '/../dashboard/hotspots.php');
$canonicalPageSource = (string)file_get_contents(__DIR__ . '/../dashboard/estabelecimento.php');
$canonicalActionSource = (string)file_get_contents(__DIR__ . '/../dashboard/actions/point.php');
partner_hotspot_expect(!str_contains($layoutSource,"['href' => 'hotspots.php', 'label' => 'Instalações'"),'Instalações ainda aparece como menu global.');
partner_hotspot_expect(str_contains($compatibilitySource,"REQUEST_METHOD")&&str_contains($compatibilitySource,"http_response_code(410)")&&str_contains($compatibilitySource,'fs_control_center_partner_url'),'A fachada antiga não limita a compatibilidade a GET ou não encaminha ao contexto canônico.');
partner_hotspot_expect(!str_contains($compatibilitySource,'<form')&&!str_contains($compatibilitySource,'central_hotspot_create'),'A fachada antiga ainda contém formulários ou controladores de ponto duplicados.');
partner_hotspot_expect(str_contains($legacyPageSource,'fs_control_center_partner_url') && str_contains($legacyPageSource,"'points'") && str_contains($legacyPageSource,"'estabelecimentos.php'") && str_contains($legacyPageSource,"header('Location: ' . \$destination"),'A URL antiga não redireciona para a rota contextual canônica.');
partner_hotspot_expect(str_contains($canonicalPageSource,'action="actions/point.php"') && str_contains($canonicalActionSource,"point_create_draft") && str_contains($canonicalActionSource,"point_discard_draft"),'A rota canônica não oferece o ciclo local de rascunhos de ponto.');
partner_hotspot_expect(!preg_match('/routeros_|hotspot_apply|MikroTik/i',$canonicalActionSource),'A ação canônica de ponto pode alcançar operação remota.');

$interface = $pdo->prepare('SELECT i.id,i.nas_id FROM nas_interfaces i JOIN nas n ON n.id=i.nas_id WHERE i.nas_id<>? ORDER BY i.nas_id,i.id LIMIT 1');
$interface->execute([(int)$defaultContext['nas_id']]);
$interface = $interface->fetch();
partner_hotspot_expect(is_array($interface),'São necessários dois NAS com interface mapeada para o teste.');
$allocationPolicy=fs_nas_hotspot_policy($pdo,(int)$interface['nas_id']);
$vlan = fs_partner_hotspot_next_vlan($pdo,(int)$interface['nas_id'],false,$allocationPolicy);
partner_hotspot_expect($vlan !== null && $vlan >= (int)$allocationPolicy['vlan_start'] && $vlan <= (int)$allocationPolicy['vlan_end'],'Não há VLAN automática livre para o teste transacional.');
$suggestedNetwork = fs_partner_hotspot_network_suggestion((int)$vlan,$allocationPolicy);
partner_hotspot_expect($suggestedNetwork['gateway_ip']==='10.'.(int)$vlan.'.0.1' && $suggestedNetwork['pool_start']==='10.'.(int)$vlan.'.0.2' && str_ends_with((string)$suggestedNetwork['cidr'],'/'.(int)$allocationPolicy['prefix_length']),'O padrão automático de rede divergiu da política do NAS.');
partner_hotspot_expect(fs_partner_hotspot_slug('Balneário Norte')==='balneario-norte','A normalização do nome da instalação está incorreta.');

$eventCounts = [];
foreach (['guest_orders','courtesy_grants','custom_ads_events','ad_deliveries'] as $table) {
    $eventCounts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}
$snapshot = fs_portal_simulator_snapshot($pdo,$defaultContext);
partner_hotspot_expect((int)$snapshot['partner']['hotspot_id'] === (int)$defaultContext['hotspot_id'],'O simulador perdeu a instalação selecionada.');
foreach ($eventCounts as $table => $before) {
    partner_hotspot_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === $before,"O simulador gerou efeitos colaterais em {$table}.");
}

$pdo->beginTransaction();
try {
    $pdo->exec('SAVEPOINT active_partner_requires_nas');
    $pdo->prepare('UPDATE partners SET nas_id=NULL WHERE id=?')->execute([$partnerId]);
    try {
        fs_partner_hotspot_sync_default_from_partner($pdo,$partnerId);
        $activeWithoutNasBlocked = false;
    } catch (InvalidArgumentException $e) {
        $activeWithoutNasBlocked = str_contains($e->getMessage(),'ativo precisa estar associado a um NAS');
    }
    partner_hotspot_expect($activeWithoutNasBlocked,'A sincronização aceitou instalação principal ativa sem NAS.');
    $pdo->exec('ROLLBACK TO SAVEPOINT active_partner_requires_nas');
    $pdo->exec('RELEASE SAVEPOINT active_partner_requires_nas');

    $installationName = 'Teste transacional em outro NAS';
    $expectedCode = fs_partner_hotspot_suggest_code($pdo,(string)$defaultContext['code'],$installationName);
    $staleVlanBlocked = false;
    try {
        fs_partner_hotspot_create($pdo,$partnerId,[
            'name'=>$installationName,'nas_id'=>(int)$interface['nas_id'],'nas_interface_id'=>(int)$interface['id'],
            'vlan_id'=>(int)$vlan+1,'radius_ip'=>(string)($defaultContext['radius_ip'] ?: '10.99.0.1'),'active'=>1,
        ]);
    } catch (RuntimeException $e) {
        $staleVlanBlocked = str_contains($e->getMessage(),'próxima VLAN disponível');
    }
    partner_hotspot_expect($staleVlanBlocked,'O servidor aceitou uma VLAN diferente da próxima disponível.');
    $secondaryId = fs_partner_hotspot_create($pdo,$partnerId,[
        'code'=>'valor-cliente-ignorado',
        'name'=>$installationName,
        'nas_id'=>(int)$interface['nas_id'],
        'nas_interface_id'=>(int)$interface['id'],
        'vlan_id'=>$vlan,
        'gateway_ip'=>'10.254.0.1',
        'pool_start'=>'10.254.0.10',
        'pool_end'=>'10.254.0.250',
        'dns_servers'=>'1.1.1.1,8.8.8.8',
        'dns_name'=>'teste.local',
        'radius_ip'=>(string)($defaultContext['radius_ip'] ?: '10.99.0.1'),
        'active'=>1,
    ]);
    $secondary = fs_partner_hotspot_by_id($pdo,$secondaryId,$partnerId,true);
    partner_hotspot_expect(is_array($secondary) && (int)$secondary['nas_id'] === (int)$interface['nas_id'],'A segunda instalação não preservou seu próprio NAS.');
    partner_hotspot_expect((string)$secondary['hotspot_code']===$expectedCode,'O código público não combinou o código do estabelecimento com o nome da instalação.');
    partner_hotspot_expect((string)$secondary['dns_name']==='teste.hotspot.internal','O sufixo .local não foi substituído pelo domínio privado unicast.');
    partner_hotspot_expect((int)$secondary['vlan_id']===(int)$vlan && (string)$secondary['gateway_ip']===$suggestedNetwork['gateway_ip'] && (string)$secondary['pool_start']===$suggestedNetwork['pool_start'] && (string)$secondary['pool_end']===$suggestedNetwork['pool_end'],'A criação não aplicou a VLAN e a faixa de IP automáticas.');
    partner_hotspot_expect((int)$secondary['nas_id'] !== (int)$defaultContext['nas_id'],'As duas instalações do teste deveriam usar NAS diferentes.');
    partner_hotspot_expect((int)$secondary['id'] === $partnerId,'A segunda instalação criou uma nova fronteira comercial.');
    partner_hotspot_expect(($secondary['payment_wallet_id'] ?? null) === ($defaultContext['payment_wallet_id'] ?? null),'A carteira deixou de ser compartilhada pelo estabelecimento.');

    $plan = ['source'=>'global','id'=>1,'name'=>'Plano transacional','price_cents'=>500,'duration_minutes'=>60,'download_kbps'=>1000,'upload_kbps'=>500];
    $firstOrder = fs_guest_create_order($pdo,$defaultContext,$plan,['mac'=>'02:00:00:00:10:01','ip'=>'10.254.0.11'],null);
    $plan['price_cents'] = 700;
    $secondOrder = fs_guest_create_order($pdo,$secondary,$plan,['mac'=>'02:00:00:00:10:02','ip'=>'10.254.0.12'],null);
    $pdo->prepare("UPDATE guest_orders SET status='paid',paid_at=NOW() WHERE id IN (?,?)")->execute([(int)$firstOrder['id'],(int)$secondOrder['id']]);
    partner_hotspot_expect((int)$firstOrder['hotspot_id'] === (int)$defaultContext['hotspot_id'],'A venda principal perdeu sua origem.');
    partner_hotspot_expect((int)$secondOrder['hotspot_id'] === $secondaryId,'A venda secundária perdeu sua origem.');

    $persisted = $pdo->prepare('SELECT * FROM guest_orders WHERE id=?');
    $persisted->execute([(int)$secondOrder['id']]);
    $persisted = $persisted->fetch();
    $operation = fs_partner_hotspot_for_operation($pdo,$partnerId,(int)$persisted['hotspot_id']);
    partner_hotspot_expect(is_array($operation) && (int)$operation['nas_id'] === (int)$interface['nas_id'],'A execução técnica não usou o NAS persistido no pedido.');

    $totals = $pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(amount_cents),0) amount FROM guest_orders WHERE id IN (?,?)');
    $totals->execute([(int)$firstOrder['id'],(int)$secondOrder['id']]);
    $totals = $totals->fetch();
    $groups = $pdo->prepare('SELECT hotspot_id,COUNT(*) total,SUM(amount_cents) amount FROM guest_orders WHERE id IN (?,?) GROUP BY hotspot_id');
    $groups->execute([(int)$firstOrder['id'],(int)$secondOrder['id']]);
    $groups = $groups->fetchAll();
    partner_hotspot_expect(count($groups) === 2,'O agrupamento não separou as duas instalações.');
    partner_hotspot_expect(array_sum(array_column($groups,'total')) === (int)$totals['total'] && array_sum(array_column($groups,'amount')) === (int)$totals['amount'],'A soma por instalação não fecha com o estabelecimento.');

    $financeTestDay = (new DateTimeImmutable('now',new DateTimeZone('America/Manaus')))->format('Y-m-d');
    $financeFilters = partner_finance_filters(['from'=>$financeTestDay,'to'=>$financeTestDay,'hotspot_id'=>(string)$secondaryId]);
    $secondaryFinance = partner_finance_overview($pdo,$partnerId,$financeFilters);
    $foreignFinance = partner_finance_overview($pdo,$otherPartnerId,$financeFilters);
    partner_hotspot_expect((int)$secondaryFinance['paid_count'] === 1 && (int)$secondaryFinance['paid_amount'] === 700,'O filtro financeiro da instalação não conciliou a venda.');
    partner_hotspot_expect((int)$foreignFinance['paid_count'] === 0 && (int)$foreignFinance['paid_amount'] === 0,'O filtro financeiro expôs dados a outro estabelecimento.');

    $policy = fs_courtesy_policy_resolve($pdo,$partnerId);
    $courtesyBase = ['partner_id'=>$partnerId,'device_key'=>'02:00:00:00:20:01','mac'=>'02:00:00:00:20:01','portal'=>'test','source'=>'multi_hotspot_test'];
    $usageDefault = fs_courtesy_usage_snapshot($pdo,$policy,$courtesyBase+['hotspot_id'=>(int)$defaultContext['hotspot_id']]);
    $usageSecondary = fs_courtesy_usage_snapshot($pdo,$policy,$courtesyBase+['hotspot_id'=>$secondaryId]);
    partner_hotspot_expect($usageDefault === $usageSecondary,'A troca de instalação alterou a franquia de cortesia do estabelecimento.');

    $mismatchBlocked = false;
    try {
        $pdo->prepare('UPDATE guest_orders SET partner_id=? WHERE id=?')->execute([$otherPartnerId,(int)$secondOrder['id']]);
    } catch (PDOException $e) {
        $mismatchBlocked = (string)$e->getCode() === '23000';
    }
    partner_hotspot_expect($mismatchBlocked,'A chave composta aceitou estabelecimento e hotspot divergentes.');

    $pdo->prepare('UPDATE partner_hotspots SET active=0 WHERE id=?')->execute([$secondaryId]);
    partner_hotspot_expect(fs_partner_hotspot_resolve($pdo,(string)$secondary['hotspot_code'],false,true) === null,'Uma instalação inativa iniciou nova jornada.');
    partner_hotspot_expect(fs_partner_hotspot_by_id($pdo,$secondaryId,$partnerId,false) !== null,'A desativação apagou o histórico da instalação.');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "OK: {$checks} verificações de múltiplos hotspots por estabelecimento.\n";
