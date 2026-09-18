<?php
declare(strict_types=1);

// SEC-005: webhook legado não idempotente e dependente de mock retirado.
http_response_code(410);
exit;

// portal/webhooks/mercadopago.php — Webhook de notificação de pagamentos (Mercado Pago)
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/mercadopago_pix.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../api/premium_mock.php'; // troque pela integração real

// Mercado Pago envia GET ?type=payment&id=123 ou POST com JSON { "type": "payment", "data": { "id": "..." } }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = file_get_contents('php://input');
$data   = [];

if ($method === 'POST' && $input) {
  $data = json_decode($input, true) ?: [];
}
$type = $_GET['type'] ?? ($data['type'] ?? '');
$payId = $_GET['data_id'] ?? ($_GET['id'] ?? ($data['data']['id'] ?? ''));

if ($type !== 'payment' || !$payId) {
  http_response_code(400);
  echo 'ignored'; exit;
}

try {
  $p = mp_get_payment((string)$payId);
  $status = $p['status'] ?? 'unknown';
  if ($status !== 'approved') { echo 'ok'; exit; }

  $pdo = db();
  // encontra pedido
  $stmt = $pdo->prepare('SELECT * FROM vip_orders WHERE mp_payment_id=? LIMIT 1');
  $stmt->execute([$payId]);
  $ord = $stmt->fetch();
  if (!$ord) { echo 'order_not_found'; exit; }

  // marca como pago
  $pdo->prepare('UPDATE vip_orders SET status="paid", paid_at=NOW() WHERE id=?')->execute([$ord['id']]);

  runtime_schema_require($pdo, 'vip_passes', ['username','status','expires_at','policy_down_kbps','policy_up_kbps']);

  $dur = (int)$ord['duracao_min'];
  $up  = (int)$ord['up_kbps'];
  $down= (int)$ord['down_kbps'];

  $pdo->prepare('INSERT INTO vip_passes (username, created_at, start_at, expires_at, status, policy_down_kbps, policy_up_kbps) VALUES (?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), "active", ?, ?)')
      ->execute([$ord['username'], $dur, $down, $up]);

  // aplica política de rede (mock)
  applyVipPolicy($ord['username'], $up, $down, $dur);

  echo 'ok';
} catch (Throwable $e) {
  http_response_code(500);
  echo 'error: '.$e->getMessage();
}
