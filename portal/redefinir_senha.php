<?php
// /portal/redefinir_senha.php — Redefinição de senha para visitantes
// Agora rebaixa automaticamente para VISITANTE (remove ISP_UNL e VIP, zera créditos).

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

$csrf = csrf_token();

function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function only_digits(string $s): string
{
  return preg_replace('/\D+/', '', $s) ?? '';
}
function normalize_phone_br(string $s): string
{
  $d = only_digits($s);
  if (strpos($d, '55') === 0 && strlen($d) >= 12)
    $d = substr($d, 2);
  if (strlen($d) > 11)
    $d = substr($d, -11);
  return $d;
}

/** Garante Plano_Padrao (priority 10) */
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

/** Rebaixa completamente para VISITANTE (remove ilimitado e VIP, zera créditos) */
function demote_to_visitor(PDO $pdo, string $username): void
{
  if ($username === '')
    return;
  $pdo->beginTransaction();
  try {
    // 1) Remover grupo de provedor e VIPs
    $pdo->prepare("DELETE FROM radusergroup WHERE username=? AND groupname IN ('ISP_UNL','VIP_24H','PREMIUM_DAY')")
      ->execute([$username]);

    // 2) Zerar créditos/limites diretos no usuário
    $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Max-All-Session','Expiration','Simultaneous-Use')")
      ->execute([$username]);
    $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Session-Timeout','Idle-Timeout','Mikrotik-Rate-Limit','Acct-Interim-Interval')")
      ->execute([$username]);

    // 3) Garantir Plano_Padrao (controle de uso diário fica na aplicação)
    ensure_default_plan($pdo, $username, 'Plano_Padrao', 10);

    $pdo->commit();
  } catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/** Atualiza (ou cria) Cleartext-Password do usuário no radcheck */
function set_radcheck_password(PDO $pdo, string $username, string $clearPassword): void
{
  if ($username === '' || $clearPassword === '')
    return;
  $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")
    ->execute([$username]);
  $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
    ->execute([$username, 'Cleartext-Password', $clearPassword]);
}

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Requisição inválida.';
  } else {
    $usernameRaw = trim($_POST['username'] ?? '');
    $phoneRaw = trim($_POST['telefone'] ?? '');
    $pw = (string) ($_POST['senha'] ?? '');
    $pw2 = (string) ($_POST['senha_confirm'] ?? '');

    // Normaliza CPF
    $username = $usernameRaw;
    $cpfDigits = only_digits($usernameRaw);
    if (strlen($cpfDigits) === 11)
      $username = $cpfDigits;

    $phone = normalize_phone_br($phoneRaw);

    // Regras de senha
    if (strlen($pw) < 6 || strlen($pw) > 64) {
      $error = 'A senha deve ter entre 6 e 64 caracteres.';
    } elseif ($pw !== $pw2) {
      $error = 'As senhas não conferem.';
    } elseif (preg_match('/\s/', $pw)) {
      $error = 'A senha não deve conter espaços.';
    } else {
      try {
        $pdo = db();
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone='-04:00'");

        // Verificação: clientes_info (CPF + telefone)
        $st = $pdo->prepare("SELECT telefone FROM clientes_info WHERE cpf = ? LIMIT 1");
        $st->execute([$username]);
        $telDb = $st->fetchColumn();

        if ($telDb === false) {
          $error = 'Não foi possível validar seus dados. Verifique as informações.';
        } else {
          $telDbNorm = normalize_phone_br((string) $telDb);
          if ($telDbNorm !== $phone) {
            $error = 'Não foi possível validar seus dados. Verifique as informações.';
          } else {
            // 1) Atualiza senha
            set_radcheck_password($pdo, $username, $pw);

            // 2) Rebaixa para VISITANTE (remove ilimitado, VIP e zera créditos)
            demote_to_visitor($pdo, $username);

            $success = true;

            // prepara sessão para facilitar login posterior
            $_SESSION['cliente_username'] = $username;
          }
        }
      } catch (\Throwable $e) {
        error_log('redefinir_senha: ' . $e->getMessage());
        $error = 'Erro ao atualizar a senha. Tente novamente.';
      }
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
  <title>Redefinir senha</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .wrap {
      display: grid;
      place-items: center;
      min-height: 70vh;
      padding: 16px;
    }

    .cardx {
      width: 100%;
      max-width: 460px;
      background: var(--card, #fff);
      border: 1px solid var(--borda, #ddd);
      border-radius: 14px;
      padding: 16px 16px 18px;
    }

    .grid {
      display: grid;
      gap: 12px;
    }

    .muted {
      color: #6b7280
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

    @media (max-width:480px) {
      .cardx {
        border-radius: 12px;
        padding: 14px
      }
    }
  </style>
  <script>
    function formatCPF(value) {
      let v = (value || '').replace(/\D+/g, '').slice(0, 11);
      if (v.length <= 3) return v;
      if (v.length <= 6) return v.replace(/(\d{3})(\d+)/, '$1.$2');
      if (v.length <= 9) return v.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
      return v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, '$1.$2.$3-$4');
    }
    function maskPhone(v) {
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
    }
    document.addEventListener('DOMContentLoaded', () => {
      const iuser = document.querySelector('input[name="username"]');
      const itel = document.querySelector('input[name="telefone"]');
      const p1 = document.getElementById('senha');
      const p2 = document.getElementById('senha_confirm');
      const eye1 = document.getElementById('eye1');
      const eye2 = document.getElementById('eye2');

      iuser?.addEventListener('input', () => {
        const before = iuser.value;
        const only = before.replace(/\D+/g, '');
        if (only.length <= 11) {
          const pos = iuser.selectionStart ?? before.length;
          iuser.value = formatCPF(before);
          const delta = iuser.value.length - before.length;
          const newPos = pos + delta;
          iuser.selectionStart = iuser.selectionEnd = newPos < 0 ? iuser.value.length : newPos;
        }
      }, { passive: true });

      itel?.addEventListener('input', () => {
        const before = itel.value;
        const pos = itel.selectionStart ?? before.length;
        itel.value = maskPhone(before);
        const delta = itel.value.length - before.length;
        const newPos = pos + delta;
        itel.selectionStart = itel.selectionEnd = newPos < 0 ? itel.value.length : newPos;
      }, { passive: true });

      eye1?.addEventListener('click', () => {
        if (!p1) return;
        const show = p1.type === 'password';
        p1.type = show ? 'text' : 'password';
        eye1.textContent = show ? 'ocultar' : 'mostrar';
      });
      eye2?.addEventListener('click', () => {
        if (!p2) return;
        const show = p2.type === 'password';
        p2.type = show ? 'text' : 'password';
        eye2.textContent = show ? 'ocultar' : 'mostrar';
      });

      document.getElementById('frm')?.addEventListener('submit', () => {
        if (iuser) iuser.value = iuser.value.replace(/\D+/g, '').slice(0, 11);
        if (itel) itel.value = itel.value.replace(/\D+/g, '').slice(0, 11);
      });
    });
  </script>
</head>

<body>
  <div class="header">
    <a href="login.php">← Voltar</a>
    <strong>Redefinir senha</strong>
    <span></span>
  </div>

  <div class="wrap">
    <div class="cardx">
      <p class="muted" style="margin-top:0;">
        Informe seus dados para redefinir a senha. Usaremos o telefone cadastrado para validar sua identidade.
      </p>

      <?php if ($error): ?>
        <div class="notice" style="color:#b91c1c;border-color:#fecaca;background:#fee2e2; margin-bottom:10px;">
          <?= h($error) ?>
        </div>
      <?php elseif ($success): ?>
        <div class="notice" style="color:#065f46;border-color:#a7f3d0;background:#ecfdf5;">
          Senha atualizada e perfil ajustado para <b>Visitante</b>. Você já pode fazer login normalmente.
        </div>
        <div style="margin-top:12px;">
          <a class="btn primary" href="login.php">Ir para o Login</a>
          <a class="btn" href="cliente.php" style="margin-left:8px;">Ir para Minha Conta</a>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
        <form id="frm" method="post" class="grid" novalidate>
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <label>
            CPF ou usuário
            <input type="text" name="username" inputmode="text" autocomplete="username" placeholder="000.000.000-00"
              maxlength="14" required>
          </label>

          <label>
            Telefone (DDD + número) cadastrado
            <input type="tel" name="telefone" inputmode="numeric" placeholder="(92) 9XXXX-XXXX" maxlength="16" required>
          </label>

          <div class="pwfield">
            <label>Nova senha</label>
            <input id="senha" type="password" name="senha" minlength="6" maxlength="64" autocomplete="new-password"
              required>
            <span id="eye1" class="toggle-eye">mostrar</span>
          </div>

          <div class="pwfield">
            <label>Confirme a nova senha</label>
            <input id="senha_confirm" type="password" name="senha_confirm" minlength="6" maxlength="64"
              autocomplete="new-password" required>
            <span id="eye2" class="toggle-eye">mostrar</span>
          </div>

          <button class="btn primary" type="submit">Redefinir senha</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>