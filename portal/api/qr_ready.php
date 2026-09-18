<?php
// /portal/qr_ready.php — Tela intermediária do fluxo QR com auth
// Mostra “Você terá X minutos” + botão “Começar agora” -> vai para qr_check.php?code=...

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/partner_helpers.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Precisa estar logado
if (empty($_SESSION['cliente_username'])) {
  // volta para login mantendo a volta para o QR
  $code = trim((string)($_GET['code'] ?? ''));
  $next = '/portal/api/qr_check.php?code=' . rawurlencode($code);
  header('Location: /portal/login.php?next=' . rawurlencode($next));
  exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') { http_response_code(400); echo 'Código ausente.'; exit; }

$minutes = null; $partner = null; $codeParam = '';

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");

  $st = $pdo->prepare("
    SELECT *
      FROM partners
     WHERE code=? AND active=1
     LIMIT 1
  ");
  $st->execute([$code]);
  $partner = $st->fetch(PDO::FETCH_ASSOC);
  if (!$partner) {
    http_response_code(410);
    echo 'QR inválido ou fora da janela ativa.';
    exit;
  }
  $minutes = max(1, (int)$partner['free_minutes']);
  if (partner_profile($partner) === 'comerciante') {
    $minutes = min($minutes, 5);
  }
  $codeParam = rawurlencode($code);
} catch (\Throwable $e) {
  http_response_code(500);
  echo 'Falha ao preparar acesso.';
  exit;
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Acesso pronto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/portal.css">
  <style>
    .wrap{ max-width:720px; margin:24px auto; padding:0 16px; }
    .center{ text-align:center }
  </style>
</head>
<body>
  <div class="header inline">
    <a href="index.php">← Portal</a>
    <strong>Acesso por QR</strong>
    <span></span>
  </div>

  <main class="wrap">
    <div class="card center">
      <h2><?= h($partner['name']) ?></h2>
      <p>Pronto! Ao iniciar, seu acesso será liberado por <b><?= h($minutes) ?> minuto(s)</b>.</p>
      <?php if (partner_profile($partner) === 'comerciante'): ?>
        <p style="margin-top:8px;">Parceiros comerciais oferecem um acesso rápido de até 5 minutos para concluir o pagamento.</p>
      <?php endif; ?>
      <p>
        <a class="btn primary" href="/portal/anuncio.php?next=<?= h(rawurlencode('/portal/api/qr_check.php?code=' . $code . '&ad=1')) ?>">
          Começar agora
        </a>
      </p>
      <small class="muted">Você será conectado automaticamente ao Wi-Fi do Hotspot.</small>
    </div>
  </main>
</body>
</html>
