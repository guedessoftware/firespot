<?php
# ===== File: /var/www/html/portal/vip_checkout.php =====

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mp_client.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/vip_order_session.php';


// --- Somente POST com CSRF válido ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
  http_response_code(403); exit('CSRF inválido');
}
#var_dump($_POST);

$username  = trim($_POST['username']  ?? '');
$nome      = trim($_POST['nome']      ?? '');
$cpf       = only_digits($_POST['cpf']?? '');
$email     = trim($_POST['email']     ?? '');
$telefone  = trim($_POST['telefone']  ?? '');
$plano_id  = (int)($_POST['plano_id'] ?? 0);
$pixCheckoutInfo = '';

if ($nome==='' || strlen($cpf)!==11 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $plano_id<=0) {
  http_response_code(400); echo 'Dados inválidos'; exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $st = $pdo->prepare(
 'SELECT id,nome,grupo,preco_centavos,down_kbps,up_kbps,duracao_min,descricao
    FROM planos
   WHERE id=? AND ativo=1'
);

  $st->execute([$plano_id]);
  $plano = $st->fetch();
  if (!$plano) { http_response_code(404); echo 'Plano não encontrado'; exit; }

  $hasPaymentMeta = table_has_column($pdo, 'vip_orders', 'payment_method');
  $paymentMethod = 'pix';

  $pixEnabledRaw = settings_get('payment_pix_enabled', envv('PAYMENT_PIX_ENABLED', '1'));
  $pixEnabled = in_array(strtolower((string)$pixEnabledRaw), ['1','true','on','yes','sim'], true);
  if (!$pixEnabled) {
    http_response_code(503);
    echo 'Pagamentos PIX temporariamente indisponíveis.';
    exit;
  }

  $pixExpireMinutes = (int) settings_get('payment_pix_expire_minutes', envv('MP_PIX_EXPIRE_MINUTES', 10));
  if ($pixExpireMinutes < 5) $pixExpireMinutes = 5;
  if ($pixExpireMinutes > 60) $pixExpireMinutes = 60;
  $pixCheckoutInfo = settings_get('payment_pix_checkout_info', '');
  $tzManaus = new DateTimeZone('America/Manaus');
  $expireAt = (new DateTimeImmutable('now', $tzManaus))->add(new DateInterval('PT' . $pixExpireMinutes . 'M'));
  $expireAtDb = $expireAt->format('Y-m-d H:i:s');
  $expireAtIso = $expireAt->format('c');

  $pdo->beginTransaction();

  // Captura informações do dispositivo e host de origem
  $client_ip  = $_POST['ip']  ?? ($_SESSION['client_ip']  ?? ($_SESSION['hotspot_device_info']['ip']  ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
  $client_mac = $_POST['mac'] ?? ($_SESSION['client_mac'] ?? ($_SESSION['hotspot_device_info']['mac'] ?? ''));
  $host_code  = $_POST['host_code'] ?? ($_SESSION['hotspot_ctx']['data']['server-name'] ?? null);
  
  // Normaliza MAC e IP usando helpers
  $client_mac = normalize_mac($client_mac);
  $client_ip  = normalize_ip($client_ip);

  $external_ref = 'VIP-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
  if ($hasPaymentMeta) {
    $ins = $pdo->prepare('INSERT INTO vip_orders
      (username,nome,cpf,email,telefone,plano_id,valor_centavos,up_kbps,down_kbps,duracao_min,payment_method,payment_method_detail,payment_installments,external_ref,status,host_code,device_mac,device_ip,created_at,payment_expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)');
    $ins->execute([
      $username ?: null,
      $nome,
      $cpf,
      $email,
      $telefone,
      (int)$plano['id'],
      (int)$plano['preco_centavos'],
      (int)$plano['up_kbps'],
      (int)$plano['down_kbps'],
      (int)$plano['duracao_min'],
      $paymentMethod,
      'pix',
      1,
      $external_ref,
      'pending',
      $host_code,
      $client_mac,
      $client_ip,
      $expireAtDb
    ]);
  } else {
    $ins = $pdo->prepare('INSERT INTO vip_orders
      (username,nome,cpf,email,telefone,plano_id,valor_centavos,up_kbps,down_kbps,duracao_min,external_ref,status,host_code,device_mac,device_ip,created_at,payment_expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)');
    $ins->execute([
      $username ?: null,
      $nome,
      $cpf,
      $email,
      $telefone,
      (int)$plano['id'],
      (int)$plano['preco_centavos'],
      (int)$plano['up_kbps'],
      (int)$plano['down_kbps'],
      (int)$plano['duracao_min'],
      $external_ref,
      'pending',
      $host_code,
      $client_mac,
      $client_ip,
      $expireAtDb
    ]);
  }
  $order_id = (int)$pdo->lastInsertId();

  [$ddd,$fone] = normalize_phone_br($telefone);
  $payer = [
    'first_name'   => $nome,
    'email'        => $email,
    'cpf'          => $cpf,
    'phone_area'   => $ddd,
    'phone_number' => $fone,
  ];
  $desc = 'Acesso VIP - '.$plano['nome'];

  $pix = mp_create_pix_payment((int)$plano['preco_centavos'], $external_ref, $desc, $payer, ['order_id'=>$order_id,'username'=>$username], $expireAtIso);
  if (empty($pix['id']) || empty($pix['qr_code']) || empty($pix['qr_code_base64'])) {
    throw new RuntimeException('Resposta do Mercado Pago incompleta');
  }

  if ($hasPaymentMeta) {
    $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id=?, payment_method_detail=?, updated_at=NOW() WHERE id=?');
    $up->execute([$pix['id'], 'pix', $order_id]);
  } else {
    $up = $pdo->prepare('UPDATE vip_orders SET mp_payment_id=?, updated_at=NOW() WHERE id=?');
    $up->execute([$pix['id'], $order_id]);
  }

  $pdo->commit();
  vip_order_session_bind($external_ref);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo $DEBUG ? 'Erro: '.htmlspecialchars($e->getMessage()) : 'Falha ao criar pagamento.';
  exit;
}

$qrBase64 = $pix['qr_code_base64'];
$qrText   = $pix['qr_code'];
$ticket   = $pix['ticket_url'] ?? '';

$trial_minutes = (int)(getenv('TRIAL_MINUTES_DEFAULT') ?: 10);
if ($trial_minutes <= 0) $trial_minutes = 10;
if ($trial_minutes > 120) $trial_minutes = 120;

$vip_minutes = (int)$plano['duracao_min'];
if ($vip_minutes <= 0) $vip_minutes = 1440;

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <title>Pagamento PIX · <?= htmlspecialchars($plano['nome']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .stack{display:grid;gap:12px;max-width:560px}
    .mono{width:100%;font-family:monospace}
    .notice.ok{color:#0a7}.notice.warn{color:#b50}
  </style>
</head>
<body>
  <main class="container">
    <h1>Finalize seu pagamento</h1>
    <p>Plano: <strong><?= htmlspecialchars($plano['nome']) ?></strong> · Valor: <strong><?= money_br($plano['preco_centavos']) ?></strong></p>

    <div class="card stack">
      <img src="<?= htmlspecialchars($qrBase64) ?>" alt="QR Code Pix" style="width:260px;height:260px;margin:auto" />
      <div>
        <label class="muted">Copia e Cola</label>
        <textarea readonly rows="3" id="pixcopia" class="mono"><?= htmlspecialchars($qrText) ?></textarea>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" id="btnCopiar">Copiar</button>
          <?php if ($ticket): ?><a class="btn" target="_blank" href="<?= htmlspecialchars($ticket) ?>">Abrir no app</a><?php endif; ?>
        </div>
      </div>
      <?php if (trim($pixCheckoutInfo) !== ''): ?>
        <div class="muted" style="white-space:pre-wrap;">
          <?= htmlspecialchars($pixCheckoutInfo) ?>
        </div>
      <?php endif; ?>
      <div id="status" class="notice">Aguardando pagamento...</div>
      <div id="expire-info" class="muted">QR Code expira em <span id="expire-timer">--:--</span>.</div>
      <div id="hint" class="muted"></div>
    </div>

<script>
// Base pública automática (/portal/ ou /hotspot/)
const BASE = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\').'/') ?>;
const API  = BASE + 'api/';

// Helper: POST form-url-encoded (preenche $_POST no PHP)
async function postForm(url, params){
  const body = new URLSearchParams(params||{});
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body
  });
  const raw = await r.text();
  let data = null; try{ data = JSON.parse(raw); }catch(_){ }
  if (!r.ok || (data && data.ok===false)){
    const msg = (data && (data.error||data.err)) || (raw && raw.length<500 ? raw : ('HTTP '+r.status));
    throw new Error(msg);
  }
  return data||{};
}

// Variáveis vindas do PHP
const externalRef  = <?= json_encode($external_ref) ?>;
const apiCsrf      = <?= json_encode(csrf_token()) ?>;
const trialMinutes = <?= (int)$trial_minutes ?>;
const vipMinutes   = <?= (int)$vip_minutes ?>;
const pixExpireMinutes = <?= (int)$pixExpireMinutes ?>;
const pixExpiresAtIso  = <?= json_encode($expireAtIso) ?>;
const telefoneRaw  = <?= json_encode($telefone) ?>;
const CLIENT_IP    = <?= json_encode($client_ip) ?>;
const CLIENT_MAC   = <?= json_encode($client_mac) ?>;

const statusEl = document.getElementById('status');
const expireInfoEl = document.getElementById('expire-info');
const expireTimerEl = document.getElementById('expire-timer');
const expireTimestamp = pixExpiresAtIso ? Date.parse(pixExpiresAtIso) : null;
let expireTimerId = null;
let expiredFlag = false;

function formatCountdown(ms){
  const totalSeconds = Math.max(0, Math.floor(ms / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return String(minutes).padStart(2,'0')+':'+String(seconds).padStart(2,'0');
}

function markExpired(message){
  if (expiredFlag) return;
  expiredFlag = true;
  if (expireTimerId) clearInterval(expireTimerId);
  if (expireTimerEl) expireTimerEl.textContent = '00:00';
  if (expireInfoEl) {
    expireInfoEl.classList.add('warn');
    expireInfoEl.textContent = message || 'QR Code expirou. Gere um novo pagamento.';
  }
  if (statusEl) statusEl.textContent = 'Pagamento expirado. Gere um novo QR Code.';
}

function updateCountdown(){
  if (!expireTimestamp || expiredFlag) return;
  const diff = expireTimestamp - Date.now();
  if (diff <= 0){
    markExpired();
    return;
  }
  if (expireTimerEl) expireTimerEl.textContent = formatCountdown(diff);
}

if (expireTimestamp){
  updateCountdown();
  expireTimerId = setInterval(updateCountdown, 1000);
}

async function startTrial(){
  const hint = document.getElementById('hint');
  let trialOk=false, ip='', mac='';
  let lastErr='';
  // 1) Trial — envia IP/MAC SEM usar undefined
  try{
    const j = await postForm(API+'trial_start.php', {
      csrf_token: apiCsrf,
      ref: externalRef,
      minutes: String(trialMinutes),
      ip:  (CLIENT_IP  || ''),
      mac: (CLIENT_MAC || '')
    });
    if (j && j.code === 'ASSOC_REQUIRED'){
      // redireciona para login com associação
      window.location.href = (j.login || (BASE+'login.php?assoc=1')) + '&next=' + encodeURIComponent(window.location.pathname + window.location.search);
      return;
    }
    trialOk = !!j.ok; ip = j.ip || CLIENT_IP || ''; mac = j.mac || CLIENT_MAC || '';
  }catch(e){ lastErr = e.message; }

  // 2) Bind sessão (sempre com token e ip/mac)
  try{
    await postForm(API+'pay_session_bind.php', {
      token: externalRef,
      csrf_token: apiCsrf,
      ip:  (ip||''),
      mac: (mac||''),
      minutes: String(vipMinutes),
      phone: (telefoneRaw||'')
    });
  }catch(e){ lastErr = 'Erro ao vincular sessão: '+e.message; }

  if (trialOk){
    hint.innerHTML = '⚡ Conexão liberada por <strong>'+trialMinutes+' min</strong> para concluir o pagamento.';
    hint.classList.add('ok');
  } else if (lastErr){
    hint.textContent = 'Não foi possível liberar a janela: '+lastErr;
    hint.classList.add('warn');
  }
}

async function promoteVipNow(){
  try{
    await postForm(API+'vip_promote.php', {
      ref: externalRef,
      csrf_token: apiCsrf,
      ip:  (CLIENT_IP  || ''),
      mac: (CLIENT_MAC || '')
    });
  }catch(_){ }
}

async function copiarPix(){
  const ta = document.getElementById('pixcopia');
  const text = ta.value;
  try{
    if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(text);
    else { ta.select(); document.execCommand('copy'); }
    if (statusEl) statusEl.textContent = 'Código PIX copiado. Você tem '+pixExpireMinutes+' minutos para pagar.';
  }catch(e){
    if (statusEl) statusEl.textContent = 'Falha ao copiar. Copie manualmente e tente novamente.';
  }
}

document.getElementById('btnCopiar').addEventListener('click', copiarPix);

let tries = 0;
async function poll(){
  if (expiredFlag) return;
  if (expireTimestamp && Date.now() >= expireTimestamp){
    markExpired();
    return;
  }
  try{
    const j = await postForm(API+'payment_status.php', {
      ref: externalRef,
      csrf_token: apiCsrf
    });
    if (j && j.ok && j.status==='paid'){
      try { await promoteVipNow(); } catch(_) {}
      window.location.href = 'vip_sucesso.php?ref='+encodeURIComponent(externalRef);
      return;
    }
    if (j && j.ok && j.status==='expired'){
      markExpired();
      return;
    }
  }catch(_){ }
  if (++tries<90) setTimeout(poll, 3000); else {
    if (!expiredFlag && statusEl) statusEl.textContent = 'Tempo esgotado, atualize a página.';
  }
}
poll();
</script>
  </main>
</body>
</html>
