<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/session_kick.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$app = new PDO('sqlite::memory:');
$radius = new PDO('sqlite::memory:');
foreach ([$app, $radius] as $pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$app->exec('CREATE TABLE nas (
    id INTEGER PRIMARY KEY,nasname TEXT,shortname TEXT,type TEXT,secret TEXT,
    mgmt_username TEXT,mgmt_password TEXT,mgmt_port INTEGER
)');
$app->exec('CREATE TABLE nas_health (nas_id INTEGER PRIMARY KEY,routeros_version TEXT)');
$app->exec('CREATE TABLE nas_base_provisioning (nas_id INTEGER PRIMARY KEY,routeros_version TEXT)');
$app->prepare('INSERT INTO nas (id,nasname,shortname,type,secret,mgmt_username,mgmt_password,mgmt_port) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([2, '192.0.2.10', 'FireSpot', 'mikrotik', 'radius-secret', 'firespot', 'ssh-secret', 4022]);
$app->prepare('INSERT INTO nas_health (nas_id,routeros_version) VALUES (?,?)')->execute([2, '7.20.6 (stable)']);

$radius->exec('CREATE TABLE radacct (
    radacctid INTEGER PRIMARY KEY,username TEXT,callingstationid TEXT,framedipaddress TEXT,nasipaddress TEXT,
    acctstarttime TEXT,acctupdatetime TEXT,acctstoptime TEXT,acctsessiontime INTEGER,acctterminatecause TEXT
)');
$radius->prepare('INSERT INTO radacct (radacctid,username,callingstationid,framedipaddress,nasipaddress,acctstarttime,acctsessiontime) VALUES (?,?,?,?,?,?,?)')
    ->execute([48134, 'cty_test', 'AA-BB-CC-DD-EE-FF', '10.5.5.20', '192.0.2.10', '2026-08-14 11:30:00', 120]);

$commandsSeen = [];
$connectionSeen = [];
$executor = static function (array $commands, array $connection) use (&$commandsSeen, &$connectionSeen): array {
    $commandsSeen = $commands;
    $connectionSeen = $connection;
    return ['ok' => true, 'out' => ['7.23.3 (stable)', '1', '', '0', '1', '', '0'], 'err' => null];
};
$clock = new DateTimeImmutable('2026-08-14 11:50:00', new DateTimeZone('America/Manaus'));
$result = fs_session_kick($app, $radius, [
    'radacctid' => 48134,
    // Valores forjados do navegador não podem substituir os dados do radacct.
    'username' => 'outro',
    'mac' => '11:22:33:44:55:66',
    'ip' => '192.0.2.99',
], $executor, $clock);

$expect($result['ok'] === true, 'Encerramento não retornou sucesso.');
$expect($result['nas_id'] === 2, 'Sessão não foi associada ao NAS correto.');
$expect($result['routeros_major'] === 7, 'Versão real do RouterOS não foi validada.');
$expect($result['selector'] === 'mac', 'MAC do accounting não foi priorizado para encerrar apenas o aparelho.');
$expect($connectionSeen['host'] === '192.0.2.10' && $connectionSeen['user'] === 'firespot' && $connectionSeen['port'] === 4022, 'Credenciais do NAS da sessão não foram usadas.');
$expect(strpos($commandsSeen[1], 'AA:BB:CC:DD:EE:FF') !== false && strpos(implode("\n", $commandsSeen), '11:22:33:44:55:66') === false, 'Seletor confiou nos dados enviados pelo navegador.');
$expect(strpos($commandsSeen[2], '/ip hotspot cookie remove') === 0 && strpos($commandsSeen[5], '/ip hotspot active remove') === 0, 'Cookie não foi removido antes da sessão ativa.');
$closed = $radius->query('SELECT acctstoptime,acctterminatecause,acctsessiontime FROM radacct WHERE radacctid=48134')->fetch();
$expect($closed['acctstoptime'] === '2026-08-14 11:50:00' && $closed['acctterminatecause'] === 'Admin-Reset', 'Accounting não foi encerrado como Admin-Reset.');
$expect((int) $closed['acctsessiontime'] >= 120, 'Tempo da sessão foi reduzido ao encerrar o accounting.');

$radius->prepare('INSERT INTO radacct (radacctid,username,callingstationid,framedipaddress,nasipaddress,acctstarttime,acctsessiontime) VALUES (?,?,?,?,?,?,?)')
    ->execute([48135, 'cty_missing_nas', '00:11:22:33:44:55', '10.5.5.21', '192.0.2.44', '2026-08-14 11:40:00', 60]);
try {
    fs_session_kick($app, $radius, ['radacctid' => 48135], $executor, $clock);
    $expect(false, 'Sessão de NAS não cadastrado foi aceita.');
} catch (FsSessionKickException $error) {
    $expect($error->publicCode() === 'nas_not_registered', 'NAS ausente retornou diagnóstico incorreto.');
}

$radius->prepare('INSERT INTO radacct (radacctid,username,callingstationid,framedipaddress,nasipaddress,acctstarttime,acctsessiontime) VALUES (?,?,?,?,?,?,?)')
    ->execute([48136, 'cty_remains', '00:AA:BB:CC:DD:EE', '10.5.5.22', '192.0.2.10', '2026-08-14 11:42:00', 30]);
$remainsExecutor = static fn(array $commands, array $connection): array => [
    'ok' => true,
    'out' => ['7.23.3 (stable)', '1', '', '0', '1', '', '1'],
    'err' => null,
];
try {
    fs_session_kick($app, $radius, ['radacctid' => 48136], $remainsExecutor, $clock);
    $expect(false, 'RouterOS que manteve a sessão foi tratado como sucesso.');
} catch (FsSessionKickException $error) {
    $expect($error->publicCode() === 'routeros_session_remained', 'Sessão remanescente retornou diagnóstico incorreto.');
}
$expect($radius->query('SELECT acctstoptime IS NULL FROM radacct WHERE radacctid=48136')->fetchColumn() == 1, 'Accounting foi fechado apesar da falha remota.');

echo "Session kick OK: {$checks} verificações.\n";
