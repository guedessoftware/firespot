<?php
// /portal/login.php — Login de Visitante (amigável, responsivo)
// + Fluxo “retorno do QR”: se vier com next=qr_check.php?code=... → redireciona para qr_ready.php

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
// Hubsoft e utils opcionais
if (file_exists(__DIR__ . '/../app/hubsoft_api.php')) {
  require_once __DIR__ . '/../app/hubsoft_api.php';
}
if (file_exists(__DIR__ . '/../app/auth_utils.php')) {
  require_once __DIR__ . '/../app/auth_utils.php';
} 

$csrf = csrf_token();

// Captura contexto do hotspot (se vier do Mikrotik) para facilitar a conexão depois
$hotspotKeys = ['server-name', 'mac', 'ip', 'username', 'error', 'chap-id', 'chap-challenge', 'link-login-only', 'link-orig-esc'];
$ctx = $_SESSION['hotspot_ctx']['data'] ?? [];
foreach ($hotspotKeys as $k) {
  if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
    $ctx[$k] = $_REQUEST[$k];
  }
}
if ($ctx) $_SESSION['hotspot_ctx']['data'] = $ctx;

// Helpers
function only_digits($s){ return preg_replace('/\D+/', '', (string)$s); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Sanitiza “next” (evita open redirect): aceita apenas caminhos locais
function sanitize_next($n){
  $n = (string)$n;
  if ($n === '') return '';
  // Apenas caminho absoluto interno. Bloqueia URL protocol-relative,
  // caracteres de controle e qualquer autoridade externa.
  if ($n[0] !== '/' || strncmp($n,'//',2) === 0 || preg_match('/[\x00-\x1F\x7F]/',$n)) return '';
  $parts = parse_url($n);
  if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
  return $n;
}

// Normalizadores reutilizados
function normalize_phone_br_local($s){
  $d = only_digits($s);
  if (strpos($d, '55') === 0 && strlen($d) >= 12) $d = substr($d, 2);
  if (strlen($d) > 11) $d = substr($d, -11);
  return $d;
}
function phones_match_local($informed, $from){
  $a = normalize_phone_br_local($informed);
  $b = normalize_phone_br_local($from);
  if ($a === '' || $b === '') return false;
  if ($a === $b) return true;
  foreach ([11,10,9,8] as $len){ if (strlen($a)>= $len && strlen($b)>= $len){ if (substr($a,-$len)===substr($b,-$len)) return true; } }
  return false;
}
function hubsoft_fetch_cliente_por_cpf_local($cpf){
  if (!function_exists('hubsoftRequest')) return ['ok'=>false];
  $cpf = only_digits($cpf);
  $endpoint = "/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca={$cpf}";
  try{ $dados = hubsoftRequest($endpoint,'GET',null);}catch(\Throwable $e){ return ['ok'=>false]; }
  if (empty($dados) || !is_array($dados)) return ['ok'=>false];
  $cli = $dados[0] ?? null; if (!$cli || !is_array($cli)) return ['ok'=>false];
  $ativo = !empty($cli['ativo']);
  $servOk=false; if (!empty($cli['servicos']) && is_array($cli['servicos'])){ foreach($cli['servicos'] as $srv){ if (($srv['status']??'')==='Serviço Habilitado'){ $servOk=true; break; } } }
  $tel1 = $cli['telefone_primario'] ?? ($cli['telefone'] ?? null);
  $tel2 = $cli['telefone_secundario'] ?? ($cli['celular'] ?? null);
  return ['ok'=>true,'ativo'=>$ativo,'servico_habilitado'=>$servOk,'tel_primario'=>$tel1,'tel_secundario'=>$tel2];
}

$error = null;
$assocIntent = isset($_GET['assoc']) || isset($_POST['assoc']);
$nextParam = sanitize_next($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Requisição inválida.';
  } else {
    $userRaw = trim($_POST['username'] ?? '');
    $pass    = (string)($_POST['password'] ?? '');
    $nextParam = sanitize_next($_POST['next'] ?? $nextParam);

    // Permite CPF com máscara ou username “livre”
    $username = $userRaw;
    $cpfDigits = only_digits($userRaw);
    if (strlen($cpfDigits) === 11) $username = $cpfDigits;

    try {
      $pdo = db();
      $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
      $pdo->exec("SET time_zone='-04:00'");

      // Busca Cleartext-Password no radcheck
      $st = $pdo->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Cleartext-Password' LIMIT 1");
      $st->execute([$username]);
      $expected = $st->fetchColumn();

      if ($expected === false) {
        $error = 'Usuário não encontrado. Crie seu cadastro.';
      } else if ($expected !== $pass) {
        $error = 'Senha incorreta.';
      } else {
        // Login OK → prepara sessão para auto-conectar no hotspot
        $_SESSION['cliente_username'] = $username;
        $_SESSION['hotspot_auto'] = ['username' => $username, 'password' => $pass];

        // Se for cliente do provedor (Hubsoft), promover automaticamente a ilimitado
        try{
          $hub = hubsoft_fetch_cliente_por_cpf_local($username);
          if (!empty($hub['ok']) && !empty($hub['ativo']) && !empty($hub['servico_habilitado'])){
            // Tenta cruzar telefone com clientes_info (se existir)
            $phone = '';
            try{
              $q=$pdo->prepare("SELECT telefone FROM clientes_info WHERE cpf=? LIMIT 1");
              $q->execute([$username]); $phone = (string)($q->fetchColumn() ?: '');
            }catch(\Throwable $e){}
            $match=false; $hp1=$hub['tel_primario']??null; $hp2=$hub['tel_secundario']??null;
            if ($phone!==''){
              if ($hp1 && phones_match_local($phone,(string)$hp1)) $match=true;
              if (!$match && $hp2 && phones_match_local($phone,(string)$hp2)) $match=true;
            }
            if (!$match && (!$hp1 && !$hp2)) $match=true; // sem telefone na API
            if ($match){
              // promove limpando limites e adicionando ISP_UNL
              // replicando promote_to_provider de forma local para evitar include duplicado
              $pdo->beginTransaction();
              try{
                $pdo->prepare("DELETE FROM firespot.radcheck WHERE username=? AND attribute IN ('Max-All-Session','Expiration','Simultaneous-Use')")->execute([$username]);
                $pdo->prepare("DELETE FROM firespot.radreply WHERE username=? AND attribute IN ('Session-Timeout','Idle-Timeout','Mikrotik-Rate-Limit','Acct-Interim-Interval')")->execute([$username]);
                $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname IN ('Plano_Padrao','VIP_24H','PREMIUM_DAY','ISP_UNL')")->execute([$username]);
                try{
                  $pdo->prepare("INSERT INTO firespot.radusergroup (username, groupname, priority) VALUES (?,?,1)")->execute([$username,'ISP_UNL']);
                }catch(\PDOException $e2){ if(($e2->errorInfo[1]??0)==1364){ $nextId=(int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM firespot.radusergroup")->fetchColumn(); $pdo->prepare("INSERT INTO firespot.radusergroup (id, username, groupname, priority) VALUES (?,?,?,1)")->execute([$nextId,$username,'ISP_UNL']); } else { throw $e2; } }
                $pdo->commit();
                $_SESSION['is_hubsoft'] = true;
                $_SESSION['notice_provider_unl'] = true; // mensagem única pós-login
              }catch(\Throwable $e2){ if($pdo->inTransaction()) $pdo->rollBack(); }
            }
          }
        }catch(\Throwable $e){ /* ignora */ }

        // Associação de dispositivo ao usuário (se ainda não associado)
        try {
          // Identidade do device: usa MAC do hotspot quando disponível ou um DeviceID persistente
          $mac = '';
          if (!empty($_SESSION['hotspot_ctx']['data']['mac'])) {
            $mac = strtoupper(str_replace('-', ':', trim((string)$_SESSION['hotspot_ctx']['data']['mac'])));
          } else if (!empty($_COOKIE['fs_did'])) {
            $mac = 'DID:' . preg_replace('/[^A-Fa-f0-9]/','', (string)$_COOKIE['fs_did']);
          }
          if ($mac !== '') {
            // Se já existir, apenas atualiza last_seen/visits; senão cria
            $pdo->prepare("UPDATE clientes_dispositivos SET username=? WHERE mac=? AND username<>? LIMIT 1")
                ->execute([$username, $mac, $username]);
            $pdo->prepare("INSERT INTO clientes_dispositivos (username, mac, first_seen, last_seen, visits) 
                           SELECT ?, ?, NOW(), NOW(), 1 FROM DUAL 
                           WHERE NOT EXISTS (SELECT 1 FROM clientes_dispositivos WHERE username=? AND mac=?)")
                ->execute([$username, $mac, $username, $mac]);
          }
        } catch (\Throwable $e) {
          // silencioso
        }

        // Se o usuário veio do fluxo de QR (next aponta para /portal/qr_check.php?code=...)
        if ($nextParam && strpos($nextParam, '/portal/api/qr_check.php') === 0) {
          // extrai code=...
          $u = parse_url($nextParam);
          $q = [];
          if (!empty($u['query'])) parse_str($u['query'], $q);
          $code = isset($q['code']) ? trim((string)$q['code']) : '';
          if ($code !== '') {
            // guarda “de onde veio” (opcional) e manda para a tela de prontidão
            $_SESSION['qr_after_login_code'] = $code;
            header('Location: /portal/api/qr_ready.php?code=' . rawurlencode($code));
            exit;
          }
        }

        // Caso haja um next válido não-QR, usa; senão vai para cliente.php
  if ($nextParam) {
          header('Location: ' . $nextParam);
        } else {
          header('Location: cliente.php');
        }
        exit;
      }
    } catch (\Throwable $e) {
      error_log('login visitante: ' . $e->getMessage());
      $error = 'Erro ao autenticar. Tente novamente.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar como Visitante</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .login-wrap { display:grid; place-items:center; min-height:70vh; padding:16px; }
    .login-card { width:100%; max-width:420px; background:var(--card,#fff); border:1px solid var(--borda,#ddd);
                  border-radius:14px; padding:16px 16px 18px; }
    .brand{ display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .brand img{ height:28px; width:auto }
    .muted{ color:#6b7280 }
    .grid{ display:grid; gap:10px }
    .pwfield{ position:relative }
    .toggle-eye{ position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:13px; color:#555; user-select:none }
    .hint{ font-size:12px; color:#6b7280; margin-top:4px }
    @media (max-width:480px){ .login-card{ border-radius:12px; padding:14px } }
  </style>
  <script>
    // Máscara de CPF suave
    function formatCPF(value){
      let v=(value||'').replace(/\D+/g,'').slice(0,11);
      if (v.length<=3) return v;
      if (v.length<=6) return v.replace(/(\d{3})(\d+)/,'$1.$2');
      if (v.length<=9) return v.replace(/(\d{3})(\d{3})(\d+)/,'$1.$2.$3');
      return v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2}).*/,'$1.$2.$3-$4');
    }
    document.addEventListener('DOMContentLoaded', ()=>{
      const icpf=document.querySelector('input[name="username"]');
      const ipw=document.getElementById('password');
      const eye=document.getElementById('toggle-eye');

      icpf?.addEventListener('input', ()=>{
        const before=icpf.value;
        const only=before.replace(/\D+/g,'');
        if (only.length<=11){
          const pos=icpf.selectionStart ?? before.length;
          icpf.value = formatCPF(before);
          const delta = icpf.value.length - before.length;
          const newPos = pos + delta;
          icpf.selectionStart = icpf.selectionEnd = newPos < 0 ? icpf.value.length : newPos;
        }
      }, {passive:true});

      eye?.addEventListener('click', ()=>{
        if(!ipw) return;
        const show = ipw.type === 'password';
        ipw.type = show ? 'text' : 'password';
        eye.textContent = show ? 'ocultar' : 'mostrar';
      });
    });
  </script>
</head>
<body>
  <div class="header inline">
    <a href="index.php">← Portal</a>
    <strong>Entrar</strong>
    <a href="cadastro.php">Criar conta</a>
  </div>

  <div class="login-wrap">
    <div class="login-card">
      <div class="brand">
        <img src="assets/img/logo-light.png" alt="FireSpot" loading="lazy">
        <div>
          <div style="font-weight:700">Bem-vindo</div>
          <div class="muted" style="font-size:13px">Acesse com CPF (ou usuário) e senha</div>
        </div>
      </div>

      <?php if ($assocIntent): ?>
        <div class="notice" style="color:#166534;border-color:#bbf7d0;background:#ecfdf5; margin-bottom:10px;">
          Ao entrar, este dispositivo será associado à sua conta para conexões futuras.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="notice" style="color:#b91c1c;border-color:#fecaca;background:#fee2e2; margin-bottom:10px;">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="grid" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="next" value="<?= h($nextParam) ?>">
  <?php if ($assocIntent): ?><input type="hidden" name="assoc" value="1"><?php endif; ?>

        <label>
          CPF ou usuário
          <input type="text" name="username" inputmode="text" autocomplete="username" placeholder="000.000.000-00"
                 maxlength="14" required>
        </label>

        <div class="pwfield">
          <label>Senha</label>
          <input id="password" type="password" name="password" autocomplete="current-password" required>
          <span id="toggle-eye" class="toggle-eye">mostrar</span>
          <div class="hint">Esqueceu a senha? Redefina em poucos passos.</div>
        </div>

        <button class="btn primary" type="submit">Entrar</button>

        <div class="muted" style="font-size:13px; margin-top:6px;">
          <a href="redefinir_senha.php">Esqueci minha senha</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
