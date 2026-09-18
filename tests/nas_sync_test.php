<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/nas_sync.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO nas (nasname,shortname,type,secret,mgmt_username,mgmt_password,mgmt_port,description) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['192.0.2.20','sync-' . $suffix,'other','radius-secret','sync-user','ssh-secret',22,'Teste de sincronização']);
    $nasId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO nas_interfaces (nas_id,interface_name,mac_address,description) VALUES (?,?,?,?)')
        ->execute([$nasId,'stale-unused','00:00:00:00:00:01','antiga']);

    $commandsSeen = [];
    $executor = static function (array $commands, array $connection) use (&$commandsSeen): array {
        $commandsSeen = array_merge($commandsSeen,$commands);
        foreach ($commands as $command) {
            if (!preg_match('~\b(print|get)\b~',$command)) throw new RuntimeException('A sincronização tentou alterar o RouterOS.');
        }
        if (strpos($commands[0],'/system health') === 0) {
            return ['ok'=>true,'out'=>["temperature: 42C\nvoltage: 24V"],'err'=>null];
        }
        return ['ok'=>true,'out'=>[
            "0 R name=ether1 type=ether mac-address=AA:BB:CC:DD:EE:01 actual-mtu=1500\n1 R name=bridge-hotspot type=bridge comment=Clientes actual-mtu=1500\n2 D R name=<pppoe-cliente> type=pppoe-in actual-mtu=1480\n3 R name=<pppoe-sem-flag> type=pppoe-in actual-mtu=1480",
            "uptime: 1d2h\nversion: 7.20.2 (stable)\nboard-name: hEX\ncpu-load: 4%\nfree-memory: 100MiB\ntotal-memory: 256MiB",
            '3',
            '2',
            '5',
            '1',
            '1',
            'yes',
            '3799',
            "0 name=cliente-ppp service=pppoe caller-id=AA:BB:CC:DD:EE:99 address=198.51.100.20 uptime=2h3m session-id=0x81F00001",
        ],'err'=>null];
    };
    $result = fs_nas_sync($pdo,$nasId,$executor,static fn(string $host): float => 2.4);
    $expect($result['ok'] === true,'Sincronização não retornou sucesso.');
    $expect($result['detectedType'] === 'mikrotik','Tipo MikroTik não foi detectado.');
    $expect($result['resource']['version'] === '7.20.2 (stable)','Versão não foi interpretada.');
    $expect($result['resource']['board'] === 'hEX' && $result['resource']['cpuLoad'] === '4%','Formato de recursos do RouterOS não foi interpretado.');
    $expect($result['inventory']['created'] === 2,'Interfaces novas não foram criadas.');
    $expect($result['ignoredDynamicInterfaces'] === 2,'Interfaces PPP dinâmicas não foram excluídas das opções de instalação.');
    $expect($result['pppInventory']['total'] === 1 && ($result['pppSessions'][0]['username'] ?? '') === 'cliente-ppp','Sessões PPP não foram separadas do inventário estrutural.');
    $expect($result['inventory']['removed'] === 1,'Interface obsoleta e livre não foi removida.');
    $expect($result['inventory']['retained'] === 0,'Interface livre foi marcada como preservada.');
    $expect($result['hotspotHosts'] === 3,'Quantidade de clientes Hotspot divergente.');
    $expect($result['latencyMs'] === 2,'Latência não foi arredondada.');
    $expect($result['routerosInventory']['hotspotServers'] === 2,'Servidores Hotspot não foram inventariados.');
    $expect($result['routerosInventory']['vlans'] === 5,'VLANs não foram inventariadas.');
    $expect($result['routerosInventory']['radiusHotspot'] === 1 && $result['routerosInventory']['firespotRadius'] === 1,'RADIUS do RouterOS não foi inventariado.');
    $expect($result['routerosInventory']['coaReady'] === true && $result['routerosInventory']['coaPort'] === 3799,'Recebimento de CoA não foi inventariado.');
    $expect(count($commandsSeen) === 11,'Quantidade inesperada de consultas RouterOS.');
    $expect(in_array('/ppp active print terse without-paging',$commandsSeen,true),'Sincronização não consulta as sessões PPP separadamente.');
    $expect(in_array('/system resource print',$commandsSeen,true) && in_array('/interface print terse without-paging',$commandsSeen,true),'Sincronização não usa a sintaxe compatível confirmada no RouterOS.');
    $expect(strpos(implode("\n",$commandsSeen),'print detail without-paging terse') === false && strpos(implode("\n",$commandsSeen),'resource print detail') === false,'Sincronização ainda contém combinação de parâmetros incompatível.');

    $nas = $pdo->query('SELECT type,ports FROM nas WHERE id=' . $nasId)->fetch(PDO::FETCH_ASSOC);
    $expect($nas['type'] === 'mikrotik','Tipo detectado não foi persistido.');
    $expect((int)$nas['ports'] === 2,'Contagem de interfaces não foi persistida.');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM nas_interfaces WHERE nas_id=' . $nasId)->fetchColumn() === 2,'Inventário local não corresponde ao NAS.');
    $expect((int)$pdo->query("SELECT COUNT(*) FROM nas_interfaces WHERE nas_id={$nasId} AND interface_type IN ('ether','bridge')")->fetchColumn() === 2,'Tipos das interfaces estruturais não foram persistidos.');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM nas_ppp_active_sessions WHERE nas_id=' . $nasId)->fetchColumn() === 1,'Snapshot PPP ativo não foi persistido separadamente.');
    $expect((int)$pdo->query("SELECT COUNT(*) FROM nas_interfaces WHERE nas_id={$nasId} AND interface_name='stale-unused'")->fetchColumn() === 0,'Interface obsoleta permaneceu no inventário.');
    $health = $pdo->query('SELECT * FROM nas_health WHERE nas_id=' . $nasId)->fetch(PDO::FETCH_ASSOC);
    $expect(($health['status'] ?? '') === 'ok','Saúde do NAS não foi atualizada.');
    $expect(($health['routeros_version'] ?? '') === '7.20.2 (stable)','Versão não foi gravada na saúde.');
    $expect((int)($health['hotspot_host_count'] ?? 0) === 3,'Clientes Hotspot não foram gravados.');
    $expect((int)($health['ppp_active_count'] ?? 0) === 1,'Contagem PPP ativa não foi gravada separadamente.');
    $expect((int)($health['hotspot_server_count'] ?? 0) === 2 && (int)($health['vlan_count'] ?? 0) === 5,'Topologia RouterOS não foi gravada.');
    $expect((int)($health['radius_hotspot_count'] ?? 0) === 1 && (int)($health['firespot_radius_count'] ?? 0) === 1,'Estado RADIUS remoto não foi gravado.');
    $expect(($health['temperature'] ?? '') === '42C','Saúde opcional não foi gravada.');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM nas_base_provisioning WHERE nas_id=' . $nasId)->fetchColumn() === 1,'Estado-base pendente não foi criado para NAS legado.');

    $idsBefore = $pdo->query('SELECT interface_name,id FROM nas_interfaces WHERE nas_id=' . $nasId . ' ORDER BY interface_name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $second = fs_nas_sync($pdo,$nasId,$executor,static fn(string $host): float => 3.0);
    $idsAfter = $pdo->query('SELECT interface_name,id FROM nas_interfaces WHERE nas_id=' . $nasId . ' ORDER BY interface_name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $expect($idsBefore === $idsAfter,'Nova sincronização recriou interfaces existentes.');
    $expect($second['inventory']['created'] === 0 && $second['inventory']['updated'] === 2,'Nova sincronização não foi idempotente.');

    $source = file_get_contents(__DIR__ . '/../dashboard/api/nas_interfaces_refresh.php');
    $expect(strpos($source,"admin_has_capability('partner.network.manage')") !== false,'Endpoint de sincronização não exige capacidade de rede.');
    $poller = file_get_contents(__DIR__ . '/../dashboard/assets/js/poller.js');
    $expect(strpos($poller,'api/nas_interfaces_refresh.php') === false,'Painel ainda dispara sincronização SSH implícita em segundo plano.');
    $page = file_get_contents(__DIR__ . '/../dashboard/nas.php');
    $expect(strpos($page,'class="theme-btn apply-btn js-sync"') !== false,'Tela não oferece ação explícita de sincronização.');
    $expect(strpos($page,'id="info-hotspot-servers"') !== false && strpos($page,'id="info-firespot-radius"') !== false,'Tela não apresenta o inventário remoto ampliado.');
    $expect(strpos($page,'entrada FireSpot confirmada') !== false && strpos($page,'entrada FireSpot ausente') !== false,'Tela não diferencia presença e ausência da base no MikroTik.');
    $expect(strpos($page,'Sessões PPP ativas') !== false && strpos($page,'Serviço externo ao FireSpot') !== false,'Tela não separa os usuários PPP dos dados FireSpot.');
    $expect(strpos($page,'data-nas-tab="overview"') !== false && strpos($page,'data-nas-tab="interfaces"') !== false && strpos($page,'data-nas-tab="ppp"') !== false,'Modal do NAS não foi dividido em abas compactas.');
    $nasAsset = file_get_contents(__DIR__ . '/../dashboard/assets/js/nas.js');
    $layout = file_get_contents(__DIR__ . '/../dashboard/layout.php');
    $expect(strpos($layout,"'nas' => ['styles' => ['pages/nas.css'], 'scripts' => ['nas.js']]") !== false,'Tela do NAS não carrega o asset JavaScript extraído.');
    $expect(strpos($nasAsset,'function escapeHtml') !== false && strpos($nasAsset,'escapeHtml(iface.name') !== false,'Renderização das interfaces não protege conteúdo do RouterOS.');

    $pdo->rollBack();
    echo "NAS sync OK: {$checks} verificações.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
