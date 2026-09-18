<?php
// /app/devices.php
// Helpers para cadastro/atualização de dispositivos do cliente

require_once __DIR__ . '/db.php';

/**
 * Parse simplificado de User-Agent → [os, os_version, browser, browser_version]
 * (não pretende cobrir 100% dos casos; pode ser refinado futuramente)
 */
function fs_parse_user_agent(?string $ua): array {
    $ua = $ua ?: '';

    $os = 'Desconhecido'; $osv = null;
    $br = 'Desconhecido'; $brv = null;

    // OS
    if (preg_match('~Windows NT ([0-9\.]+)~i', $ua, $m)) {
        $os = 'Windows'; $osv = $m[1];
    } elseif (preg_match('~Android ([0-9\.]+)~i', $ua, $m)) {
        $os = 'Android'; $osv = $m[1];
    } elseif (preg_match('~iPhone OS ([0-9\_]+)~i', $ua, $m)) {
        $os = 'iOS'; $osv = str_replace('_','.', $m[1]);
    } elseif (preg_match('~iPad; CPU OS ([0-9\_]+)~i', $ua, $m)) {
        $os = 'iPadOS'; $osv = str_replace('_','.', $m[1]);
    } elseif (preg_match('~Mac OS X ([0-9\_]+)~i', $ua, $m)) {
        $os = 'macOS'; $osv = str_replace('_','.', $m[1]);
    } elseif (preg_match('~Linux~i', $ua)) {
        $os = 'Linux';
    }

    // Browser (ordem importa)
    if (preg_match('~Edg/([0-9\.]+)~i', $ua, $m)) {
        $br = 'Edge'; $brv = $m[1];
    } elseif (preg_match('~OPR/([0-9\.]+)~i', $ua, $m)) {
        $br = 'Opera'; $brv = $m[1];
    } elseif (preg_match('~Chrome/([0-9\.]+)~i', $ua, $m)) {
        $br = 'Chrome'; $brv = $m[1];
    } elseif (preg_match('~Firefox/([0-9\.]+)~i', $ua, $m)) {
        $br = 'Firefox'; $brv = $m[1];
    } elseif (preg_match('~Version/([0-9\.]+).*Safari~i', $ua, $m)) {
        $br = 'Safari'; $brv = $m[1];
    } elseif (preg_match('~Safari/([0-9\.]+)~i', $ua, $m)) {
        $br = 'Safari'; $brv = $m[1];
    }

    return [
        'os' => $os,
        'os_version' => $osv,
        'browser' => $br,
        'browser_version' => $brv,
    ];
}

/**
 * UPSERT do dispositivo do cliente.
 * - Se (username, mac) não existe → INSERE
 * - Se existe → atualiza last_seen, last_ip, server_name, UA e incrementa visits
 */
function fs_upsert_device(PDO $pdo, string $username, ?string $mac, ?string $ip, ?string $serverName, ?string $userAgent): void {
    $username = trim($username);
    $mac      = strtoupper(trim((string)$mac));
    $ip       = trim((string)$ip);
    $server   = trim((string)$serverName);
    $ua       = (string)$userAgent;

    if ($username === '' || $mac === '') {
        // sem user ou MAC não cadastramos
        return;
    }

    $info = fs_parse_user_agent($ua);

    // tenta atualizar primeiro; se 0 linhas → insere
    $upd = $pdo->prepare("
        UPDATE clientes_dispositivos
           SET last_seen = NOW(),
               last_ip = :last_ip,
               server_name = :server,
               os = :os,
               os_version = :osv,
               browser = :br,
               browser_version = :brv,
               user_agent = :ua,
               visits = visits + 1
         WHERE username = :u
           AND mac = :m
         LIMIT 1
    ");
    $upd->execute([
        ':last_ip' => $ip ?: null,
        ':server'  => $server ?: null,
        ':os'      => $info['os'],
        ':osv'     => $info['os_version'],
        ':br'      => $info['browser'],
        ':brv'     => $info['browser_version'],
        ':ua'      => $ua ?: null,
        ':u'       => $username,
        ':m'       => $mac,
    ]);

    if ($upd->rowCount() > 0) {
        return;
    }

    // insere
    $ins = $pdo->prepare("
        INSERT INTO clientes_dispositivos
            (username, mac, first_seen, last_seen, first_ip, last_ip, server_name,
             os, os_version, browser, browser_version, user_agent, visits)
        VALUES
            (:u, :m, NOW(), NOW(), :first_ip, :last_ip, :server,
             :os, :osv, :br, :brv, :ua, 1)
    ");
    $ins->execute([
        ':u'        => $username,
        ':m'        => $mac,
        ':first_ip' => $ip ?: null,
        ':last_ip'  => $ip ?: null,
        ':server'   => $server ?: null,
        ':os'       => $info['os'],
        ':osv'      => $info['os_version'],
        ':br'       => $info['browser'],
        ':brv'      => $info['browser_version'],
        ':ua'       => $ua ?: null,
    ]);
}
