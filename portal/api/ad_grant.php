<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/settings.php';
require_once __DIR__ . '/../../app/lib/routeros.php';
require_once __DIR__ . '/radius_vip.php'; // rad_db(), build_hotspot_login_url(), radius helpers
require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/courtesy_shadow.php';
require_once __DIR__ . '/../../app/courtesy_access.php';
require_once __DIR__ . '/../../app/partner_ads.php';
require_once __DIR__ . '/../../app/ad_monetization.php';

function json_input(){
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  if (is_array($j)) return $j;
  if (!empty($_POST)) return $_POST;
  return [];
}
function h_ip($ip){
  $ip = trim((string)$ip);
  if ($ip === '') return '';
  if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $ip)) $ip = preg_replace('/:(\d+)$/','',$ip);
  $ip = trim($ip, '[]');
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return $ip;
  return '';
}
function h_mac($mac){
  $mac = strtoupper(str_replace('-',':', trim((string)$mac)));
  if ($mac === '') return '';
  if (preg_match('/^[0-9A-F]{12}$/', $mac)) $mac = implode(':', str_split($mac, 2));
  return preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac) ? $mac : '';
}

// Entrada: concessões alteram estado e nunca aceitam GET ou requisição sem CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED']);
  exit;
}
$in = json_input();
if (!csrf_check((string)($in['csrf'] ?? ''))) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'INVALID_CSRF']);
  exit;
}
$ip  = h_ip($in['ip'] ?? ($_SESSION['hotspot_device_info']['ip'] ?? ''));
$mac = h_mac($in['mac'] ?? ($_SESSION['hotspot_device_info']['mac'] ?? ''));
if ($mac === '' && isset($_COOKIE['fs_did'])) {
  $did = preg_replace('/[^A-Fa-f0-9]/','', (string)$_COOKIE['fs_did']);
  $did = substr($did, 0, 28); // 4+28 = 32 chars total p/ coluna VARCHAR(32)
  $mac = 'DID:' . $did;
}

// Para o novo fluxo (sem bypass), permitir mesmo sem IP/MAC.
// Usaremos uma "chave de dispositivo" para controle de cooldown/cap:
//  - Preferir MAC quando disponível;
//  - Caso contrário, usar USR:<username> (por conta), evitando abuso básico.

// Usuário atual (pode ser nulo caso o parceiro não exija login)
$username = $_SESSION['cliente_username'] ?? null;
$partnerId = 0;
$partnerCode = '';
$hotspotId = 0;
$isProvider = false;
$hasActivePaid = false;
$centralPolicy = null;
$centralEnforce = false;
$centralRequiresAssociation = false;
$row = [];

// Chave para controle (cooldown/limite) — prioridade MAC, senão fallback por usuário
$macKey = $mac !== '' ? $mac : ('USR:' . $username);

// Le configurações (padrão)
$minutes = (int)settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 10);
if ($minutes <= 0) $minutes = 10;
if ($minutes > 120) $minutes = 120;
// Cooldown padrão global (pode ser sobreposto pelo parceiro abaixo)
$coolMin = (int)settings_get('ad_cooldown_minutes', getenv('AD_COOLDOWN_DEFAULT') ?: 180);
if ($coolMin < 0) $coolMin = 0;
// Se houver um host/parceiro no contexto (Acesso rápido), preferir os "Minutos liberados" do host
// Determina política do parceiro (se existir): minutos e se exige login
$partnerRequireAuth = true; // por padrão, exigir login em ambientes sem parceiro
try {
  $fastId = trim((string)($_SESSION['portal_fast_id'] ?? ''));
  if ($fastId !== '') {
    $app = db();
    $app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $hasPartners = (bool)$app->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      $row = fs_partner_hotspot_resolve($app,$fastId,false,false);
      if (!$row) {$st = $app->prepare("SELECT * FROM partners WHERE code=? LIMIT 1");$st->execute([$fastId]);$row=$st->fetch(PDO::FETCH_ASSOC)?:[];}
      if ($row) {
        if ((int)($row['active'] ?? 0) === 1) {
          $partnerId = (int)($row['id'] ?? 0);
          $partnerCode = (string)($row['code'] ?? $fastId);
          $hotspotId = (int)(fs_partner_hotspot_id($row) ?? 0);
          $m = (int)($row['free_minutes'] ?? 0);
          if ($m > 0) { $minutes = $m; if ($minutes > 120) $minutes = 120; }
          $partnerRequireAuth = ((int)($row['require_auth'] ?? 1) === 1);
          // Partner-defined cooldown window per device, if available
          $win = (int)($row['window_per_device_minutes'] ?? 0);
          if ($win > 0) { $coolMin = $win; }

          $centralPolicy = fs_courtesy_policy_resolve($app, $partnerId);
          $centralRollout = fs_courtesy_rollout_resolve($app, $partnerId, 'qr_ad', $centralPolicy);
          $centralEnforce = ($centralRollout['effective_mode'] ?? '') === 'enforce';
          if ($centralEnforce) {
            $partnerRequireAuth = in_array((string)($centralPolicy['auth_mode'] ?? 'anonymous'), ['account', 'account_device'], true);
            $centralRequiresAssociation = (string)($centralPolicy['auth_mode'] ?? '') === 'account_device';
            $minutes = (int)($centralPolicy['grant_minutes'] ?? $minutes);
          }
        }
      }
    }
  }
} catch (\Throwable $e) { /* segue com defaults */ }
$centralAdCompleted = true;
$adProofToken = trim((string)($in['ad_token'] ?? ''));
if (!empty($centralPolicy) && !empty($centralPolicy['requires_ad'])) {
  $centralAdCompleted = false;
  $proof = $_SESSION['courtesy_ad_proofs'][$adProofToken] ?? null;
  if (is_array($proof)) {
    $partnerMatches = $partnerId > 0 && (int)($proof['partner_id'] ?? 0) === $partnerId && ($hotspotId<=0||(int)($proof['hotspot_id']??0)===$hotspotId);
    $eligibleAd = false;
    if ($partnerMatches && $partnerId > 0 && (int)($proof['ad_id'] ?? 0) > 0) {
      try {
        $eligibleIds = array_map(static fn($ad) => (int)$ad['id'], partner_ads_eligible($app,$row));
        $eligibleAd = in_array((int)$proof['ad_id'],$eligibleIds,true);
      } catch (Throwable $e) { $eligibleAd = false; }
    }
    $centralAdCompleted = $partnerMatches && $eligibleAd
      && (int)($proof['ready_at'] ?? PHP_INT_MAX) <= time()
      && (int)($proof['expires_at'] ?? 0) > time();
    if($centralAdCompleted&&strlen($adProofToken)===64){
      try{$delivery=fs_ad_delivery_complete($app,$adProofToken);$centralAdCompleted=(int)$delivery['partner_id']===$partnerId&&($hotspotId<=0||(int)($delivery['hotspot_id']??0)===$hotspotId)&&(int)$delivery['ad_id']===(int)$proof['ad_id'];}catch(Throwable $e){$centralAdCompleted=false;}
    }
  }
}
if (($row['access_purpose'] ?? null) === 'paid') {
  http_response_code(409);
  echo json_encode(['ok'=>false,'code'=>'NOT_ELIGIBLE','error'=>'Esta unidade oferece somente acesso pago.']);
  exit;
}
if (in_array((string)($row['access_purpose'] ?? ''),['sponsored','hybrid'],true)
    && !empty($centralPolicy['requires_ad']) && !$centralAdCompleted) {
  http_response_code(409);
  echo json_encode(['ok'=>false,'code'=>'AD_PROOF_REQUIRED','error'=>'A prova do anúncio não é válida para este estabelecimento.']);
  exit;
}
$shadow = static function (array $legacy, array $extra = []) use (&$partnerId, &$partnerCode, &$hotspotId, &$macKey, &$mac, &$ip, &$username, &$isProvider, &$hasActivePaid, &$centralAdCompleted): void {
  try {
    fs_courtesy_shadow_capture(db(), $legacy, array_merge([
      'partner_id' => $partnerId,
      'partner_code' => $partnerCode,
      'hotspot_id' => $hotspotId,
      'portal' => 'qr_ad',
      'source' => 'ad_grant',
      'device_key' => $macKey === 'USR:' ? '' : $macKey,
      'mac' => $mac,
      'ip' => $ip,
      'username' => (string)$username,
      'ad_completed' => $centralAdCompleted,
      'has_active_paid' => $hasActivePaid,
      'is_provider' => $isProvider,
    ], $extra));
  } catch (\Throwable $e) {
    error_log('[ad grant shadow] ' . $e->getMessage());
  }
};
// limite diário por aparelho (0 = sem limite)
$dailyCap = (int)settings_get('ad_daily_cap', getenv('AD_DAILY_CAP') ?: 0);

// Se o parceiro exige login e não há usuário logado, pede autenticação
if (!$username && $partnerRequireAuth) {
  $shadow(['allowed'=>false,'code'=>'LEGACY_AUTH_REQUIRED']);
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'AUTH_REQUIRED']);
  exit;
}

// Elegibilidade: somente aplicável quando temos um usuário logado
if ($username) {
  try {
    $rad = rad_db();
    $rad->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // provider unlimited?
    $stP = $rad->prepare("SELECT 1 FROM radusergroup WHERE username=? AND groupname='ISP_UNL' LIMIT 1");
    $stP->execute([$username]);
    $isProvider = (bool)$stP->fetchColumn();
    if ($isProvider && !$centralEnforce) {
      $shadow(['allowed'=>false,'code'=>'LEGACY_PROVIDER_NOT_ELIGIBLE']);
      echo json_encode(['ok'=>false,'code'=>'NOT_ELIGIBLE','reason'=>'PROVIDER_UNL']);
      exit;
    }

    // premium ativo? (tem saldo de tempo)
    $stA = $rad->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
    $stA->execute([$username]);
    $allowed = (int)($stA->fetchColumn() ?: 0);
    $stU = $rad->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
    $stU->execute([$username]);
    $usedSoFar = (int)$stU->fetchColumn();
    $remainingNow = max(0, $allowed - $usedSoFar);
    if ($remainingNow > 0) {
      $hasActivePaid = true;
      if (!$centralEnforce) {
        $shadow(['allowed'=>false,'code'=>'LEGACY_ACTIVE_PLAN_NOT_ELIGIBLE']);
        echo json_encode(['ok'=>false,'code'=>'NOT_ELIGIBLE','reason'=>'ALREADY_ACTIVE','remaining'=>$remainingNow]);
        exit;
      }
    }
  } catch (Throwable $e) {
    // se RADIUS falhar, seguimos (best-effort) e deixamos o grant abaixo
  }
}

// Anti-abuso: verificar última liberação por dispositivo
$pdo = db();

// Exigir associação no legado quando o parceiro pede login; no motor central,
// somente o modo account_device exige vínculo entre esta conta e dispositivo.
$associatedDevice = false;
if ($mac !== '' && $username) {
  $associatedDevice = fs_courtesy_shadow_associated($pdo, (string)$username, $mac);
}
if (($centralEnforce ? $centralRequiresAssociation : $partnerRequireAuth) && $mac !== '') {
  $st = $pdo->prepare($centralEnforce
    ? 'SELECT 1 FROM clientes_dispositivos WHERE username=? AND UPPER(mac)=? AND is_active=1 LIMIT 1'
    : 'SELECT 1 FROM clientes_dispositivos WHERE mac=? LIMIT 1');
  $st->execute($centralEnforce ? [(string)$username, strtoupper($mac)] : [$mac]);
  if (!$st->fetchColumn()) {
    $shadow(['allowed'=>false,'code'=>'LEGACY_ASSOCIATION_REQUIRED']);
    echo json_encode(['ok'=>false,'code'=>'ASSOC_REQUIRED','login'=>'/portal/login.php?assoc=1']);
    exit;
  }
}

// Checa cooldown
if (!$centralEnforce && $coolMin > 0) {
  $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(granted_at) AS ts FROM ad_grants WHERE mac=? ORDER BY granted_at DESC LIMIT 1');
  $st->execute([$macKey]);
  if ($lastTs = (int)$st->fetchColumn()) {
    $next = $lastTs + ($coolMin * 60);
    if (time() < $next) {
      $shadow(['allowed'=>false,'code'=>'LEGACY_COOLDOWN','retry_at'=>$next]);
      echo json_encode(['ok'=>false,'code'=>'COOLDOWN','retry_at'=>$next]);
      exit;
    }
  }
}

// Checa limite diário por dispositivo (janela móvel de 24h)
if (!$centralEnforce && $dailyCap > 0) {
  $st = $pdo->prepare('SELECT COUNT(*) FROM ad_grants WHERE mac = ? AND granted_at >= (NOW() - INTERVAL 24 HOUR)');
  $st->execute([$macKey]);
  $count24h = (int)$st->fetchColumn();
  if ($count24h >= $dailyCap) {
    // próxima disponibilidade: 24h após a mais antiga dentro da janela atual
    $st2 = $pdo->prepare('SELECT UNIX_TIMESTAMP(MIN(granted_at)) FROM ad_grants WHERE mac = ? AND granted_at >= (NOW() - INTERVAL 24 HOUR)');
    $st2->execute([$macKey]);
    $minTs = (int)$st2->fetchColumn();
    $retry = $minTs ? ($minTs + 24*60*60) : (time() + 24*60*60);
    $shadow(['allowed'=>false,'code'=>'LEGACY_DAILY_CAP','retry_at'=>$retry]);
    echo json_encode(['ok'=>false,'code'=>'DAILY_CAP','retry_at'=>$retry]);
    exit;
  }
}

// Novo fluxo: cria um usuário temporário no RADIUS com Session-Timeout e link de conexão
try {
  if ($centralEnforce) {
    // Recompõe as exclusões com o mesmo detector usado no shadow para cobrir
    // grupos ISP_* e planos premium além do grupo legado exato ISP_UNL.
    if ($username) {
      $radiusState = fs_courtesy_shadow_radius_state((string)$username);
      $isProvider = $isProvider || !empty($radiusState['is_provider']);
      $hasActivePaid = $hasActivePaid || !empty($radiusState['has_active_paid']);
    }
    $centralDeviceKey = strncmp($macKey, 'USR:', 4) === 0 ? '' : $macKey;
    $courtesyContext = [
      'partner_id' => $partnerId,
      'hotspot_id' => $hotspotId,
      'portal' => 'qr_ad',
      'source' => 'ad_grant',
      'device_key' => $centralDeviceKey,
      'mac' => $mac,
      'ip' => $ip,
      'account_key' => (string)$username,
      'account_username' => (string)$username,
      'associated_device' => $associatedDevice,
      'ad_completed' => $centralAdCompleted,
      'has_active_paid' => $hasActivePaid,
      'is_provider' => $isProvider,
    ];
    $courtesyContext['idempotency_key'] = fs_courtesy_access_idempotency($courtesyContext, 'ad_grant');
    $centralResult = fs_courtesy_access_issue($pdo, $courtesyContext);
    if (empty($centralResult['handled'])) {
      throw new RuntimeException('O rollout central perdeu o modo enforce durante a concessão.');
    }
    if (empty($centralResult['allowed']) || empty($centralResult['grant']['username']) || empty($centralResult['grant']['password'])) {
      $status = in_array((string)($centralResult['code'] ?? ''), ['ENFORCEMENT_FAILED', 'ENFORCEMENT_UNAVAILABLE'], true) ? 503 : 409;
      http_response_code($status);
      echo json_encode([
        'ok' => false,
        'code' => (string)($centralResult['code'] ?? 'COURTESY_DENIED'),
        'error' => (string)($centralResult['message'] ?? 'Cortesia não disponível.'),
        'retry_at' => $centralResult['retry_at'] ?? null,
      ]);
      exit;
    }
    $tmpUser = (string)$centralResult['grant']['username'];
    $tmpPass = (string)$centralResult['grant']['password'];
    $minutes = max(1, (int)($centralResult['grant']['minutes'] ?? $minutes));
    $_SESSION['courtesy_active_grant'] = [
      'public_id' => (string)($centralResult['grant']['public_id'] ?? ''),
      'portal' => 'qr_ad',
      'partner_id' => $partnerId,
      'hotspot_id' => $hotspotId,
      'account_username' => (string)$username,
    ];
    if ($adProofToken !== '') {
      unset($_SESSION['courtesy_ad_proofs'][$adProofToken]);
    }
  } else {
    if (!isset($rad)) { $rad = rad_db(); }
    $rad->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Credencial temporária legada, mantida somente até o rollout deste portal.
    $tmpUser = 'ad' . date('ymd') . substr(bin2hex(random_bytes(3)), 0, 5);
    $tmpPass = substr(bin2hex(random_bytes(8)), 0, 10);
    $sessTimeout = max(60, (int)$minutes * 60);

    $rad->beginTransaction();
    $stC = $rad->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)");
    $stC->execute([$tmpUser, 'Cleartext-Password', $tmpPass]);
    $stR = $rad->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?,?,':=',?)");
    $stR->execute([$tmpUser, 'Session-Timeout', (string)$sessTimeout]);
    $stR2 = $rad->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?,?,':=',?)");
    $stR2->execute([$tmpUser, 'Acct-Interim-Interval', '60']);
    try {
      $grp = settings_get('ad_temp_group', getenv('AD_TEMP_GROUP') ?: '');
      if ($grp) {
        $stG = $rad->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?,?,1)");
        $stG->execute([$tmpUser, $grp]);
      }
    } catch (\Throwable $e) {}
    $rad->commit();

    $st = $pdo->prepare('INSERT INTO ad_grants (username, mac, ip, minutes) VALUES (?, ?, ?, ?)');
    $st->execute([$tmpUser, $macKey, $ip, $minutes]);
    $shadow(['allowed'=>true,'code'=>'LEGACY_GRANTED','minutes'=>$minutes]);
  }

  // Gera link de conexão one-click SEMPRE via token (conect.php), para respeitar dns_name do parceiro
  $login_url = '';
  {
    try {
      $app = db();
      $app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      $app->exec("SET time_zone='-04:00'");
      // gera code curto
      $code = null;
      for ($t=0; $t<5; $t++) {
        $try = '';
        for ($i=0; $i<8; $i++) { $try .= (string)random_int(0,9); }
        $chk = $app->prepare('SELECT 1 FROM login_tokens WHERE code=?');
        $chk->execute([$try]);
        if (!$chk->fetchColumn()) { $code = $try; break; }
      }
      if ($code) {
        // Captura contexto do parceiro para persistir no token
        $partnerCode = null; $partnerDns = null; $hotspotId = null;
        try {
          $fastId = trim((string)($_SESSION['portal_fast_id'] ?? ''));
          if ($fastId !== '') $partnerCode = $fastId;
          if ($partnerCode !== null && $partnerCode !== '') {
            $hotspot=fs_partner_hotspot_resolve($app,(string)$partnerCode,false,true);
            if($hotspot){$hotspotId=fs_partner_hotspot_id($hotspot);$partnerCode=fs_partner_hotspot_public_code($hotspot);$partnerDns=(string)($hotspot['dns_name']??'');}
            else{$stP = $app->prepare('SELECT dns_name FROM partners WHERE (code=? OR id=?) AND active=1 LIMIT 1');$stP->execute([$partnerCode, ctype_digit((string)$partnerCode) ? (int)$partnerCode : 0]);$partnerDns = (string)($stP->fetchColumn() ?: '');}
          }
          if ($partnerDns === '') {
            $hostHdr = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
            if ($hostHdr !== '') {
              $stH = $app->prepare('SELECT dns_name, code FROM partners WHERE dns_name=? AND active=1 LIMIT 1');
              $stH->execute([$hostHdr]);
              if ($rH = $stH->fetch(PDO::FETCH_ASSOC)) {
                $partnerDns = (string)($rH['dns_name'] ?? '');
                if (!$partnerCode && !empty($rH['code'])) $partnerCode = (string)$rH['code'];
              }
            }
          }
        } catch (\Throwable $eCtx) { /* ignore */ }
        $ipTok = $ip ?: ($_SESSION['hotspot_device_info']['ip'] ?? null);
        $macTok = $mac ?: ($_SESSION['hotspot_device_info']['mac'] ?? null);
        $ttlMin = max(1, (int)getenv('LOGIN_TOKEN_TTL_MIN') ?: 10);
        $ins = $app->prepare('INSERT INTO login_tokens (code, username, ip, mac, partner_code, hotspot_id, partner_dns, expires_at, created_at) VALUES (?,?,?,?,?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), UTC_TIMESTAMP())');
        $ins->execute([$code, $tmpUser, $ipTok, $macTok, $partnerCode, $hotspotId, $partnerDns, $ttlMin]);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
          $login_url = $scheme . '://' . $host . '/portal/conect.php?id=' . rawurlencode($code) . '&open=1';
        } else {
          $login_url = '/portal/conect.php?id=' . rawurlencode($code) . '&open=1';
        }
      }
    } catch (\Throwable $e) { /* segue para outros fallbacks */ }
  }
  // Fallback: URL direta do hotspot com user/pass
  // Removido fallback direto para HS_LOGIN_URL para garantir uso de conect.php
  // Se ainda não temos URL, salva credenciais na sessão para o fluxo hotspot_do_login.php
  if ($login_url === '' || $login_url === null) {
  // sessão já inicializada por session_boot.php no topo
    $_SESSION['hotspot_auto'] = ['username' => $tmpUser, 'password' => $tmpPass];
  }

  if($adProofToken!==''&&strlen($adProofToken)===64){
    try{fs_ad_delivery_attach_access($pdo,$adProofToken,$tmpUser);}catch(Throwable $e){error_log('[ad delivery attach] '.get_class($e));}
  }

  if ($adProofToken !== '') unset($_SESSION['courtesy_ad_proofs'][$adProofToken]);
  echo json_encode(['ok'=>true,'minutes'=>$minutes,'ip'=>$ip,'mac'=>$mac,'key'=>$macKey,'temp_user'=>$tmpUser,'login_url'=>$login_url]);
} catch (Throwable $e) {
  if (isset($rad) && $rad instanceof PDO && $rad->inTransaction()) {
    $rad->rollBack();
  }
  error_log('[ad grant] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Não foi possível liberar o acesso agora.']);
}
