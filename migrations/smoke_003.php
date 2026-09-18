<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/guest_access.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (!fs_payment_schema_ready($pdo)) throw new RuntimeException('Schema V3 indisponível.');

$probe = 'firespot-wallet-probe';
if (fs_decrypt_credential(fs_encrypt_credential($probe)) !== $probe) {
    throw new RuntimeException('Falha no round-trip criptográfico.');
}

$globalWallet = fs_global_wallet();
if (empty($globalWallet['public_key']) || empty($globalWallet['access_token'])) {
    throw new RuntimeException('Carteira global incompleta.');
}

$partner = $pdo->query('SELECT * FROM partners WHERE active=1 ORDER BY id LIMIT 1')->fetch();
if (!$partner) throw new RuntimeException('Nenhum estabelecimento ativo para smoke test.');
$partner['independent_billing'] = 0;
$plans = fs_guest_plans($pdo, $partner);
if (!$plans) throw new RuntimeException('Nenhum plano global ativo para smoke test.');

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE partners SET portal_mode='v3' WHERE id=?")->execute([(int)$partner['id']]);
    $routedPartner = fs_guest_partner($pdo, (string)$partner['code'], true);
    if (!$routedPartner) throw new RuntimeException('Roteamento opt-in do Portal V3 falhou.');
    $order = fs_guest_create_order($pdo, $partner, $plans[0], ['mac'=>'02:00:00:00:00:01','ip'=>'192.0.2.10'], null);
    $loaded = fs_guest_order_for_session($pdo, $order['public_id'], $order['order_token']);
    if (!$loaded || (int)$loaded['amount_cents'] !== (int)$plans[0]['price_cents']) {
        throw new RuntimeException('Pedido anônimo não pôde ser validado.');
    }
    $pdo->prepare("UPDATE guest_orders SET status='paid',paid_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
    $granted = fs_guest_grant_access($pdo, (int)$order['id']);
    if (empty($granted['radius_username']) || empty($granted['_radius_password'])) {
        throw new RuntimeException('Liberação RADIUS anônima falhou.');
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM radcheck WHERE username=? AND attribute IN ('Cleartext-Password','Max-All-Session','Simultaneous-Use')");
    $check->execute([$granted['radius_username']]);
    if ((int)$check->fetchColumn() !== 3) throw new RuntimeException('Atributos RADIUS incompletos.');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "SMOKE_003_OK\n";
echo 'hosts_v3=' . (int)$pdo->query("SELECT COUNT(*) FROM partners WHERE portal_mode='v3'")->fetchColumn() . PHP_EOL;
echo 'wallets_adicionais=' . (int)$pdo->query('SELECT COUNT(*) FROM payment_wallets')->fetchColumn() . PHP_EOL;
