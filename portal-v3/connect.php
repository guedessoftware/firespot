<?php
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../app/portal_v3_payment_window.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$publicId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['order'] ?? '')));
$orderToken = v3_order_token($publicId);
$order = fs_guest_order_for_session($pdo, $publicId, $orderToken);
if (!$order || (int) $order['partner_id'] !== (int) $partner['id'] || $order['status'] !== 'paid') v3_fail('O acesso ainda não está disponível.', 403);
$partner = v3_context_for_order($pdo,$order,$partner);
$returnState = v3_paid_return_state($pdo,$order);
if (!empty($returnState['connected'])) {
    $destination = v3_paid_return_destination($partner,$pdo);
    header('Location: ' . ($destination ?? ('sucesso.php?order=' . rawurlencode($publicId) . '&return=1&connected=1')), true, 302);
    exit;
}
if (!empty($returnState['handoff_pending'])) {
    header('Location: sucesso.php?order=' . rawurlencode($publicId), true, 302);
    exit;
}
if (empty($returnState['same_device'])) {
    v3_fail('Este acesso pertence ao aparelho usado na compra.',403);
}
// Esta requisição já está dentro da WebView com o desafio do Hotspot. Remova
// o bypass, mas preserve o host até o POST de autenticação logo abaixo.
if (!fs_v3_payment_window_close($pdo, $partner, $order, false)) {
    v3_fail('Não foi possível concluir a transição para o acesso pago. Tente novamente.');
}
$order = fs_guest_grant_access($pdo, (int) $order['id']);
v3_remember_order($order, $orderToken);
try {
    $order = fs_guest_prepare_reconnect($pdo, (int) $order['id']);
} catch (RuntimeException $e) {
    v3_clear_recovery_cookie((int) $partner['id']);
    header('Location: index.php?' . v3_hotspot_query($partner) . '&credit=exhausted');
    exit;
}
$username = (string) $order['radius_username'];
$password = fs_guest_radius_password($pdo, $username);
if ($password === null) v3_fail('Não foi possível preparar a autenticação no Wi-Fi.');

$ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
$loginUrl = v3_hotspot_login_url($partner,trim((string) ($ctx['link-login-only'] ?? $ctx['link-login'] ?? '')));
if ($loginUrl === null) v3_fail('Volte às configurações do Wi-Fi e abra novamente esta rede para concluir a conexão.');
$chapId = (string)($ctx['chap-id'] ?? '');
$challenge = (string)($ctx['chap-challenge'] ?? '');
$loginPassword = fs_hotspot_login_password($password,$chapId,$challenge);
if ($loginPassword === null) v3_fail('O desafio de autenticação do Wi-Fi é inválido. Abra novamente esta rede para conectar.');
$theme = v3_theme_current();
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>" data-v3-show-title="<?= (int)$theme['show_title'] ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>"><title>Conectando… — <?= v3_h($theme['brand_name']) ?></title><link rel="stylesheet" href="assets/css/portal-v3.css?v=18"><?= v3_theme_css($theme) ?></head>
<body>
<main class="shell narrow"><section class="panel success-panel"><?= v3_brand_mark($theme) ?><div class="success-icon">✓</div><h1>Conectando ao Wi-Fi…</h1><p class="muted">Seu pagamento foi confirmado e o acesso já está liberado.</p></section></main>
<form id="hotspot-login" method="post" action="<?= v3_h($loginUrl) ?>" hidden>
  <input type="hidden" name="username" value="<?= v3_h($username) ?>">
  <input type="hidden" name="password" value="<?= v3_h($loginPassword) ?>">
  <?php if (!empty($ctx['link-orig-esc'])): ?><input type="hidden" name="dst" value="<?= v3_h($ctx['link-orig-esc']) ?>"><?php endif; ?>
</form>
<script>setTimeout(function(){document.getElementById('hotspot-login').submit();},500);</script>
</body></html>
