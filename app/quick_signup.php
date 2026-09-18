<?php
// Shared helpers for quick signup flows (user bootstrap, device association).

declare(strict_types=1);

require_once __DIR__ . '/identifier.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/db.php';

function fs_quick_generate_password(int $length = 8): string
{
    $length = max(6, min(32, $length));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function fs_quick_upsert_radcheck_password(PDO $pdo, string $username, string $password): void
{
    if ($username === '' || $password === '') {
        return;
    }
    $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")
        ->execute([$username]);
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
        ->execute([$username, 'Cleartext-Password', $password]);
}

function fs_quick_ensure_default_plan(PDO $pdo, string $username, string $group = 'Plano_Padrao', int $priority = 10): void
{
    if ($username === '') {
        return;
    }
    $st = $pdo->prepare('SELECT 1 FROM radusergroup WHERE username=? AND groupname=? LIMIT 1');
    $st->execute([$username, $group]);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (?,?,?)')
        ->execute([$username, $group, $priority]);
}

function fs_quick_client_info_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rs = $pdo->query('SHOW COLUMNS FROM clientes_info');
        if ($rs) {
            while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['Field'])) {
                    $cache[$row['Field']] = true;
                }
            }
        }
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

function fs_quick_store_client_info(PDO $pdo, array $data): void
{
    $columns = fs_quick_client_info_columns($pdo);
    if (!$columns) {
        return;
    }

    $cpf = isset($data['cpf']) && $data['cpf'] !== null ? fs_digits_only((string) $data['cpf']) : '';
    $telefone = isset($data['telefone']) ? fs_digits_only((string) $data['telefone']) : '';
    $aceitou = isset($data['aceitou_termos']) ? (int) $data['aceitou_termos'] : 1;

    if ($cpf !== '' && isset($columns['cpf'])) {
        $insertCols = ['cpf'];
        $placeholders = ['?'];
        $values = [$cpf];
        $hasPhoneValue = $telefone !== '';

        if (isset($columns['telefone'])) {
            $insertCols[] = 'telefone';
            $placeholders[] = '?';
            $values[] = $hasPhoneValue ? $telefone : '';
        }
        if (isset($columns['aceitou_termos'])) {
            $insertCols[] = 'aceitou_termos';
            $placeholders[] = '?';
            $values[] = $aceitou;
        }

        $updates = [];
        if (isset($columns['telefone'])) {
            $updates[] = "telefone = CASE WHEN VALUES(telefone) = '' THEN telefone ELSE VALUES(telefone) END";
        }
        if (isset($columns['aceitou_termos'])) {
            $updates[] = 'aceitou_termos = VALUES(aceitou_termos)';
        }

        $sql = 'INSERT INTO clientes_info (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
        if ($updates) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return;
    }

    if ($telefone === '' || !isset($columns['telefone'])) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM clientes_info WHERE telefone = ? LIMIT 1');
    $stmt->execute([$telefone]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        if (isset($columns['aceitou_termos'])) {
            $pdo->prepare('UPDATE clientes_info SET aceitou_termos = ? WHERE id = ? LIMIT 1')
                ->execute([$aceitou, (int) $existingId]);
        }
        return;
    }

    $insertCols = ['telefone'];
    $placeholders = ['?'];
    $values = [$telefone];
    if (isset($columns['aceitou_termos'])) {
        $insertCols[] = 'aceitou_termos';
        $placeholders[] = '?';
        $values[] = $aceitou;
    }

    $sql = 'INSERT INTO clientes_info (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function fs_quick_find_client_by_phone(PDO $pdo, string $phone): ?array
{
    $digits = fs_digits_only($phone);
    if ($digits === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM clientes_info WHERE telefone = ? LIMIT 1');
    $stmt->execute([$digits]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_quick_register_device(PDO $pdo, string $username): void
{
    if ($username === '') {
        return;
    }
    $ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
    $mac = '';
    if (!empty($ctx['mac'])) {
        $mac = strtoupper(str_replace('-', ':', trim((string) $ctx['mac'])));
    } elseif (!empty($_COOKIE['fs_did'])) {
        $mac = 'DID:' . preg_replace('/[^A-Fa-f0-9]/', '', (string) $_COOKIE['fs_did']);
    }
    if ($mac === '') {
        return;
    }
    $ip = isset($ctx['ip']) ? (string) $ctx['ip'] : '';
    $serverName = isset($ctx['server-name']) ? (string) $ctx['server-name'] : '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    fs_upsert_device($pdo, $username, $mac, $ip, $serverName, $ua);
}
