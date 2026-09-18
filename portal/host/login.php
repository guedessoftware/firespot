<?php
require_once __DIR__ . '/_boot.php';

$err = '';
try { ensure_tables(); } catch (Throwable $e) { $err = partner_admin_public_error($e,'O painel está temporariamente indisponível.'); }
if (partner_admin_context(host_db())) { header('Location: ' . host_admin_url('panel')); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Sessão expirada. Recarregue a página.';
    } else {
        try {
            $result = partner_admin_authenticate(host_db(),(string)($_POST['email'] ?? ''),(string)($_POST['password'] ?? ''));
            header('Location: ' . (count($result['memberships']) > 1 ? host_admin_url('select') : host_admin_url('panel')));
            exit;
        } catch (Throwable $e) {
            $err = partner_admin_public_error($e,'Não foi possível entrar agora.');
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Painel do estabelecimento</title><link rel="stylesheet" href="/portal/assets/css/portal.css"><link rel="stylesheet" href="<?=host_h(host_admin_asset_url('host-admin.css'))?>"><link rel="icon" href="/favicon.ico"></head>
<body class="host-auth"><div class="header inline"><img class="host-auth-logo" src="/portal/assets/img/logo-dark.png" alt="Fire Network"><span></span></div>
<main class="container"><section class="card host-auth-card host-auth-card--compact"><h2>Acessar o painel</h2><p class="notice">Use o e-mail vinculado à equipe do estabelecimento.</p>
<?php if ($err): ?><div class="notice error"><?= host_h($err) ?></div><?php endif; ?>
<form method="post" class="host-auth-form host-auth-form--roomy"><input type="hidden" name="csrf" value="<?= host_h(csrf_token()) ?>"><label>E-mail<input name="email" type="email" autocomplete="username" required></label><label>Senha<input name="password" type="password" autocomplete="current-password" required></label><div class="host-auth-actions"><a href="<?=host_h(host_admin_url('forgot'))?>">Esqueci minha senha</a><button class="btn primary" type="submit">Entrar</button></div></form></section></main>
<div class="footer">© <?= date('Y') ?> FireSpot</div></body></html>
