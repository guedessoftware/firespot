<?php

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../app/partner_ads.php';

$pdo = db();
$partner = v3_context($pdo);
$credentials = $_SESSION['portal_v3_courtesy_credentials'] ?? [];
if ((int)($credentials['partner_id'] ?? 0) !== (int)$partner['id']
    || (int)($credentials['hotspot_id'] ?? 0) !== (int)(fs_partner_hotspot_id($partner) ?? 0)
    || (int)($credentials['expires_at'] ?? 0) < time()) {
    v3_fail('A credencial da cortesia expirou. Solicite novamente.',403);
}
unset($_SESSION['portal_v3_courtesy_credentials']);
$username = (string)$credentials['username'];
$password = (string)$credentials['password'];
$ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
$loginUrl = v3_hotspot_login_url($partner,trim((string)($ctx['link-login-only'] ?? $ctx['link-login'] ?? '')));
if ($loginUrl === null) v3_fail('Abra novamente esta rede Wi-Fi para concluir a conexão.');

$destination = partner_ads_pending_offer_url($pdo,(int)$partner['id']);

$chapId = (string)($ctx['chap-id'] ?? '');
$challenge = (string)($ctx['chap-challenge'] ?? '');
$loginPassword = fs_hotspot_login_password($password,$chapId,$challenge);
if ($loginPassword === null) v3_fail('O desafio de autenticação do Wi-Fi é inválido. Abra novamente esta rede para conectar.');
$theme = v3_theme_current();
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?=v3_h($theme['theme_mode'])?>" data-v3-preset="<?=v3_h($theme['theme_preset'])?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conectando…</title><link rel="stylesheet" href="assets/css/portal-v3.css?v=18"><?=v3_theme_css($theme)?></head>
<body>
<main class="shell narrow"><section class="panel success-panel"><?=v3_brand_mark($theme)?><div class="success-icon">✓</div><h1>Conectando ao Wi-Fi…</h1><p class="muted"><?=$destination?'Sua oferta será exibida assim que a conexão terminar.':'Sua cortesia foi liberada pelo motor unificado.'?></p></section></main>
<form id="hotspot-login" method="post" action="<?=v3_h($loginUrl)?>" hidden>
  <input type="hidden" name="username" value="<?=v3_h($username)?>">
  <input type="hidden" name="password" value="<?=v3_h($loginPassword)?>">
  <?php if ($destination !== null): ?><input type="hidden" name="dst" value="<?=v3_h($destination)?>"><?php endif; ?>
</form>
<script>setTimeout(()=>document.getElementById('hotspot-login').submit(),500);</script>
</body>
</html>
