<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/identifier.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/mp_client.php';
require_once __DIR__ . '/../../app/vip_order_session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
  $input = $_POST;
}

$csrfToken = (string) ($input['csrf_token'] ?? '');
if (!csrf_check($csrfToken)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
  exit;
}

$planId = isset($input['plan_id']) ? (int) $input['plan_id'] : 0;
$method = strtolower(trim((string) ($input['payment_method'] ?? '')));
$customer = isset($input['customer']) && is_array($input['customer']) ? $input['customer'] : [];
$context = isset($input['context']) && is_array($input['context']) ? $input['context'] : [];
$card = isset($input['card']) && is_array($input['card']) ? $input['card'] : [];

if ($planId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'plan_required']);
  exit;
}

if (!in_array($method, ['pix', 'card'], true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'payment_method_invalid']);
  exit;
}

$customerName = trim((string) ($customer['name'] ?? ''));
$customerCpf = fs_normalize_cpf((string) ($customer['cpf'] ?? ''));
$customerEmail = trim((string) ($customer['email'] ?? ''));
$customerPhone = fs_normalize_phone((string) ($customer['phone'] ?? ''));
$customerUsername = trim((string) ($customer['username'] ?? ''));

if ($customerUsername === '' && $customerCpf !== '') {
  $customerUsername = $customerCpf;
} elseif ($customerUsername === '' && $customerPhone !== '') {
  $customerUsername = $customerPhone;
}

$errors = [];
if ($customerName === '' || mb_strlen($customerName) < 3) {
  $errors[] = 'Informe seu nome completo.';
}
if (!fs_is_valid_cpf($customerCpf)) {
  $errors[] = 'Informe um CPF válido.';
}
$phoneCheck = fs_validate_phone_digits($customerPhone);
if (!($phoneCheck['valid'] ?? false)) {
  $errors[] = fs_identifier_reason_message($phoneCheck['reason'] ?? 'invalid_phone');
}
if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
  $errors[] = 'Informe um e-mail válido.';
}

if ($errors) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => $errors[0], 'errors' => $errors]);
  exit;
}

$contextIp = trim((string) ($context['ip'] ?? ''));
$contextMac = trim((string) ($context['mac'] ?? ''));
$contextHost = trim((string) ($context['host_code'] ?? ''));

if ($contextIp === '') {
  $contextIp = $_SESSION['hotspot_device_info']['ip'] ?? ($_SESSION['hotspot_ctx']['data']['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
}
if ($contextMac === '') {
  $contextMac = $_SESSION['hotspot_device_info']['mac'] ?? ($_SESSION['hotspot_ctx']['data']['mac'] ?? '');
}
if ($contextHost === '') {
  $contextHost = $_SESSION['hotspot_ctx']['data']['server-name'] ?? ($_SESSION['hotspot_device_info']['server-name'] ?? ($_SESSION['hotspot_device_info']['server_name'] ?? ''));
}

$contextMacNorm = $contextMac !== '' ? normalize_mac($contextMac) : '';
$contextIpNorm = $contextIp !== '' ? normalize_ip($contextIp) : '';
$contextMac = $contextMacNorm ?: '';
$contextIp = $contextIpNorm ?: $contextIp;

$paymentMethod = $method;
$requestedInstallments = 1;
if ($paymentMethod === 'card') {
  $requestedInstallments = isset($card['installments']) ? (int) $card['installments'] : 1;
  if ($requestedInstallments <= 0) {
    $requestedInstallments = 1;
  }
}
$paymentMethodDetail = $paymentMethod === 'pix' ? 'pix' : null;

$hasPaymentMeta = false;
try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  $hasPaymentMeta = table_has_column($pdo, 'vip_orders', 'payment_method');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
  exit;
}

$planStmt = $pdo->prepare('SELECT id, nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min FROM planos WHERE id = ? AND ativo = 1 LIMIT 1');
$planStmt->execute([$planId]);
$planRow = $planStmt->fetch();
if (!$planRow) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'plan_not_found']);
  exit;
}

$amountCents = (int) $planRow['preco_centavos'];
if ($amountCents <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'plan_amount_invalid']);
  exit;
}

$ddd = substr($customerPhone, 0, 2);
$phoneNumber = substr($customerPhone, 2);

$externalRef = 'VIP-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
$orderId = null;

try {
  $pdo->beginTransaction();

  $clientId = null;
  if ($customerCpf !== '') {
    $st = $pdo->prepare('SELECT id FROM clientes_info WHERE cpf = ? LIMIT 1');
    $st->execute([$customerCpf]);
    $clientId = $st->fetchColumn();
  }
  if (!$clientId && $customerPhone !== '') {
    $st = $pdo->prepare('SELECT id FROM clientes_info WHERE telefone = ? LIMIT 1');
    $st->execute([$customerPhone]);
    $clientId = $st->fetchColumn();
  }

  if ($clientId) {
    $upd = $pdo->prepare('UPDATE clientes_info SET nome = ?, telefone = ?, email = ?, aceitou_termos = 1 WHERE id = ?');
    $upd->execute([
      $customerName,
      $customerPhone,
      $customerEmail,
      (int) $clientId,
    ]);
  } else {
    $insCli = $pdo->prepare('INSERT INTO clientes_info (cpf, nome, telefone, email, aceitou_termos) VALUES (?,?,?,?,1)');
    $insCli->execute([
      $customerCpf,
      $customerName,
      $customerPhone,
      $customerEmail,
    ]);
  }

  if ($hasPaymentMeta) {
    $insOrder = $pdo->prepare('INSERT INTO vip_orders
      (username, nome, cpf, email, telefone, plano_id, valor_centavos, up_kbps, down_kbps, duracao_min, payment_method, payment_method_detail, payment_installments, external_ref, status, host_code, device_mac, device_ip, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $insOrder->execute([
      $customerUsername ?: null,
      $customerName,
      $customerCpf,
      $customerEmail,
      $customerPhone,
      (int) $planRow['id'],
      $amountCents,
      (int) $planRow['up_kbps'],
      (int) $planRow['down_kbps'],
      (int) $planRow['duracao_min'],
      $paymentMethod,
      $paymentMethodDetail,
      $requestedInstallments,
      $externalRef,
      'pending',
      $contextHost !== '' ? $contextHost : null,
      $contextMac !== '' ? $contextMac : null,
      $contextIp !== '' ? $contextIp : null,
    ]);
  } else {
    $insOrder = $pdo->prepare('INSERT INTO vip_orders
      (username, nome, cpf, email, telefone, plano_id, valor_centavos, up_kbps, down_kbps, duracao_min, external_ref, status, host_code, device_mac, device_ip, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $insOrder->execute([
      $customerUsername ?: null,
      $customerName,
      $customerCpf,
      $customerEmail,
      $customerPhone,
      (int) $planRow['id'],
      $amountCents,
      (int) $planRow['up_kbps'],
      (int) $planRow['down_kbps'],
      (int) $planRow['duracao_min'],
      $externalRef,
      'pending',
      $contextHost !== '' ? $contextHost : null,
      $contextMac !== '' ? $contextMac : null,
      $contextIp !== '' ? $contextIp : null,
    ]);
  }
  $orderId = (int) $pdo->lastInsertId();

  $pdo->commit();
  vip_order_session_bind($externalRef);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'order_create_failed']);
  exit;
}

$description = 'Acesso VIP - ' . (string) $planRow['nome'];
$payer = [
  'first_name'   => $customerName,
  'email'        => $customerEmail,
  'cpf'          => $customerCpf,
  'phone_area'   => $ddd,
  'phone_number' => $phoneNumber,
];
$metadata = [
  'order_id' => $orderId,
  'username' => $customerUsername,
  'plan_id' => (int) $planRow['id'],
];

try {
  if ($method === 'pix') {
    $pix = mp_create_pix_payment($amountCents, $externalRef, $description, $payer, $metadata);
    $paymentId = $pix['id'] ?? null;
    if ($paymentId) {
      if ($hasPaymentMeta) {
        $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id = ?, payment_method_detail = ?, updated_at = NOW() WHERE id = ?');
        $up->execute([$paymentId, 'pix', $orderId]);
      } else {
        $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id = ?, updated_at = NOW() WHERE id = ?');
        $up->execute([$paymentId, $orderId]);
      }
    }

    $qrBase64 = $pix['qr_code_base64'] ?? null;
    $qrText = $pix['qr_code'] ?? '';
    $ticketUrl = $pix['ticket_url'] ?? '';
    $expiresAt = null;
    if (!empty($pix['raw']['point_of_interaction']['transaction_data']['date_of_expiration'])) {
      $expiresAt = (string) $pix['raw']['point_of_interaction']['transaction_data']['date_of_expiration'];
    }

    echo json_encode([
      'ok' => true,
      'type' => 'pix',
      'order_id' => $orderId,
      'external_ref' => $externalRef,
      'qr_code' => $qrText,
      'qr_code_base64' => $qrBase64,
      'ticket_url' => $ticketUrl,
      'expires_at' => $expiresAt,
    ]);
    exit;
  }

  $cardToken = trim((string) ($card['token'] ?? ''));
  $cardMethod = trim((string) ($card['payment_method_id'] ?? ''));
  $cardIssuer = trim((string) ($card['issuer_id'] ?? ''));
  $cardInstallments = isset($card['installments']) ? (int) $card['installments'] : 1;

  if ($cardToken === '' || $cardMethod === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'card_data_missing']);
    exit;
  }

  $cardPayload = [
    'token' => $cardToken,
    'payment_method_id' => $cardMethod,
    'issuer_id' => $cardIssuer,
    'installments' => $cardInstallments > 0 ? $cardInstallments : 1,
    'metadata' => $metadata,
  ];

  $cardPayment = mp_create_card_payment($amountCents, $externalRef, $description, $payer, $cardPayload);
  $paymentId = $cardPayment['id'] ?? null;
  $status = strtolower((string) ($cardPayment['status'] ?? ''));
  $statusDetail = (string) ($cardPayment['status_detail'] ?? '');
  $responseInstallments = isset($cardPayment['installments']) ? (int) $cardPayment['installments'] : null;
  if ($responseInstallments && $responseInstallments > 0) {
    $cardInstallments = $responseInstallments;
  }
  $paymentDetail = $cardPayment['payment_method_id'] ?? ($cardPayment['payment_method'] ?? null);

  if ($paymentId) {
    if ($hasPaymentMeta) {
      $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id = ?, payment_method_detail = ?, payment_installments = ?, updated_at = NOW() WHERE id = ?');
      $up->execute([$paymentId, $paymentDetail ?: 'card', $cardInstallments > 0 ? $cardInstallments : 1, $orderId]);
    } else {
      $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id = ?, updated_at = NOW() WHERE id = ?');
      $up->execute([$paymentId, $orderId]);
    }
  }

  if ($status === 'approved') {
    $upStatus = $pdo->prepare('UPDATE vip_orders SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = ?');
    $upStatus->execute([$orderId]);
  }

  $message = 'Pagamento em processamento. Aguarde a confirmação.';
  if ($status === 'approved') {
    $message = 'Pagamento aprovado! Seu acesso premium será liberado em instantes.';
  } elseif ($status === 'rejected') {
    $message = 'Pagamento não aprovado pelo emissor do cartão.';
  }

  echo json_encode([
    'ok' => true,
    'type' => 'card',
    'order_id' => $orderId,
    'external_ref' => $externalRef,
    'status' => $status,
    'status_detail' => $statusDetail,
    'message' => $message,
    'redirect_url' => '/portal-v2/vip_sucesso.php?ref=' . rawurlencode($externalRef),
  ]);
  exit;

} catch (Throwable $e) {
  error_log('[checkout] payment error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'payment_failed']);
  exit;
}
