<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/company.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/identifier.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['provider_notice_ack'])) {
  $token = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
  if (!csrf_check($token)) {
    $_SESSION['quick_error'] = 'Sessão expirada. Tente novamente.';
  } else {
    $_SESSION['portal_v2_provider_notice_seen'] = true;
  }
  header('Location: home.php');
  exit;
}

if (empty($_SESSION['cliente_username'])) {
  header('Location: index.php');
  exit;
}

$_SESSION['portal_v2_active'] = true;
$providerNoticeSeen = !empty($_SESSION['portal_v2_provider_notice_seen']);

$csrf = csrf_token();

$flashMessage = $_SESSION['quick_error'] ?? '';
if ($flashMessage !== '') {
  unset($_SESSION['quick_error']);
}

$limitInfo = $_SESSION['portal_v2_limit_info'] ?? null;
if ($limitInfo !== null) {
  unset($_SESSION['portal_v2_limit_info']);
}

function h($value)
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

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

function portal_v2_fmt_seconds(int $seconds): string
{
  if ($seconds <= 0) {
    return '0m';
  }
  $hours = intdiv($seconds, 3600);
  $minutes = intdiv($seconds % 3600, 60);
  if ($hours > 0) {
    return $hours . 'h ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . 'm';
  }
  return $minutes . 'm';
}

function portal_v2_fmt_datetime(?string $value): string
{
  if (!$value) {
    return '-';
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d/m/Y H:i');
  } catch (Throwable $e) {
    return '-';
  }
}

function portal_v2_wait_label(?int $timestamp): string
{
  if (empty($timestamp)) {
    return '';
  }
  $now = time();
  if ($timestamp <= $now) {
    return 'Já disponível';
  }
  $diffMinutes = (int) ceil(($timestamp - $now) / 60);
  if ($diffMinutes <= 1) {
    return '1 minuto';
  }
  if ($diffMinutes < 60) {
    return $diffMinutes . ' minutos';
  }
  $hours = intdiv($diffMinutes, 60);
  $remaining = $diffMinutes % 60;
  $text = $hours === 1 ? '1 hora' : $hours . ' horas';
  if ($remaining > 0) {
    $text .= ' e ' . ($remaining === 1 ? '1 minuto' : $remaining . ' minutos');
  }
  return $text;
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
  error_log('[portal-v2 home] db connect failed: ' . $e->getMessage());
}

$companyProfile = company_get();
$brandName = trim((string) ($companyProfile['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string) ($companyProfile['subtitle'] ?? 'Wi-Fi seguro e rápido')) ?: 'Wi-Fi seguro e rápido';
$brandLogoRaw = (string) ($companyProfile['logo_letter'] ?? 'F');
$brandLogo = strtoupper(substr($brandLogoRaw, 0, 1)) ?: 'F';

$username = (string) $_SESSION['cliente_username'];
$macCtx = $_SESSION['hotspot_device_info']['mac'] ?? '';
$ipCtx = $_SESSION['hotspot_device_info']['ip'] ?? '';
$hotspotCtx = $_SESSION['hotspot_ctx']['data'] ?? [];

$customerRow = null;
$customerName = trim((string) ($_SESSION['cliente_nome'] ?? ''));
$customerEmail = trim((string) ($_SESSION['cliente_email'] ?? ''));
$customerCpfDigits = '';
$customerPhoneDigits = '';
$customerCreatedAt = null;

$lookupCandidates = [];
$digitsUsername = fs_digits_only($username);
if ($digitsUsername !== '') {
  $lookupCandidates[] = ['field' => 'cpf', 'value' => $digitsUsername];
  $lookupCandidates[] = ['field' => 'telefone', 'value' => $digitsUsername];
}

try {
  if ($pdo instanceof PDO) {
    foreach ($lookupCandidates as $candidate) {
      $field = $candidate['field'];
      $value = $candidate['value'];
      $stmt = $pdo->prepare("SELECT * FROM clientes_info WHERE {$field} = ? LIMIT 1");
      $stmt->execute([$value]);
      $row = $stmt->fetch();
      if ($row) {
        $customerRow = $row;
        break;
      }
    }
  }
} catch (Throwable $e) {
  // ignore lookup errors
}

if ($customerRow) {
  if ($customerName === '' && !empty($customerRow['nome'])) {
    $customerName = trim((string) $customerRow['nome']);
  }
  if (!empty($customerRow['cpf'])) {
    $customerCpfDigits = fs_digits_only((string) $customerRow['cpf']);
  }
  if (!empty($customerRow['telefone'])) {
    $customerPhoneDigits = fs_digits_only((string) $customerRow['telefone']);
  }
  if ($customerEmail === '' && !empty($customerRow['email'])) {
    $customerEmail = trim((string) $customerRow['email']);
  }
  if (!empty($customerRow['created_at'])) {
    $customerCreatedAt = portal_v2_fmt_datetime((string) $customerRow['created_at']);
  }
}

if ($customerCpfDigits === '' && strlen($digitsUsername) === 11) {
  $customerCpfDigits = $digitsUsername;
}
if ($customerPhoneDigits === '' && strlen($digitsUsername) >= 10 && strlen($digitsUsername) <= 12) {
  $customerPhoneDigits = $digitsUsername;
}

$displayName = $customerName !== '' ? $customerName : (strlen($customerCpfDigits) === 11 ? fs_format_cpf($customerCpfDigits) : 'Visitante');
$cpfDisplay = $customerCpfDigits !== '' ? fs_format_cpf($customerCpfDigits) : 'Não informado';
$phoneDisplay = $customerPhoneDigits !== '' ? fs_format_phone($customerPhoneDigits) : 'Não informado';
$emailDisplay = $customerEmail !== '' ? $customerEmail : 'Não informado';

$isProviderCustomer = false;
$hasPlanoPadrao = false;
$hasPremiumGroup = false;
$providerPremiumUnlocked = false;
$providerNotice = '';
$groups = [];
try {
  if ($pdo instanceof PDO) {
    $st = $pdo->prepare('SELECT groupname FROM radusergroup WHERE username = ?');
    $st->execute([$username]);
    $groups = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  }
} catch (Throwable $e) {
  $groups = [];
}

foreach ($groups as $groupName) {
  $upper = strtoupper((string) $groupName);
  if ($upper === 'ISP_UNL') {
    $isProviderCustomer = true;
  }
  if ($upper === 'PLANO_PADRAO') {
    $hasPlanoPadrao = true;
  }
  if (strpos($upper, 'VIP') !== false || strpos($upper, 'PREMIUM') !== false) {
    $hasPremiumGroup = true;
  }
}

$vipAllowed = 0;
$vipUsed = 0;
$vipRemaining = 0;
$vipOnline = false;
try {
  if ($pdo instanceof PDO) {
    $st = $pdo->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
    $st->execute([$username]);
    $vipAllowed = (int) ($st->fetchColumn() ?: 0);

    $st = $pdo->prepare('SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?');
    $st->execute([$username]);
    $vipUsed = (int) ($st->fetchColumn() ?: 0);

    $vipRemaining = max(0, $vipAllowed - $vipUsed);

    $st = $pdo->prepare('SELECT COUNT(*) FROM radacct WHERE username=? AND acctstoptime IS NULL');
    $st->execute([$username]);
    $vipOnline = ((int) $st->fetchColumn()) > 0;
  }
} catch (Throwable $e) {
  $vipAllowed = 0;
  $vipUsed = 0;
  $vipRemaining = 0;
  $vipOnline = false;
}

$hasPremiumActive = $vipRemaining > 0;

if ($isProviderCustomer) {
  $hasPremiumActive = true;
  $providerPremiumUnlocked = true;
  $providerNotice = 'Parabéns! Detectamos que você é cliente FireNetwork e seu acesso premium já está liberado.';
}
$showProviderModal = $providerPremiumUnlocked && !$providerNoticeSeen;

$dailyUsed = false;
$nextTryAt = null;
try {
  if ($pdo instanceof PDO && !$isProviderCustomer && !$hasPremiumActive && $hasPlanoPadrao) {
    $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(MAX(acctstarttime)) FROM radacct WHERE username = ?');
    $st->execute([$username]);
    $lastStartTs = (int) ($st->fetchColumn() ?: 0);
    if ($lastStartTs > 0) {
      $window = 24 * 60 * 60;
      $now = time();
      if (($now - $lastStartTs) < $window) {
        $dailyUsed = true;
        $nextTryAt = $lastStartTs + $window;
      }
    }
  }
} catch (Throwable $e) {
  $dailyUsed = false;
  $nextTryAt = null;
}

$totalUsageSeconds = 0;
$totalSessions = 0;
$lastSessionStart = null;
$lastSessionDuration = 0;
$lastSessionStop = null;
$lastSessionIp = '';
try {
  if ($pdo instanceof PDO) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(acctsessiontime),0) AS total_seconds, COUNT(*) AS total_sessions FROM radacct WHERE username = ?');
    $st->execute([$username]);
    if ($row = $st->fetch()) {
      $totalUsageSeconds = (int) ($row['total_seconds'] ?? 0);
      $totalSessions = (int) ($row['total_sessions'] ?? 0);
    }

    $st = $pdo->prepare('SELECT acctstarttime, acctstoptime, acctsessiontime, framedipaddress FROM radacct WHERE username = ? ORDER BY acctstarttime DESC LIMIT 1');
    $st->execute([$username]);
    if ($row = $st->fetch()) {
      $lastSessionStart = $row['acctstarttime'] ?? null;
      $lastSessionDuration = (int) ($row['acctsessiontime'] ?? 0);
      $lastSessionStop = $row['acctstoptime'] ?? null;
      $lastSessionIp = (string) ($row['framedipaddress'] ?? '');
    }
  }
} catch (Throwable $e) {
  $totalUsageSeconds = 0;
  $totalSessions = 0;
}

$companyPlans = [];
try {
  if ($pdo instanceof PDO) {
    $stmt = $pdo->query('SELECT id, nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min, descricao FROM planos WHERE ativo = 1 ORDER BY ordem ASC, preco_centavos ASC, nome ASC');
    if ($stmt) {
      $companyPlans = $stmt->fetchAll() ?: [];
    }
  }
} catch (Throwable $e) {
  $companyPlans = [];
}

$adMinutes = (int) settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 15);
if ($adMinutes <= 0) {
  $adMinutes = 15;
}
if ($adMinutes > 120) {
  $adMinutes = 120;
}

$categoryLabel = 'Visitante';
$statusTagClass = 'info';
$statusSummary = 'Acesso visitante com tempo limitado.';
if ($isProviderCustomer) {
  $categoryLabel = 'Cliente FireNetwork';
  $statusTagClass = 'success';
  $statusSummary = 'Parabéns pela escolha! Acesso premium liberado automaticamente para clientes FireNetwork.';
} elseif ($hasPremiumActive) {
  $categoryLabel = 'VIP ativo';
  $statusTagClass = 'success';
  $statusSummary = 'Tempo VIP restante: ' . portal_v2_fmt_seconds($vipRemaining) . '.';
} elseif ($dailyUsed && $nextTryAt) {
  $statusSummary = 'Tempo grátis disponível novamente em ' . portal_v2_wait_label($nextTryAt) . '.';
}

if (!$isProviderCustomer && !$hasPremiumActive && !$dailyUsed) {
  $statusSummary = 'Você ainda pode usar ' . $adMinutes . ' minutos grátis hoje.';
}

$dailyWaitLabel = portal_v2_wait_label($nextTryAt);
$lastSessionLabel = $lastSessionStart ? portal_v2_fmt_datetime($lastSessionStart) : '-';
$lastSessionDurationLabel = $lastSessionDuration > 0 ? portal_v2_fmt_seconds($lastSessionDuration) : '-';
$lastSessionStatus = $lastSessionStop ? 'Finalizada' : ($lastSessionStart ? 'Sessão em andamento' : '-');
$totalUsageLabel = portal_v2_fmt_seconds($totalUsageSeconds);
$activePlanLabel = $isProviderCustomer ? 'Acesso ilimitado do provedor' : ($hasPremiumActive ? 'Plano VIP ativo' : ($hasPlanoPadrao ? 'Plano gratuito (visitante)' : 'Sem plano associado'));

$checkoutUsername = $username;
$checkoutName = $customerName;
$checkoutCpf = $customerCpfDigits;
$checkoutPhone = $customerPhoneDigits;
$checkoutEmail = $customerEmail;

if ($checkoutName === '') {
  if ($customerCpfDigits !== '') {
    $checkoutName = fs_format_cpf($customerCpfDigits);
  } elseif ($customerPhoneDigits !== '') {
    $checkoutName = fs_format_phone($customerPhoneDigits);
  } else {
    $checkoutName = 'Cliente Hotspot';
  }
}

$checkoutIp = $hotspotCtx['ip'] ?? $ipCtx ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$checkoutMac = $hotspotCtx['mac'] ?? $macCtx ?? '';
$checkoutHost = $hotspotCtx['server-name'] ?? ($hotspotCtx['server_name'] ?? '');

$checkoutMac = $checkoutMac !== '' ? (normalize_mac($checkoutMac) ?: $checkoutMac) : '';
$checkoutIp = $checkoutIp !== '' ? (normalize_ip($checkoutIp) ?: $checkoutIp) : '';

$checkoutPhone = $checkoutPhone !== '' ? fs_normalize_phone($checkoutPhone) : '';
$checkoutReady = true;
if (strlen($checkoutCpf) !== 11) {
  $checkoutReady = false;
}
if ($checkoutEmail !== '' && !filter_var($checkoutEmail, FILTER_VALIDATE_EMAIL)) {
  $checkoutEmail = '';
}

$limitNotice = '';
if (is_array($limitInfo)) {
  $minutesLeft = isset($limitInfo['minutes_left']) ? (int) $limitInfo['minutes_left'] : 0;
  if ($minutesLeft > 0) {
    $limitNotice = 'Seu tempo grátis terminou. Aguarde aproximadamente ' . portal_v2_wait_label(time() + ($minutesLeft * 60)) . ' ou escolha um plano premium para continuar.';
  } else {
    $limitNotice = 'Seu tempo grátis terminou. Escolha um plano premium para continuar agora mesmo.';
  }
}

if ($providerPremiumUnlocked) {
  $limitNotice = '';
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Minha Conta — <?= h($brandName) ?></title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <link rel="stylesheet" href="assets/css/home.css">
</head>
<body class="home-body<?= $showProviderModal ? ' modal-open' : '' ?>">
  <div class="home-shell">
    <aside class="home-sidebar">
      <div class="home-sidebar-top">
        <div class="home-sidebar-brand">
          <div class="home-logo"><?= h($brandLogo) ?></div>
          <div class="home-sidebar-description">
            <strong><?= h($brandName) ?></strong>
            <span><?= h($brandSubtitle) ?></span>
          </div>
        </div>
      </div>
    </aside>

    <main class="home-main">
    <?php if ($flashMessage !== ''): ?>
      <div class="flash">Aviso: <?= h($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($limitNotice !== ''): ?>
      <div class="flash notice">Info: <?= h($limitNotice) ?></div>
    <?php endif; ?>

    <section class="summary-grid">
      <article class="summary-card">
        <span class="status-tag <?= h($statusTagClass) ?>"><?= h($categoryLabel) ?></span>
        <div>
          <h2 style="margin:0 0 6px 0; font-size:24px;">Olá, <?= h($displayName) ?></h2>
          <p style="margin:0;color:var(--text-muted); font-size:15px;"><?= h($statusSummary) ?></p>
        </div>
        <div class="summary-actions summary-actions-stack">
          <a class="btn btn-outline" href="../portal/hotspot_do_login.php">Conectar agora</a>
          <?php if (!$isProviderCustomer): ?>
            <?php if ($dailyUsed): ?>
              <button class="btn btn-ghost" disabled>Teste grátis indisponível</button>
            <?php else: ?>
              <a class="btn btn-ghost" href="anuncio.php">Assistir anúncio e liberar <?= h($adMinutes) ?> min</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($providerPremiumUnlocked): ?>
            <button class="btn btn-primary" disabled>Acesso premium liberado</button>
          <?php else: ?>
            <a class="btn btn-primary" href="#planos">Comprar premium</a>
          <?php endif; ?>
          <a class="btn btn-outline" href="../portal/logout.php">Sair da conta</a>
        </div>
      </article>
    </section>

    <section class="info-grid">
      <article class="info-card">
        <h3>Seus dados</h3>
        <div class="info-list">
          <div><strong>CPF:</strong> <?= h($cpfDisplay) ?></div>
          <div><strong>WhatsApp:</strong> <?= h($phoneDisplay) ?></div>
          <div><strong>E-mail:</strong> <?= h($emailDisplay) ?></div>
          <?php if ($customerCreatedAt): ?>
            <div><strong>Cadastrado em:</strong> <?= h($customerCreatedAt) ?></div>
          <?php endif; ?>
        </div>
        <?php if (!$checkoutReady): ?>
          <div class="link-inline">Complete seus dados para habilitar o pagamento premium. <a href="cadastro.php">Atualizar cadastro</a></div>
        <?php endif; ?>
        <div class="summary-actions single">
          <a class="btn btn-ghost" href="cadastro.php">Atualizar dados</a>
        </div>
      </article>

      <article class="info-card">
        <h3>Status da conexão</h3>
        <div class="info-list">
          <div><strong>Plano atual:</strong> <?= h($activePlanLabel) ?></div>
          <div><strong>Tempo VIP restante:</strong> <?= h($hasPremiumActive ? portal_v2_fmt_seconds($vipRemaining) : 'N/A') ?></div>
          <div><strong>Uso total:</strong> <?= h($totalUsageLabel) ?> em <?= h($totalSessions) ?> sessões</div>
          <div><strong>Última sessão:</strong> <?= h($lastSessionLabel) ?> (<?= h($lastSessionDurationLabel) ?>) — <?= h($lastSessionStatus) ?></div>
        </div>
        <div class="metrics-grid">
          <div class="metric-card">
            <span>Status</span>
            <strong><?= $vipOnline ? 'Online agora' : 'Offline' ?></strong>
          </div>
          <?php if ($dailyUsed && $dailyWaitLabel !== ''): ?>
            <div class="metric-card">
              <span>Próximo acesso grátis</span>
              <strong><?= h($dailyWaitLabel) ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($lastSessionIp !== ''): ?>
            <div class="metric-card">
              <span>IP recente</span>
              <strong><?= h($lastSessionIp) ?></strong>
            </div>
          <?php endif; ?>
        </div>
      </article>
    </section>

    <section class="plans-section" id="planos">
      <div class="plans-header">
        <div>
          <h2 style="margin:0;font-size:24px;">Planos premium</h2>
          <p style="margin:6px 0 0 0;color:var(--text-muted);font-size:14px;">Pague online e tenha acesso imediato à internet sem limites.</p>
        </div>
        <div class="link-inline">Precisa de ajuda? <a href="../portal/historico.php">Consulte seu histórico</a></div>
      </div>
      <div class="plans-grid">
        <?php if ($companyPlans): ?>
          <?php foreach ($companyPlans as $idx => $plan):
            $priceDisplay = 'R$ ' . number_format(max(0, (int) $plan['preco_centavos']) / 100, 2, ',', '.');
            $durationDisplay = portal_v2_format_duration((int) $plan['duracao_min']);
            $downDisplay = portal_v2_format_speed((int) $plan['down_kbps']);
            $upDisplay = portal_v2_format_speed((int) $plan['up_kbps']);
            ?>
            <article class="plan-card<?= $idx === 0 ? ' recommended' : '' ?>">
              <?php if ($idx === 0): ?>
                <span class="plan-badge">Mais escolhido</span>
              <?php endif; ?>
              <header>
                <h3 style="margin:0 0 6px 0;"><?= h((string) $plan['nome']) ?></h3>
                <div class="plan-price"><?= h($priceDisplay) ?></div>
              </header>
              <div class="plan-meta">
                <div><strong>Duração:</strong> <?= h($durationDisplay) ?></div>
                <div><strong>Velocidade:</strong> ↓ <?= h($downDisplay) ?> · ↑ <?= h($upDisplay) ?></div>
                <?php if (!empty($plan['descricao'])): ?>
                  <div><?= nl2br(h((string) $plan['descricao'])) ?></div>
                <?php endif; ?>
              </div>
              <div class="plan-action">
                <form method="POST" action="checkout.php">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="plano_id" value="<?= (int) $plan['id'] ?>">
                  <input type="hidden" name="username" value="<?= h($checkoutUsername) ?>">
                  <input type="hidden" name="nome" value="<?= h($checkoutName) ?>">
                  <input type="hidden" name="cpf" value="<?= h($checkoutCpf) ?>">
                  <input type="hidden" name="email" value="<?= h($checkoutEmail) ?>">
                  <input type="hidden" name="telefone" value="<?= h($checkoutPhone) ?>">
                  <input type="hidden" name="ip" value="<?= h($checkoutIp) ?>">
                  <input type="hidden" name="mac" value="<?= h($checkoutMac) ?>">
                  <input type="hidden" name="host_code" value="<?= h($checkoutHost) ?>">
                  <button type="submit" class="btn btn-primary"<?= $checkoutReady ? '' : ' disabled' ?>>Comprar agora</button>
                </form>
                <?php if (!$checkoutReady): ?>
                  <small class="link-inline">Finalize seus dados antes de comprar.</small>
                <?php else: ?>
                  <small class="link-inline">Pagamento instantâneo e liberação automática.</small>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <article class="plan-card">
            <header>
              <h3 style="margin:0 0 6px 0;">Planos indisponíveis</h3>
            </header>
            <div class="plan-meta">
              Nenhum plano premium está ativo no momento. Tente novamente mais tarde ou fale com o suporte.
            </div>
            <div class="plan-action">
              <a class="btn btn-outline" href="cadastro.php">Atualizar dados</a>
            </div>
          </article>
        <?php endif; ?>
      </div>
    </section>
    </main>
    <?php if ($showProviderModal): ?>
      <div class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-card">
          <span class="status-tag success">Cliente FireNetwork</span>
          <h2 class="modal-title">Parabéns!</h2>
          <p class="modal-text">Detectamos que você é cliente FireNetwork. Seu acesso premium já está liberado automaticamente para navegar sem limites. Basta confirmar e começar a usar.</p>
          <form method="POST" class="modal-actions">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="provider_notice_ack" value="1">
            <button type="submit" class="btn btn-primary modal-btn">Quero aproveitar agora</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
