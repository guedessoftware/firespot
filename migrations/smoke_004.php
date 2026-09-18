<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../portal-v3/_boot.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$partner = $pdo->query('SELECT * FROM partners WHERE active=1 ORDER BY id LIMIT 1')->fetch();
if (!$partner) throw new RuntimeException('Nenhum estabelecimento ativo para o teste.');
$partner['independent_billing'] = 0;
$plans = fs_guest_plans($pdo, $partner);
if (!$plans) throw new RuntimeException('Nenhum plano global ativo para o teste.');

$mac = '02:00:00:00:04:04';
$pdo->beginTransaction();
try {
    $order = fs_guest_create_order($pdo, $partner, $plans[0], ['mac' => $mac, 'ip' => '192.0.2.44'], null);
    $pdo->prepare("UPDATE guest_orders SET status='paid',paid_at=NOW() WHERE id=?")->execute([(int) $order['id']]);
    $granted = fs_guest_grant_access($pdo, (int) $order['id']);

    $simultaneous = $pdo->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Simultaneous-Use'");
    $simultaneous->execute([(string) $granted['radius_username']]);
    if ((string) $simultaneous->fetchColumn() !== '1') throw new RuntimeException('Limite simultâneo não aplicado.');

    $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Cleartext-Password','Max-All-Session')")
        ->execute([(string) $granted['radius_username']]);
    $granted = fs_guest_grant_access($pdo, (int) $order['id']);
    if (empty($granted['_radius_password'])) throw new RuntimeException('Credencial RADIUS ausente não foi reparada.');

    $credit = fs_guest_credit_balance($pdo, $granted);
    $allowed = (int) $credit['allowed_seconds'];
    if ($allowed !== (int) $plans[0]['duration_minutes'] * 60 || (int) $credit['remaining_seconds'] !== $allowed) {
        throw new RuntimeException('Saldo inicial divergente.');
    }

    $recovered = fs_guest_find_credit_by_mac($pdo, (int) $partner['id'], $mac);
    if (!$recovered || (int) $recovered['id'] !== (int) $order['id']) throw new RuntimeException('Recuperação por MAC falhou.');

    $pdo->prepare('UPDATE guest_orders SET device_mac=NULL WHERE id=?')->execute([(int) $order['id']]);
    $_SESSION['portal_v3_orders'] = [(string) $order['public_id'] => (string) $order['order_token']];
    $_SESSION['hotspot_ctx'] = ['data' => ['mac' => $mac, 'ip' => '192.0.2.44'], 'ts' => time()];
    unset($_COOKIE[v3_recovery_cookie_name((int) $partner['id'])]);
    $boundOrder = v3_recover_access($pdo, $partner);
    if (!$boundOrder || (string) $boundOrder['device_mac'] !== $mac) throw new RuntimeException('Vínculo seguro do MAC ausente falhou.');

    $_SESSION['portal_v3_orders'] = [];
    unset($_COOKIE[v3_recovery_cookie_name((int) $partner['id'])]);
    $portalRecovered = v3_recover_access($pdo, $partner);
    if (!$portalRecovered || (int) $portalRecovered['id'] !== (int) $order['id']) throw new RuntimeException('Retomada do Portal V3 falhou.');
    $portalToken = v3_order_token((string) $order['public_id']);
    if (!fs_guest_order_for_session($pdo, (string) $order['public_id'], $portalToken)) throw new RuntimeException('Sessão recuperada inválida.');

    $used = max(1, min(120, $allowed - 1));
    $sessionId = 'smk4-' . bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO radacct
      (acctsessionid,acctuniqueid,username,nasipaddress,acctstarttime,acctupdatetime,acctstoptime,acctsessiontime,calledstationid,callingstationid,acctterminatecause)
      VALUES (?,?,?,?,DATE_SUB(NOW(),INTERVAL ? SECOND),NOW(),NOW(),?,?,?,'User-Request')")
      ->execute([$sessionId, md5($sessionId), (string) $granted['radius_username'], '192.0.2.1', $used, $used, 'smoke-v3', $mac]);

    $credit = fs_guest_credit_balance($pdo, $granted);
    $expectedRemaining = $allowed - $used;
    if ((int) $credit['remaining_seconds'] !== $expectedRemaining) throw new RuntimeException('Consumo acumulado divergente.');

    $newToken = fs_guest_rotate_order_token($pdo, (int) $order['id']);
    if (fs_guest_order_for_session($pdo, $order['public_id'], $order['order_token'])) throw new RuntimeException('Token anterior não foi revogado.');
    if (!fs_guest_order_for_session($pdo, $order['public_id'], $newToken)) throw new RuntimeException('Novo token de recuperação inválido.');

    $prepared = fs_guest_prepare_reconnect($pdo, (int) $order['id']);
    if ((int) $prepared['_credit']['remaining_seconds'] !== $expectedRemaining) throw new RuntimeException('Saldo de reconexão divergente.');
    $timeout = $pdo->prepare("SELECT value FROM radreply WHERE username=? AND attribute='Session-Timeout'");
    $timeout->execute([(string) $granted['radius_username']]);
    if ((int) $timeout->fetchColumn() !== $expectedRemaining) throw new RuntimeException('Session-Timeout não foi recalculado.');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "SMOKE_004_OK\n";
