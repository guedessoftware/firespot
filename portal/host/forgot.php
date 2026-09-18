<?php
require_once __DIR__ . '/_boot.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) $message = 'Sessão expirada. Recarregue a página.';
    else {
        try { partner_admin_password_reset_request(host_db(),(string)($_POST['email'] ?? '')); } catch (Throwable $e) { error_log('partner_admin reset request: '.$e->getMessage()); }
        $message = 'Se o e-mail estiver cadastrado e o envio estiver disponível, você receberá as instruções.';
    }
}
$enabled = partner_admin_password_reset_enabled(host_db());
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar acesso</title><link rel="stylesheet" href="/portal/assets/css/portal.css"><link rel="stylesheet" href="<?=host_h(host_admin_asset_url('host-admin.css'))?>"></head><body class="host-auth"><main class="container"><section class="card host-auth-card host-auth-card--compact"><h2>Recuperar acesso</h2><?php if($message):?><div class="notice"><?=host_h($message)?></div><?php endif;?><?php if(!$enabled):?><div class="notice">A recuperação pública está temporariamente indisponível. Solicite um novo convite ao administrador da FireSpot.</div><?php else:?><form method="post" class="host-auth-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><label>E-mail<input type="email" name="email" required></label><button class="btn primary" type="submit">Enviar instruções</button></form><?php endif;?><p><a href="<?=host_h(host_admin_url('login'))?>">Voltar ao login</a></p></section></main></body></html>
