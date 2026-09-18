<?php
// /portal/qr.php — Entrada de código manual do parceiro (substitui scanner)
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/partner_hotspots.php';

$loggedIn = !empty($_SESSION['cliente_username']);
$returnHref = $loggedIn ? 'cliente.php' : 'index.php';
$returnLabel = $loggedIn ? '← Minha Conta' : '← Portal';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Detecta parceiro atual (quando vindo do card de acesso rápido) para exibir o nome do estabelecimento
$partnerName = '';
try {
  $fastId = trim((string)($_GET['fast_id'] ?? $_GET['id'] ?? ($_SESSION['portal_fast_id'] ?? '')));
  if ($fastId !== '') {
    $pdo = db();
    $hasPartners = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      $hotspot=fs_partner_hotspot_resolve($pdo,$fastId,false,true);
      if($hotspot)$partnerName=(string)$hotspot['name'];
      else{$st = $pdo->prepare("SELECT name FROM partners WHERE code=? AND active=1 LIMIT 1");$st->execute([$fastId]);$partnerName = (string)($st->fetchColumn() ?: '');}
    }
  }
} catch (\Throwable $e) { /* ignore */ }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <title>Acesso rápido • <?= $partnerName !== '' ? h($partnerName) : 'Código do Estabelecimento' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .wrap{ max-width:720px; margin:0 auto; padding:16px; }
    .center{ text-align:center }
    .form{ display:grid; gap:12px }
    .row{ display:flex; gap:8px; flex-wrap:wrap }
    input[type=text]{
      width:100%; padding:12px 14px; border:1px solid var(--border,#e5e7eb); border-radius:12px;
      font-size:16px; outline: none;
    }
    .btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border-radius:12px; border:1px solid var(--border,#e5e7eb); background:var(--card,#fff); cursor:pointer }
    .btn.primary{ background: var(--menu-accent,#eba725); font-weight:700 }
    .muted{ color: var(--muted,#6b7280) }
  </style>
</head>
<body>
  <div class="header inline">
    <a href="<?= h($returnHref) ?>"><?= h($returnLabel) ?></a>
  <strong>Acesso Rápido</strong>
    <span></span>
  </div>
  <main class="wrap">
    <div class="card" style="padding:18px;">
  <h2><?= $partnerName !== '' ? h($partnerName) : 'Digite o código do Estabelecimento' ?></h2>
  <p class="muted">Informe o código exibido no estabelecimento para liberar o seu acesso.</p>
      <form class="form" method="get" action="api/qr_check.php" onsubmit="return validateCode(this);">
        <input type="text" name="code" id="code" inputmode="latin" pattern="[A-Za-z0-9_-]{3,64}" maxlength="64" placeholder="Ex.: FIRE-CAFE-01" required autocomplete="one-time-code" />
        <div class="row">
          <button type="submit" class="btn primary">Continuar</button>
          <a class="btn" href="index.php">Cancelar</a>
        </div>
      </form>
    </div>
  </main>
  <script>
    function validateCode(f){
      var v = (f.code.value||'').trim();
      v = v.replace(/\s+/g,'');
      if (!/^[A-Za-z0-9_-]{3,64}$/.test(v)){
        alert('Código inválido. Use apenas letras, números, traço e sublinhado.');
        f.code.focus();
        return false;
      }
      f.code.value = v;
      return true;
    }
    // Auto-focus
    window.addEventListener('DOMContentLoaded', function(){ var el=document.getElementById('code'); if (el) el.focus(); });
  </script>
</body>
</html>
