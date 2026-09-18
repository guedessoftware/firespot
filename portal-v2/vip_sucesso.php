<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/company.php';

$refRaw = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$ref = trim($refRaw);
$orderRow = null;

if ($ref !== '') {
  try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $st = $pdo->prepare('SELECT nome, valor_centavos, duracao_min, paid_at, status FROM vip_orders WHERE external_ref = ? LIMIT 1');
    $st->execute([$ref]);
    $orderRow = $st->fetch();
  } catch (Throwable $e) {
    $orderRow = null;
  }
}

$companyProfile = company_get();
$brandName = trim((string) ($companyProfile['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string) ($companyProfile['subtitle'] ?? 'Wi-Fi seguro e rápido')) ?: 'Wi-Fi seguro e rápido';
$logoRaw = (string) ($companyProfile['logo_letter'] ?? 'F');
$brandLogo = strtoupper(substr($logoRaw, 0, 1)) ?: 'F';

$amountLabel = '';
$durationLabel = '';
$statusTag = 'Pagamento aprovado';
$statusClass = 'success';

if (is_array($orderRow)) {
  if (isset($orderRow['valor_centavos'])) {
    $amountLabel = format_money_br((int) $orderRow['valor_centavos']);
  }
  if (isset($orderRow['duracao_min']) && (int) $orderRow['duracao_min'] > 0) {
    $minutes = (int) $orderRow['duracao_min'];
    if ($minutes >= 1440 && $minutes % 1440 === 0) {
      $days = (int) ($minutes / 1440);
      $durationLabel = $days === 1 ? '1 dia' : $days . ' dias';
    } elseif ($minutes >= 60 && $minutes % 60 === 0) {
      $hours = (int) ($minutes / 60);
      $durationLabel = $hours === 1 ? '1 hora' : $hours . ' horas';
    } else {
      $durationLabel = $minutes . ' minutos';
    }
  }
  $status = strtolower((string) ($orderRow['status'] ?? ''));
  if ($status !== '' && $status !== 'paid') {
    $statusTag = 'Pagamento em processamento';
    $statusClass = 'info';
  }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VIP liberado — <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <style>
    body {
      background: #f5f5f7;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 16px;
    }
    .success-card {
      width: min(460px, 100%);
      background: #ffffff;
      border-radius: 28px;
      box-shadow: var(--shadow-soft);
      padding: 32px;
      display: grid;
      gap: 22px;
    }
    .success-card header {
      display: flex;
      gap: 14px;
      align-items: center;
    }
    .brand-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .brand-name {
      font-size: 18px;
      font-weight: 700;
      color: var(--black);
      line-height: 1.1;
    }
    .brand-subtitle {
      font-size: 13px;
      color: var(--gray-500);
    }
    .brand-badge {
      width: 48px;
      height: 48px;
      border-radius: 16px;
      background: var(--orange);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      font-weight: 700;
    }
    .headline {
      font-size: 24px;
      font-weight: 700;
      color: var(--black);
      margin: 4px 0 0;
      text-align: center;
    }
    .status-tag {
      display: inline-flex;
      align-items: center;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }
    .status-tag.success {
      background: #dcfce7;
      color: #166534;
    }
    .status-tag.info {
      background: #dbeafe;
      color: #1d4ed8;
    }
    .summary-box {
      border-radius: 18px;
      background: var(--gray-100);
      padding: 18px;
      display: grid;
      gap: 8px;
    }
    .summary-item {
      display: flex;
      justify-content: space-between;
      font-size: 14px;
      color: var(--gray-600);
    }
    .summary-item strong {
      color: var(--black);
      font-size: 15px;
    }
    .actions {
      display: grid;
      gap: 12px;
    }
    .btn-primary {
      background: var(--orange);
      color: #fff;
      border: none;
      border-radius: 999px;
      padding: 12px 18px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      justify-content: center;
    }
    .btn-outline {
      background: transparent;
      border: 1px solid var(--gray-300);
      color: var(--gray-700);
      border-radius: 999px;
      padding: 12px 18px;
      font-size: 14px;
      font-weight: 600;
      display: inline-flex;
      justify-content: center;
    }
  </style>
</head>
<body>
  <div class="success-card">
    <header>
      <div class="brand-badge"><?= htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="brand-info">
        <span class="brand-name"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="brand-subtitle"><?= htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </header>

    <div class="headline">VIP liberado!</div>

    <span class="status-tag <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusTag, ENT_QUOTES, 'UTF-8') ?></span>

    <?php if ($ref !== ''): ?>
      <div class="summary-box">
        <div class="summary-item"><span>Referência</span><strong><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php if ($amountLabel !== ''): ?>
          <div class="summary-item"><span>Valor pago</span><strong><?= htmlspecialchars($amountLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php endif; ?>
        <?php if ($durationLabel !== ''): ?>
          <div class="summary-item"><span>Duração do acesso</span><strong><?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p style="font-size:14px;color:var(--gray-600);margin:0;">Seu acesso premium está liberado. Clique abaixo para voltar ao portal e começar a navegar.</p>

    <div class="actions">
      <a class="btn-primary" href="home.php">Voltar ao portal</a>
    </div>
  </div>
</body>
</html>
