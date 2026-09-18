<?php
require_once __DIR__ . '/_boot.php';
$pdo = host_db();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$valid = partner_admin_password_reset_token($pdo,$token);
$error = '';$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) $error = 'Sessão expirada. Recarregue a página.';
    elseif ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) $error = 'As senhas não coincidem.';
    else try { partner_admin_password_reset($pdo,$token,(string)$_POST['password']); $done=true; $valid=null; } catch(Throwable $e) { $error=partner_admin_public_error($e); }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nova senha</title><link rel="stylesheet" href="/portal/assets/css/portal.css"><link rel="stylesheet" href="<?=host_h(host_admin_asset_url('host-admin.css'))?>"></head><body class="host-auth"><main class="container"><section class="card host-auth-card host-auth-card--compact"><h2>Definir nova senha</h2><?php if($done):?><div class="notice">Senha alterada. As sessões anteriores foram revogadas. <a href="<?=host_h(host_admin_url('login'))?>">Entrar</a>.</div><?php elseif(!$valid):?><div class="notice">Link inválido ou expirado.</div><?php else:?><?php if($error):?><div class="notice error"><?=host_h($error)?></div><?php endif;?><form method="post" class="host-auth-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="token" value="<?=host_h($token)?>"><label>Nova senha<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Confirmar senha<input type="password" name="password2" minlength="12" autocomplete="new-password" required></label><small>Mínimo de 12 caracteres.</small><button class="btn primary" type="submit">Salvar senha</button></form><?php endif;?></section></main></body></html>
