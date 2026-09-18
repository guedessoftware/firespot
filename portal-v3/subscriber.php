<?php
require_once __DIR__ . '/_boot.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$config = fs_portal_config_for_partner($pdo, $partner);
if (!fs_portal_config_subscriber_enabled($pdo, $config)
    || !fs_subscriber_feature_enabled($pdo, 'subscriber_account_enabled', false)) {
    v3_fail('O benefício FIRENETWORK não está disponível nesta unidade.', 404);
}
fs_partner_portal_metric_event($pdo,$partner,'subscriber_selection');

$deviceContext = array_merge(v3_device_context(), [
    'partner_id' => (int) $partner['id'],
    'hotspot_id' => (int) (fs_partner_hotspot_id($partner) ?? 0),
]);
$device = fs_subscriber_device_current($pdo, $deviceContext);
$account = fs_subscriber_current($pdo);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!csrf_check($_POST['csrf'] ?? '')) throw new RuntimeException('Sua sessão expirou. Recarregue a página.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'authorize_primary') {
            if (!$account) throw new RuntimeException('Entre na Minha Conta para autorizar este aparelho.');
            fs_subscriber_authorize_primary($pdo, (int) $account['id'], $deviceContext);
        } elseif ($action === 'redeem') {
            fs_subscriber_invite_redeem($pdo, (string) ($_POST['invite_code'] ?? ''), $deviceContext);
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        header('Location: subscriber.php?' . v3_hotspot_query($partner) . '&return=1');
        exit;
    } catch (Throwable $e) {
        $error = v3_public_error($e);
    }
}

$return = '/portal-v3/subscriber.php?' . v3_hotspot_query($partner);
$theme = v3_theme_current();
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>">
  <title>Cliente FIRENETWORK — <?= v3_h($theme['brand_name']) ?></title>
  <link rel="stylesheet" href="assets/css/portal-v3.css?v=18">
  <?= v3_theme_css($theme) ?>
</head>
<body>
<main class="shell narrow">
  <section class="panel<?= $device ? ' success-panel' : '' ?>">
    <?= v3_brand_mark($theme) ?>
    <p class="eyebrow"><?= $device ? 'Acesso reconhecido' : 'Benefício FIRENETWORK' ?></p>
    <h1><?= $device ? 'Bem-vindo de volta' : 'Autorize este aparelho' ?></h1>
    <p class="muted"><?= $device
        ? 'Este aparelho já possui o benefício FIRENETWORK. Confirme para reconectar sem consumir cortesia ou comprar outro acesso.'
        : 'Cada aparelho usa uma autorização individual. O convidado não precisa conhecer a conta nem a senha do titular.' ?></p>
    <?php if ($error): ?><div class="status-box error"><?= v3_h($error) ?></div><?php endif; ?>

    <?php if ($device): ?>
      <div class="status-box">Benefício disponível para este aparelho.</div>
      <form method="post" action="subscriber_connect.php">
        <input type="hidden" name="csrf" value="<?= v3_h(csrf_token()) ?>">
        <button class="button primary" type="submit">Confirmar e conectar</button>
      </form>
    <?php else: ?>
      <?php if ($account): ?>
        <form method="post" class="stack subscriber-stack">
          <input type="hidden" name="csrf" value="<?= v3_h(csrf_token()) ?>">
          <input type="hidden" name="action" value="authorize_primary">
          <button class="button primary" type="submit">Usar como aparelho principal</button>
        </form>
        <a class="button secondary subscriber-action" href="/conta/?next=<?= rawurlencode($return) ?>">Gerenciar vagas na Minha Conta</a>
      <?php else: ?>
        <a class="button primary subscriber-action" href="/conta/?next=<?= rawurlencode($return) ?>">Entrar como titular</a>
      <?php endif; ?>
      <div class="divider"><span>ou</span></div>
      <form method="post" class="stack subscriber-stack">
        <input type="hidden" name="csrf" value="<?= v3_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="redeem">
        <label><span>Código do convite</span><input name="invite_code" inputmode="text" autocomplete="one-time-code" maxlength="48" placeholder="ABCD2345" required></label>
        <button class="button secondary" type="submit">Usar convite único</button>
      </form>
      <a class="back" href="index.php?<?= v3_h(v3_hotspot_query($partner)) ?>&amp;step=options">← Voltar às modalidades</a>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
