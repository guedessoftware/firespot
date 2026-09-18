<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/nas_base_provisioning.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$redacted = ros_redact_sensitive('/radius add secret="very secret" password=ssh-pass address=192.0.2.1');
$expect(strpos($redacted,'very secret') === false && strpos($redacted,'ssh-pass') === false,'Log RouterOS expõe credenciais.');
$expect(substr_count($redacted,'[REDACTED]') === 2,'Log RouterOS não marcou os campos protegidos.');

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $radiusHost = '192.0.2.254';
    $radiusPort = 19000 + random_int(1,500);
    $pdo->prepare('INSERT INTO radius_servers (name,host,port,secret) VALUES (?,?,?,?)')
        ->execute(['RADIUS Teste ' . $suffix,$radiusHost,$radiusPort,'radius-server-secret']);
    $radiusId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO nas (nasname,shortname,type,secret,mgmt_username,mgmt_password,mgmt_port,description) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['192.0.2.10','nas-test-' . $suffix,'mikrotik','nas-shared-secret','firespot-test','ssh-password',22,'Teste transacional']);
    $nasId = (int)$pdo->lastInsertId();
    fs_nas_base_assign($pdo,$nasId,$radiusId,true);

    $commandsSeen = [];
    $executor = static function (array $commands, array $connection) use (&$commandsSeen,$radiusHost): array {
        $commandsSeen = array_merge($commandsSeen,$commands);
        if ($commands === [':put [/system resource get version]']) return ['ok'=>true,'out'=>['7.20.2 (stable)'],'err'=>null];
        if (count($commands) === 2 && strpos($commands[1],'service~') !== false) return ['ok'=>true,'out'=>['0','0'],'err'=>null];
        if (count($commands) === 4 && strpos($commands[1],'print detail') !== false) {
            return ['ok'=>true,'out'=>['1','0 name="FireSpot Base" service=hotspot address=' . $radiusHost . ' timeout=3s','yes','3799'],'err'=>null];
        }
        return ['ok'=>true,'out'=>[''],'err'=>null];
    };
    $result = fs_nas_base_provision($pdo,$nasId,$executor);
    $expect($result['status'] === 'ready','Provisionamento não terminou pronto.');
    $expect($result['routeros_version'] === '7.20.2 (stable)','Versão do RouterOS não foi preservada.');
    $state = fs_nas_base_state($pdo,$nasId);
    $expect(($state['status'] ?? '') === 'ready','Estado pronto não foi persistido.');
    $expect((int)($state['config_revision'] ?? 0) === FS_NAS_BASE_REVISION,'Revisão da base não foi persistida.');
    $expect((int)($state['attempt_count'] ?? 0) === 1,'Tentativa não foi contabilizada.');
    $expect((string)($state['radius_host'] ?? '') === $radiusHost,'Destino RADIUS não foi herdado.');
    $expect(fs_nas_base_assert_ready($pdo,$nasId,$radiusHost)['status'] === 'ready','Pré-requisito da instalação rejeitou base pronta.');

    $allCommands = implode("\n",$commandsSeen);
    $expect(strpos($allCommands,'/radius add') !== false,'Base não criou entrada RADIUS ausente.');
    foreach (['/interface vlan','/ip address add','/ip pool','/ip dhcp-server','/ip hotspot','walled-garden','/ip firewall nat'] as $forbidden) {
        $expect(strpos($allCommands,$forbidden) === false,'Base invadiu configuração da instalação: ' . $forbidden);
    }
    $expect(strpos($allCommands,'secret="nas-shared-secret"') !== false,'Shared secret do NAS não foi aplicado com aspas.');
    $expect(strpos($allCommands,'authentication-port=' . $radiusPort) !== false,'Porta de autenticação não foi aplicada.');
    $expect(strpos($allCommands,'/radius incoming set accept=yes port=3799') !== false,'Base não habilitou o recebimento de CoA.');
    $expect(($state['coa_status'] ?? '') === 'ready' && (int)($state['coa_port'] ?? 0) === 3799,'Prontidão CoA não foi persistida.');

    fs_nas_base_assign($pdo,$nasId,$radiusId,false);
    $secondCommands = [];
    $secondExecutor = static function (array $commands, array $connection) use (&$secondCommands,$radiusHost): array {
        $secondCommands = array_merge($secondCommands,$commands);
        if ($commands === [':put [/system resource get version]']) return ['ok'=>true,'out'=>['6.49.17 (long-term)'],'err'=>null];
        if (count($commands) === 2 && strpos($commands[1],'service~') !== false) return ['ok'=>true,'out'=>['1','1'],'err'=>null];
        if (count($commands) === 4 && strpos($commands[1],'print detail') !== false) return ['ok'=>true,'out'=>['1','service=hotspot address=' . $radiusHost,'yes','3799'],'err'=>null];
        return ['ok'=>true,'out'=>[''],'err'=>null];
    };
    fs_nas_base_provision($pdo,$nasId,$secondExecutor);
    $expect(strpos(implode("\n",$secondCommands),'/radius set') !== false,'Reaplicação não atualizou a entrada gerenciada.');
    $expect(strpos(implode("\n",$secondCommands),'/radius add') === false,'Reaplicação duplicou a entrada gerenciada.');
    $expect((int)fs_nas_base_state($pdo,$nasId)['attempt_count'] === 2,'Reaplicação não foi contabilizada.');
    try {
        fs_nas_base_assert_ready($pdo,$nasId,'198.51.100.90');
        $expect(false,'Instalação com RADIUS divergente foi aceita.');
    } catch (RuntimeException $error) {
        $expect(strpos($error->getMessage(),'diverge') !== false,'Divergência de RADIUS retornou motivo incorreto.');
    }

    fs_nas_base_assign($pdo,$nasId,$radiusId,true);
    $ambiguousCommands = [];
    $ambiguous = static function (array $commands, array $connection) use (&$ambiguousCommands): array {
        $ambiguousCommands = array_merge($ambiguousCommands,$commands);
        if ($commands === [':put [/system resource get version]']) return ['ok'=>true,'out'=>['7.20.2 (stable)'],'err'=>null];
        return ['ok'=>true,'out'=>['0','2'],'err'=>null];
    };
    try {
        fs_nas_base_provision($pdo,$nasId,$ambiguous);
        $expect(false,'Múltiplas entradas RADIUS não gerenciadas foram sobrescritas.');
    } catch (FsNasBaseProvisioningException $error) {
        $expect($error->errorCodeName() === 'RADIUS_AMBIGUOUS','Ambiguidade RADIUS retornou código incorreto.');
    }
    $expect(strpos(implode("\n",$ambiguousCommands),'/radius add') === false && strpos(implode("\n",$ambiguousCommands),'/radius set') === false,'Ambiguidade RADIUS executou alteração remota.');

    fs_nas_base_assign($pdo,$nasId,$radiusId,true);
    $unsupported = static function (array $commands, array $connection): array {
        return ['ok'=>true,'out'=>['5.26'],'err'=>null];
    };
    try {
        fs_nas_base_provision($pdo,$nasId,$unsupported);
        $expect(false,'RouterOS não suportado foi aceito.');
    } catch (FsNasBaseProvisioningException $error) {
        $expect($error->errorCodeName() === 'ROUTEROS_UNSUPPORTED','Código de versão incompatível incorreto.');
    }
    $failedState = fs_nas_base_state($pdo,$nasId);
    $expect(($failedState['status'] ?? '') === 'error','Falha de versão não foi persistida.');
    $expect(($failedState['last_error_code'] ?? '') === 'ROUTEROS_UNSUPPORTED','Motivo da falha não foi persistido.');

    $source = file_get_contents(__DIR__ . '/../dashboard/api/host_apply.php');
    $expect(strpos($source,'fs_nas_base_assert_ready') !== false,'Aplicação da instalação não exige base pronta.');
    $expect(strpos($source,'/radius set [find where service') === false,'Aplicação da instalação ainda altera o RADIUS global.');

    $pdo->rollBack();
    echo "NAS base provisioning OK: {$checks} verificações.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
