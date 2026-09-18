<?php
require_once __DIR__ . '/_boot.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$portalConfig = fs_portal_config_for_partner($pdo, $partner);
$hasSales = fs_portal_config_has_sales($portalConfig);
$hasCourtesy = fs_portal_config_has_courtesy($portalConfig);
$isSponsored = fs_portal_config_is_sponsored($portalConfig);
$subscriberEnabled = fs_portal_config_subscriber_enabled($pdo, $portalConfig)
    && fs_subscriber_feature_enabled($pdo, 'subscriber_account_enabled', false)
    && fs_subscriber_feature_enabled($pdo, 'subscriber_invites_enabled', false)
    && fs_subscriber_feature_enabled($pdo, 'subscriber_radius_enabled', false);

$deviceContext = array_merge(v3_device_context(), [
    'partner_id' => (int) $partner['id'],
    'hotspot_id' => (int) (fs_partner_hotspot_id($partner) ?? 0),
]);
$subscriberDevice = $subscriberEnabled ? fs_subscriber_device_current($pdo, $deviceContext) : null;
$subscriberEntitlement = $subscriberDevice ? fs_subscriber_entitlement($pdo, (int) $subscriberDevice['account_id']) : null;
$subscriberReady = $subscriberDevice && fs_subscriber_entitlement_usable($subscriberEntitlement);
$subscriberAccount = $subscriberEnabled ? fs_subscriber_current($pdo) : null;

$courtesyPolicy = $hasCourtesy ? fs_courtesy_policy_resolve($pdo,(int)$partner['id'],fs_partner_hotspot_id($partner)) : [];
$courtesyMinutes = max(1, (int) ($courtesyPolicy['grant_minutes'] ?? 1));
$courtesyDecision = $hasCourtesy
    ? v3_courtesy_preflight($pdo, $partner, $courtesyPolicy)
    : fs_courtesy_result(false, 'NOT_APPLICABLE', 'Cortesia não oferecida.');
$courtesyCode = (string) ($courtesyDecision['code'] ?? 'COURTESY_UNAVAILABLE');
$courtesyCanStart = $hasCourtesy
    && (!empty($courtesyDecision['allowed']) || in_array($courtesyCode, ['AUTH_REQUIRED', 'ACTIVE_GRANT'], true));
$courtesyRetryAt = $courtesyCanStart ? null : v3_courtesy_retry_timestamp($courtesyDecision);
$courtesyWaitLabel = $courtesyRetryAt ? v3_courtesy_wait_label($courtesyRetryAt - time()) : '';
$courtesyBlockedLabel = $courtesyRetryAt
    ? 'Disponível em ' . $courtesyWaitLabel
    : (string) ($courtesyDecision['message'] ?? 'Acesso gratuito indisponível');
$hotspotQuery = v3_hotspot_query($partner);
$courtesyTarget = 'courtesy.php?' . $hotspotQuery;

/*
 * Retornos válidos têm prioridade sobre a vitrine. O visitante apenas confirma
 * a reconexão da modalidade já reconhecida; não recebe uma nova concessão e
 * não precisa escolher novamente entre benefício, cortesia e compra.
 */
$recoveredOrder = $hasSales ? v3_recover_access($pdo, $partner) : null;
if ($recoveredOrder) {
    $recoveredPublicId = rawurlencode((string)$recoveredOrder['public_id']);
    $paidReturn = (string)($recoveredOrder['status'] ?? '') === 'paid'
        ? v3_paid_return_state($pdo,$recoveredOrder)
        : null;
    if ($paidReturn && !empty($paidReturn['connected'])) {
        $destination = v3_paid_return_destination($partner,$pdo);
        if ($destination !== null) {
            header('Location: ' . $destination, true, 302);
        } else {
            header('Location: sucesso.php?order=' . $recoveredPublicId . '&return=1&connected=1');
        }
    } elseif ($paidReturn && !empty($paidReturn['connect_allowed']) && v3_claim_recent_paid_handoff($recoveredOrder)) {
        // O RouterOS acabou de reciclar o host da janela Pix e entregou um
        // desafio novo. Esta é a única continuação automática; uma eventual
        // falha volta ao fluxo confirmado para não criar loop de autenticação.
        header('Location: connect.php?order=' . $recoveredPublicId . '&handoff=1');
    } elseif ($paidReturn && v3_claim_paid_return_reconnect($recoveredOrder,$partner,$paidReturn)) {
        header('Location: connect.php?order=' . $recoveredPublicId . '&return=auto');
    } else {
        header('Location: sucesso.php?order=' . $recoveredPublicId . '&return=1');
    }
    exit;
}
if ($subscriberReady) {
    header('Location: subscriber.php?' . v3_hotspot_query($partner) . '&return=1');
    exit;
}
if ($courtesyCode === 'ACTIVE_GRANT') {
    header('Location: courtesy.php?' . v3_hotspot_query($partner) . '&return=1');
    exit;
}

$welcomeEnabled = fs_portal_config_welcome_enabled($portalConfig);
$singleOptionDirectEnabled = fs_portal_config_single_option_direct_enabled($portalConfig);
$singleVisibleMode = $singleOptionDirectEnabled ? fs_portal_config_single_visible_mode([
    'courtesy' => $hasCourtesy,
    'paid' => $hasSales,
    'subscriber' => $subscriberEnabled,
]) : null;
$directMode = $singleVisibleMode;
if ($directMode === 'courtesy' && !$courtesyCanStart) $directMode = null;

$requestedStage = strtolower(trim((string) ($_GET['step'] ?? '')));
$stage = $requestedStage !== '' ? $requestedStage : ($welcomeEnabled ? 'welcome' : 'options');
if (isset($_GET['credit']) || isset($_GET['payment'])) $stage = 'options';
if (!in_array($stage, ['welcome', 'options', 'plans'], true)) $stage = $welcomeEnabled ? 'welcome' : 'options';
if ($stage === 'welcome' && !$welcomeEnabled) $stage = 'options';
if ($stage === 'options' && $directMode !== null) {
    if ($directMode === 'paid') {
        $stage = 'plans';
    } elseif ($directMode === 'courtesy') {
        header('Location: ' . $courtesyTarget);
        exit;
    } else {
        header('Location: subscriber.php?' . v3_hotspot_query($partner));
        exit;
    }
}
if ($stage === 'plans' && !$hasSales) $stage = 'options';

$optionsBackHref = $welcomeEnabled ? 'index.php?' . $hotspotQuery : null;
$plansBackHref = $directMode === 'paid'
    ? ($welcomeEnabled ? 'index.php?' . $hotspotQuery : null)
    : 'index.php?' . $hotspotQuery . '&step=options';

$plans = [];
$salesError = '';
if ($stage === 'plans' && $hasSales) {
    try {
        $wallet = fs_wallet_for_partner($pdo, $partner, false);
        if (trim((string) ($wallet['public_key'] ?? '')) === '') throw new RuntimeException('O pagamento está temporariamente indisponível.');
        $plans = fs_guest_plans($pdo, $partner);
        if (!$plans) $salesError = 'Nenhum plano de acesso está disponível neste momento.';
    } catch (Throwable $e) {
        $salesError = v3_public_error($e, 'O pagamento está temporariamente indisponível.');
    }
}

fs_partner_portal_metric_event($pdo,$partner,'portal_open');
if($stage==='options')fs_partner_portal_metric_event($pdo,$partner,'options_view');
elseif($stage==='plans')fs_partner_portal_metric_event($pdo,$partner,'paid_options_view');

$theme = v3_theme_current();
$brand = (string) $theme['brand_name'];
$csrf = csrf_token();
$adBannerHtml='';
if($hasSales&&$stage==='welcome')$adBannerHtml=v3_ad_platform_banner($pdo,$partner,'welcome_banner','welcome');
elseif($hasSales&&$stage==='plans')$adBannerHtml=v3_ad_platform_banner($pdo,$partner,'plans_banner','plan_selection');

/*
 * Uma apresentação nova só participa do portal público depois de existir uma
 * revisão explicitamente publicada. Rascunhos e candidatos ficam invisíveis
 * para preservar integralmente o portal atual durante a migração manual.
 */
$publishedPresentation=fs_portal_presentation_get($pdo,(int)$partner['id'],'published');
if($publishedPresentation){
    $notices=[];
    if(($_GET['credit']??'')==='exhausted')$notices[]='Seu crédito anterior terminou. Escolha uma nova opção para continuar.';
    if(($_GET['payment']??'')==='expired')$notices[]='O Pix anterior venceu e foi cancelado. Você já pode fazer uma nova compra.';
    if(($_GET['payment']??'')==='not_approved')$notices[]='A compra anterior não foi aprovada. Escolha uma opção para tentar novamente.';
    $viewModel=fs_portal_view_model([
        'stage'=>$stage,'partner_id'=>(int)$partner['id'],'presentation'=>$publishedPresentation,'theme'=>$theme,
        'has_courtesy'=>$hasCourtesy,'is_sponsored'=>$isSponsored,'courtesy_minutes'=>$courtesyMinutes,
        'courtesy_can_start'=>$courtesyCanStart,'courtesy_blocked_label'=>$courtesyBlockedLabel,'courtesy_retry_at'=>$courtesyRetryAt,
        'courtesy_url'=>$courtesyTarget,'has_sales'=>$hasSales,'plans_url'=>'index.php?'.$hotspotQuery.'&step=plans',
        'subscriber_enabled'=>$subscriberEnabled,'subscriber_account'=>$subscriberAccount,'subscriber_url'=>'subscriber.php?'.$hotspotQuery,
        'plans'=>$plans,'checkout_url'=>'checkout.php','sales_error'=>$salesError,'notices'=>$notices,'csrf'=>$csrf,
        'back_url'=>$stage==='options'?($optionsBackHref??''):($stage==='plans'?($plansBackHref??''):''),'hotspot_query'=>$hotspotQuery,
        'ad_banner_html'=>$adBannerHtml,
    ]);
    $viewModel['ad_banner_html']=$adBannerHtml;
    $vm=$viewModel;
    require fs_portal_skin_view_file((string)$viewModel['skin']['code']);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>" data-v3-show-title="<?= (int) $theme['show_title'] ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>">
  <title><?= v3_h($brand) ?> — Wi-Fi</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="assets/css/portal-v3.css?v=19">
  <?= v3_theme_css($theme) ?>
</head>
<body class="portal-entry-page portal-stage-<?= v3_h($stage) ?>">
<?php if ($stage === 'welcome'): ?>
<main class="shell narrow entry-shell">
  <section class="panel entry-card">
    <?= v3_brand_mark($theme) ?>
    <p class="entry-brand-title brand-title"><?= v3_h($brand) ?></p>
    <p class="eyebrow">Seja bem-vindo</p>
    <h1>Conecte-se ao Wi-Fi</h1>
    <p class="muted">Escolha como deseja acessar.</p>
    <a class="button primary entry-continue" href="index.php?<?= v3_h($hotspotQuery) ?>&amp;step=options">Continuar</a>
  </section>
  <?=$adBannerHtml?>
  <p class="footer-note"><?= v3_h($brand) ?> · Conexão protegida · <a href="../portal/termos.php">Privacidade e publicidade</a></p>
</main>
<?php elseif ($stage === 'options'): ?>
<main class="shell narrow access-options-shell">
  <section class="panel access-options-header">
    <?= v3_brand_mark($theme) ?>
    <h1>Escolha seu acesso</h1>
    <?php if (($_GET['credit'] ?? '') === 'exhausted'): ?><div class="notice">Seu crédito anterior terminou. Escolha uma nova opção para continuar.</div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'expired'): ?><div class="notice">O Pix anterior venceu e foi cancelado. Você já pode fazer uma nova compra.</div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'not_approved'): ?><div class="notice">A compra anterior não foi aprovada. Escolha uma opção para tentar novamente.</div><?php endif; ?>
  </section>

  <section class="access-choice-grid" aria-label="Modalidades de acesso disponíveis">
    <?php if ($hasCourtesy): ?>
    <article class="panel access-choice access-choice--courtesy">
      <div class="access-choice-icon" aria-hidden="true"><?= $isSponsored ? '▶' : '✓' ?></div>
      <div class="access-choice-copy">
        <h2><?= $isSponsored ? 'Internet patrocinada' : 'Usar cortesia' ?></h2>
        <p class="access-choice-meta"><?= v3_h(v3_duration($courtesyMinutes)) ?> grátis<?= $isSponsored ? ' após o anúncio' : '' ?></p>
      </div>
      <a class="button primary courtesy-entry-button<?= $courtesyCanStart ? '' : ' is-disabled' ?>"<?= $courtesyCanStart ? ' href="' . v3_h($courtesyTarget) . '"' : ' aria-disabled="true" tabindex="-1"' ?> data-courtesy-target="<?= v3_h($courtesyTarget) ?>" data-courtesy-ready-label="<?= $isSponsored ? 'Assistir e conectar' : 'Usar cortesia' ?>"<?= $courtesyRetryAt ? ' data-courtesy-retry-at="' . (int) $courtesyRetryAt . '"' : '' ?>><?= v3_h($courtesyCanStart ? ($isSponsored ? 'Assistir e conectar' : 'Usar cortesia') : $courtesyBlockedLabel) ?></a>
    </article>
    <?php endif; ?>

    <?php if ($hasSales): ?>
    <article class="panel access-choice access-choice--paid">
      <div class="access-choice-icon" aria-hidden="true">★</div>
      <div class="access-choice-copy">
        <h2>Comprar acesso</h2>
        <p class="access-choice-meta">Pix ou cartão</p>
      </div>
      <a class="button primary" href="index.php?<?= v3_h($hotspotQuery) ?>&amp;step=plans">Ver planos</a>
    </article>
    <?php endif; ?>

    <?php if ($subscriberEnabled): ?>
    <article class="panel access-choice access-choice--subscriber">
      <div class="access-choice-icon" aria-hidden="true">F</div>
      <div class="access-choice-copy">
        <h2>Benefício FIRENETWORK</h2>
        <p class="access-choice-meta">Conta ou convite</p>
      </div>
      <a class="button primary" href="subscriber.php?<?= v3_h($hotspotQuery) ?>"><?= $subscriberAccount ? 'Autorizar aparelho' : 'Acessar benefício' ?></a>
    </article>
    <?php endif; ?>

    <?php if (!$hasCourtesy && !$hasSales && !$subscriberEnabled): ?>
    <article class="panel access-choice access-choice--empty">
      <div class="access-choice-copy"><p class="eyebrow">Indisponível</p><h2>Nenhuma modalidade ativa</h2><p class="muted">Este estabelecimento ainda não possui uma opção de acesso publicada.</p></div>
    </article>
    <?php endif; ?>
  </section>
  <?php if ($optionsBackHref !== null): ?><a class="back options-back" href="<?= v3_h($optionsBackHref) ?>">← Voltar</a><?php endif; ?>
</main>
<?php else: ?>
<main class="shell plans-shell">
  <section class="hero panel plans-hero<?= !empty($theme['logo_url']) ? ' hero-has-logo' : '' ?>">
    <div class="step">Acesso premium</div>
    <?= v3_brand_mark($theme) ?>
    <p class="eyebrow brand-title"><?= v3_h($brand) ?></p>
    <h1>Escolha seu plano</h1>
  </section>
  <section class="panel plans-catalog">
    <?php if ($plansBackHref !== null): ?><a class="back" href="<?= v3_h($plansBackHref) ?>"><?= $directMode === 'paid' ? '← Voltar às boas-vindas' : '← Voltar às modalidades' ?></a><?php endif; ?>
    <?php if (($_GET['credit'] ?? '') === 'exhausted'): ?><div class="notice">Seu crédito anterior terminou. Escolha um novo plano para continuar.</div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'expired'): ?><div class="notice">O Pix anterior venceu e foi cancelado. Você já pode fazer uma nova compra.</div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'not_approved'): ?><div class="notice">A compra anterior não foi aprovada. Escolha um plano para tentar novamente.</div><?php endif; ?>
    <div class="section-title"><div><span class="eyebrow">Planos disponíveis</span><h2>Quanto tempo você precisa?</h2></div><span class="secure-pill">Pagamento seguro</span></div>
    <?php if ($salesError !== ''): ?>
      <div class="status-box error"><?= v3_h($salesError) ?></div>
    <?php else: ?>
      <p class="preset-title">Compre seu acesso via Pix ou cartão</p>
      <div class="plan-grid">
        <?php foreach ($plans as $index => $plan): ?>
          <form method="post" action="checkout.php" class="plan-card<?= $index === 0 ? ' featured' : '' ?>">
            <input type="hidden" name="csrf" value="<?= v3_h($csrf) ?>">
            <input type="hidden" name="plan_source" value="<?= v3_h($plan['source']) ?>">
            <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
            <?php if ($index === 0): ?><span class="popular">Mais escolhido</span><?php endif; ?>
            <h3><?= v3_h($plan['name']) ?></h3>
            <div class="price"><?= v3_h(v3_money((int) $plan['price_cents'])) ?></div>
            <div class="duration"><?= v3_h(v3_duration((int) $plan['duration_minutes'])) ?> de acesso</div>
            <ul><li>Download: <?= v3_h(v3_speed((int) $plan['download_kbps'])) ?></li><li>Upload: <?= v3_h(v3_speed((int) $plan['upload_kbps'])) ?></li><li>Liberação automática</li></ul>
            <?php $compactLabel = 'Wi-Fi ' . v3_duration((int) $plan['duration_minutes']) . ' — ' . v3_money((int) $plan['price_cents']); ?>
            <button class="button primary" type="submit">
              <span class="modern-plan-label">Escolher este acesso</span>
              <span class="compact-plan-label"><?= v3_h($compactLabel) ?></span>
              <span class="v3-plan-pill-label"><span><?= v3_h($plan['name']) ?></span><strong><?= v3_h(v3_money((int) $plan['price_cents'])) ?></strong></span>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?=$adBannerHtml?>
  <p class="footer-note"><?= v3_h($brand) ?> · Pagamento seguro e liberação automática · <a href="../portal/termos.php">Privacidade e publicidade</a></p>
</main>
<?php endif; ?>
<?php if ($stage === 'options' && $hasCourtesy): ?><script>
(() => {
  const buttons = [...document.querySelectorAll('.courtesy-entry-button[aria-disabled="true"]')];
  const formatWait = seconds => {
    seconds = Math.max(1, Math.ceil(seconds));
    const days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), minutes = Math.floor(seconds % 3600 / 60);
    if (days > 0) return `${days}d ${String(hours).padStart(2, '0')}h`;
    if (hours > 0) return `${hours}h ${String(minutes).padStart(2, '0')}min`;
    if (minutes > 0) return `${minutes}min ${String(seconds % 60).padStart(2, '0')}s`;
    return `${seconds}s`;
  };
  let interval = null;
  const update = () => {
    const now = Math.floor(Date.now() / 1000);
    let waiting = false;
    buttons.forEach(button => {
      if (button.getAttribute('aria-disabled') !== 'true') return;
      const retryAt = Number(button.dataset.courtesyRetryAt || 0);
      if (!retryAt) return;
      const remaining = retryAt - now;
      if (remaining > 0) { waiting = true; button.textContent = `Disponível em ${formatWait(remaining)}`; return; }
      button.textContent = button.dataset.courtesyReadyLabel || 'Continuar';
      button.href = button.dataset.courtesyTarget;
      button.classList.remove('is-disabled');
      button.removeAttribute('aria-disabled');
      button.removeAttribute('tabindex');
    });
    if (!waiting && interval) { clearInterval(interval); interval = null; }
    return waiting;
  };
  buttons.forEach(button => button.addEventListener('click', event => { if (button.getAttribute('aria-disabled') === 'true') event.preventDefault(); }));
  if (update()) interval = setInterval(update, 1000);
})();
</script><?php endif; ?>
</body>
</html>
