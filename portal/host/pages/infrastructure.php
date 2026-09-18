<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')||!isset($infrastructureView)||!in_array($infrastructureView,['nas','hotspots'],true)){http_response_code(404);exit;}

$showNas=$infrastructureView==='nas';
$showHotspots=$infrastructureView==='hotspots';

$canManageHotspots=partner_admin_role_has($role,'hotspots.manage')
    &&fs_partner_has_entitlement($pdo,$partnerId,'hotspots.draft.manage',true);
$canManageNas=partner_admin_role_has($role,'nas.manage')
    &&fs_partner_has_entitlement($pdo,$partnerId,'nas.manage',true);
$canPrepareNas=partner_admin_role_has($role,'nas.prepare')
    &&fs_partner_has_entitlement($pdo,$partnerId,'nas.prepare',true);
$canRetireNas=partner_admin_role_has($role,'nas.retire')
    &&fs_partner_has_entitlement($pdo,$partnerId,'nas.retire',true);
$canViewPointCourtesy=partner_admin_role_has($role,'courtesy.view')&&fs_partner_has_entitlement($pdo,$partnerId,'courtesy.view',false);
$canViewPointAnalytics=partner_admin_role_has($role,'reports.view')&&fs_partner_has_entitlement($pdo,$partnerId,'reports.advanced',false);
$canViewPointReports=partner_admin_role_has($role,'reports.view')&&!$canViewPointAnalytics&&fs_partner_has_entitlement($pdo,$partnerId,'reports.basic',false);
$canViewPointSales=partner_admin_role_has($role,'sales.view')&&fs_partner_has_entitlement($pdo,$partnerId,'finance.view',false);
$commercialSchemaReady=fs_partner_hotspot_commercial_schema_ready($pdo);
$canManagePointCommercial=partner_admin_role_has($role,'hotspot_commercial.manage')
    &&fs_portal_config_module_allowed($pdo,$context,'infrastructure')
    &&fs_partner_has_entitlement($pdo,$partnerId,'hotspots.draft.manage',false)
    &&fs_partner_has_entitlement($pdo,$partnerId,'courtesy.hotspot_override.manage',false)
    &&fs_partner_has_entitlement($pdo,$partnerId,'wallet.manage',false);
$pointConfigurationSchemaReady=fs_partner_hotspot_configuration_schema_ready($pdo);
$nasPolicySchemaReady=fs_nas_hotspot_policy_schema_ready($pdo);
$availableNas=array_values(array_filter($partnerNas,static fn(array $nas):bool=>
    ($nas['assignment_status']??'')==='ready'
    &&($nas['base_status']??'')==='ready'
    &&!empty($nasInterfacesByNas[(int)$nas['id']])
    &&(!$canManageNas||($nas['management_mode']??'')==='partner_owned')
));
$nasById=[];$ownedNasCount=0;$centralNasCount=0;
foreach($partnerNas as $nas){
    $nasById[(int)$nas['id']]=$nas;
    if(($nas['management_mode']??'')==='partner_owned')$ownedNasCount++;else $centralNasCount++;
}
$nasUsage=fs_partner_quota_usage($pdo,$partnerId,'max_nas');
$pointUsage=fs_partner_quota_usage($pdo,$partnerId,'max_hotspots');
$activePointCount=count(array_filter($partnerHotspots,static fn(array $hotspot):bool=>(int)($hotspot['active']??0)===1));
$draftPointCount=count(array_filter($partnerHotspots,static fn(array $hotspot):bool=>($hotspot['management_state']??'')==='draft'));
$operationLabels=[
    'nas_verify'=>'Verificar acesso','nas_sync'=>'Sincronizar inventário','nas_prepare'=>'Preparar NAS',
    'hotspot_apply'=>'Aplicar ponto','hotspot_deactivate'=>'Retirar ponto','hotspot_cleanup'=>'Limpar configuração anterior',
];
$statusLabels=['queued'=>'Na fila','running'=>'Em andamento','retry'=>'Nova tentativa','succeeded'=>'Concluída','failed'=>'Falhou','cancelled'=>'Cancelada'];
$ownershipStatusLabels=['pending'=>'validação pendente','verifying'=>'validando acesso','verified'=>'acesso verificado','preparing'=>'preparando base','ready'=>'pronto','error'=>'requer revisão'];
$healthStatusLabels=['ok'=>'Operacional','warning'=>'Atenção','error'=>'Falha','offline'=>'Offline','unknown'=>'Não conferido'];
$baseStatusLabels=['ready'=>'Pronta','pending'=>'Pendente','error'=>'Requer revisão','unknown'=>'Não conferida'];
$coaStatusLabels=['ready'=>'Pronto','pending'=>'Pendente','error'=>'Indisponível','unknown'=>'Não conferido'];
?>
<section class="stack host-infrastructure">
  <article class="card host-infrastructure__hero">
    <div class="host-infrastructure__heading">
      <div>
        <span class="host-section-eyebrow"><?=$showNas?'Equipamentos do estabelecimento':'Instalações do estabelecimento'?></span>
        <h1><?=$showNas?'NAS':'Pontos Hotspot'?></h1>
        <p class="subtle"><?=$showNas?'Administre acesso ao equipamento, RADIUS, inventário, telemetria e a política usada na criação de novos pontos.':'Administre cada instalação Hotspot: NAS responsável, interface, VLAN, gateway, pool, DNS e estado da configuração.'?></p>
      </div>
      <div class="host-infrastructure__actions">
        <?php if($showNas&&$canManageNas):?><button class="btn primary" type="button" data-host-modal-open="host-modal-nas-register">Cadastrar NAS</button><?php endif;?>
        <?php if($showHotspots&&$canManageHotspots&&$availableNas):?><button class="btn primary" type="button" data-host-modal-open="host-modal-new-point">Novo ponto</button><?php endif;?>
      </div>
    </div>
    <?php if($showNas):?>
      <div class="host-infrastructure__summary" aria-label="Resumo dos NAS">
        <div><strong><?=count($partnerNas)?></strong><span>NAS associados</span></div>
        <div><strong><?=$ownedNasCount?></strong><span>Do estabelecimento</span></div>
        <div><strong><?=$centralNasCount?></strong><span>Gestão FireSpot</span></div>
        <div><strong><?=$nasUsage?> / <?=(int)$platformLimits['max_nas']?></strong><span>Uso da cota de NAS</span></div>
      </div>
    <?php else:?>
      <div class="host-infrastructure__summary" aria-label="Resumo dos pontos Hotspot">
        <div><strong><?=count($partnerHotspots)?></strong><span>Pontos cadastrados</span></div>
        <div><strong><?=$activePointCount?></strong><span>Pontos ativos</span></div>
        <div><strong><?=$draftPointCount?></strong><span>Rascunhos</span></div>
        <div><strong><?=$pointUsage?> / <?=(int)$platformLimits['max_hotspots']?></strong><span>Uso da cota de pontos</span></div>
      </div>
    <?php endif;?>
  </article>

  <?php if($showNas):?>
  <article class="card host-infrastructure__section" id="nas-associados">
    <div class="host-section-heading">
      <div><h2>NAS associados</h2><p class="subtle">Configurações do equipamento ficam aqui. Ações aparecem somente nos NAS pertencentes ao estabelecimento.</p></div>
      <span class="pill"><?=count($partnerNas)?> equipamento(s)</span>
    </div>
    <div class="host-nas-grid">
    <?php foreach($partnerNas as $nas):
      $isOwned=($nas['management_mode']??'')==='partner_owned';
      $hasCredentials=(int)($nas['credentials_configured']??0)===1;
      $ownershipStatus=(string)($nas['ownership_status']??'');
      $canPrepareCurrent=$hasCredentials&&in_array($ownershipStatus,['verified','ready'],true);
      $nasName=(string)($nas['shortname']?:'NAS #'.$nas['id']);
      $nasModalKey='nas-'.(int)$nas['id'];
      $healthStatus=(string)($nas['health_status']??'unknown');
      $baseStatus=(string)($nas['base_status']??'pending');
      $coaStatus=(string)($nas['coa_status']??'pending');
    ?>
      <article class="host-nas-card">
        <header class="host-nas-card__header">
          <div class="host-cell-stack">
            <strong><?=host_h($nasName)?></strong>
            <small><?=host_h($nas['type']?:'equipamento de rede')?><?php if($isOwned):?> · <?=host_h((string)($nas['management_address']?:'IPv4 não informado'))?><?php endif;?> · SSH <?=(int)($nas['mgmt_port']?:22)?></small>
          </div>
          <?php if($isOwned):?><span class="pill ok">Do estabelecimento</span><?php else:?><span class="pill">Gestão FireSpot</span><?php endif;?>
        </header>
        <div class="host-nas-card__body">
          <section class="host-nas-fact">
            <span>RADIUS e acesso</span>
            <strong><?=host_h($nas['radius_server_name']??'Não atribuído')?></strong>
            <small><?=host_h($nas['radius_host']??'Destino pendente')?><?php if(!empty($nas['radius_port'])):?>:<?=(int)$nas['radius_port']?><?php endif;?></small>
            <small>Base <?=host_h($baseStatusLabels[$baseStatus]??$baseStatus)?> · CoA <?=host_h($coaStatusLabels[$coaStatus]??$coaStatus)?><?php if(!empty($nas['coa_port'])):?>:<?=(int)$nas['coa_port']?><?php endif;?> · revisão <?=(int)($nas['config_revision']??0)?></small>
          </section>
          <section class="host-nas-fact">
            <span>Saúde e inventário</span>
            <strong><?=host_h($healthStatusLabels[$healthStatus]??$healthStatus)?></strong>
            <small>RouterOS <?=host_h($nas['routeros_version']??'não lido')?> · <?=isset($nas['latency_ms'])?(int)$nas['latency_ms'].' ms':'latência não lida'?></small>
            <small><?=(int)($nas['interface_count']??0)?> interfaces · <?=(int)($nas['hotspot_host_count']??0)?> hosts · <?=host_h(host_datetime($nas['health_checked_at']??null))?></small>
          </section>
          <section class="host-nas-fact host-nas-fact--policy">
            <span>Política dos novos pontos</span>
            <strong>VLAN <?=(int)$nas['vlan_start']?>–<?=(int)$nas['vlan_end']?> · /<?=(int)$nas['prefix_length']?></strong>
            <small><?=host_h((string)$nas['network_template'])?> · DNS <?=host_h((string)$nas['default_dns_servers'])?></small>
            <?php if(is_array($nas['next_network']??null)):?><span class="host-next-allocation">Próxima: VLAN <?=(int)$nas['next_vlan']?> · <?=host_h((string)$nas['next_network']['cidr'])?></span><?php else:?><span class="pill off">Faixa esgotada</span><?php endif;?>
          </section>
          <section class="host-nas-fact host-nas-fact--count">
            <span>Pontos neste NAS</span>
            <strong><?=(int)$nas['hotspot_count']?></strong>
            <small><?=(int)$nas['hotspot_count']===1?'Hotspot associado':'Hotspots associados'?></small>
          </section>
        </div>
        <footer class="host-nas-card__footer">
          <?php if($isOwned&&$canManageNas):?>
            <div class="host-row-actions">
              <button class="btn compact" type="button" data-host-modal-open="host-modal-edit-<?=$nasModalKey?>"><?=$hasCredentials?'Acesso':'Definir acesso'?></button>
              <?php if($nasPolicySchemaReady):?><button class="btn compact primary" type="button" data-host-modal-open="host-modal-policy-<?=$nasModalKey?>">Política de pontos</button><?php endif;?>
              <?php if($hasCredentials):?>
                <form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_sync"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="nas_id" value="<?=(int)$nas['id']?>"><input type="hidden" name="idempotency_key" value="<?=host_h($formIdempotency.'-sync-'.(int)$nas['id'])?>"><button class="btn compact" type="submit">Atualizar inventário</button></form>
                <?php if($canPrepareNas&&$canPrepareCurrent):?><button class="btn compact" type="button" data-host-modal-open="host-modal-prepare-<?=$nasModalKey?>">Preparar RADIUS</button><?php endif;?>
              <?php endif;?>
              <?php if($canRetireNas):?><button class="btn compact danger" type="button" data-host-modal-open="host-modal-retire-<?=$nasModalKey?>">Aposentar</button><?php endif;?>
            </div>
            <small>Acesso <?=$hasCredentials?'protegido':'a definir'?><?=$ownershipStatus!==''?' · '.host_h($ownershipStatusLabels[$ownershipStatus]??$ownershipStatus):''?></small>
          <?php else:?><span class="subtle">Consulta disponível; alterações técnicas são realizadas pela FireSpot.</span><?php endif;?>
        </footer>
      </article>
    <?php endforeach;?>
    <?php if(!$partnerNas):?><div class="host-empty-cell">Nenhum NAS associado a este estabelecimento.</div><?php endif;?>
    </div>
  </article>
  <?php endif;?>

  <?php if($showHotspots):?>
  <article class="card host-infrastructure__section" id="pontos-do-estabelecimento">
    <div class="host-section-heading">
      <div><h2>Pontos do estabelecimento</h2><p class="subtle">Cada ponto mostra claramente o NAS responsável e sua rede reservada.</p></div>
    </div>
    <div class="table-wrap host-infrastructure__table-wrap"><table class="host-infrastructure__table host-infrastructure__points"><thead><tr><th>Ponto</th><th>NAS e interface</th><th>Rede</th><th>Estado</th><th>Ação</th></tr></thead><tbody>
    <?php foreach($partnerHotspots as $hotspot):
      $nas=$nasById[(int)($hotspot['nas_id']??0)]??null;
      $isEditableDraft=($hotspot['management_state']??'')==='draft'&&!(int)$hotspot['active']&&!(int)$hotspot['applied_config_version'];
      $pointNasOwned=($nas['management_mode']??'')==='partner_owned';
      $configurationRequest=$hotspotConfigurationRequests[(int)$hotspot['id']]??null;
      $pointModalKey='point-'.(int)$hotspot['id'];
      $canConfigurePoint=$canManageHotspots&&$pointNasOwned&&($isEditableDraft||$pointConfigurationSchemaReady);
    ?>
      <tr>
        <td><div class="host-cell-stack"><strong><?=host_h($hotspot['name'])?></strong><small><?=host_h($hotspot['code'])?><?=$hotspot['is_default']?' · principal':''?></small></div></td>
        <td><div class="host-cell-stack"><strong><?=host_h($hotspot['shortname']?:($nas['shortname']??'Não associado'))?></strong><small><?=host_h($hotspot['interface_name']?:'Interface pendente')?></small><span class="pill <?=$pointNasOwned?'ok':''?>"><?=$pointNasOwned?'NAS do estabelecimento':'NAS FireSpot'?></span></div></td>
        <td><div class="host-cell-stack"><strong>VLAN <?=host_h($hotspot['vlan_id']?:'—')?></strong><small><?=host_h($hotspot['gateway_ip']?:'Gateway pendente')?><?php if(!empty($hotspot['network_prefix_length'])):?>/<?=(int)$hotspot['network_prefix_length']?><?php endif;?></small></div></td>
        <td><div class="host-cell-stack"><span class="pill <?=$hotspot['active']?'ok':''?>"><?=host_h($hotspot['management_state']??($hotspot['active']?'ativo':'rascunho'))?></span><small>Desejada v<?=(int)($hotspot['desired_config_version']??0)?> · aplicada v<?=(int)($hotspot['applied_config_version']??0)?></small><?php if($configurationRequest):?><span class="pill warning">Alteração solicitada · r<?=(int)$configurationRequest['revision']?></span><?php endif;?></div></td>
        <td>
          <div class="host-row-actions">
            <button class="btn compact <?=$canConfigurePoint?'primary':''?>" type="button" data-host-modal-open="host-modal-<?=$pointModalKey?>"><?=$canConfigurePoint?'Configurar':'Ver detalhes'?></button>
            <?php if($pointNasOwned&&!$pointConfigurationSchemaReady&&!$isEditableDraft):?><span class="subtle">Aguardando atualização</span><?php endif;?>
          </div>
        </td>
      </tr>
    <?php endforeach;?>
    <?php if(!$partnerHotspots):?><tr><td colspan="5" class="host-empty-cell">Nenhum ponto cadastrado.</td></tr><?php endif;?>
    </tbody></table></div>
  </article>
  <?php endif;?>

  <article class="card host-infrastructure__section" id="historico-tecnico">
    <div class="host-section-heading"><div><h2><?=$showNas?'Histórico dos NAS':'Histórico dos pontos'?></h2><p class="subtle"><?=$showNas?'Verificações, sincronizações e preparações dos equipamentos.':'Aplicações, retiradas e limpezas das instalações Hotspot.'?> Abrir esta página nunca executa uma operação no equipamento.</p></div></div>
    <div class="table-wrap host-infrastructure__table-wrap"><table class="host-infrastructure__table"><thead><tr><th>Solicitação</th><th>Destino</th><th>Operação</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach($changeRequests as $request):$requestNas=$nasById[(int)$request['nas_id']]??null;?><tr>
      <td><div class="host-cell-stack"><strong>#<?=(int)$request['id']?></strong><small><?=host_h(host_datetime($request['created_at']??null))?></small></div></td>
      <td><div class="host-cell-stack"><strong><?=host_h($requestNas['shortname']??('NAS #'.(int)$request['nas_id']))?></strong><small><?=!empty($request['hotspot_id'])?'Ponto #'.(int)$request['hotspot_id']:'Equipamento'?></small></div></td>
      <td><?=host_h($operationLabels[$request['operation']]??$request['operation'])?></td>
      <td><span class="pill <?=$request['status']==='succeeded'?'ok':($request['status']==='failed'?'off':'')?>"><?=host_h($statusLabels[$request['status']]??$request['status'])?></span></td>
      <td><?=host_h($request['error_code']?:'—')?></td>
    </tr><?php endforeach;?>
    <?php if(!$changeRequests):?><tr><td colspan="5" class="host-empty-cell">Nenhuma operação de <?=$showNas?'NAS':'ponto Hotspot'?> registrada.</td></tr><?php endif;?>
    </tbody></table></div>
  </article>

  <?php if($showHotspots&&$canManageHotspots&&$availableNas):?>
  <div class="host-modal" id="host-modal-new-point" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="host-modal-new-point-title" tabindex="-1">
      <div class="host-modal__header"><div><span class="host-section-eyebrow">Novo rascunho</span><h2 id="host-modal-new-point-title">Novo ponto</h2></div><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p class="subtle">O FireSpot consumirá a próxima VLAN livre e calculará rede, gateway, pool e DNS pela política do NAS escolhido. A criação não altera o equipamento.</p>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_draft_create"><input type="hidden" name="return_page" value="hotspots">
        <div class="form-grid host-new-point-grid">
          <label>Nome do ponto<input name="name" maxlength="150" required></label>
          <label>NAS responsável<select name="nas_id" required data-host-nas-select><?php foreach($availableNas as $nas):$next=$nas['next_network']??null;?><option value="<?=(int)$nas['id']?>" data-next-allocation="<?=host_h(is_array($next)?('VLAN '.(int)$nas['next_vlan'].' · '.$next['cidr'].' · gateway '.$next['gateway_ip'].' · pool '.$next['pool_start'].'–'.$next['pool_end'].' · DNS '.$next['dns_servers']):'Faixa automática esgotada')?>"><?=host_h($nas['shortname']?:'NAS #'.$nas['id'])?></option><?php endforeach;?></select></label>
          <label>Interface-base<select name="nas_interface_id" required data-host-interface-select><?php foreach($availableNas as $nas):foreach($nasInterfacesByNas[(int)$nas['id']]??[] as $interface):?><option data-nas-id="<?=(int)$nas['id']?>" value="<?=(int)$interface['id']?>"><?=host_h($interface['interface_name'])?></option><?php endforeach;endforeach;?></select></label>
        </div>
        <div class="notice host-span-full" data-host-allocation-preview>Selecione o NAS para conferir a próxima alocação.</div>
        <div class="host-modal__footer"><span class="subtle">Cota de pontos: <?=$pointUsage?> / <?=(int)$platformLimits['max_hotspots']?></span><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Criar rascunho</button></div>
      </form>
    </section>
  </div>
  <?php endif;?>

  <?php if($showHotspots):foreach($partnerHotspots as $hotspot):
    $pointId=(int)$hotspot['id'];
    $pointNas=$nasById[(int)($hotspot['nas_id']??0)]??null;
    $pointNasOwned=($pointNas['management_mode']??'')==='partner_owned';
    $isEditableDraft=($hotspot['management_state']??'')==='draft'&&!(int)$hotspot['active']&&!(int)$hotspot['applied_config_version'];
    $configurationRequest=$hotspotConfigurationRequests[$pointId]??null;
    $selectedNasId=(int)($configurationRequest['requested_nas_id']??$hotspot['nas_id']??0);
    $selectedInterfaceId=(int)($configurationRequest['requested_nas_interface_id']??$hotspot['nas_interface_id']??0);
    $selectedName=(string)($configurationRequest['requested_name']??$hotspot['name']);
    $selectedDns=(string)($configurationRequest['requested_dns_servers']??$hotspot['dns_servers']??'');
    $canSubmitConfiguration=$canManageHotspots&&$pointNasOwned&&(int)$hotspot['active']===1&&$pointConfigurationSchemaReady&&!empty($availableNas);
    $pointCourtesy=$pointCourtesyPolicies[$pointId]??null;
    $pointCommercial=$pointCommercialPolicies[$pointId]??fs_partner_hotspot_commercial_defaults($context,$portalConfig);
    $pointSalesEnabled=(int)($pointCommercial['paid_access_enabled']??0)===1;
    $pointCourtesyMode=(string)($pointCommercial['courtesy_mode']??'disabled');
    $pointPaymentWindowEnabled=$pointSalesEnabled&&(int)($pointCommercial['payment_window_enabled']??0)===1;
    $courtesyConsumptionLabel=($pointCourtesy['consumption_mode']??'elapsed')==='online'?'somente enquanto conectado':'tempo corrido';
    $courtesySourceLabel=($pointCourtesy['policy_source']??'')==='hotspot'?'Regra própria deste ponto':'Padrão herdado';
  ?>
  <div class="host-modal" id="host-modal-point-<?=$pointId?>" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog host-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="host-modal-point-<?=$pointId?>-title" tabindex="-1">
      <div class="host-modal__header"><div><span class="host-section-eyebrow"><?=$pointNasOwned?'Ponto do estabelecimento':'Gestão FireSpot'?></span><h2 id="host-modal-point-<?=$pointId?>-title"><?=host_h($hotspot['name'])?></h2></div><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p class="subtle">Configuração efetiva atualmente armazenada. Consultar esta janela não acessa nem altera o RouterOS.</p>
      <dl class="host-point-technical">
        <div><dt>NAS responsável</dt><dd><?=host_h($hotspot['shortname']?:($pointNas['shortname']??'Não associado'))?></dd></div>
        <div><dt>Interface-base</dt><dd><?=host_h($hotspot['interface_name']?:'Pendente')?></dd></div>
        <div><dt>Rede reservada</dt><dd>VLAN <?=host_h($hotspot['vlan_id']?:'—')?> · <code><?=host_h($hotspot['gateway_ip']?:'gateway pendente')?><?php if(!empty($hotspot['network_prefix_length'])):?>/<?=(int)$hotspot['network_prefix_length']?><?php endif;?></code></dd></div>
        <div><dt>Pool</dt><dd><code><?=host_h(($hotspot['pool_start']?:'—').' — '.($hotspot['pool_end']?:'—'))?></code></dd></div>
        <div><dt>DNS upstream</dt><dd><code><?=host_h($hotspot['dns_servers']?:'—')?></code></dd></div>
        <div><dt>DNS lógico</dt><dd><code><?=host_h($hotspot['dns_name']?:'zona automática pelo gateway')?></code></dd></div>
        <div><dt>Servidor Hotspot</dt><dd><code>hs_<?=host_h(strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',(string)$hotspot['code'])))?></code></dd></div>
        <div><dt>Servidor DHCP</dt><dd><code>dhcp_<?=host_h(strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',(string)$hotspot['code'])))?></code></dd></div>
      </dl>
      <section class="host-point-policies" aria-label="Acesso e resultados deste ponto">
        <div class="host-point-policies__heading"><strong>Acesso deste ponto</strong><span>As modalidades abaixo são independentes dos demais pontos</span></div>
        <div class="host-point-policies__grid">
          <article class="host-point-policy">
            <header><span>Cobrança</span><span class="pill <?=$pointSalesEnabled?'ok':'off'?>"><?=$pointSalesEnabled?'Ativa':'Desativada'?></span></header>
            <strong><?=$pointSalesEnabled?'Planos pagos disponíveis':'Sem venda de acesso'?></strong>
            <small>A carteira continua sendo a do estabelecimento.</small>
          </article>
          <?php if($canViewPointCourtesy&&$pointCourtesy):?>
          <article class="host-point-policy">
            <header><span>Cortesia</span><span class="pill <?=$pointCourtesyMode!=='disabled'&&!empty($pointCourtesy['enabled'])?'ok':'off'?>"><?=$pointCourtesyMode!=='disabled'&&!empty($pointCourtesy['enabled'])?'Ativa':'Desativada'?></span></header>
            <strong><?=$pointCourtesyMode!=='disabled'&&!empty($pointCourtesy['enabled'])?(int)$pointCourtesy['grant_minutes'].' min por liberação':'Sem acesso de cortesia'?></strong>
            <small><?=host_h($courtesySourceLabel)?> · <?=host_h($courtesyConsumptionLabel)?> · intervalo <?=(int)($pointCourtesy['cooldown_after_end_minutes']??0)?> min</small>
          </article>
          <?php endif;?>
          <article class="host-point-policy">
            <header><span>Janela Pix</span><span class="pill <?=$pointPaymentWindowEnabled?'ok':'off'?>"><?=$pointPaymentWindowEnabled?'Ativa':'Desativada'?></span></header>
            <strong><?=$pointPaymentWindowEnabled?(int)($pointCommercial['payment_window_minutes']??2).' min de internet temporária':'Sem internet temporária'?></strong>
            <small><?=(int)($pointCommercial['payment_window_daily_limit']??3)?> tentativa(s) a cada <?=max(1,(int)ceil(((int)($pointCommercial['payment_window_period_minutes']??1440))/60))?> h · intervalo <?=(int)($pointCommercial['payment_window_cooldown_minutes']??10)?> min · QR <?=$pixQrExpirationMinutes?> min</small>
          </article>
          <article class="host-point-policy host-point-policy--links">
            <header><span>Resultados do ponto</span></header>
            <div class="host-row-actions">
              <?php if($canViewPointAnalytics):?><a class="btn compact" href="?page=analytics&amp;period=30&amp;hotspot_id=<?=$pointId?>">Métricas</a><?php endif;?>
              <?php if($canViewPointReports):?><a class="btn compact" href="?page=reports&amp;hotspot_id=<?=$pointId?>">Métricas</a><?php endif;?>
              <?php if($canViewPointSales):?><a class="btn compact" href="?page=finance&amp;hotspot_id=<?=$pointId?>">Vendas</a><?php endif;?>
            </div>
          </article>
        </div>
      </section>

      <?php if($pointNasOwned&&(int)$hotspot['active']===1&&$canManagePointCommercial&&$commercialSchemaReady&&$pointCourtesy):?>
      <details class="host-point-commercial" open>
        <summary><span><strong>Configurar acesso e regras comerciais</strong><small>Somente para <?=host_h($hotspot['name'])?></small></span></summary>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_commercial_save"><input type="hidden" name="return_page" value="hotspots"><input type="hidden" name="hotspot_id" value="<?=$pointId?>">
          <fieldset class="host-commercial-modes"><legend>Modalidades disponíveis</legend>
            <label class="host-switch"><input type="checkbox" name="paid_access_enabled" value="1" <?=$pointSalesEnabled?'checked':''?>><span><strong>Cobrança por acesso</strong><small>Exibe os planos pagos neste ponto.</small></span></label>
            <label>Cortesia<select name="courtesy_mode"><option value="disabled" <?=$pointCourtesyMode==='disabled'?'selected':''?>>Desativada</option><option value="direct" <?=$pointCourtesyMode==='direct'?'selected':''?>>Ativa</option><option value="sponsored" <?=$pointCourtesyMode==='sponsored'?'selected':''?>>Ativa após anúncio</option></select></label>
            <label class="host-switch"><input type="checkbox" name="payment_window_enabled" value="1" <?=$pointPaymentWindowEnabled?'checked':''?>><span><strong>Janela temporária Pix</strong><small>Libera somente o necessário para concluir o pagamento.</small></span></label>
          </fieldset>
          <div class="host-commercial-rules">
            <fieldset><legend>Regras de cortesia</legend><div class="form-grid host-commercial-grid">
              <label>Duração da cortesia (min)<input type="number" name="grant_minutes" min="1" max="1440" value="<?=(int)($pointCourtesy['grant_minutes']??30)?>" required></label>
              <label>Validade do crédito (min)<input type="number" name="credit_validity_minutes" min="1" max="525600" value="<?=(int)($pointCourtesy['credit_validity_minutes']??1440)?>" required></label>
              <label>Consumo<select name="consumption_mode"><option value="online" <?=($pointCourtesy['consumption_mode']??'online')==='online'?'selected':''?>>Somente conectado</option><option value="elapsed" <?=($pointCourtesy['consumption_mode']??'')==='elapsed'?'selected':''?>>Tempo corrido</option></select></label>
              <label>Identificação<select name="auth_mode"><option value="anonymous" <?=($pointCourtesy['auth_mode']??'')==='anonymous'?'selected':''?>>Sem conta</option><option value="account" <?=($pointCourtesy['auth_mode']??'')==='account'?'selected':''?>>Por conta</option><option value="account_device" <?=($pointCourtesy['auth_mode']??'account_device')==='account_device'?'selected':''?>>Conta e dispositivo</option></select></label>
              <label>Usos por dispositivo<input type="number" name="device_max_grants" min="1" value="<?=host_h((string)($pointCourtesy['device_max_grants']??''))?>" placeholder="Sem limite"></label>
              <label>Janela do dispositivo (min)<input type="number" name="device_period_minutes" min="1" value="<?=host_h((string)($pointCourtesy['device_period_minutes']??''))?>" placeholder="Sem janela"></label>
              <label>Usos por conta<input type="number" name="account_max_grants" min="1" value="<?=host_h((string)($pointCourtesy['account_max_grants']??''))?>" placeholder="Sem limite"></label>
              <label>Janela da conta (min)<input type="number" name="account_period_minutes" min="1" value="<?=host_h((string)($pointCourtesy['account_period_minutes']??''))?>" placeholder="Sem janela"></label>
              <label>Intervalo após terminar (min)<input type="number" name="cooldown_after_end_minutes" min="0" max="525600" value="<?=(int)($pointCourtesy['cooldown_after_end_minutes']??0)?>" required></label>
            </div></fieldset>
            <fieldset><legend>Janela Pix</legend><div class="form-grid host-commercial-grid">
              <label>Duração (min)<input type="number" name="payment_window_minutes" min="1" max="5" value="<?=(int)($pointCommercial['payment_window_minutes']??2)?>" required></label>
              <label>Tentativas por período<input type="number" name="payment_window_daily_limit" min="1" max="12" value="<?=(int)($pointCommercial['payment_window_daily_limit']??3)?>" required></label>
              <label>Intervalo entre tentativas (min)<input type="number" name="payment_window_cooldown_minutes" min="5" max="60" value="<?=(int)($pointCommercial['payment_window_cooldown_minutes']??10)?>" required></label>
              <label>Renovação das tentativas (h)<input type="number" name="payment_window_period_hours" min="1" max="168" value="<?=max(1,(int)ceil(((int)($pointCommercial['payment_window_period_minutes']??1440))/60))?>" required></label>
            </div><p class="subtle">A validade do QR Code (<?=$pixQrExpirationMinutes?> min) é uma proteção central e não altera esta janela.</p></fieldset>
          </div>
          <div class="host-modal__footer"><span class="subtle">Vale para novas jornadas neste ponto. Não acessa o NAS nem altera o RouterOS.</span><button class="btn primary" type="submit">Salvar regras do ponto</button></div>
        </form>
      </details>
      <?php elseif($pointNasOwned&&!$commercialSchemaReady):?>
        <div class="notice warning">As regras comerciais por ponto estarão disponíveis após a migração operacional 056.</div>
      <?php endif;?>

      <?php if(!$pointNasOwned):?>
        <div class="notice">Este ponto utiliza um NAS da FireSpot. A configuração técnica permanece somente leitura para o estabelecimento.</div>
      <?php elseif($isEditableDraft&&$canManageHotspots):?>
        <div class="host-point-editor">
          <h3>Editar rascunho</h3>
          <p class="subtle">O ponto ainda não foi aplicado. Nome, NAS e interface podem ser corrigidos diretamente sem ação remota.</p>
          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_draft_update"><input type="hidden" name="return_page" value="hotspots"><input type="hidden" name="hotspot_id" value="<?=$pointId?>">
            <div class="form-grid">
              <label>Nome do ponto<input name="name" maxlength="150" value="<?=host_h($hotspot['name'])?>" required></label>
              <label>NAS atribuído<select name="nas_id" required data-host-nas-select><?php foreach($availableNas as $nas):?><option value="<?=(int)$nas['id']?>" <?=(int)$nas['id']===(int)$hotspot['nas_id']?'selected':''?>><?=host_h($nas['shortname']?:'NAS #'.$nas['id'])?></option><?php endforeach;?></select></label>
              <label>Interface-base<select name="nas_interface_id" required data-host-interface-select><?php foreach($availableNas as $nas):foreach($nasInterfacesByNas[(int)$nas['id']]??[] as $interface):?><option data-nas-id="<?=(int)$nas['id']?>" value="<?=(int)$interface['id']?>" <?=(int)$interface['id']===(int)$hotspot['nas_interface_id']?'selected':''?>><?=host_h($interface['interface_name'])?></option><?php endforeach;endforeach;?></select></label>
            </div>
            <div class="host-modal__footer"><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Salvar rascunho</button></div>
          </form>
          <form method="post" class="host-point-discard"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_draft_discard"><input type="hidden" name="return_page" value="hotspots"><input type="hidden" name="hotspot_id" value="<?=$pointId?>"><label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label><button class="btn danger" type="submit">Descartar rascunho</button></form>
        </div>
      <?php elseif($canSubmitConfiguration):?>
        <div class="host-point-editor">
          <h3><?=$configurationRequest?'Atualizar solicitação r'.(int)$configurationRequest['revision']:'Solicitar alteração do ponto'?></h3>
          <p class="subtle">Você define o resultado desejado. A FireSpot revisa a solicitação antes de alterar a configuração efetiva ou o equipamento.</p>
          <?php if($configurationRequest):?><div class="notice warning"><strong>Solicitação pendente.</strong> Destino proposto: <?=host_h($configurationRequest['requested_nas_name'])?> · <?=host_h($configurationRequest['requested_interface_name'])?>, enviada em <?=host_h(host_datetime($configurationRequest['submitted_at']))?>.</div><?php endif;?>
          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_configuration_request_save"><input type="hidden" name="return_page" value="hotspots"><input type="hidden" name="hotspot_id" value="<?=$pointId?>">
            <div class="form-grid">
              <label>Nome desejado<input name="name" maxlength="150" value="<?=host_h($selectedName)?>" required></label>
              <label>NAS desejado<select name="nas_id" required data-host-nas-select><?php foreach($availableNas as $nas):?><option value="<?=(int)$nas['id']?>" <?=(int)$nas['id']===$selectedNasId?'selected':''?>><?=host_h($nas['shortname']?:'NAS #'.$nas['id'])?></option><?php endforeach;?></select></label>
              <label>Interface-base desejada<select name="nas_interface_id" required data-host-interface-select><?php foreach($availableNas as $nas):foreach($nasInterfacesByNas[(int)$nas['id']]??[] as $interface):?><option data-nas-id="<?=(int)$nas['id']?>" value="<?=(int)$interface['id']?>" <?=(int)$interface['id']===$selectedInterfaceId?'selected':''?>><?=host_h($interface['interface_name'])?></option><?php endforeach;endforeach;?></select></label>
              <label>DNS upstream do ponto<input name="dns_servers" value="<?=host_h($selectedDns)?>" maxlength="255" placeholder="1.1.1.1,8.8.8.8" required><small>A configuração de DNS pertence ao ponto, não às credenciais do NAS.</small></label>
              <label class="host-span-full">Observação para a FireSpot<textarea name="reason" maxlength="300" rows="3" placeholder="Explique a necessidade da alteração"><?=host_h((string)($configurationRequest['reason']??''))?></textarea></label>
              <label class="host-span-full">Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
            </div>
            <div class="host-modal__footer"><span class="subtle">VLAN, gateway e pool são calculados pela política do NAS; a aplicação continua sob revisão.</span><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit"><?=$configurationRequest?'Atualizar solicitação':'Enviar para revisão'?></button></div>
          </form>
          <?php if($configurationRequest):?><form method="post" class="host-point-discard"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="hotspot_configuration_request_cancel"><input type="hidden" name="return_page" value="hotspots"><input type="hidden" name="hotspot_id" value="<?=$pointId?>"><label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label><button class="btn danger" type="submit">Cancelar solicitação</button></form><?php endif;?>
        </div>
      <?php elseif($pointNasOwned&&!$pointConfigurationSchemaReady):?>
        <div class="notice warning">A janela de configuração está pronta no código, mas precisa da migração operacional 054 para registrar solicitações com segurança.</div>
      <?php elseif($pointNasOwned):?>
        <div class="notice">Seu papel ou o estado do NAS permite apenas consultar esta configuração.</div>
      <?php endif;?>
      <?php if(!(($isEditableDraft&&$canManageHotspots)||$canSubmitConfiguration)):?><div class="host-modal__footer"><button class="btn" type="button" data-host-modal-close>Fechar</button></div><?php endif;?>
    </section>
  </div>
  <?php endforeach;endif;?>

  <?php if($showNas&&$canManageNas):?>
  <div class="host-modal" id="host-modal-nas-register" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="host-modal-nas-register-title" tabindex="-1">
      <div class="host-modal__header"><div><span class="host-section-eyebrow">NAS do estabelecimento</span><h2 id="host-modal-nas-register-title">Cadastrar NAS</h2></div><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p class="subtle">Use um IPv4 de gerenciamento exclusivo. Endereços privados novos precisam pertencer a uma rede autorizada pela FireSpot.</p>
      <div class="notice">O NAS nasce com política VLAN 100–200, rede <code>10.{vlan}.0.0/24</code> e DNS <code>1.1.1.1,8.8.8.8</code>. Você poderá ajustá-la depois, sem alterar pontos existentes.</div>
      <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_register"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="idempotency_key" value="<?=host_h($formIdempotency.'-register')?>">
        <div class="form-grid"><label>Nome do equipamento<input name="name" maxlength="32" required></label><label>IPv4 de gerenciamento<input name="address" inputmode="decimal" maxlength="45" placeholder="203.0.113.10" required></label><label>Porta SSH<input type="number" name="port" min="1" max="65535" value="22" required></label><label>Usuário SSH<input name="username" maxlength="64" autocomplete="off" required></label><label>Senha SSH<input type="password" name="password" autocomplete="new-password" required></label><label>Shared secret RADIUS<input type="password" name="radius_secret" autocomplete="new-password" required></label></div>
        <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
        <div class="host-modal__footer"><span class="subtle">Cota de NAS: <?=$nasUsage?> / <?=(int)$platformLimits['max_nas']?></span><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Cadastrar e verificar</button></div>
      </form>
    </section>
  </div>

  <?php foreach($partnerNas as $nas):if(($nas['management_mode']??'')!=='partner_owned')continue;
    $nasName=(string)($nas['shortname']?:'NAS #'.$nas['id']);$nasModalKey='nas-'.(int)$nas['id'];$hasCredentials=(int)($nas['credentials_configured']??0)===1;
  ?>
  <div class="host-modal" id="host-modal-edit-<?=$nasModalKey?>" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="host-modal-edit-<?=$nasModalKey?>-title" tabindex="-1">
      <div class="host-modal__header"><div><span class="host-section-eyebrow">NAS do estabelecimento</span><h2 id="host-modal-edit-<?=$nasModalKey?>-title"><?=$hasCredentials?'Editar':'Definir'?> acesso de <?=host_h($nasName)?></h2></div><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p class="subtle">As credenciais atuais nunca são exibidas. Para salvar, informe novamente o conjunto completo. Manter o IPv4 atual não exige recadastrar a rede privada.</p>
      <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_update"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="nas_id" value="<?=(int)$nas['id']?>"><input type="hidden" name="idempotency_key" value="<?=host_h($formIdempotency.'-update-'.(int)$nas['id'])?>">
        <div class="form-grid"><label>Nome do equipamento<input name="name" value="<?=host_h($nasName)?>" maxlength="80" required></label><label>IPv4 de gerenciamento<input name="address" value="<?=host_h((string)$nas['management_address'])?>" inputmode="decimal" maxlength="45" required></label><label>Porta SSH<input type="number" name="port" min="1" max="65535" value="<?=(int)($nas['mgmt_port']?:22)?>" required></label><label>Usuário SSH<input name="username" maxlength="64" autocomplete="off" required></label><label>Nova senha SSH<input type="password" name="password" autocomplete="new-password" required></label><label>Novo shared secret RADIUS<input type="password" name="radius_secret" autocomplete="new-password" required></label></div>
        <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
        <div class="host-modal__footer"><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Salvar e verificar</button></div>
      </form>
    </section>
  </div>

  <?php if($nasPolicySchemaReady):?>
  <div class="host-modal" id="host-modal-policy-<?=$nasModalKey?>" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog host-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="host-modal-policy-<?=$nasModalKey?>-title" tabindex="-1">
      <div class="host-modal__header"><div><span class="host-section-eyebrow">Comportamento do equipamento</span><h2 id="host-modal-policy-<?=$nasModalKey?>-title">Política de pontos de <?=host_h($nasName)?></h2></div><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p class="subtle">Define somente como os próximos pontos deste NAS recebem VLAN, sub-rede, gateway, pool e DNS padrão. Os pontos existentes não são renumerados.</p>
      <form method="post" class="stack" data-host-policy-form>
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_policy_save"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="nas_id" value="<?=(int)$nas['id']?>">
        <div class="form-grid">
          <label>Primeira VLAN<input type="number" name="vlan_start" min="1" max="255" value="<?=(int)$nas['vlan_start']?>" required></label>
          <label>Última VLAN<input type="number" name="vlan_end" min="1" max="255" value="<?=(int)$nas['vlan_end']?>" required></label>
          <label>Modelo de rede<input name="network_template" value="<?=host_h((string)$nas['network_template'])?>" pattern="10\.\{vlan\}\.0\.0" required><small>Formato inicial suportado: 10.{vlan}.0.0</small></label>
          <label>Máscara por ponto<select name="prefix_length" required><?php foreach(range(16,30) as $prefix):?><option value="<?=$prefix?>" <?=$prefix===(int)$nas['prefix_length']?'selected':''?>>/<?=$prefix?></option><?php endforeach;?></select><small>A VLAN ocupa o segundo octeto: /24 gera 10.101.0.0/24; /22 gera 10.101.0.0/22.</small></label>
          <label>Offset do gateway<input type="number" name="gateway_offset" min="1" value="<?=(int)$nas['gateway_offset']?>" required></label>
          <label>Início do pool<input type="number" name="pool_start_offset" min="1" value="<?=(int)$nas['pool_start_offset']?>" required></label>
          <label>Reserva no fim da rede<input type="number" name="pool_end_reserve" min="1" value="<?=(int)$nas['pool_end_reserve']?>" required></label>
          <label>DNS padrão dos novos pontos<input name="default_dns_servers" maxlength="255" value="<?=host_h((string)$nas['default_dns_servers'])?>" required></label>
        </div>
        <div class="notice" data-host-policy-preview>Prévia: próxima VLAN <?=(int)($nas['next_vlan']??0)?><?=is_array($nas['next_network']??null)?' · '.host_h((string)$nas['next_network']['cidr']).' · gateway '.host_h((string)$nas['next_network']['gateway_ip']):' · faixa esgotada'?></div>
        <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
        <div class="host-modal__footer"><span class="subtle">Salvar é uma alteração local e auditada; nenhuma ação remota é enfileirada.</span><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Salvar política</button></div>
      </form>
    </section>
  </div>
  <?php endif;?>

  <?php if($canPrepareNas&&in_array((string)($nas['ownership_status']??''),['verified','ready'],true)):?>
  <div class="host-modal" id="host-modal-prepare-<?=$nasModalKey?>" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog host-modal__dialog--compact" role="dialog" aria-modal="true" aria-labelledby="host-modal-prepare-<?=$nasModalKey?>-title" tabindex="-1">
      <div class="host-modal__header"><h2 id="host-modal-prepare-<?=$nasModalKey?>-title">Preparar <?=host_h($nasName)?></h2><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p>Esta ação enfileira uma alteração auditada no RouterOS para configurar a base RADIUS. Ela não cria nem ativa pontos.</p>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_prepare"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="nas_id" value="<?=(int)$nas['id']?>"><input type="hidden" name="idempotency_key" value="<?=host_h($formIdempotency.'-prepare-'.(int)$nas['id'])?>"><label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label><div class="host-modal__footer"><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn primary" type="submit">Confirmar preparação</button></div></form>
    </section>
  </div>
  <?php endif;?>

  <?php if($canRetireNas):?>
  <div class="host-modal" id="host-modal-retire-<?=$nasModalKey?>" hidden data-host-modal>
    <div class="host-modal__backdrop" data-host-modal-close></div>
    <section class="host-modal__dialog host-modal__dialog--compact" role="dialog" aria-modal="true" aria-labelledby="host-modal-retire-<?=$nasModalKey?>-title" tabindex="-1">
      <div class="host-modal__header"><h2 id="host-modal-retire-<?=$nasModalKey?>-title">Aposentar <?=host_h($nasName)?></h2><button class="host-modal__close" type="button" aria-label="Fechar" data-host-modal-close>&times;</button></div>
      <p>O NAS só pode ser aposentado sem pontos ativos e sem operações pendentes. As credenciais serão revogadas e o histórico será preservado.</p>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="nas_retire"><input type="hidden" name="return_page" value="nas"><input type="hidden" name="nas_id" value="<?=(int)$nas['id']?>"><label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label><div class="host-modal__footer"><button class="btn" type="button" data-host-modal-close>Cancelar</button><button class="btn danger" type="submit">Aposentar NAS</button></div></form>
    </section>
  </div>
  <?php endif;?>
  <?php endforeach;?>
  <?php endif;?>
</section>
