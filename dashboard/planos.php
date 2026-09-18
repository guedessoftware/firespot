<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
admin_require_capability('partners.view');
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/control_center_plans.php';
require_once __DIR__.'/../app/control_center_navigation.php';
require_once __DIR__.'/components/status-pill.php';

$titulo = "Planos";
$pageId = "planos";
$csrf = csrf_token();
$section=(string)($_GET['section']??'firespot');if(!in_array($section,['firespot','subscriptions','access','partner-access'],true))$section='firespot';$_GET['section']=$section;
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$platformPlans=$section==='firespot'?fs_control_center_platform_plan_versions($pdo):[];
$subscriptions=$section==='subscriptions'?fs_control_center_subscriptions($pdo,$_GET):['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'filters'=>[]];
$subscriptionKpis=$section==='subscriptions'?fs_control_center_subscription_kpis($pdo):[];
$accessKpis=$section==='access'?fs_control_center_access_plan_kpis($pdo):[];
$partnerAccess=$section==='partner-access'?fs_control_center_partner_access_plans($pdo,$_GET):['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'filters'=>[],'kpis'=>[]];

ob_start();
?>

<?php if($section==='firespot'):?>
<section class="card">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Produto contratado</span><h2>Planos FireSpot versionados</h2><p>Definem ferramentas e cotas da conta. Não são os planos de internet comprados pelo visitante.</p></div><span class="fs-cc-pill neutral"><?=count($platformPlans)?> versão(ões)</span></header>
  <div class="notice">Uma assinatura permanece vinculada à versão contratada. Versões novas não reclassificam estabelecimentos automaticamente; a atribuição é feita em <strong>Estabelecimento → Contrato e plano</strong>.</div>
</section>
<section class="fs-cc-plan-grid fs-cc-separated-card">
<?php foreach($platformPlans as $plan):?>
  <article class="card fs-cc-plan-card"><header><div><span><?=!empty($plan['internal_only'])?'Compatibilidade interna':'Plano comercial'?></span><h3><?=fs_cc_escape($plan['name'])?> · v<?=(int)$plan['version']?></h3></div><?=fs_cc_status_pill(!empty($plan['active'])?'Ativo':'Inativo',!empty($plan['active'])?'success':'neutral')?></header><p><?=fs_cc_escape($plan['description']?:'Sem descrição')?></p>
  <dl class="fs-cc-detail-grid"><div><dt>Pontos</dt><dd><?=(int)$plan['max_hotspots']?></dd></div><div><dt>NAS próprios</dt><dd><?=(int)$plan['max_nas']>0?(int)$plan['max_nas']:'Não incluído'?></dd></div><div><dt>Administradores</dt><dd><?=(int)$plan['max_admin_users']?></dd></div><div><dt>Relatórios</dt><dd><?=(int)$plan['max_report_range_days']?> dias</dd></div><div><dt>Overrides de cortesia</dt><dd><?=(int)$plan['custom_courtesy_overrides']?></dd></div><div><dt>Assinaturas atuais</dt><dd><?=(int)$plan['current_subscriptions']?></dd></div></dl>
  <div class="fs-cc-chip-list"><?php foreach($plan['features'] as $feature):if(empty($feature['enabled']))continue;?><span><?=fs_cc_escape(fs_partner_feature_labels()[$feature['feature_code']]??$feature['feature_code'])?></span><?php endforeach;?></div>
  </article>
<?php endforeach;?>
</section>

<?php elseif($section==='subscriptions'):$filters=$subscriptions['filters'];?>
<section class="fs-cc-kpis" aria-label="Resumo das assinaturas"><article class="card"><span>Estabelecimentos</span><strong><?=(int)($subscriptionKpis['total']??0)?></strong><small>com e sem assinatura</small></article><article class="card"><span>Ativas ou em teste</span><strong><?=(int)($subscriptionKpis['healthy']??0)?></strong><small>operação contratual normal</small></article><article class="card"><span>Exigem atenção</span><strong><?=(int)($subscriptionKpis['attention']??0)?></strong><small>carência ou atraso</small></article><article class="card"><span>Multipontos</span><strong><?=(int)($subscriptionKpis['multipoint']??0)?></strong><small>Gestão Avançada</small></article></section>
<section class="card">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Visão global</span><h2>Assinaturas e consumo de cotas</h2><p>Consolidação por estabelecimento. Alterações contratuais continuam na página da própria conta.</p></div><span class="fs-cc-pill neutral"><?=number_format((int)$subscriptions['total'],0,',','.')?> conta(s)</span></header>
  <form method="get" class="fs-cc-filter-bar"><input type="hidden" name="section" value="subscriptions"><label><span>Buscar estabelecimento</span><input name="q" maxlength="100" value="<?=fs_cc_escape($filters['q']??'')?>"></label><label><span>Situação</span><select name="status"><?php foreach(['all'=>'Todas','trial'=>'Teste','active'=>'Ativa','grace'=>'Carência','past_due'=>'Em atraso','suspended'=>'Suspensa','ended'=>'Encerrada'] as $value=>$label):?><option value="<?=$value?>" <?=($filters['status']??'all')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label><span>Plano FireSpot</span><select name="plan"><option value="all">Todos</option><?php $seen=[];foreach(fs_platform_plan_catalog($pdo,true) as $plan):if(isset($seen[$plan['code']]))continue;$seen[$plan['code']]=true;?><option value="<?=fs_cc_escape($plan['code'])?>" <?=($filters['plan']??'all')===$plan['code']?'selected':''?>><?=fs_cc_escape($plan['name'])?></option><?php endforeach;?></select></label><button class="btn primary" type="submit">Filtrar</button></form>
  <div class="table-responsive"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>Assinatura</th><th>Pontos</th><th>Administradores</th><th>Cortesia/ponto</th><th>Ação</th></tr></thead><tbody><?php foreach($subscriptions['rows'] as $row):?><tr><td><strong><?=fs_cc_escape($row['name'])?></strong><br><small><?=fs_cc_escape($row['code'])?></small></td><td><?=fs_cc_escape($row['plan_name']?:'Sem plano')?><?=!empty($row['plan_version'])?' · v'.(int)$row['plan_version']:''?><br><?=fs_cc_status_pill($row['status']?:'Sem assinatura',in_array($row['status'],['trial','active'],true)?'success':'warning')?> <?php if($row['quota_alert']):?><?=fs_cc_status_pill('Cota ≥ 80%','warning')?><?php endif;?></td><td><?=$row['used_hotspots']?> / <?=$row['max_hotspots']?></td><td><?=$row['used_admins']?> / <?=$row['max_admin_users']?></td><td><?=$row['used_overrides']?> / <?=$row['custom_courtesy_overrides']?></td><td><a class="btn" href="<?=fs_cc_escape(fs_control_center_partner_url((int)$row['id'],'contract'))?>">Abrir contrato</a></td></tr><?php endforeach;?><?php if(!$subscriptions['rows']):?><tr><td colspan="6">Nenhuma assinatura encontrada.</td></tr><?php endif;?></tbody></table></div>
  <?php if((int)$subscriptions['pages']>1):?><nav class="fs-cc-pagination" aria-label="Paginação das assinaturas"><?php for($page=max(1,(int)$subscriptions['page']-2);$page<=min((int)$subscriptions['pages'],(int)$subscriptions['page']+2);$page++):$query=['section'=>'subscriptions','q'=>$filters['q'],'status'=>$filters['status'],'plan'=>$filters['plan'],'page'=>$page];?><a class="btn <?=$page===(int)$subscriptions['page']?'primary':''?>" href="planos.php?<?=fs_cc_escape(http_build_query($query))?>"><?=$page?></a><?php endfor;?></nav><?php endif;?>
</section>

<?php elseif($section==='access'):?>

<section class="fs-context-callout">
  <div>
    <span>Escopo dos planos</span>
    <strong>Esta tela administra os planos globais vendidos ao visitante.</strong>
    <p>Preços exclusivos ficam em Estabelecimento → Planos de acesso. Quando há ao menos um plano próprio ativo, ele substitui este catálogo global somente naquela conta.</p>
  </div>
  <a href="estabelecimentos.php">Abrir estabelecimentos</a>
</section>
<section class="fs-cc-kpis" aria-label="Resumo dos planos globais"><article class="card"><span>Total</span><strong><?=(int)($accessKpis['total']??0)?></strong><small>planos cadastrados</small></article><article class="card"><span>Ativos</span><strong><?=(int)($accessKpis['active']??0)?></strong><small>disponíveis como fallback</small></article><article class="card"><span>Inativos</span><strong><?=(int)($accessKpis['inactive']??0)?></strong><small>preservados no histórico</small></article></section>

<!-- Barra de ações -->
<div class="card plan-access-toolbar">
  <div class="card-header plan-access-header">
    <h3>Gerenciar Planos</h3>
    <div class="plan-access-actions">
      <button id="btn-new" class="theme-btn" type="button">➕ Novo Plano</button>
      <button id="btn-reload" class="theme-btn" type="button" title="Recarregar">⟳</button>
    </div>
  </div>
  <div class="content-grid plan-access-filters">
    <div>
      <label class="muted" for="f-q">Buscar</label>
      <input id="f-q" type="text" placeholder="Nome / grupo / descrição" />
    </div>
    <div>
      <label class="muted" for="f-ativo">Ativo?</label>
      <select id="f-ativo">
        <option value="">Todos</option>
        <option value="1">Ativo</option>
        <option value="0">Inativo</option>
      </select>
    </div>
    <div>
      <label class="muted" for="f-order">Ordenar por</label>
      <select id="f-order">
        <option value="atualizado_em">Atualizado</option>
        <option value="nome">Nome</option>
        <option value="grupo">Grupo</option>
        <option value="preco_centavos">Preço</option>
        <option value="ordem">Ordem</option>
      </select>
    </div>
    <div>
      <button id="btn-apply" class="theme-btn plan-apply" type="button">Aplicar</button>
    </div>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header"><h3 class="plan-section-title">Planos cadastrados</h3></div>
  <div class="table-responsive">
    <table class="tabela" id="tbl-planos">
      <thead>
        <tr>
          <th>#</th>
          <th>Nome</th>
          <th>Grupo</th>
          <th>Preço</th>
          <th>↓ Mbps</th>
          <th>↑ Mbps</th>
          <th>Duração</th>
          <th>Ativo</th>
          <th>Ordem</th>
          <th>Atualizado</th>
          <th class="plan-actions-column">Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="11">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal (criar/editar) -->
<div id="modal-planos" class="modal-backdrop" aria-hidden="true">
  <div class="card plan-modal-card">
    <div class="card-header plan-access-header">
      <h3 id="modal-title" class="plan-section-title">Novo Plano</h3>
      <button id="modal-close" class="theme-btn" type="button" title="Fechar">✕</button>
    </div>

    <form id="form-plano" class="plan-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="id" id="pl-id" value="">

      <div class="content-grid plan-grid-two-wide">
        <div>
          <label class="muted" for="pl-nome">Nome do plano *</label>
          <input name="nome" id="pl-nome" required maxlength="120" />
        </div>
        <div>
          <label class="muted" for="pl-grupo">Grupo *</label>
          <input list="grupos-radius" name="grupo" id="pl-grupo" required maxlength="120" />
          <datalist id="grupos-radius"></datalist>
        </div>
      </div>

      <div class="content-grid plan-grid-four">
        <div>
          <label class="muted" for="pl-preco">Preço (R$)</label>
          <input name="preco" id="pl-preco" inputmode="decimal" placeholder="ex.: 19,90" />
        </div>
        <div>
          <label class="muted" for="pl-down">Download (Mbps)</label>
          <input type="number" min="0" step="1" name="down_mbps" id="pl-down" />
        </div>
        <div>
          <label class="muted" for="pl-up">Upload (Mbps)</label>
          <input type="number" min="0" step="1" name="up_mbps" id="pl-up" />
        </div>
        <div>
          <label class="muted" for="pl-duracao">Duração (min)</label>
          <input type="number" min="1" step="1" name="duracao_min" id="pl-duracao" />
        </div>
      </div>

      <div class="content-grid plan-grid-two">
        <div>
          <label class="muted" for="pl-ordem">Ordem</label>
          <input type="number" min="0" step="1" name="ordem" id="pl-ordem" value="100" />
        </div>
        <div class="plan-check-row">
          <label><input type="checkbox" name="ativo" id="pl-ativo" checked> Ativo</label>
          <span class="muted">* campos obrigatórios</span>
        </div>
      </div>

      <div>
        <label class="muted" for="pl-desc">Descrição</label>
        <textarea name="descricao" id="pl-desc" rows="3"></textarea>
      </div>

      <div class="plan-form-actions">
        <button type="button" id="btn-cancel" class="theme-btn">Cancelar</button>
        <button type="submit" id="btn-save" class="theme-btn">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php else:$filters=$partnerAccess['filters'];$kpis=$partnerAccess['kpis'];?>
<section class="fs-context-callout"><div><span>Catálogos próprios</span><strong>Consolidação sem formulário duplicado.</strong><p>Esta visão compara os planos vendidos por cada estabelecimento. Toda criação ou alteração continua em Estabelecimento → Planos de acesso.</p></div><a href="estabelecimentos.php">Abrir estabelecimentos</a></section>
<section class="fs-cc-kpis" aria-label="Resumo dos planos próprios"><article class="card"><span>Planos próprios</span><strong><?=(int)($kpis['total']??0)?></strong><small>histórico preservado</small></article><article class="card"><span>Ativos</span><strong><?=(int)($kpis['active']??0)?></strong><small>ofertados atualmente</small></article><article class="card"><span>Estabelecimentos</span><strong><?=(int)($kpis['partners']??0)?></strong><small>com catálogo próprio</small></article></section>
<section class="card">
  <header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Visão consolidada</span><h2>Planos de acesso por estabelecimento</h2><p><?=number_format((int)$partnerAccess['total'],0,',','.')?> resultado(s) nos filtros atuais.</p></div></header>
  <form method="get" class="fs-cc-filter-bar"><input type="hidden" name="section" value="partner-access"><label><span>Buscar</span><input name="q" maxlength="100" value="<?=fs_cc_escape($filters['q']??'')?>" placeholder="Estabelecimento, código ou plano"></label><label><span>Situação</span><select name="status"><option value="all">Todas</option><option value="active" <?=($filters['status']??'all')==='active'?'selected':''?>>Ativos</option><option value="inactive" <?=($filters['status']??'all')==='inactive'?'selected':''?>>Inativos</option></select></label><button class="btn primary" type="submit">Filtrar</button></form>
  <div class="table-responsive"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>Plano de acesso</th><th>Preço</th><th>Duração</th><th>Velocidade</th><th>Situação</th><th>Ação</th></tr></thead><tbody><?php foreach($partnerAccess['rows'] as $row):?><tr><td><strong><?=fs_cc_escape($row['partner_name'])?></strong><br><small><?=fs_cc_escape($row['partner_code'])?></small></td><td><strong><?=fs_cc_escape($row['name'])?></strong><br><small><?=fs_cc_escape($row['description']?:'Sem descrição')?></small></td><td>R$ <?=number_format((int)$row['price_cents']/100,2,',','.')?></td><td><?=(int)$row['duration_minutes']?> min</td><td>↓ <?=number_format((int)$row['download_kbps'],0,',','.')?> / ↑ <?=number_format((int)$row['upload_kbps'],0,',','.')?> Kbps</td><td><?=fs_cc_status_pill((int)$row['active']===1?'Ativo':'Inativo',(int)$row['active']===1?'success':'neutral')?></td><td><a class="btn" href="<?=fs_cc_escape(fs_control_center_partner_url((int)$row['partner_id'],'access-plans'))?>">Abrir estabelecimento</a></td></tr><?php endforeach;?><?php if(!$partnerAccess['rows']):?><tr><td colspan="7">Nenhum plano próprio encontrado.</td></tr><?php endif;?></tbody></table></div>
  <?php if((int)$partnerAccess['pages']>1):?><nav class="fs-cc-pagination" aria-label="Paginação dos planos próprios"><?php for($page=max(1,(int)$partnerAccess['page']-2);$page<=min((int)$partnerAccess['pages'],(int)$partnerAccess['page']+2);$page++):$query=['section'=>'partner-access','q'=>$filters['q'],'status'=>$filters['status'],'page'=>$page];?><a class="btn <?=$page===(int)$partnerAccess['page']?'primary':''?>" href="planos.php?<?=fs_cc_escape(http_build_query($query))?>"><?=$page?></a><?php endfor;?></nav><?php endif;?>
</section>

<?php endif;?>

<?php
$conteudo = ob_get_clean();
include "layout.php";
