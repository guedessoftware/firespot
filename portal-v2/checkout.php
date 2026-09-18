<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/company.php';
require_once __DIR__ . '/../app/identifier.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/mp_client.php';

if (!function_exists('portal_v2_format_duration')) {
  function portal_v2_format_duration(int $minutes): string
  {
    if ($minutes <= 0) {
      return 'Sem limite';
    }
    if ($minutes % 1440 === 0) {
      $days = (int) ($minutes / 1440);
      return $days === 1 ? '1 dia' : $days . ' dias';
    }
    if ($minutes % 60 === 0) {
      $hours = (int) ($minutes / 60);
      return $hours === 1 ? '1 hora' : $hours . ' horas';
    }
    return $minutes . ' minutos';
  }
}

if (!function_exists('portal_v2_format_speed')) {
  function portal_v2_format_speed(int $kbps): string
  {
    if ($kbps <= 0) {
      return '0 kbps';
    }
    if ($kbps >= 1000) {
      $mbps = $kbps / 1000;
      if ($kbps % 1000 === 0) {
        return (int) ($kbps / 1000) . ' Mbps';
      }
      return number_format($mbps, 1, ',', '.') . ' Mbps';
    }
    return $kbps . ' kbps';
  }
}

if (!function_exists('portal_v2_format_money')) {
  function portal_v2_format_money(int $centavos): string
  {
    return 'R$ ' . number_format(max(0, $centavos) / 100, 2, ',', '.');
  }
}

if (!function_exists('portal_v2_session_context')) {
  function portal_v2_session_context(string $key, $default = '')
  {
    $ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
    if ($key === 'mac') {
      if (!empty($ctx['mac'])) {
        return (string) $ctx['mac'];
      }
      if (!empty($_SESSION['hotspot_device_info']['mac'])) {
        return (string) $_SESSION['hotspot_device_info']['mac'];
      }
    }
    if ($key === 'ip') {
      if (!empty($ctx['ip'])) {
        return (string) $ctx['ip'];
      }
      if (!empty($_SESSION['hotspot_device_info']['ip'])) {
        return (string) $_SESSION['hotspot_device_info']['ip'];
      }
    }
    if ($key === 'host_code') {
      if (!empty($ctx['server-name'])) {
        return (string) $ctx['server-name'];
      }
      if (!empty($_SESSION['hotspot_device_info']['server-name'])) {
        return (string) $_SESSION['hotspot_device_info']['server-name'];
      }
      if (!empty($_SESSION['hotspot_device_info']['server_name'])) {
        return (string) $_SESSION['hotspot_device_info']['server_name'];
      }
    }
    return $default;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['_csrf'] ?? '')) {
    $_SESSION['checkout_error'] = 'Sessão expirada. Volte e selecione o plano novamente.';
    header('Location: index.php');
    exit;
  }

  $planId = isset($_POST['plano_id']) ? (int) $_POST['plano_id'] : 0;
  $payload = [
    'plan_id'   => $planId,
    'username'  => trim((string) ($_POST['username'] ?? '')),
    'nome'      => trim((string) ($_POST['nome'] ?? '')),
    'cpf'       => fs_normalize_cpf((string) ($_POST['cpf'] ?? '')),
    'email'     => trim((string) ($_POST['email'] ?? '')),
    'telefone'  => fs_normalize_phone((string) ($_POST['telefone'] ?? '')),
    'ip'        => trim((string) ($_POST['ip'] ?? '')),
    'mac'       => trim((string) ($_POST['mac'] ?? '')),
    'host_code' => trim((string) ($_POST['host_code'] ?? '')),
  ];

  if ($payload['ip'] === '') {
    $payload['ip'] = portal_v2_session_context('ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
  }
  if ($payload['mac'] === '') {
    $payload['mac'] = portal_v2_session_context('mac', '');
  }
  if ($payload['host_code'] === '') {
    $payload['host_code'] = portal_v2_session_context('host_code', '');
  }

  unset($_SESSION['checkout_error']);

  $_SESSION['checkout_v2'] = $payload;
  header('Location: checkout.php');
  exit;
}

$payload = $_SESSION['checkout_v2'] ?? null;
if (!$payload) {
  header('Location: index.php');
  exit;
}

$planId = (int) ($payload['plan_id'] ?? 0);
if ($planId <= 0) {
  $_SESSION['checkout_error'] = 'Escolha um plano premium para continuar.';
  header('Location: index.php');
  exit;
}

$errorBanner = $_SESSION['checkout_error'] ?? '';
if ($errorBanner !== '') {
  unset($_SESSION['checkout_error']);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
} catch (Throwable $e) {
  $_SESSION['checkout_error'] = 'Não foi possível carregar os dados do plano. Tente novamente.';
  header('Location: index.php');
  exit;
}

$planStmt = $pdo->prepare('SELECT id, nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min, descricao FROM planos WHERE id = ? AND ativo = 1 LIMIT 1');
$planStmt->execute([$planId]);
$planRow = $planStmt->fetch();
if (!$planRow) {
  $_SESSION['checkout_error'] = 'Plano selecionado não está mais disponível.';
  header('Location: index.php');
  exit;
}

// Atualiza plan_id consolidado na sessão para evitar inconsistências.
$_SESSION['checkout_v2']['plan_id'] = (int) $planRow['id'];

$companyProfile = company_get();
$brandName = trim((string) ($companyProfile['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string) ($companyProfile['subtitle'] ?? 'Wi-Fi seguro e rápido')) ?: 'Wi-Fi seguro e rápido';
$logoRaw = (string) ($companyProfile['logo_letter'] ?? 'F');
$brandLogo = strtoupper(substr($logoRaw, 0, 1)) ?: 'F';

$customerUsername = trim((string) ($payload['username'] ?? ''));
$customerName = trim((string) ($payload['nome'] ?? ''));
$customerCpf = fs_normalize_cpf((string) ($payload['cpf'] ?? ''));
$customerEmail = trim((string) ($payload['email'] ?? ''));
$customerPhone = fs_normalize_phone((string) ($payload['telefone'] ?? ''));

if ($customerUsername === '' && $customerCpf !== '') {
  $customerUsername = $customerCpf;
} elseif ($customerUsername === '' && $customerPhone !== '') {
  $customerUsername = $customerPhone;
}

if ($customerName === '' && !empty($_SESSION['cliente_nome'])) {
  $customerName = trim((string) $_SESSION['cliente_nome']);
}
if ($customerEmail === '' && !empty($_SESSION['cliente_email'])) {
  $customerEmail = trim((string) $_SESSION['cliente_email']);
}

$contextIp = $payload['ip'] !== '' ? $payload['ip'] : portal_v2_session_context('ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$contextMac = $payload['mac'] !== '' ? $payload['mac'] : portal_v2_session_context('mac', '');
$contextHost = $payload['host_code'] !== '' ? $payload['host_code'] : portal_v2_session_context('host_code', '');

$contextMacNorm = $contextMac !== '' ? normalize_mac($contextMac) : '';
$contextIpNorm = $contextIp !== '' ? normalize_ip($contextIp) : '';
$contextMac = $contextMacNorm ?: '';
$contextIp = $contextIpNorm ?: $contextIp;

$apiCsrf = csrf_token();
$publicKey = mp_public_key();

$planPriceCents = (int) $planRow['preco_centavos'];
$planPriceDisplay = portal_v2_format_money($planPriceCents);
$planDurationLabel = portal_v2_format_duration((int) $planRow['duracao_min']);
$planSpeedDown = portal_v2_format_speed((int) $planRow['down_kbps']);
$planSpeedUp = portal_v2_format_speed((int) $planRow['up_kbps']);

$cardTabClasses = 'tab-btn';
$cardTabDisabledAttr = '';
if ($publicKey === '') {
  $cardTabClasses .= ' disabled';
  $cardTabDisabledAttr = ' disabled';
}

$checkoutConfig = [
  'csrfToken' => $apiCsrf,
  'plan' => [
    'id' => (int) $planRow['id'],
    'name' => (string) $planRow['nome'],
    'priceCents' => $planPriceCents,
    'priceFormatted' => $planPriceDisplay,
    'durationMinutes' => (int) $planRow['duracao_min'],
    'durationLabel' => $planDurationLabel,
    'downKbps' => (int) $planRow['down_kbps'],
    'upKbps' => (int) $planRow['up_kbps'],
    'group' => (string) ($planRow['grupo'] ?? ''),
  ],
  'customer' => [
    'username' => $customerUsername,
    'name' => $customerName,
    'cpf' => $customerCpf,
    'email' => $customerEmail,
    'phone' => $customerPhone,
  ],
  'context' => [
    'ip' => $contextIp,
    'mac' => $contextMac,
    'host_code' => $contextHost,
  ],
  'api' => [
    'create' => 'api/checkout_create.php',
    'paymentStatus' => '../portal/api/payment_status.php',
    'promoteVip' => '../portal/api/vip_promote.php',
  ],
  'publicKey' => $publicKey,
  'successUrl' => '/portal-v2/vip_sucesso.php',
];

$customerCpfDisplay = $customerCpf !== '' ? fs_format_cpf($customerCpf) : '';
$customerPhoneDisplay = $customerPhone !== '' ? fs_format_phone($customerPhone) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout Premium — <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <style>
    body.checkout-body {
      background: #f3f4f6;
      padding: 32px 16px;
      display: flex;
      justify-content: center;
      min-height: 100vh;
    }
    .checkout-wrapper {
      width: min(960px, 100%);
      display: grid;
      gap: 24px;
    }
    .checkout-card {
      background: #ffffff;
      border-radius: 24px;
      box-shadow: var(--shadow-soft);
      padding: 28px;
    }
    .checkout-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }
    .checkout-header .brand {
      gap: 12px;
    }
    .checkout-header h1 {
      font-size: 20px;
      margin: 0;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 18px;
      margin-bottom: 28px;
    }
    .summary-box {
      border-radius: 18px;
      background: var(--gray-100);
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .summary-box strong {
      font-size: 16px;
      color: var(--black);
    }
    .summary-meta {
      font-size: 12px;
      color: var(--gray-500);
    }
    .checkout-form {
      display: grid;
      gap: 20px;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px 16px;
    }
    .tabs {
      display: inline-flex;
      border-radius: 999px;
      background: var(--gray-100);
      padding: 4px;
      gap: 6px;
      margin-bottom: 12px;
    }
    .tab-btn {
      border: none;
      background: transparent;
      padding: 8px 16px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      color: var(--gray-500);
    }
    .tab-btn.active {
      background: var(--orange);
      color: #ffffff;
      box-shadow: 0 6px 16px rgba(255, 122, 0, 0.35);
    }
    .payment-panel {
      border-radius: 18px;
      border: 1px solid var(--gray-200);
      padding: 18px;
      display: none;
      flex-direction: column;
      gap: 14px;
    }
    .payment-panel.active {
      display: flex;
    }
    .pix-area {
      display: grid;
      gap: 14px;
    }
    .pix-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .pix-code {
      font-family: monospace;
      font-size: 12px;
      width: 100%;
      min-height: 90px;
      padding: 10px;
      border-radius: 12px;
      border: 1px solid var(--gray-200);
      background: #fafafa;
      resize: none;
    }
    .status-box {
      border-radius: 14px;
      padding: 12px;
      font-size: 12px;
      background: var(--gray-100);
      color: var(--black);
    }
    .status-box.success {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #22c55e;
    }
    .status-box.error {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    .card-grid {
      display: grid;
      gap: 12px;
    }
    .card-grid .input-group {
      margin-top: 0;
    }
    .payment-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    .btn[disabled] {
      opacity: 0.65;
      cursor: not-allowed;
      box-shadow: none;
    }
    @media (max-width: 720px) {
      .checkout-card {
        padding: 22px;
      }
    }
  </style>
  <script src="https://sdk.mercadopago.com/js/v2"></script>
  <script>window.CHECKOUT_CONFIG = <?= json_encode($checkoutConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
</head>
<body class="checkout-body">
  <div class="checkout-wrapper">
    <div class="checkout-card">
      <div class="checkout-header">
        <div class="brand">
          <div class="brand-logo"><?= htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle"><?= htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>
        <div>
          <h1>Checkout Premium</h1>
          <p style="font-size:12px;color:var(--gray-500);">Finalize seu acesso com PIX ou cartão em poucos passos.</p>
        </div>
      </div>

      <?php if ($errorBanner): ?>
        <div class="banner-error" style="margin-bottom:18px;">
          <?= htmlspecialchars($errorBanner, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($publicKey === ''): ?>
        <div class="banner-error" style="margin-bottom:18px;">
          Configure sua chave pública do Mercado Pago (MERCADOPAGO_PUBLIC_KEY) para habilitar pagamentos com cartão.
        </div>
      <?php endif; ?>

      <div class="summary-grid">
        <div class="summary-box">
          <span class="summary-meta">Plano escolhido</span>
          <strong><?= htmlspecialchars($planRow['nome'], ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="summary-meta">Válido por <?= htmlspecialchars($planDurationLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="summary-box">
          <span class="summary-meta">Valor</span>
          <strong><?= htmlspecialchars($planPriceDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="summary-meta">Velocidade: ↓ <?= htmlspecialchars($planSpeedDown, ENT_QUOTES, 'UTF-8') ?> · ↑ <?= htmlspecialchars($planSpeedUp, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>

      <form id="checkout-customer-form" class="checkout-form" autocomplete="on">
        <div class="form-grid">
          <div class="input-group">
            <label class="input-label" for="customer-name">Nome completo</label>
            <input class="input-field" type="text" id="customer-name" name="name" required minlength="3" value="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Como está no documento">
          </div>
          <div class="input-group">
            <label class="input-label" for="customer-cpf">CPF</label>
            <input class="input-field" type="text" id="customer-cpf" name="cpf" inputmode="numeric" maxlength="14" value="<?= htmlspecialchars($customerCpfDisplay, ENT_QUOTES, 'UTF-8') ?>" placeholder="000.000.000-00">
          </div>
          <div class="input-group">
            <label class="input-label" for="customer-phone">WhatsApp</label>
            <input class="input-field" type="text" id="customer-phone" name="phone" inputmode="numeric" maxlength="15" value="<?= htmlspecialchars($customerPhoneDisplay, ENT_QUOTES, 'UTF-8') ?>" placeholder="(99) 99999-9999">
          </div>
          <div class="input-group">
            <label class="input-label" for="customer-email">E-mail</label>
            <input class="input-field" type="email" id="customer-email" name="email" required value="<?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="voce@dominio.com">
          </div>
        </div>

        <div>
          <span class="input-label" style="font-size:13px; font-weight:600;">Forma de pagamento</span>
          <div class="tabs" role="tablist">
            <button type="button" class="tab-btn active" data-method="pix">Pagar com PIX</button>
            <button type="button" class="<?= htmlspecialchars($cardTabClasses, ENT_QUOTES, 'UTF-8') ?>" data-method="card"<?= $cardTabDisabledAttr ?>>Cartão de crédito</button>
          </div>
        </div>

        <div id="panel-pix" class="payment-panel active" data-method="pix">
          <div class="pix-area">
            <p style="font-size:12px;color:var(--gray-500);">Gere o QR Code e pague usando qualquer banco ou carteira digital. O acesso premium é liberado assim que o pagamento for confirmado.</p>
            <div class="pix-actions">
              <button type="button" class="btn btn-primary" id="btn-generate-pix">Gerar QR Code PIX</button>
              <button type="button" class="btn btn-outline" id="btn-copy-pix" disabled>Copiar código</button>
              <a id="btn-open-ticket" class="btn btn-outline" href="#" target="_blank" rel="noopener" style="display:none;">Abrir no app</a>
            </div>
            <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start;">
              <img id="pix-qr-image" src="" alt="QR Code PIX" style="display:none;width:200px;height:200px;border-radius:16px;border:1px solid var(--gray-200);padding:8px;background:#fff;">
              <textarea id="pix-code" class="pix-code" readonly placeholder="O código PIX aparecerá aqui após a geração." disabled></textarea>
            </div>
            <div id="pix-status" class="status-box" style="display:none;"></div>
          </div>
        </div>

        <div id="panel-card" class="payment-panel" data-method="card">
          <div class="card-grid">
            <div class="input-group">
              <label class="input-label" for="card-number">Número do cartão</label>
              <input class="input-field" type="text" id="form-card-number" data-checkout="cardNumber" placeholder="0000 0000 0000 0000">
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <div class="input-group" style="flex:1 1 120px;">
                <label class="input-label" for="card-expiration">Validade</label>
                <input class="input-field" type="text" id="form-card-expiration-date" data-checkout="cardExpirationDate" placeholder="MM/AA">
              </div>
              <div class="input-group" style="flex:1 1 120px;">
                <label class="input-label" for="card-cvv">CVV</label>
                <input class="input-field" type="text" id="form-card-security-code" data-checkout="securityCode" placeholder="123">
              </div>
            </div>
            <div class="input-group">
              <label class="input-label" for="card-holder-name">Nome impresso</label>
              <input class="input-field" type="text" id="form-card-holder-name" data-checkout="cardholderName" placeholder="Como está no cartão">
            </div>
            <div class="input-group">
              <label class="input-label" for="card-holder-email">E-mail do titular</label>
              <input class="input-field" type="email" id="form-card-holder-email" data-checkout="cardholderEmail" placeholder="voce@dominio.com" value="<?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <div class="input-group" style="flex:1 1 140px;">
                <label class="input-label" for="doc-type">Documento</label>
                <select class="input-field" id="form-doc-type" data-checkout="identificationType">
                  <option value="CPF">CPF</option>
                </select>
              </div>
              <div class="input-group" style="flex:1 1 180px;">
                <label class="input-label" for="doc-number">Número do documento</label>
                <input class="input-field" type="text" id="form-doc-number" data-checkout="identificationNumber" placeholder="00000000000" value="<?= htmlspecialchars($customerCpf, ENT_QUOTES, 'UTF-8') ?>">
              </div>
            </div>
            <div class="input-group">
              <label class="input-label" for="installments">Parcelas</label>
              <select class="input-field" id="form-installments" data-checkout="installments"></select>
            </div>
            <div class="input-group">
              <label class="input-label" for="card-issuer">Banco emissor</label>
              <select class="input-field" id="form-issuer" data-checkout="issuer"></select>
            </div>
          </div>
          <div class="payment-actions">
            <button type="submit" class="btn btn-primary" id="btn-pay-card">Pagar com cartão</button>
          </div>
          <div id="card-status" class="status-box" style="display:none;"></div>
        </div>

        <input type="hidden" id="checkout-username" value="<?= htmlspecialchars($customerUsername, ENT_QUOTES, 'UTF-8') ?>">
      </form>

      <div id="checkout-feedback" class="status-box" style="display:none;margin-top:18px;"></div>
    </div>
  </div>
  <script defer src="assets/js/checkout.js"></script>
</body>
</html>
