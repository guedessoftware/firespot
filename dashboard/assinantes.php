<?php
declare(strict_types=1);

require_once __DIR__.'/../app/admin_auth.php';
admin_require_page();admin_require_capability('subscribers.view');
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/subscriber_admin.php';
require_once __DIR__.'/../app/hubsoft_cache.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sub_h($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function sub_redirect(string $section='rollout',string $query=''):void{$params=['section'=>$section];if($query!=='')$params['q']=$query;header('Location: assinantes.php?'.http_build_query($params),true,303);exit;}
function sub_dt($value):string{$ts=strtotime((string)$value);return$ts?date('d/m/Y H:i',$ts):'—';}
function sub_speed($value):string{$kbps=max(0,(int)$value);return$kbps>=1000?number_format($kbps/1000,$kbps%1000===0?0:1,',','.').' Mbps':number_format($kbps,0,',','.').' Kbps';}
$requestedSection=(string)($_GET['section']??'overview');
if($requestedSection==='integration'){header('Location: integracoes.php?section=hubsoft',true,302);exit;}
$allowedSections=['overview','rollout','mappings','accounts','support','observability'];
$currentSection=$requestedSection;
if(!in_array($currentSection,$allowedSections,true))$currentSection='rollout';
$flash=$_SESSION['subscriber_admin_flash']??null;$nextQuery=$_SESSION['subscriber_admin_query']??'';unset($_SESSION['subscriber_admin_flash'],$_SESSION['subscriber_admin_query']);
$hubsoftStatus=fs_subscriber_admin_hubsoft_status($pdo);$hubsoftApiReady=!empty($hubsoftStatus['configured']);$hubsoftCapability=fs_hubsoft_capability_status($pdo);$hubsoftReady=$hubsoftApiReady&&!empty($hubsoftStatus['crypto_ready'])&&!empty($hubsoftCapability['ok']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    $redirectSection='rollout';
    try{
        admin_require_capability('subscribers.manage');if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');$action=(string)($_POST['action']??'');
        if($action==='flags_save')$redirectSection='rollout';
        elseif(in_array($action,['mapping_save','mapping_toggle','mapping_delete','mapping_migrate'],true))$redirectSection='mappings';
        elseif($action==='hubsoft_test')$redirectSection='support';
        elseif(in_array($action,['account_refresh','device_revoke','cooldown_release'],true))$redirectSection='support';
        if($action==='flags_save'){
            $current=[];$requested=[];foreach(fs_subscriber_feature_labels() as $key=>$label){$current[$key]=fs_subscriber_feature_enabled($pdo,$key,false);$requested[$key]=isset($_POST[$key]);}
            $desired=fs_subscriber_feature_normalize($current,$requested);
            if($desired['subscriber_access_enabled']&&!$hubsoftReady)throw new RuntimeException('Conclua a configuração segura do HubSoft antes de liberar a resolução de benefícios.');
            if($desired['subscriber_account_enabled']&&empty($hubsoftStatus['otp_ready']))throw new RuntimeException('Ative e configure a mensageria antes de liberar a Minha Conta.');
            $pdo->beginTransaction();try{foreach($desired as $key=>$enabled)fs_subscriber_feature_set($pdo,$key,$enabled);$pdo->commit();}catch(Throwable $rolloutError){if($pdo->inTransaction())$pdo->rollBack();throw$rolloutError;}
            $message='Controles de rollout atualizados com as dependências necessárias.';
        }elseif($action==='mapping_save'){$id=fs_subscriber_admin_mapping_save($pdo,$_POST);$message='Mapeamento HubSoft salvo com ID interno '.$id.'.';}
        elseif($action==='mapping_toggle'){fs_subscriber_admin_mapping_toggle($pdo,(int)($_POST['id']??0),!empty($_POST['active']));$message='Situação do mapeamento atualizada.';}
        elseif($action==='mapping_delete'){fs_subscriber_admin_mapping_delete($pdo,(int)($_POST['id']??0),admin_id());$message='Mapeamento inativo excluído com segurança.';}
        elseif($action==='mapping_migrate'){$migration=fs_subscriber_admin_mapping_migrate($pdo,(int)($_POST['source_id']??0),(int)($_POST['target_id']??0),admin_id());$message='Mapeamento migrado de '.$migration['source_label'].' para '.$migration['target_label'].'. '.(int)$migration['linked_entitlements'].' vínculo(s) serão revalidados no HubSoft.';}
        elseif($action==='hubsoft_test'){$result=fs_subscriber_sync_document($pdo,(string)($_POST['document']??''));if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'O contrato não está elegível.'));$nextQuery=(string)$result['account']['public_id'];$message='Cliente de teste validado sem expor o documento ou a resposta bruta.';}
        elseif($action==='account_refresh'){$accountId=(int)($_POST['account_id']??0);$result=fs_subscriber_refresh_account($pdo,$accountId);if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'Não foi possível revalidar.'));$nextQuery=(string)($_POST['account_public_id']??'');$message='Benefício revalidado no HubSoft.';}
        elseif($action==='device_revoke'){$accountId=(int)($_POST['account_id']??0);fs_subscriber_admin_revoke_device($pdo,$accountId,(int)($_POST['device_id']??0),(string)($_POST['reason']??''),admin_id());$nextQuery=(string)($_POST['account_public_id']??'');$message='Aparelho e concessões ativas revogados.';}
        elseif($action==='cooldown_release'){$accountId=(int)($_POST['account_id']??0);fs_subscriber_admin_release_cooldown($pdo,$accountId,(int)($_POST['device_id']??0),(string)($_POST['reason']??''),admin_id());$nextQuery=(string)($_POST['account_public_id']??'');$message='Vaga liberada com justificativa auditada.';}
        else throw new RuntimeException('Ação inválida.');
        $_SESSION['subscriber_admin_flash']=['ok'=>true,'message'=>$message];
    }catch(Throwable $e){$_SESSION['subscriber_admin_flash']=['ok'=>false,'message'=>admin_public_error($e,'Não foi possível concluir a operação.')];$nextQuery=(string)($_POST['account_public_id']??'');}
    if($nextQuery!=='')$_SESSION['subscriber_admin_query']=$nextQuery;sub_redirect($redirectSection,$nextQuery);
}

$query=trim((string)($_GET['q']??$nextQuery));$account=$query!==''?fs_subscriber_admin_find_account($pdo,$query):null;
$features=[];foreach(fs_subscriber_feature_labels() as $key=>$label)$features[$key]=fs_subscriber_feature_enabled($pdo,$key,false);
$profiles=fs_subscriber_admin_profiles($pdo);$mappings=fs_subscriber_admin_mappings($pdo);$migrationTargets=array_values(array_filter($mappings,static fn(array $mapping):bool=>!empty($mapping['active'])&&!empty($mapping['eligible_internet'])));$unknown=fs_subscriber_admin_unknown_ids($pdo);$metrics=fs_subscriber_admin_metrics($pdo);$canManage=admin_has_capability('subscribers.manage');
$titulo='HubSoft e assinantes FIRENETWORK';$pageId='assinantes';ob_start();
?>
<?php if($flash):?><div class="notice <?=!empty($flash['ok'])?'success':'error'?>"><?=sub_h($flash['message'])?></div><?php endif;?>
<div class="subscriber-admin">
<section class="sub-grid sub-kpi-grid" aria-label="Indicadores de assinantes">
  <article class="card sub-kpi"><span>Contas locais</span><strong><?=$metrics['accounts']?></strong></article>
  <article class="card sub-kpi"><span>Benefícios ativos</span><strong><?=$metrics['active_entitlements']?></strong></article>
  <article class="card sub-kpi"><span>Aparelhos ativos</span><strong><?=$metrics['active_devices']?></strong></article>
  <article class="card sub-kpi"><span>Concessões online</span><strong><?=$metrics['active_grants']?></strong></article>
</section>

<?php if($currentSection==='overview'):?>
<section class="card sub-section-card"><header class="sub-section-header"><div><h2>Operação dos assinantes FIRENETWORK</h2><p class="muted">Benefício, perfis, contas, atendimento e observabilidade permanecem aqui. OAuth, credenciais e testes técnicos do provedor ficam em Sistema → Integrações.</p></div><a class="btn" href="integracoes.php?section=hubsoft">Ver integração HubSoft</a></header>
<div class="fs-cc-workspace-grid"><a class="card" href="?section=rollout"><span>Benefício</span><strong>Ativação controlada</strong><small>Gates e dependências do rollout.</small></a><a class="card" href="?section=mappings"><span>Contrato</span><strong>Planos e perfis</strong><small>IDs estáveis e perfis de velocidade.</small></a><a class="card" href="?section=accounts"><span>Cadastro local</span><strong>Contas e aparelhos</strong><small>Consulta sanitizada sem ações de suporte.</small></a><a class="card" href="?section=support"><span>Operação</span><strong>Atendimento</strong><small>Revalidação, revogação e liberação auditadas.</small></a><a class="card" href="?section=observability"><span>Diagnóstico</span><strong>Observabilidade</strong><small>Indicadores agregados e códigos seguros.</small></a></div></section>
<?php endif;?>

<?php if($currentSection==='rollout'):?>
<section class="card sub-section-card" id="subscriber-rollout">
<header class="sub-section-header"><div><h2>Ativação do benefício FIRENETWORK</h2><p class="muted">Ative os recursos em sequência. Desligar um gate impede novas operações sem apagar contas, compras ou auditoria.</p></div><span class="sub-pill <?=!empty($features['subscriber_radius_enabled'])?'ok':'warn'?>"><?=!empty($features['subscriber_radius_enabled'])?'Jornada liberada':'Rollout desativado'?></span></header>
<div class="sub-grid sub-steps">
  <div class="sub-step"><span class="sub-step-number">1</span><div><strong>Resolução HubSoft</strong><small>Consulta o contrato e determina o benefício.</small></div></div>
  <div class="sub-step"><span class="sub-step-number">2</span><div><strong>Minha Conta</strong><small>Exige a mensageria OTP pronta.</small></div></div>
  <div class="sub-step"><span class="sub-step-number">3</span><div><strong>Aparelhos e RADIUS</strong><small>Libera convites e acesso individual.</small></div></div>
</div>
<?php if($canManage):?>
<?php
$rolloutDependencies=[
  'subscriber_access_enabled'=>[],
  'subscriber_account_enabled'=>['subscriber_access_enabled'],
  'subscriber_invites_enabled'=>['subscriber_account_enabled'],
  'subscriber_radius_enabled'=>['subscriber_invites_enabled'],
  'subscriber_authenticated_purchase_enabled'=>['subscriber_account_enabled'],
];
$rolloutHelp=[
  'subscriber_access_enabled'=>'Base necessária para consultar e resolver o benefício.',
  'subscriber_account_enabled'=>'Requer resolução HubSoft e mensageria OTP.',
  'subscriber_invites_enabled'=>'Requer a Minha Conta para administrar aparelhos.',
  'subscriber_radius_enabled'=>'Requer convites e libera a concessão individual.',
  'subscriber_authenticated_purchase_enabled'=>'Requer a Minha Conta e consentimento explícito.',
];
?>
<div class="sub-rollout-guide" id="subscriber-rollout-guidance" aria-live="polite"><div><strong>Dependências automáticas</strong><span>Selecione a função desejada; as etapas anteriores serão marcadas juntas.</span></div><?php if(!$hubsoftReady):?><a class="btn" href="integracoes.php?section=hubsoft">Configurar HubSoft</a><?php elseif(empty($hubsoftStatus['otp_ready'])):?><a class="btn" href="integracoes.php?section=messaging">Configurar OTP</a><?php endif;?></div>
<form method="post" class="sub-form" id="subscriber-rollout-form"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="flags_save">
  <div class="sub-grid sub-flags"><?php foreach(fs_subscriber_feature_labels() as $key=>$label):?><?php $externalReady=$key==='subscriber_access_enabled'?$hubsoftReady:($hubsoftReady&&!empty($hubsoftStatus['otp_ready']));$blocked=!$externalReady&&empty($features[$key]);$blockedMessage=!$hubsoftReady?'Configure e ative a integração HubSoft.':'Configure e ative a mensageria OTP.';?><label class="sub-flag <?=!empty($features[$key])?'is-selected':''?> <?=$blocked?'is-blocked':''?>" data-feature="<?=sub_h($key)?>"><input type="checkbox" name="<?=sub_h($key)?>" value="1" data-requires="<?=sub_h(implode(',',$rolloutDependencies[$key]))?>" <?=!empty($features[$key])?'checked':''?> <?=$blocked?'disabled':''?>><span><strong><?=sub_h($label)?></strong><small><?=sub_h($blocked?$blockedMessage:$rolloutHelp[$key])?></small></span></label><?php endforeach;?></div>
  <div class="sub-form-actions"><button class="btn primary" type="submit">Salvar rollout</button></div>
</form>
<?php else:?><p class="muted">Seu papel possui somente acesso de consulta.</p><?php endif;?>
</section>
<?php endif;?>

<?php if($currentSection==='mappings'):?>
<section class="card sub-section-card" id="hubsoft-profiles">
<header class="sub-section-header"><div><h2>Contrato de teste e perfis</h2><p class="muted">O teste consulta o HubSoft, persiste apenas o mínimo necessário e nunca mostra a resposta bruta.</p></div></header>
<div class="sub-grid sub-profile-grid"><?php foreach($profiles as $profile):?><article class="sub-profile-card"><strong><?=sub_h($profile['name'])?></strong><p><?=(int)$profile['device_limit']?> aparelho(s) · <?=(int)$profile['concurrent_limit']?> simultâneo(s)</p><p>↓ <?=sub_h(sub_speed($profile['download_kbps']))?> · ↑ <?=sub_h(sub_speed($profile['upload_kbps']))?></p><small class="sub-code"><?=sub_h($profile['code'])?></small></article><?php endforeach;?></div>
<?php if($canManage):?><form method="post" class="sub-form sub-form--inline" autocomplete="off"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="hubsoft_test"><label class="sub-field"><span>CPF/CNPJ de cliente de teste</span><input name="document" inputmode="numeric" required></label><div class="sub-form-actions"><button class="btn" type="submit" <?=$hubsoftReady?'':'disabled'?>>Validar contrato de teste</button></div></form><?php endif;?>
</section>

<section class="card sub-section-card" id="hubsoft-mappings">
<header class="sub-section-header"><div><h2>Mapeamentos por ID estável</h2><p class="muted">A decisão usa o ID de serviço, plano ou pacote e escolhe o maior benefício, sem somar limites.</p></div><span class="sub-pill <?=!empty($mappings)?'ok':'warn'?>"><?=count($mappings)?> cadastrado(s)</span></header>
<?php if($canManage):?>
<form method="post" class="sub-form sub-form--mapping" id="hubsoft-mapping-form"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="mapping_save"><input type="hidden" name="id" id="hubsoft-mapping-internal-id" value="0">
  <label class="sub-field"><span>Tipo</span><select name="external_kind" id="hubsoft-mapping-kind"><option value="plan">Plano</option><option value="service">Serviço</option><option value="package">Pacote</option></select></label>
  <label class="sub-field"><span>ID no HubSoft</span><input name="external_id" id="hubsoft-mapping-id" maxlength="128" required></label>
  <label class="sub-field"><span>Rótulo operacional</span><input name="external_label" id="hubsoft-mapping-label" maxlength="180"></label>
  <label class="sub-field"><span>Perfil</span><select name="benefit_profile_id" id="hubsoft-mapping-profile"><?php foreach($profiles as $profile):?><option value="<?=(int)$profile['id']?>" data-download="<?=(int)$profile['download_kbps']?>" data-upload="<?=(int)$profile['upload_kbps']?>"><?=sub_h($profile['name'])?></option><?php endforeach;?></select></label>
  <label class="sub-field"><span>Download (Kbps)</span><input name="download_kbps" id="hubsoft-mapping-download" type="number" min="64" max="10000000" value="10000" required></label>
  <label class="sub-field"><span>Upload (Kbps)</span><input name="upload_kbps" id="hubsoft-mapping-upload" type="number" min="64" max="10000000" value="3000" required></label>
  <div class="sub-rules"><span class="sub-field-label">Regras</span><label><input type="checkbox" name="eligible_internet" value="1" checked> Internet elegível</label><label><input type="checkbox" name="active" value="1" checked> Mapeamento ativo</label></div>
  <div class="sub-form-actions"><button class="btn primary" type="submit" id="hubsoft-mapping-submit">Salvar mapeamento</button><button class="btn" type="button" id="hubsoft-mapping-cancel" hidden>Cancelar edição</button></div>
</form>
<?php endif;?>
<div class="sub-table-wrap"><table class="sub-table sub-mapping-table">
  <thead><tr><th>Tipo / ID</th><th>Rótulo</th><th>Perfil</th><th>Velocidade</th><th>Clientes</th><th>Situação</th><th>Ações</th></tr></thead>
  <tbody>
  <?php foreach($mappings as $mapping):?>
    <?php $linked=(int)($mapping['linked_entitlements']??0);$activeLinked=(int)($mapping['active_entitlements']??0);?>
    <tr>
      <td><strong><?=sub_h($mapping['external_kind'])?></strong><br><span class="sub-code"><?=sub_h($mapping['external_id'])?></span></td>
      <td><?=sub_h($mapping['external_label']?:'—')?></td>
      <td><?=sub_h($mapping['profile_name'])?></td>
      <td><strong>↓ <?=sub_h(sub_speed($mapping['effective_download_kbps']))?></strong><br><small class="muted">↑ <?=sub_h(sub_speed($mapping['effective_upload_kbps']))?><?=empty($mapping['download_kbps'])||empty($mapping['upload_kbps'])?' · padrão do perfil':''?></small></td>
      <td><strong><?=$linked?></strong><br><small class="muted"><?=$activeLinked?> ativo(s)</small></td>
      <td><span class="sub-pill <?=$mapping['active']?'ok':'off'?>"><?=$mapping['active']?'Ativo':'Inativo'?></span></td>
      <td>
      <?php if($canManage):?>
        <div class="sub-mapping-actions">
          <button class="btn" type="button" data-edit-mapping data-id="<?=(int)$mapping['id']?>" data-kind="<?=sub_h($mapping['external_kind'])?>" data-external-id="<?=sub_h($mapping['external_id'])?>" data-label="<?=sub_h($mapping['external_label'])?>" data-profile-id="<?=(int)$mapping['benefit_profile_id']?>" data-download="<?=(int)$mapping['effective_download_kbps']?>" data-upload="<?=(int)$mapping['effective_upload_kbps']?>" data-eligible="<?=!empty($mapping['eligible_internet'])?'1':'0'?>" data-active="<?=!empty($mapping['active'])?'1':'0'?>">Editar</button>
          <form method="post"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="mapping_toggle"><input type="hidden" name="id" value="<?=(int)$mapping['id']?>"><input type="hidden" name="active" value="<?=$mapping['active']?0:1?>"><button class="btn" type="submit"><?=$mapping['active']?'Desativar':'Ativar'?></button></form>
          <?php if(empty($mapping['active'])):?>
            <?php if($migrationTargets):?>
            <details class="sub-mapping-migrate">
              <summary>Migrar</summary>
              <form method="post" data-confirm="A migração deve ser feita depois que os clientes forem movidos no HubSoft. Deseja transferir os vínculos locais e remover este mapeamento antigo?">
                <input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="mapping_migrate"><input type="hidden" name="source_id" value="<?=(int)$mapping['id']?>">
                <label class="sub-field"><span>Novo mapeamento</span><select name="target_id" required><option value="">Selecione o destino</option><?php foreach($migrationTargets as $target):?><?php if((int)$target['id']===(int)$mapping['id'])continue;?><option value="<?=(int)$target['id']?>"><?=sub_h($target['external_kind'].':'.$target['external_id'].' · '.$target['profile_name'])?></option><?php endforeach;?></select></label>
                <small>Transfere <?=$linked?> vínculo(s), exige nova validação no HubSoft e não altera contratos no ERP.</small>
                <button class="btn primary" type="submit">Migrar e remover antigo</button>
              </form>
            </details>
            <?php endif;?>
            <form method="post" data-confirm="Excluir definitivamente este mapeamento inativo? Esta ação só será aceita se não houver clientes vinculados."><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="mapping_delete"><input type="hidden" name="id" value="<?=(int)$mapping['id']?>"><button class="btn danger" type="submit" <?=$linked>0?'disabled title="Migre os clientes antes de excluir"':''?>>Excluir</button></form>
          <?php endif;?>
        </div>
      <?php else:?>—<?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  <?php if(!$mappings):?><tr><td class="sub-empty" colspan="7">Nenhum mapeamento específico. Serviços elegíveis recebem o perfil básico com velocidade limitada.</td></tr><?php endif;?>
  </tbody>
</table></div>
<?php if($unknown):?><div class="sub-subsection"><div><h3>IDs descobertos e ainda não mapeados</h3><p class="muted">Gerados pelas validações reais. O sinal de internet ajuda no diagnóstico, mas a ativação continua dependendo da sua confirmação.</p></div><div class="sub-table-wrap"><table class="sub-table sub-discovered-table"><thead><tr><th>Tipo / ID</th><th>Rótulo retornado</th><th>Sinal</th><th>Ocorrências</th><th>Última identificação</th><?php if($canManage):?><th>Ação</th><?php endif;?></tr></thead><tbody><?php foreach($unknown as $item):?><tr><td><strong><?=sub_h($item['kind'])?></strong><br><span class="sub-code"><?=sub_h($item['external_id'])?></span></td><td><?=sub_h($item['label']?:'Não informado pelo HubSoft')?></td><td><span class="sub-pill <?=!empty($item['internet_detected'])?'ok':'warn'?>"><?=!empty($item['internet_detected'])?'Internet detectada':'Revisar serviço'?></span></td><td><?=(int)$item['total']?></td><td><?=sub_h(sub_dt($item['last_seen']))?></td><?php if($canManage):?><td><button class="btn" type="button" data-use-mapping data-kind="<?=sub_h($item['kind'])?>" data-external-id="<?=sub_h($item['external_id'])?>" data-label="<?=sub_h($item['label'])?>">Usar ID</button></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
</section>
<?php endif;?>

<?php if(in_array($currentSection,['accounts','support'],true)):$supportMode=$currentSection==='support';?>
<section class="card sub-section-card" id="subscriber-support">
<header class="sub-section-header"><div><h2><?=$supportMode?'Atendimento de conta':'Contas e aparelhos'?></h2><p class="muted"><?=$supportMode?'Busque e execute somente ações de suporte justificadas e auditadas.':'Consulta sanitizada da conta, dos aparelhos e das concessões; ações permanecem em Atendimento.'?> CPF/CNPJ, contatos completos e credenciais não são exibidos.</p></div><?php if(!$supportMode):?><a class="btn" href="?section=support<?= $query!==''?'&amp;q='.rawurlencode($query):'' ?>">Abrir atendimento</a><?php endif;?></header>
<form method="get" class="sub-form sub-form--inline"><input type="hidden" name="section" value="<?=$supportMode?'support':'accounts'?>"><label class="sub-field"><span>ID público ou ID HubSoft</span><input name="q" value="<?=sub_h($query)?>" required></label><div class="sub-form-actions"><button class="btn" type="submit">Localizar</button></div></form>
<?php if($query!==''&&!$account):?><div class="notice error">Conta não encontrada.</div><?php elseif($account):?>
<div class="sub-grid sub-account-summary"><div><span class="muted">Conta</span><strong class="sub-code"><?=sub_h($account['public_id'])?></strong></div><div><span class="muted">Benefício</span><strong><?=sub_h($account['profile_name']??'—')?></strong></div><div><span class="muted">Situação</span><strong><?=sub_h(($account['status']??'').' / '.($account['entitlement_status']??'sem entitlement'))?></strong></div><div><span class="muted">Contato confirmado</span><strong><?=sub_h($account['verified_phone_hint']?:$account['verified_email_hint']?:'—')?></strong></div><div><span class="muted">Última validação</span><strong><?=sub_h(sub_dt($account['last_verified_at']))?></strong></div></div>
<?php if($canManage&&$supportMode):?><form method="post" class="sub-actions"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="account_refresh"><input type="hidden" name="account_id" value="<?=(int)$account['id']?>"><input type="hidden" name="account_public_id" value="<?=sub_h($account['public_id'])?>"><button class="btn" type="submit">Revalidar no HubSoft</button></form><?php endif;?>
<div class="sub-subsection"><h3>Aparelhos</h3><div class="sub-table-wrap"><table class="sub-table"><thead><tr><th>Aparelho</th><th>Situação</th><th>Último acesso</th><th><?=$supportMode?'Ações de suporte':'Operação'?></th></tr></thead><tbody><?php foreach($account['devices'] as $device):?><tr><td><?=sub_h($device['label']?:$device['device_kind'])?><br><small class="sub-code"><?=sub_h($device['public_id'])?></small></td><td><?=sub_h($device['status'])?><?php if($device['replace_available_at']):?><br><small>vaga: <?=sub_h(sub_dt($device['replace_available_at']))?></small><?php endif;?></td><td><?=sub_h(sub_dt($device['last_seen_at']))?></td><td><?php if($supportMode&&$canManage&&$device['status']==='active'):?><form method="post" class="sub-inline"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="device_revoke"><input type="hidden" name="account_id" value="<?=(int)$account['id']?>"><input type="hidden" name="account_public_id" value="<?=sub_h($account['public_id'])?>"><input type="hidden" name="device_id" value="<?=(int)$device['id']?>"><input name="reason" placeholder="Motivo obrigatório" required><button class="btn danger" type="submit">Revogar</button></form><?php elseif($supportMode&&$canManage&&$device['status']==='revoked'&&!empty($device['replace_available_at'])&&strtotime($device['replace_available_at'])>time()):?><form method="post" class="sub-inline"><input type="hidden" name="csrf" value="<?=sub_h(csrf_token())?>"><input type="hidden" name="action" value="cooldown_release"><input type="hidden" name="account_id" value="<?=(int)$account['id']?>"><input type="hidden" name="account_public_id" value="<?=sub_h($account['public_id'])?>"><input type="hidden" name="device_id" value="<?=(int)$device['id']?>"><input name="reason" placeholder="Justificativa obrigatória" required><button class="btn" type="submit">Liberar vaga</button></form><?php elseif(!$supportMode):?><a class="btn" href="?section=support&amp;q=<?=rawurlencode((string)$account['public_id'])?>">Atender</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$account['devices']):?><tr><td class="sub-empty" colspan="4">Nenhum aparelho cadastrado.</td></tr><?php endif;?></tbody></table></div></div>
<div class="sub-subsection"><h3>Concessões recentes</h3><div class="sub-table-wrap"><table class="sub-table"><thead><tr><th>Data</th><th>Estabelecimento / ponto</th><th>Situação</th><th>Diagnóstico</th></tr></thead><tbody><?php foreach($account['grants'] as $grant):?><tr><td><?=sub_h(sub_dt($grant['created_at']))?></td><td><?=sub_h($grant['partner_name'].' / '.$grant['hotspot_name'])?></td><td><?=sub_h($grant['status'])?></td><td><?=sub_h($grant['failure_code']?:'—')?></td></tr><?php endforeach;?><?php if(!$account['grants']):?><tr><td class="sub-empty" colspan="4">Nenhuma concessão registrada.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?>
</section>

<?php endif;?>

<?php if($currentSection==='observability'):?>
<section class="card sub-section-card" id="subscriber-observability">
<header class="sub-section-header"><div><h2>Observabilidade agregada</h2><p class="muted">Indicadores técnicos sem exposição de dados pessoais dos assinantes.</p></div></header>
<div class="sub-grid sub-observation-grid">
  <article class="sub-observation-card"><h3>Entitlements</h3><?php foreach($metrics['entitlements'] as $row):?><p><span class="sub-pill"><?=sub_h($row['status'])?></span><span><?=sub_h($row['result_code']?:'—')?></span><strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$metrics['entitlements']):?><small class="muted">Sem registros.</small><?php endif;?></article>
  <article class="sub-observation-card"><h3>Convites</h3><?php foreach($metrics['invites'] as $row):?><p><span class="sub-pill"><?=sub_h($row['status'])?></span><strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$metrics['invites']):?><small class="muted">Sem registros.</small><?php endif;?></article>
  <article class="sub-observation-card"><h3>Concessões</h3><?php foreach($metrics['grants'] as $row):?><p><span class="sub-pill"><?=sub_h($row['status'])?></span><span><?=sub_h($row['failure_code']?:'—')?></span><strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$metrics['grants']):?><small class="muted">Sem registros.</small><?php endif;?></article>
  <article class="sub-observation-card"><h3>Entradas por senha (24h)</h3><?php foreach($metrics['auth_attempts'] as $row):?><p><span class="sub-pill"><?=sub_h($row['outcome'])?></span><span><?=sub_h($row['result_code'])?></span><strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$metrics['auth_attempts']):?><small class="muted">Sem registros.</small><?php endif;?></article>
</div>
<?php if($metrics['partners']):?><div class="sub-table-wrap"><table class="sub-table"><thead><tr><th>Estabelecimento (30 dias)</th><th>Acessos</th><th>Aparelhos distintos</th><th>Falhas</th></tr></thead><tbody><?php foreach($metrics['partners'] as $row):?><tr><td><?=sub_h($row['name'])?></td><td><?=(int)$row['accesses']?></td><td><?=(int)$row['devices']?></td><td><?=(int)$row['failures']?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<?php endif;?>
</div>
<?php $conteudo=ob_get_clean();require __DIR__.'/layout.php';
