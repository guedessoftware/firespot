<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/company.php';

$_SESSION['portal_v2_active'] = true;

if (!function_exists('portal_v2_escape')) {
  function portal_v2_escape($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}

$company = company_get();
$brandName = trim((string) ($company['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string) ($company['subtitle'] ?? 'Wi-Fi seguro e rápido'));
$logoSeed = (string) ($company['logo_letter'] ?? '');
$brandLogo = strtoupper(substr($logoSeed !== '' ? $logoSeed : $brandName, 0, 1));
if ($brandLogo === '') {
  $brandLogo = 'F';
}

$isLogged = !empty($_SESSION['cliente_username']);
$backHref = $isLogged ? 'home.php' : 'index.php';
$backLabel = $isLogged ? 'Minha Conta' : 'Portal';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conectar — <?= portal_v2_escape($brandName) ?></title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/notice.css">
</head>
<body class="notice-body">
  <div class="notice-shell">
    <header class="notice-header">
      <a class="notice-back" href="<?= portal_v2_escape($backHref) ?>">&larr; <?= portal_v2_escape($backLabel) ?></a>
      <strong class="notice-header-title">Conectar</strong>
      <span></span>
    </header>

    <main class="notice-card">
      <div class="notice-brand">
        <div class="notice-logo"><?= portal_v2_escape($brandLogo) ?></div>
        <div class="notice-brand-text">
          <span class="notice-brand-name"><?= portal_v2_escape($brandName) ?></span>
          <?php if ($brandSubtitle !== ''): ?>
            <span class="notice-brand-subtitle"><?= portal_v2_escape($brandSubtitle) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="notice-content">
        <h1>Contexto do Hotspot ausente</h1>
        <p>Para conectar automaticamente, abra o navegador já conectado ao Wi-Fi e acesse a página inicial (captura do portal). Em seguida, volte a tentar.</p>
      </div>

      <div class="notice-actions">
        <a class="notice-button" href="index.php">Ir para o Portal</a>
      </div>

      <div class="notice-secondary">
        Se o problema continuar, abra a rede Wi-Fi novamente, confirme o login pelo portal e prossiga para a conexão.
      </div>
    </main>
  </div>
</body>
</html>
