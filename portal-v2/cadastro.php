<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/identifier.php';
require_once __DIR__ . '/../app/company.php';
require_once __DIR__ . '/../app/quick_signup.php';

$candidateUsername = '';
if (!empty($_SESSION['cliente_username'])) {
  $candidateUsername = (string) $_SESSION['cliente_username'];
} elseif (!empty($_SESSION['quick_identifier']['sanitized'])) {
  $candidateUsername = (string) $_SESSION['quick_identifier']['sanitized'];
} elseif (!empty($_GET['username'])) {
  $candidateUsername = (string) $_GET['username'];
}

$candidateDigits = fs_digits_only($candidateUsername);
$sessionUsername = fs_digits_only((string)($_SESSION['cliente_username'] ?? ''));
$pendingUsername = fs_digits_only((string)($_SESSION['portal_v2_signup_username'] ?? ''));

if ($sessionUsername !== '') {
  $username = $sessionUsername;
  $_SESSION['cliente_username'] = $username;
} else {
  $username = $candidateDigits !== '' ? $candidateDigits : $pendingUsername;
  if ($username === '') {
    header('Location: index.php');
    exit;
  }
  $_SESSION['portal_v2_signup_username'] = $username;
}

$csrf = csrf_token();
if ($username === '') {
  header('Location: index.php');
  exit;
}
if ($sessionUsername !== '') {
  $_SESSION['cliente_username'] = $username;
}
$companyProfile = company_get();
$brandName = trim((string) ($companyProfile['name'] ?? 'FireSpot')) ?: 'FireSpot';
$brandSubtitle = trim((string) ($companyProfile['subtitle'] ?? 'Wi-Fi seguro e rápido')) ?: 'Wi-Fi seguro e rápido';
$brandLogo = strtoupper(substr((string) ($companyProfile['logo_letter'] ?? 'F'), 0, 1));
if ($brandLogo === '') {
    $brandLogo = 'F';
}

$errors = [];
$prefillName = '';
$prefillCpf = '';
$prefillPhone = '';
$prefillEmail = '';
$acceptedLgpd = false;

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone='-04:00'");
} catch (Throwable $e) {
    error_log('[signup completion] connection error: ' . $e->getMessage());
    $errors[] = 'Não foi possível carregar os dados do cadastro. Tente novamente em instantes.';
    $pdo = null;
}

$clientRow = null;
if ($pdo) {
    try {
        $st = $pdo->prepare('SELECT * FROM clientes_info WHERE cpf = ? LIMIT 1');
        $st->execute([$username]);
        $clientRow = $st->fetch();
        if (!$clientRow) {
            $st = $pdo->prepare('SELECT * FROM clientes_info WHERE telefone = ? LIMIT 1');
            $st->execute([$username]);
            $clientRow = $st->fetch();
        }
    } catch (Throwable $e) {
        error_log('[signup completion] fetch client error: ' . $e->getMessage());
    }
}

if ($clientRow) {
    $prefillName = trim((string) ($clientRow['nome'] ?? ''));
    $prefillCpf = fs_digits_only((string) ($clientRow['cpf'] ?? ''));
    $prefillPhone = fs_digits_only((string) ($clientRow['telefone'] ?? ''));
    $prefillEmail = trim((string) ($clientRow['email'] ?? ''));
    $acceptedLgpd = !empty($clientRow['aceitou_termos']);
}

if ($prefillCpf === '' && !empty($_SESSION['quick_identifier']['type']) && $_SESSION['quick_identifier']['type'] === 'cpf') {
    $prefillCpf = (string) $_SESSION['quick_identifier']['sanitized'];
}
if ($prefillPhone === '' && !empty($_SESSION['quick_identifier']['type']) && $_SESSION['quick_identifier']['type'] === 'phone') {
    $prefillPhone = (string) $_SESSION['quick_identifier']['sanitized'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Requisição inválida. Atualize a página e tente novamente.';
    } elseif (!$pdo) {
        $errors[] = 'Não foi possível salvar seus dados agora. Tente novamente.';
    } else {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $cpfInput = trim((string) ($_POST['cpf'] ?? ''));
        $telefoneInput = trim((string) ($_POST['telefone'] ?? ''));
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');
        $senhaConf = (string) ($_POST['senha_confirm'] ?? '');
        $lgpdFlag = isset($_POST['lgpd']) && $_POST['lgpd'] === '1';

        $cpfDigits = fs_normalize_cpf($cpfInput);
        if ($cpfDigits === '') {
          $errors[] = 'Informe seu CPF completo.';
        } elseif (!fs_is_valid_cpf($cpfDigits)) {
          $errors[] = 'CPF inválido. Verifique os números informados.';
        }

        $phoneDigits = fs_normalize_phone($telefoneInput);
        $phoneCheck = fs_validate_phone_digits($phoneDigits);
        if (!($phoneCheck['valid'] ?? false)) {
            $errors[] = fs_identifier_reason_message($phoneCheck['reason'] ?? 'invalid_phone');
        }

        if ($nome === '' || mb_strlen($nome) < 2) {
            $errors[] = 'Informe seu nome completo.';
        }

        if ($emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        }

        if (strlen($senha) < 6 || strlen($senha) > 64) {
            $errors[] = 'Defina uma senha com pelo menos 6 caracteres.';
        }
        if ($senha !== $senhaConf) {
            $errors[] = 'As senhas digitadas não conferem.';
        }

        if (!$lgpdFlag) {
            $errors[] = 'Você precisa aceitar os termos de uso para continuar.';
        }

        if ($cpfDigits !== '') {
            try {
                $chk = $pdo->prepare('SELECT id FROM clientes_info WHERE cpf = ? LIMIT 1');
                $chk->execute([$cpfDigits]);
                $existingId = $chk->fetchColumn();
                if ($existingId && (!$clientRow || (int) $existingId !== (int) ($clientRow['id'] ?? 0))) {
                    $errors[] = 'Este CPF já está cadastrado em outro acesso.';
                }
            } catch (Throwable $e) {
                error_log('[signup completion] cpf check error: ' . $e->getMessage());
                $errors[] = 'Não foi possível validar o CPF informado.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($clientRow && !empty($clientRow['id'])) {
                    $stmt = $pdo->prepare('UPDATE clientes_info SET cpf = ?, nome = ?, telefone = ?, email = ?, aceitou_termos = 1 WHERE id = ? LIMIT 1');
                    $stmt->execute([
                        $cpfDigits !== '' ? $cpfDigits : null,
                        $nome,
                        $phoneDigits,
                        $emailInput !== '' ? $emailInput : null,
                        (int) $clientRow['id'],
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO clientes_info (cpf, nome, telefone, email, aceitou_termos) VALUES (?,?,?,?,1)');
                    $stmt->execute([
                        $cpfDigits !== '' ? $cpfDigits : null,
                        $nome,
                        $phoneDigits,
                        $emailInput !== '' ? $emailInput : null,
                    ]);
                    $clientRow = ['id' => (int) $pdo->lastInsertId()];
                }

                fs_quick_upsert_radcheck_password($pdo, $username, $senha);
                fs_quick_ensure_default_plan($pdo, $username);

                $pdo->commit();

                $_SESSION['signup_pending'] = false;
                $_SESSION['hotspot_auto'] = ['username' => $username, 'password' => $senha];
                $_SESSION['cliente_username'] = $username;
                unset($_SESSION['portal_v2_signup_username']);
                $_SESSION['cliente_nome'] = $nome;
                $_SESSION['quick_identifier'] = [
                    'type' => $cpfDigits !== '' ? 'cpf' : 'phone',
                    'sanitized' => $cpfDigits !== '' ? $cpfDigits : $phoneDigits,
                    'formatted' => $cpfDigits !== '' ? fs_format_cpf($cpfDigits) : fs_format_phone($phoneDigits),
                ];

                header('Location: ../portal/hotspot_do_login.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[signup completion] save error: ' . $e->getMessage());
                $errors[] = 'Não foi possível salvar seus dados. Tente novamente.';
            }
        }

        $prefillName = $nome;
        $prefillCpf = $cpfDigits;
        $prefillPhone = $phoneDigits;
        $prefillEmail = $emailInput;
        $acceptedLgpd = $lgpdFlag;
    }
}

function signup_format_input(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Complete seu cadastro — <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <style>
    body.signup-body {
      background: #f3f4f6;
      margin: 0;
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: #111827;
    }
    .signup-wrapper {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px 16px;
    }
    .signup-card {
      max-width: 420px;
      width: 100%;
      background: #ffffff;
      border-radius: 22px;
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
      padding: 28px 26px;
    }
    .signup-card h1 {
      font-size: 22px;
      margin-bottom: 10px;
    }
    .signup-card p.lead {
      font-size: 14px;
      color: #4b5563;
      margin-bottom: 20px;
    }
    .signup-form .input-group {
      margin-bottom: 14px;
    }
    .signup-form label.input-label {
      font-size: 13px;
      font-weight: 600;
    }
    .signup-form .input-field {
      margin-top: 6px;
    }
    .signup-form .checkbox-row {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 12px;
      color: #374151;
      margin: 14px 0 18px;
    }
    .signup-form button {
      width: 100%;
      margin-top: 6px;
    }
    .banner-error {
      background: #fee2e2;
      border: 1px solid #fecaca;
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 13px;
      color: #991b1b;
      margin-bottom: 16px;
    }
    .banner-error ul {
      margin: 0;
      padding-left: 18px;
    }
    .support-text {
      font-size: 12px;
      color: #6b7280;
      text-align: center;
      margin-top: 18px;
    }
  </style>
</head>
<body class="signup-body">
  <div class="signup-wrapper">
    <div class="signup-card">
      <div class="screen-header" style="margin-bottom:16px;">
        <div class="brand">
          <div class="brand-logo" style="background:#fde68a;color:#92400e;">
            <?= htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="brand-text">
            <span class="brand-title"><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand-subtitle"><?= htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>
      </div>

      <h1>Quase lá! 🚀</h1>
      <p class="lead">Precisamos de alguns dados para ativar seu acesso e conectar este dispositivo automaticamente nas próximas visitas.</p>

      <?php if ($errors): ?>
        <div class="banner-error">
          <ul>
            <?php foreach ($errors as $msg): ?>
              <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" class="signup-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

        <div class="input-group">
          <label class="input-label" for="nome">Nome completo</label>
          <input class="input-field" type="text" id="nome" name="nome" required maxlength="150" autocomplete="name" value="<?= signup_format_input($prefillName) ?>">
        </div>

        <div class="input-group">
          <label class="input-label" for="cpf">CPF</label>
          <input class="input-field" type="text" id="cpf" name="cpf" maxlength="14" inputmode="numeric" autocomplete="off" required value="<?= signup_format_input(fs_format_cpf($prefillCpf)) ?>" placeholder="000.000.000-00">
        </div>

        <div class="input-group">
          <label class="input-label" for="telefone">Telefone com DDD (WhatsApp)</label>
          <input class="input-field" type="text" id="telefone" name="telefone" maxlength="15" inputmode="numeric" autocomplete="tel" required value="<?= signup_format_input(fs_format_phone($prefillPhone)) ?>" placeholder="(99) 99999-9999">
        </div>

        <div class="input-group">
          <label class="input-label" for="email">E-mail (opcional)</label>
          <input class="input-field" type="email" id="email" name="email" maxlength="120" autocomplete="email" value="<?= signup_format_input($prefillEmail) ?>" placeholder="exemplo@dominio.com">
        </div>

        <div class="input-group">
          <label class="input-label" for="senha">Defina uma senha</label>
          <input class="input-field" type="password" id="senha" name="senha" minlength="6" maxlength="64" required autocomplete="new-password">
        </div>

        <div class="input-group">
          <label class="input-label" for="senha_confirm">Confirme a senha</label>
          <input class="input-field" type="password" id="senha_confirm" name="senha_confirm" minlength="6" maxlength="64" required autocomplete="new-password">
        </div>

        <label class="checkbox-row">
          <input type="checkbox" name="lgpd" value="1" <?= $acceptedLgpd ? 'checked' : '' ?> required>
          <span>Eu li e aceito os <a href="../portal/termos.php" target="_blank" rel="noopener">termos de uso e política LGPD</a>.</span>
        </label>

        <button type="submit" class="btn btn-primary">Salvar e conectar</button>
      </form>

      <div class="support-text">
        Dispositivo atual: será reconhecido automaticamente nas próximas conexões.
      </div>
    </div>
  </div>
</body>
</html>
