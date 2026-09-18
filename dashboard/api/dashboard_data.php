<?php
// /dashboard/api/dashboard_data.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (!isset($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../app/db.php';

if (!defined('FIRESPOT_DASH_NOCACHE')) {
    define('FIRESPOT_DASH_NOCACHE', isset($_GET['dash_nocache']) || isset($_GET['nocache']));
}

/**
 * Simple cross-request cache for heavy dashboard blocks.
 * Uses APCu when available, otherwise falls back to temp files.
 */
function dashboard_cache(string $key, int $ttl, callable $producer)
{
    if (FIRESPOT_DASH_NOCACHE || $ttl <= 0) {
        return $producer();
    }

    static $memory = [];
    $now = time();
    $cacheKey = 'firespot_dash_' . $key;

    if (isset($memory[$cacheKey]) && ($memory[$cacheKey]['expires'] ?? 0) >= $now) {
        return $memory[$cacheKey]['value'];
    }

    $payload = null;

    if (function_exists('apcu_fetch')) {
        $payload = apcu_fetch($cacheKey, $hit);
        if ($hit && is_array($payload) && ($payload['expires'] ?? 0) >= $now) {
            $memory[$cacheKey] = $payload;
            return $payload['value'];
        }
        $payload = null;
    }

    if ($payload === null) {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey . '.cache';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $stored = @unserialize($raw);
                if (is_array($stored) && ($stored['expires'] ?? 0) >= $now) {
                    $memory[$cacheKey] = $stored;
                    return $stored['value'];
                }
            }
        }
    }

    $value = $producer();
    $payload = ['expires' => $now + $ttl, 'value' => $value];
    $memory[$cacheKey] = $payload;

    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, $payload, $ttl);
    } else {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey . '.cache';
        @file_put_contents($file, serialize($payload), LOCK_EX);
    }

    return $value;
}

try {
    $pdo = db(); // usa sua conexão existente (PDO)
    try { $pdo->query("SET time_zone = 'America/Manaus'"); } catch (\Throwable $e) {}

    // ===== KPIs (sem joins; consultas simples e estáveis) =====
    $kpis = kpis($pdo);

    // ===== Lista de clientes online agora (sem joins que causem collation) =====
    $online = onlineAgoraLista($pdo);

    // ===== Gráficos =====
    // porHora24h → mantém o nome esperado pelo front, mas calcula concorrência por hora (12h)
    $picoPorHora12h = seriePicoOnlinePorHora12h($pdo);
    $trafegoPorDia7d = serieTrafegoHoje($pdo);
    
    $graficos = [
        'porHora24h' => serieOnlinePorHora12hPHP($pdo),
        'porDia7d'   => serieConexoesPorDia7d($pdo),
        'picoPorHora12h' => [
            'labels' => array_column($picoPorHora12h, 'label'),
            'data'   => array_column($picoPorHora12h, 'value')
        ],
        'trafegoPorDia7d' => [
            'labels' => array_column($trafegoPorDia7d, 'label'),
            'data'   => array_map(function($bytes) {
                return round($bytes / 1048576, 2); // Convert to MB
            }, array_column($trafegoPorDia7d, 'value'))
        ],
    ];

    $deviceInsights = deviceInsights($pdo);
    $nasHealth = nasHealthSnapshot($pdo);

    echo json_encode([
        'ok'             => true,
        'kpis'           => $kpis,
        'online'         => $online,
        'graficos'       => $graficos,
        'deviceInsights' => $deviceInsights,
        'nasHealth'      => $nasHealth,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("dashboard_data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DASHBOARD_QUERY_FAILED']);
}

/* =======================================================================
   KPIs
   ======================================================================= */
function kpis(PDO $pdo): array {
    // Online agora (sessões ativas)
    $onlineAgora = (int)$pdo->query("
        SELECT COUNT(DISTINCT COALESCE(NULLIF(username,''), callingstationid, CONCAT('sess#',radacctid)))
        FROM radacct
        WHERE (acctstoptime IS NULL OR acctstoptime > NOW())
          AND acctstarttime <= NOW()
    ")->fetchColumn();

    $trafegoHojeBytes = (int)$pdo->query("
        SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)), 0)
        FROM radacct
        WHERE acctstarttime >= CURDATE()
    ")->fetchColumn();

    $usuariosCadastrados = (int)dashboard_cache('kpi_users_total', 60, function () use ($pdo) {
        return (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck")->fetchColumn();
    });

    $dispositivos30d = (int)dashboard_cache('kpi_devices_30d', 60, function () use ($pdo) {
        return (int)$pdo->query(
            "SELECT COUNT(DISTINCT callingstationid)
             FROM radacct
             WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();
    });

    $dispositivosTotais = (int)dashboard_cache('kpi_devices_total', 300, function () use ($pdo) {
        return (int)$pdo->query("SELECT COUNT(DISTINCT callingstationid) FROM radacct")->fetchColumn();
    });

    $novosUsuarios7d = (int)dashboard_cache('kpi_new_users7d', 300, function () use ($pdo) {
        $sql = "SELECT COUNT(*) FROM (
          SELECT username, MIN(acctstarttime) AS first_seen
          FROM radacct
          WHERE username IS NOT NULL AND username <> ''
          GROUP BY username
          HAVING first_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) t";
        return (int)$pdo->query($sql)->fetchColumn();
    });

    return [
        'onlineAgora'         => $onlineAgora,
        'usuariosCadastrados' => $usuariosCadastrados,
        'dispositivos30d'     => $dispositivos30d,
        'trafegoHojeBytes'    => $trafegoHojeBytes,
        'dispositivosTotais'  => $dispositivosTotais,
        'novosUsuarios7d'     => $novosUsuarios7d,
    ];
}

/* =======================================================================
   Lista de sessões ativas agora (nome preservado, sem mix de collations)
   ======================================================================= */
function onlineAgoraLista(PDO $pdo): array {
    // Primeiro, busca os NAS para mapear nasipaddress para nomes
    $nasMap = [];
    try {
        $nasSt = $pdo->query("SELECT nasname, shortname, description FROM nas");
        while ($nas = $nasSt->fetch()) {
            $label = $nas['shortname'] ?: $nas['nasname'];
            if ($nas['description'] && $nas['description'] !== 'RADIUS Client') {
                $label .= ' (' . $nas['description'] . ')';
            }
            $nasMap[$nas['nasname']] = $label;
        }
    } catch (\Throwable $e) {
        // Se falhar, continua sem o mapeamento
    }

    // Busca hosts (partners) para mapear códigos para nomes amigáveis
    $hostInfoByCode = [];
    try {
        $hostsSt = $pdo->query("SELECT code, name FROM partners");
        while ($host = $hostsSt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)($host['code'] ?? ''));
            if ($code === '') continue;
            $name = trim((string)($host['name'] ?? ''));
            $hostInfoByCode[$code] = [
                'code' => $code,
                'name' => $name,
            ];
        }
    } catch (\Throwable $e) {
        // Tabela pode não existir; segue sem nomes de host
    }

    // Tenta ler device_info; se a coluna não existir, cai para sem ela.
    $sqlWithDevice = "
            SELECT
                radacctid                                   AS radacctid,
                COALESCE(NULLIF(username,''), '—')          AS username,
                framedipaddress                              AS ip,
                nasipaddress                                 AS servidor,
                nasportid                                    AS port_id,
                callingstationid                             AS mac,
                acctstarttime                                AS start_time,
                TIMESTAMPDIFF(SECOND, acctstarttime, NOW())  AS segs,
                COALESCE(acctinputoctets,0)                  AS in_octets,
                COALESCE(acctoutputoctets,0)                 AS out_octets,
                COALESCE(device_info,'')                     AS device_info
      FROM radacct
      WHERE (acctstoptime IS NULL OR acctstoptime > NOW())
        AND acctstarttime <= NOW()
      ORDER BY acctstarttime DESC
      LIMIT 200
    ";
    $sqlWithoutDevice = "
            SELECT
                radacctid                                   AS radacctid,
                COALESCE(NULLIF(username,''), '—')          AS username,
                framedipaddress                              AS ip,
                nasipaddress                                 AS servidor,
                nasportid                                    AS port_id,
                callingstationid                             AS mac,
                acctstarttime                                AS start_time,
                TIMESTAMPDIFF(SECOND, acctstarttime, NOW())  AS segs,
                COALESCE(acctinputoctets,0)                  AS in_octets,
                COALESCE(acctoutputoctets,0)                 AS out_octets
      FROM radacct
      WHERE (acctstoptime IS NULL OR acctstoptime > NOW())
        AND acctstarttime <= NOW()
      ORDER BY acctstarttime DESC
      LIMIT 200
    ";

    try {
        $rows = $pdo->query($sqlWithDevice)->fetchAll(PDO::FETCH_ASSOC);
        $hasDevice = true;
    } catch (\Throwable $e) {
        $rows = $pdo->query($sqlWithoutDevice)->fetchAll(PDO::FETCH_ASSOC);
        $hasDevice = false;
    }

    // Coleta CPFs (usernames) não vazios para buscar nome na clientes_info SEM JOIN
    $usernames = [];
    foreach ($rows as $r) {
        $u = trim((string)$r['username']);
        if ($u !== '' && $u !== '—') $usernames[$u] = true;
    }
    $clienteInfo = [];
    $phoneNameMap = [];
    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));
        $st = $pdo->prepare("SELECT cpf, nome, telefone, vip_ativo, vip_until FROM clientes_info WHERE cpf IN ($place)");
        $st->execute(array_keys($usernames));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $cpf = trim((string)($ci['cpf'] ?? ''));
            if ($cpf === '') continue;
            $clienteInfo[$cpf] = [
                'nome' => trim((string)($ci['nome'] ?? '')),
                'telefone' => trim((string)($ci['telefone'] ?? '')),
                'vip_ativo' => (int)($ci['vip_ativo'] ?? 0),
                'vip_until' => $ci['vip_until'] ?? null,
            ];
            $telDigits = digits_only((string)($ci['telefone'] ?? ''));
            if ($telDigits !== '' && !isset($phoneNameMap[$telDigits])) {
                $phoneNameMap[$telDigits] = trim((string)($ci['nome'] ?? ''));
            }
        }
    }

    $userLimits = [];
    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));

        $sqlCheck = "SELECT username, attribute, CAST(value AS UNSIGNED) AS val
                      FROM radcheck
                      WHERE attribute IN ('Max-All-Session','Max-Daily-Session','Session-Timeout')
                        AND username IN ($place)";
        $st = $pdo->prepare($sqlCheck);
        $st->execute(array_keys($usernames));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userKey = trim((string)($row['username'] ?? ''));
            if ($userKey === '') continue;
            $attr = strtoupper((string)($row['attribute'] ?? ''));
            $val  = (int)($row['val'] ?? 0);
            if (!isset($userLimits[$userKey])) {
                $userLimits[$userKey] = [];
            }
            if ($attr === 'SESSION-TIMEOUT') {
                $userLimits[$userKey]['session_timeout'] = max($val, (int)($userLimits[$userKey]['session_timeout'] ?? 0));
            } elseif ($attr === 'MAX-ALL-SESSION') {
                $userLimits[$userKey]['max_all_session'] = max($val, (int)($userLimits[$userKey]['max_all_session'] ?? 0));
            } elseif ($attr === 'MAX-DAILY-SESSION') {
                $userLimits[$userKey]['max_daily_session'] = max($val, (int)($userLimits[$userKey]['max_daily_session'] ?? 0));
            }
        }

        $sqlReply = "SELECT username, attribute, CAST(value AS UNSIGNED) AS val
                      FROM radreply
                      WHERE attribute IN ('Session-Timeout')
                        AND username IN ($place)";
        $st = $pdo->prepare($sqlReply);
        $st->execute(array_keys($usernames));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userKey = trim((string)($row['username'] ?? ''));
            if ($userKey === '') continue;
            $attr = strtoupper((string)($row['attribute'] ?? ''));
            $val  = (int)($row['val'] ?? 0);
            if ($attr === 'SESSION-TIMEOUT') {
                if (!isset($userLimits[$userKey])) {
                    $userLimits[$userKey] = [];
                }
                $userLimits[$userKey]['session_timeout'] = max($val, (int)($userLimits[$userKey]['session_timeout'] ?? 0));
            }
        }
    }

    $phoneLookupDigits = [];
    if (!empty($usernames)) {
        foreach ($usernames as $cpfKey => $_) {
            if (isset($clienteInfo[$cpfKey])) {
                continue;
            }
            $digits = digits_only((string)$cpfKey);
            if ($digits === '' || strlen($digits) < 8) {
                continue;
            }
            $phoneLookupDigits[$digits] = true;
        }
    }

    if (!empty($phoneLookupDigits)) {
        $place = implode(',', array_fill(0, count($phoneLookupDigits), '?'));
        $st = $pdo->prepare("SELECT telefone, nome FROM clientes_info WHERE telefone IN ($place)");
        $st->execute(array_keys($phoneLookupDigits));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $telDigits = digits_only((string)($row['telefone'] ?? ''));
            if ($telDigits === '' || isset($phoneNameMap[$telDigits])) {
                continue;
            }
            $phoneNameMap[$telDigits] = trim((string)($row['nome'] ?? ''));
        }
    }

    // (Opcional) visitas: agregamos em lote para não fazer N queries
    $mapVisUsr = [];
    $usageSecondsByUser = [];
    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));
        $st = $pdo->prepare(
            "SELECT username,
                    COUNT(*) AS c,
                    SUM(CASE
                          WHEN acctstoptime IS NULL THEN TIMESTAMPDIFF(SECOND, acctstarttime, NOW())
                          ELSE COALESCE(acctsessiontime, TIMESTAMPDIFF(SECOND, acctstarttime, acctstoptime))
                        END) AS used
             FROM radacct
             WHERE username IN ($place)
             GROUP BY username"
        );
        $st->execute(array_keys($usernames));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $userKey = (string)$v['username'];
            $mapVisUsr[$userKey] = (int)$v['c'];
            $usageSecondsByUser[$userKey] = (int)($v['used'] ?? 0);
        }
    }

    $usageTodaySecondsByUser = [];
    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));
        $sql = "SELECT username,
                        SUM(
                            CASE
                                WHEN acctstoptime IS NULL THEN
                                    GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST(acctstarttime, CURDATE()), NOW()))
                                ELSE
                                    GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST(acctstarttime, CURDATE()), acctstoptime))
                            END
                        ) AS used_today
                FROM radacct
                WHERE username IN ($place)
                  AND COALESCE(acctstoptime, NOW()) > CURDATE()
                GROUP BY username";
        $st = $pdo->prepare($sql);
        $st->execute(array_keys($usernames));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userKey = (string)($row['username'] ?? '');
            if ($userKey === '') continue;
            $usageTodaySecondsByUser[$userKey] = (int)($row['used_today'] ?? 0);
        }
    }
    // Para MACs (apenas onde username é vazio)
    $macs = [];
    foreach ($rows as $r) {
        if (trim((string)$r['username']) === '' || trim((string)$r['username']) === '—') {
            $m = trim((string)$r['mac']);
            if ($m !== '') $macs[$m] = true;
        }
    }
    $mapVisMac = [];
    if (!empty($macs)) {
        $place = implode(',', array_fill(0, count($macs), '?'));
        $st = $pdo->prepare("SELECT callingstationid AS mac, COUNT(*) c FROM radacct WHERE callingstationid IN ($place) GROUP BY callingstationid");
        $st->execute(array_keys($macs));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $mapVisMac[(string)$v['mac']] = (int)$v['c'];
        }
    }

    $primaryGroupByUser = [];
    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));
        $sql = "SELECT username, groupname FROM radusergroup WHERE username IN ($place) ORDER BY username ASC, COALESCE(priority, 999999) ASC, groupname ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array_keys($usernames));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $userKey = trim((string)($row['username'] ?? ''));
            if ($userKey === '') continue;
            if (!isset($primaryGroupByUser[$userKey])) {
                $primaryGroupByUser[$userKey] = (string)($row['groupname'] ?? '');
            }
        }
    }

    $groupLimits = [];
    if (!empty($primaryGroupByUser)) {
        $allGroups = array_values(array_unique(array_filter($primaryGroupByUser)));
        if (!empty($allGroups)) {
            $place = implode(',', array_fill(0, count($allGroups), '?'));

            $sqlGroupCheck = "SELECT groupname, attribute, CAST(value AS UNSIGNED) AS val
                               FROM radgroupcheck
                               WHERE attribute IN ('Max-All-Session','Max-Daily-Session')
                                 AND groupname IN ($place)";
            $st = $pdo->prepare($sqlGroupCheck);
            $st->execute($allGroups);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $group = trim((string)($row['groupname'] ?? ''));
                if ($group === '') continue;
                $attr = strtoupper((string)($row['attribute'] ?? ''));
                $val  = (int)($row['val'] ?? 0);
                if (!isset($groupLimits[$group])) {
                    $groupLimits[$group] = [];
                }
                if ($attr === 'MAX-ALL-SESSION') {
                    $groupLimits[$group]['max_all_session'] = max($val, (int)($groupLimits[$group]['max_all_session'] ?? 0));
                } elseif ($attr === 'MAX-DAILY-SESSION') {
                    $groupLimits[$group]['max_daily_session'] = max($val, (int)($groupLimits[$group]['max_daily_session'] ?? 0));
                }
            }

            $sqlGroupReply = "SELECT groupname, attribute, CAST(value AS UNSIGNED) AS val
                               FROM radgroupreply
                               WHERE attribute IN ('Session-Timeout')
                                 AND groupname IN ($place)";
            $st = $pdo->prepare($sqlGroupReply);
            $st->execute($allGroups);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $group = trim((string)($row['groupname'] ?? ''));
                if ($group === '') continue;
                $attr = strtoupper((string)($row['attribute'] ?? ''));
                $val  = (int)($row['val'] ?? 0);
                if ($attr === 'SESSION-TIMEOUT') {
                    if (!isset($groupLimits[$group])) {
                        $groupLimits[$group] = [];
                    }
                    $groupLimits[$group]['session_timeout'] = max($val, (int)($groupLimits[$group]['session_timeout'] ?? 0));
                }
            }
        }
    }

    $deviceMetaByMac = [];
    $deviceMetaByUser = [];
    if (!empty($macs)) {
        $place = implode(',', array_fill(0, count($macs), '?'));
        $sql = "SELECT mac, os, device_label, user_agent FROM clientes_dispositivos WHERE mac IN ($place) ORDER BY COALESCE(last_seen, first_seen) DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array_keys($macs));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $macKey = strtolower(trim((string)($row['mac'] ?? '')));
            if ($macKey === '') continue;
            if (!isset($deviceMetaByMac[$macKey])) {
                $deviceMetaByMac[$macKey] = [
                    'os' => $row['os'] ?? '',
                    'label' => $row['device_label'] ?? '',
                    'ua' => $row['user_agent'] ?? '',
                ];
            }
        }
    }

    if (!empty($usernames)) {
        $place = implode(',', array_fill(0, count($usernames), '?'));
        $sql = "SELECT username, os, device_label, user_agent FROM clientes_dispositivos WHERE username IN ($place) ORDER BY COALESCE(last_seen, first_seen) DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array_keys($usernames));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $userKey = trim((string)($row['username'] ?? ''));
            if ($userKey === '') continue;
            if (!isset($deviceMetaByUser[$userKey])) {
                $deviceMetaByUser[$userKey] = [
                    'os' => $row['os'] ?? '',
                    'label' => $row['device_label'] ?? '',
                    'ua' => $row['user_agent'] ?? '',
                ];
            }
        }
    }

    // Mapeia host de origem a partir de partner_uses (quando existir)
    $ips = [];
    foreach ($rows as $r) {
        $ip = trim((string)($r['ip'] ?? ''));
        if ($ip !== '') $ips[$ip] = true;
    }

    $hostByMac = [];
    $hostByUsername = [];
    $hostByIp = [];
    if (!empty($hostInfoByCode)) {
        // Busca por MAC
        if (!empty($macs)) {
            try {
                $place = implode(',', array_fill(0, count($macs), '?'));
                $sql = "SELECT mac, code FROM partner_uses WHERE mac IN ($place) ORDER BY used_at DESC";
                $st = $pdo->prepare($sql);
                $st->execute(array_keys($macs));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $macKey = strtolower(trim((string)$row['mac']));
                    if ($macKey === '') continue;
                    if (!isset($hostByMac[$macKey])) {
                        $hostByMac[$macKey] = (string)($row['code'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                $hostByMac = [];
            }
        }

        // Busca por username
        if (!empty($usernames)) {
            try {
                $place = implode(',', array_fill(0, count($usernames), '?'));
                $sql = "SELECT username, code FROM partner_uses WHERE username IN ($place) ORDER BY used_at DESC";
                $st = $pdo->prepare($sql);
                $st->execute(array_keys($usernames));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $userKey = mb_strtolower(trim((string)$row['username']));
                    if ($userKey === '') continue;
                    if (!isset($hostByUsername[$userKey])) {
                        $hostByUsername[$userKey] = (string)($row['code'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                $hostByUsername = [];
            }
        }

        // Busca por IP
        if (!empty($ips)) {
            try {
                $place = implode(',', array_fill(0, count($ips), '?'));
                $sql = "SELECT ip, code FROM partner_uses WHERE ip IN ($place) ORDER BY used_at DESC";
                $st = $pdo->prepare($sql);
                $st->execute(array_keys($ips));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $ipKey = trim((string)$row['ip']);
                    if ($ipKey === '') continue;
                    if (!isset($hostByIp[$ipKey])) {
                        $hostByIp[$ipKey] = (string)($row['code'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                $hostByIp = [];
            }
        }
    }

    // Montagem final preservando o "nome" como no exemplo do usuário
    $out = [];
    foreach ($rows as $r) {
        $username = (string)$r['username'];
        $cliInfo  = $clienteInfo[$username] ?? null;
        $nomeBase = $cliInfo['nome'] ?? '';
        $nome = '';
        if ($nomeBase !== '') {
            $nome = $nomeBase;
        }

        if ($nome === '') {
            $digitsUsername = digits_only($username);
            if ($digitsUsername !== '' && isset($phoneNameMap[$digitsUsername]) && $phoneNameMap[$digitsUsername] !== '') {
                $nome = $phoneNameMap[$digitsUsername];
            }
        }

        if ($nome === '') {
            $nome = ($username !== '' && $username !== '—') ? $username : '—';
        }
        
        // Mapear servidor (nasipaddress) para nome amigável
        $servidor = (string)($r['servidor'] ?? '');
        $nasLabel = $nasMap[$servidor] ?? $servidor;

        // Determinar host de origem real (ponto de acesso)
        $hostCode = '';
        $macKey = strtolower(trim((string)$r['mac'] ?? ''));
        if ($macKey !== '' && isset($hostByMac[$macKey]) && $hostByMac[$macKey] !== '') {
            $hostCode = $hostByMac[$macKey];
        }

            if ($hostCode === '') {
                $portId = (string)($r['port_id'] ?? '');
                if ($portId !== '' && preg_match('/-([A-Za-z0-9]+)$/', $portId, $m)) {
                    $hostCode = $m[1];
                }
            }

            if ($hostCode === '') {
                $userKey = mb_strtolower(trim($username));
                if ($userKey !== '' && isset($hostByUsername[$userKey]) && $hostByUsername[$userKey] !== '') {
                    $hostCode = $hostByUsername[$userKey];
                }
            }

            if ($hostCode === '') {
                $ipKey = trim((string)$r['ip'] ?? '');
                if ($ipKey !== '' && isset($hostByIp[$ipKey]) && $hostByIp[$ipKey] !== '') {
                    $hostCode = $hostByIp[$ipKey];
                }
            }

        $hostOrigem = $nasLabel;
        if ($hostCode !== '') {
            $info = $hostInfoByCode[$hostCode] ?? null;
            if ($info) {
                $hostName = trim($info['name']);
                if ($hostName !== '') {
                    $hostOrigem = strpos($hostName, $hostCode) !== false
                        ? $hostName
                        : $hostCode . ' - ' . $hostName;
                } else {
                    $hostOrigem = $hostCode;
                }
            } else {
                $hostOrigem = $hostCode;
            }
        }

        $ua = $hasDevice ? (string)($r['device_info'] ?? '') : '';
        $macRaw = (string)($r['mac'] ?? '');
        $macKey = strtolower(trim($macRaw));
        $deviceMeta = $macKey !== '' ? ($deviceMetaByMac[$macKey] ?? null) : null;
        if (!$deviceMeta && $username !== '' && $username !== '—') {
            $deviceMeta = $deviceMetaByUser[$username] ?? null;
        }
        if ($ua === '' && $deviceMeta && !empty($deviceMeta['ua'])) {
            $ua = (string)$deviceMeta['ua'];
        }
        $deviceLabel = '';
        $emoji = '';

        if ($deviceMeta) {
            $osRaw = (string)($deviceMeta['os'] ?? '');
            if ($osRaw !== '') {
                $deviceLabel = normalizeOsLabel($osRaw);
            }
            if ($deviceLabel === '' && !empty($deviceMeta['label'])) {
                $deviceLabel = trim((string)$deviceMeta['label']);
            }
            if ($deviceLabel !== '') {
                $emoji = osEmojiByLabel($deviceLabel);
            }
        }

        if ($emoji === '') {
            $emoji = deviceEmoji($ua);
        }
        if ($deviceLabel === '' && $ua !== '') {
            $deviceLabel = normalizeOsLabel($ua);
        }
        if ($deviceLabel === '') {
            $deviceLabel = 'Outros';
        }

        $planLabel = 'Gratuito';
        if ($username !== '' && $username !== '—') {
            $groupRaw = $primaryGroupByUser[$username] ?? '';
            $planLabel = resolvePlanLabel($cliInfo, $groupRaw);
        } elseif (isVipClienteActive($cliInfo)) {
            $planLabel = 'Premium';
        }

        $visitas = 0;
        if ($username !== '' && $username !== '—') {
            $visitas = $mapVisUsr[$username] ?? 0;
        } else {
            $m = trim((string)$r['mac']);
            if ($m !== '') $visitas = $mapVisMac[$m] ?? 0;
        }

        $tempoSessaoSegs = max(0, (int)$r['segs']);
        $tempoSessaoLabel = trim(segsHumano($tempoSessaoSegs));
        if ($tempoSessaoLabel === '') {
            $tempoSessaoLabel = '0s';
        }

        $tempoDisplay = $tempoSessaoLabel;
        $tempoRestanteLabel = null;
        $tempoTotalLabel = null;
        $tempoRestanteSegs = null;

        $usernameKey = ($username !== '' && $username !== '—') ? $username : null;
        if ($usernameKey !== null) {
            $groupCandidate = $primaryGroupByUser[$usernameKey] ?? '';
            $limitsEffective = [];
            if ($groupCandidate !== '' && isset($groupLimits[$groupCandidate])) {
                $limitsEffective = $groupLimits[$groupCandidate];
            }
            if (isset($userLimits[$usernameKey])) {
                $limitsEffective = array_merge($limitsEffective, $userLimits[$usernameKey]);
            }

            if (!empty($limitsEffective)) {
                $limitMode = null;
                $allowedSeconds = null;

                if (!empty($limitsEffective['session_timeout'])) {
                    $allowedSeconds = (int)$limitsEffective['session_timeout'];
                    $limitMode = 'session';
                } elseif (!empty($limitsEffective['max_all_session'])) {
                    $allowedSeconds = (int)$limitsEffective['max_all_session'];
                    $limitMode = 'total';
                } elseif (!empty($limitsEffective['max_daily_session'])) {
                    $allowedSeconds = (int)$limitsEffective['max_daily_session'];
                    $limitMode = 'daily';
                }

                if ($allowedSeconds !== null) {
                    if ($allowedSeconds > 0) {
                        $tempoTotalLabel = trim(segsHumano($allowedSeconds));
                        if ($tempoTotalLabel === '') {
                            $tempoTotalLabel = '0s';
                        }

                        if ($limitMode === 'session') {
                            $tempoRestanteSegs = max(0, $allowedSeconds - $tempoSessaoSegs);
                        } elseif ($limitMode === 'daily') {
                            $usedToday = max(0, (int)($usageTodaySecondsByUser[$usernameKey] ?? 0));
                            $tempoRestanteSegs = max(0, $allowedSeconds - $usedToday);
                        } else {
                            $usedSeconds = max(0, (int)($usageSecondsByUser[$usernameKey] ?? 0));
                            $tempoRestanteSegs = max(0, $allowedSeconds - $usedSeconds);
                        }

                        $tempoRestanteLabel = trim(segsHumano($tempoRestanteSegs));
                        if ($tempoRestanteLabel === '') {
                            $tempoRestanteLabel = '0s';
                        }
                        $tempoDisplay = $tempoSessaoLabel . '/' . $tempoRestanteLabel;
                    } else {
                        $tempoRestanteLabel = '∞';
                        $tempoTotalLabel = '∞';
                        $tempoDisplay = $tempoSessaoLabel . '/∞';
                    }
                }
            }
        }

        if ($tempoRestanteLabel === null) {
            $tempoRestanteLabel = '—';
        }
        if (strpos($tempoDisplay, '/') === false) {
            $tempoDisplay = $tempoSessaoLabel . '/' . $tempoRestanteLabel;
        }

        $out[] = [
            'radacctid'   => (int)($r['radacctid'] ?? 0),
            'nome'        => $nome,
            'username'    => $username !== '—' ? $username : '',
            'dispositivo' => $emoji,
            'deviceLabel' => $deviceLabel,
            'ip'          => (string)($r['ip'] ?? ''),
            'servidor'    => (string)($r['servidor'] ?? ''),
            'hostOrigem'  => $hostOrigem,
            'nasLabel'    => $nasLabel,
            'hostCode'    => $hostCode,
            'mac'         => (string)($r['mac'] ?? ''),
            'tempoOnline' => $tempoDisplay,
            'tempoSessao' => $tempoSessaoLabel,
            'tempoSessaoSegs' => $tempoSessaoSegs,
            'tempoRestante' => $tempoRestanteLabel,
            'tempoRestanteSegs' => $tempoRestanteSegs,
            'tempoTotal' => $tempoTotalLabel,
            'in'          => (int)$r['in_octets'],
            'out'         => (int)$r['out_octets'],
            'perfil'      => $planLabel,
            'visitas'     => $visitas,
        ];
    }
    return $out;
}

/* =======================================================================
   Gráfico: concorrência por hora (12h, L→R) calculado em PHP (robusto)
   ======================================================================= */
function serieOnlinePorHora12hPHP(PDO $pdo): array {
    $now = new DateTimeImmutable('now');
    $startWindow = $now->sub(new DateInterval('PT12H'));

    // Busca sessões que se sobrepõem à janela (sem JOIN/CTE → sem collation issues)
    $sql = "
        SELECT
          radacctid,
          COALESCE(username, '')         AS username,
          COALESCE(callingstationid, '') AS mac,
          acctstarttime                   AS start_time,
          COALESCE(acctstoptime, NOW())  AS stop_time
        FROM radacct
        WHERE acctstarttime < NOW()
          AND COALESCE(acctstoptime, NOW()) > :win_start
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':win_start' => $startWindow->format('Y-m-d H:i:s')]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Prepara 12 buckets (antigo→recente), cada um com set de identidades únicas
    $buckets = [];
    for ($i = 11; $i >= 0; $i--) {
        $hStart = $now->sub(new DateInterval('PT' . $i . 'H'));
        $hStart = $hStart->setTime((int)$hStart->format('H'), 0, 0);
        $hEnd   = $hStart->add(new DateInterval('PT1H'));
        $buckets[] = ['start' => $hStart, 'end' => $hEnd, 'ids' => []];
    }

    foreach ($rows as $r) {
        $start = new DateTimeImmutable($r['start_time']);
        $stop  = new DateTimeImmutable($r['stop_time']);

        // limita aos limites da janela
        if ($stop <= $startWindow || $start >= $now) continue;

        // identidade estável: username (se houver) → MAC → fallback por sessão
        $uid = trim((string)$r['username']);
        if ($uid === '') {
            $mac = strtolower(trim((string)$r['mac']));
            if ($mac !== '') $uid = "mac:" . $mac;
        }
        if ($uid === '') $uid = 'sess#' . (string)$r['radacctid'];

        // marca presença em cada hora onde há sobreposição (start < end && stop >= start)
        foreach ($buckets as &$b) {
            if ($start < $b['end'] && $stop >= $b['start']) {
                $b['ids'][$uid] = true;
            }
        }
        unset($b);
    }

    // Monta saída (12 pontos, L→R) no formato já usado pelo front
    $out = [];
    foreach ($buckets as $b) {
        $out[] = [
            'hora' => $b['start']->format('Y-m-d H:00:00'),
            'qtd'  => count($b['ids']),
        ];
    }
    return $out;
}

/* =======================================================================
   Gráfico: conexões por dia (7d) — sessões iniciadas por dia
   ======================================================================= */
function serieConexoesPorDia7d(PDO $pdo): array {
    $sql = "
      SELECT DATE(acctstarttime) AS dia, COUNT(*) AS qtd
      FROM radacct
      WHERE acctstarttime >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      GROUP BY DATE(acctstarttime)
      ORDER BY dia ASC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Normaliza para 7 pontos contínuos (antigo→recente)
    $map = [];
    foreach ($rows as $r) $map[(string)$r['dia']] = (int)$r['qtd'];

    $out = [];
    $now = new DateTime('now');
    for ($i=6; $i>=0; $i--) {
        $t = (clone $now)->modify("-{$i} day");
        $key = $t->format('Y-m-d');
        $out[] = ['dia' => $key, 'qtd' => $map[$key] ?? 0];
    }
    return $out;
}

/* =======================================================================
   Helpers
   ======================================================================= */
function segsHumano(int $s): string {
    if ($s < 60) return "{$s}s";
    $m = intdiv($s, 60);
    $h = intdiv($m, 60); $m = $m % 60;
    if ($h > 0) return "{$h}h " . ($m>0 ? "{$m}m" : '');
    return "{$m}m";
}

/** Mapeamento solicitado: 🤖 Android, 🪟 Windows, 🍎 Apple, 🐧 Linux; fallback 📶 */
function deviceEmoji(string $ua): string {
    $u = mb_strtolower($ua ?? '');
    if ($u === '') return '📶';
    if (strpos($u, 'android') !== false) return '🤖';
    if (strpos($u, 'iphone') !== false || strpos($u, 'ipad') !== false || strpos($u, 'ios') !== false || strpos($u, 'mac os') !== false || strpos($u, 'macintosh') !== false) return '🍎';
    if (strpos($u, 'windows') !== false) return '🪟';
    if (strpos($u, 'linux') !== false || strpos($u, 'ubuntu') !== false || strpos($u, 'debian') !== false) return '🐧';
    return '📶';
}

function digits_only(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    return $digits === null ? '' : $digits;
}

function deviceInsights(PDO $pdo): array {
    return dashboard_cache('device_insights', 120, function () use ($pdo) {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Manaus'));
        $windowDays = 30;
        $cutoff = $now->modify('-'.$windowDays.' days')->format('Y-m-d H:i:s');

        $result = [
            'windowDays'    => $windowDays,
            'generatedAt'   => $now->format(DATE_ATOM),
            'totalDevices'  => 0,
            'osMix'         => [],
            'topReturning'  => [],
        ];

        try {
            $sqlMix = "
                SELECT
                  COUNT(*) AS total,
                  SUM(CASE WHEN LOWER(COALESCE(os,'')) LIKE '%android%' THEN 1 ELSE 0 END) AS android,
                  SUM(CASE WHEN LOWER(COALESCE(os,'')) LIKE '%iphone%' OR LOWER(COALESCE(os,'')) LIKE '%ipad%' OR LOWER(COALESCE(os,'')) LIKE '%ios%' OR LOWER(COALESCE(os,'')) LIKE '%mac%' THEN 1 ELSE 0 END) AS apple,
                  SUM(CASE WHEN LOWER(COALESCE(os,'')) LIKE '%windows%' THEN 1 ELSE 0 END) AS windows,
                  SUM(CASE WHEN LOWER(COALESCE(os,'')) LIKE '%linux%' OR LOWER(COALESCE(os,'')) LIKE '%ubuntu%' OR LOWER(COALESCE(os,'')) LIKE '%debian%' THEN 1 ELSE 0 END) AS linux
                FROM clientes_dispositivos
                WHERE COALESCE(last_seen, first_seen) >= :cutoff
            ";
            $stMix = $pdo->prepare($sqlMix);
            $stMix->execute([':cutoff' => $cutoff]);
            $mixRow = $stMix->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $result['error'] = 'DEVICE_TABLE_UNAVAILABLE';
            return $result;
        }

        $total = (int)($mixRow['total'] ?? 0);
        $result['totalDevices'] = $total;

        $counts = [
            'Android' => (int)($mixRow['android'] ?? 0),
            'Apple'   => (int)($mixRow['apple'] ?? 0),
            'Windows' => (int)($mixRow['windows'] ?? 0),
            'Linux'   => (int)($mixRow['linux'] ?? 0),
        ];
        $knownSum = array_sum($counts);
        $other = max(0, $total - $knownSum);
        if ($other > 0) {
            $counts['Outros'] = $other;
        }

        foreach ($counts as $label => $count) {
            $result['osMix'][] = [
                'label'   => $label,
                'count'   => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'icon'    => osEmojiByLabel($label),
            ];
        }

        try {
            $sqlTop = "
                SELECT username, mac, device_label, os, os_version, visits, first_seen, last_seen
                FROM clientes_dispositivos
                WHERE COALESCE(last_seen, first_seen) >= :cutoff AND visits >= 2
                ORDER BY visits DESC, COALESCE(last_seen, first_seen) DESC
                LIMIT 6
            ";
            $stTop = $pdo->prepare($sqlTop);
            $stTop->execute([':cutoff' => $cutoff]);
            $topRows = $stTop->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (count($topRows) < 3) {
                $sqlFallback = "
                    SELECT username, mac, device_label, os, os_version, visits, first_seen, last_seen
                    FROM clientes_dispositivos
                    WHERE COALESCE(last_seen, first_seen) >= :cutoff
                    ORDER BY COALESCE(last_seen, first_seen) DESC
                    LIMIT 6
                ";
                $stFallback = $pdo->prepare($sqlFallback);
                $stFallback->execute([':cutoff' => $cutoff]);
                $topRows = $stFallback->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            $topRows = [];
        }

        if (!empty($topRows)) {
            $usernames = [];
            foreach ($topRows as $row) {
                $u = trim((string)($row['username'] ?? ''));
                if ($u !== '') {
                    $usernames[$u] = true;
                }
            }
            $nameByUser = [];
            if (!empty($usernames)) {
                try {
                    $place = implode(',', array_fill(0, count($usernames), '?'));
                    $stNames = $pdo->prepare("SELECT cpf, nome FROM clientes_info WHERE cpf IN ($place)");
                    $stNames->execute(array_keys($usernames));
                    foreach ($stNames->fetchAll(PDO::FETCH_ASSOC) as $info) {
                        $nameByUser[(string)$info['cpf']] = (string)($info['nome'] ?? '');
                    }
                } catch (Throwable $e) {
                    $nameByUser = [];
                }
            }

            foreach ($topRows as $row) {
                $username = trim((string)($row['username'] ?? ''));
                $deviceLabel = trim((string)($row['device_label'] ?? ''));
                if ($deviceLabel === '') {
                    $deviceLabel = trim((string)($row['os'] ?? '')) ?: ($row['mac'] ?? '');
                }
                $owner = $nameByUser[$username] ?? $username;

                $firstRaw = $row['first_seen'] ?? null;
                $lastRaw = $row['last_seen'] ?? $firstRaw;
                $firstSeen = safeDate($firstRaw, $now) ?? $now;
                $lastSeen = safeDate($lastRaw, $now) ?? $now;

                $daysActive = max(1, (int)$firstSeen->diff($now)->days);

                $osNorm = normalizeOsLabel($row['os'] ?? '');
                if ($osNorm === '') {
                    $osNorm = normalizeOsLabel($deviceLabel);
                }
                if ($osNorm === '') {
                    $osNorm = 'Outros';
                }

                $result['topReturning'][] = [
                    'username'   => $username,
                    'owner'      => $owner,
                    'label'      => $deviceLabel,
                    'os'         => $osNorm,
                    'osIcon'     => osEmojiByLabel($osNorm),
                    'visits'     => (int)($row['visits'] ?? 0),
                    'mac'        => (string)($row['mac'] ?? ''),
                    'lastSeen'   => $lastSeen->format(DATE_ATOM),
                    'lastSeenAgo'=> humanAgo($lastSeen, $now),
                    'firstSeen'  => $firstSeen->format(DATE_ATOM),
                    'daysActive' => $daysActive,
                ];
            }
        }

        return $result;
    });
}

function nasHealthSnapshot(PDO $pdo): array {
    return dashboard_cache('nas_health', 30, function () use ($pdo) {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Manaus'));
        $snapshot = [
            'generatedAt'  => $now->format(DATE_ATOM),
            'items'        => [],
            'statusCounts' => ['ok' => 0, 'error' => 0, 'unknown' => 0],
        ];

        try {
            $nasRows = $pdo->query("SELECT id, nasname, shortname, description FROM nas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $snapshot['error'] = 'NAS_TABLE_UNAVAILABLE';
            return $snapshot;
        }

        if (!$nasRows) {
            return $snapshot;
        }

        $healthByNas = [];
        try {
            $healthRows = $pdo->query("SELECT * FROM nas_health")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($healthRows as $h) {
                $healthByNas[(int)$h['nas_id']] = $h;
            }
        } catch (Throwable $e) {
            $healthByNas = [];
        }

        $sessionsNow = [];
        try {
            $sqlActive = "
                SELECT nasipaddress, COUNT(*) AS qtd
                FROM radacct
                WHERE (acctstoptime IS NULL OR acctstoptime > NOW()) AND acctstarttime <= NOW()
                GROUP BY nasipaddress
            ";
            foreach ($pdo->query($sqlActive) as $row) {
                $sessionsNow[(string)$row['nasipaddress']] = (int)$row['qtd'];
            }
        } catch (Throwable $e) {
            $sessionsNow = [];
        }

        $lastSessionByNas = [];
        try {
            $sqlLast = "SELECT nasipaddress, MAX(acctstarttime) AS last_start FROM radacct GROUP BY nasipaddress";
            foreach ($pdo->query($sqlLast) as $row) {
                $nasName = (string)$row['nasipaddress'];
                $lastSessionByNas[$nasName] = $row['last_start'];
            }
        } catch (Throwable $e) {
            $lastSessionByNas = [];
        }

        $ifaceMeta = [];
        try {
            $sqlIface = "SELECT nas_id, COUNT(*) AS qtd, MAX(updated_at) AS updated_at FROM nas_interfaces GROUP BY nas_id";
            foreach ($pdo->query($sqlIface) as $row) {
                $ifaceMeta[(int)$row['nas_id']] = [
                    'count' => (int)$row['qtd'],
                    'updated_at' => $row['updated_at'],
                ];
            }
        } catch (Throwable $e) {
            $ifaceMeta = [];
        }

        foreach ($nasRows as $nas) {
            $id = (int)$nas['id'];
            $nasName = (string)$nas['nasname'];
            $label = trim((string)($nas['shortname'] ?? '')) ?: $nasName;
            $desc = trim((string)($nas['description'] ?? ''));
            if ($desc !== '' && $desc !== 'RADIUS Client') {
                if (strpos($label, $desc) === false) {
                    $label .= ' (' . $desc . ')';
                }
            }

            $health = $healthByNas[$id] ?? null;
            $status = $health['status'] ?? 'unknown';

            $checkedAtStr = $health['checked_at'] ?? null;
            $checkedAtIso = null;
            if ($checkedAtStr) {
                try {
                    $checkedAtIso = (new DateTimeImmutable($checkedAtStr, new DateTimeZone('America/Manaus')))->format(DATE_ATOM);
                } catch (Throwable $e) {
                    $checkedAtIso = $checkedAtStr;
                }
            }

            $lastSessStr = $lastSessionByNas[$nasName] ?? null;
            $lastSessIso = null;
            $lastSessAgo = null;
            if ($lastSessStr) {
                try {
                    $lastDt = new DateTimeImmutable($lastSessStr, new DateTimeZone('America/Manaus'));
                } catch (Throwable $e) {
                    $lastDt = null;
                }
                if ($lastDt) {
                    $lastSessIso = $lastDt->format(DATE_ATOM);
                    $lastSessAgo = humanAgo($lastDt, $now);
                }
            }

            $iface = $ifaceMeta[$id] ?? null;

            $item = [
                'id'               => $id,
                'label'            => $label,
                'nasname'          => $nasName,
                'status'           => $status,
                'statusMessage'    => $health['message'] ?? null,
                'checkedAt'        => $checkedAtStr,
                'checkedAtIso'     => $checkedAtIso,
                'latencyMs'        => isset($health['latency_ms']) ? (int)$health['latency_ms'] : null,
                'uptime'           => $health['uptime'] ?? null,
                'routerOs'         => $health['routeros_version'] ?? null,
                'board'            => $health['board_model'] ?? null,
                'cpuLoad'          => $health['cpu_load'] ?? null,
                'memoryFree'       => $health['memory_free'] ?? null,
                'memoryTotal'      => $health['memory_total'] ?? null,
                'temperature'      => $health['temperature'] ?? null,
                'voltage'          => $health['voltage'] ?? null,
                'interfacesKnown'  => $iface['count'] ?? null,
                'interfacesUpdatedAt' => $iface['updated_at'] ?? null,
                'hotspotHosts'     => isset($health['hotspot_host_count']) ? (int)$health['hotspot_host_count'] : null,
                'activeSessions'   => $sessionsNow[$nasName] ?? 0,
                'lastSessionAt'    => $lastSessIso,
                'lastSessionAgo'   => $lastSessAgo,
                'lastSuccessAt'    => $health['last_success_at'] ?? null,
                'lastErrorAt'      => $health['last_error_at'] ?? null,
                'errorDetail'      => $health['error_detail'] ?? null,
            ];

            if (!isset($snapshot['statusCounts'][$status])) {
                $snapshot['statusCounts'][$status] = 0;
            }
            $snapshot['statusCounts'][$status]++;
            $snapshot['items'][] = $item;
        }

        return $snapshot;
    });
}

function safeDate($value, DateTimeImmutable $fallbackNow): ?DateTimeImmutable {
    if (!$value) return null;
    try {
        return new DateTimeImmutable($value, new DateTimeZone('America/Manaus'));
    } catch (Throwable $e) {
        return $fallbackNow;
    }
}

function normalizeOsLabel(string $raw): string {
    $os = mb_strtolower(trim($raw));
    if ($os === '' || in_array($os, ['desconhecido','unknown','outros','outro','n/a','nao identificado','nao-identificado'], true)) {
        return '';
    }
    if (strpos($os, 'android') !== false) return 'Android';
    if (strpos($os, 'iphone') !== false || strpos($os, 'ipad') !== false || strpos($os, 'ios') !== false || strpos($os, 'mac') !== false) return 'Apple';
    if (strpos($os, 'windows') !== false || strpos($os, 'win') === 0) return 'Windows';
    if (strpos($os, 'linux') !== false || strpos($os, 'ubuntu') !== false || strpos($os, 'debian') !== false) return 'Linux';
    return ucfirst($raw ?: '');
}

function osEmojiByLabel(string $label): string {
    switch ($label) {
        case 'Android': return '🤖';
        case 'Windows': return '🪟';
        case 'Apple':   return '🍎';
        case 'Linux':   return '🐧';
        default:        return '📶';
    }
}

function resolvePlanLabel(?array $clienteInfo, string $groupRaw): string {
    $label = planLabelFromGroup($groupRaw);
    if ($label !== null) {
        return $label;
    }
    if (isVipClienteActive($clienteInfo)) {
        return 'Premium';
    }
    return 'Gratuito';
}

function planLabelFromGroup(?string $groupRaw): ?string {
    $group = strtoupper(trim((string)$groupRaw));
    if ($group === '') {
        return null;
    }
    if (strpos($group, 'VIP') !== false || strpos($group, 'PREMIUM') !== false || strpos($group, 'UNL') !== false || strpos($group, 'ILIMIT') !== false) {
        return 'Premium';
    }
    if (strpos($group, 'PADRAO') !== false || strpos($group, 'FREE') !== false || strpos($group, 'BASICO') !== false) {
        return 'Gratuito';
    }
    return null;
}

function isVipClienteActive(?array $clienteInfo): bool {
    if (!$clienteInfo) {
        return false;
    }
    $ativo = (int)($clienteInfo['vip_ativo'] ?? 0) === 1;
    if (!$ativo) {
        return false;
    }
    $until = $clienteInfo['vip_until'] ?? null;
    if ($until) {
        $ts = strtotime((string)$until);
        if ($ts !== false && $ts < time()) {
            return false;
        }
    }
    return true;
}

function humanAgo(DateTimeImmutable $past, DateTimeImmutable $now): string {
    $diff = max(0, $now->getTimestamp() - $past->getTimestamp());
    if ($diff < 60) {
        return $diff . 's atrás';
    }
    if ($diff < 3600) {
        $m = intdiv($diff, 60);
        return $m . 'm atrás';
    }
    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return $h . 'h atrás';
    }
    if ($diff < 604800) {
        $d = intdiv($diff, 86400);
        return $d . 'd atrás';
    }
    return $past->format('d/m');
}


// antes: $graficos['porHora24h'] = <alguma lógica on-the-fly>
$graficos['porHora24h'] = seriePeak12hFromTable($pdo, 'device'); // ou 'user'

/**
 * Lê a tabela de agregação e devolve 12 pontos (antigo→recente) no formato do front:
 * [['hora' => 'YYYY-MM-DD HH:00:00', 'qtd' => int], ...]
 * $mode = 'device' | 'user'
 */
function seriePeak12hFromTable(PDO $pdo, string $mode = 'device'): array {
    $mode = ($mode === 'user') ? 'user' : 'device';

    // hora atual e início da janela de 12h (timezone do dashboard)
    try { $pdo->query("SET time_zone = 'America/Manaus'"); } catch(Throwable $e){}
    $now = new DateTime('now', new DateTimeZone('America/Manaus'));

    // montamos o mapa esperado com zeros
    $map = [];
    for ($i=11; $i>=0; $i--) {
        $t = (clone $now)->modify("-{$i} hour")->setTime((int)$now->modify("-{$i} hour")->format('H'), 0, 0);
        $k = $t->format('Y-m-d H:00:00');
        $map[$k] = 0;
    }

    // buscamos o que existir gravado
    $minKey = array_key_first($map);
    $maxKey = array_key_last($map);

    $st = $pdo->prepare("
      SELECT hour_key, peak_count
      FROM rad_concurrency_hourly
      WHERE mode = ?
        AND hour_key BETWEEN ? AND ?
      ORDER BY hour_key ASC
    ");
    $st->execute([$mode, $minKey, $maxKey]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (string)$r['hour_key'];
        $map[$k] = max($map[$k] ?? 0, (int)$r['peak_count']);
    }

    // formata saída
    $out = [];
    foreach ($map as $k => $v) {
        $out[] = ['hora' => $k, 'qtd' => $v];
    }
    return $out;
}

/**
 * Tráfego TOTAL do dia corrente (bytes) com fatiamento por janela:
 * soma, por sessão, apenas a fração de (input+output) que cai dentro de [hoje 00:00 .. agora].
 * Considera stoptime NULL ou '0000-00-00 00:00:00' como sessão ativa.
 * Usa TZ -04:00 (Manaus) para as fronteiras de dia.
 */
function trafegoHojeBytes(PDO $pdo): int {
    // Melhor esforço: fixa TZ no MySQL (evita #1298 com nomes IANA)
    try { $pdo->query("SET time_zone = '-04:00'"); } catch (\Throwable $e) {}

    $sql = "
        SELECT
          ROUND(SUM(
            (CAST(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0) AS DECIMAL(20,0)))
            *
            (
              GREATEST(
                0,
                TIMESTAMPDIFF(
                  SECOND,
                  GREATEST(acctstarttime, CURDATE()),
                  LEAST(COALESCE(NULLIF(acctstoptime, '0000-00-00 00:00:00'), NOW()), NOW())
                )
              )
              /
              NULLIF(
                TIMESTAMPDIFF(
                  SECOND,
                  acctstarttime,
                  COALESCE(NULLIF(acctstoptime, '0000-00-00 00:00:00'), NOW())
                ),
                0
              )
            )
          )) AS traf
        FROM radacct
        WHERE
          acctstarttime < NOW()
          AND COALESCE(NULLIF(acctstoptime, '0000-00-00 00:00:00'), NOW()) > CURDATE()
    ";
    $v = $pdo->query($sql)->fetchColumn();
    return (int)($v ?? 0);
}

/**
 * Série de pico de conexões simultâneas por hora (últimas 12 horas)
 * Retorna array com [hora => máximo de usuários online naquela hora]
 */
function seriePicoOnlinePorHora12h(PDO $pdo): array {
    try { $pdo->query("SET time_zone = 'America/Manaus'"); } catch (\Throwable $e) {}
    
    $series = [];
    
    // Gera as últimas 12 horas
    for ($i = 11; $i >= 0; $i--) {
        $horaInicio = date('Y-m-d H:00:00', strtotime("-$i hours"));
        $horaFim = date('Y-m-d H:59:59', strtotime("-$i hours"));
        $label = date('H:00', strtotime("-$i hours"));
        
        // Conta o pico de sessões simultâneas dentro desta hora
        $sql = "
            SELECT COUNT(DISTINCT COALESCE(NULLIF(username,''), callingstationid, CONCAT('sess#',radacctid))) AS pico
            FROM radacct
            WHERE acctstarttime <= '$horaFim'
              AND (acctstoptime IS NULL OR acctstoptime >= '$horaInicio')
        ";
        
        $result = $pdo->query($sql);
        $pico = (int)$result->fetchColumn();
        
        $series[] = [
            'label' => $label,
            'value' => $pico
        ];
    }
    
    return $series;
}

/**
 * Série de tráfego por hora do dia atual (hoje, hora a hora)
 * Retorna array com [hora => bytes totais naquela hora]
 */
function serieTrafegoHoje(PDO $pdo): array {
    try { $pdo->query("SET time_zone = 'America/Manaus'"); } catch (\Throwable $e) {}
    
    $series = [];
    $horaAtual = (int)date('H');
    
    // Gera as horas do dia de hoje até a hora atual
    for ($h = 0; $h <= $horaAtual; $h++) {
        $horaInicio = date('Y-m-d') . ' ' . sprintf('%02d:00:00', $h);
        $horaFim = date('Y-m-d') . ' ' . sprintf('%02d:59:59', $h);
        $label = sprintf('%02d:00', $h);
        
        // Soma tráfego (input + output) das sessões que estiveram ativas nesta hora
        $sql = "
            SELECT COALESCE(SUM(
                (COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)) *
                (
                    GREATEST(0, TIMESTAMPDIFF(SECOND,
                        GREATEST(acctstarttime, '$horaInicio'),
                        LEAST(COALESCE(NULLIF(acctstoptime, '0000-00-00 00:00:00'), NOW()), '$horaFim')
                    )) /
                    NULLIF(TIMESTAMPDIFF(SECOND, acctstarttime, 
                        COALESCE(NULLIF(acctstoptime, '0000-00-00 00:00:00'), NOW())), 0)
                )
            ), 0) AS trafego
            FROM radacct
            WHERE acctstarttime <= '$horaFim'
              AND (acctstoptime IS NULL OR acctstoptime >= '$horaInicio')
        ";
        
        $result = $pdo->query($sql);
        $bytes = (int)$result->fetchColumn();
        
        $series[] = [
            'label' => $label,
            'value' => $bytes
        ];
    }
    
    return $series;
}
