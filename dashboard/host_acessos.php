<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/partner_admin.php';
require_once __DIR__ . '/../app/control_center_administrators.php';
require_once __DIR__ . '/../app/control_center_navigation.php';
require_once __DIR__ . '/components/status-pill.php';

if (!admin_has_capability('partner.administrators.view')) {
    http_response_code(403);
    $titulo = 'Acesso restrito';
    $pageId = 'host_acessos';
    $conteudo = '<div class="card"><h2>Acesso restrito</h2><p>Seu papel administrativo não permite consultar responsáveis dos estabelecimentos.</p></div>';
    require __DIR__ . '/layout.php';
    exit;
}

// Esta é uma visão global estritamente de supervisão. Toda mutação de equipe
// pertence à página canônica do estabelecimento.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    $titulo = 'Ação indisponível';
    $pageId = 'host_acessos';
    $conteudo = '<div class="card"><h2>Ação indisponível</h2><p>Abra o estabelecimento e use a seção Equipe para alterar vínculos, convites ou sessões.</p></div>';
    require __DIR__ . '/layout.php';
    exit;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$supervision = fs_control_center_administrator_supervision($pdo, $_GET);
$filters = $supervision['filters'];
$q = (string)$filters['q'];
$page = (int)$supervision['page'];
$pages = (int)$supervision['pages'];
$total = (int)$supervision['total'];
$members = $supervision['rows'];
$invitations = fs_control_center_pending_administrator_invitations($pdo);

$titulo = 'Administradores dos estabelecimentos';
$pageId = 'host_acessos';
ob_start();
?>
<section class="fs-cc-toolbar" aria-label="Supervisão de acessos">
  <div><span class="fs-cc-eyebrow">Estabelecimentos</span><h2>Supervisão de administradores</h2><p>Consulta global de vínculos e convites. Alterações são feitas somente em Estabelecimento → Equipe.</p></div>
  <a class="btn" href="estabelecimentos.php">Voltar aos estabelecimentos</a>
</section>

<section class="card">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Vínculos</span><h2>Responsáveis dos estabelecimentos</h2><p><?=number_format($total, 0, ',', '.')?> vínculo(s) encontrado(s).</p></div></header>
  <form method="get" class="fs-cc-filter-bar" role="search">
    <label><span>Buscar</span><input name="q" maxlength="100" value="<?=fs_cc_escape($q)?>" placeholder="Pessoa ou estabelecimento"></label>
    <button class="btn primary" type="submit">Buscar</button>
    <?php if($q !== ''):?><a class="btn" href="host_acessos.php">Limpar</a><?php endif;?>
  </form>
  <div class="table-responsive"><table class="tabela"><thead><tr><th>Pessoa</th><th>Estabelecimento</th><th>Papel e vínculo</th><th>Último acesso</th><th>Ação</th></tr></thead><tbody>
  <?php foreach($members as $member):?>
    <tr><td><strong><?=fs_cc_escape((string)($member['name'] ?: 'Sem nome'))?></strong><br><?=fs_cc_escape($member['email'])?><?php if((int)$member['partner_count']>1):?><br><small class="muted"><?=(int)$member['partner_count']?> estabelecimentos vinculados</small><?php endif;?></td><td><strong><?=fs_cc_escape($member['partner_name'])?></strong><br><small><?=fs_cc_escape($member['partner_code'])?></small></td><td><?=fs_cc_escape(partner_admin_roles()[$member['role']] ?? $member['role'])?><br><?=fs_cc_status_pill((int)$member['membership_active']===1?'Vínculo ativo':'Vínculo inativo',(int)$member['membership_active']===1?'success':'neutral')?> <?=fs_cc_status_pill((int)$member['user_active']===1?'Identidade ativa':'Identidade inativa',(int)$member['user_active']===1?'success':'neutral')?></td><td><?=fs_cc_escape((string)($member['last_login_at'] ?: 'Nunca'))?></td><td><a class="btn" href="<?=fs_cc_escape(fs_control_center_partner_url((int)$member['partner_id'],'team'))?>">Abrir equipe</a></td></tr>
  <?php endforeach;?>
  <?php if(!$members):?><tr><td colspan="5" class="fs-cc-empty"><strong>Nenhum vínculo encontrado.</strong><span>Revise a busca informada.</span></td></tr><?php endif;?>
  </tbody></table></div>
  <?php if($pages>1):?><nav class="fs-cc-pagination" aria-label="Paginação dos responsáveis"><?php if($page>1):?><a class="btn" href="?<?=fs_cc_escape(http_build_query(['q'=>$q,'page'=>$page-1]))?>">← Anterior</a><?php endif;?><span>Página <?=$page?> de <?=$pages?></span><?php if($page<$pages):?><a class="btn" href="?<?=fs_cc_escape(http_build_query(['q'=>$q,'page'=>$page+1]))?>">Próxima →</a><?php endif;?></nav><?php endif;?>
</section>

<section class="card fs-cc-separated-card">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Pendências</span><h2>Convites ativos</h2><p>Os tokens e links de ativação nunca são exibidos nesta visão global.</p></div></header>
  <div class="table-responsive"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>E-mail</th><th>Papel</th><th>Validade</th><th>Ação</th></tr></thead><tbody>
  <?php foreach($invitations as $invitation):?><tr><td><strong><?=fs_cc_escape($invitation['partner_name'])?></strong><br><small><?=fs_cc_escape($invitation['partner_code'])?></small></td><td><?=fs_cc_escape($invitation['email'])?></td><td><?=fs_cc_escape(partner_admin_roles()[$invitation['role']] ?? $invitation['role'])?></td><td><?=fs_cc_escape($invitation['expires_at'])?></td><td><a class="btn" href="<?=fs_cc_escape(fs_control_center_partner_url((int)$invitation['partner_id'],'team'))?>">Abrir equipe</a></td></tr><?php endforeach;?>
  <?php if(!$invitations):?><tr><td colspan="5">Nenhum convite pendente.</td></tr><?php endif;?>
  </tbody></table></div>
</section>
<?php
$conteudo = (string)ob_get_clean();
require __DIR__ . '/layout.php';
