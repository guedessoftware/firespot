<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/admin_auth.php';
require_once __DIR__ . '/../app/partner_admin.php';
require_once __DIR__ . '/../app/portal_simulator.php';
require_once __DIR__ . '/../app/partner_hotspots.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: http: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'");

function simulator_h($value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}

function simulator_asset(string $file): string
{
    $file = basename($file);
    $path = __DIR__ . '/assets/' . $file;
    return '/simulador/assets/' . rawurlencode($file) . '?v=' . (is_file($path) ? filemtime($path) : 1);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

$source = (string)($_GET['source'] ?? '');
$partner = null;
$backUrl = '/admin/painel.php';
$backLabel = 'Voltar ao painel do estabelecimento';

if ($source === 'control' || ($source === '' && admin_is_authenticated() && isset($_GET['partner_id']))) {
    if (!admin_is_authenticated()) {
        header('Location: /dashboard/login.php?expired=1&next=' . rawurlencode('../simulador/?source=control'));
        exit;
    }
    if (!admin_has_capability('partners.view')) {
        http_response_code(403);
        exit('Seu papel administrativo não permite visualizar estabelecimentos.');
    }
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if ($partnerId <= 0) {
        http_response_code(422);
        exit('Selecione um estabelecimento na Central FireSpot.');
    }
    $st = $pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1');
    $st->execute([$partnerId]);
    $partner = $st->fetch() ?: null;
    $source = 'control';
    $backUrl = '/dashboard/estabelecimento.php?id=' . $partnerId . '&section=portal';
    $backLabel = 'Voltar à Central FireSpot';
} else {
    $partner = partner_admin_context($pdo);
    if (!$partner) {
        header('Location: /admin/?expired=1');
        exit;
    }
    $source = 'partner';
}

if (!$partner) {
    http_response_code(404);
    exit('Estabelecimento não encontrado.');
}

$partnerId=(int)$partner['id'];
$availableHotspots=fs_partner_hotspots_for_partner($pdo,$partnerId,false);
$requestedHotspotId=max(0,(int)($_GET['hotspot_id']??0));
if(fs_partner_hotspots_schema_ready($pdo)){
    $hotspotContext=$requestedHotspotId>0
        ? fs_partner_hotspot_by_id($pdo,$requestedHotspotId,$partnerId,false)
        : fs_partner_hotspot_default($pdo,$partnerId,false);
    if(!$hotspotContext){http_response_code(422);exit('A instalação selecionada não pertence a este estabelecimento.');}
    $partner=$hotspotContext;
}

$portalConfigId=$source==='control'?max(0,(int)($_GET['portal_config_id']??0)):0;
$snapshot = fs_portal_simulator_snapshot($pdo,$partner,$portalConfigId>0?$portalConfigId:null);
$configJson = json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$theme = $snapshot['theme'];
$capabilities = $snapshot['capabilities'];
$policy = $snapshot['policy'];
$partnerInfo = $snapshot['partner'];
$embed = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <title>Simulação · <?=simulator_h($partnerInfo['name'])?></title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="<?=simulator_h(simulator_asset('simulator.css'))?>">
</head>
<body class="simulator<?= $embed?' is-embed':'' ?>">
<div id="simulator-data" data-config="<?=simulator_h($configJson)?>"></div>
<?php if(!$embed):?><header class="sim-topbar">
  <div class="sim-topbar__identity"><span class="sim-kicker">Simulação segura · sem efeitos reais</span><strong><?=simulator_h($partnerInfo['name'])?></strong><small><?=simulator_h($partnerInfo['hotspot_name'])?> · <?=simulator_h($partnerInfo['purpose_label'])?> · Portal <?=simulator_h(strtoupper($partnerInfo['portal_mode']))?></small></div>
  <a class="sim-back" href="<?=simulator_h($backUrl)?>">← <?=simulator_h($backLabel)?></a>
</header><?php endif;?>

<main class="sim-workspace">
  <aside class="sim-console" aria-label="Controles da simulação">
    <div class="sim-console__heading"><div><span class="sim-kicker">Jornada do cliente</span><h1>Teste a experiência completa</h1></div><button class="sim-reset" type="button" data-sim-action="restart">Reiniciar</button></div>
    <p class="sim-intro">A simulação usa <?=($partnerInfo['portal_config_state']??'')==='draft'?'o rascunho selecionado':'a configuração publicada'?>. Nenhuma cobrança, cortesia, impressão de anúncio ou conexão RADIUS será criada.</p>

    <?php if(count($availableHotspots)>1):?><form method="get" class="sim-routes"><input type="hidden" name="source" value="<?=simulator_h($source)?>"><?php if($embed):?><input type="hidden" name="embed" value="1"><?php endif;?><?php if($source==='control'):?><input type="hidden" name="partner_id" value="<?=$partnerId?>"><?php if($portalConfigId>0):?><input type="hidden" name="portal_config_id" value="<?=$portalConfigId?>"><?php endif;?><?php endif;?><label><strong>Instalação simulada</strong><select name="hotspot_id" onchange="this.form.submit()"><?php foreach($availableHotspots as $hotspot):?><option value="<?=(int)$hotspot['id']?>" <?=(int)$partnerInfo['hotspot_id']===(int)$hotspot['id']?'selected':''?>><?=simulator_h($hotspot['name'])?><?=!$hotspot['active']?' · inativa':''?></option><?php endforeach;?></select></label></form><?php endif;?>

    <div class="sim-status-grid">
      <div><span>Planos</span><strong><?=count($snapshot['plans'])?></strong><small><?=$capabilities['sales']?'jornada '.(count($snapshot['plans'])?'disponível':'incompleta'):'não aplicável'?></small></div>
      <div><span>Cortesia</span><strong><?=$capabilities['courtesy']?(int)$policy['grant_minutes'].' min':'—'?></strong><small><?=$capabilities['courtesy_enabled']?'política ativa':($capabilities['courtesy']?'desativada':'não oferecida')?></small></div>
      <div><span>Identificação</span><strong><?=$capabilities['auth_required']?'Sim':'Não'?></strong><small><?=simulator_h(str_replace('_',' + ',$policy['auth_mode']))?></small></div>
      <div><span>Publicidade</span><strong><?=count($snapshot['ads'])?></strong><small><?=$capabilities['requires_ad']?'obrigatória na cortesia':'fora desta jornada'?></small></div>
      <div><span>FIRENETWORK</span><strong><?=$capabilities['subscriber']?'Ativo':'—'?></strong><small><?=$capabilities['subscriber']?'benefício por aparelho':'não oferecido'?></small></div>
    </div>

    <div class="sim-routes">
      <strong>Atalhos de teste</strong>
      <div><button type="button" data-sim-action="restart">Do login ao fim</button><button type="button" data-sim-route="subscriber" <?=!$capabilities['subscriber']?'disabled':''?>>Benefício FIRENETWORK</button><button type="button" data-sim-route="paid" <?=!$capabilities['sales']?'disabled':''?>>Fluxo pago</button><button type="button" data-sim-route="courtesy" <?=!$capabilities['courtesy']?'disabled':''?>>Fluxo cortesia</button><button type="button" data-sim-route="ad" <?=!$snapshot['ads']?'disabled':''?>>Ver propaganda</button></div>
    </div>

    <ol class="sim-progress" aria-label="Progresso da jornada">
      <li class="is-active" data-progress="entry"><span>1</span><div><strong>Login do Wi-Fi</strong><small>Detecção do portal cativo</small></div></li>
      <li data-progress="offer"><span>2</span><div><strong>Modalidade</strong><small>Cortesia, premium ou benefício</small></div></li>
      <li data-progress="validation"><span>3</span><div><strong>Validação</strong><small>Identificação, anúncio ou pagamento</small></div></li>
      <li data-progress="release"><span>4</span><div><strong>Liberação</strong><small>Crédito simulado</small></div></li>
      <li data-progress="connected"><span>5</span><div><strong>Conectado</strong><small>Resultado final</small></div></li>
    </ol>

    <?php if($snapshot['warnings']):?><div class="sim-warnings"><strong>Pontos encontrados na configuração</strong><ul><?php foreach($snapshot['warnings'] as $warning):?><li><?=simulator_h($warning)?></li><?php endforeach;?></ul></div><?php endif;?>
    <div class="sim-safety"><strong>Ambiente de demonstração</strong><span>Botões de pagamento e conexão são locais nesta tela e não chamam serviços externos.</span></div>
  </aside>

  <section class="sim-device-area" aria-label="Celular simulado">
    <div class="sim-device-heading"><div><strong>Experiência no celular</strong><span id="sim-current-step">Login do Wi-Fi</span></div><span>Configuração atual</span></div>
    <div class="sim-phone">
      <div class="sim-phone__speaker" aria-hidden="true"></div>
      <div class="sim-phone__status" aria-hidden="true"><strong>9:41</strong><span>▮▮▮ Wi‑Fi ▰</span></div>
      <div class="sim-phone__browser" aria-hidden="true"><span>‹</span><div><strong><?=simulator_h($snapshot['public_host'])?></strong><small>Hotspot</small></div><span>×</span></div>
      <div class="sim-phone__viewport">
        <div id="sim-portal" class="sim-portal" data-layout="<?=simulator_h($theme['layout'])?>" data-mode="<?=simulator_h($theme['mode'])?>" style="--portal-bg:<?=simulator_h($theme['background_color'])?>;--portal-bg2:<?=simulator_h($theme['background_secondary'])?>;--portal-panel:<?=simulator_h($theme['panel_color'])?>;--portal-ink:<?=simulator_h($theme['text_color'])?>;--portal-muted:<?=simulator_h($theme['muted_color'])?>;--portal-brand:<?=simulator_h($theme['primary_color'])?>;--portal-brand2:<?=simulator_h($theme['secondary_color'])?>;--portal-brand-rgb:<?=simulator_h($theme['primary_rgb'])?>;--portal-line:<?=simulator_h($theme['line_color'])?>;--portal-soft:<?=simulator_h($theme['soft_color'])?>;--portal-hero1:<?=simulator_h($theme['hero_primary'])?>;--portal-hero2:<?=simulator_h($theme['hero_secondary'])?>;--portal-hero-ink:<?=simulator_h($theme['hero_text'])?>;--portal-on-brand:<?=simulator_h($theme['on_brand'])?>;--portal-footer:<?=simulator_h($theme['footer_text'])?>;--portal-popular:<?=simulator_h($theme['popular_color'])?>;--portal-popular-ink:<?=simulator_h($theme['popular_text'])?>">
          <div id="sim-screen" aria-live="polite"></div>
        </div>
      </div>
      <div class="sim-phone__home" aria-hidden="true"></div>
    </div>
  </section>
</main>
<script src="<?=simulator_h(simulator_asset('simulator.js'))?>" defer></script>
</body>
</html>
