<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/payment_wallets.php';
require_once __DIR__ . '/../app/control_center_finance.php';
require_once __DIR__ . '/../app/control_center_navigation.php';
require_once __DIR__ . '/components/status-pill.php';

$section=(string)($_GET['section']??'overview');
if(!in_array($section,['overview','delivery','settings'],true))$section='overview';
$flash=$_SESSION['finance_flash']??null;
unset($_SESSION['finance_flash']);
$pixEnabled=filter_var(settings_get('payment_pix_enabled',getenv('PAYMENT_PIX_ENABLED')?:'1'),FILTER_VALIDATE_BOOLEAN);
$pixExpire=max(5,min(60,(int)settings_get('payment_pix_expire_minutes',getenv('MP_PIX_EXPIRE_MINUTES')?:10)));
$pixInfo=(string)settings_get('payment_pix_checkout_info','');
$canManage=admin_has_capability('system.settings.manage');
$csrf=csrf_token();
$delivery=['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'filters'=>[],'kpis'=>[],'partners'=>[]];
if($section==='delivery'){
    $financePdo=db();$financePdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$financePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if(fs_payment_schema_ready($financePdo))$delivery=fs_control_center_deliveries($financePdo,$_GET);
}
$titulo = 'Financeiro';
$pageId = 'financeiro';
ob_start();
?>
<?php if($flash):?><div class="notice <?=!empty($flash['ok'])?'success':'error'?>"><?=htmlspecialchars((string)$flash['message'])?></div><?php endif;?>
<section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Negócio</span><h2>Workspace financeiro</h2><p>Uma entrada única para vendas, carteiras, conciliação, entrega e repasses.</p></div><a class="btn primary" href="vendas.php">Consultar vendas</a></section>
<?php if($section==='overview'):?>
<section class="fs-cc-workspace-grid">
  <a class="card" href="vendas.php"><span>Vendas</span><strong>Pedidos e pagamentos</strong><small>Receita, situação e desempenho comercial.</small></a>
  <a class="card" href="recebimentos.php?section=wallets"><span>Carteiras</span><strong>Destinos de recebimento</strong><small>Carteira global e carteiras próprias, sem expor segredos.</small></a>
  <a class="card" href="recebimentos.php?section=receipts"><span>Recebimentos</span><strong>Configuração financeira</strong><small>Vínculo de carteira por estabelecimento.</small></a>
  <a class="card" href="financeiro.php?section=delivery"><span>Conciliação e entrega</span><strong>Pagamento → acesso</strong><small>Confirmação, RADIUS, CoA e revisão manual.</small></a>
  <a class="card" href="monetizacao.php"><span>Repasses</span><strong>Ledger e fechamentos</strong><small>Marketplace, lançamentos e liquidações.</small></a>
  <a class="card" href="auditoria_vendas.php"><span>Auditoria</span><strong>Trilha financeira</strong><small>Eventos, confirmações e diagnóstico sanitizado.</small></a>
  <a class="card" href="financeiro.php?section=settings"><span>Configurações</span><strong>Políticas financeiras</strong><small>Pix, vencimento e parâmetros gerais de checkout.</small></a>
</section>
<?php elseif($section==='delivery'):$filters=$delivery['filters'];$kpis=$delivery['kpis'];?>
<section class="fs-cc-kpis" aria-label="Resumo de conciliação"><article class="card"><span>Pagamentos</span><strong><?=(int)($kpis['paid']??0)?></strong><small>pedidos pagos</small></article><article class="card"><span>Entregues</span><strong><?=(int)($kpis['delivered']??0)?></strong><small>acesso confirmado</small></article><article class="card"><span>Processando</span><strong><?=(int)($kpis['pending']??0)?></strong><small>fila ou reconexão</small></article><article class="card"><span>Revisão manual</span><strong><?=(int)($kpis['manual_review']??0)?></strong><small>sem ação automática</small></article></section>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Conciliação</span><h2>Pagamento e entrega de acesso</h2><p>Estado financeiro e resultado sanitizado da entrega. Nenhuma retentativa ou CoA é disparada ao abrir esta página.</p></div><a class="btn" href="auditoria_vendas.php">Abrir auditoria</a></header>
<?php if(!$filters):?><div class="notice error">O schema financeiro ainda não está disponível.</div><?php else:?>
<form method="get" class="fs-cc-filter-bar"><input type="hidden" name="section" value="delivery"><label><span>Estabelecimento</span><select name="partner_id"><option value="0">Todos</option><?php foreach($delivery['partners'] as $partner):?><option value="<?=(int)$partner['id']?>" <?=(int)$filters['partner_id']===(int)$partner['id']?'selected':''?>><?=fs_cc_escape($partner['name'])?></option><?php endforeach;?></select></label><label><span>Resultado</span><select name="state"><?php foreach(['all'=>'Todos','delivered'=>'Entregue','pending'=>'Processando','manual_review'=>'Revisão manual'] as $value=>$label):?><option value="<?=$value?>" <?=$filters['state']===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="btn primary" type="submit">Filtrar</button></form>
<div class="table-responsive"><table class="tabela"><thead><tr><th>Pedido</th><th>Estabelecimento</th><th>Pagamento</th><th>Fase RADIUS</th><th>Entrega</th><th>Última tentativa</th></tr></thead><tbody><?php foreach($delivery['rows'] as $row):$state=$row['delivery'];?><tr><td><strong>#<?=(int)$row['id']?></strong><br><small><?=fs_cc_escape($row['created_at'])?></small></td><td><a href="<?=fs_cc_escape(fs_control_center_partner_url((int)$row['partner_id'],'finance'))?>"><?=fs_cc_escape($row['partner_name']?:'Não identificado')?></a></td><td>R$ <?=number_format((int)$row['amount_cents']/100,2,',','.')?><br><small><?=fs_cc_escape($row['payment_method']?:'—')?> · <?=fs_cc_escape($row['wallet_name']?:'Carteira global')?></small></td><td><?=fs_cc_escape($row['radius_phase']?:'—')?><br><small><?=fs_cc_escape($row['radius_coa_status']?:'—')?> · <?=(int)$row['radius_coa_attempts']?> tentativa(s)</small></td><td><?=fs_cc_status_pill($state['label'],$state['tone'])?><br><small><?=fs_cc_escape($state['detail'])?></small></td><td><?=fs_cc_escape($row['radius_coa_last_attempt_at']?:'Não registrada')?></td></tr><?php endforeach;?><?php if(!$delivery['rows']):?><tr><td colspan="6">Nenhuma entrega encontrada.</td></tr><?php endif;?></tbody></table></div>
<?php if((int)$delivery['pages']>1):?><nav class="fs-cc-pagination" aria-label="Paginação da conciliação"><?php for($page=max(1,(int)$delivery['page']-2);$page<=min((int)$delivery['pages'],(int)$delivery['page']+2);$page++):$query=['section'=>'delivery','partner_id'=>$filters['partner_id'],'state'=>$filters['state'],'page'=>$page];?><a class="btn <?=$page===(int)$delivery['page']?'primary':''?>" href="financeiro.php?<?=fs_cc_escape(http_build_query($query))?>"><?=$page?></a><?php endfor;?></nav><?php endif;?>
<?php endif;?></section>
<?php else:?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Checkout</span><h2>Política global do Pix</h2><p>Define disponibilidade, validade e orientação geral. Credenciais e webhooks do provedor permanecem em Integrações; carteiras ficam em Financeiro &gt; Carteiras.</p></div><?=fs_cc_status_pill($pixEnabled?'Ativo':'Desativado',$pixEnabled?'success':'warning')?></header>
<?php if($canManage):?><form method="post" action="actions/finance.php" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="pix_settings_save"><div class="fs-cc-form-grid"><label class="fs-cc-check"><input type="checkbox" name="payment_pix_enabled" value="1" <?=$pixEnabled?'checked':''?>><span>Checkout Pix habilitado</span></label><label><span>Validade do QR Code</span><input type="number" name="payment_pix_expire_minutes" min="5" max="60" value="<?=$pixExpire?>" required><small>Entre 5 e 60 minutos.</small></label><label class="fs-cc-span-two"><span>Orientação exibida ao cliente</span><textarea name="payment_pix_checkout_info" rows="5" maxlength="1000"><?=htmlspecialchars($pixInfo)?></textarea><small>Texto simples; não aceita HTML.</small></label></div><div class="fs-cc-form-actions"><button class="btn primary" type="submit">Salvar política Pix</button></div></form><?php else:?><p class="muted">Seu papel possui somente acesso de consulta.</p><?php endif;?></section>
<section class="fs-cc-workspace-grid"><a class="card" href="integracoes.php?section=payments"><span>Integração</span><strong>Mercado Pago</strong><small>Credencial, webhook e diagnóstico técnico.</small></a><a class="card" href="recebimentos.php?section=wallets"><span>Carteiras</span><strong>Destinos financeiros</strong><small>Carteira global e carteiras próprias dos estabelecimentos.</small></a><a class="card" href="monetizacao.php?section=marketplace"><span>Repasses</span><strong>Marketplace</strong><small>OAuth das contas recebedoras e liquidações.</small></a></section>
<?php endif;?>
<?php $conteudo=(string)ob_get_clean();require __DIR__.'/layout.php';
