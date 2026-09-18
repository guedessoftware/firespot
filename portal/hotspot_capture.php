<?php
// /portal/hotspot_capture.php
// Recebe os campos do Mikrotik e guarda em sessão para uso no login.
// Chame esta página a partir do formulário da página de login do Mikrotik.

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/partner_hotspots.php';
header('Content-Type: text/html; charset=utf-8');

$newPortalParam = $_REQUEST['newportal'] ?? '';
$isNewPortal = false;
if (is_string($newPortalParam) && strtolower(trim($newPortalParam)) === 'yes') {
  $isNewPortal = true;
}

$fields = [
  'hotspot',           // $(server-name)
  'mac',               // $(mac)
  'ip',                // $(ip)
  'username',          // $(username) - pode vir vazio
  'fast_id',           // id opcional enviado pelo hotspot
  'chap-id',           // $(chap-id)
  'chap-challenge',    // $(chap-challenge)
  'link-login',        // $(link-login)
  'link-login-only',   // $(link-login-only)
  'link-orig-esc',     // $(link-orig-esc)
  'error',             // $(error)
];

$_SESSION['hotspot_device_info'] = [
  // valores padrão corrigidos (apenas fallback visível em debug)
  'mac' => trim($_POST['mac'] ?? '00:00:00:00:00:00'),
  'ip' => trim($_POST['ip'] ?? '192.168.1.2'),
];

$hs = [];
foreach ($fields as $f) {
  $value = isset($_POST[$f]) ? (string)$_POST[$f] : '';
  // chap-id e chap-challenge podem conter bytes que trim() removeria.
  $hs[$f] = in_array($f, ['chap-id', 'chap-challenge'], true) ? $value : trim($value);
}

if ($isNewPortal) {
  $hs['newportal'] = 'yes';
}

// Guarda em sessão
$_SESSION['hotspot_ctx'] = [
  'data' => $hs,
  'ts'   => time()
];

$fastId = $hs['fast_id'] ?? '';

// Portal V3 é opt-in por estabelecimento. Se a migração ainda não foi aplicada,
// a consulta falha silenciosamente e o roteamento anterior permanece intacto.
$portalMode = 'inherit';
if ($fastId !== '') {
  $fastIdSafe = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$fastId);
  if ($fastIdSafe !== '') {
    $_SESSION['portal_fast_id'] = $fastIdSafe;
    try {
      require_once __DIR__ . '/../app/db.php';
      $pdoRoute = db();
      $routeContext=fs_partner_hotspot_resolve($pdoRoute,$fastIdSafe,false,true);
      if($routeContext){
        $portalMode=(string)($routeContext['portal_mode']??'inherit');
        $_SESSION['portal_hotspot_id']=fs_partner_hotspot_id($routeContext);
        $_SESSION['portal_hotspot_code']=fs_partner_hotspot_public_code($routeContext);
        $_SESSION['portal_partner_id']=(int)$routeContext['id'];
        $_SESSION['portal_fast_id']=fs_partner_hotspot_public_code($routeContext);
        $fastId=$_SESSION['portal_fast_id'];
      }else{
        $sqlRoute = 'SELECT portal_mode FROM partners WHERE active=1 AND (code=?';$routeParams = [$fastIdSafe];if (ctype_digit($fastIdSafe)) {$sqlRoute .= ' OR id=?';$routeParams[] = (int)$fastIdSafe;}$sqlRoute .= ') LIMIT 1';$stRoute = $pdoRoute->prepare($sqlRoute);$stRoute->execute($routeParams);$portalMode = (string)($stRoute->fetchColumn() ?: 'inherit');
      }
    } catch (Throwable $e) {
      $portalMode = 'inherit';
    }
  }
}

if ($portalMode === 'classic') {
  $isNewPortal = false;
} elseif ($portalMode === 'v2') {
  $isNewPortal = true;
}

if ($portalMode === 'v3') {
  $_SESSION['portal_v3_active'] = true;
  unset($_SESSION['portal_v2_active']);
  $redirect = '../portal-v3/index.php';
  if ($fastId !== '') {
    $redirect .= '?hotspot=' . rawurlencode((string)$fastId);
  }
  header('Location: ' . $redirect);
  exit;
}

// Se já temos o usuário logado no portal, vamos direto para a página de login no Mikrotik.
// Senão, mandamos para o hub do portal para ele se autenticar primeiro.
if (!empty($_SESSION['cliente_username'])) {
  header('Location: hotspot_do_login.php'); // vai pedir a senha (se precisar) e completar o CHAP
} else {
  $redirect = $isNewPortal ? '../portal-v2/index.php' : 'index.php';
  $query = [];
  if ($fastId !== '') {
    $query['fast_id'] = $fastId;
  }
  if (!empty($query)) {
    $redirect .= '?' . http_build_query($query);
  }
  header('Location: ' . $redirect);
}
exit;
