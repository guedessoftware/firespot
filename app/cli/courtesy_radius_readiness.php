<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../radius_db.php';

function fs_readiness_command(array $command): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) return ['code' => 127, 'output' => 'comando indisponível'];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'output' => trim((string) $stdout . "\n" . (string) $stderr)];
}

function fs_readiness_attr_string(int $type, string $value): string
{
    if ($type < 1 || $type > 255 || strlen($value) > 253) throw new InvalidArgumentException('Atributo RADIUS inválido.');
    return chr($type) . chr(strlen($value) + 2) . $value;
}

function fs_readiness_attr_int(int $type, int $value): string
{
    return fs_readiness_attr_string($type, pack('N', $value));
}

function fs_readiness_attr_ipv4(int $type, string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 4) throw new InvalidArgumentException('NAS IP do probe deve ser IPv4.');
    return fs_readiness_attr_string($type, $packed);
}

function fs_readiness_user_password(string $password, string $secret, string $authenticator): string
{
    $length = max(16, (int) ceil(strlen($password) / 16) * 16);
    $padded = str_pad($password, $length, "\0");
    $encrypted = '';
    $previous = $authenticator;
    for ($offset = 0; $offset < $length; $offset += 16) {
        $hash = md5($secret . $previous, true);
        $block = substr($padded, $offset, 16) ^ $hash;
        $encrypted .= $block;
        $previous = $block;
    }
    return $encrypted;
}

function fs_readiness_radius_exchange(string $host, int $port, int $code, string $secret, string $attributes): array
{
    $identifier = random_int(0, 255);
    $length = 20 + strlen($attributes);
    $header = pack('CCn', $code, $identifier, $length);
    if ($code === 4) {
        $requestAuthenticator = md5($header . str_repeat("\0", 16) . $attributes . $secret, true);
    } else {
        $requestAuthenticator = random_bytes(16);
    }
    $packet = $header . $requestAuthenticator . $attributes;
    $targetHost = strpos($host, ':') !== false ? '[' . trim($host, '[]') . ']' : $host;
    $socket = @stream_socket_client('udp://' . $targetHost . ':' . $port, $errno, $error, 3);
    if (!is_resource($socket)) throw new RuntimeException('Falha ao abrir UDP do RADIUS: ' . ($error ?: (string) $errno));
    stream_set_timeout($socket, 3);
    fwrite($socket, $packet);
    $response = fread($socket, 4096);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);
    if ($response === false || strlen($response) < 20) {
        throw new RuntimeException(!empty($metadata['timed_out']) ? 'Timeout aguardando o RADIUS.' : 'Resposta RADIUS ausente ou truncada.');
    }
    $responseHeader = unpack('Ccode/Cidentifier/nlength', substr($response, 0, 4));
    $responseLength = (int) ($responseHeader['length'] ?? 0);
    if ((int) ($responseHeader['identifier'] ?? -1) !== $identifier || $responseLength < 20 || strlen($response) < $responseLength) {
        throw new RuntimeException('Resposta RADIUS inválida.');
    }
    $response = substr($response, 0, $responseLength);
    $responseAttributes = substr($response, 20);
    $expectedAuthenticator = md5(
        pack('CCn', (int) $responseHeader['code'], $identifier, $responseLength)
        . $requestAuthenticator . $responseAttributes . $secret,
        true
    );
    if (!hash_equals($expectedAuthenticator, substr($response, 4, 16))) {
        throw new RuntimeException('Authenticator da resposta RADIUS não confere.');
    }
    return ['code' => (int) $responseHeader['code'], 'attributes' => $responseAttributes];
}

function fs_readiness_live_probe(PDO $radius, string $host, int $authPort, int $acctPort, string $secret, string $nasIp): array
{
    $suffix = bin2hex(random_bytes(6));
    $username = 'cty_probe_' . $suffix;
    $password = bin2hex(random_bytes(10));
    $sessionId = 'cty-readiness-' . $suffix;
    $callingStation = '02:00:' . strtoupper(implode(':', str_split(substr($suffix, 0, 8), 2)));
    $authAccepted = false;
    $accountingAccepted = false;
    $accountingPersisted = false;

    $cleanup = static function () use ($radius, $username, $sessionId): void {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            try { $radius->prepare('DELETE FROM ' . $table . ' WHERE username=?')->execute([$username]); } catch (Throwable $e) {}
        }
        try { $radius->prepare('DELETE FROM radacct WHERE username=? AND acctsessionid=?')->execute([$username, $sessionId]); } catch (Throwable $e) {}
    };
    $cleanup();
    try {
        $st = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)");
        $st->execute([$username, 'Cleartext-Password', $password]);
        $st->execute([$username, 'Max-All-Session', '300']);
        $st->execute([$username, 'Simultaneous-Use', '1']);

        // O User-Password depende do authenticator do pacote; monta o Access-Request aqui.
        $identifier = random_int(0, 255);
        $authAttrsWithoutPassword = fs_readiness_attr_string(1, $username);
        $requestAuthenticator = random_bytes(16);
        $authAttrs = $authAttrsWithoutPassword
            . fs_readiness_attr_string(2, fs_readiness_user_password($password, $secret, $requestAuthenticator))
            . fs_readiness_attr_ipv4(4, $nasIp)
            . fs_readiness_attr_int(5, 1)
            . fs_readiness_attr_int(6, 2)
            . fs_readiness_attr_string(31, $callingStation)
            . fs_readiness_attr_string(32, 'firespot-readiness');
        $length = 20 + strlen($authAttrs);
        $packet = pack('CCn', 1, $identifier, $length) . $requestAuthenticator . $authAttrs;
        $targetHost = strpos($host, ':') !== false ? '[' . trim($host, '[]') . ']' : $host;
        $socket = @stream_socket_client('udp://' . $targetHost . ':' . $authPort, $errno, $error, 3);
        if (!is_resource($socket)) throw new RuntimeException('Falha ao abrir UDP de autenticação: ' . ($error ?: (string) $errno));
        stream_set_timeout($socket, 3);
        fwrite($socket, $packet);
        $response = fread($socket, 4096);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);
        if ($response === false || strlen($response) < 20) throw new RuntimeException(!empty($metadata['timed_out']) ? 'Timeout no Access-Request.' : 'Resposta de autenticação inválida.');
        $parsed = unpack('Ccode/Cidentifier/nlength', substr($response, 0, 4));
        $responseLength = (int) ($parsed['length'] ?? 0);
        if ((int) ($parsed['identifier'] ?? -1) !== $identifier || $responseLength < 20 || strlen($response) < $responseLength) throw new RuntimeException('Access-Response inválido.');
        $response = substr($response, 0, $responseLength);
        $expected = md5(pack('CCn', (int) $parsed['code'], $identifier, $responseLength) . $requestAuthenticator . substr($response, 20) . $secret, true);
        if (!hash_equals($expected, substr($response, 4, 16))) throw new RuntimeException('Authenticator do Access-Response não confere.');
        $authAccepted = (int) $parsed['code'] === 2;
        if (!$authAccepted) throw new RuntimeException('RADIUS respondeu Access-Reject (código ' . (int) $parsed['code'] . ').');

        $baseAccounting = fs_readiness_attr_string(1, $username)
            . fs_readiness_attr_ipv4(4, $nasIp)
            . fs_readiness_attr_int(5, 1)
            . fs_readiness_attr_string(31, $callingStation)
            . fs_readiness_attr_string(32, 'firespot-readiness')
            . fs_readiness_attr_string(44, $sessionId)
            . fs_readiness_attr_int(45, 1)
            . fs_readiness_attr_int(55, time());
        $start = fs_readiness_radius_exchange($host, $acctPort, 4, $secret, fs_readiness_attr_int(40, 1) . $baseAccounting);
        if ($start['code'] !== 5) throw new RuntimeException('RADIUS não confirmou Accounting-Start.');
        $interim = fs_readiness_radius_exchange($host, $acctPort, 4, $secret, fs_readiness_attr_int(40, 3) . fs_readiness_attr_int(46, 7) . $baseAccounting);
        if ($interim['code'] !== 5) throw new RuntimeException('RADIUS não confirmou Accounting-Interim.');
        $stop = fs_readiness_radius_exchange($host, $acctPort, 4, $secret, fs_readiness_attr_int(40, 2) . fs_readiness_attr_int(46, 9) . fs_readiness_attr_int(49, 1) . $baseAccounting);
        $accountingAccepted = $stop['code'] === 5;
        if (!$accountingAccepted) throw new RuntimeException('RADIUS não confirmou Accounting-Stop.');

        $st = $radius->prepare('SELECT acctsessiontime,acctstoptime FROM radacct WHERE username=? AND acctsessionid=? LIMIT 1');
        $st->execute([$username, $sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $accountingPersisted = is_array($row) && (int) ($row['acctsessiontime'] ?? 0) >= 9 && !empty($row['acctstoptime']);
        if (!$accountingPersisted) throw new RuntimeException('Accounting respondeu, mas a sessão não foi persistida corretamente em radacct.');
    } finally {
        $cleanup();
    }

    return [
        'auth_accept' => $authAccepted,
        'accounting_response' => $accountingAccepted,
        'accounting_persisted' => $accountingPersisted,
        'temporary_rows_cleaned' => true,
    ];
}

$args = $argv ?? [];
$json = in_array('--json', $args, true);
$probeRequested = in_array('--auth-probe', $args, true);
$markReady = in_array('--mark-ready', $args, true);
$probeHost = trim((string) env('COURTESY_RADIUS_PROBE_HOST', '127.0.0.1'));
$probeSecret = (string) env('COURTESY_RADIUS_PROBE_SECRET', '');
$probeNasIp = trim((string) env('COURTESY_RADIUS_PROBE_NAS_IP', '127.0.0.1'));
$authPort = max(1, (int) env('COURTESY_RADIUS_PROBE_AUTH_PORT', '1812'));
$acctPort = max(1, (int) env('COURTESY_RADIUS_PROBE_ACCT_PORT', '1813'));
if ($markReady && !$probeRequested) {
    fwrite(STDERR, "--mark-ready exige --auth-probe.\n");
    exit(2);
}

$checks = [];
$add = static function (string $name, bool $ok, string $detail, bool $blocking = true) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'blocking' => $blocking, 'detail' => $detail];
};

$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $radius = fs_radius_db();
    $add('radius_database', true, 'Conexão e tabelas radcheck/radreply/radusergroup/radacct disponíveis.');
} catch (Throwable $e) {
    $add('radius_database', false, $e->getMessage());
    $radius = null;
}

$systemctl = is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl' : '/bin/systemctl';
$service = fs_readiness_command([$systemctl, 'is-active', 'freeradius']);
$add('freeradius_service', $service['code'] === 0 && trim($service['output']) === 'active', $service['output'] ?: 'sem resposta');

$ssPath = is_executable('/usr/sbin/ss') ? '/usr/sbin/ss' : (is_executable('/usr/bin/ss') ? '/usr/bin/ss' : '/bin/ss');
$listeners = fs_readiness_command([$ssPath, '-H', '-l', '-u', '-n']);
$authListening = preg_match('/(?:\]|\*|\d):1812\b/', $listeners['output']) === 1;
$acctListening = preg_match('/(?:\]|\*|\d):1813\b/', $listeners['output']) === 1;
$add('udp_1812', $listeners['code'] === 0 && $authListening, $authListening ? 'Porta de autenticação em escuta.' : 'Porta 1812 não encontrada.');
$add('udp_1813', $listeners['code'] === 0 && $acctListening, $acctListening ? 'Porta de accounting em escuta.' : 'Porta 1813 não encontrada.');

if ($radius instanceof PDO) {
    try {
        $nasTable = (bool) $radius->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas' LIMIT 1")->fetchColumn();
        $nasCount = $nasTable ? (int) $radius->query('SELECT COUNT(*) FROM nas')->fetchColumn() : 0;
        $add('radius_clients', $nasCount > 0, $nasCount . ' cliente(s) NAS no banco.');
        if ($nasTable) {
            $st = $radius->prepare('SELECT secret FROM nas WHERE nasname=? LIMIT 1');
            $st->execute([$probeNasIp]);
            $databaseSecret = $st->fetchColumn();
            if ($databaseSecret === false) {
                $add('sql_probe_client', false, 'Nenhum client SQL corresponde ao IP de origem do probe (' . $probeNasIp . '). Um client estático ainda pode ser usado.', false);
            } elseif ($probeSecret === '') {
                $add('sql_probe_client', false, 'O client SQL do probe existe, mas COURTESY_RADIUS_PROBE_SECRET não foi configurado na aplicação.', false);
            } else {
                $add('sql_probe_client', hash_equals((string) $databaseSecret, $probeSecret), hash_equals((string) $databaseSecret, $probeSecret)
                    ? 'Client SQL e segredo dedicado do probe correspondem.'
                    : 'O segredo configurado para o probe não corresponde ao client SQL.', false);
            }
        }
    } catch (Throwable $e) {
        $add('radius_clients', false, 'Falha ao auditar clientes NAS: ' . $e->getMessage());
    }
    try {
        $recent = (int) $radius->query('SELECT COUNT(*) FROM radacct WHERE COALESCE(acctupdatetime,acctstoptime,acctstarttime)>=NOW()-INTERVAL 24 HOUR')->fetchColumn();
        $add('recent_accounting', $recent > 0, $recent . ' sessão(ões) com accounting nas últimas 24h.', false);
    } catch (Throwable $e) {
        $add('recent_accounting', false, 'Falha ao consultar accounting: ' . $e->getMessage(), false);
    }
}

$probe = null;
if ($probeRequested && $radius instanceof PDO) {
    if ($probeSecret === '') {
        $add('live_auth_accounting_probe', false, 'COURTESY_RADIUS_PROBE_SECRET não configurado.');
    } else {
        try {
            $probe = fs_readiness_live_probe($radius, $probeHost, $authPort, $acctPort, $probeSecret, $probeNasIp);
            $add('live_auth_accounting_probe', true, 'Access-Accept, Start, Interim, Stop e persistência confirmados; registros temporários removidos.');
        } catch (Throwable $e) {
            $add('live_auth_accounting_probe', false, $e->getMessage());
        }
    }
} else {
    $add('live_auth_accounting_probe', false, 'Não executado. Use --auth-probe com um segredo dedicado configurado.');
}

$blockingFailures = array_values(array_filter($checks, static fn(array $check): bool => $check['blocking'] && !$check['ok']));
$ready = count($blockingFailures) === 0;
if ($markReady && $ready) {
    $st = $app->prepare("INSERT INTO app_settings (skey,svalue) VALUES ('courtesy_radius_ready','1') ON DUPLICATE KEY UPDATE svalue='1'");
    $st->execute();
}

$result = [
    'ready' => $ready,
    'marked_ready' => $markReady && $ready,
    'checks' => $checks,
    'probe' => $probe,
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($checks as $check) {
        echo '[' . ($check['ok'] ? 'OK' : ($check['blocking'] ? 'BLOQUEIO' : 'AVISO')) . '] '
            . $check['name'] . ': ' . $check['detail'] . PHP_EOL;
    }
    echo 'Resultado: RADIUS ' . ($ready ? 'PRONTO' : 'NÃO PRONTO') . ($markReady && $ready ? ' (gate atualizado)' : '') . ".\n";
}
exit($ready ? 0 : 2);
