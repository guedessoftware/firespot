<?php
// /dashboard/api/charts_report.php
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

try {
    $pdo = db();
    try { $pdo->query("SET time_zone = 'America/Manaus'"); } catch (\Throwable $e) {}

    // Parâmetros de período (padrão: últimos 30 dias)
    $periodo = isset($_GET['periodo']) ? (int)$_GET['periodo'] : 30;
    if ($periodo <= 0) $periodo = 30;
    if ($periodo > 365) $periodo = 365; // máximo 1 ano

    $acessosPorNas = getAcessosPorNas($pdo, $periodo);
    $acessosPorHost = getAcessosPorHost($pdo, $periodo);

    echo json_encode([
        'ok' => true,
        'periodo' => $periodo,
        'acessosPorNas' => $acessosPorNas,
        'acessosPorHost' => $acessosPorHost,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("charts_report error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'CHART_REPORT_FAILED']);
}

/**
 * Retorna dados de acessos por NAS nos últimos N dias
 */
function getAcessosPorNas(PDO $pdo, int $periodo): array {
    $sql = "
        SELECT 
            n.nasname,
            n.shortname,
            n.description,
            COUNT(*) as total_acessos,
            COUNT(DISTINCT r.username) as usuarios_unicos,
            COUNT(DISTINCT r.callingstationid) as dispositivos_unicos
        FROM radacct r
        JOIN nas n ON n.nasname COLLATE utf8mb4_general_ci = r.nasipaddress COLLATE utf8mb4_general_ci
        WHERE r.acctstarttime >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY n.nasname, n.shortname, n.description
        ORDER BY total_acessos DESC
        LIMIT 20
    ";
    
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$periodo]);
        $rows = $st->fetchAll();
        
        $result = [];
        foreach ($rows as $row) {
            $label = $row['shortname'] ?: $row['nasname'];
            if ($row['description'] && $row['description'] !== 'RADIUS Client') {
                $label .= ' (' . $row['description'] . ')';
            }
            
            $result[] = [
                'nasname' => $row['nasname'],
                'label' => $label,
                'total_acessos' => (int)$row['total_acessos'],
                'usuarios_unicos' => (int)$row['usuarios_unicos'],
                'dispositivos_unicos' => (int)$row['dispositivos_unicos']
            ];
        }
        return $result;
    } catch (\Throwable $e) {
        error_log("getAcessosPorNas error: " . $e->getMessage());
        return [];
    }
}

/**
 * Retorna dados de acessos por Host/Parceiro nos últimos N dias
 */
function getAcessosPorHost(PDO $pdo, int $periodo): array {
    $nasMap = buildNasMap($pdo);
    $hostMeta = buildHostMeta($pdo, $nasMap);

    $fromRadacct = getAcessosPorHostFromRadacct($pdo, $periodo, $hostMeta, $nasMap);
    if (!empty($fromRadacct)) {
        return $fromRadacct;
    }

    return getAcessosPorHostFromPartners($pdo, $periodo, $hostMeta, $nasMap);
}

/**
 * Busca dados de hosts usando as tabelas partner_uses e partners
 */
function getAcessosPorHostFromPartners(PDO $pdo, int $periodo, array $hostMeta, array $nasMap): array {
    try {
        $sql = "
            SELECT 
                UPPER(pu.code) AS code_upper,
                COUNT(*) AS total_acessos,
                COUNT(DISTINCT pu.username) AS usuarios_unicos,
                COUNT(DISTINCT pu.mac) AS dispositivos_unicos
            FROM partner_uses pu
            WHERE pu.used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY code_upper
            HAVING code_upper IS NOT NULL AND code_upper <> ''
            ORDER BY total_acessos DESC
            LIMIT 50
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$periodo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("getAcessosPorHostFromPartners error: " . $e->getMessage());
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['code_upper'] ?? '')));
        if ($code === '') {
            continue;
        }

        $meta = $hostMeta[$code] ?? null;
        $label = hostDisplayLabel($code, $meta);
        $nasIp = '';
        $nasLabel = '';
        if ($meta) {
            $nasIp = (string)($meta['nas_name'] ?? '');
            $nasLabel = (string)($meta['nas_label'] ?? '');
        }
        if ($nasLabel === '' && $nasIp !== '') {
            $nasLabel = $nasMap[$nasIp] ?? $nasIp;
        }

        $result[] = [
            'code' => $code,
            'label' => $label,
            'nas_ip' => $nasIp,
            'nas_label' => $nasLabel,
            'total_acessos' => (int)($row['total_acessos'] ?? 0),
            'usuarios_unicos' => (int)($row['usuarios_unicos'] ?? 0),
            'dispositivos_unicos' => (int)($row['dispositivos_unicos'] ?? 0)
        ];
    }

    if (empty($result)) {
        return [];
    }

    usort($result, function ($a, $b) {
        return $b['total_acessos'] <=> $a['total_acessos'];
    });

    return array_slice($result, 0, 20);
}

/**
 * Fallback: usar dados diretamente do radacct agrupados por host (nasportid)
 */
function getAcessosPorHostFromRadacct(PDO $pdo, int $periodo, array $hostMeta, array $nasMap): array {
    $sql = "
        SELECT 
            UPPER(SUBSTRING_INDEX(r.nasportid, '-', -1)) AS host_code,
            COUNT(*) AS total_acessos,
            COUNT(DISTINCT r.username) AS usuarios_unicos,
            COUNT(DISTINCT r.callingstationid) AS dispositivos_unicos,
            MAX(r.nasipaddress) AS nas_ip
        FROM radacct r
        WHERE r.acctstarttime >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND r.nasportid IS NOT NULL
          AND r.nasportid <> ''
        GROUP BY host_code
        HAVING host_code IS NOT NULL AND host_code <> ''
        ORDER BY total_acessos DESC
        LIMIT 50
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([$periodo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("getAcessosPorHostFromRadacct error: " . $e->getMessage());
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['host_code'] ?? '')));
        if ($code === '') {
            continue;
        }

        $meta = $hostMeta[$code] ?? null;
        $label = hostDisplayLabel($code, $meta);
        $nasIp = trim((string)($row['nas_ip'] ?? ''));
        if ($nasIp === '' && $meta && !empty($meta['nas_name'])) {
            $nasIp = (string)$meta['nas_name'];
        }

        $nasLabel = '';
        if ($meta && !empty($meta['nas_label'])) {
            $nasLabel = (string)$meta['nas_label'];
        }
        if ($nasLabel === '' && $nasIp !== '') {
            $nasLabel = $nasMap[$nasIp] ?? $nasIp;
        }

        $result[] = [
            'code' => $code,
            'label' => $label,
            'nas_ip' => $nasIp,
            'nas_label' => $nasLabel,
            'total_acessos' => (int)($row['total_acessos'] ?? 0),
            'usuarios_unicos' => (int)($row['usuarios_unicos'] ?? 0),
            'dispositivos_unicos' => (int)($row['dispositivos_unicos'] ?? 0)
        ];
    }

    if (empty($result)) {
        return [];
    }

    usort($result, function ($a, $b) {
        return $b['total_acessos'] <=> $a['total_acessos'];
    });

    return array_slice($result, 0, 20);
}

function buildNasMap(PDO $pdo): array {
    $map = [];
    try {
        $st = $pdo->query("SELECT nasname, shortname, description FROM nas");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nasName = trim((string)($row['nasname'] ?? ''));
            if ($nasName === '') {
                continue;
            }
            $map[$nasName] = formatNasLabel($nasName, $row['shortname'] ?? '', $row['description'] ?? '');
        }
    } catch (\Throwable $e) {}
    return $map;
}

function buildHostMeta(PDO $pdo, array $nasMap): array {
    $meta = [];
    try {
        $sql = "
            SELECT h.code, CONCAT(p.name,' · ',h.name) name, h.nas_id,
                   n.nasname, n.shortname, n.description
            FROM partner_hotspots h
            JOIN partners p ON p.id=h.partner_id
            LEFT JOIN nas n ON n.id = h.nas_id
        ";
        $st = $pdo->query($sql);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = strtoupper(trim((string)($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $nasName = trim((string)($row['nasname'] ?? ''));
            $nasLabel = '';
            if ($nasName !== '') {
                $nasLabel = $nasMap[$nasName] ?? formatNasLabel($nasName, $row['shortname'] ?? '', $row['description'] ?? '');
            }
            $meta[$code] = [
                'code' => $code,
                'name' => trim((string)($row['name'] ?? '')),
                'nas_name' => $nasName,
                'nas_label' => $nasLabel,
            ];
        }
    } catch (\Throwable $e) {}
    if (!$meta) {
        try {
            $st=$pdo->query('SELECT p.code,p.name,p.nas_id,n.nasname,n.shortname,n.description FROM partners p LEFT JOIN nas n ON n.id=p.nas_id');
            while($row=$st->fetch(PDO::FETCH_ASSOC)){
                $code=strtoupper(trim((string)($row['code']??'')));if($code==='')continue;
                $nasName=trim((string)($row['nasname']??''));
                $meta[$code]=['code'=>$code,'name'=>trim((string)($row['name']??'')),'nas_name'=>$nasName,'nas_label'=>$nasName!==''?($nasMap[$nasName]??formatNasLabel($nasName,$row['shortname']??'',$row['description']??'')):''];
            }
        } catch (Throwable $e) {}
    }
    return $meta;
}

function hostDisplayLabel(string $code, ?array $meta): string {
    if (!$meta) {
        return $code;
    }
    $name = trim((string)($meta['name'] ?? ''));
    if ($name === '') {
        return $code;
    }
    if (stripos($name, $code) !== false) {
        return $name;
    }
    return $code . ' - ' . $name;
}

function formatNasLabel(?string $nasName, ?string $shortname, ?string $description): string {
    $label = trim((string)$shortname);
    $nas = trim((string)$nasName);
    if ($label === '' && $nas !== '') {
        $label = $nas;
    }
    $desc = trim((string)$description);
    if ($desc !== '' && $desc !== 'RADIUS Client') {
        $label = ($label !== '' ? $label : $nas) . ' (' . $desc . ')';
    }
    if ($label === '') {
        $label = $nas;
    }
    return $label;
}
