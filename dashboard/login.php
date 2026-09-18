<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    header('Location: index.php');
    exit;
}

$database = db();
$maxAttempts = 5;
$lockSeconds = 120;
$_SESSION['login_attempts'] ??= 0;
$_SESSION['login_locked_until'] ??= 0;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < (int) $_SESSION['login_locked_until']) {
        $remaining = (int) $_SESSION['login_locked_until'] - time();
        $error = "Muitas tentativas. Tente novamente em {$remaining} segundos.";
    } elseif (!csrf_check((string) ($_POST['csrf'] ?? ''))) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $username = trim((string) ($_POST['usuario'] ?? ''));
        $password = (string) ($_POST['senha'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Informe usuário e senha.';
        } else {
            $statement = $database->prepare('SELECT id, username, password_hash, role FROM admin_users WHERE username = :username LIMIT 1');
            $statement->execute(['username' => $username]);
            $admin = $statement->fetch(PDO::FETCH_ASSOC);
            if ($admin && password_verify($password, (string) $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_name'] = (string) $admin['username'];
                $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'admin');
                $_SESSION['login_attempts'] = 0;
                $_SESSION['login_locked_until'] = 0;
                try {
                    $update = $database->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
                    $update->execute(['id' => (int) $admin['id']]);
                } catch (Throwable $ignored) {
                }
                header('Location: index.php');
                exit;
            }

            $_SESSION['login_attempts']++;
            if ((int) $_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['login_locked_until'] = time() + $lockSeconds;
                $error = "Muitas tentativas inválidas. Aguarde {$lockSeconds} segundos.";
            } else {
                $remaining = $maxAttempts - (int) $_SESSION['login_attempts'];
                $error = "Usuário ou senha incorretos. Você ainda tem {$remaining} tentativa(s).";
            }
        }
    }
}

$csrfToken = csrf_token();
$stylesheetVersion = (int) (@filemtime(__DIR__ . '/assets/css/pages/login.css') ?: 1);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Administrativo — FireSpot</title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/pages/login.css?v=<?=$stylesheetVersion?>">
</head>
<body>
  <div class="login-shell">
    <section class="login-brand" aria-label="FireSpot">
      <img class="login-brand__logo" src="assets/img/logo-dark.png" alt="Fire Network">
      <div class="login-brand__content">
        <span class="login-brand__eyebrow">FireSpot Operations</span>
        <h2>Controle da operação em um só lugar.</h2>
        <p>Clientes, estabelecimentos, vendas e infraestrutura organizados para uma administração mais rápida.</p>
      </div>
      <div class="login-brand__status"><i aria-hidden="true"></i><span>Ambiente administrativo seguro</span></div>
    </section>

    <main class="login-card">
      <div class="login-card__logo"><img src="assets/img/logo-light.png" alt="FireSpot"></div>
      <h1>Login administrativo</h1>
      <p class="login-card__subtitle">Acesse o painel de controle do FireSpot.</p>

      <?php if ($error !== ''): ?>
        <div class="login-alert login-alert--error" role="alert"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div>
      <?php endif; ?>
      <?php if ($success !== ''): ?>
        <div class="login-alert login-alert--success" role="status"><?=htmlspecialchars($success, ENT_QUOTES, 'UTF-8')?></div>
      <?php endif; ?>

      <form method="post" class="login-form">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8')?>">
        <div>
          <label for="usuario">Usuário</label>
          <input class="login-input" type="text" id="usuario" name="usuario" autocomplete="username" required autofocus>
        </div>
        <div>
          <label for="senha">Senha</label>
          <input class="login-input" type="password" id="senha" name="senha" autocomplete="current-password" required>
        </div>
        <button class="login-button" type="submit">Entrar</button>
      </form>

      <p class="login-card__footer">© <?=date('Y')?> FireSpot · Painel administrativo</p>
    </main>
  </div>
</body>
</html>
