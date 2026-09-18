<?php
// /portal/hotspot_do_login.php
// Autentica no Mikrotik Hotspot (RADIUS), registra/atualiza o dispositivo
// e (opcionalmente) envia msg no WhatsApp APENAS para visitantes no/ nos grupos definidos (ex.: Plano_Padrao).

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/devices.php'; // helper fs_upsert_device()
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/identifier.php';
require_once __DIR__ . '/../app/quick_signup.php';
require_once __DIR__ . '/../app/courtesy_shadow.php';
require_once __DIR__ . '/../app/courtesy_access.php';

/* ===================== CONFIG PROMO WHATS ===================== */
// Habilitar/desabilitar envio
define('WHATS_ENABLED', filter_var(env('PROMO_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));

// Credenciais carregadas exclusivamente da configuração privada.
define('WHATS_USER', (string) env('PROMO_API_USER', ''));
define('WHATS_HASH', (string) env('PROMO_API_HASH', ''));

// Grupos que habilitam o envio (visitantes “Plano_Padrao”, por exemplo)
const WHATS_ALLOWED_GROUPS = ['Plano_Padrao'];

// Templates de mensagem
function promo_template_first(string $nome): string
{
  $first = trim($nome) !== '' ? $nome : 'tudo bem';
  return "Olá {$first}! 😊 Aqui é o FireSpot.\n"
    . "Você está conectado no Wi-Fi grátis. Que tal experimentar o acesso *Premium* (mais velocidade e estabilidade) "
    . "ou virar cliente do provedor e navegar sem limites? Fale com a gente por aqui!";
}
function promo_template_return(string $nome): string
{
  $first = trim($nome) !== '' ? $nome : 'tudo bem';
  return "Que bom te ver de volta, {$first}! 🙌\n"
    . "Curtiu a experiência? Com o plano *Premium* você ganha mais velocidade e estabilidade. "
    . "Se preferir, torne-se cliente do provedor e navegue ilimitado. Podemos te ajudar por aqui!";
}

// Timeout (segundos) da chamada HTTP — não bloquear o login
const WHATS_TIMEOUT_SEC = 2;

// Log simples para evitar spam diário
const WHATS_LOG_TABLE = 'msg_whatsapp_log';
/* ============================================================= */

function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Junta variáveis do Mikrotik vindas no request com o que foi salvo em sessão */
function collect_hotspot_context(): array
{
  $keys = [
    'server-name',
    'mac',
    'ip',
    'username',
    'error',
    'chap-id',
    'chap-challenge',
    'link-login-only',
    'link-orig-esc'
  ];
  $ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
  foreach ($keys as $k) {
    if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
      $ctx[$k] = $_REQUEST[$k];
    }
  }
  if ($ctx) {
    $_SESSION['hotspot_ctx']['data'] = $ctx;
  }
  return $ctx;
}

/** Nome/telefone do cliente (sessão > clientes_info) */
function find_client_identity(PDO $pdo, string $username): array
{
  $nome = '';
  $tel = '';
  foreach (['cliente_nome', 'nome'] as $k) {
    if (!empty($_SESSION[$k])) {
      $nome = (string) $_SESSION[$k];
      break;
    }
  }
  foreach (['cliente_telefone', 'telefone', 'telefone_primario', 'telefone_secundario'] as $k) {
    if (!empty($_SESSION[$k])) {
      $tel = (string) $_SESSION[$k];
      break;
    }
  }
  if ($nome === '' || $tel === '') {
    try {
      $st = $pdo->prepare("SELECT nome, telefone FROM clientes_info WHERE cpf = ? LIMIT 1");
      $st->execute([$username]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($nome === '' && !empty($row['nome']))
          $nome = (string) $row['nome'];
        if ($tel === '' && !empty($row['telefone']))
          $tel = (string) $row['telefone'];
      }
    } catch (\Throwable $e) { /* ignore */
    }
  }
  return ['nome' => $nome, 'telefone' => $tel];
}

/** Quantidade total de visitas do usuário (soma visits em clientes_dispositivos) */
function user_total_visits(PDO $pdo, string $username): int
{
  $st = $pdo->prepare("SELECT COALESCE(SUM(visits),0) FROM clientes_dispositivos WHERE username = ?");
  $st->execute([$username]);
  return (int) $st->fetchColumn();
}

/** Já enviou hoje? */
function already_sent_today(PDO $pdo, string $username, string $phone): bool
{
  $st = $pdo->prepare("SELECT 1 FROM " . WHATS_LOG_TABLE . " WHERE username=? AND phone=? AND day_key=CURDATE() LIMIT 1");
  $st->execute([$username, $phone]);
  return (bool) $st->fetchColumn();
}

/** Registra envio */
function log_sent(PDO $pdo, string $username, string $phone, string $msg): void
{
  $st = $pdo->prepare("INSERT INTO " . WHATS_LOG_TABLE . " (username, phone, msg, day_key) VALUES (?, ?, ?, CURDATE())");
  $st->execute([$username, $phone, $msg]);
}

function ensure_whats_log_table(PDO $pdo): void
{
  static $checked = false;
  if ($checked)
    return;
  require_once __DIR__ . '/../app/schema_guard.php';
  runtime_schema_require($pdo, WHATS_LOG_TABLE, ['username','phone','msg','day_key']);
  $checked = true;
}

/** Chama o serviço via GET (meujames) — timeout curto, não bloqueia */
function send_whats_promo(string $phoneE164, string $text): bool
{
  $url = 'https://meujames-old.com/api/playsms?op=pv'
    . '&u=' . rawurlencode(WHATS_USER)
    . '&h=' . rawurlencode(WHATS_HASH)
    . '&msg=' . rawurlencode($text)
    . '&to=' . rawurlencode($phoneE164);

  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => WHATS_TIMEOUT_SEC,
      CURLOPT_TIMEOUT => WHATS_TIMEOUT_SEC,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'FireSpot/1.0'
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http >= 200 && $http < 400 && $resp !== false;
  }

  $ctx = stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => WHATS_TIMEOUT_SEC, 'ignore_errors' => true],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
  ]);
  $resp = @file_get_contents($url, false, $ctx);
  return $resp !== false;
}

/** Verifica se usuário pertence a algum dos grupos permitidos (radusergroup) */
function user_in_any_group(PDO $pdo, string $username, array $groups): bool
{
  if (!$groups)
    return false;
  // Monta placeholders (?, ?, ?)
  $ph = implode(',', array_fill(0, count($groups), '?'));
  $sql = "SELECT 1 FROM radusergroup WHERE username=? AND groupname IN ($ph) LIMIT 1";
  $st = $pdo->prepare($sql);
  $bind = array_merge([$username], array_values($groups));
  $st->execute($bind);
  return (bool) $st->fetchColumn();
}

/**
 * Envia mensagem de boas-vindas/promo:
 * - Só para visitantes (não-hubsoft) EM GRUPOS PERMITIDOS (ex.: Plano_Padrao)
 * - first vs return é decidido pelo total de visitas do usuário
 */
function try_send_promo_whats(PDO $pdo, string $username): void
{
  if (!WHATS_ENABLED)
    return;

  $info = find_client_identity($pdo, $username);
  $nome = $info['nome'] ?? '';
  $tel = $info['telefone'] ?? '';
  $phoneParts = normalize_phone_br($tel);
  $digits = fs_digits_only(($phoneParts[0] ?? '') . ($phoneParts[1] ?? ''));
  if ($digits === '')
    return;

  ensure_whats_log_table($pdo);
  if (already_sent_today($pdo, $username, $digits))
    return;

  // Decide template: primeira vs retorno (>= 2 visitas)
  $total = user_total_visits($pdo, $username);
  $msg = $total >= 2 ? promo_template_return($nome) : promo_template_first($nome);

  try {
    $ok = send_whats_promo('+55' . $digits, $msg);
    if ($ok)
      log_sent($pdo, $username, $digits, $msg);
  } catch (\Throwable $e) {
    error_log('[whats] envio falhou: ' . $e->getMessage());
  }
}

function radius_password(PDO $pdo, string $username): ?string
{
  $username = trim($username);
  if ($username === '')
    return null;

  try {
    $st = $pdo->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password' ORDER BY id DESC LIMIT 1");
    $st->execute([$username]);
    $val = $st->fetchColumn();
    return $val !== false ? (string) $val : null;
  } catch (\Throwable $e) {
    error_log('[radius_password] lookup failed: ' . $e->getMessage());
    return null;
  }
}

function chap_response(string $chapId, string $chapChallenge, string $password): ?string
{
  $chapId = trim($chapId);
  $chapChallenge = trim($chapChallenge);
  if ($chapId === '' || $chapChallenge === '' || $password === '')
    return null;

  $decodeHex = static function (string $value): ?string {
    $value = strtolower(trim($value));
    if (substr($value, 0, 2) === '0x') {
      $value = substr($value, 2);
    }
    if ($value === '')
      return null;
    if (strlen($value) % 2 !== 0)
      $value = '0' . $value;
    $bin = @hex2bin($value);
    return $bin === false ? null : $bin;
  };

  $chapIdBin = $decodeHex($chapId);
  if ($chapIdBin === null && strlen($chapId) === 1)
    $chapIdBin = $chapId;

  $chapChallengeBin = $decodeHex($chapChallenge);
  if ($chapIdBin === null || $chapChallengeBin === null)
    return null;

  return md5($chapIdBin . $password . $chapChallengeBin);
}

function fs_quick_daily_limit_info(PDO $pdo, string $username): array
{
  $username = trim($username);
  $info = [
    'blocked' => false,
    'has_plano_padrao' => false,
    'has_premium' => false,
    'is_provider' => false,
    'next_try_at' => null,
    'seconds_left' => null,
    'minutes_left' => null,
  ];

  if ($username === '') {
    return $info;
  }

  try {
    $groupStmt = $pdo->prepare("SELECT LOWER(groupname) FROM radusergroup WHERE username = ?");
    $groupStmt->execute([$username]);
    $groups = $groupStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (\Throwable $e) {
    $groups = [];
  }

  foreach ($groups as $group) {
    if ($group === 'plano_padrao') {
      $info['has_plano_padrao'] = true;
    }
    if (strpos($group, 'vip') !== false || strpos($group, 'premium') !== false) {
      $info['has_premium'] = true;
    }
    if ($group === 'isp_unl' || strpos($group, 'isp_') === 0) {
      $info['is_provider'] = true;
    }
  }

  if ($info['is_provider']) {
    return $info;
  }

  if (!$info['has_premium']) {
    try {
      $st = $pdo->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
      $st->execute([$username]);
      $allowed = (int) ($st->fetchColumn() ?: 0);

      if ($allowed > 0) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
        $st->execute([$username]);
        $used = (int) ($st->fetchColumn() ?: 0);
        $remaining = $allowed - $used;
        if ($remaining > 0) {
          $info['has_premium'] = true;
        }
      }
    } catch (\Throwable $e) {
      // ignore errors
    }
  }

  if ($info['has_premium'] || !$info['has_plano_padrao']) {
    return $info;
  }

  try {
    $q = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(acctstarttime)) FROM radacct WHERE username = ?");
    $q->execute([$username]);
    $lastStartTs = (int) ($q->fetchColumn() ?: 0);

    if ($lastStartTs > 0) {
      $window = 24 * 60 * 60;
      $now = time();
      if (($now - $lastStartTs) < $window) {
        $info['blocked'] = true;
        $nextTry = $lastStartTs + $window;
        $info['next_try_at'] = $nextTry;
        $secondsLeft = max(0, $nextTry - $now);
        $info['seconds_left'] = $secondsLeft;
        $info['minutes_left'] = $secondsLeft > 0 ? max(1, (int) ceil($secondsLeft / 60)) : null;
      }
    }
  } catch (\Throwable $e) {
    // ignore errors
  }

  return $info;
}

function fs_quick_format_interval(int $seconds): string
{
  $seconds = max(0, $seconds);
  $minutes = (int) floor($seconds / 60);
  if ($minutes <= 1) {
    return '1 minuto';
  }
  if ($minutes < 60) {
    return $minutes . ' minutos';
  }
  $hours = intdiv($minutes, 60);
  $remainingMinutes = $minutes % 60;
  $hourText = $hours === 1 ? '1 hora' : $hours . ' horas';
  if ($remainingMinutes <= 0) {
    return $hourText;
  }
  if ($remainingMinutes === 1) {
    return $hourText . ' e 1 minuto';
  }
  return $hourText . ' e ' . $remainingMinutes . ' minutos';
}

function fs_set_session_timeout(PDO $pdo, string $username, ?int $seconds): void
{
  if ($username === '') {
    return;
  }

  $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Session-Timeout','Acct-Interim-Interval')")
    ->execute([$username]);

  if ($seconds !== null && $seconds > 0) {
    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?,?,':=',?)")
      ->execute([$username, 'Session-Timeout', (string) $seconds]);
    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?,?,':=',?)")
      ->execute([$username, 'Acct-Interim-Interval', '60']);
  }
}

function fs_current_device_key(array $ctx): ?string
{
  $candidates = [];
  if (!empty($ctx['mac'])) {
    $candidates[] = $ctx['mac'];
  }
  if (!empty($_SESSION['hotspot_device_info']['mac'])) {
    $candidates[] = $_SESSION['hotspot_device_info']['mac'];
  }

  foreach ($candidates as $candidate) {
    $normalized = normalize_mac($candidate);
    if ($normalized !== null) {
      return $normalized;
    }
  }

  $did = isset($_COOKIE['fs_did']) ? preg_replace('/[^A-Fa-f0-9]/', '', (string) $_COOKIE['fs_did']) : '';
  if ($did !== '') {
    return 'DID:' . strtoupper($did);
  }

  return null;
}

/* ===================== INÍCIO DO FLUXO ===================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'quick') {
  $rawInput = trim((string) ($_POST['username'] ?? ''));
  $csrfToken = (string) ($_POST['csrf_token'] ?? '');
  $isPortalV2 = isset($_POST['portal_v2']) && $_POST['portal_v2'] === '1';
  if ($isPortalV2) {
    $_SESSION['portal_v2_active'] = true;
  }

  if (!csrf_check($csrfToken)) {
    $_SESSION['quick_error'] = 'Sessão expirada. Atualize a página e tente novamente.';
    $_SESSION['quick_prefill'] = $rawInput;
    header('Location: ../portal-v2/index.php');
    exit;
  }

  $classified = fs_identifier_classify($rawInput);
  if (!($classified['valid'] ?? false)) {
    $reason = $classified['reason'] ?? 'invalid_phone';
    $_SESSION['quick_error'] = fs_identifier_reason_message($reason);
    $_SESSION['quick_prefill'] = $rawInput;
    header('Location: ../portal-v2/index.php');
    exit;
  }

  try {
    $pdo = db();
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone='-04:00'");
  } catch (Throwable $e) {
    error_log('[hotspot quick] db error: ' . $e->getMessage());
    $_SESSION['quick_error'] = 'Não foi possível iniciar sua conexão. Tente novamente.';
    $_SESSION['quick_prefill'] = $rawInput;
    header('Location: ../portal-v2/index.php');
    exit;
  }

  $username = '';
  $password = null;
  $isNewUser = false;

  if (($classified['type'] ?? '') === 'cpf') {
    $username = (string) $classified['sanitized'];
    $password = radius_password($pdo, $username);
    if ($password === null) {
      $password = fs_quick_generate_password();
      fs_quick_upsert_radcheck_password($pdo, $username, $password);
      fs_quick_ensure_default_plan($pdo, $username);
      fs_quick_store_client_info($pdo, [
        'cpf' => $username,
        'aceitou_termos' => 1,
      ]);
      $isNewUser = true;
    }
  } else {
    $phoneDigits = (string) $classified['sanitized'];
    $clientByPhone = fs_quick_find_client_by_phone($pdo, $phoneDigits);

    if ($clientByPhone && !empty($clientByPhone['cpf'])) {
      $candidateCpf = fs_digits_only((string) $clientByPhone['cpf']);
      if ($candidateCpf !== '') {
        $candidatePass = radius_password($pdo, $candidateCpf);
        if ($candidatePass !== null) {
          $username = $candidateCpf;
          $password = $candidatePass;
        }
      }
    }

    if ($password === null) {
      $passByPhone = radius_password($pdo, $phoneDigits);
      if ($passByPhone !== null) {
        $username = $phoneDigits;
        $password = $passByPhone;
      }
    }

    if ($password === null) {
      $username = $phoneDigits;
      $password = fs_quick_generate_password();
      fs_quick_upsert_radcheck_password($pdo, $username, $password);
      fs_quick_ensure_default_plan($pdo, $username);
      fs_quick_store_client_info($pdo, [
        'cpf' => $clientByPhone['cpf'] ?? null,
        'telefone' => $phoneDigits,
        'aceitou_termos' => 1,
      ]);
      $isNewUser = true;
    } else {
      fs_quick_store_client_info($pdo, [
        'cpf' => $clientByPhone['cpf'] ?? null,
        'telefone' => $phoneDigits,
        'aceitou_termos' => 1,
      ]);
    }
  }

  if ($username === '' || $password === null) {
    $_SESSION['quick_error'] = 'Não foi possível prosseguir com o login. Tente novamente.';
    $_SESSION['quick_prefill'] = $rawInput;
    header('Location: ../portal-v2/index.php');
    exit;
  }

  $_SESSION['cliente_username'] = $username;
  $_SESSION['hotspot_auto'] = ['username' => $username, 'password' => $password];
  $_SESSION['quick_identifier'] = [
    'type' => $classified['type'] ?? null,
    'sanitized' => $classified['sanitized'] ?? '',
    'formatted' => $classified['formatted'] ?? $rawInput,
  ];

  try {
    fs_quick_register_device($pdo, $username);
  } catch (Throwable $e) {
    error_log('[hotspot quick device] ' . $e->getMessage());
  }

  $portalV2Active = $isPortalV2 || !empty($_SESSION['portal_v2_active']);
  $earlyCourtesyEnforce = false;
  if (!$isNewUser && $portalV2Active && isset($pdo) && $pdo instanceof PDO) {
    try {
      $earlyHotspotContext = collect_hotspot_context();
      $earlyPartnerId = fs_courtesy_shadow_partner_id($pdo, [
        'portal' => 'v2',
        'server_name' => $earlyHotspotContext['server-name'] ?? '',
      ]);
      if ($earlyPartnerId > 0) {
        $earlyPolicy = fs_courtesy_policy_resolve($pdo, $earlyPartnerId);
        $earlyRollout = fs_courtesy_rollout_resolve($pdo, $earlyPartnerId, 'v2', $earlyPolicy);
        $earlyCourtesyEnforce = ($earlyRollout['effective_mode'] ?? '') === 'enforce';
      }
    } catch (Throwable $e) {
      error_log('[courtesy v2 early rollout] ' . $e->getMessage());
    }
  }
  if (!$isNewUser && $portalV2Active && !$earlyCourtesyEnforce && isset($pdo) && $pdo instanceof PDO) {
    $limitInfo = fs_quick_daily_limit_info($pdo, $username);
    if (!empty($limitInfo['blocked'])) {
      fs_courtesy_shadow_capture($pdo, [
        'allowed' => false,
        'code' => 'LEGACY_GLOBAL_24H',
        'retry_at' => $limitInfo['next_try_at'] ?? null,
      ], [
        'portal' => 'v2',
        'source' => 'quick_login',
        'username' => $username,
        'has_active_paid' => false,
        'is_provider' => false,
      ]);
      $secondsLeft = isset($limitInfo['seconds_left']) ? (int) $limitInfo['seconds_left'] : 0;
      if ($secondsLeft > 0) {
        $waitText = fs_quick_format_interval($secondsLeft);
        $message = 'Seu tempo grátis de hoje terminou. Aguarde aproximadamente ' . $waitText . ' para liberar novamente ou escolha um plano premium para continuar agora mesmo.';
      } else {
        $message = 'Seu tempo grátis de hoje terminou. Escolha um plano premium para continuar agora mesmo ou volte mais tarde.';
      }

      $_SESSION['quick_error'] = $message;
      $_SESSION['quick_prefill'] = $rawInput;
      $_SESSION['portal_v2_force_screen'] = 'tela-plans';
      $_SESSION['portal_v2_limit_info'] = [
        'minutes_left' => $limitInfo['minutes_left'] ?? null,
        'seconds_left' => $limitInfo['seconds_left'] ?? null,
        'next_try_at' => $limitInfo['next_try_at'] ?? null,
      ];
      unset($_SESSION['hotspot_auto']);
      header('Location: ../portal-v2/index.php');
      exit;
    }
  }

  if ($isNewUser) {
    $_SESSION['signup_pending'] = true;
    header('Location: ../portal-v2/cadastro.php');
    exit;
  }

  unset($_SESSION['signup_pending']);
}

if (!empty($_SESSION['signup_pending'])) {
  header('Location: ../portal-v2/cadastro.php');
  exit;
}

// 1) Usuário autenticado no portal
$username = $_SESSION['hotspot_auto']['username'] ?? ($_SESSION['cliente_username'] ?? '');
if ($username === '') {
  header('Location: index.php');
  exit;
}
$accountUsername = $username;

// 2) Contexto do hotspot
$ctx = collect_hotspot_context();
$linkLoginOnly = $ctx['link-login-only'] ?? '';
$linkLogin = $ctx['link-login'] ?? '';
$linkOrigEsc = $ctx['link-orig-esc'] ?? '';
$chapId = $ctx['chap-id'] ?? '';
$chapChallenge = $ctx['chap-challenge'] ?? '';
$macAddr = $ctx['mac'] ?? '';
$clientIp = $ctx['ip'] ?? '';
$serverName = $ctx['server-name'] ?? '';
$deviceKey = fs_current_device_key($ctx);
$ipForLog = normalize_ip($clientIp);
if ($ipForLog === null && !empty($_SESSION['hotspot_device_info']['ip'])) {
  $ipForLog = normalize_ip((string) $_SESSION['hotspot_device_info']['ip']);
}

if (!empty($_SESSION['portal_v2_active'])) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '0') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  if ($host !== '') {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePrefix = '';
    if ($scriptName !== '') {
      $needle = '/portal/';
      $pos = strpos($scriptName, $needle);
      if ($pos !== false) {
        $basePrefix = substr($scriptName, 0, $pos);
      } else {
        $basePrefix = rtrim(dirname(dirname($scriptName)), '/');
      }
    }
    $basePrefix = rtrim($basePrefix, '/');
    $path = ($basePrefix === '' ? '' : $basePrefix) . '/portal-v2/home.php';
    $targetUrl = $scheme . '://' . $host . $path;
    $linkOrigEsc = rawurlencode($targetUrl);
  }
}

// 2.1) Registra/atualiza o dispositivo
try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  fs_upsert_device($pdo, $username, $macAddr, $clientIp, $serverName, $ua);
} catch (\Throwable $e) {
  error_log('[device] upsert falhou: ' . $e->getMessage());
}

$sessionTimeoutSeconds = null;
$_partnerLogPayload = null;
$centralCourtesyHandled = false;
if (isset($pdo) && $pdo instanceof PDO) {
  try {
    $fastId = trim((string) ($_SESSION['portal_fast_id'] ?? ''));
    if ($fastId === '' && $serverName !== '') {
      $normalizedServer = strtolower($serverName);
      if (strpos($normalizedServer, 'hs_') === 0) {
        $fastId = substr($serverName, 3);
      }
    }
    $fastIdSanitized = $fastId !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $fastId) : '';
    $resolvedPartnerId = fs_courtesy_shadow_partner_id($pdo, [
      'portal' => 'v2',
      'partner_code' => $fastIdSanitized,
      'server_name' => $serverName,
    ]);
    if ($fastIdSanitized !== '' || $resolvedPartnerId > 0) {
      $hasPartners = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")
        ->fetchColumn();
      if ($hasPartners) {
        $partnerRow = $fastIdSanitized!=='' ? fs_partner_hotspot_resolve($pdo,$fastIdSanitized,false,false) : null;
        $fastCandidates = [];
        $fastCandidates[] = $fastIdSanitized;
        $trimmedCandidate = ltrim($fastIdSanitized, '_-');
        if ($trimmedCandidate !== '' && $trimmedCandidate !== $fastIdSanitized) {
          $fastCandidates[] = $trimmedCandidate;
        }

        foreach ($fastCandidates as $candidate) {
          $stPartner = $pdo->prepare('SELECT id, code, free_minutes, active, max_uses_per_device, window_per_device_minutes FROM partners WHERE code=? LIMIT 1');
          $stPartner->execute([$candidate]);
          $partnerRow = $stPartner->fetch(PDO::FETCH_ASSOC);
          if ($partnerRow) {
            break;
          }
        }

        if (!$partnerRow) {
          $numericCandidate = $trimmedCandidate !== '' ? $trimmedCandidate : $fastIdSanitized;
          if (ctype_digit($numericCandidate)) {
            $stPartner = $pdo->prepare('SELECT id, code, free_minutes, active, max_uses_per_device, window_per_device_minutes FROM partners WHERE id=? LIMIT 1');
            $stPartner->execute([(int) $numericCandidate]);
            $partnerRow = $stPartner->fetch(PDO::FETCH_ASSOC);
          }
        }

        if (!$partnerRow && $resolvedPartnerId > 0) {
          $stPartner = $pdo->prepare('SELECT id, code, free_minutes, active, max_uses_per_device, window_per_device_minutes FROM partners WHERE id=? LIMIT 1');
          $stPartner->execute([$resolvedPartnerId]);
          $partnerRow = $stPartner->fetch(PDO::FETCH_ASSOC);
        }

        if ($partnerRow && (int) ($partnerRow['active'] ?? 0) === 1) {
          $_SESSION['portal_fast_id'] = fs_partner_hotspot_public_code($partnerRow) ?: (string) ($partnerRow['code'] ?? $fastIdSanitized);

          $minutes = max(0, (int) ($partnerRow['free_minutes'] ?? 0));
          $perDeviceLimit = max(0, (int) ($partnerRow['max_uses_per_device'] ?? 0));
          $windowMinutes = max(1, (int) ($partnerRow['window_per_device_minutes'] ?? 1440));
          $limitInfo = fs_quick_daily_limit_info($pdo, $accountUsername);
          $isUnlimited = !empty($limitInfo['has_premium']) || !empty($limitInfo['is_provider']);

          $courtesyContext = [
            'partner_id' => (int) ($partnerRow['id'] ?? 0),
            'hotspot_id' => (int)(fs_partner_hotspot_id($partnerRow) ?? 0),
            'portal' => 'v2',
            'source' => 'quick_login',
            'device_key' => $deviceKey ?? '',
            'mac' => $macAddr,
            'ip' => $ipForLog,
            'account_key' => $accountUsername,
            'account_username' => $accountUsername,
            'associated_device' => fs_courtesy_shadow_associated($pdo, $accountUsername, (string) ($deviceKey ?? '')),
            'ad_completed' => false,
            'has_active_paid' => !empty($limitInfo['has_premium']),
            'is_provider' => !empty($limitInfo['is_provider']),
          ];
          $courtesyContext['idempotency_key'] = fs_courtesy_access_idempotency($courtesyContext, 'v2_quick_login');
          $centralResult = fs_courtesy_access_issue($pdo, $courtesyContext);
          $centralCourtesyHandled = !empty($centralResult['handled']);

          if ($centralCourtesyHandled) {
            $existingAccessCode = in_array((string)($centralResult['code'] ?? ''), ['PROVIDER_NOT_ELIGIBLE', 'ACTIVE_PLAN_NOT_ELIGIBLE'], true);
            if (empty($centralResult['allowed']) && $existingAccessCode && $isUnlimited) {
              // A política exclui a cortesia, não o acesso já contratado.
              // Mantém a credencial original e não grava uso no ledger legado.
              $username = $accountUsername;
              unset($_SESSION['courtesy_active_grant']);
            } elseif (empty($centralResult['allowed']) || empty($centralResult['grant']['username']) || empty($centralResult['grant']['password'])) {
              $_SESSION['quick_error'] = (string) ($centralResult['message'] ?? 'Não foi possível liberar a cortesia agora.');
              $_SESSION['quick_prefill'] = $accountUsername;
              $_SESSION['portal_v2_force_screen'] = 'tela-plans';
              if (!empty($centralResult['retry_at'])) {
                $_SESSION['portal_v2_limit_info'] = ['next_try_at' => $centralResult['retry_at']];
              }
              unset($_SESSION['hotspot_auto'], $_SESSION['courtesy_active_grant']);
              header('Location: ../portal-v2/index.php');
              exit;
            } else {
              $username = (string) $centralResult['grant']['username'];
              $password = (string) $centralResult['grant']['password'];
              $_SESSION['hotspot_auto'] = ['username' => $username, 'password' => $password];
              $_SESSION['courtesy_active_grant'] = [
                'public_id' => (string) ($centralResult['grant']['public_id'] ?? ''),
                'portal' => 'v2',
                'partner_id' => (int) ($partnerRow['id'] ?? 0),
                'account_username' => $accountUsername,
              ];
            }
          } elseif (!$isUnlimited) {
            if ($perDeviceLimit > 0 && $deviceKey !== null) {
              $recentStmt = $pdo->prepare("SELECT COUNT(*) FROM partner_uses WHERE code=? AND mac=? AND used_at >= (NOW() - INTERVAL ? MINUTE)");
              $recentStmt->execute([(string)($partnerRow['code'] ?? $fastIdSanitized), $deviceKey, $windowMinutes]);
              $recentCount = (int) $recentStmt->fetchColumn();
              if ($recentCount >= $perDeviceLimit) {
                $firstStmt = $pdo->prepare("SELECT used_at FROM partner_uses WHERE code=? AND mac=? AND used_at >= (NOW() - INTERVAL ? MINUTE) ORDER BY used_at ASC LIMIT 1");
                $firstStmt->execute([(string)($partnerRow['code'] ?? $fastIdSanitized), $deviceKey, $windowMinutes]);
                $firstUse = $firstStmt->fetchColumn();
                $waitSeconds = $windowMinutes * 60;
                $unlockAtTs = null;
                if ($firstUse) {
                  $unlockAtTs = strtotime((string) $firstUse) + ($windowMinutes * 60);
                  $waitSeconds = max(60, $unlockAtTs - time());
                }
                $waitLabel = fs_quick_format_interval($waitSeconds);
                fs_courtesy_shadow_capture($pdo, [
                  'allowed' => false,
                  'code' => 'LEGACY_DEVICE_LIMIT',
                  'retry_at' => $unlockAtTs,
                ], [
                  'partner_id' => (int) ($partnerRow['id'] ?? 0),
                  'partner_code' => (string) ($partnerRow['code'] ?? $fastIdSanitized),
                  'portal' => 'v2',
                  'source' => 'quick_login',
                  'device_key' => $deviceKey,
                  'mac' => $macAddr,
                  'ip' => $ipForLog,
                  'username' => $username,
                  'has_active_paid' => false,
                  'is_provider' => false,
                ]);
                $_SESSION['quick_error'] = 'Você já utilizou o acesso gratuito deste local. Ele libera novamente em aproximadamente ' . $waitLabel . '.';
                $_SESSION['portal_v2_force_screen'] = 'tela-plans';
                $_SESSION['portal_v2_limit_info'] = [
                  'minutes_left' => (int) ceil($waitSeconds / 60),
                  'seconds_left' => $waitSeconds,
                  'next_try_at' => $unlockAtTs,
                ];
                $_SESSION['quick_prefill'] = $username;
                unset($_SESSION['hotspot_auto']);
                header('Location: ../portal-v2/index.php');
                exit;
              }
            }

            if ($minutes > 0) {
              $sessionTimeoutSeconds = min($minutes, 1440) * 60;
            }

            $_partnerLogPayload = [
              'partner_id' => (int) ($partnerRow['id'] ?? 0),
              'code' => (string) ($partnerRow['code'] ?? $fastIdSanitized),
              'mac' => $deviceKey,
              'ip' => $ipForLog,
            ];

            fs_courtesy_shadow_capture($pdo, [
              'allowed' => true,
              'code' => 'LEGACY_GRANTED',
              'minutes' => $minutes,
            ], [
              'partner_id' => (int) ($partnerRow['id'] ?? 0),
              'partner_code' => (string) ($partnerRow['code'] ?? $fastIdSanitized),
              'portal' => 'v2',
              'source' => 'quick_login',
              'device_key' => $deviceKey,
              'mac' => $macAddr,
              'ip' => $ipForLog,
              'username' => $username,
              'has_active_paid' => false,
              'is_provider' => false,
            ]);
          } else {
            fs_courtesy_shadow_capture($pdo, [
              'allowed' => false,
              'code' => !empty($limitInfo['is_provider']) ? 'LEGACY_PROVIDER_BYPASS' : 'LEGACY_ACTIVE_PLAN_BYPASS',
            ], [
              'partner_id' => (int) ($partnerRow['id'] ?? 0),
              'partner_code' => (string) ($partnerRow['code'] ?? $fastIdSanitized),
              'portal' => 'v2',
              'source' => 'quick_login',
              'device_key' => $deviceKey,
              'mac' => $macAddr,
              'ip' => $ipForLog,
              'username' => $username,
              'has_active_paid' => !empty($limitInfo['has_premium']),
              'is_provider' => !empty($limitInfo['is_provider']),
            ]);
          }
        }
      }
    }
  } catch (\Throwable $e) {
    error_log('[partner] session timeout lookup falhou: ' . $e->getMessage());
  }

  if ($_partnerLogPayload && ($_partnerLogPayload['partner_id'] ?? 0) > 0) {
    try {
      $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
      $ins = $pdo->prepare("INSERT INTO partner_uses (partner_id, code, username, mac, ip, user_agent, used_at) VALUES (?,?,?,?,?,?,NOW())");
      $ins->execute([
        $_partnerLogPayload['partner_id'],
        $_partnerLogPayload['code'],
        $accountUsername,
        $_partnerLogPayload['mac'] ?? null,
        $_partnerLogPayload['ip'] ?? null,
        $ua !== '' ? $ua : null,
      ]);
    } catch (\Throwable $e) {
      error_log('[partner] uso log falhou: ' . $e->getMessage());
    }
  }

  if (!$centralCourtesyHandled) {
    try {
      fs_set_session_timeout($pdo, $accountUsername, $sessionTimeoutSeconds);
    } catch (\Throwable $e) {
      error_log('[partner] session timeout update falhou: ' . $e->getMessage());
    }
  }
}

// 2.2) Envia WhatsApp SOMENTE se NÃO-Hubsoft **e** em grupo permitido (ex.: Plano_Padrao)
$isHubsoft = !empty($_SESSION['is_hubsoft']); // true para clientes do provedor
if (!$isHubsoft) {
  try {
    if (isset($pdo) && $pdo instanceof PDO) {
      $inAllowedGroup = user_in_any_group($pdo, $accountUsername, WHATS_ALLOWED_GROUPS);
      if ($inAllowedGroup) {
        try_send_promo_whats($pdo, $accountUsername);
      }
    }
  } catch (\Throwable $e) {
    error_log('[whats] erro geral: ' . $e->getMessage());
  }
}

// 3) Sem endpoint do Mikrotik? instrução amigável
// Se link-login-only veio vazio, tenta usar link-login simples
if ($linkLoginOnly === '' && $linkLogin !== '') {
  $linkLoginOnly = $linkLogin;
}

if ($linkLoginOnly === '') {
  if (!empty($_SESSION['portal_v2_active'])) {
    header('Location: ../portal-v2/hotspot_hint.php');
    exit;
  }

  http_response_code(200);
  ?>
  <!DOCTYPE html>
  <html lang="pt-BR">

  <head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conectar</title>
    <link rel="stylesheet" href="assets/css/portal.css">
  </head>

  <body>
  <div class="header inline"><a href="cliente.php">← Minha Conta</a><strong>Conectar</strong><span></span></div>
    <div class="container">
      <div class="card">
        <h3>Contexto do Hotspot ausente</h3>
        <p>Para conectar automaticamente, abra o navegador já conectado ao Wi-Fi e acesse a página inicial
          (captura do portal). Em seguida, volte a tentar.</p>
        <a class="btn" href="index.php">Ir para o Portal</a>
      </div>
    </div>
  </body>

  </html>
  <?php
  exit;
}

// 4) Senha: sessão > radcheck
$password = $_SESSION['hotspot_auto']['password'] ?? null;
if ($password === null || $password === '') {
  try {
    if (!isset($pdo)) {
      $pdo = db();
    }
    $password = radius_password($pdo, $username);
  } catch (\Throwable $e) {
    $password = null;
  }
}

// 5) Prepara payload CHAP ou simples
$useChap = ($chapId !== '' && $chapChallenge !== '' && $password !== null);
$responseHex = $useChap ? chap_response($chapId, $chapChallenge, $password) : null;

// 6) Render + auto-submit
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conectando…</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
  <meta http-equiv="Pragma" content="no-cache">
  <style>
    body {
      margin: 0
    }

    .center {
      display: flex;
      min-height: 70vh;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .card {
      max-width: 560px
    }

    .muted {
      color: #6b7280
    }

    .btn-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
  </style>
</head>

<body>
  <div class="header inline">
    <a href="cliente.php">← Minha Conta</a>
    <strong>Conectando ao Wi-Fi</strong>
    <span></span>
  </div>

  <div class="container center">
    <div class="card">
      <h3>Conectando…</h3>
      <p class="muted">Autenticando <b><?= h($username) ?></b> no hotspot.</p>

      <?php if (($useChap && $responseHex) || ($password !== null)): ?>
        <p class="muted">Redirecionando para o Mikrotik…</p>
      <?php else: ?>
        <p class="muted" style="color:#b91c1c">Não foi possível obter sua senha para autenticação automática.</p>
        <div class="btn-row">
          <a class="btn" href="login.php">Entrar manualmente</a>
          <a class="btn" href="cliente.php">Voltar</a>
        </div>
      <?php endif; ?>

      <form id="mkLogin" method="post" action="<?= h($linkLoginOnly) ?>" style="display:none;">
        <input type="hidden" name="username" value="<?= h($username) ?>">
        <?php if ($useChap && $responseHex): ?>
          <input type="hidden" name="chap-id" value="<?= h($chapId) ?>">
          <input type="hidden" name="chap-challenge" value="<?= h($chapChallenge) ?>">
          <input type="hidden" name="response" value="<?= h($responseHex) ?>">
        <?php elseif ($password !== null): ?>
          <input type="hidden" name="password" value="<?= h($password) ?>">
        <?php endif; ?>
        <?php if (!empty($linkOrigEsc)): ?>
          <input type="hidden" name="dst" value="<?= h($linkOrigEsc) ?>">
        <?php endif; ?>
      </form>

      <?php if (($useChap && $responseHex) || ($password !== null)): ?>
        <noscript>
          <p>JavaScript desabilitado. Clique no botão abaixo para conectar.</p>
          <div class="btn-row">
            <button form="mkLogin" class="btn primary" type="submit">Conectar</button>
          </div>
        </noscript>
      <?php endif; ?>
    </div>
  </div>

  <?php if (($useChap && $responseHex) || ($password !== null)): ?>
    <script>
      (function () { var f = document.getElementById('mkLogin'); if (f) f.submit(); })();
    </script>
  <?php endif; ?>
</body>

</html>
