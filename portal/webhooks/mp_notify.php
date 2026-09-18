<?php
// SEC-005: webhook legado com modo debug público retirado.
http_response_code(410);
exit;

// /portal/webhooks/mp_notify.php
// Confirma pagamento, aplica VIP (idempotente), gera link de conexão (SSL)
// e envia WhatsApp — agora tudo em TZ -04:00 (America/Manaus).

http_response_code(200);
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/mp_client.php'; // mp_get_payment()
require_once __DIR__ . '/../../app/lib/promo_api.php'; // promo_api_send()
require_once __DIR__ . '/../../app/helpers.php'; // Funções centralizadas
require_once __DIR__ . '/../../app/public_url.php';

function norm_msisdn_br($tel){
  $d = only_digits($tel);
  if ($d === '') return '';
  if (strpos($d,'55') !== 0) $d = '55'.$d;
  return $d;
}

function http_post_json($url, $payload, $headers = []){
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $default = ['Content-Type: application/json', 'Accept: application/json'];
  $headers = array_merge($default, $headers);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $resp];
  }
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'POST',
      'header'  => implode("\r\n", $headers),
      'content' => $body,
      'ignore_errors' => true,
      'timeout' => 10,
    ]
  ]);
  $resp = @file_get_contents($url, false, $ctx);
  $status = 0;
  if (isset($http_response_header) && preg_match('#\s(\d{3})\s#', $http_response_header[0] ?? '', $m)) {
    $status = (int)$m[1];
  }
  return [$status, $resp];
}

// Envio centralizado usando promo_api_send, que lê configuracoes do painel
function send_promo($to_msisdn, $text){
  // normaliza para formato internacional, assumindo Brasil se não houver DDI
  try {
    if (function_exists('promo_clean_number')) {
      $n = promo_clean_number($to_msisdn);
    } else {
      $n = preg_replace('/[^+0-9]/','', (string)$to_msisdn);
    }
    if ($n !== '' && $n[0] !== '+') {
      if (strpos($n,'55') === 0 && strlen($n) >= 12) $to_msisdn = '+'.$n; else $to_msisdn = '+55'.$n;
    } else if ($n !== '') {
      $to_msisdn = $n;
    }
  } catch (\Throwable $e) {}
  return promo_api_send($to_msisdn, $text);
}

/**
 * Aplica VIP idempotente:
 * - Seta Max-All-Session = used_total + vip_seconds.
 * - Garante grupo (se existir).
 * - Marca vip_applied_at (local -04:00) se a coluna existir.
 */
function apply_vip_once(PDO $pdo, array $orderRow){
  // TZ -04:00 nesta conexão
  $pdo->exec("SET time_zone='-04:00'");

  try {
    $q = $pdo->prepare("SELECT vip_applied_at FROM vip_orders WHERE id=?");
    $q->execute([(int)$orderRow['id']]);
    $applied = $q->fetchColumn();
    if (!empty($applied)) return false; // já aplicado
  } catch (\Throwable $e) { /* coluna pode não existir */ }

  $username    = trim((string)$orderRow['username']);
  $vip_minutes = (int)$orderRow['duracao_min'];
  $vip_seconds = max(0, $vip_minutes * 60);
  $groupName   = trim((string)($orderRow['grupo'] ?? ''));

  if ($username === '' || $vip_seconds <= 0) return false;

  $st = $pdo->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
  $st->execute([$username]);
  $used = (int)$st->fetchColumn();

  $targetMax = $used + $vip_seconds;

  $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Max-All-Session'")->execute([$username]);
  $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
      ->execute([$username, 'Max-All-Session', (string)$targetMax]);

  if ($groupName !== '') {
    $s = $pdo->prepare("SELECT 1 FROM radusergroup WHERE username=? AND groupname=? LIMIT 1");
    $s->execute([$username, $groupName]);
    if (!$s->fetchColumn()) {
      $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?,?,?)")
          ->execute([$username, $groupName, 1]);
    }
  }

  try {
    // Agora marca com horário local
    $pdo->prepare("UPDATE vip_orders SET vip_applied_at=NOW(), updated_at=NOW() WHERE id=?")
        ->execute([(int)$orderRow['id']]);
  } catch (\Throwable $e) {}

  return true;
}

/**
 * Gera link de conexão chamando sua API /portal/api/login_token_create.php.
 * Se a API falhar, cria o token direto no DB — todos com TZ -04:00 (NOW()).
 */
function build_connect_link_for($username, $ttl_min = 10){
  $publicBase = fs_public_base_url(db());
  $api = $publicBase . '/portal/api/login_token_create.php';

  $headers = [];
  $internalKey = envv('INTERNAL_API_KEY', '');
  if ($internalKey !== '') $headers[] = 'X-Internal-Key: ' . $internalKey;

  list($status, $resp) = http_post_json($api, [
    'username' => $username,
    'ttl_min'  => max(1, min(30, (int)$ttl_min)),
  ], $headers);

  if ($status === 200) {
    $j = json_decode($resp, true);
    if (!empty($j['ok']) && !empty($j['link'])) return $j['link'];
  }

  // Fallback: grava direto com NOW() e time_zone -04:00
  try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone='-04:00'");

    // gera code único
    $code = null;
    for ($t=0;$t<5;$t++){
      $n=''; for($i=0;$i<8;$i++) $n .= (string)random_int(0,9);
      if ($n[0]==='0') $n[(int)0] = (string)random_int(1,9);
      $s = $pdo->prepare("SELECT 1 FROM login_tokens WHERE code=?");
      $s->execute([$n]);
      if (!$s->fetchColumn()){ $code = $n; break; }
    }
    if (!$code) throw new RuntimeException('no code');

    $ttl = max(1, min(30, (int)$ttl_min));
    $ins = $pdo->prepare("
      INSERT INTO login_tokens (code, username, ip, mac, expires_at, created_at)
      VALUES (?, ?, NULL, NULL, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())
    ");
    $ins->execute([$code, $username, $ttl]);

  return $publicBase . '/portal/conect.php?id=' . rawurlencode($code) . '&open=1';
  } catch (\Throwable $e) {
    return '';
  }
}

// ========= Handler =========
try {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  $payment_id = '';
  if (is_array($j) && isset($j['data']['id'])) $payment_id = (string)$j['data']['id'];
  if ($payment_id === '' && isset($_GET['data_id'])) $payment_id = (string)$_GET['data_id'];
  if ($payment_id === '') exit;

  $pay = mp_get_payment($payment_id);
  if (!isset($pay['status']) || $pay['status'] !== 'approved') exit;

  if (empty($pay['external_reference'])) exit;
  $ref = (string)$pay['external_reference'];

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  // TZ -04:00 nesta conexão
  $pdo->exec("SET time_zone='-04:00'");

  $st = $pdo->prepare("SELECT * FROM vip_orders WHERE external_ref=? LIMIT 1");
  $st->execute([$ref]);
  $order = $st->fetch();
  if (!$order) exit;

  if ($order['status'] !== 'paid') {
    $up = $pdo->prepare('UPDATE vip_orders SET status="paid", paid_at=NOW(), updated_at=NOW(), mp_payment_id=? WHERE id=?');
    $up->execute([$payment_id, (int)$order['id']]);
    $st->execute([$ref]);
    $order = $st->fetch();
  }

  apply_vip_once($pdo, $order);

  $username = trim((string)$order['username']);
  $link = ($username !== '') ? build_connect_link_for($username, 10) : '';

  // Monta mensagem amigável com valor e minutos
  $telefone = trim((string)$order['telefone']);
  $valorCent = isset($order['valor_centavos']) ? (int)$order['valor_centavos'] : null;
  $min = (int)$order['duracao_min'];
  // Fallback: se não houver valor/telefone na ordem, tenta payments_session
  if (($telefone === '' || $valorCent === null)) {
    try {
      $ps = $pdo->prepare("SELECT phone, amount_centavos FROM payments_session WHERE token=? LIMIT 1");
      $ps->execute([$ref]);
      if ($row2 = $ps->fetch()) {
        if ($telefone === '' && !empty($row2['phone'])) $telefone = (string)$row2['phone'];
        if ($valorCent === null && isset($row2['amount_centavos'])) $valorCent = (int)$row2['amount_centavos'];
      }
    } catch (\Throwable $e) { }
  }

  $to = trim($telefone);
  if ($to !== '') {
    $nomePlano = (string)($order['nome'] ?? $order['plano_nome'] ?? 'Acesso VIP');
    $valorTxt = ($valorCent !== null) ? ('R$ ' . number_format($valorCent/100, 2, ',', '.')) : '';
    $parts = [];
    $parts[] = '🎉 Pagamento aprovado!';
    if ($valorTxt !== '') $parts[] = 'Valor: '.$valorTxt;
    $parts[] = "Seu {$nomePlano} foi ativado por {$min} minuto(s).";
    if ($link) $parts[] = 'Conectar agora: '.$link;
    $parts[] = 'Boa navegação!';
    $txt = implode("\n", $parts);
    try { send_promo($to, $txt); } catch (\Throwable $e) {}
  }

  if ((envv('APP_DEBUG','false')==='true') || isset($_GET['debug'])) {
    echo json_encode(['ok'=>true,'ref'=>$ref,'user'=>$username,'link'=>$link,'tz'=>'-04:00']);
  } else {
    echo json_encode(['ok'=>true]);
  }

} catch (\Throwable $e) {
  echo json_encode(['ok'=>true]); // mantém 200 para o MP
}
