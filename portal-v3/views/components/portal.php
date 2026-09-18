<?php
declare(strict_types=1);
if(!isset($vm)||!is_array($vm))throw new RuntimeException('PortalViewModel ausente.');
$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$theme=$vm['theme'];$stage=(string)$vm['stage'];$preview=!empty($vm['preview']);$assetBase=$preview?(string)($vm['preview_asset_base']??'../portal-v3/'):'';
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?=$h($theme['theme_mode'])?>" data-v3-skin="<?=$h($vm['skin']['code'])?>" data-v3-density="<?=$h($vm['content']['density'])?>" data-v3-alignment="<?=$h($vm['content']['alignment'])?>" data-preview-viewport="<?=$h($vm['preview_viewport'])?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="<?=$h($theme['background_color'])?>"><title><?=$h($vm['brand']['name'])?> — Wi-Fi</title>
  <link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="<?=$assetBase?>assets/css/portal-v3.css?v=19">
  <link rel="stylesheet" href="<?=$assetBase?>assets/css/skins/<?=$h($vm['skin']['code'])?>.css?v=1"><?=v3_theme_css($theme)?>
</head>
<body class="portal-entry-page portal-stage-<?=$h($stage)?> skin-<?=$h($vm['skin']['code'])?><?=$preview?' portal-preview':''?>">
<?php if($preview):?><div class="portal-preview-banner" role="status">Prévia segura · nenhuma ação, métrica ou ativação será executada</div><?php endif;?>
<?php if($stage==='welcome'):?>
<main class="shell narrow entry-shell"><section class="panel entry-card">
  <?=v3_brand_mark($theme)?><?php if(!empty($vm['brand']['show_title'])):?><p class="entry-brand-title brand-title"><?=$h($vm['brand']['name'])?></p><?php endif;?>
  <?php if(!empty($vm['content']['hero_url'])):?><img class="portal-skin-hero" src="<?=$h($vm['content']['hero_url'])?>" alt=""><?php endif;?>
  <p class="eyebrow">Seja bem-vindo</p><h1><?=$h($vm['content']['headline'])?></h1><p class="muted"><?=$h($vm['content']['message'])?></p>
  <?php if($preview):?><button class="button primary entry-continue" type="button" disabled><?=$h($vm['content']['continue_label'])?></button><?php else:?><a class="button primary entry-continue" href="index.php?<?=$h($vm['hotspot_query'])?>&amp;step=options"><?=$h($vm['content']['continue_label'])?></a><?php endif;?>
</section><?=!$preview?(string)($vm['ad_banner_html']??''):''?><p class="footer-note"><?=$h($vm['brand']['name'])?> · <?=$h($vm['content']['legal'])?> · <a href="<?=$preview?'#':'../portal/termos.php'?>">Privacidade e publicidade</a></p></main>
<?php elseif($stage==='options'):?>
<main class="shell narrow access-options-shell"><section class="panel access-options-header">
  <?=v3_brand_mark($theme)?><h1>Escolha seu acesso</h1><?php foreach($vm['notices'] as $notice):?><div class="notice"><?=$h($notice)?></div><?php endforeach;?>
</section><section class="access-choice-grid" aria-label="Modalidades de acesso disponíveis">
<?php if(!empty($vm['content']['sponsor_url'])):?><figure class="panel portal-skin-sponsor"><img src="<?=$h($vm['content']['sponsor_url'])?>" alt="Conteúdo patrocinado"><figcaption>Conteúdo patrocinado</figcaption></figure><?php endif;?>
<?php foreach($vm['options'] as $option):?>
  <article class="panel access-choice access-choice--<?=$h($option['type'])?>"><div class="access-choice-icon" aria-hidden="true"><?=$h($option['icon'])?></div><div class="access-choice-copy"><h2><?=$h($option['title'])?></h2><p class="access-choice-meta"><?=$h($option['meta'])?></p></div>
  <?php if($preview):?><button class="button primary" type="button" disabled><?=$h($option['label'])?></button><?php else:?><a class="button primary<?=$option['disabled']?' courtesy-entry-button is-disabled':''?>"<?=$option['disabled']?' aria-disabled="true" tabindex="-1"':' href="'.$h($option['url']).'"'?><?=isset($option['retry_at'])?' data-courtesy-target="'.$h($option['url']).'" data-courtesy-ready-label="'.$h($option['ready_label']).'"'.($option['retry_at']?' data-courtesy-retry-at="'.(int)$option['retry_at'].'"':''):''?>><?=$h($option['label'])?></a><?php endif;?>
  </article>
<?php endforeach;?>
<?php if(!$vm['options']):?><article class="panel access-choice access-choice--empty"><div class="access-choice-copy"><p class="eyebrow">Indisponível</p><h2>Nenhuma modalidade ativa</h2><p class="muted">Este estabelecimento ainda não possui uma opção de acesso publicada.</p></div></article><?php endif;?>
</section><?php if($vm['back_url']!==''):?><a class="back options-back" href="<?=$preview?'#':$h($vm['back_url'])?>">← Voltar</a><?php endif;?></main>
<?php else:?>
<main class="shell plans-shell"><section class="hero panel plans-hero<?=!empty($theme['logo_url'])?' hero-has-logo':''?>"><div class="step">Acesso premium</div><?=v3_brand_mark($theme)?><?php if(!empty($vm['brand']['show_title'])):?><p class="eyebrow brand-title"><?=$h($vm['brand']['name'])?></p><?php endif;?><h1>Escolha seu plano</h1></section>
<section class="panel plans-catalog"><?php if($vm['back_url']!==''):?><a class="back" href="<?=$preview?'#':$h($vm['back_url'])?>">← Voltar</a><?php endif;?><?php foreach($vm['notices'] as $notice):?><div class="notice"><?=$h($notice)?></div><?php endforeach;?>
<div class="section-title"><div><span class="eyebrow">Planos disponíveis</span><h2>Quanto tempo você precisa?</h2></div><span class="secure-pill">Pagamento seguro</span></div>
<?php if($vm['sales_error']!==''):?><div class="status-box error"><?=$h($vm['sales_error'])?></div><?php else:?><p class="preset-title">Compre seu acesso via Pix ou cartão</p><div class="plan-grid">
<?php foreach($vm['plans'] as $index=>$plan):?>
  <form method="post" action="<?=$h($plan['url'])?>" class="plan-card<?=$index===0?' featured':''?>"><?php if(!$preview):?><input type="hidden" name="csrf" value="<?=$h($vm['csrf'])?>"><input type="hidden" name="plan_source" value="<?=$h($plan['source'])?>"><input type="hidden" name="plan_id" value="<?=(int)$plan['id']?>"><?php endif;?><?php if($index===0):?><span class="popular">Mais escolhido</span><?php endif;?><h3><?=$h($plan['name'])?></h3><div class="price"><?=$h($plan['price'])?></div><div class="duration"><?=$h($plan['duration'])?> de acesso</div><ul><li>Download: <?=$h($plan['download'])?></li><li>Upload: <?=$h($plan['upload'])?></li><li>Liberação automática</li></ul><button class="button primary" type="<?=$preview?'button':'submit'?>" <?=$preview?'disabled':''?>>Escolher este acesso</button></form>
<?php endforeach;?></div><?php endif;?></section><?=!$preview?(string)($vm['ad_banner_html']??''):''?><p class="footer-note"><?=$h($vm['brand']['name'])?> · <?=$h($vm['content']['legal'])?> · <a href="<?=$preview?'#':'../portal/termos.php'?>">Privacidade e publicidade</a></p></main>
<?php endif;?>
<?php require __DIR__.'/courtesy-timer.php';?>
</body></html>
