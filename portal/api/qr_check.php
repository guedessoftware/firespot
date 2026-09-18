<?php
// /portal/qr_check.php — processa QR de parceiro e libera acesso
@ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../../app/session_boot.php';

require_once __DIR__ . '/../../app/db.php'; // << CORRIGIDO: um nível só
require_once __DIR__ . '/../../app/partner_helpers.php';
require_once __DIR__ . '/../../app/courtesy_shadow.php';
require_once __DIR__ . '/../../app/courtesy_access.php';

// ===== Helpers =====
if (!function_exists('envv')) {
  function envv($k, $d = null)
  {
    if (function_exists('env'))
      return env($k, $d);
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
  }
}
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function http_redirect($u)
{
  header('Cache-Control:no-store');
  header('Location:' . $u, true, 302);
  echo '<a href="' . h($u) . '">Continuar</a>';
  exit;
}

function render_portal_error(string $message, int $status = 403): void
{
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html lang="pt-BR">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso bloqueado</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
      body { margin:0; display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg,#f9fafb); }
      .notice-card {
        max-width: 420px;
        padding: 24px;
        border-radius: 18px;
        border:1px solid #fecaca;
        background:#fef2f2;
        color:#b91c1c;
        text-align:center;
        box-shadow:0 12px 32px rgba(0,0,0,.08);
      }
      .notice-card h1 { margin:0 0 12px; font-size:22px; }
      .notice-card p { margin:0 0 18px; line-height:1.5; }
      .notice-card a {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:10px 18px;
        border-radius:12px;
        text-decoration:none;
        background:#111827;
        color:#fff;
        font-weight:600;
      }
      .notice-card a:hover { filter:brightness(1.05); }
    </style>
  </head>
  <body>
    <div class="notice-card">
      <h1>Acesso não permitido</h1>
      <p><?= h($message) ?></p>
      <a href="/portal/index.php">← Voltar ao portal</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/** Constrói a URL de login do Hotspot para auto-login (sem '/../') */
function build_hotspot_login_url(string $username, string $password): string
{
  // Preferir domínio do estabelecimento quando conhecido
  $scheme = 'http'; // Hotspot Mikrotik usualmente em HTTP
  $hostHdr = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
  $useHost = $hostHdr;
  try {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $dns = '';
    // 1) via contexto em sessão (portal_fast_id pode ser id numérico ou code)
    $fast = isset($_SESSION['portal_fast_id']) ? trim((string)$_SESSION['portal_fast_id']) : '';
    if ($fast !== '') {
      if (ctype_digit($fast)) {
        $st = $pdo->prepare('SELECT dns_name FROM partners WHERE id=? AND active=1 LIMIT 1');
        $st->execute([(int)$fast]);
        $dns = trim((string)($st->fetchColumn() ?: ''));
        if ($dns === '') {
          $st2 = $pdo->prepare('SELECT dns_name FROM partners WHERE code=? AND active=1 LIMIT 1');
          $st2->execute([$fast]);
          $dns = trim((string)($st2->fetchColumn() ?: ''));
        }
      } else {
        $st = $pdo->prepare('SELECT dns_name FROM partners WHERE code=? AND active=1 LIMIT 1');
        $st->execute([$fast]);
        $dns = trim((string)($st->fetchColumn() ?: ''));
      }
    }
    // 2) fallback: casar pelo host atual
    if ($dns === '') {
      if ($hostHdr !== '') {
        $st = $pdo->prepare('SELECT dns_name FROM partners WHERE dns_name=? AND active=1 LIMIT 1');
        $st->execute([$hostHdr]);
        $dns = trim((string)($st->fetchColumn() ?: ''));
      }
    }
    if ($dns !== '') {
      $loginBase = 'http://' . $dns; // Mikrotik Hotspot normalmente em HTTP
      $dst       = $loginBase . '/status'; // manter dst no mesmo domínio do hotspot
      return $loginBase . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password) . '&dst=' . rawurlencode($dst);
    } else if ($hostHdr !== '') {
      $base = $scheme . '://' . $hostHdr;
      $dst  = $base . '/status';
      return $base . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password) . '&dst=' . rawurlencode($dst);
    }
  } catch (\Throwable $e) { /* fallback abaixo */ }

  // Sem DNS e sem host HTTP: sem como construir
  return '';
}

/** Captura IP/MAC do hotspot (quando disponível) + cria DeviceID persistente como fallback */
function device_identity(): array
{
  $ip = $_SESSION['hotspot_device_info']['ip'] ?? ($_SESSION['hotspot_ctx']['data']['ip'] ?? '');
  $mac = $_SESSION['hotspot_device_info']['mac'] ?? ($_SESSION['hotspot_ctx']['data']['mac'] ?? '');
  $ip = $ip ?: ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
  $mac = strtoupper(str_replace('-', ':', trim((string) $mac)));

  // Device ID por cookie (1 ano) — usado quando não há MAC
  $did = $_COOKIE['fs_did'] ?? '';
  if ($did === '') {
    $did = bin2hex(random_bytes(8));
    setcookie('fs_did', $did, [
      'expires' => time() + 31536000,
      'path' => '/',
      'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
    $_COOKIE['fs_did'] = $did;
  }
  return [trim((string) $ip), $mac, $did];
}

/** Upsert Session-Timeout (não acumula tempo — é por sessão) e Interim=60 */
function upsert_session_timeout(PDO $pdo, string $u, int $sec): void
{
  if ($u === '' || $sec <= 0)
    return;
  $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute='Session-Timeout'")->execute([$u]);
  $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)")
    ->execute([$u, 'Session-Timeout', (string) $sec]);

  $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute='Acct-Interim-Interval'")->execute([$u]);
  $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)")
    ->execute([$u, 'Acct-Interim-Interval', '60']);
}

/** Upsert credencial temporária + timeout */
function upsert_temp_user(PDO $pdo, string $u, string $pwd, int $sec): void
{
  $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")->execute([$u]);
  $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)")
    ->execute([$u, 'Cleartext-Password', $pwd]);
  upsert_session_timeout($pdo, $u, $sec);
}

/** Gera senha aleatória legível */
function gen_password(int $n = 8): string
{
  $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $o = '';
  for ($i = 0; $i < $n; $i++)
    $o .= $c[random_int(0, strlen($c) - 1)];
  return $o;
}

// ===== Input =====
$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
if ($code === '') {
  http_response_code(400);
  echo 'Código ausente.';
  exit;
}

$expectedFastId = trim((string)($_SESSION['portal_fast_id'] ?? ''));
if ($expectedFastId !== '' && strcasecmp($expectedFastId, $code) !== 0) {
  render_portal_error('Este QR não pertence a este estabelecimento.');
}

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");

  // 1) Carrega o parceiro (ativo e não expirado)
  $st = $pdo->prepare("
    SELECT *
      FROM partners
     WHERE code=? AND active=1
     LIMIT 1
  ");
  $st->execute([$code]);
  $p = $st->fetch(PDO::FETCH_ASSOC);
  if (!$p) {
    http_response_code(410);
    echo 'QR inválido ou fora da janela ativa.';
    exit;
  }
  // Garante o contexto do parceiro para os próximos passos (anúncio/ad_grant)
  $_SESSION['portal_fast_id'] = $code;

  $partner_id = (int) $p['id'];
  $minutes = max(1, (int) $p['free_minutes']);
  $profile = partner_profile($p);
  $isFastTrack = ($profile === 'comerciante');
  if ($isFastTrack) {
    // mantém apenas o clamp dos minutos para perfil "comerciante"
    $minutes = min($minutes, 5);
  }
  $ttl_sec = $minutes * 60;

  // ERA assim:
// $requireAuth = (int) $p['require_auth'] === 1;
// if ($isFastTrack) {
//   $requireAuth = false;
// }

  // O legado usa require_auth; em enforce, auth_mode/requires_ad vêm somente
  // da política unificada.
  $requireAuth = ((int)$p['require_auth'] === 1);
  $requireAssociation = $requireAuth;
  $requiresAd = true;
  $centralPolicy = fs_courtesy_policy_resolve($pdo, $partner_id);
  $centralRollout = fs_courtesy_rollout_resolve($pdo, $partner_id, 'qr_ad', $centralPolicy);
  $centralEnforce = ($centralRollout['effective_mode'] ?? '') === 'enforce';
  if ($centralEnforce) {
    $authMode = (string)($centralPolicy['auth_mode'] ?? 'anonymous');
    $requireAuth = in_array($authMode, ['account', 'account_device'], true);
    $requireAssociation = $authMode === 'account_device';
    $requiresAd = !empty($centralPolicy['requires_ad']);
    $minutes = max(1, (int)($centralPolicy['grant_minutes'] ?? $minutes));
    $ttl_sec = $minutes * 60;
  }

  $maxTotal = (int) ($p['max_uses_total'] ?? 0);
  $perDev = max(0, (int) $p['max_uses_per_device']);        // 0 = sem limite por dispositivo
  $winMin = max(1, (int) $p['window_per_device_minutes']);  // janela por dispositivo (min)

  $ip = '';
  $mac = '';
  $devKey = '';
  $assocUser = false;
  $username = trim((string) ($_SESSION['cliente_username'] ?? ''));
  $shadow = static function (array $legacy) use ($pdo, $partner_id, $code, &$ip, &$mac, &$devKey, &$assocUser, &$username): void {
    fs_courtesy_shadow_capture($pdo, $legacy, [
      'partner_id' => $partner_id,
      'partner_code' => $code,
      'portal' => 'qr_ad',
      'source' => 'qr_check',
      'device_key' => $devKey,
      'mac' => $mac,
      'ip' => $ip,
      'username' => $username,
      'associated_device' => (bool)$assocUser,
      'ad_completed' => !empty($_GET['ad']),
    ]);
  };

  if (!$centralEnforce && $maxTotal > 0) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM partner_uses WHERE partner_id=?");
    $q->execute([$partner_id]);
    if ((int) $q->fetchColumn() >= $maxTotal) {
      $shadow(['allowed' => false, 'code' => 'LEGACY_PARTNER_TOTAL_LIMIT']);
      http_response_code(429);
      echo 'Este QR atingiu o limite total de acessos disponíveis.';
      exit;
    }
  }

  // 2) Identidade do dispositivo (MAC ou DeviceID)
  list($ip, $mac, $did) = device_identity();
  $devKey = $mac !== '' ? $mac : ('DID:' . $did); // usado para limites e auditoria

  // 2.1) Exigir associação prévia do dispositivo a uma conta
  // Só forçar login quando a opção "Exigir login prévio" estiver habilitada no host
  try {
    $chk = $pdo->prepare("SELECT username FROM clientes_dispositivos WHERE mac = ? LIMIT 1");
    $chk->execute([$devKey]);
    $assocUser = $chk->fetchColumn();
  } catch (\Throwable $e) {
    $assocUser = false; // se der erro, trata como não associado para lado seguro
  }
  $associatedDevice = $username !== '' && fs_courtesy_shadow_associated($pdo, $username, $devKey);
  if (($centralEnforce ? $requireAssociation : $requireAuth) && !$associatedDevice && ($centralEnforce || !$assocUser)) {
    $shadow(['allowed' => false, 'code' => 'LEGACY_ASSOCIATION_REQUIRED']);
    $next = '/portal/api/qr_ready.php?code=' . rawurlencode($code);
    // Se já passou pelo anúncio (ad=1), ainda assim precisamos do login para associar o device, mantendo a regra.
    http_redirect('../login.php?assoc=1&next=' . rawurlencode($next));
  }

  // 3) Restrições de uso
  if (!$centralEnforce && $perDev > 0) {
    $q = $pdo->prepare("
      SELECT COUNT(*) FROM partner_uses
       WHERE code=? AND mac=?
         AND used_at >= (NOW() - INTERVAL ? MINUTE)
    ");
    $q->execute([$code, $devKey, $winMin]);
    if ((int) $q->fetchColumn() >= $perDev) {
      $shadow(['allowed' => false, 'code' => 'LEGACY_DEVICE_LIMIT']);
      http_response_code(429);
      echo 'Você já utilizou este acesso recente. Tente novamente mais tarde.';
      exit;
    }
  }

  // 4) No corte, esta entrada não mantém regra ou credencial paralela.
  if ($centralEnforce) {
    if ($requireAuth && $username === '') {
      $next = '/portal/api/qr_ready.php?code=' . rawurlencode($code);
      http_redirect('../login.php?next=' . rawurlencode($next));
    }
    if ($requiresAd) {
      http_redirect('../anuncio.php?code=' . rawurlencode($code));
    }

    $radiusState = fs_courtesy_shadow_radius_state($username);
    $courtesyContext = [
      'partner_id' => $partner_id,
      'portal' => 'qr_ad',
      'source' => 'qr_check',
      'device_key' => $devKey,
      'mac' => $mac,
      'ip' => $ip,
      'account_key' => $username,
      'account_username' => $username,
      'associated_device' => $associatedDevice,
      'ad_completed' => false,
      'has_active_paid' => !empty($radiusState['has_active_paid']),
      'is_provider' => !empty($radiusState['is_provider']),
    ];
    $courtesyContext['idempotency_key'] = fs_courtesy_access_idempotency($courtesyContext, 'qr_check');
    $centralResult = fs_courtesy_access_issue($pdo, $courtesyContext);
    if (empty($centralResult['handled'])) {
      throw new RuntimeException('O rollout central perdeu o modo enforce durante a concessão QR.');
    }
    if (empty($centralResult['allowed']) || empty($centralResult['grant']['username']) || empty($centralResult['grant']['password'])) {
      $status = in_array((string)($centralResult['code'] ?? ''), ['ENFORCEMENT_FAILED', 'ENFORCEMENT_UNAVAILABLE'], true) ? 503 : 409;
      render_portal_error((string)($centralResult['message'] ?? 'Cortesia não disponível.'), $status);
    }

    $tempUser = (string)$centralResult['grant']['username'];
    $tempPwd = (string)$centralResult['grant']['password'];
    $minutes = max(1, (int)($centralResult['grant']['minutes'] ?? $minutes));
    $_SESSION['hotspot_auto'] = ['username' => $tempUser, 'password' => $tempPwd];
    $_SESSION['courtesy_active_grant'] = [
      'public_id' => (string)($centralResult['grant']['public_id'] ?? ''),
      'portal' => 'qr_ad',
      'partner_id' => $partner_id,
      'account_username' => $username,
    ];
    $login = build_hotspot_login_url($tempUser, $tempPwd);
    if ($login !== '') http_redirect($login);
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <main class="container">
      <div class="card" style="max-width:720px;margin:24px auto;">
        <h2>Acesso liberado por <?= h($minutes) ?> minuto(s)</h2>
        <p>Conecte-se à rede Wi-Fi e tente novamente se o login não abrir automaticamente.</p>
      </div>
    </main>
    <?php
    exit;
  }

  // 5) Fluxo legado com/sem autenticação prévia
  if ($requireAuth) {
    // Requer login → se não logado, encaminha para login (com retorno ao fluxo pronto)
    if ($username === '') {
      $shadow(['allowed' => false, 'code' => 'LEGACY_AUTH_REQUIRED']);
      // Se veio do anúncio, redireciona ao login e retorna ao qr_ready
      $next = '/portal/api/qr_ready.php?code=' . rawurlencode($code);
      http_redirect('../login.php?next=' . rawurlencode($next));
    }

    // Antes de conceder, exibir anúncio uma vez neste fluxo (se ainda não exibido)
    if (empty($_GET['ad'])) {
      $nextSelf = '/portal/api/qr_check.php?code=' . rawurlencode($code) . '&ad=1';
      http_redirect('../anuncio.php?next=' . rawurlencode($nextSelf));
    }

    // Usuário TEMPORÁRIO por parceiro + dispositivo (MAC ou DID)
    $suffix = $mac !== '' ? preg_replace('/[^A-F0-9]/', '', strtoupper($mac)) : substr(sha1($did), 0, 12);
    $prefix = $mac !== '' ? 'QR' : 'QRD';
    $tempUser = $prefix . $partner_id . '-' . $suffix;
    $tempPwd = gen_password(8);

    upsert_temp_user($pdo, $tempUser, $tempPwd, $ttl_sec);

    // Registra uso (mantém username real) — salva devKey na coluna mac
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $ins = $pdo->prepare("INSERT INTO partner_uses(partner_id, code, username, mac, ip, user_agent, used_at)
                          VALUES(?,?,?,?,?,?,NOW())");
    $ins->execute([$partner_id, $code, $username, $devKey, $ip ?: null, $ua ?: null]);
    $shadow(['allowed' => true, 'code' => 'LEGACY_GRANTED', 'minutes' => $minutes]);

    // Auto-login
    $login = build_hotspot_login_url($tempUser, $tempPwd);
    if ($login !== '')
      http_redirect($login);

    // Fallback visual
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <link rel="stylesheet" href="assets/css/portal.css">
    <main class="container">
      <div class="card" style="max-width:720px;margin:24px auto;">
        <h2>Acesso liberado por <?= h($minutes) ?> minuto(s)</h2>
        <p>Caso a página de login não abra automaticamente, acesse a rede Wi-Fi e tente novamente.</p>
        <?php if ($isFastTrack): ?>
          <p>Parceiros comerciais liberam um acesso rápido de até 5 minutos para concluir o pagamento.</p>
        <?php endif; ?>
      </div>
    </main>
    <?php
    exit;

  } else {
    // NÃO exige login → usuário TEMPORÁRIO por parceiro + dispositivo (MAC ou DID)
    // Ainda assim, exibe o anúncio uma vez antes de conceder o acesso
    if (empty($_GET['ad'])) {
      $nextSelf = '/portal/api/qr_check.php?code=' . rawurlencode($code) . '&ad=1';
      http_redirect('../anuncio.php?next=' . rawurlencode($nextSelf));
    }
    $suffix = $mac !== '' ? preg_replace('/[^A-F0-9]/', '', strtoupper($mac)) : substr(sha1($did), 0, 12);
    $prefix = $mac !== '' ? 'QR' : 'QRD';
    $tempUser = $prefix . $partner_id . '-' . $suffix;
    $tempPwd = gen_password(8);

    upsert_temp_user($pdo, $tempUser, $tempPwd, $ttl_sec);

    // Registra uso (sem usuário autenticado; grava o próprio tempUser) — devKey no campo mac
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $ins = $pdo->prepare("INSERT INTO partner_uses(partner_id, code, username, mac, ip, user_agent, used_at)
                          VALUES(?,?,?,?,?,?,NOW())");
    $ins->execute([$partner_id, $code, $tempUser, $devKey, $ip ?: null, $ua ?: null]);
    $shadow(['allowed' => true, 'code' => 'LEGACY_GRANTED', 'minutes' => $minutes]);

    // Auto-login direto
    $login = build_hotspot_login_url($tempUser, $tempPwd);
    if ($login !== '')
      http_redirect($login);

    // Fallback visual
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <link rel="stylesheet" href="assets/css/portal.css">
    <main class="container">
      <div class="card" style="max-width:720px;margin:24px auto;">
        <h2>Acesso temporário gerado</h2>
        <p>Se a página de login do Hotspot não abriu, conecte-se ao Wi-Fi e tente novamente.</p>
        <?php if ($isFastTrack): ?>
          <p>Parceiros comerciais liberam até 5 minutos de conexão para finalizar a compra.</p>
        <?php endif; ?>
      </div>
    </main>
    <?php
    exit;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo 'Falha interna: ' . h($e->getMessage());
}
