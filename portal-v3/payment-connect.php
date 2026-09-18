<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../app/portal_v3_payment_window.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$publicId = preg_replace('/[^a-f0-9]/','',strtolower((string)($_GET['order'] ?? '')));
$token = v3_order_token($publicId);
$order = fs_guest_order_for_session($pdo,$publicId,$token);
if (!$order || (int)$order['partner_id'] !== (int)$partner['id'] || (string)$order['status'] !== 'pending') {
    v3_fail('Este Pix não está disponível para conexão temporária.',403);
}
$partner = v3_context_for_order($pdo,$order,$partner);
$policy = fs_v3_payment_window_status($pdo,$partner,$order);
if (empty($policy['active']) || (string)($order['payment_access_mode'] ?? '') !== FS_GUEST_PAYMENT_ACCESS_RADIUS) {
    v3_fail('Libere novamente a internet para o Pix antes de continuar.',409);
}

$prepared = fs_guest_prepare_provisional_access($pdo,(int)$order['id'],max(30,(int)$policy['current_remaining_seconds']));
$username = (string)$prepared['radius_username'];
$password = (string)($prepared['_radius_password'] ?? fs_guest_radius_password(fs_radius_db(),$username));
if ($password === '') v3_fail('Não foi possível preparar a autenticação temporária.',503);

$ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
$loginUrl = v3_hotspot_login_url($partner,trim((string)($ctx['link-login-only'] ?? $ctx['link-login'] ?? '')));
if ($loginUrl === null) v3_fail('Abra novamente esta rede Wi-Fi para iniciar o acesso temporário.',409);
$loginPassword = fs_hotspot_login_password($password,(string)($ctx['chap-id'] ?? ''),(string)($ctx['chap-challenge'] ?? ''));
if ($loginPassword === null) v3_fail('A sessão do Wi-Fi expirou. Abra novamente esta rede para continuar.',409);
$returnUrl = v3_public_base_url($pdo) . '/portal-v3/sucesso.php?order=' . rawurlencode($publicId) . '&preauth=1';
$theme = v3_theme_current();
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>" data-v3-show-title="<?= (int)$theme['show_title'] ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>"><title>Conectando para o Pix</title><link rel="stylesheet" href="assets/css/portal-v3.css?v=18"><?= v3_theme_css($theme) ?></head>
<body>
<main class="shell narrow"><section class="panel success-panel"><?= v3_brand_mark($theme) ?><div class="success-icon">&#10003;</div><h1>Abrindo acesso para o Pix...</h1><p class="muted">Aguarde um instante.</p></section></main>
<form id="hotspot-login" method="post" action="<?= v3_h($loginUrl) ?>" hidden>
  <input type="hidden" name="username" value="<?= v3_h($username) ?>">
  <input type="hidden" name="password" value="<?= v3_h($loginPassword) ?>">
  <input type="hidden" name="dst" value="<?= v3_h($returnUrl) ?>">
</form>
<script>window.setTimeout(function(){document.getElementById('hotspot-login').submit();},250);</script>
</body></html>
