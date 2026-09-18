<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/company.php';
require_once __DIR__ . '/../app/identifier.php';
require_once __DIR__ . '/../app/helpers.php';

$_SESSION['portal_v2_active'] = true;

if (!function_exists('portal_v2_format_duration')) {
  function portal_v2_format_duration(int $minutes): string
  {
    if ($minutes <= 0) {
      return 'Sem limite';
    }
    if ($minutes % 1440 === 0) {
      $days = (int) ($minutes / 1440);
      return $days === 1 ? '1 dia' : $days . ' dias';
    }
    if ($minutes % 60 === 0) {
      $hours = (int) ($minutes / 60);
      return $hours === 1 ? '1 hora' : $hours . ' horas';
    }
    return $minutes . ' minutos';
  }
}

if (!function_exists('portal_v2_format_speed')) {
  function portal_v2_format_speed(int $kbps): string
  {
    if ($kbps <= 0) {
      return '0 kbps';
    }
    if ($kbps >= 1000) {
      $mbps = $kbps / 1000;
      if ($kbps % 1000 === 0) {
        return (int) ($kbps / 1000) . ' Mbps';
      }
      return number_format($mbps, 1, ',', '.') . ' Mbps';
    }
    return $kbps . ' kbps';
  }
}

if (!function_exists('portal_v2_format_money')) {
  function portal_v2_format_money(int $centavos): string
  {
    return 'R$ ' . number_format(max(0, $centavos) / 100, 2, ',', '.');
  }
}

if (!function_exists('portal_v2_format_wait_time')) {
  function portal_v2_format_wait_time(int $minutes): string
  {
    if ($minutes <= 1) {
      return '1 minuto';
    }
    if ($minutes < 60) {
      return $minutes . ' minutos';
    }
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    $hourText = $hours === 1 ? '1 hora' : $hours . ' horas';
    if ($remaining <= 0) {
      return $hourText;
    }
    $minuteText = $remaining === 1 ? '1 minuto' : $remaining . ' minutos';
    return $hourText . ' e ' . $minuteText;
  }
}

$csrf = csrf_token();

if (!empty($_SESSION['cliente_username'])) {
  header('Location: home.php');
  exit;
}

$quickErrorMessage = $_SESSION['quick_error'] ?? '';
if ($quickErrorMessage !== '') {
  unset($_SESSION['quick_error']);
}

$quickPrefill = $_SESSION['quick_prefill'] ?? '';
if ($quickPrefill !== '') {
  unset($_SESSION['quick_prefill']);
}

$forceScreen = $_SESSION['portal_v2_force_screen'] ?? '';
if ($forceScreen !== '') {
  unset($_SESSION['portal_v2_force_screen']);
}

$limitInfo = $_SESSION['portal_v2_limit_info'] ?? null;
if ($limitInfo !== null) {
  unset($_SESSION['portal_v2_limit_info']);
}

$identifierPrefill = '';
if ($quickPrefill !== '') {
  $prefillDigits = fs_digits_only($quickPrefill);
  $identifierPrefill = $prefillDigits;
} elseif (!empty($_SESSION['quick_identifier']['sanitized'])) {
  $identifierPrefill = (string) $_SESSION['quick_identifier']['sanitized'];
}

$pdo = null;
try {
  $pdo = db();
  if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone='-04:00'");
  }
} catch (Throwable $e) {
  $pdo = null;
  error_log('[portal-v2] db connect failed: ' . $e->getMessage());
}

// Configurações do portal
$companyProfile = company_get();
$brandName = trim((string)($companyProfile['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string)($companyProfile['subtitle'] ?? 'Wi-Fi seguro e rápido')) ?: 'Wi-Fi seguro e rápido';
$logoRaw = (string)($companyProfile['logo_letter'] ?? 'F');
$brandLogo = strtoupper(substr($logoRaw, 0, 1));
if ($brandLogo === '') {
  $brandLogo = 'F';
}
$hostDisplayName = $brandName;

$freeMinutes = (int) settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 15);
if ($freeMinutes <= 0) { $freeMinutes = 15; }
if ($freeMinutes > 120) { $freeMinutes = 120; }

// Detecção de parceiro (fast_id)
$fastIdRaw = trim((string) ($_GET['fast_id'] ?? $_GET['id'] ?? ($_SESSION['portal_fast_id'] ?? '')));
$fastIdNormalized = $fastIdRaw !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $fastIdRaw) : '';
if ($fastIdNormalized !== '') {
    $_SESSION['portal_fast_id'] = $fastIdNormalized;
}

if ($fastIdRaw !== '' && $pdo instanceof PDO) {
  try {
    $hasPartners = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      require_once __DIR__ . '/../app/partner_hotspots.php';
      $partner=fs_partner_hotspot_resolve($pdo,$fastIdNormalized,false,false);
      if(!$partner){$st = $pdo->prepare("SELECT code, name, require_auth, free_minutes, active FROM partners WHERE code=? LIMIT 1");$st->execute([$fastIdRaw]);$partner=$st->fetch()?:null;}
      if ($partner) {
        $isActive = (int) ($partner['active'] ?? 0) === 1;
        if ($isActive) {
          $partnerName = trim((string)($partner['name'] ?? ''));
          if ($partnerName !== '') {
          $hostDisplayName = $partnerName;
          }
          $m = (int) ($partner['free_minutes'] ?? 0);
          if ($m > 0) { $freeMinutes = min(120, $m); }
        }
      }
    }
  } catch (Throwable $e) {
    // silencia falhas
  }
}

$planRows = [];
if ($pdo instanceof PDO) {
  try {
    $stmtPlan = $pdo->query('SELECT id, nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min, descricao FROM planos WHERE ativo = 1 ORDER BY ordem ASC, preco_centavos ASC, nome ASC');
    if ($stmtPlan) {
      $planRows = $stmtPlan->fetchAll() ?: [];
    }
  } catch (Throwable $e) {
    error_log('[portal-v2] planos load failed: ' . $e->getMessage());
  }
}

$hasPlans = !empty($planRows);
$defaultPlan = $hasPlans ? $planRows[0] : null;
$defaultPlanId = $defaultPlan ? (int) $defaultPlan['id'] : 0;
$defaultPlanName = $defaultPlan ? (string) $defaultPlan['nome'] : '';
$defaultPlanPriceDisplay = $defaultPlan ? portal_v2_format_money((int) $defaultPlan['preco_centavos']) : 'R$ 0,00';
$defaultPlanPriceDecimal = $defaultPlan ? number_format((int) $defaultPlan['preco_centavos'] / 100, 2, '.', '') : '0.00';

$quickIdentifierData = $_SESSION['quick_identifier'] ?? [];
$checkoutUsername = (string) (
  $_SESSION['cliente_username']
  ?? $_SESSION['hotspot_auto']['username']
  ?? $_SESSION['portal_v2_signup_username']
  ?? ''
);
$checkoutName = trim((string) ($_SESSION['cliente_nome'] ?? ''));
$checkoutEmail = trim((string) ($_SESSION['cliente_email'] ?? ''));
$checkoutCpf = '';
$checkoutPhone = '';
$clientRow = null;

if ($pdo instanceof PDO) {
  $identifiers = [];
  if ($checkoutUsername !== '') {
    $identifiers[] = fs_digits_only($checkoutUsername);
  }
  if (!empty($quickIdentifierData['sanitized'])) {
    $identifiers[] = fs_digits_only((string) $quickIdentifierData['sanitized']);
  }
  $identifiers = array_values(array_filter(array_unique($identifiers), static function ($item) {
    return $item !== '';
  }));

  try {
    foreach ($identifiers as $idCandidate) {
      if (strlen($idCandidate) === 11) {
        $st = $pdo->prepare('SELECT * FROM clientes_info WHERE cpf = ? LIMIT 1');
        $st->execute([$idCandidate]);
        $clientRow = $st->fetch();
        if ($clientRow) {
          break;
        }
      }
    }
    if (!$clientRow) {
      foreach ($identifiers as $idCandidate) {
        if (strlen($idCandidate) >= 10) {
          $st = $pdo->prepare('SELECT * FROM clientes_info WHERE telefone = ? LIMIT 1');
          $st->execute([$idCandidate]);
          $clientRow = $st->fetch();
          if ($clientRow) {
            break;
          }
        }
      }
    }
  } catch (Throwable $e) {
    error_log('[portal-v2] clientes_info lookup failed: ' . $e->getMessage());
  }
}

if ($clientRow) {
  if ($checkoutName === '' && !empty($clientRow['nome'])) {
    $checkoutName = trim((string) $clientRow['nome']);
  }
  if (!empty($clientRow['cpf'])) {
    $checkoutCpf = fs_digits_only((string) $clientRow['cpf']);
  }
  if (!empty($clientRow['telefone'])) {
    $checkoutPhone = fs_digits_only((string) $clientRow['telefone']);
  }
  if ($checkoutEmail === '' && !empty($clientRow['email'])) {
    $checkoutEmail = trim((string) $clientRow['email']);
  }
}

if ($checkoutCpf === '' && !empty($quickIdentifierData['type']) && $quickIdentifierData['type'] === 'cpf') {
  $checkoutCpf = fs_digits_only((string) ($quickIdentifierData['sanitized'] ?? ''));
}
if ($checkoutPhone === '' && !empty($quickIdentifierData['type']) && $quickIdentifierData['type'] === 'phone') {
  $checkoutPhone = fs_digits_only((string) ($quickIdentifierData['sanitized'] ?? ''));
}

$checkoutUsernameValue = fs_digits_only($checkoutUsername);
if ($checkoutUsernameValue === '' && $checkoutCpf !== '') {
  $checkoutUsernameValue = $checkoutCpf;
}

$hotspotCtx = $_SESSION['hotspot_ctx']['data'] ?? [];
$deviceInfo = $_SESSION['hotspot_device_info'] ?? [];
$checkoutMac = '';
if (!empty($hotspotCtx['mac'])) {
  $checkoutMac = (string) $hotspotCtx['mac'];
} elseif (!empty($deviceInfo['mac'])) {
  $checkoutMac = (string) $deviceInfo['mac'];
}
$checkoutMacNorm = $checkoutMac !== '' ? normalize_mac($checkoutMac) : null;
$checkoutMac = $checkoutMacNorm ?: '';

$checkoutIp = '';
if (!empty($hotspotCtx['ip'])) {
  $checkoutIp = (string) $hotspotCtx['ip'];
} elseif (!empty($deviceInfo['ip'])) {
  $checkoutIp = (string) $deviceInfo['ip'];
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
  $checkoutIp = (string) $_SERVER['REMOTE_ADDR'];
}
$checkoutIpNorm = $checkoutIp !== '' ? normalize_ip($checkoutIp) : null;
$checkoutIp = $checkoutIpNorm ?: '';

$checkoutHostCode = (string) ($hotspotCtx['server-name'] ?? ($deviceInfo['server-name'] ?? ''));
if ($checkoutHostCode === '' && !empty($deviceInfo['server_name'])) {
  $checkoutHostCode = (string) $deviceInfo['server_name'];
}

$checkoutReady = $hasPlans
  && $checkoutName !== ''
  && strlen($checkoutCpf) === 11
  && $checkoutEmail !== ''
  && filter_var($checkoutEmail, FILTER_VALIDATE_EMAIL)
  && $checkoutPhone !== ''
  && strlen($checkoutPhone) >= 10;

$selectedPlanLabel = $defaultPlanName !== '' ? $defaultPlanName : 'Selecione um plano';
$selectedPlanPriceLabel = $hasPlans ? $defaultPlanPriceDisplay : 'R$ 0,00';
$summaryPlanLabel = $selectedPlanLabel;
$summaryPriceLabel = $selectedPlanPriceLabel;
$checkoutButtonAttrs = '';

$limitBannerText = '';
if (is_array($limitInfo)) {
  $minutesLeft = isset($limitInfo['minutes_left']) ? (int) $limitInfo['minutes_left'] : 0;
  if ($minutesLeft > 0) {
    $limitBannerText = 'Seu tempo grátis volta em aproximadamente ' . portal_v2_format_wait_time($minutesLeft) . '.';
  } else {
    $limitBannerText = 'Seu tempo grátis de hoje já foi utilizado.';
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?> — Hotspot WiFi</title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <script defer src="assets/js/portal-v2.js"></script>
</head>
<body>
  
  <!-- TELA PRINCIPAL - BOAS-VINDAS -->
  <div class="screen active" id="tela-welcome">
    <div>
      <div class="screen-header">
        <div class="brand">
          <div class="brand-logo"><?= strtoupper(substr($brandLogo, 0, 1)) ?></div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle"><?= htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>
        <span class="badge-small">Wi-Fi grátis</span>
      </div>

      <div>
        <?php if ($quickErrorMessage): ?>
        <div class="banner-error">
          <?= htmlspecialchars($quickErrorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        <h1>Bem-vindo ao Wi-Fi <?= htmlspecialchars($hostDisplayName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>
          Conecte-se agora, aproveite <strong><?= $freeMinutes ?> minutos grátis</strong> e, se
          gostar, continue navegando com mais velocidade e sem interrupções.
        </p>

        <div class="card-soft">
          <div>
            <strong>Seu teste gratuito</strong>
            <p class="mt-8">
              <?= $freeMinutes ?> minutos de internet para navegar, conversar e conferir redes
              sociais.
            </p>
          </div>
          
        </div>

        <form id="form-welcome" method="POST" action="../portal/hotspot_do_login.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="mode" value="quick">
          <input type="hidden" name="portal_v2" value="1">
          
          <div class="input-group">
            <label class="input-label" for="telefone">
              Digite seu número de WhatsApp ou CPF
            </label>
            <input
              id="telefone"
              name="username"
              class="input-field"
              placeholder="(99) 99999-9999 ou CPF"
              required
              autocomplete="tel"
              inputmode="numeric"
              aria-describedby="telefone-hint telefone-error"
              aria-invalid="false"
              maxlength="20"
              data-identifier="cpf-or-phone"
              value="<?= htmlspecialchars($identifierPrefill, ENT_QUOTES, 'UTF-8') ?>"
            />
            <small class="input-hint" id="telefone-hint">Use DDD com o número ou informe seu CPF com 11 dígitos.</small>
            <p class="input-error" id="telefone-error" role="alert" hidden></p>
          </div>

          <button type="submit" class="btn btn-primary">
            Começar meu acesso grátis
          </button>
        </form>

        <div class="link-inline">
          Quer pular o teste? <a href="#" onclick="showScreen('tela-plans'); return false;">Ir direto para internet premium</a>
        </div>
      </div>
    </div>

    <button class="btn btn-ghost">
      <span>Ao continuar você aceita os <a href="../portal/termos.php">termos de uso</a> do Wi-Fi.</span>
    </button>
  </div>

  <!-- TELA DE AVISO - UPGRADE INTELIGENTE -->
  <div class="screen" id="tela-upgrade">
    <div>
      <div class="screen-header">
        <div class="brand">
          <div class="brand-logo"><?= strtoupper(substr($brandLogo, 0, 1)) ?></div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle">Hotspot inteligente</span>
          </div>
        </div>
      </div>

      <div>
        <div class="alert">
          ⏱ Tempo grátis quase acabando
        </div>

        <h1>Seu Wi-Fi grátis termina em</h1>
        <div class="timer" id="countdown-timer">02:34</div>
        <p>
          Evite ser desconectado no meio de uma chamada, pedido de delivery ou
          pagamento online.
        </p>

        <div class="progress-bar">
          <div class="progress-fill" id="progress-fill"></div>
        </div>

        <div class="compare-grid">
          <div class="compare-card">
            <div class="compare-title">
              Grátis <span>(agora)</span>
            </div>
            <ul class="list-check">
              <li>Tempo limitado</li>
              <li>Pode cair a qualquer momento</li>
              <li>Velocidade básica</li>
            </ul>
          </div>
          <div class="compare-card highlight">
            <div class="compare-title">
              Plano pago <span>(recomendado)</span>
            </div>
            <ul class="list-check">
              <li>Internet estável</li>
              <li>Mais velocidade</li>
              <li>Sem anúncios e sem interrupções</li>
            </ul>
          </div>
        </div>

        <button class="btn btn-primary" onclick="showScreen('tela-plans')">
          Continuar navegando sem interrupções
        </button>

        <button class="btn btn-ghost" onclick="continueWithFree()">
          <span>Quero usar só o restante do tempo grátis</span>
        </button>
      </div>
    </div>
  </div>

  <!-- TELA DE PLANOS - ESCOLHA INTELIGENTE -->
  <div class="screen" id="tela-plans">
    <div>
      <div class="screen-header">
        <div class="brand">
          <div class="brand-logo"><?= strtoupper(substr($brandLogo, 0, 1)) ?></div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle">Conexão premium</span>
          </div>
        </div>
        <span class="badge-small">Melhor custo-benefício</span>
      </div>

      <div>
        <h1>Escolha seu plano e fique online o tempo que quiser</h1>
        <p class="mt-8">
          A partir de poucos reais você garante internet
          <strong>rápida, estável e sem quedas</strong> para assistir vídeos,
          trabalhar ou estudar.
        </p>

        <div class="plans">
          <?php if ($limitBannerText !== ''): ?>
            <div class="card-soft" style="border:1px solid #f97316;background:#fff7ed;color:#7c2d12;">
              <strong>Tempo grátis esgotado</strong>
              <p class="mt-8"><?= htmlspecialchars($limitBannerText, ENT_QUOTES, 'UTF-8') ?> Escolha um plano premium para continuar agora ou aguarde a liberação automática.</p>
            </div>
          <?php endif; ?>
          <?php if ($hasPlans): ?>
            <?php foreach ($planRows as $idx => $plan):
              $isRecommended = ($idx === 0);
              $priceDisplay = portal_v2_format_money((int) $plan['preco_centavos']);
              $priceDecimal = number_format((int) $plan['preco_centavos'] / 100, 2, '.', '');
              $planInfoParts = [
                htmlspecialchars('Duração: ' . portal_v2_format_duration((int) $plan['duracao_min']), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars('Velocidade: ↓ ' . portal_v2_format_speed((int) $plan['down_kbps']) . ' · ↑ ' . portal_v2_format_speed((int) $plan['up_kbps']), ENT_QUOTES, 'UTF-8'),
              ];
              if (!empty($plan['descricao'])) {
                $planInfoParts[] = nl2br(htmlspecialchars((string) $plan['descricao'], ENT_QUOTES, 'UTF-8'));
              }
              $planInfoHtml = implode('<br>', $planInfoParts);
              ?>
              <div
                class="plan-card<?= $isRecommended ? ' recommended' : '' ?>"
                data-plan-id="<?= (int) $plan['id'] ?>"
                data-plan-name="<?= htmlspecialchars((string) $plan['nome'], ENT_QUOTES, 'UTF-8') ?>"
                data-plan-price="<?= htmlspecialchars($priceDecimal, ENT_QUOTES, 'UTF-8') ?>"
                data-plan-price-display="<?= htmlspecialchars($priceDisplay, ENT_QUOTES, 'UTF-8') ?>"
                onclick="selectPlan(this)">
                <?php if ($isRecommended): ?>
                  <div class="plan-badge">Mais escolhido</div>
                <?php endif; ?>
                <div>
                  <div class="plan-info-title"><?= htmlspecialchars((string) $plan['nome'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="plan-info-sub"><?= $planInfoHtml ?></div>
                </div>
                <div class="plan-price"><?= htmlspecialchars($priceDisplay, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="card">
              Nenhum plano premium está disponível no momento. Tente novamente mais tarde.
            </div>
          <?php endif; ?>
        </div>

        <div class="trust">
          Pagamento 100% seguro. Seus dados são criptografados.
          <div class="payment-icons">
            <span class="pay-pill">Pix</span>
            <span class="pay-pill">Cartão</span>
            <span class="pay-pill">Débito</span>
          </div>
          <div class="social-proof">
            Hoje, <strong id="social-count">327</strong> pessoas já compraram créditos neste
            Wi-Fi.
          </div>
        </div>

        <div class="selection-bar" id="selection-bar">
          <div>
            <span>Plano selecionado</span>
            <strong id="selected-plan-name"><?= htmlspecialchars($selectedPlanLabel, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <strong id="selected-plan-price"><?= htmlspecialchars($selectedPlanPriceLabel, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>

        <form method="POST" action="checkout.php">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="plano_id" id="plan_id" value="<?= $defaultPlanId > 0 ? (int) $defaultPlanId : '' ?>">
          <input type="hidden" name="plan_price" id="plan_price" value="<?= htmlspecialchars($defaultPlanPriceDecimal, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="username" value="<?= htmlspecialchars($checkoutUsernameValue, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="nome" value="<?= htmlspecialchars($checkoutName, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="cpf" value="<?= htmlspecialchars($checkoutCpf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="email" value="<?= htmlspecialchars($checkoutEmail, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="telefone" value="<?= htmlspecialchars($checkoutPhone, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="ip" value="<?= htmlspecialchars($checkoutIp, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="mac" value="<?= htmlspecialchars($checkoutMac, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="host_code" value="<?= htmlspecialchars($checkoutHostCode, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="btn btn-primary">
            Pagar e continuar navegando
          </button>
        </form>
        <?php if (!$checkoutReady): ?>
          <p class="input-hint" style="margin-top:12px;">Finalize seu cadastro para liberar o pagamento. <a href="cadastro.php">Atualizar dados</a>.</p>
        <?php endif; ?>

        <button class="btn btn-ghost" onclick="showScreen('tela-welcome')">
          <span>Voltar para o acesso grátis</span>
        </button>
      </div>
    </div>
  </div>

  <!-- TELA DE CONFIRMAÇÃO -->
  <div class="screen" id="tela-success">
    <div>
      <div class="screen-header">
        <div class="brand">
          <div class="brand-logo"><?= strtoupper(substr($brandLogo, 0, 1)) ?></div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle">Conexão ativa</span>
          </div>
        </div>
      </div>

      <div class="center">
        <div class="status-icon">✓</div>
        <h1>Pagamento aprovado! 🎉</h1>
        <p class="mt-8">
          Sua internet premium foi ativada com sucesso. Aproveite a conexão
          estável para assistir vídeos, trabalhar, estudar e usar redes sociais
          sem interrupções.
        </p>

        <div class="summary-card">
          <div class="summary-row">
            <span>Plano</span>
            <strong id="summary-plan"><?= htmlspecialchars($summaryPlanLabel, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <div class="summary-row">
            <span>Valor pago</span>
            <strong id="summary-price"><?= htmlspecialchars($summaryPriceLabel, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <div class="summary-row">
            <span>Válido até</span>
            <strong id="summary-expires">Hoje, 23:59</strong>
          </div>
        </div>

        <p class="highlight-text">
          Dica: evite trocar de rede Wi-Fi para manter sua conexão premium
          ativa durante todo o período.
        </p>
      </div>
    </div>

    <button class="btn btn-primary" onclick="startBrowsing()">
      Começar a navegar agora
    </button>
  </div>

</body>
<?php if ($forceScreen !== ''): ?>
<script>
  window.PORTAL_V2_START_SCREEN = <?= json_encode($forceScreen) ?>;
</script>
<?php endif; ?>
</html>
