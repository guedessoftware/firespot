<?php
/**
 * Retorna histórico de compras (vip_orders) e acessos (radacct) para um usuário.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (!isset($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

$username = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
if ($username === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_username']);
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '-04:00'");

    $baseCols = ['cpf', 'nome'];
    $optionalCols = ['email', 'telefone', 'nascimento', 'created_at', 'vip_ativo', 'vip_expira'];
    $ciHas = [];
    foreach ($optionalCols as $col) {
        $ciHas[$col] = table_has_column($pdo, 'clientes_info', $col);
        if ($ciHas[$col]) {
            $baseCols[] = $col;
        }
    }

    $user = null;
    $sqlInfo = 'SELECT ' . implode(', ', $baseCols) . ' FROM clientes_info WHERE cpf = ? LIMIT 1';
    $stInfo = $pdo->prepare($sqlInfo);
    $stInfo->execute([$username]);
    if ($row = $stInfo->fetch()) {
        $user = [
            'cpf' => isset($row['cpf']) ? (string) $row['cpf'] : $username,
            'nome' => isset($row['nome']) ? (string) $row['nome'] : '',
            'email' => (isset($row['email']) && $row['email'] !== null) ? (string) $row['email'] : null,
            'telefone' => (isset($row['telefone']) && $row['telefone'] !== null) ? (string) $row['telefone'] : null,
            'nascimento' => (isset($row['nascimento']) && $row['nascimento']) ? (string) $row['nascimento'] : null,
            'created_at' => (isset($row['created_at']) && $row['created_at']) ? (string) $row['created_at'] : null,
            'vip_ativo' => isset($row['vip_ativo']) ? (int) $row['vip_ativo'] : null,
            'vip_expira' => (isset($row['vip_expira']) && $row['vip_expira']) ? (string) $row['vip_expira'] : null,
        ];
    }

    $hasVipApplied = table_has_column($pdo, 'vip_orders', 'vip_applied_at');
    $hasRadiusApplied = table_has_column($pdo, 'vip_orders', 'radius_applied_at');
    $hasPaymentMethod = table_has_column($pdo, 'vip_orders', 'payment_method');
    $hasPaymentDetail = table_has_column($pdo, 'vip_orders', 'payment_method_detail');
    $hasPaymentInstallments = table_has_column($pdo, 'vip_orders', 'payment_installments');
    $hasHostCode = table_has_column($pdo, 'vip_orders', 'host_code');
    $hasDeviceMac = table_has_column($pdo, 'vip_orders', 'device_mac');
    $hasDeviceIp = table_has_column($pdo, 'vip_orders', 'device_ip');
    $hasPartner = table_has_column($pdo, 'vip_orders', 'partner_code');
    $hasRadNasIdentifier = table_has_column($pdo, 'radacct', 'nasidentifier');

    $hasRadiusAppliedFlag = table_has_column($pdo, 'vip_orders', 'radius_applied');
    $hasRadiusAppliedSec = table_has_column($pdo, 'vip_orders', 'radius_applied_sec');

    $select = 'SELECT o.id, o.status, o.valor_centavos, o.duracao_min, o.created_at, o.paid_at, o.external_ref, o.mp_payment_id';
    $select .= $hasRadiusAppliedFlag ? ', o.radius_applied' : ', NULL AS radius_applied';
    $select .= $hasRadiusAppliedSec ? ', o.radius_applied_sec' : ', NULL AS radius_applied_sec';

    $planHasNome = table_has_column($pdo, 'planos', 'nome');
    $planHasGrupo = table_has_column($pdo, 'planos', 'grupo');
    $select .= $planHasNome ? ', p.nome AS plano_nome' : ', NULL AS plano_nome';
    $select .= $planHasGrupo ? ', p.grupo AS plano_grupo' : ', NULL AS plano_grupo';
    if ($hasVipApplied) {
        $select .= ', o.vip_applied_at';
    }
    if ($hasRadiusApplied) {
        $select .= ', o.radius_applied_at';
    }
    if ($hasPaymentMethod) {
        $select .= ', o.payment_method';
    }
    if ($hasPaymentDetail) {
        $select .= ', o.payment_method_detail';
    }
    if ($hasPaymentInstallments) {
        $select .= ', o.payment_installments';
    }
    if ($hasHostCode) {
        $select .= ', o.host_code';
    }
    if ($hasDeviceMac) {
        $select .= ', o.device_mac';
    }
    if ($hasDeviceIp) {
        $select .= ', o.device_ip';
    }
    if ($hasPartner) {
        $select .= ', o.partner_code';
    }

    $whereMatch = 'WHERE (BINARY o.username = BINARY ? OR BINARY o.cpf = BINARY ?)';
    $sqlOrders = $select . ' FROM vip_orders o LEFT JOIN planos p ON p.id = o.plano_id ' . $whereMatch . ' ORDER BY o.created_at DESC LIMIT 60';
    $stOrders = $pdo->prepare($sqlOrders);
    $stOrders->execute([$username, $username]);
    $ordersRaw = $stOrders->fetchAll() ?: [];

    $orders = [];
    foreach ($ordersRaw as $order) {
        $appliedAt = null;
        if ($hasVipApplied && !empty($order['vip_applied_at'])) {
            $appliedAt = (string) $order['vip_applied_at'];
        } elseif ($hasRadiusApplied && !empty($order['radius_applied_at'])) {
            $appliedAt = (string) $order['radius_applied_at'];
        }

        $orders[] = [
            'id' => (int) $order['id'],
            'status' => (string) $order['status'],
            'valor_centavos' => (int) $order['valor_centavos'],
            'valor_formatado' => format_money_br((int) $order['valor_centavos']),
            'duracao_min' => (int) $order['duracao_min'],
            'created_at' => $order['created_at'] ? (string) $order['created_at'] : null,
            'paid_at' => $order['paid_at'] ? (string) $order['paid_at'] : null,
            'applied_at' => $appliedAt,
            'external_ref' => (string) $order['external_ref'],
            'mp_payment_id' => $order['mp_payment_id'] ? (string) $order['mp_payment_id'] : null,
            'plano_nome' => $order['plano_nome'] ? (string) $order['plano_nome'] : null,
            'plano_grupo' => $order['plano_grupo'] ? (string) $order['plano_grupo'] : null,
            'radius_applied' => isset($order['radius_applied']) ? (int) $order['radius_applied'] : null,
            'radius_applied_sec' => isset($order['radius_applied_sec']) ? (int) $order['radius_applied_sec'] : null,
            'payment_method' => $hasPaymentMethod && isset($order['payment_method']) ? (string) $order['payment_method'] : null,
            'payment_method_detail' => $hasPaymentDetail && isset($order['payment_method_detail']) ? (string) $order['payment_method_detail'] : null,
            'payment_installments' => $hasPaymentInstallments && isset($order['payment_installments']) ? (int) $order['payment_installments'] : null,
            'host_code' => $hasHostCode && isset($order['host_code']) ? (string) $order['host_code'] : null,
            'device_mac' => $hasDeviceMac && isset($order['device_mac']) ? (string) $order['device_mac'] : null,
            'device_ip' => $hasDeviceIp && isset($order['device_ip']) ? (string) $order['device_ip'] : null,
            'partner_code' => $hasPartner && isset($order['partner_code']) ? (string) $order['partner_code'] : null,
        ];
    }

    $sessionCols = [
        'ra.radacctid',
        'ra.acctsessionid',
        'ra.acctstarttime',
        'ra.acctstoptime',
        'ra.acctsessiontime',
        'ra.framedipaddress',
        'ra.callingstationid',
        'ra.nasipaddress',
        $hasRadNasIdentifier ? 'ra.nasidentifier' : 'NULL AS nasidentifier',
        'ra.acctinputoctets',
        'ra.acctoutputoctets',
        'ra.acctterminatecause',
        'n.shortname',
        'n.description'
    ];

    $sqlSessions = 'SELECT ' . implode(', ', $sessionCols)
        . ' FROM radacct ra '
        . 'LEFT JOIN nas n ON BINARY n.nasname = BINARY ra.nasipaddress '
        . 'WHERE BINARY ra.username = BINARY ? '
        . 'ORDER BY ra.acctstarttime DESC '
        . 'LIMIT 120';
    $stSessions = $pdo->prepare($sqlSessions);
    $stSessions->execute([$username]);
    $sessionsRaw = $stSessions->fetchAll() ?: [];

    $sessions = [];
    foreach ($sessionsRaw as $sess) {
        $sessions[] = [
            'radacctid' => (int) $sess['radacctid'],
            'acctsessionid' => $sess['acctsessionid'] ? (string) $sess['acctsessionid'] : null,
            'start' => $sess['acctstarttime'] ? (string) $sess['acctstarttime'] : null,
            'stop' => $sess['acctstoptime'] ? (string) $sess['acctstoptime'] : null,
            'duration' => isset($sess['acctsessiontime']) ? (int) $sess['acctsessiontime'] : null,
            'framed_ip' => $sess['framedipaddress'] ? (string) $sess['framedipaddress'] : null,
            'mac' => $sess['callingstationid'] ? (string) $sess['callingstationid'] : null,
            'nas_ip' => $sess['nasipaddress'] ? (string) $sess['nasipaddress'] : null,
            'nas_name' => $sess['shortname'] ? (string) $sess['shortname'] : ($sess['description'] ? (string) $sess['description'] : null),
            'nas_identifier' => $sess['nasidentifier'] ? (string) $sess['nasidentifier'] : null,
            'input_octets' => isset($sess['acctinputoctets']) ? (int) $sess['acctinputoctets'] : null,
            'output_octets' => isset($sess['acctoutputoctets']) ? (int) $sess['acctoutputoctets'] : null,
            'terminate_cause' => $sess['acctterminatecause'] ? (string) $sess['acctterminatecause'] : null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'user' => $user,
        'orders' => $orders,
        'sessions' => $sessions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
