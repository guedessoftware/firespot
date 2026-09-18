<?php
// PRIV-001: endpoint legado desativado. O código antigo possui seleção ampla
// quando o telefone está vazio e não usa a sessão administrativa canônica.
// A exclusão suportada permanece em dashboard/api/user_delete.php até a
// implementação do novo serviço de anonimização e recibo mínimo.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'endpoint_retired']);
exit;

// /hotspot/dashboard/api/purge_client.php
// API para apagar todo e qualquer registro de um cliente (LGPD: use com cautela)
// Requer sessão autenticada no dashboard e token CSRF.

@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE)
        session_start();

    // ===== Autorização básica (ajuste conforme seu sistema) =====
    $isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['user_is_admin']);
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    // ===== CSRF (aceita header X-CSRF-Token ou campo _csrf no JSON) =====
    require_once __DIR__ . '/../app/csrf.php';
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    $tok = $hdr ?: ($j['_csrf'] ?? '');
    if (!csrf_check($tok)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    // ===== Conexão banco (mesmo used em todo o projeto) =====
    require_once __DIR__ . '/../app/db.php';
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // ===== Helpers =====
    $json = is_array($j) ? $j : [];
    $username = trim((string) ($json['username'] ?? $json['cpf'] ?? ''));
    $cpf = preg_replace('/\D+/', '', (string) ($json['cpf'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($json['phone'] ?? $json['telefone'] ?? ''));
    $confirm = (bool) ($json['confirm'] ?? false);
    $dryRun = (bool) ($json['dry_run'] ?? false);

    if ($username === '' && $cpf === '' && $phone === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_identifier', 'hint' => 'Envie username/cpf e opcionalmente phone']);
        exit;
    }
    if (!$confirm && !$dryRun) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'confirm_required', 'hint' => 'Passe {"confirm":true} para executar. Use dry_run para simular.']);
        exit;
    }

    // normaliza username = cpf quando possível
    if ($username === '' && $cpf !== '')
        $username = $cpf;
    if ($cpf === '' && preg_match('/^\d{11}$/', $username))
        $cpf = $username;

    // util: checar existencia de tabela/coluna
    $tableHas = function (string $table, ?string $col = null) use ($pdo): bool {
        $sql = 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
        $params = [$table];
        if ($col !== null) {
            $sql .= ' AND COLUMN_NAME = ?';
            $params[] = $col;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool) $st->fetchColumn();
    };

    // monta lista de deletes (tabela => [sql, params])
    $deletes = [];

    // RADIUS core
    $deletes['radacct'] = ['DELETE FROM radacct      WHERE username = ?', [$username]];
    $deletes['radpostauth'] = ['DELETE FROM radpostauth  WHERE username = ?', [$username]];
    $deletes['radreply'] = ['DELETE FROM radreply     WHERE username = ?', [$username]];
    $deletes['radcheck'] = ['DELETE FROM radcheck     WHERE username = ?', [$username]];
    $deletes['radusergroup'] = ['DELETE FROM radusergroup WHERE username = ?', [$username]];

    // Portal / app
    if ($tableHas('vip_orders')) {
        $deletes['vip_orders'] = ['DELETE FROM vip_orders WHERE (username = ? OR cpf = ? OR REPLACE(telefone,\'\',\'\') LIKE ?)', [$username, $cpf, "%$phone%"]];
    }
    if ($tableHas('clientes_dispositivos')) {
        // apaga por username se houver essa coluna; fallback: por cpf
        if ($tableHas('clientes_dispositivos', 'username')) {
            $deletes['clientes_dispositivos'] = ['DELETE FROM clientes_dispositivos WHERE username = ?', [$username]];
        } elseif ($tableHas('clientes_dispositivos', 'cpf')) {
            $deletes['clientes_dispositivos'] = ['DELETE FROM clientes_dispositivos WHERE cpf = ?', [$cpf]];
        }
    }
    if ($tableHas('clientes_info')) {
        $deletes['clientes_info'] = ['DELETE FROM clientes_info WHERE cpf = ?', [$cpf]];
    }
    if ($tableHas('promo_queue')) {
        // números na fila: remove os que batem pelo final igual ao phone (ex.: termina com 11 dígitos)
        if ($phone !== '') {
            $deletes['promo_queue'] = ['DELETE FROM promo_queue WHERE to_msisdn LIKE ? OR to_msisdn LIKE ?', ["%$phone", "+%$phone"]];
        }
    }

    // Prévia (dry run) — conta registros que seriam apagados
    $summary = [];
    foreach ($deletes as $tbl => [$sql, $params]) {
        // troca DELETE por SELECT COUNT(*) para estimar
        $countSql = 'SELECT COUNT(*) FROM ' . $tbl . ' ' . preg_replace('/^DELETE FROM\s+\S+\s*/i', 'WHERE ', $sql);
        // se o DELETE não tinha WHERE, evita catástrofe
        if (stripos($countSql, 'WHERE') === false) {
            $summary[$tbl] = 0;
            continue;
        }
        try {
            $st = $pdo->prepare($countSql);
            $st->execute($params);
            $summary[$tbl] = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $summary[$tbl] = 0;
        }
    }

    if ($dryRun) {
        echo json_encode(['ok' => true, 'dry_run' => true, 'username' => $username, 'cpf' => $cpf, 'phone' => $phone, 'would_delete' => $summary]);
        exit;
    }

    // Execução real
    $pdo->beginTransaction();
    $deleted = [];
    try {
        foreach ($deletes as $tbl => [$sql, $params]) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $deleted[$tbl] = $st->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['ok' => true, 'deleted' => $deleted]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
