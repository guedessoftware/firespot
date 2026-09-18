<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// SEC-004: a janela legada usava o RouterOS global e não garantia o NAS da
// instalação do pedido. O fluxo V3/RADIUS é o substituto canônico.
http_response_code(410);
echo json_encode(['ok' => false, 'error' => 'legacy_trial_retired']);
exit;

$ROOT = realpath(__DIR__ . '/..');     // .../hotspot
$APP  = $ROOT . '/app';

require_once __DIR__ . '/../../app/lib/routeros.php'; // ros_exec(), ros_safe_token()
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/vip_order_session.php';

function trial_error(int $status, string $error): void {
  http_response_code($status);
  echo json_encode(['ok'=>false, 'error'=>$error]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  trial_error(405, 'method_not_allowed');
}

/* -------- Helpers -------- */
function read_json_or_post() {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  if (is_array($j)) return $j;
  if (!empty($_POST)) return $_POST;
  if (!empty($_GET))  return $_GET;
  return [];
}

function first_ip_from_xff($s) {
  // pega o primeiro IP de uma lista "client, proxy1, proxy2"
  $parts = array_map('trim', explode(',', $s));
  return $parts[0] ?? $s;
}

function normalize_ip($ip) {
  $ip = trim((string)$ip);
  if ($ip === '') return '';
  // se vier com porta IPv4 "1.2.3.4:5678", remova a porta
  if (preg_match('/^\\d+\\.\\d+\\.\\d+\\.\\d+:\\d+$/', $ip)) {
    $ip = preg_replace('/:(\\d+)$/', '', $ip);
  }
  // IPv6 pode ter "[]:port" em proxies; remove [ ] e :port
  $ip = trim($ip, "[]");
  if (strpos($ip, ':') !== false && substr_count($ip, ':') > 1) {
    // IPv6 — se tiver porta final, remova ":12345"
    if (preg_match('/\\]:\\d+$/', $ip)) {
      $ip = preg_replace('/\\]:\\d+$/', ']', $ip);
      $ip = trim($ip, "[]");
    }
  }
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return $ip;
  return '';
}

function derive_client_ip() {
  // tenta descobrir automaticamente
  $cands = [];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $cands[] = first_ip_from_xff($_SERVER['HTTP_X_FORWARDED_FOR']);
  if (!empty($_SERVER['HTTP_X_REAL_IP']))       $cands[] = $_SERVER['HTTP_X_REAL_IP'];
  if (!empty($_SERVER['REMOTE_ADDR']))          $cands[] = $_SERVER['REMOTE_ADDR'];
  foreach ($cands as $cand) {
    $ip = normalize_ip($cand);
    if ($ip !== '') return $ip;
  }
  return '';
}

function normalize_mac($mac) {
  $mac = trim((string)$mac);
  if ($mac === '') return '';
  $mac = str_replace('-', ':', $mac);
  $mac = strtoupper($mac);
  // Se vier colado "AABBCCDDEEFF"
  if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
    $mac = implode(':', str_split($mac, 2));
  }
  if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) return $mac;
  return '';
}

function try_arp_lookup_mac($ip) {
  // Precisa de acesso SSH ao MikroTik. Liga com ROS_TRY_ARP=1 no .env
  if ((int)getenv('ROS_TRY_ARP') !== 1) return '';
  try {
    $res = ros_exec([ '/ip arp print where address=' . $ip ]);
    if (empty($res['ok'])) return '';
    $txt = implode("\n", $res['out']);
    // saída típica contém "MAC-Address: XX:XX:..."; vamos capturar o primeiro MAC
    if (preg_match('/([0-9A-Fa-f]{2}([:\\-][0-9A-Fa-f]{2}){5})/', $txt, $m)) {
      return normalize_mac($m[1]);
    }
  } catch (Throwable $e) {
    // silencioso
  }
  return '';
}

/* -------- Entrada -------- */
$in = read_json_or_post();
$csrf = (string)($in['csrf_token'] ?? $in['csrf'] ?? $in['_csrf'] ?? '');
if (!csrf_check($csrf)) trial_error(403, 'invalid_csrf');

$orderRef = trim((string)($in['ref'] ?? ''));
if ($orderRef === '' || !vip_order_session_owns($orderRef)) {
  trial_error(403, 'order_session_mismatch');
}

try {
  $pdo = db();
  $orderStmt = $pdo->prepare("SELECT status,device_ip,device_mac,payment_expires_at
      FROM vip_orders WHERE external_ref=? LIMIT 1");
  $orderStmt->execute([$orderRef]);
  $trialOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
  if (!$trialOrder) trial_error(404, 'order_not_found');
  if ((string)($trialOrder['status'] ?? '') !== 'pending') trial_error(409, 'order_not_pending');
  if (!empty($trialOrder['payment_expires_at']) && strtotime((string)$trialOrder['payment_expires_at']) <= time()) {
    trial_error(409, 'order_expired');
  }
} catch (Throwable $e) {
  error_log('[trial_start order] ' . get_class($e));
  trial_error(500, 'trial_context_failed');
}

$ip   = normalize_ip((string)($trialOrder['device_ip'] ?? ''));
$mac  = normalize_mac((string)($trialOrder['device_mac'] ?? ''));
$mins = (int)(getenv('TRIAL_MINUTES_DEFAULT') ?: 10);
if ($mins <= 0) $mins = 10;
if ($mins > 120) $mins = 120; // segurança: máx 2h

if ($ip === '') {
  $ip = normalize_ip((string)($_SESSION['hotspot_device_info']['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
}
if ($ip === '') {
  trial_error(400, 'device_ip_unavailable');
}

// Tenta descobrir MAC via ARP se não veio válido
if ($mac === '') {
  $mac = normalize_mac((string)($_SESSION['hotspot_device_info']['mac'] ?? ''));
}
if ($mac === '') {
  // fallback para DeviceID persistente via cookie
  $did = isset($_COOKIE['fs_did']) ? trim((string)$_COOKIE['fs_did']) : '';
  if ($did !== '') {
    $mac = 'DID:' . preg_replace('/[^A-Fa-f0-9]/','', $did);
  }
}

$activeTrial = $_SESSION['payment_trial'][$orderRef] ?? null;
if (is_array($activeTrial) && (int)($activeTrial['expires_at'] ?? 0) > time()) {
  echo json_encode([
    'ok'=>true,
    'already_started'=>true,
    'minutes'=>max(1, (int)ceil(((int)$activeTrial['expires_at'] - time()) / 60)),
    'ip'=>$ip,
    'mac'=>$mac,
  ]);
  exit;
}

/* -------- Exigir associação do dispositivo -------- */
try {
  // Checa política do parceiro, se contexto existir
  $partnerRequireAuth = true; // padrão: exige login quando sem parceiro
  try {
    $fastId = isset($_SESSION) ? (trim((string)($_SESSION['portal_fast_id'] ?? ''))) : '';
    if ($fastId !== '') {
      $hasPartners = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
      if ($hasPartners) {
        $stP = $pdo->prepare('SELECT require_auth FROM partners WHERE code=? LIMIT 1');
        $stP->execute([$fastId]);
        $partnerRequireAuth = ((int)($stP->fetchColumn() ?? 1) === 1);
      }
    }
  } catch (\Throwable $e) { /* usa padrão */ }

  if ($partnerRequireAuth && $mac !== '') {
    $st = $pdo->prepare('SELECT 1 FROM clientes_dispositivos WHERE mac=? LIMIT 1');
    $st->execute([$mac]);
    $hasAssoc = (bool)$st->fetchColumn();
    if (!$hasAssoc) {
      // sinaliza ao front para redirecionar ao login com associação
      echo json_encode([
        'ok'    => false,
        'code'  => 'ASSOC_REQUIRED',
        'login' => '/portal/login.php?assoc=1'
      ]);
      exit;
    }
  }
} catch (\Throwable $e) {
  // Se der erro na checagem de política, não bloqueia — segue com grant
}

/* -------- Aplica no MikroTik -------- */
// Criamos um script com timeout que adiciona ip-binding bypassed e remove depois
$token = 'trial-' . ros_safe_token(12);
$delay = $mins * 60;

$script = ":local t \"$token\";\n"
        . ":local ip \"$ip\";\n"
        . ":local mac \"$mac\";\n"
        // remove bindings anteriores com mesmo ip/mac/token (idempotência leve)
        . "/ip hotspot ip-binding remove [find where address=$ip or mac-address=$mac or comment=$t];\n"
        . "/ip hotspot ip-binding add to-address=$ip mac-address=$mac type=bypassed comment=$t;\n"
        . ":delay $delay;\n"
        . "/ip hotspot ip-binding remove [find mac-address=$mac];\n"
        . "/system script remove [find name=$t];\n";

try {
  $escaped = str_replace(['\\','"'], ['\\\\','\\"'], $script);
  $cmds = [
    "/system script add name=$token source=\"$escaped\"",
    "/system script run $token"
  ];
  $r = ros_exec($cmds);
  if (empty($r['ok'])) throw new Exception($r['err'] ?: 'falha ao executar no roteador');

  $_SESSION['payment_trial'][$orderRef] = [
    'expires_at' => time() + $delay,
    'ip' => $ip,
    'mac' => $mac,
  ];

  // (Opcional) se você usa a tabela payments_session para amarrar esta sessão:
  // Salve token->ip/mac para o webhook aprovar e promover
  // require_once $APP . '/db.php'; $pdo->prepare("INSERT IGNORE INTO payments_session (token, ip, mac, status) VALUES (?, ?, ?, 'pending')")->execute([$token, $ip, $mac]);

  echo json_encode(['ok'=>true, 'token'=>$token, 'minutes'=>$mins, 'ip'=>$ip, 'mac'=>$mac]);
} catch (Throwable $e) {
  error_log('[trial_start routeros] ' . get_class($e));
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'trial_activation_failed']);
}
