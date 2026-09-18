<?php
require_once __DIR__ . '/../app/session_boot.php';

// Detecção do novo portal V2
if (isset($_GET['newportal']) && $_GET['newportal'] === 'yes') {
  // Preserva todos os parâmetros da URL
  $queryParams = $_GET;
  unset($queryParams['newportal']); // Remove o parâmetro de controle
  $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
  header('Location: ../portal-v2/index.php' . $queryString);
  exit;
}

require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/adsense.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/partner_hotspots.php';

$csrf = csrf_token();
$accountDeleted = isset($_GET['account_deleted']);

$showFastCard = true; // sempre mostrar o card de acesso rápido
$fastCardName = '';
$fastFreeMinutes = null; // minutos específicos do parceiro (se houver)
$defaultFreeMinutes = (int) settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 5);
if ($defaultFreeMinutes <= 0) {
  $defaultFreeMinutes = 5;
}
if ($defaultFreeMinutes > 120) {
  $defaultFreeMinutes = 120;
}

// Usa fast_id do GET ou o persistido na sessão
$fastIdRaw = trim((string) ($_GET['fast_id'] ?? $_GET['id'] ?? ($_SESSION['portal_fast_id'] ?? '')));
$fastIdNormalized = $fastIdRaw !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $fastIdRaw) : '';
if ($fastIdNormalized !== '') {
  $_SESSION['portal_fast_id'] = $fastIdNormalized;
}

if ($fastIdRaw !== '') {
  try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $hasPartners = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      $partner=fs_partner_hotspot_resolve($pdo,$fastIdNormalized,false,false);
      if(!$partner){$st = $pdo->prepare("SELECT code, name, require_auth, free_minutes, active FROM partners WHERE code=? LIMIT 1");$st->execute([$fastIdRaw]);$partner=$st->fetch()?:null;}
      if ($partner) {
        $isActive = (int) ($partner['active'] ?? 0) === 1;
        if ($isActive) {
          // Mostra nome do parceiro quando ativo, independente de exigir autenticação
          $fastCardName = trim((string) ($partner['name'] ?? ''));
          $m = (int) ($partner['free_minutes'] ?? 0);
          if ($m > 0) {
            $fastFreeMinutes = min(120, $m);
          }
        }
      }
    }
  } catch (Throwable $e) {
    // silencia falhas para não quebrar a página pública
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FireSpot — Portal</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <script defer src="assets/js/portal.js"></script>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <?= adsense_head_snippet() ?>
  <style>
    /* Button with end-aligned time badge */
    .btn.btn-wide {
      display: inline-flex;
      justify-content: space-between;
      align-items: center;
      min-width: 240px;
    }

    .btn .time-badge {
      margin-left: 12px;
      background: #fff3da;
      border-color: #f1cf87;
      color: #7a4a00;
    }

    @media (max-width:640px) {
      .btn.btn-wide {
        min-width: 100%;
      }

      .btn .time-badge {
        font-size: 12px;
        padding: 2px 6px;
      }
    }
  </style>
</head>

<body>
  <div class="header inline"><img src="../dashboard/assets/img/logo-light.png" alt="" width="140"><a
      href="termos.php">Termos
      & LGPD</a></div>
  <div class="container">
    <?php if ($accountDeleted): ?>
      <div class="notice" style="margin-bottom:16px; color:#166534; background:#ecfdf5; border-color:#bbf7d0;">
        Seu cadastro foi removido com sucesso. Se mudar de ideia, é só criar uma nova conta.
      </div>
    <?php endif; ?>
    <div class="grid">
      <?php if ($showFastCard): ?>
        <div class="card">
          <h3>Acesso rápido<?= $fastCardName !== '' ? ' • ' . htmlspecialchars($fastCardName, ENT_QUOTES, 'UTF-8') : '' ?>
          </h3>
          <p class="notice">Peça o codigo do Estabelecimento para liberar acesso grátis.</p>
          <a class="btn primary btn-wide" href="qr.php">Código do Estabelecimento <span class="badge time-badge">⏱️
              <?= (int) ($fastFreeMinutes ?? $defaultFreeMinutes) ?> min</span></a>
        </div>
      <?php endif; ?>
      <div class="card">
        <h3>Visitante</h3>
        <p class="notice">Cadastre-se pra liberar acesso diario, ou compre o Plano Premium.</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn primary" href="login.php">Login visitante</a>
          <a class="btn" href="cadastro.php">Criar conta</a>
        </div>
      </div>
      <div class="card">
        <h3>Cliente FIRENETWORK</h3>
        <p class="notice">Digite seu CPF e sua datata de nascimento para confirmar seu cadastro.</p>
        <a class="btn primary" href="login_cpf.php">Entrar com CPF</a>
      </div>
    </div>
  </div>
  <div class="footer">© <?= date('Y') ?> FIRENETWORK</div>
</body>

</html>
