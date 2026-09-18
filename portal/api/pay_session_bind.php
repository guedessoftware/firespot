<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// SEC-004: o vínculo legado aceitava endereço e duração enviados pelo
// navegador. Pedidos atuais preservam o dispositivo no próprio vip_orders.
http_response_code(410);
echo json_encode(['ok' => false, 'err' => 'legacy_session_binding_retired']);
exit;

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/vip_order_session.php';

/* Localiza db.php (ajuste se seu projeto usa outro caminho) */
require_once __DIR__ . '/../../app/db.php';

/* Helpers de sessão/IP */
function sess_get($path)
{
  $ref = &$_SESSION;
  foreach (explode('.', $path) as $k) {
    if (!is_array($ref) || !array_key_exists($k, $ref))
      return null;
    $ref = &$ref[$k];
  }
  return $ref;
}
function first_ip_from_xff($s)
{
  $parts = array_map('trim', explode(',', (string) $s));
  return $parts[0] ?? '';
}
function normalize_ip($ip)
{
  $ip = trim((string) $ip);
  if ($ip === '')
    return '';
  if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $ip))
    $ip = preg_replace('/:(\d+)$/', '', $ip);
  $ip = trim($ip, '[]');
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
    return $ip;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
    return $ip;
  return '';
}
function derive_server_ip()
{
  $cands = [];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
    $cands[] = first_ip_from_xff($_SERVER['HTTP_X_FORWARDED_FOR']);
  if (!empty($_SERVER['HTTP_X_REAL_IP']))
    $cands[] = $_SERVER['HTTP_X_REAL_IP'];
  if (!empty($_SERVER['REMOTE_ADDR']))
    $cands[] = $_SERVER['REMOTE_ADDR'];
  foreach ($cands as $c) {
    $ip = normalize_ip($c);
    if ($ip !== '')
      return $ip;
  }
  return '';
}

/* Entrada */
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in))
  $in = $_POST;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'err' => 'method_not_allowed']);
  exit;
}

$csrfToken = (string) ($in['csrf_token'] ?? $in['csrf'] ?? '');
if (!csrf_check($csrfToken)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'err' => 'csrf_invalid']);
  exit;
}

$token = trim((string) ($in['token'] ?? ''));
$ipIn = normalize_ip($in['ip'] ?? '');
$mac = strtoupper(trim((string) ($in['mac'] ?? '')));
$minutes = isset($in['minutes']) ? (int) $in['minutes'] : null;
$phone = preg_replace('/\D+/', '', (string) ($in['phone'] ?? ''));


if ($token === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'err' => 'token ausente']);
  exit;
}

if (!vip_order_session_owns($token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'err' => 'order_session_mismatch']);
  exit;
}

/* >>> ORDEM DE PREFERÊNCIA PARA IP <<< 
   1) Body (enviado pelo front)
   2) Sessão (variáveis do CAPT)
   3) Cabeçalhos/REMOTE_ADDR
*/
$ipSess = (sess_get('client_ip') ?: sess_get('hotspot.ip') ?: sess_get('CLIENT_IP') ?: sess_get('capt.ip') ?: '');
$ipSrv = derive_server_ip();
$ip = $ipIn ?: normalize_ip($ipSess) ?: $ipSrv;

/* Agora, EXIJA IP — nada de gravar NULL */
if ($ip === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'err' => 'IP não encontrado (body, sessão ou REMOTE_ADDR)']);
  exit;
}

/* Normaliza MAC opcional */
if ($mac !== '') {
  $mac = str_replace('-', ':', $mac);
  if (preg_match('/^[0-9A-F]{12}$/', $mac))
    $mac = implode(':', str_split($mac, 2));
  if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac))
    $mac = '';
}
if ($mac === '') {
  $mac = '00:00:00:00:00:00';
}

try {
  /* DB */
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /* UPSERT com IP obrigatório */
  $sql = "INSERT INTO payments_session (token, ip, mac, phone, minutes)
          VALUES (:t,:ip,:mac,:ph,:m)
          ON DUPLICATE KEY UPDATE
            ip=VALUES(ip),
            mac=IFNULL(NULLIF(VALUES(mac),''), mac),
            phone=IFNULL(NULLIF(VALUES(phone),''), phone),
            minutes=IFNULL(VALUES(minutes), minutes)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':t' => $token,
    ':ip' => $ip,
    ':mac' => $mac,
    ':ph' => ($phone ?: null),
    ':m' => $minutes
  ]);

  echo json_encode(['ok' => true, 'ip' => $ip, 'source' => ($ipIn ? 'body' : ($ipSess ? 'session' : 'server'))]);
} catch (Throwable $e) {
  error_log('[pay_session_bind] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'err' => 'db_error']);
}
