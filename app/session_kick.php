<?php

declare(strict_types=1);

require_once __DIR__ . '/nas_base_provisioning.php';

final class FsSessionKickException extends RuntimeException
{
    private string $publicCode;
    private int $httpStatus;

    public function __construct(string $publicCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->publicCode = $publicCode;
        $this->httpStatus = $httpStatus;
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

function fs_session_kick_normalize_mac(string $value): string
{
    $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $value));
    if (strlen($hex) !== 12) return '';
    return implode(':', str_split($hex, 2));
}

/** @return array{radacctid:int,username:string,callingstationid:string,framedipaddress:string,nasipaddress:string,acctstarttime:?string,acctsessiontime:int} */
function fs_session_kick_find_radius_session(PDO $radius, array $input, string $now): array
{
    $radacctId = max(0, (int) ($input['radacctid'] ?? 0));
    $params = [':now' => $now];
    $where = ['(acctstoptime IS NULL OR acctstoptime > :now)'];

    if ($radacctId > 0) {
        $where[] = 'radacctid = :radacctid';
        $params[':radacctid'] = $radacctId;
    } else {
        $username = trim((string) ($input['username'] ?? ''));
        $mac = fs_session_kick_normalize_mac((string) ($input['mac'] ?? ''));
        $ip = trim((string) ($input['ip'] ?? ''));
        if ($username !== '') {
            if (strlen($username) > 128 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
                throw new FsSessionKickException('invalid_selector', 'O identificador da sessão é inválido.');
            }
            $where[] = 'username = :username';
            $params[':username'] = $username;
        }
        if ($mac !== '') {
            $where[] = "REPLACE(REPLACE(UPPER(callingstationid), '-', ''), ':', '') = :mac";
            $params[':mac'] = str_replace(':', '', $mac);
        }
        if ($ip !== '') {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new FsSessionKickException('invalid_selector', 'O endereço da sessão é inválido.');
            }
            $where[] = 'framedipaddress = :ip';
            $params[':ip'] = $ip;
        }
        if (count($where) === 1) {
            throw new FsSessionKickException('missing_selector', 'A sessão não possui um identificador utilizável.');
        }
    }

    $sql = 'SELECT radacctid,username,callingstationid,framedipaddress,nasipaddress,acctstarttime,COALESCE(acctsessiontime,0) acctsessiontime
        FROM radacct WHERE ' . implode(' AND ', $where) . ' ORDER BY acctstarttime DESC LIMIT 2';
    $stmt = $radius->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        throw new FsSessionKickException('session_not_found', 'A sessão já não está ativa no RADIUS.', 404);
    }
    if (count($rows) > 1) {
        throw new FsSessionKickException('session_ambiguous', 'Mais de uma sessão corresponde à seleção. Atualize a tela e tente novamente.', 409);
    }

    $row = $rows[0];
    $nasIp = trim((string) ($row['nasipaddress'] ?? ''));
    if ($nasIp === '') {
        throw new FsSessionKickException('session_without_nas', 'A sessão não informa o NAS de origem.', 409);
    }
    return [
        'radacctid' => (int) $row['radacctid'],
        'username' => trim((string) ($row['username'] ?? '')),
        'callingstationid' => trim((string) ($row['callingstationid'] ?? '')),
        'framedipaddress' => trim((string) ($row['framedipaddress'] ?? '')),
        'nasipaddress' => $nasIp,
        'acctstarttime' => $row['acctstarttime'] !== null ? (string) $row['acctstarttime'] : null,
        'acctsessiontime' => max(0, (int) ($row['acctsessiontime'] ?? 0)),
    ];
}

function fs_session_kick_find_nas(PDO $app, string $nasIp): array
{
    $stmt = $app->prepare('SELECT n.*,COALESCE(h.routeros_version,b.routeros_version) detected_routeros_version
        FROM nas n
        LEFT JOIN nas_health h ON h.nas_id=n.id
        LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id
        WHERE n.nasname=? LIMIT 2');
    $stmt->execute([$nasIp]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        throw new FsSessionKickException('nas_not_registered', 'O NAS desta sessão não está cadastrado no FireSpot.', 409);
    }
    if (count($rows) > 1) {
        throw new FsSessionKickException('nas_ambiguous', 'Existem cadastros duplicados para o NAS desta sessão.', 409);
    }
    $nas = $rows[0];
    if (!fs_nas_is_mikrotik((string) ($nas['type'] ?? ''))) {
        throw new FsSessionKickException('nas_not_mikrotik', 'O equipamento de origem não está confirmado como MikroTik.', 409);
    }
    return $nas;
}

/** @return array{active:string,cookie:?string,label:string} */
function fs_session_kick_selectors(array $session): array
{
    $mac = fs_session_kick_normalize_mac((string) ($session['callingstationid'] ?? ''));
    if ($mac !== '') {
        $selector = 'mac-address=' . fs_routeros_quote($mac);
        return ['active' => $selector, 'cookie' => $selector, 'label' => 'mac'];
    }

    $username = trim((string) ($session['username'] ?? ''));
    if ($username !== '') {
        $selector = 'user=' . fs_routeros_quote($username);
        return ['active' => $selector, 'cookie' => $selector, 'label' => 'user'];
    }

    $ip = trim((string) ($session['framedipaddress'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return ['active' => 'address=' . fs_routeros_quote($ip), 'cookie' => null, 'label' => 'ip'];
    }

    throw new FsSessionKickException('session_without_selector', 'A sessão não possui MAC, usuário ou IP válido.', 409);
}

function fs_session_kick_count($value, string $context): int
{
    $value = trim((string) $value);
    if (!preg_match('/^\d+$/', $value)) {
        throw new FsSessionKickException('routeros_invalid_response', 'O MikroTik não confirmou ' . $context . '.', 502);
    }
    return (int) $value;
}

/**
 * Encerra uma sessão no NAS que originou o accounting e fecha o registro RADIUS.
 * O executor opcional permite testes sem acessar equipamento real.
 */
function fs_session_kick(PDO $app, PDO $radius, array $input, ?callable $executor = null, ?DateTimeImmutable $clock = null): array
{
    $clock = $clock ?: new DateTimeImmutable('now', new DateTimeZone('America/Manaus'));
    $now = $clock->format('Y-m-d H:i:s');
    $session = fs_session_kick_find_radius_session($radius, $input, $now);
    $nas = fs_session_kick_find_nas($app, $session['nasipaddress']);
    $nas = fs_nas_credentials_for_operation($app, $nas);
    $selectors = fs_session_kick_selectors($session);
    $connection = fs_nas_base_connection($nas);

    $commands = [':put [/system resource get version]'];
    $map = ['version' => 0];
    if ($selectors['cookie'] !== null) {
        $map['cookie_before'] = count($commands);
        $commands[] = '/ip hotspot cookie print count-only where ' . $selectors['cookie'];
        $commands[] = '/ip hotspot cookie remove [find where ' . $selectors['cookie'] . ']';
        $map['cookie_after'] = count($commands);
        $commands[] = '/ip hotspot cookie print count-only where ' . $selectors['cookie'];
    }
    $map['active_before'] = count($commands);
    $commands[] = '/ip hotspot active print count-only where ' . $selectors['active'];
    $commands[] = '/ip hotspot active remove [find where ' . $selectors['active'] . ']';
    $map['active_after'] = count($commands);
    $commands[] = '/ip hotspot active print count-only where ' . $selectors['active'];

    $run = $executor ?: static fn(array $commands, array $connection): array => ros_exec($commands, $connection);
    try {
        $result = $run($commands, $connection);
        $outputs = fs_nas_base_assert_command_result($result, 'encerrar a sessão Hotspot', 'remoção da sessão');
    } catch (FsSessionKickException $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('[session kick] nas_id=' . (int) $nas['id'] . ' transport=' . get_class($error));
        throw new FsSessionKickException('nas_connection_failed', 'Não foi possível autenticar no NAS desta sessão.', 502);
    }

    try {
        $version = fs_nas_base_routeros_version((string) ($outputs[$map['version']] ?? ''));
    } catch (Throwable $error) {
        throw new FsSessionKickException('routeros_version_invalid', 'Não foi possível confirmar uma versão RouterOS suportada.', 502);
    }

    $cookieBefore = isset($map['cookie_before']) ? fs_session_kick_count($outputs[$map['cookie_before']] ?? '', 'os cookies ativos') : 0;
    $cookieAfter = isset($map['cookie_after']) ? fs_session_kick_count($outputs[$map['cookie_after']] ?? '', 'a remoção do cookie') : 0;
    $activeBefore = fs_session_kick_count($outputs[$map['active_before']] ?? '', 'a sessão ativa');
    $activeAfter = fs_session_kick_count($outputs[$map['active_after']] ?? '', 'a remoção da sessão ativa');
    if ($cookieAfter > 0 || $activeAfter > 0) {
        throw new FsSessionKickException('routeros_session_remained', 'O MikroTik ainda informa a sessão como ativa.', 502);
    }

    $startedAt = !empty($session['acctstarttime']) ? strtotime((string) $session['acctstarttime']) : false;
    $sessionSeconds = $session['acctsessiontime'];
    if ($startedAt !== false) $sessionSeconds = max($sessionSeconds, max(0, $clock->getTimestamp() - $startedAt));
    $close = $radius->prepare("UPDATE radacct
        SET acctstoptime=:now,acctupdatetime=:now,acctterminatecause='Admin-Reset',acctsessiontime=:seconds
        WHERE radacctid=:id AND (acctstoptime IS NULL OR acctstoptime>:now_check)");
    $close->execute([':now' => $now, ':seconds' => $sessionSeconds, ':id' => $session['radacctid'], ':now_check' => $now]);

    return [
        'ok' => true,
        'nas_id' => (int) $nas['id'],
        'routeros_major' => (int) $version['major'],
        'selector' => $selectors['label'],
        'active' => ['before' => $activeBefore, 'after' => $activeAfter],
        'cookie' => ['before' => $cookieBefore, 'after' => $cookieAfter],
        'radius' => ['closed' => $close->rowCount()],
        'note' => ($activeBefore === 0 && $cookieBefore === 0) ? 'radius_only' : null,
    ];
}
