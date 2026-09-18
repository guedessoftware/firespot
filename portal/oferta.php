<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/partner_ads.php';
require_once __DIR__ . '/../app/ad_monetization.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$token = trim((string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
$pdo = db();
$offer = partner_ads_pending_offer_token($pdo,$token);
$partnerId = (int)($offer['partner_id'] ?? 0);

if (!$offer) {
    http_response_code(404);
    $message = 'Esta oferta não está mais disponível.';
} else {
    $message = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        $message = 'Sua sessão expirou. Reabra a oferta.';
    } elseif (!$offer) {
        http_response_code(404);
    } elseif (($_POST['action'] ?? '') === 'open') {
        $consumed = partner_ads_consume_offer($pdo,$token,'open');
        if (!$consumed) { http_response_code(409); $offer=null; $message='Esta oferta já foi utilizada.'; }
        else {
          try { partner_ads_track($pdo,$partnerId,(int)$consumed['ad_id'],'destination_open',$_SESSION['cliente_username']??null,$_SESSION['hotspot_device_info']['mac']??null,isset($consumed['hotspot_id'])?(int)$consumed['hotspot_id']:null); }
          catch (Throwable $e) { error_log('[ad destination_open] ' . $e->getMessage()); }
          if(!empty($consumed['delivery_id'])){try{fs_ad_charge_click_by_delivery_id($pdo,(int)$consumed['delivery_id']);}catch(Throwable $e){error_log('[ad qualified click] '.get_class($e));}}
          $destination = (string)$consumed['link_url'];
          header('Location: ' . $destination,true,303);
          exit;
        }
    } elseif (($_POST['action'] ?? '') === 'dismiss') {
        partner_ads_consume_offer($pdo,$token,'dismiss');
        $offer = null;
        $message = 'Tudo certo. Seu Wi-Fi já está conectado.';
    } else {
        http_response_code(400);
        $message = 'Ação inválida.';
    }
}

function offer_h(string $value): string
{
    return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <title><?= $offer ? 'Oferta salva' : 'Wi-Fi conectado' ?> — FireSpot</title>
  <style>
    :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
    *{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;background:radial-gradient(circle at top,#183250,#071321 58%);color:#f8fafc;padding:24px}
    main{width:min(100%,520px);padding:32px;border:1px solid rgba(255,255,255,.14);border-radius:24px;background:rgba(7,19,33,.88);box-shadow:0 24px 80px rgba(0,0,0,.38);text-align:center}
    .icon{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;margin:0 auto 20px;background:#22c55e;color:#052e16;font-size:32px;font-weight:900}
    .eyebrow{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#93c5fd}h1{margin:8px 0 12px;font-size:clamp(26px,7vw,38px)}p{color:#cbd5e1;line-height:1.55}
    form{display:grid;gap:12px;margin-top:24px}button{width:100%;border:0;border-radius:14px;padding:15px 18px;font:inherit;font-weight:800;cursor:pointer}.primary{background:#f59e0b;color:#1c1403}.secondary{background:#1e293b;color:#e2e8f0;border:1px solid #334155}
  </style>
</head>
<body>
<main>
  <div class="icon">✓</div>
  <?php if ($offer): ?>
    <div class="eyebrow">Wi-Fi conectado</div>
    <h1>Você demonstrou interesse</h1>
    <p>A oferta “<?= offer_h((string)$offer['title']) ?>” foi guardada até a conexão terminar. Agora você pode abri-la sem interromper o acesso ao Wi-Fi.</p>
    <form method="post" action="oferta.php">
      <input type="hidden" name="csrf" value="<?= offer_h(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= offer_h($token) ?>">
      <button class="primary" type="submit" name="action" value="open">Abrir oferta</button>
      <button class="secondary" type="submit" name="action" value="dismiss">Agora não</button>
    </form>
  <?php else: ?>
    <div class="eyebrow">FireSpot</div>
    <h1>Conexão concluída</h1>
    <p><?= offer_h($message !== '' ? $message : 'Seu acesso ao Wi-Fi está pronto.') ?></p>
  <?php endif; ?>
</main>
</body>
</html>
