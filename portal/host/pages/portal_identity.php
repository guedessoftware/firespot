<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}
$themeColorFields=[
    'primary_color'=>['Principal',$theme['primary_color']],
    'secondary_color'=>['Secundária',$theme['secondary_color']],
    'background_color'=>['Fundo',$theme['background_color']],
    'text_color'=>['Texto principal',$theme['text_color']],
    'muted_text_color'=>['Texto secundário',$theme['muted_color']],
    'hero_text_color'=>['Texto de destaque',$theme['hero_text']],
    'button_text_color'=>['Texto dos botões',$theme['on_brand']],
    'footer_text_color'=>['Texto do rodapé',$theme['footer_text']],
];
?>
<article class="card host-theme-card" id="identidade-visual">
  <form method="post" enctype="multipart/form-data" class="host-theme-form" id="host-theme-form">
    <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="theme_save"><input type="hidden" name="return_page" value="portal">
    <div class="host-theme-layout">
      <div class="host-theme-controls">
        <div class="host-theme-heading"><span class="host-portal-section-kicker">Identidade visual</span><h2>Cores e marca do portal</h2><p>Esta é a identidade única usada pelo portal atual e incorporada ao rascunho V3.</p></div>
        <div class="host-theme-basics">
          <label><span>Tema predefinido</span><select name="theme_preset" class="js-host-theme-preset"><option value="modern" <?=$theme['theme_preset']==='modern'?'selected':''?>>Padrão moderno</option><option value="compact_blue" <?=$theme['theme_preset']==='compact_blue'?'selected':''?>>Azul Noturno compacto</option><option value="compact_light" <?=$theme['theme_preset']==='compact_light'?'selected':''?>>Azul Claro compacto</option></select></label>
          <label><span>Aparência</span><select name="theme_mode" class="js-host-theme-mode"><option value="light" <?=$theme['theme_mode']==='light'?'selected':''?>>Clara</option><option value="dark" <?=$theme['theme_mode']==='dark'?'selected':''?>>Escura</option></select></label>
          <label class="host-theme-check"><input type="checkbox" name="show_title" value="1" aria-label="Exibir nome ou título" <?=!empty($theme['show_title'])?'checked':''?>><span>Exibir nome/título</span></label>
        </div>
        <div class="host-theme-colors">
          <?php foreach($themeColorFields as $field=>[$label,$value]):?>
            <label class="host-theme-color"><span><?=host_h($label)?></span><code class="js-host-color-value"><?=host_h($value)?></code><input type="color" name="<?=host_h($field)?>" value="<?=host_h($value)?>"></label>
          <?php endforeach;?>
        </div>
        <div class="host-theme-logos">
          <?php foreach(['light'=>'Logo para tema claro','dark'=>'Logo para tema escuro'] as $variant=>$label):$logoUrl=(string)($theme['logo_'.$variant.'_url']??'');?>
            <div class="host-theme-logo host-theme-logo--<?=host_h($variant)?>" data-logo-card="<?=host_h($variant)?>">
              <strong><?=host_h($label)?></strong>
              <div class="host-theme-logo__preview"><?php if($logoUrl!==''):?><img src="<?=host_h($logoUrl)?>" alt="<?=host_h($label)?>"><?php else:?><span>Sem logo personalizado</span><?php endif;?></div>
              <label><span>PNG, JPG ou WEBP (até 2 MB)</span><input type="file" name="logo_<?=host_h($variant)?>" accept="image/png,image/jpeg,image/webp"></label>
              <?php if($logoUrl!==''):?><label class="host-theme-check"><input type="checkbox" name="remove_logo_<?=host_h($variant)?>" value="1" aria-label="Remover <?=host_h(strtolower($label))?>"><span>Remover este logo</span></label><?php endif;?>
            </div>
          <?php endforeach;?>
        </div>
        <div class="host-theme-actions"><button class="btn js-host-reset-theme" type="button">Restaurar cores do modelo</button><button class="btn primary" type="submit">Salvar identidade visual</button></div>
      </div>

      <aside class="fs-central-theme-preview-column">
        <div class="fs-central-theme-preview-heading"><div><strong>Prévia instantânea</strong><span>Acompanhe cores e logos antes de salvar.</span></div><span>Celular · tela inicial</span></div>
        <div class="fs-central-phone">
          <div class="fs-central-phone__speaker" aria-hidden="true"></div>
          <div class="fs-central-phone__status" aria-hidden="true"><strong>9:41</strong><span>▮▮▮ Wi‑Fi ▰</span></div>
          <div class="fs-central-phone__browser" aria-hidden="true"><span>‹</span><div><strong><?=host_h($publicHostForDisplay)?></strong><small>Hotspot</small></div><span>×</span></div>
          <div class="fs-central-phone__viewport">
            <div class="fs-central-theme-preview js-host-theme-preview" data-mode="<?=host_h($theme['theme_mode'])?>" data-preset="<?=host_h($theme['theme_preset'])?>" data-layout="<?=str_starts_with((string)$theme['theme_preset'],'compact_')?'compact':'modern'?>">
              <main class="fs-pv-shell fs-pv-entry-shell">
                <section class="fs-pv-panel fs-pv-hero fs-pv-entry-card<?=!empty($theme['logo_url'])?' has-logo':''?>">
                  <span class="fs-pv-signal" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                  <div class="fs-pv-brand-mark<?=!empty($theme['logo_url'])?' has-logo':''?>"><img class="js-host-preview-image" src="<?=host_h((string)($theme['logo_url']??''))?>" data-light-url="<?=host_h((string)($theme['logo_light_url']??''))?>" data-dark-url="<?=host_h((string)($theme['logo_dark_url']??''))?>" alt="" <?=empty($theme['logo_url'])?'hidden':''?>><span class="js-host-preview-letter" <?=!empty($theme['logo_url'])?'hidden':''?>><?=host_h($theme['logo_letter']??'F')?></span></div>
                  <p class="fs-pv-eyebrow fs-pv-brand-title js-host-preview-title" <?=!empty($theme['show_title'])?'':'hidden'?>><?=host_h($context['name'])?></p>
                  <p class="fs-pv-eyebrow">Seja bem-vindo</p>
                  <h1>Conecte-se ao Wi-Fi</h1>
                  <p class="fs-pv-muted">Escolha como deseja acessar.</p>
                  <span class="fs-pv-button">Continuar</span>
                </section>
                <p class="fs-pv-footer"><?=host_h($context['name'])?> · Conexão protegida</p>
              </main>
            </div>
          </div>
          <div class="fs-central-phone__home" aria-hidden="true"></div>
        </div>
      </aside>
    </div>
  </form>
</article>
