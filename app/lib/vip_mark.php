<?php
// Marca cliente como VIP (em banco) e tenta preparar auto-login via Hotspot
// Uso: vip_mark_and_autologin($external_ref)

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../schema_guard.php';

function envv($k, $def=null){
  if (function_exists('env')) return env($k, $def);
  $v = getenv($k); return ($v!==false && $v!=='') ? $v : $def;
}

function normalize_mac_v($mac){
  $mac = strtoupper(str_replace('-', ':', trim((string)$mac)));
  if ($mac==='') return '';
  if (preg_match('/^[0-9A-F]{12}$/',$mac)) $mac = implode(':', str_split($mac,2));
  return preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/',$mac) ? $mac : '';
}
function normalize_ip_v($ip){
  $ip = trim((string)$ip); if ($ip==='') return '';
  if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/',$ip)) $ip = preg_replace('/:(\d+)$/','',$ip);
  $ip = trim($ip,'[]');
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return $ip;
  return '';
}

function table_has_col(PDO $pdo, $table, $col){
  $q = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $q->execute([$table, $col]); return (bool)$q->fetchColumn();
}

function ensure_client_vip_cols(PDO $pdo){
  runtime_schema_require($pdo, 'clientes_info', ['vip_ativo','vip_until']);
}

function build_hotspot_login_url($username, $password){
  $base = rtrim(envv('HS_LOGIN_URL',''),'/');
  if ($base==='') return '';
  $dst = $base . '/status';
  // Mikrotik padrão: /login?username=&password=&dst=
  return $base.'/login?username='.rawurlencode($username).'&password='.rawurlencode($password).'&dst='.rawurlencode($dst);
}

function hotspot_create_user_and_cookie($username, $password, $profile, $mac=''){
  // cria/garante usuário VIP e adiciona cookie p/ auto-login por MAC
  require_once __DIR__ . '/routeros.php';
  $profile = $profile ?: envv('ROS_VIP_PROFILE','VIP');
  $u = $username; $p = $password;
  $mac = normalize_mac_v($mac);

  $cmds = [];
  // garante profile existe? (opcional) — aqui assumimos que já existe
  $cmds[] = "/ip hotspot user remove [find where name=$u]";
  $cmds[] = "/ip hotspot user add name=$u password=$p profile=$profile";
  if ($mac) {
    $cmds[] = "/ip hotspot cookie remove [find where mac-address=$mac]";
    // adiciona cookie para que na próxima navegação o host logue sem precisar da página
    $cmds[] = "/ip hotspot cookie add mac-address=$mac user=$u";
    // força reautenticação para pegar o cookie
    $cmds[] = "/ip hotspot active remove [find where mac-address=$mac]";
    $cmds[] = "/ip firewall connection remove [find where src-address|dst-address~\"$mac\"]"; // melhor esforço
  }
  ros_exec($cmds);
}

function vip_mark_and_autologin(string $external_ref): array {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  // carrega order + plano
  $q = $pdo->prepare("SELECT o.*, p.duracao_min FROM vip_orders o LEFT JOIN planos p ON p.id=o.plano_id WHERE o.external_ref=? LIMIT 1");
  $q->execute([$external_ref]);
  $order = $q->fetch();
  if (!$order) return ['ok'=>false,'err'=>'Ordem não encontrada'];

  $minutes = (int)($order['duracao_min'] ?? 0); if ($minutes<=0) $minutes = 1440;
  $mac = normalize_mac_v($order['mac'] ?? '');
  $ip  = normalize_ip_v($order['ip']  ?? '');
  $username = trim((string)($order['username'] ?? ''));
  $phone = preg_replace('/\D+/','', (string)($order['telefone'] ?? ''));

  // payments_session como fallback de ip/mac/phone/minutos
  $s = $pdo->prepare("SELECT ip, mac, phone, minutes FROM payments_session WHERE token=? LIMIT 1");
  $s->execute([$external_ref]);
  if ($r=$s->fetch()){
    if ($ip==='')  $ip  = normalize_ip_v($r['ip']??'');
    if ($mac==='') $mac = normalize_mac_v($r['mac']??'');
    if ($phone==='') $phone = preg_replace('/\D+/','', (string)($r['phone']??''));
    if (!empty($r['minutes'])) $minutes = (int)$r['minutes'];
  }

  // garante colunas vip em clientes_info
  ensure_client_vip_cols($pdo);

  // encontra/atualiza cliente por CPF ou telefone
  $cpf = preg_replace('/\D+/','', (string)($order['cpf'] ?? ''));
  $cli = null; $cli_id = null;
  if ($cpf) {
    $st = $pdo->prepare("SELECT id FROM clientes_info WHERE cpf=? LIMIT 1"); $st->execute([$cpf]); $cli = $st->fetch();
  }
  if (!$cli && $phone) {
    $st = $pdo->prepare("SELECT id FROM clientes_info WHERE telefone=? LIMIT 1"); $st->execute([$phone]); $cli = $st->fetch();
  }
  if ($cli) { $cli_id = (int)$cli['id']; }
  else {
    $st = $pdo->prepare("INSERT INTO clientes_info (cpf, nome, telefone, aceitou_termos) VALUES (?,?,?,1)");
    $st->execute([$cpf ?: null, (string)($order['nome']??''), $phone ?: null]);
    $cli_id = (int)$pdo->lastInsertId();
  }

  // marca VIP por janela de tempo (relógio corrido)
  $pdo->beginTransaction();
  try {
    $pdo->prepare("UPDATE clientes_info SET vip_ativo=1, vip_until=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")
        ->execute([$minutes, $cli_id]);
    // marca order
    $pdo->prepare("UPDATE vip_orders SET status='paid', paid_at=NOW(), updated_at=NOW() WHERE external_ref=?")
        ->execute([$external_ref]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok'=>false,'err'=>$e->getMessage()];
  }

  // cria usuário hotspot + cookie p/ auto-login
  // gera credenciais se não houver username
  if ($username==='') {
    $username = $cpf ?: ($phone ?: ('vip'.substr($external_ref,-6)));
  }
  $password = substr(hash('sha256', $external_ref),0,10);
  try {
    hotspot_create_user_and_cookie($username, $password, envv('ROS_VIP_PROFILE','VIP'), $mac);
  } catch (Throwable $e) {
    // segue mesmo assim; login por URL abaixo pode resolver
  }

  // url de login (para enviar por WhatsApp ou redirecionar em sucesso)
  $login_url = build_hotspot_login_url($username, $password);

  return [
    'ok'=>true,
    'cliente_id'=>$cli_id,
    'minutes'=>$minutes,
    'vip_until'=>date('Y-m-d H:i:s', time()+$minutes*60),
    'username'=>$username,
    'login_url'=>$login_url,
    'ip'=>$ip,
    'mac'=>$mac
  ];
}
