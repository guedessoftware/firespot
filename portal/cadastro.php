<?php
// /portal/cadastro.php — cadastro de visitante com senha definida pelo usuário (+ e-mail + 30min trial)
require_once __DIR__ . '/../app/session_boot.php';

require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/courtesy_shadow.php';
require_once __DIR__ . '/../app/courtesy_access.php';
require_once __DIR__ . '/../app/devices.php';
// Hubsoft API para identificar cliente do provedor automaticamente (se disponível)
if (file_exists(__DIR__ . '/../app/hubsoft_api.php')) {
  require_once __DIR__ . '/../app/hubsoft_api.php'; // getAccessToken(), hubsoftRequest()
}

// Opcional: util de auth se existir (tem helpers de grupos)
if (file_exists(__DIR__ . '/../app/auth_utils.php')) {
  require_once __DIR__ . '/../app/auth_utils.php';
}

$csrf = csrf_token();

/* ==========================
   Helpers (server-side)
   ========================== */
function only_digits(string $s): string
{
  return preg_replace('/\D+/', '', $s) ?? '';
}
function normalize_cpf(string $s): string
{
  return only_digits($s);
}
function is_valid_cpf(string $cpf): bool
{
  $cpf = normalize_cpf($cpf);
  if (strlen($cpf) !== 11)
    return false;
  if (preg_match('/^(\d)\1{10}$/', $cpf))
    return false;
  $sum = 0;
  for ($i = 0, $w = 10; $i < 9; $i++, $w--)
    $sum += intval($cpf[$i]) * $w;
  $d1 = ($sum * 10) % 11;
  if ($d1 === 10)
    $d1 = 0;
  if ($d1 !== intval($cpf[9]))
    return false;
  $sum = 0;
  for ($i = 0, $w = 11; $i < 10; $i++, $w--)
    $sum += intval($cpf[$i]) * $w;
  $d2 = ($sum * 10) % 11;
  if ($d2 === 10)
    $d2 = 0;
  return $d2 === intval($cpf[10]);
}
function normalize_phone_br(string $s): string
{
  $d = only_digits($s);
  if (strpos($d, '55') === 0 && strlen($d) >= 12)
    $d = substr($d, 2); // remove +55
  if (strlen($d) > 11)
    $d = substr($d, -11);
  return $d;
}
/** compara telefones com tolerância (com/sem DDI/DDD/9º dígito) */
function phones_match(string $informed, string $fromHubsoft): bool
{
  $a = normalize_phone_br($informed);
  $b = normalize_phone_br($fromHubsoft);
  if ($a === '' || $b === '')
    return false;
  if ($a === $b)
    return true;
  foreach ([11, 10, 9, 8] as $len) {
    if (strlen($a) >= $len && strlen($b) >= $len) {
      if (substr($a, -$len) === substr($b, -$len))
        return true;
    }
  }
  return false;
}
/** Aceita YYYY-MM-DD, DD/MM/YYYY e DD-MM-YYYY; retorna YYYY-MM-DD ou null */
function normalize_date(?string $s): ?string
{
  if (!$s)
    return null;
  $s = trim($s);
  if ($s === '')
    return null;

  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
    if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
      return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null;
  }
  if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/', $s, $m)) {
    $d = (int) $m[1];
    $mth = (int) $m[2];
    $y = (int) $m[3];
    if (checkdate($mth, $d, $y)) {
      return sprintf('%04d-%02d-%02d', $y, $mth, $d);
    }
    return null;
  }
  return null;
}

/** Hubsoft — busca cliente por CPF e retorna status/canais de telefone (se API estiver configurada) */
function hubsoft_fetch_cliente_por_cpf(string $cpf): array
{
  if (!function_exists('hubsoftRequest')) {
    return ['ok' => false, 'cliente' => null, 'ativo' => false, 'servico_habilitado' => false, 'tel_primario' => null, 'tel_secundario' => null];
  }
  $cpf = normalize_cpf($cpf);
  $endpoint = "/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca={$cpf}";
  try {
    $dados = hubsoftRequest($endpoint, 'GET', null);
  } catch (\Throwable $e) {
    error_log('hubsoft_fetch_cliente_por_cpf: ' . $e->getMessage());
    return ['ok' => false, 'cliente' => null, 'ativo' => false, 'servico_habilitado' => false, 'tel_primario' => null, 'tel_secundario' => null];
  }
  if (empty($dados) || !is_array($dados)) {
    return ['ok' => false, 'cliente' => null, 'ativo' => false, 'servico_habilitado' => false, 'tel_primario' => null, 'tel_secundario' => null];
  }
  $cliente = $dados[0] ?? null;
  if (!$cliente || !is_array($cliente)) {
    return ['ok' => false, 'cliente' => null, 'ativo' => false, 'servico_habilitado' => false, 'tel_primario' => null, 'tel_secundario' => null];
  }

  $ativo = !empty($cliente['ativo']);
  $servicoHabil = false;
  if (!empty($cliente['servicos']) && is_array($cliente['servicos'])) {
    foreach ($cliente['servicos'] as $srv) {
      if (isset($srv['status']) && (string) $srv['status'] === 'Serviço Habilitado') {
        $servicoHabil = true; break;
      }
    }
  }
  $telPrim = !empty($cliente['telefone_primario']) ? (string) $cliente['telefone_primario'] : null;
  $telSec  = !empty($cliente['telefone_secundario']) ? (string) $cliente['telefone_secundario'] : null;
  if (!$telPrim && !empty($cliente['telefone'])) $telPrim = (string) $cliente['telefone'];
  if (!$telSec  && !empty($cliente['celular']))  $telSec  = (string) $cliente['celular'];

  return [
    'ok' => true,
    'cliente' => $cliente,
    'ativo' => $ativo,
    'servico_habilitado' => $servicoHabil,
    'tel_primario' => $telPrim,
    'tel_secundario' => $telSec,
  ];
}

/** PROMOÇÃO COMPLETA PARA PROVEDOR (limpa limites e aplica ISP_UNL) */
function promote_to_provider(PDO $pdo, string $username): void
{
  if ($username === '') return;
  $pdo->beginTransaction();
  try {
    // Remover limites de visitante/VIP
    $pdo->prepare("DELETE FROM firespot.radcheck WHERE username=? AND attribute IN ('Max-All-Session','Expiration','Simultaneous-Use')")
        ->execute([$username]);
    $pdo->prepare("DELETE FROM firespot.radreply WHERE username=? AND attribute IN ('Session-Timeout','Idle-Timeout','Mikrotik-Rate-Limit','Acct-Interim-Interval')")
        ->execute([$username]);

    // Remover grupos de visitante/VIP e duplicados
    $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname IN ('Plano_Padrao','VIP_24H','PREMIUM_DAY','ISP_UNL')")
        ->execute([$username]);

    // Adicionar ISP_UNL como primário (priority 1)
    try {
      $pdo->prepare("INSERT INTO firespot.radusergroup (username, groupname, priority) VALUES (?,?,1)")
          ->execute([$username, 'ISP_UNL']);
    } catch (\PDOException $e) {
      if (($e->errorInfo[1] ?? 0) == 1364) { // id sem default
        $nextId = (int) $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM firespot.radusergroup")->fetchColumn();
        $pdo->prepare("INSERT INTO firespot.radusergroup (id, username, groupname, priority) VALUES (?,?,?,1)")
            ->execute([$nextId, $username, 'ISP_UNL']);
      } else {
        throw $e;
      }
    }

    $pdo->commit();
  } catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Lista colunas existentes na tabela `clientes_info` (no DB atual) */
function clientes_info_columns(PDO $pdo): array
{
  $stmt = $pdo->query("\n        SELECT COLUMN_NAME\n        FROM information_schema.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = 'clientes_info'\n    ");
  $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
  return array_map('strval', $cols);
}

/** UPSERT em clientes_info apenas com as colunas que existem (usa data_nascimento) */
function upsert_clientes_info(PDO $pdo, array $data): void
{
  $cols = clientes_info_columns($pdo);
  if (!$cols)
    throw new RuntimeException("Tabela clientes_info não encontrada no schema atual.");

  $map = [
    'cpf' => $data['cpf'] ?? null,
    'nome' => $data['nome'] ?? null,
    'telefone' => $data['telefone'] ?? null,
    'email' => $data['email'] ?? null,   // <-- NOVO: email (só grava se existir a coluna)
    'data_nascimento' => $data['data_nascimento'] ?? null,
    'sexo' => $data['sexo'] ?? null,
  ];

  $insertCols = [];
  $placeholders = [];
  $values = [];
  foreach ($map as $k => $v) {
    if (in_array($k, $cols, true)) {
      $insertCols[] = "`{$k}`";
      $placeholders[] = "?";
      $values[] = $v;
    }
  }

  if (!in_array('cpf', array_map(fn($c) => trim($c, '`'), $insertCols), true)) {
    throw new RuntimeException("A tabela clientes_info precisa ter a coluna `cpf` (UNIQUE).");
  }

  $updates = [];
  foreach ($map as $k => $v) {
    if ($k === 'cpf')
      continue;
    if (in_array($k, $cols, true)) {
      $updates[] = "`{$k}` = VALUES(`{$k}`)";
    }
  }

  $sql = "INSERT INTO `clientes_info` (" . implode(',', $insertCols) . ")\n            VALUES (" . implode(',', $placeholders) . ")\n            ON DUPLICATE KEY UPDATE " . implode(',', $updates);

  $st = $pdo->prepare($sql);
  $st->execute($values);
}

/* ==========================
   RADIUS helpers
   ========================== */
if (!function_exists('upsert_radcheck_password')) {
  function upsert_radcheck_password(PDO $pdo, string $username, string $clearPassword): void
  {
    if ($username === '' || $clearPassword === '')
      return;
    // Garante único registro Cleartext-Password por usuário
    $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")
      ->execute([$username]);
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
      ->execute([$username, 'Cleartext-Password', $clearPassword]);
  }
}
if (!function_exists('ensure_default_plan')) {
  function ensure_default_plan(PDO $pdo, string $username, string $group = 'Plano_Padrao', int $priority = 10): void
  {
    if ($username === '')
      return;
    $st = $pdo->prepare("SELECT 1 FROM radusergroup WHERE username=? AND groupname=? LIMIT 1");
    $st->execute([$username, $group]);
    if ($st->fetchColumn())
      return;
    $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?,?,?)")
      ->execute([$username, $group, $priority]);
  }
}

/* ==========================
   Handler
   ========================== */
$errors = [];
$success = false;
$promotedProvider = false; // exibe aviso de acesso ilimitado se true
$signupCentralEnforce = false;
$signupAutoCredentials = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Requisição inválida.';
  } else {
    $nome = trim($_POST['nome'] ?? '');
    $cpfRaw = trim($_POST['cpf'] ?? '');
    $telRaw = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');       // <-- NOVO: captura email
    $nasc = trim($_POST['nascimento'] ?? '');  // input mantém name="nascimento"
    $sexo = trim($_POST['sexo'] ?? '');        // 'M','F','O' (opcional)

    $senha = (string) ($_POST['senha'] ?? '');
    $conf = (string) ($_POST['senha_confirm'] ?? '');

    $cpf = normalize_cpf($cpfRaw);
    $tel = normalize_phone_br($telRaw);
    $dob = normalize_date($nasc); // converte para YYYY-MM-DD

    // Validações campos básicos (mantidas)
    if ($nome === '' || mb_strlen($nome) < 2) {
      $errors[] = 'Informe seu nome completo.';
    }
    if (!is_valid_cpf($cpf)) {
      $errors[] = 'CPF inválido.';
    }
    if (strlen($tel) < 10 || strlen($tel) > 11) {
      $errors[] = 'Telefone inválido. Use DDD + número (10 ou 11 dígitos).';
    }
    if ($nasc !== '' && $dob === null) {
      $errors[] = 'Data de nascimento inválida. Use 01/02/1990 ou 1990-02-01.';
    }
    if ($sexo !== '' && !in_array($sexo, ['M', 'F', 'O'], true)) {
      $errors[] = 'Sexo inválido.';
    }
    if (!isset($_POST['lgpd']) || $_POST['lgpd'] !== '1') {
      $errors[] = 'É necessário aceitar os termos da LGPD.';
    }
    // E-mail é opcional; se vier preenchido, valida formato
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'E-mail inválido.';
    }

    // Validação da senha (para RADIUS: Cleartext-Password)
    if (strlen($senha) < 6 || strlen($senha) > 64) {
      $errors[] = 'A senha deve ter entre 6 e 64 caracteres.';
    }
    if ($senha !== $conf) {
      $errors[] = 'As senhas não conferem.';
    }
    if (preg_match('/\s/', $senha)) {
      $errors[] = 'A senha não deve conter espaços.';
    }

    if (!$errors) {
      try {
        $pdo = db();
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone='-04:00'");

        $signupPartnerId = fs_courtesy_shadow_partner_id($pdo, [
          'portal' => 'classic_signup',
          'server_name' => $_SESSION['hotspot_ctx']['data']['server-name'] ?? '',
        ]);
        $signupPolicy = null;
        if ($signupPartnerId > 0) {
          $signupPolicy = fs_courtesy_policy_resolve($pdo, $signupPartnerId);
          $signupRollout = fs_courtesy_rollout_resolve($pdo, $signupPartnerId, 'classic_signup', $signupPolicy);
          $signupCentralEnforce = ($signupRollout['effective_mode'] ?? '') === 'enforce';
        }

        // 1) Grava/atualiza dados do visitante (inclui email se a coluna existir)
        upsert_clientes_info($pdo, [
          'cpf' => $cpf,
          'nome' => $nome,
          'telefone' => $tel,
          'email' => ($email ?: null),
          'data_nascimento' => $dob,
          'sexo' => ($sexo ?: null),
        ]);

        // 2) Cria/atualiza credencial no RADIUS (username = CPF, senha escolhida)
        upsert_radcheck_password($pdo, $cpf, $senha);

        // 3) Garante Plano_Padrao (priority 10)
        ensure_default_plan($pdo, $cpf, 'Plano_Padrao', 10);

        // 4) Trial legado: só existe enquanto esta superfície não estiver em
        // enforce. No corte, a credencial é exclusiva da concessão central.
        if (!$signupCentralEnforce) {
          try {
            $trialSec = 30 * 60;
            $st = $pdo->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
            $st->execute([$cpf]);
            $used = (int) $st->fetchColumn();
            $allowed = $used + $trialSec;
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Max-All-Session'")
              ->execute([$cpf]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
              ->execute([$cpf, 'Max-All-Session', (string) $allowed]);
          } catch (\Throwable $e) { /* best-effort, não bloqueia cadastro */
          }
        }

        // 5) Session para fluxo continuar
        $_SESSION['cliente_username'] = $cpf;

        // 6) Identifica automaticamente se é cliente do provedor (Hubsoft) e promove p/ ilimitado
        try {
          $hub = hubsoft_fetch_cliente_por_cpf($cpf);
          if (!empty($hub['ok']) && !empty($hub['ativo']) && !empty($hub['servico_habilitado'])) {
            $match = false;
            $hp1 = $hub['tel_primario'] ?? null; $hp2 = $hub['tel_secundario'] ?? null;
            if ($tel !== '') {
              if ($hp1 && phones_match($tel, (string)$hp1)) $match = true;
              if (!$match && $hp2 && phones_match($tel, (string)$hp2)) $match = true;
            }
            // Se não houver telefone na Hubsoft, confia apenas no status ativo
            if (!$match && (!$hp1 && !$hp2)) $match = true;

            if ($match) {
              promote_to_provider($pdo, $cpf);
              $_SESSION['is_hubsoft'] = true;
              $promotedProvider = true;
            }
          }
        } catch (\Throwable $e) {
          // não bloqueia cadastro
          error_log('auto-promote hubsoft on signup: ' . $e->getMessage());
        }

        $signupDeviceKey = fs_courtesy_shadow_device_key([]);
        $signupIp = (string)($_SESSION['hotspot_device_info']['ip'] ?? $_SESSION['hotspot_ctx']['data']['ip'] ?? '');
        $signupMac = (string)($_SESSION['hotspot_device_info']['mac'] ?? $_SESSION['hotspot_ctx']['data']['mac'] ?? '');
        $signupServer = (string)($_SESSION['hotspot_ctx']['data']['server-name'] ?? '');
        try {
          fs_upsert_device($pdo, $cpf, $signupDeviceKey, $signupIp, $signupServer, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        } catch (\Throwable $e) {
          error_log('[courtesy classic signup device] ' . $e->getMessage());
        }

        if ($signupCentralEnforce && $signupPartnerId > 0) {
          $radiusState = fs_courtesy_shadow_radius_state($cpf);
          $courtesyContext = [
            'partner_id' => $signupPartnerId,
            'portal' => 'classic_signup',
            'source' => 'signup_trial',
            'device_key' => $signupDeviceKey,
            'mac' => $signupMac,
            'ip' => $signupIp,
            'account_key' => $cpf,
            'account_username' => $cpf,
            'associated_device' => fs_courtesy_shadow_associated($pdo, $cpf, $signupDeviceKey),
            'ad_completed' => false,
            'has_active_paid' => !empty($radiusState['has_active_paid']),
            'is_provider' => $promotedProvider || !empty($radiusState['is_provider']),
          ];
          $courtesyContext['idempotency_key'] = fs_courtesy_access_idempotency($courtesyContext, 'classic_signup');
          $signupResult = fs_courtesy_access_issue($pdo, $courtesyContext);
          if (!empty($signupResult['handled']) && !empty($signupResult['allowed'])
              && !empty($signupResult['grant']['username']) && !empty($signupResult['grant']['password'])) {
            $signupAutoCredentials = [
              'username' => (string)$signupResult['grant']['username'],
              'password' => (string)$signupResult['grant']['password'],
            ];
            $_SESSION['courtesy_active_grant'] = [
              'public_id' => (string)($signupResult['grant']['public_id'] ?? ''),
              'portal' => 'classic_signup',
              'partner_id' => $signupPartnerId,
              'account_username' => $cpf,
            ];
          } else {
            unset($_SESSION['courtesy_active_grant']);
            $_SESSION['courtesy_notice'] = (string)($signupResult['message'] ?? 'Cadastro concluído, mas a cortesia não pôde ser liberada agora.');
            $existingAccessCode = in_array((string)($signupResult['code'] ?? ''), ['PROVIDER_NOT_ELIGIBLE', 'ACTIVE_PLAN_NOT_ELIGIBLE'], true);
            if ($promotedProvider || ($existingAccessCode && (!empty($radiusState['is_provider']) || !empty($radiusState['has_active_paid'])))) {
              $signupAutoCredentials = ['username' => $cpf, 'password' => $senha];
            }
          }
        } else {
          fs_courtesy_shadow_capture($pdo, [
            'allowed' => !$promotedProvider,
            'code' => $promotedProvider ? 'LEGACY_PROVIDER_BYPASS' : 'LEGACY_SIGNUP_TRIAL',
            'minutes' => $promotedProvider ? null : 30,
          ], [
            'portal' => 'classic_signup',
            'source' => 'signup_trial',
            'username' => $cpf,
            'device_key' => $signupDeviceKey,
            'mac' => $signupMac,
            'ip' => $signupIp,
            'associated_device' => fs_courtesy_shadow_associated($pdo, $cpf, $signupDeviceKey),
            'is_provider' => $promotedProvider,
            'has_active_paid' => false,
          ]);
          $signupAutoCredentials = ['username' => $cpf, 'password' => $senha];
        }

        $success = true;

      } catch (\Throwable $e) {
        error_log('cadastro visitante (senha + radcheck): ' . $e->getMessage());
        $errors[] = 'Erro ao gravar seus dados. Verifique a estrutura do banco.';
      }
    }
  }
}
?>
<?php
// Redireciona imediatamente para a conta do cliente após cadastro bem-sucedido
if (!headers_sent() && $success) {
  // Regra do primeiro cadastro: prevalece e mantém o usuário autenticado
  $newUser = isset($_POST['cpf']) ? preg_replace('/\D+/', '', (string)$_POST['cpf']) : '';
  $newPass = (string)($_POST['senha'] ?? '');
  if ($newUser !== '') {
    $_SESSION['cliente_username'] = $newUser;
    if (is_array($signupAutoCredentials) && !empty($signupAutoCredentials['username']) && !empty($signupAutoCredentials['password'])) {
      $_SESSION['hotspot_auto'] = $signupAutoCredentials;
    } elseif (!$signupCentralEnforce && $newPass !== '') {
      $_SESSION['hotspot_auto'] = ['username' => $newUser, 'password' => $newPass];
    } else {
      unset($_SESSION['hotspot_auto']);
    }
  }
  header('Location: cliente.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cadastro de Visitante</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    @media (max-width:700px) {
      .grid-2 {
        grid-template-columns: 1fr;
      }
    }

    .muted {
      color: #6b7280
    }

    .sex-group {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .sex-group label {
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
    }

    .pwgrid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 12px;
    }

    @media (max-width:700px) {
      .pwgrid {
        grid-template-columns: 1fr;
      }
    }

    .pwfield {
      position: relative
    }

    .toggle-eye {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 13px;
      color: #555;
      user-select: none;
    }

    .hint {
      font-size: 12px;
      color: #6b7280;
      margin-top: 4px;
    }
  </style>
  <script>
    // Máscaras de CPF e Telefone + validação básica de CPF (client-side) + toggle de senha
    document.addEventListener('DOMContentLoaded', () => {
      const icpf = document.querySelector('input[name="cpf"]');
      const itel = document.querySelector('input[name="telefone"]');
      const form = document.querySelector('form#cadastro');

      const isPwdVisible = { p: false, c: false };
      const toggle = (id, key) => {
        const i = document.getElementById(id);
        if (!i) return;
        isPwdVisible[key] = !isPwdVisible[key];
        i.type = isPwdVisible[key] ? 'text' : 'password';
        const t = i.parentElement?.querySelector('.toggle-eye');
        if (t) t.textContent = isPwdVisible[key] ? 'ocultar' : 'mostrar';
      };
      window._pwToggle = toggle;

      const maskCPF = (v) => {
        v = (v || '').replace(/\D+/g, '').slice(0, 11);
        if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, "$1.$2.$3-$4");
        else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{0,3}).*/, "$1.$2.$3");
        else if (v.length > 3) v = v.replace(/^(\d{3})(\d{0,3}).*/, "$1.$2");
        return v;
      };
      const maskPhone = (v) => {
        v = (v || '').replace(/\D+/g, '').slice(0, 11);
        if (v.length <= 10) {
          return v.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, (m, a, b, c) =>
            [a ? `(${a}` + (a.length === 2 ? ') ' : '') : '', b, (b && c ? '-' : ''), c].join('')
          );
        } else {
          return v.replace(/^(\d{0,2})(\d{0,5})(\d{0,4}).*/, (m, a, b, c) =>
            [a ? `(${a}` + (a.length === 2 ? ') ' : '') : '', b, (b && c ? '-' : ''), c].join('')
          );
        }
      };

      icpf?.addEventListener('input', () => {
        const p = icpf.selectionStart, before = icpf.value;
        icpf.value = maskCPF(before);
        const d = icpf.value.length - before.length;
        icpf.selectionStart = icpf.selectionEnd = (p + d >= 0) ? p + d : icpf.value.length;
      }, { passive: true });

      itel?.addEventListener('input', () => {
        const p = itel.selectionStart, before = itel.value;
        itel.value = maskPhone(before);
        const d = itel.value.length - before.length;
        itel.selectionStart = itel.selectionEnd = (p + d >= 0) ? p + d : itel.value.length;
      }, { passive: true });

      // Envio saneado + validação de CPF client-side
      form?.addEventListener('submit', (ev) => {
        if (icpf) icpf.value = (icpf.value || '').replace(/\D+/g, '').slice(0, 11);
        if (itel) itel.value = (itel.value || '').replace(/\D+/g, '').slice(0, 11);

        const cpf = icpf?.value || '';
        const validaCPF = (str) => {
          if (!/^\d{11}$/.test(str)) return false;
          if (/^(\d)\1+$/.test(str)) return false;
          let sum = 0; for (let i = 0, w = 10; i < 9; i++, w--) sum += parseInt(str[i], 10) * w;
          let d1 = (sum * 10) % 11; if (d1 === 10) d1 = 0; if (d1 !== parseInt(str[9], 10)) return false;
          sum = 0; for (let i = 0, w = 11; i < 10; i++, w--) sum += parseInt(str[i], 10) * w;
          let d2 = (sum * 10) % 11; if (d2 === 10) d2 = 0; return d2 === parseInt(str[10], 10);
        };
        if (!validaCPF(cpf)) {
          ev.preventDefault();
          alert('CPF inválido. Verifique os dígitos.');
        }

        const s1 = document.getElementById('senha');
        const s2 = document.getElementById('senha_confirm');
        if (s1 && s2) {
          const a = s1.value || '', b = s2.value || '';
          if (a.length < 6 || a.length > 64) { ev.preventDefault(); alert('A senha deve ter entre 6 e 64 caracteres.'); return; }
          if (/\s/.test(a)) { ev.preventDefault(); alert('A senha não deve conter espaços.'); return; }
          if (a !== b) { ev.preventDefault(); alert('As senhas não conferem.'); return; }
        }
      });
    });
  </script>
</head>

<body>
  <div class="header inline">
    <a href="index.php">Voltar ao Portal</a>
    <strong>Cadastro de Visitante</strong>
    <span></span>
  </div>

  <div class="container">
    <div class="card">
      <?php if ($success): ?>
        <div class="notice" style="color:#065f46;border-color:#a7f3d0;background:#ecfdf5;">
          Cadastro realizado com sucesso! Sua senha foi criada.
        </div>
        <?php if (!empty($promotedProvider)): ?>
          <div class="notice" style="margin-top:10px;color:#166534;border-color:#bbf7d0;background:#ecfdf5;">
            Identificamos que você é <b>cliente do provedor</b>. Seu <b>acesso ilimitado</b> já foi liberado automaticamente.
          </div>
        <?php endif; ?>
        <div style="margin-top:12px;">
          <a class="btn primary" href="login.php">Ir para o login de visitante</a>
          <a class="btn" href="cliente.php" style="margin-left:8px;">Ir para minha conta</a>
        </div>
      <?php else: ?>
        <?php if ($errors): ?>
          <div class="notice" style="color:#b91c1c;border-color:#fecaca;background:#fee2e2; margin-bottom:12px;">
            <?= htmlspecialchars(implode(' ', $errors)) ?>
          </div>
        <?php endif; ?>

        <form id="cadastro" method="post" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

          <div class="grid-2">
            <div>
              <label>Nome completo</label>
              <input type="text" name="nome" placeholder="Seu nome" required>
            </div>
            <div>
              <label>CPF</label>
              <input type="text" name="cpf" inputmode="numeric" placeholder="000.000.000-00" maxlength="14" required>
            </div>

            <div>
              <label>E-mail</label>
              <input type="email" name="email" placeholder="seu@email.com">
            </div>
            <div>
              <label>Telefone (DDD + número)</label>
              <input type="tel" name="telefone" inputmode="numeric" placeholder="(92) 9XXXX-XXXX" maxlength="16" required>
            </div>

            <div>
              <label>Data de nascimento</label>
              <input type="date" name="nascimento" placeholder="YYYY-MM-DD">
            </div>
          </div>

          <div style="margin-top:12px;">
            <label>Sexo</label>
            <div class="sex-group" role="group" aria-label="Sexo">
              <label><input type="radio" name="sexo" value="M"> Homem</label>
              <label><input type="radio" name="sexo" value="F"> Mulher</label>
              <label><input type="radio" name="sexo" value="O"> Outros</label>
            </div>
          </div>

          <div class="pwgrid">
            <div class="pwfield">
              <label>Crie sua senha</label>
              <input id="senha" type="password" name="senha" minlength="6" maxlength="64" autocomplete="new-password"
                required>
              <span class="toggle-eye" onclick="_pwToggle('senha','p')">mostrar</span>
              <div class="hint">6 a 64 caracteres, sem espaços.</div>
            </div>
            <div class="pwfield">
              <label>Confirme a senha</label>
              <input id="senha_confirm" type="password" name="senha_confirm" minlength="6" maxlength="64"
                autocomplete="new-password" required>
              <span class="toggle-eye" onclick="_pwToggle('senha_confirm','c')">mostrar</span>
            </div>
          </div>

          <div style="margin-top:12px;">
            <label class="muted" style="display:flex; gap:8px; align-items:flex-start;">
              <input type="checkbox" name="lgpd" value="1" required>
              <span>
                Li e aceito os <a href="termos.php" target="_blank" rel="noopener">Termos de Uso</a> e a
                <a href="termos.php#lgpd" target="_blank" rel="noopener">Política de Privacidade (LGPD)</a>.
              </span>
            </label>
          </div>

          <div style="margin-top:16px;">
            <button class="btn primary" type="submit">Finalizar cadastro</button>
            <a class="btn" href="login.php" style="margin-left:8px;">Já tenho cadastro</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>

</html>
