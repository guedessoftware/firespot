<?php
// Gera um link curto de conexão para uma identidade RADIUS existente.
// A rota é restrita a administrador, ao próprio cliente ou a uma chamada
// interna autenticada. Webhooks devem preferir o helper local, sem HTTP.

@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/radius_db.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/public_url.php';
require_once __DIR__ . '/../../app/partner_hotspots.php';
require_once __DIR__ . '/../../app/admin_auth.php';

function login_token_error(int $status, string $error): void
{
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $error]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  login_token_error(405, 'method_not_allowed');
}

// Lê JSON
$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
if (!is_array($j)) $j = $_POST;

// Parâmetros
$username = preg_replace('/\D+/', '', (string) ($j['username'] ?? ''));  // CPF
$ttl_min = max(1, min(30, (int) ($j['ttl_min'] ?? 10)));                // 1..30 min
$ip = isset($j['ip']) ? trim((string) $j['ip']) : null;
$mac = isset($j['mac']) ? trim((string) $j['mac']) : null;

if ($username === '') {
  login_token_error(400, 'missing_username');
}

$configuredInternalKey = trim((string) env('INTERNAL_API_KEY', ''));
$providedInternalKey = trim((string) ($_SERVER['HTTP_X_INTERNAL_KEY'] ?? ''));
$isInternal = strlen($configuredInternalKey) >= 32
  && $providedInternalKey !== ''
  && hash_equals($configuredInternalKey, $providedInternalKey);
$isAdmin = admin_is_authenticated();
$sessionUsername = preg_replace('/\D+/', '', (string) ($_SESSION['cliente_username'] ?? ''));
$isOwner = $sessionUsername !== '' && hash_equals($sessionUsername, $username);

if (!$isInternal && !$isAdmin && !$isOwner) {
  login_token_error(401, 'unauthorized');
}

if (!$isInternal) {
  $csrf = (string) ($j['csrf_token'] ?? $j['csrf'] ?? $j['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if (!csrf_check($csrf)) login_token_error(403, 'invalid_csrf');
}

// Limite simples por sessão. Chamadas internas já dependem de chave longa e
// possuem idempotência no domínio que originou o link.
if (!$isInternal) {
  $now = time();
  $attempts = array_values(array_filter(
    (array) ($_SESSION['login_token_attempts'] ?? []),
    static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
  ));
  if (count($attempts) >= 10) login_token_error(429, 'rate_limited');
  $attempts[] = $now;
  $_SESSION['login_token_attempts'] = $attempts;
}

// Um cliente nunca escolhe o IP/MAC de outra pessoa. Administradores e
// chamadas internas ainda podem criar links assistidos com contexto explícito.
if ($isOwner && !$isAdmin && !$isInternal) {
  $ip = trim((string) ($_SESSION['hotspot_device_info']['ip'] ?? '')) ?: null;
  $mac = trim((string) ($_SESSION['hotspot_device_info']['mac'] ?? '')) ?: null;
}

// Função para gerar código numérico curto (8..10 dígitos)
function gen_code($len = 8)
{
  $n = '';
  for ($i = 0; $i < $len; $i++)
    $n .= (string) random_int(0, 9);
  // evita começar com 0 (opcional)
  if ($n[0] === '0')
    $n[0] = (string) random_int(1, 9);
  return $n;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $radius = fs_radius_db();
  $credential = $radius->prepare("SELECT 1 FROM radcheck WHERE username=? AND attribute='Cleartext-Password' LIMIT 1");
  $credential->execute([$username]);
  if (!$credential->fetchColumn()) login_token_error(404, 'radius_identity_not_found');

  // cria um code único (tenta algumas vezes)
  $code = null;
  for ($t = 0; $t < 5; $t++) {
    $try = gen_code(8); // tamanho do "número" que vai por WhatsApp
    $st = $pdo->prepare('SELECT 1 FROM login_tokens WHERE code=?');
    $st->execute([$try]);
    if (!$st->fetchColumn()) {
      $code = $try;
      break;
    }
  }
  if (!$code)
    throw new RuntimeException('Falha ao gerar código único');

  runtime_schema_require($pdo, 'login_tokens', ['code','username','partner_code','partner_dns','expires_at']);

  // capturar contexto do parceiro
  $partnerCode = isset($_SESSION['portal_fast_id']) ? (string)$_SESSION['portal_fast_id'] : null;
  $partnerDns = null;
  $hotspotId = null;
  if ($partnerCode) {
    try {
      $hotspot=fs_partner_hotspot_resolve($pdo,$partnerCode,false,true);
      if($hotspot){$hotspotId=fs_partner_hotspot_id($hotspot);$partnerCode=fs_partner_hotspot_public_code($hotspot);$partnerDns=(string)($hotspot['dns_name']??'');}
      else{$stp = $pdo->prepare('SELECT dns_name FROM partners WHERE (code=? OR id=?) AND active=1 LIMIT 1');$stp->execute([$partnerCode, ctype_digit($partnerCode)?(int)$partnerCode:0]);$partnerDns = (string)($stp->fetchColumn() ?: '');}
    } catch (\Throwable $e) {}
  }
  if (!$partnerDns) {
    $hostHdr = $_SERVER['HTTP_HOST'] ?? '';
    if ($hostHdr !== '') {
      try {
        $stH = $pdo->prepare('SELECT dns_name, code FROM partners WHERE dns_name=? AND active=1 LIMIT 1');
        $stH->execute([$hostHdr]);
        if ($rH = $stH->fetch(PDO::FETCH_ASSOC)) { $partnerDns = (string)($rH['dns_name'] ?? ''); if (!$partnerCode && !empty($rH['code'])) $partnerCode = (string)$rH['code']; }
      } catch (\Throwable $e) {}
    }
  }

  $st = $pdo->prepare('
  INSERT INTO login_tokens (code, username, ip, mac, partner_code, hotspot_id, partner_dns, expires_at, created_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), UTC_TIMESTAMP())
');
  $st->execute([$code, $username, $ip, $mac, $partnerCode, $hotspotId, $partnerDns, $ttl_min]);


  // Monte o link HTTPS do seu site:
  // Use o FQDN do seu portal (com SSL):
  $base = fs_public_base_url($pdo);
  $link = $base . '/portal/conect.php?id=' . rawurlencode($code) . '&open=1';

  echo json_encode([
    'ok' => true,
    'code' => $code,
    'ttl_min' => $ttl_min,
    'link' => $link
  ]);
} catch (Throwable $e) {
  error_log('[login_token_create] ' . get_class($e));
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'login_token_create_failed']);
}
