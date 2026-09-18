<?php
require_once __DIR__ . '/_boot.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
if (!fs_portal_config_has_sales(fs_portal_config_for_partner($pdo,$partner))) v3_fail('Este estabelecimento não oferece acesso pago.',403);
$recoveredOrder = v3_recover_access($pdo, $partner);
if ($recoveredOrder) {
    header('Location: index.php?' . v3_hotspot_query($partner));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) v3_fail('Sua sessão expirou. Volte e escolha o acesso novamente.', 403);
    $_SESSION['portal_v3_selection'] = [
        'source' => (string) ($_POST['plan_source'] ?? ''),
        'id' => (int) ($_POST['plan_id'] ?? 0),
        'partner_id' => (int) $partner['id'],
        'hotspot_id' => fs_partner_hotspot_id($partner),
    ];
}
$selection = $_SESSION['portal_v3_selection'] ?? [];
if ((int) ($selection['partner_id'] ?? 0) !== (int) $partner['id']) v3_fail('Escolha uma opção de acesso antes de continuar.', 422);
if (fs_partner_hotspots_schema_ready($pdo) && (int)($selection['hotspot_id'] ?? 0) !== (int)(fs_partner_hotspot_id($partner) ?? 0)) v3_fail('A instalação da seleção foi alterada. Escolha o acesso novamente.',422);
$plan = fs_guest_plan($pdo, $partner, (string) ($selection['source'] ?? ''), (int) ($selection['id'] ?? 0));
if (!$plan) v3_fail('A opção escolhida não está mais disponível.', 404);
fs_partner_portal_metric_event($pdo,$partner,'checkout_view');

try {
    $wallet = fs_wallet_for_partner($pdo, $partner, false);
    $publicKey = trim((string) ($wallet['public_key'] ?? ''));
    if ($publicKey === '') throw new RuntimeException('A carteira ainda não está pronta para receber.');
} catch (Throwable $e) {
    v3_fail(v3_public_error($e));
}
$theme = v3_theme_current();
$subscriberAccount=fs_subscriber_current($pdo);$subscriberDevice=null;$canLinkAccount=false;
if($subscriberAccount&&fs_subscriber_feature_enabled($pdo,'subscriber_authenticated_purchase_enabled',false)){
    $subscriberDevice=fs_subscriber_device_current($pdo,array_merge(v3_device_context(),['partner_id'=>(int)$partner['id'],'hotspot_id'=>(int)(fs_partner_hotspot_id($partner)??0)]));
    $canLinkAccount=$subscriberDevice&&(int)$subscriberDevice['account_id']===(int)$subscriberAccount['id'];
}

$config = [
    'csrf' => csrf_token(),
    'publicKey' => $publicKey,
    'amount' => round(((int) $plan['price_cents']) / 100, 2),
    'payerEmail' => v3_anonymous_payer_email(),
    'apiCreate' => 'api/checkout_create.php',
    'successUrl' => 'sucesso.php',
    'canLinkAccount' => $canLinkAccount,
];
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>" data-v3-show-title="<?= (int)$theme['show_title'] ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>">
  <title>Pagamento — <?= v3_h($theme['brand_name']) ?></title>
  <link rel="stylesheet" href="assets/css/portal-v3.css?v=18">
  <?= v3_theme_css($theme) ?>
  <script>window.PORTAL_V3_CONFIG=<?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
  <script defer src="assets/js/checkout.js?v=7"></script>
</head>
<body class="checkout-page">
<main class="shell narrow checkout-shell">
  <section class="panel checkout-panel">
    <a class="back checkout-back" href="index.php?<?= v3_h(v3_hotspot_query($partner)) ?>&amp;step=plans">← Trocar plano</a>
    <?= v3_brand_mark($theme) ?>
    <div class="order-summary checkout-summary">
      <div><span>Seu acesso</span><h1><?= v3_h(v3_duration((int) $plan['duration_minutes'])) ?> de Wi-Fi</h1></div>
      <strong><?= v3_h(v3_money((int) $plan['price_cents'])) ?></strong>
    </div>
    <?php if($canLinkAccount):?><label class="checkout-link"><input id="link-account-purchase" type="checkbox" value="1" autocomplete="off"><span>Salvar esta compra na Minha Conta</span></label><?php endif;?>
    <div id="payment-error" class="status-box error" hidden></div>
    <div id="checkout-methods" class="checkout-methods">
      <button type="button" id="pay-with-pix" class="button primary checkout-method"><span>Pix</span><strong>Gerar código Pix</strong></button>
      <button type="button" id="pay-with-card" class="button secondary checkout-method"><span>Cartão</span><strong>Pagar com cartão</strong></button>
    </div>
    <div id="card-checkout" class="card-checkout" hidden>
      <button type="button" id="back-to-pix" class="card-checkout-back">← Voltar para Pix</button>
      <div id="paymentBrick_container" class="payment-brick"></div>
      <div id="payment-loading" class="status-box" hidden>Carregando cartão…</div>
    </div>
    <p class="checkout-secure">Pagamento processado pelo Mercado Pago</p>
  </section>
</main>
</body>
</html>
