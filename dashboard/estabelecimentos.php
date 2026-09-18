<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
admin_require_capability('partners.view');
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/control_center_partners.php';
require_once __DIR__ . '/../app/control_center_navigation.php';
require_once __DIR__ . '/components/status-pill.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$canViewOwners = admin_has_capability('partner.administrators.view');
if (!$canViewOwners) $_GET['owners'] = '0';
$result = fs_control_center_partners($pdo,$_GET);
$filters = $result['filters'];
$kpis = fs_control_center_partner_kpis($pdo);
$plans = fs_platform_plan_catalog($pdo,true);
$planOptions = [];
foreach ($plans as $plan) $planOptions[(string)$plan['code']] = (string)$plan['name'];
$portalLabels = ['inherit'=>'Padrão global','classic'=>'Clássico','v2'=>'V2','v3'=>'V3'];
$subscriptionLabels = ['trial'=>'Trial','active'=>'Ativa','grace'=>'Carência','past_due'=>'Em atraso','suspended'=>'Suspensa','ended'=>'Encerrada'];

function fs_cc_query_url(array $filters, array $changes): string
{
    $query = array_merge($filters,$changes);
    foreach ($query as $key=>$value) if ($value === '' || $value === 'all' || ($key === 'page' && (int)$value === 1) || ($key === 'owners' && (int)$value === 0)) unset($query[$key]);
    return 'estabelecimentos.php' . ($query ? '?' . http_build_query($query) : '');
}

if ((string)($_GET['format'] ?? '') === 'csv') {
    $rows = fs_control_center_partner_export_rows($pdo,$filters,$canViewOwners && (int)$filters['owners'] === 1);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="firespot-estabelecimentos-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output','wb');
    if ($output === false) throw new RuntimeException('Não foi possível gerar a exportação.');
    fwrite($output,"\xEF\xBB\xBF");
    $header = ['Código','Estabelecimento','Situação','Plano FireSpot','Assinatura','Portal','Pontos','Pontos ativos','Rascunhos','NAS associados','Recebimento','Alertas','Autogestão'];
    if ($canViewOwners && (int)$filters['owners'] === 1) $header[] = 'Responsáveis';
    fputcsv($output,$header,';');
    foreach ($rows as $row) {
        $line = [
            $row['code'],$row['name'],(int)$row['active']===1?'Ativo':'Inativo',$row['plan_name']?:'Sem plano',
            $subscriptionLabels[(string)($row['subscription_status']??'')]??'Não atribuída',$portalLabels[(string)$row['portal_mode']]??'Padrão global',
            (int)$row['points_total'],(int)$row['points_active'],(int)$row['points_draft'],(int)$row['nas_total'],
            (int)$row['independent_billing']===1?'Independente':'FireSpot',(int)$row['point_alerts'],(int)$row['self_service_enabled']===1?'Ativa':'Inativa',
        ];
        if ($canViewOwners && (int)$filters['owners'] === 1) $line[] = $row['responsible_summary'] ?: 'Sem responsável ativo';
        fputcsv($output,array_map('fs_control_center_csv_cell',$line),';');
    }
    fclose($output);
    exit;
}

$listReturnUrl = fs_cc_query_url($filters,[]);

$titulo = 'Estabelecimentos';
$pageId = 'estabelecimentos';
ob_start();
?>
<section class="fs-cc-toolbar" aria-label="Ações dos estabelecimentos">
  <div><span class="fs-cc-eyebrow">Carteira de contas</span><h2>Visão global dos estabelecimentos</h2><p>A lista resume os pontos; toda configuração permanece dentro do estabelecimento.</p></div>
  <div class="fs-cc-row-actions">
    <a class="btn" href="<?=fs_cc_escape(fs_cc_query_url($filters,['format'=>'csv','page'=>1]))?>">Exportar inventário</a>
    <?php if($canViewOwners):?><a class="btn" href="<?=fs_cc_escape(fs_cc_query_url($filters,['owners'=>(int)$filters['owners']===1?0:1,'page'=>1]))?>"><?=(int)$filters['owners']===1?'Ocultar responsáveis':'Mostrar responsáveis'?></a><?php endif;?>
    <?php if($canViewOwners):?><a class="btn" href="host_acessos.php">Supervisionar acessos</a><?php endif;?>
    <?php if(admin_has_capability('partners.create')):?><a class="btn primary" href="estabelecimento.php?mode=create">Criar estabelecimento</a><?php endif;?>
  </div>
</section>

<section class="fs-cc-kpis" aria-label="Indicadores dos estabelecimentos">
  <article class="card"><span>Ativos</span><strong><?= (int)$kpis['active'] ?></strong><small><?= (int)$kpis['total'] ?> conta(s) no total</small></article>
  <article class="card"><span>Multipontos</span><strong><?= (int)$kpis['multipoint'] ?></strong><small>Resumo por estabelecimento</small></article>
  <article class="card"><span>Com alerta</span><strong><?= (int)$kpis['alerts'] ?></strong><small>Pontos ativos que exigem revisão</small></article>
  <article class="card"><span>Recebimento próprio</span><strong><?= (int)$kpis['independent_billing'] ?></strong><small>Carteiras independentes</small></article>
  <article class="card"><span>Autogestão</span><strong><?= (int)$kpis['self_service'] ?></strong><small>Painel do estabelecimento ativo</small></article>
</section>

<section class="card fs-cc-list-card" aria-labelledby="partner-list-title">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Operação</span><h2 id="partner-list-title">Estabelecimentos</h2><p><?= (int)$result['total'] ?> resultado(s) nos filtros atuais.</p></div></header>
  <form method="get" class="fs-cc-filters" role="search">
    <label><span>Busca</span><input name="q" value="<?= fs_cc_escape($filters['q']) ?>" placeholder="Nome ou código"></label>
    <label><span>Situação</span><select name="status"><option value="all">Todas</option><option value="active" <?=$filters['status']==='active'?'selected':''?>>Ativos</option><option value="inactive" <?=$filters['status']==='inactive'?'selected':''?>>Inativos</option></select></label>
    <label><span>Plano FireSpot</span><select name="plan"><option value="all">Todos</option><?php foreach($planOptions as $code=>$name):?><option value="<?=fs_cc_escape($code)?>" <?=$filters['plan']===$code?'selected':''?>><?=fs_cc_escape($name)?></option><?php endforeach;?></select></label>
    <label><span>Portal</span><select name="portal"><option value="all">Todos</option><?php foreach($portalLabels as $code=>$label):?><option value="<?=fs_cc_escape($code)?>" <?=$filters['portal']===$code?'selected':''?>><?=fs_cc_escape($label)?></option><?php endforeach;?></select></label>
    <label><span>Recebimento</span><select name="billing"><option value="all">Todos</option><option value="global" <?=$filters['billing']==='global'?'selected':''?>>FireSpot</option><option value="independent" <?=$filters['billing']==='independent'?'selected':''?>>Independente</option></select></label>
    <label><span>Pontos</span><select name="points"><option value="all">Todos</option><option value="none" <?=$filters['points']==='none'?'selected':''?>>Sem ponto</option><option value="single" <?=$filters['points']==='single'?'selected':''?>>Um ponto</option><option value="multiple" <?=$filters['points']==='multiple'?'selected':''?>>Multipontos</option></select></label>
    <div class="fs-cc-filter-actions"><button class="btn primary" type="submit">Filtrar</button><a class="btn" href="estabelecimentos.php">Limpar</a></div>
  </form>

  <div class="table-responsive">
    <table class="tabela fs-cc-partner-table">
      <thead><tr><th>Estabelecimento</th><th>Situação</th><th>Plano FireSpot</th><th>Portal</th><th>Pontos</th><th>Recebimento</th><th>Prontidão</th><?php if($canViewOwners&&(int)$filters['owners']===1):?><th>Responsáveis</th><?php endif;?><th>Ação</th></tr></thead>
      <tbody>
      <?php foreach($result['rows'] as $partner):
        $alerts=(int)$partner['point_alerts'];
        $pointSummary=(int)$partner['points_total'].' ponto(s) · '.(int)$partner['points_active'].' ativo(s)';
        if((int)$partner['points_draft']>0)$pointSummary.=' · '.(int)$partner['points_draft'].' rascunho(s)';
      ?>
        <tr>
          <td><strong><?=fs_cc_escape($partner['name'])?></strong><br><small class="muted">Código <?=fs_cc_escape($partner['code'])?></small></td>
          <td><?=fs_cc_status_pill((int)$partner['active']===1?'Ativo':'Inativo',(int)$partner['active']===1?'success':'neutral')?></td>
          <td><strong><?=fs_cc_escape($partner['plan_name']?:'Sem plano')?></strong><br><small class="muted"><?=fs_cc_escape($subscriptionLabels[(string)($partner['subscription_status']??'')]??'Não atribuída')?></small></td>
          <td><?=fs_cc_escape($portalLabels[(string)$partner['portal_mode']]??'Padrão global')?></td>
          <td><strong><?=fs_cc_escape($pointSummary)?></strong><br><small class="muted"><?=(int)$partner['nas_total']?> NAS associado(s)</small></td>
          <td><?=(int)$partner['independent_billing']===1?'Independente':'FireSpot'?></td>
          <td><?=$alerts>0?fs_cc_status_pill($alerts.' alerta(s)','warning'):fs_cc_status_pill('Sem alertas','success')?></td>
          <?php if($canViewOwners&&(int)$filters['owners']===1):?><td><?=fs_cc_escape($partner['responsible_summary']?:'Sem responsável ativo')?></td><?php endif;?>
          <td><a class="btn primary" href="<?=fs_cc_escape(fs_control_center_partner_url((int)$partner['id'],'summary',['return'=>$listReturnUrl]))?>">Abrir estabelecimento</a></td>
        </tr>
      <?php endforeach;?>
      <?php if(!$result['rows']):?><tr><td colspan="<?=$canViewOwners&&(int)$filters['owners']===1?9:8?>" class="fs-cc-empty"><strong>Nenhum estabelecimento encontrado.</strong><span>Revise os filtros informados.</span></td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($result['pages']>1):?>
  <nav class="fs-cc-pagination" aria-label="Paginação dos estabelecimentos">
    <?php if($result['page']>1):?><a class="btn" href="<?=fs_cc_escape(fs_cc_query_url($filters,['page'=>$result['page']-1]))?>">← Anterior</a><?php endif;?>
    <span>Página <?=(int)$result['page']?> de <?=(int)$result['pages']?></span>
    <?php if($result['page']<$result['pages']):?><a class="btn" href="<?=fs_cc_escape(fs_cc_query_url($filters,['page'=>$result['page']+1]))?>">Próxima →</a><?php endif;?>
  </nav>
  <?php endif;?>
</section>
<?php
$conteudo = (string)ob_get_clean();
require __DIR__ . '/layout.php';
