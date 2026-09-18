<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}
$stateLabels=['schema_pending'=>'Migração pendente','legacy_active'=>'Portal atual preservado','draft'=>'Rascunho V3','ready'=>'Pronto para revisão manual','v3_active'=>'Portal V3 ativo','rollback_available'=>'V3 ativo com rollback'];
$state=(string)($migrationState['status']??'schema_pending');
?>
<section class="stack host-portal-v3">
  <article class="card host-portal-hub">
    <div class="host-actions">
      <div><span class="host-portal-section-kicker">Portal do estabelecimento</span><h1>Configuração do Portal V3</h1><p class="subtle">Um único motor de acesso, pagamento e liberação; cinco modelos visuais alteram somente a apresentação.</p></div>
      <div class="host-portal-hub__actions"><span class="pill <?=$state==='v3_active'?'ok':''?>"><?=host_h($stateLabels[$state]??$state)?></span><button class="btn primary" type="button" data-host-modal-open="host-modal-simulation" data-host-frame-src="/simulador/?source=partner&amp;embed=1">Simular portal</button></div>
    </div>
    <nav class="host-portal-subnav" aria-label="Seções da configuração do portal"><a href="#identidade-visual">Identidade visual</a><a href="#modelo-conteudo">Modelo e conteúdo</a><?php if($presentation):?><a href="#validacao-portal">Validação</a><?php endif;?></nav>
    <div class="host-portal-state-guide">
      <div><strong>Identidade visual</strong><span>Cores e logos compartilhados pelo portal.</span></div>
      <div><strong>Modelo e conteúdo</strong><span>Composição em rascunho para a migração V3.</span></div>
      <div><strong>Simulação</strong><span>Jornada completa, segura e sem efeitos reais.</span></div>
    </div>
    <p class="host-portal-safety">A migração continua individual e manual. Editar ou visualizar o rascunho não publica o V3, não gera acesso, não cria pagamento e não altera NAS.</p>
  </article>

  <?php require __DIR__ . '/portal_identity.php';?>

  <div id="modelo-conteudo" class="host-anchor-target"></div>
  <?php if(empty($migrationState['installed'])):?>
    <article class="card"><h2>Modelo e conteúdo do Portal V3</h2><p>A estrutura versionada ainda não foi instalada. A FireSpot precisa aplicar as migrações 050 e 051; o portal atual e sua identidade continuam funcionando sem alteração.</p></article>
  <?php elseif(!$presentation):?>
    <article class="card">
      <span class="host-portal-section-kicker">Modelo e conteúdo</span><h2>Começar o rascunho V3</h2>
      <p>A apresentação inicial será criada a partir da identidade visual acima. A jornada funcional publicada continuará sendo a fonte das modalidades disponíveis.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="portal_presentation_prepare"><input type="hidden" name="return_page" value="portal"><button class="btn primary" type="submit">Preparar rascunho visual V3</button></form>
    </article>
  <?php else:$content=$presentation['content'];$isDraft=($presentation['state']??'')==='draft';?>
    <article class="card host-portal-model-card">
      <div class="host-actions"><div><span class="host-portal-section-kicker">Modelo e conteúdo</span><h2>Composição do Portal V3</h2><p class="subtle">A skin define densidade, hierarquia e destaque. Ela não muda planos, permissões nem formas de acesso.</p></div><span class="pill">Revisão <?=(int)$presentation['revision']?> · <?=host_h($presentation['state'])?></span></div>
      <div class="host-skin-grid">
        <?php foreach($skinCatalog as $skin):?><article class="host-skin-card <?=($presentation['skin_code']??'')===$skin['code']?'is-selected':''?>"><span>v<?=(int)$skin['version']?></span><strong><?=host_h($skin['label'])?></strong><p><?=host_h($skin['description'])?></p><?php if(($presentation['skin_code']??'')===$skin['code']):?><small>Selecionado</small><?php endif;?></article><?php endforeach;?>
      </div>
    </article>

    <?php if($isDraft):?><article class="card host-portal-content-card">
      <div class="host-portal-section-heading"><div><h2>Conteúdo e comportamento visual</h2><p class="subtle">A identidade não é repetida aqui: cores e logos vêm do editor acima.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="portal_presentation_save"><input type="hidden" name="return_page" value="portal">
        <div class="form-grid host-portal-content-grid">
          <label>Modelo visual<select name="skin_code"><?php foreach($skinCatalog as $skin):?><option value="<?=host_h($skin['code'])?>" <?=$presentation['skin_code']===$skin['code']?'selected':''?>><?=host_h($skin['label'])?></option><?php endforeach;?></select><small>Também chamado de skin: muda apenas a forma de apresentar o mesmo Portal V3.</small></label>
          <label>Densidade<select name="density"><?php foreach(['compact'=>'Compacta','comfortable'=>'Confortável','spacious'=>'Ampla'] as $value=>$label):?><option value="<?=$value?>" <?=($content['density']??'comfortable')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
          <label>Alinhamento<select name="alignment"><option value="center" <?=($content['alignment']??'center')==='center'?'selected':''?>>Centralizado</option><option value="left" <?=($content['alignment']??'')==='left'?'selected':''?>>À esquerda</option></select></label>
          <label>Texto do botão<input name="continue_label" maxlength="40" value="<?=host_h($content['continue_label']??'Continuar')?>"></label>
          <label class="host-span-full">Título de boas-vindas<input name="headline" maxlength="100" value="<?=host_h($content['headline']??'Conecte-se ao Wi-Fi')?>" required></label>
          <label class="host-span-full">Mensagem<textarea name="message" maxlength="400" rows="3"><?=host_h($content['message']??'Escolha como deseja acessar.')?></textarea></label>
          <label class="host-span-full">Texto legal e rodapé<input name="legal" maxlength="300" value="<?=host_h($content['legal']??'Conexão protegida.')?>"></label>
          <label>Imagem de destaque<input name="hero" type="file" accept="image/png,image/jpeg,image/webp"><?php if(!empty($content['hero_path'])):?><span><input name="remove_hero" type="checkbox" value="1" aria-label="Remover imagem de destaque atual"> Remover imagem atual</span><?php endif;?><small>Usada somente por modelos com região de destaque.</small></label>
          <label>Peça patrocinada<input name="sponsor" type="file" accept="image/png,image/jpeg,image/webp"><?php if(!empty($content['sponsor_path'])):?><span><input name="remove_sponsor" type="checkbox" value="1" aria-label="Remover peça patrocinada atual"> Remover peça atual</span><?php endif;?><small>Não substitui a validação funcional de uma campanha.</small></label>
        </div>
        <div class="host-actions"><span class="subtle">HTML, JavaScript, CSS e URLs arbitrárias não são aceitos.</span><button class="btn primary" type="submit">Salvar modelo e conteúdo</button></div>
      </form>
    </article><?php endif;?>

    <article class="card" id="validacao-portal">
      <div class="host-portal-section-heading"><div><span class="host-portal-section-kicker">Prévia do rascunho</span><h2>Conferir telas específicas</h2><p class="subtle">Cada prévia abre sobre esta página. Os controles transacionais permanecem desabilitados.</p></div></div>
      <div class="host-preview-links"><?php foreach(['mobile'=>'Móvel','desktop'=>'Desktop'] as $viewport=>$viewportLabel):foreach(['welcome'=>'boas-vindas','options'=>'opções','plans'=>'planos'] as $previewStage=>$stageLabel):$previewUrl='/admin/portal_preview.php?viewport='.$viewport.'&stage='.$previewStage;?><button class="btn" type="button" data-host-modal-open="host-modal-preview" data-host-frame-src="<?=host_h($previewUrl)?>"><?=$viewportLabel?> · <?=$stageLabel?></button><?php endforeach;endforeach;?></div>
      <?php if($isDraft):?><div class="host-actions host-portal-validation-actions"><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="portal_presentation_validate"><input type="hidden" name="return_page" value="portal"><button class="btn" type="submit">Validar checklist</button></form><details><summary class="danger-text">Descartar rascunho</summary><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="portal_presentation_discard"><input type="hidden" name="return_page" value="portal"><label>Sua senha<input type="password" name="current_password" required autocomplete="current-password"></label><button class="btn danger" type="submit">Confirmar descarte</button></form></details></div><?php endif;?>
    </article>

    <?php if(!empty($presentation['validation'])):?><article class="card"><h2>Checklist</h2><div class="host-checklist"><?php foreach($presentation['validation']['items'] as $item):?><div><span class="pill <?=$item['level']==='ready'?'ok':($item['level']==='block'?'off':'')?>"><?=host_h($item['level'])?></span><strong><?=host_h($item['label'])?></strong><small><?=host_h($item['message'])?></small></div><?php endforeach;?></div><p class="subtle">A decisão final de candidatura, publicação e ativação pertence à Central FireSpot.</p></article><?php endif;?>
  <?php endif;?>
</section>

<div class="host-modal host-portal-preview-modal" id="host-modal-simulation" data-host-modal hidden>
  <button class="host-modal__backdrop" type="button" data-host-modal-close aria-label="Fechar simulação"></button>
  <section class="host-modal__dialog host-modal__dialog--portal" role="dialog" aria-modal="true" aria-labelledby="host-simulation-title" tabindex="-1">
    <header class="host-modal__header"><div><span class="host-portal-section-kicker">Simulação segura</span><h2 id="host-simulation-title">Experiência completa do cliente</h2><p>Nenhuma cobrança, cortesia, anúncio ou conexão RADIUS será criada.</p></div><button class="host-modal__close" type="button" data-host-modal-close aria-label="Fechar">×</button></header>
    <iframe title="Simulação completa do Portal V3" data-host-modal-frame loading="lazy"></iframe>
  </section>
</div>

<div class="host-modal host-portal-preview-modal" id="host-modal-preview" data-host-modal hidden>
  <button class="host-modal__backdrop" type="button" data-host-modal-close aria-label="Fechar prévia"></button>
  <section class="host-modal__dialog host-modal__dialog--portal-preview" role="dialog" aria-modal="true" aria-labelledby="host-preview-title" tabindex="-1">
    <header class="host-modal__header"><div><span class="host-portal-section-kicker">Rascunho V3</span><h2 id="host-preview-title">Prévia segura</h2><p>Visualização sem ações transacionais.</p></div><button class="host-modal__close" type="button" data-host-modal-close aria-label="Fechar">×</button></header>
    <iframe title="Prévia segura do rascunho do Portal V3" data-host-modal-frame loading="lazy"></iframe>
  </section>
</div>
