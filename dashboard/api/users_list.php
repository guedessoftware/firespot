<?php
declare(strict_types=1);

$requestStartedAt = microtime(true);
@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/dashboard_user_stats.php';

/** @param array<string,mixed> $params */
function users_list_bind(PDOStatement $statement, array $params): void
{
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (!fs_dashboard_user_stats_schema_ready($pdo)) {
        throw new RuntimeException('Migração 016 pendente: resumo de Clientes indisponível.');
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $qDigits = preg_replace('/\D+/', '', $q);
    $group = trim((string)($_GET['group'] ?? ''));
    $online = trim((string)($_GET['online'] ?? ''));
    $order = trim((string)($_GET['order'] ?? 'last_seen'));
    $dir = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];
    if ($q !== '') {
        $search = ['ci.nome LIKE :q_name'];
        $params[':q_name'] = '%' . $q . '%';
        if ($qDigits !== '') {
            $search[] = 'rc.username LIKE :q_user';
            $params[':q_user'] = '%' . $qDigits . '%';
            if (strlen($qDigits) >= 3) {
                $search[] = 'ci.telefone LIKE :q_phone';
                $params[':q_phone'] = '%' . $qDigits . '%';
            }
        } else {
            $search[] = 'rc.username LIKE :q_user';
            $params[':q_user'] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $search) . ')';
    }
    if ($group !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM radusergroup ug WHERE ug.username=rc.username AND ug.groupname=:group_name)';
        $params[':group_name'] = $group;
    }
    if ($online === 'online') {
        $where[] = 'COALESCE(s.online,0)=1';
    } elseif ($online === 'offline') {
        $where[] = 'COALESCE(s.online,0)=0';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $fromSql = ' FROM radcheck rc
        INNER JOIN clientes_info ci ON ci.cpf=rc.username
        LEFT JOIN dashboard_user_access_stats s ON s.username=rc.username';

    $count = $pdo->prepare('SELECT COUNT(DISTINCT rc.username)' . $fromSql . $whereSql);
    users_list_bind($count, $params);
    $count->execute();
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $perPage;
    }

    $orderMap = [
        'last_seen' => 'last_seen',
        'username' => 'rc.username',
        'nome' => 'nome',
        'visits30' => 'visits30',
    ];
    $orderSql = $orderMap[$order] ?? 'last_seen';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    $listSql = 'SELECT rc.username,
            COALESCE(MAX(ci.nome),\'—\') AS nome,
            COALESCE(MAX(ci.telefone),\'\') AS phone,
            MAX(s.last_seen) AS last_seen,
            COALESCE(MAX(s.visits_30d),0) AS visits30,
            COALESCE(MAX(s.online),0) AS online,
            COALESCE(MAX(s.used_seconds),0) AS used
        ' . $fromSql . $whereSql . '
        GROUP BY rc.username
        ORDER BY ' . $orderSql . ' ' . $dirSql . '
        LIMIT :limit_rows OFFSET :offset_rows';
    $list = $pdo->prepare($listSql);
    users_list_bind($list, $params);
    $list->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
    $list->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $list->execute();
    $rows = $list->fetchAll();

    $groupsByUser = [];
    $allowedByUser = [];
    if ($rows) {
        $usernames = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['username'], $rows)));
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));

        $groupQuery = $pdo->prepare("SELECT username,GROUP_CONCAT(DISTINCT groupname ORDER BY priority SEPARATOR ', ') AS groups
            FROM radusergroup WHERE username IN ({$placeholders}) GROUP BY username");
        $groupQuery->execute($usernames);
        foreach ($groupQuery->fetchAll() as $groupRow) {
            $groupsByUser[(string)$groupRow['username']] = (string)($groupRow['groups'] ?? '');
        }

        $allowedQuery = $pdo->prepare("SELECT username,MAX(CAST(value AS UNSIGNED)) AS allowed
            FROM radcheck WHERE attribute='Max-All-Session' AND username IN ({$placeholders}) GROUP BY username");
        $allowedQuery->execute($usernames);
        foreach ($allowedQuery->fetchAll() as $allowedRow) {
            $allowedByUser[(string)$allowedRow['username']] = (int)($allowedRow['allowed'] ?? 0);
        }
    }

    $outRows = array_map(static function (array $row) use ($groupsByUser, $allowedByUser): array {
        $username = (string)$row['username'];
        $allowed = (int)($allowedByUser[$username] ?? 0);
        $used = (int)($row['used'] ?? 0);
        $remaining = max(0, $allowed - $used);
        return [
            'username' => $username,
            'nome' => (string)$row['nome'],
            'groups' => ($groupsByUser[$username] ?? '') !== '' ? $groupsByUser[$username] : '—',
            'visits30' => (int)$row['visits30'],
            'last_seen' => $row['last_seen'] ? (string)$row['last_seen'] : null,
            'online' => (int)$row['online'] === 1,
            'phone' => (string)($row['phone'] ?? ''),
            'allowed' => $allowed,
            'used' => $used,
            'remaining' => $remaining,
            'remaining_hm' => sprintf('%dh %02dm', intdiv($remaining, 3600), intdiv($remaining % 3600, 60)),
        ];
    }, $rows);

    $durationMs = max(0, (int)round((microtime(true) - $requestStartedAt) * 1000));
    header('Server-Timing: users-list;dur=' . $durationMs);
    echo json_encode([
        'ok' => true,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'total' => $total,
        'rows' => $outRows,
        'stats' => fs_dashboard_user_stats_state($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[dashboard users list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível carregar os clientes.']);
}
