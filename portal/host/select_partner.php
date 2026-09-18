<?php
require_once __DIR__ . '/_boot.php';
$pdo = host_db();
$userId = (int)($_SESSION['partner_admin_pending_user_id'] ?? $_SESSION['host_user_id'] ?? 0);
if ($userId <= 0) { header('Location: ' . host_admin_url('login')); exit; }
$memberships = partner_admin_memberships_for_user($pdo,$userId);
if (!$memberships) { partner_admin_session_clear(); header('Location: ' . host_admin_url('login')); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) $err = 'Sessão expirada. Recarregue a página.';
    else try {
        partner_admin_select_membership($pdo,$userId,(int)($_POST['membership_id'] ?? 0));
        header('Location: ' . host_admin_url('panel')); exit;
    } catch (Throwable $e) { $err = 'Não foi possível selecionar este estabelecimento.'; }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Escolher estabelecimento</title><link rel="stylesheet" href="/portal/assets/css/portal.css"><link rel="stylesheet" href="<?=host_h(host_admin_asset_url('host-admin.css'))?>"></head><body class="host-auth">
<main class="container"><section class="card host-auth-card"><h2>Qual estabelecimento deseja administrar?</h2><?php if($err):?><div class="notice"><?=host_h($err)?></div><?php endif;?><form method="post" class="host-picker"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><?php foreach($memberships as $membership):?><button class="btn" name="membership_id" value="<?=(int)$membership['membership_id']?>" type="submit"><strong><?=host_h($membership['name'])?></strong><br><small><?=host_h(partner_admin_roles()[$membership['role']] ?? $membership['role'])?></small></button><?php endforeach;?></form><p><a href="<?=host_h(host_admin_url('logout'))?>">Sair</a></p></section></main></body></html>
