<?php

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../app/partner_ads.php';
require_once __DIR__ . '/../app/courtesy_policy.php';
require_once __DIR__ . '/../app/ad_monetization.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$portalConfig = fs_portal_config_for_partner($pdo,$partner);
if (!fs_portal_config_has_courtesy($portalConfig)) v3_fail('Este estabelecimento não oferece cortesia.',404);
fs_partner_portal_metric_event($pdo,$partner,'courtesy_selection');
$policy = fs_courtesy_policy_resolve($pdo,(int)$partner['id'],fs_partner_hotspot_id($partner));
$rollout = fs_courtesy_rollout_resolve($pdo,(int)$partner['id'],'v3',$policy);
$unavailableMessage = '';
if (empty($policy['enabled'])) {
    $unavailableMessage = 'O acesso gratuito não está disponível neste momento.';
} elseif (($rollout['effective_mode'] ?? 'legacy') !== 'enforce') {
    error_log('[portal-v3 courtesy] unavailable partner=' . (int)$partner['id']
        . ' requested=' . (string)($rollout['requested_mode'] ?? 'legacy')
        . ' effective=' . (string)($rollout['effective_mode'] ?? 'legacy')
        . ' blocked_by=' . (string)($rollout['blocked_by'] ?? 'none'));
    $unavailableMessage = 'Estamos preparando o acesso gratuito. Tente novamente em alguns instantes.';
}
$authRequired = in_array((string)($policy['auth_mode'] ?? 'anonymous'),['account','account_device'],true) && empty($_SESSION['cliente_username']);
$courtesyDecision = null;
$activeReconnect = false;
if ($unavailableMessage === '' && !$authRequired) {
    $courtesyDecision = v3_courtesy_preflight($pdo, $partner, $policy);
    $activeReconnect = (string)($courtesyDecision['code'] ?? '') === 'ACTIVE_GRANT';
    if (empty($courtesyDecision['allowed']) && !$activeReconnect) {
        $unavailableMessage = (string)($courtesyDecision['message'] ?? 'O acesso gratuito não está disponível neste momento.');
    }
}
$courtesyRetryAt = $courtesyDecision ? v3_courtesy_retry_timestamp($courtesyDecision) : null;
$requiresAd = !empty($policy['requires_ad']);
$courtesyAccessLabel = $requiresAd ? 'Acesso patrocinado' : 'Acesso rápido';
$platformRuntime=fs_ad_platform_runtime($pdo,$partner,'free_rewarded','sponsored','access_choice');
$platformChoice=null;$platformDelivery=null;$platformExternal=false;
$eligibleAds=$unavailableMessage===''&&$requiresAd&&!$activeReconnect?partner_ads_eligible($pdo,$partner):[];
if($unavailableMessage===''&&$requiresAd&&!$activeReconnect&&!empty($platformRuntime['managed'])){
    $platformChoice=fs_ad_platform_inventory_choice($platformRuntime,$eligibleAds);
    $ad=$platformChoice&&$platformChoice['provider']==='internal'?$platformChoice['ad']:null;
    $platformExternal=$platformChoice&&in_array((string)$platformChoice['provider'],['mock','google_ad_manager'],true);
}else{
    $ad=$unavailableMessage===''&&$requiresAd&&!$activeReconnect?partner_ads_pick($pdo,$partner):null;
}
if($unavailableMessage===''&&$requiresAd&&!$activeReconnect&&!$ad&&!$platformExternal){
    $unavailableMessage = 'Nenhuma mensagem patrocinada está disponível agora. Tente novamente em instantes.';
}

$proofToken = '';
$duration = 0;
if (!isset($_SESSION['courtesy_ad_proofs']) || !is_array($_SESSION['courtesy_ad_proofs'])) $_SESSION['courtesy_ad_proofs'] = [];
$_SESSION['courtesy_ad_proofs'] = array_filter($_SESSION['courtesy_ad_proofs'],static fn($proof) => is_array($proof) && (int)($proof['expires_at'] ?? 0) > time());
if (count($_SESSION['courtesy_ad_proofs']) > 8) $_SESSION['courtesy_ad_proofs'] = array_slice($_SESSION['courtesy_ad_proofs'],-8,null,true);
if ($unavailableMessage === '' && $ad && !$authRequired) {
    $duration = max(5,min(180,(int)($ad['duration_sec'] ?? 15)));
    $device=v3_device_context();
    $delivery=fs_ad_delivery_begin($pdo,$partner,$ad,$duration,['mac'=>$device['mac']??'','ip'=>$device['ip']??''],false);
    $proofToken=(string)($delivery['token']??'');
    if($proofToken==='')$proofToken=bin2hex(random_bytes(24));
    $_SESSION['courtesy_ad_proofs'][$proofToken] = [
        'partner_code'=>(string)$partner['code'],
        'partner_id'=>(int)$partner['id'],
        'hotspot_id'=>fs_partner_hotspot_id($partner),
        'hotspot_code'=>v3_hotspot_code($partner),
        'ad_id'=>(int)$ad['id'],
        'ready_at'=>time()+$duration,
        'expires_at'=>time()+max(900,$duration+300),
    ];
}
if($unavailableMessage===''&&$platformExternal&&!$authRequired){
    try{
        $device=v3_device_context();
        $platformDelivery=fs_ad_platform_delivery_begin($pdo,$partner,$platformChoice,[
            'mac'=>$device['mac']??'','ip'=>$device['ip']??'','journey'=>'sponsored',
            'username'=>$_SESSION['cliente_username']??'',
            'personalization_consent'=>!empty($_SESSION['ad_personalization_consent']),
            'reward_minutes'=>(int)$policy['grant_minutes'],
        ]);
        $proofToken=(string)$platformDelivery['token'];
    }catch(Throwable $error){
        error_log('[portal-v3 rewarded inventory] '.$error->getMessage());
        $unavailableMessage='O acesso patrocinado atingiu o limite ou está temporariamente indisponível.';
        $platformExternal=false;$platformDelivery=null;
    }
}
$theme = v3_theme_current();
$mediaType = $ad ? partner_ads_media_type((string)($ad['media_type'] ?? 'image')) : 'image';
$mediaUrl = $ad ? partner_ads_media_url($ad) : '';
$fitMode = $ad ? partner_ads_fit_mode((string)($ad['fit_mode'] ?? 'contain')) : 'contain';
$hasOffer = $ad && (trim((string)($ad['link_url'] ?? '')) !== '' || !empty($ad['lead_capture_enabled']));
$leadCapture = $ad && (int)($portalConfig['lead_capture_enabled'] ?? 0) === 1 && !empty($ad['lead_capture_enabled']);
$interestButtonText = $ad ? partner_ads_button_text($ad['interest_button_text'] ?? null,'Tenho interesse') : 'Tenho interesse';
$skipButtonText = $ad ? partner_ads_button_text($ad['skip_button_text'] ?? null,'Pular e conectar') : 'Pular e conectar';
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?=v3_h($theme['theme_mode'])?>" data-v3-preset="<?=v3_h($theme['theme_preset'])?>" data-v3-layout="<?=v3_theme_layout($theme)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#000000">
  <title>Cortesia — <?=v3_h($theme['brand_name'])?></title>
  <link rel="stylesheet" href="assets/css/portal-v3.css?v=18">
  <link rel="stylesheet" href="assets/css/portal-v3-sponsored.css?v=1">
  <?=v3_theme_css($theme)?>
  <?php if ($ad && !$authRequired): ?><style>
    html,body{width:100%;height:100%;margin:0;background:#000;overflow:hidden}.sponsored-screen{position:relative;width:100vw;height:100dvh;background:#000;color:#fff;isolation:isolate}.sponsored-media{position:absolute;inset:0;width:100%;height:100%;object-fit:<?=$fitMode?>;background:#000}.sponsored-shade{position:absolute;inset:0;z-index:1;pointer-events:none;background:linear-gradient(180deg,rgba(0,0,0,.58),transparent 24%);transition:background .25s}.sponsored-screen.actions-visible .sponsored-shade{background:linear-gradient(180deg,rgba(0,0,0,.58),transparent 24%,transparent 64%,rgba(0,0,0,.82))}.sponsored-top{position:absolute;z-index:2;top:max(14px,env(safe-area-inset-top));left:16px;right:16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.sponsored-title{max-width:68vw;text-shadow:0 2px 8px #000;font-weight:800}.sponsored-timer{flex:none;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:8px 12px;background:rgba(0,0,0,.68);font-size:13px;font-weight:800;backdrop-filter:blur(8px)}.sound-button{position:absolute;z-index:3;right:16px;top:max(64px,calc(env(safe-area-inset-top) + 52px));border:1px solid rgba(255,255,255,.38);border-radius:999px;padding:7px 10px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;font-weight:700}.sponsored-actions{position:absolute;z-index:2;left:0;right:0;bottom:0;padding:14px 16px max(14px,calc(env(safe-area-inset-bottom) + 10px));display:grid;gap:8px;opacity:0;visibility:hidden;transform:translateY(18px);pointer-events:none;transition:opacity .22s,transform .22s,visibility .22s}.sponsored-actions.is-visible{opacity:1;visibility:visible;transform:none;pointer-events:auto}.sponsored-button-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.sponsored-button-row button:only-child{grid-column:1/-1}.sponsored-actions button{min-height:46px;border:0;border-radius:13px;padding:10px 12px;font:inherit;font-weight:850}.interest-button{background:#f59e0b;color:#241500}.interest-button.done{background:#15803d;color:#fff}.skip-button{background:#fff;color:#0f172a}.skip-button:disabled{background:rgba(255,255,255,.2);color:rgba(255,255,255,.68)}.sponsored-note{text-align:center;color:#e2e8f0;font-size:11px;text-shadow:0 1px 5px #000}.sponsored-error{border-radius:12px;padding:9px;background:rgba(127,29,29,.92);color:#fff;text-align:center}.video-fallback{position:absolute;inset:0;display:grid;place-items:center;padding:24px;text-align:center;color:#fff;background:#111827}.video-fallback a{color:#fbbf24}.lead-overlay{position:fixed;inset:0;z-index:40;display:grid;place-items:end center;padding:16px;background:rgba(0,0,0,.72);backdrop-filter:blur(5px)}.lead-overlay[hidden]{display:none}.lead-card{box-sizing:border-box;width:min(100%,440px);padding:20px;border-radius:20px;background:#fff;color:#0f172a;box-shadow:0 24px 70px rgba(0,0,0,.45)}.lead-card h2{margin:0 0 6px;font-size:22px}.lead-card p{margin:0 0 15px;color:#475569}.lead-card label{display:grid;gap:6px;margin:11px 0;font-size:13px;font-weight:750}.lead-card input[type=text],.lead-card input[type=tel]{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:11px;font:inherit}.lead-consent{grid-template-columns:auto 1fr!important;align-items:start;font-weight:500!important}.lead-buttons{display:grid;grid-template-columns:1fr 1.4fr;gap:8px;margin-top:14px}.lead-buttons button{min-height:44px;border:0;border-radius:11px;font-weight:800}.lead-cancel{background:#e2e8f0}.lead-send{background:#f59e0b;color:#241500}.lead-error{color:#b91c1c!important;font-size:13px}
  </style><?php endif; ?>
</head>
<body>
<?php if ($unavailableMessage !== ''): ?>
  <main class="shell narrow courtesy-state-shell">
    <section class="panel courtesy-state-card">
      <?=v3_brand_mark($theme)?>
      <div class="courtesy-state-icon" aria-hidden="true">↻</div>
      <p class="eyebrow">Wi-Fi gratuito</p>
      <h1>Quase pronto</h1>
      <p class="muted"><?=v3_h($unavailableMessage)?></p>
      <?php if($courtesyRetryAt): ?>
        <a class="button primary courtesy-entry-button is-disabled" aria-disabled="true" tabindex="-1" data-courtesy-target="courtesy.php?<?=v3_h(v3_hotspot_query($partner))?>" data-courtesy-ready-label="Tentar novamente" data-courtesy-retry-at="<?=(int)$courtesyRetryAt?>">Disponível novamente em <?=v3_h(v3_courtesy_wait_label($courtesyRetryAt-time()))?></a>
      <?php else: ?>
        <a class="button primary" href="courtesy.php?<?=v3_h(v3_hotspot_query($partner))?>">Tentar novamente</a>
      <?php endif; ?>
      <a class="back" href="index.php?<?=v3_h(v3_hotspot_query($partner))?>&amp;step=options">Voltar às modalidades</a>
    </section>
  </main>
<?php elseif ($authRequired): ?>
  <?php $returnTo='/portal-v3/courtesy.php?'.v3_hotspot_query($partner); ?>
  <main class="shell narrow"><section class="panel"><?=v3_brand_mark($theme)?><p class="eyebrow"><?=v3_h($courtesyAccessLabel)?></p><h1>Identifique-se para continuar</h1><div class="notice">A política desta unidade exige identificação antes do acesso gratuito.</div><a class="button primary" href="../portal/login.php?assoc=1&amp;next=<?=rawurlencode($returnTo)?>">Identificar-se</a><a class="back" href="index.php?<?=v3_h(v3_hotspot_query($partner))?>&amp;step=options">← Voltar às modalidades</a></section></main>
<?php elseif ($platformExternal&&$platformDelivery): ?>
  <main class="shell narrow courtesy-state-shell"><section class="panel courtesy-state-card">
    <?=v3_brand_mark($theme)?>
    <div class="courtesy-state-icon" aria-hidden="true">▶</div>
    <p class="eyebrow">Acesso patrocinado</p>
    <h1>Assista e conecte</h1>
    <p class="muted"><?=v3_h(fs_ad_platform_reward_disclosure(['ad_count'=>$platformDelivery['required_ad_count'],'reward_minutes'=>(int)$policy['grant_minutes']]))?></p>
    <div class="notice">A participação é opcional. Nenhum clique, cadastro ou compra é exigido. A receita deste anúncio pertence à FireSpot.</div>
    <div id="rewarded-slot"></div>
    <div id="courtesy-error" class="status-box error" hidden></div>
    <button id="ad-platform-start" class="button primary" type="button">Iniciar anúncio</button>
    <button id="courtesy-submit" class="button primary" type="button" hidden>Conectar ao Wi-Fi</button>
    <a class="back" href="index.php?<?=v3_h(v3_hotspot_query($partner))?>&amp;step=options">← Escolher outra modalidade</a>
  </section></main>
<?php elseif ($ad): ?>
  <main class="sponsored-screen" aria-label="Anúncio obrigatório">
    <?php if ($mediaType === 'video'): ?>
      <video id="ad-video" class="sponsored-media" autoplay muted loop playsinline preload="auto" poster="<?=v3_h((string)($ad['poster_url'] ?? ''))?>"><source src="<?=v3_h($mediaUrl)?>" type="video/mp4"><div class="video-fallback">Seu navegador não conseguiu reproduzir este vídeo.</div></video>
      <button id="sound-button" class="sound-button" type="button" aria-pressed="false">Ativar som</button>
    <?php else: ?>
      <img class="sponsored-media" src="<?=v3_h($mediaUrl)?>" alt="<?=v3_h((string)$ad['title'])?>">
    <?php endif; ?>
    <div class="sponsored-shade"></div>
    <div class="sponsored-top"><div class="sponsored-title"><?=v3_h((string)$ad['title'])?></div><div class="sponsored-timer" id="ad-timer">Pular em <?=$duration?>s</div></div>
    <div class="sponsored-actions" id="sponsored-actions" aria-hidden="true">
      <div id="courtesy-error" class="sponsored-error" hidden></div>
      <div class="sponsored-button-row"><?php if ($hasOffer): ?><button id="interest-button" class="interest-button" type="button"><?=v3_h($interestButtonText)?></button><?php endif; ?><button id="courtesy-submit" class="skip-button" type="button" disabled>Pular em <?=$duration?>s</button></div>
      <div class="sponsored-note">O interesse será aberto somente depois que o Wi-Fi conectar.</div>
    </div>
  </main>
  <?php if($leadCapture): ?><div id="lead-overlay" class="lead-overlay" hidden><form id="lead-form" class="lead-card"><h2>Receber esta oferta</h2><p>Informe somente seu nome e celular. Isso não é obrigatório para usar o Wi-Fi.</p><label>Nome<input id="lead-name" name="name" type="text" autocomplete="name" maxlength="80" required></label><label>Celular com DDD<input id="lead-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="(92) 99999-9999" required></label><label class="lead-consent"><input name="consent" type="checkbox" value="1" required><span>Quero receber esta oferta no celular e sei que posso revogar meu consentimento.</span></label><p id="lead-error" class="lead-error" hidden></p><div class="lead-buttons"><button id="lead-cancel" class="lead-cancel" type="button">Agora não</button><button id="lead-send" class="lead-send" type="submit">Enviar e conectar</button></div></form></div><?php endif; ?>
<?php else: ?>
  <main class="shell narrow"><section class="panel<?=$activeReconnect?' success-panel':''?>"><?=v3_brand_mark($theme)?><p class="eyebrow"><?=$activeReconnect?'Acesso reconhecido':'Cortesia'?></p><h1><?=$activeReconnect?'Bem-vindo de volta':'Solicite seu acesso gratuito'?></h1><?php if($activeReconnect):?><p class="muted">Sua cortesia ainda possui saldo. Confirme para reconectar sem assistir novamente à propaganda.</p><?php endif;?><div id="courtesy-error" class="status-box error" hidden></div><button id="courtesy-submit" class="button primary" type="button"><?=$activeReconnect?'Confirmar e conectar':'Liberar cortesia'?></button><?php if(!$activeReconnect):?><a class="back" href="index.php?<?=v3_h(v3_hotspot_query($partner))?>&amp;step=options">← Voltar às modalidades</a><?php endif;?></section></main>
<?php endif; ?>

<?php if($platformExternal&&$platformDelivery&&!$authRequired&&$unavailableMessage===''): ?><script>
(() => {
  const csrf=<?=json_encode(csrf_token())?>, token=<?=json_encode($proofToken)?>;
  const start=document.getElementById('ad-platform-start'),connect=document.getElementById('courtesy-submit'),errorBox=document.getElementById('courtesy-error');
  let config=null,completed=0,busy=false,googleSlot=null,rewardPromise=null;
  const showError=message=>{errorBox.textContent=message;errorBox.hidden=false;start.hidden=false;start.disabled=false;busy=false;};
  async function event(name,extra={}){
    const response=await fetch('api/ad_platform_event.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf,token,event:name,...extra})});
    const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)throw new Error(data?.error||'Não foi possível validar o anúncio.');return data;
  }
  function ready(data){
    completed=Number(data.completed||completed);
    if(completed<Number(data.required||config.required||1)){start.textContent='Assistir ao próximo anúncio';start.hidden=false;start.disabled=false;busy=false;return;}
    if(data.simulation){showError('Teste concluído com sucesso. O modo de teste não libera internet nem gera receita.');start.hidden=true;return;}
    start.hidden=true;connect.hidden=false;connect.focus();busy=false;
  }
  async function mockReward(){
    start.textContent='Simulando anúncio seguro…';
    await new Promise(resolve=>setTimeout(resolve,2500));
    ready(await event('reward_granted',{provider_request_id:'mock-'+Date.now()}));
  }
  function loadGpt(){return new Promise((resolve,reject)=>{if(window.googletag?.apiReady){resolve();return;}const script=document.createElement('script');script.async=true;script.src='https://securepubads.g.doubleclick.net/tag/js/gpt.js';script.onload=resolve;script.onerror=()=>reject(new Error('Não foi possível carregar o provedor de anúncios.'));document.head.appendChild(script);});}
  async function googleReward(){
    await loadGpt();window.googletag=window.googletag||{cmd:[]};
    googletag.cmd.push(()=>{
      if(config.test_mode)googletag.setConfig({adsenseAttributes:{adsense_test_mode:'on'}});
      if(config.privacy_treatment==='limited')googletag.pubads().setPrivacySettings({limitedAds:true});
      else if(config.privacy_treatment==='non_personalized')googletag.pubads().setPrivacySettings({nonPersonalizedAds:true});
      const currentSlot=googletag.defineOutOfPageSlot(config.ad_unit_path,googletag.enums.OutOfPageFormat.REWARDED);googleSlot=currentSlot;
      if(!currentSlot){showError('O anúncio recompensado não está disponível neste aparelho.');return;}
      currentSlot.addService(googletag.pubads());
      googletag.pubads().addEventListener('rewardedSlotReady',e=>{if(e.slot===currentSlot)e.makeRewardedVisible();});
      googletag.pubads().addEventListener('impressionViewable',e=>{if(e.slot===currentSlot)event('impression',{provider_request_id:currentSlot.getSlotElementId?.()||''}).catch(()=>{});});
      googletag.pubads().addEventListener('rewardedSlotGranted',e=>{if(e.slot===currentSlot)rewardPromise=event('reward_granted',{provider_request_id:String(e.payload?.type||'rewarded')});});
      googletag.pubads().addEventListener('rewardedSlotClosed',async e=>{if(e.slot!==currentSlot)return;try{if(!rewardPromise)throw new Error('O anúncio foi fechado antes da conclusão.');ready(await rewardPromise);}catch(error){showError(error.message);}finally{googletag.destroySlots([currentSlot]);if(googleSlot===currentSlot)googleSlot=null;rewardPromise=null;}});
      googletag.pubads().addEventListener('slotRenderEnded',e=>{if(e.slot===currentSlot&&e.isEmpty)showError('Não há anúncio disponível agora. Tente novamente mais tarde.');});
      googletag.enableServices();googletag.display(currentSlot);
    });
  }
  start?.addEventListener('click',async()=>{if(busy)return;busy=true;start.disabled=true;errorBox.hidden=true;try{if(!config)config=await event('accept');if(config.provider==='mock')await mockReward();else if(config.provider==='google_ad_manager')await googleReward();else throw new Error('Provedor recompensado indisponível.');}catch(error){showError(error.message);}});
  connect?.addEventListener('click',async()=>{if(busy)return;busy=true;connect.disabled=true;connect.textContent='Conectando…';errorBox.hidden=true;try{const response=await fetch('api/courtesy_grant.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf,ad_token:token,ad_platform:'1'})});const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)throw new Error(data?.error||'Não foi possível conectar agora.');location.href=data.connect_url;}catch(error){errorBox.textContent=error.message;errorBox.hidden=false;connect.disabled=false;connect.textContent='Tentar conectar novamente';busy=false;}});
})();
</script><?php endif; ?>
<?php if (!$authRequired && $unavailableMessage === '' && !$platformExternal): ?><script>
const csrf=<?=json_encode(csrf_token())?>, proof=<?=json_encode($proofToken)?>, adId=<?=(int)($ad['id']??0)?>, skipLabel=<?=json_encode($skipButtonText)?>, leadCapture=<?=json_encode($leadCapture)?>;
const button=document.getElementById('courtesy-submit'),errorBox=document.getElementById('courtesy-error');
let interested=false,finished=false,submitting=false,interestPending=null,retryBlocked=false;
function retryTimestamp(value){if(!value)return 0;if(/^\d+$/.test(String(value)))return Number(value);const parsed=Date.parse(String(value));return Number.isFinite(parsed)?Math.floor(parsed/1000):0;}
function formatCourtesyWait(seconds){seconds=Math.max(1,Math.ceil(seconds));const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60);if(days>0)return `${days}d ${String(hours).padStart(2,'0')}h`;if(hours>0)return `${hours}h ${String(minutes).padStart(2,'0')}min`;if(minutes>0)return `${minutes}min ${String(seconds%60).padStart(2,'0')}s`;return `${seconds}s`;}
function blockCourtesyUntil(value){const retryAt=retryTimestamp(value);if(!retryAt)return false;retryBlocked=true;button.disabled=true;let interval=null;const render=()=>{const remaining=retryAt-Math.floor(Date.now()/1000);if(remaining>0){button.textContent=`Disponível novamente em ${formatCourtesyWait(remaining)}`;return;}if(interval)clearInterval(interval);retryBlocked=false;submitting=false;button.disabled=false;button.dataset.retryReady='1';button.textContent='Verificar disponibilidade';};render();if(retryBlocked)interval=setInterval(render,1000);return true;}
async function track(action,extra={}){if(!adId)return null;try{const body=new URLSearchParams({csrf,action,ad_id:String(adId),delivery_token:proof,...extra});const response=await fetch('../portal/api/ad_track.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});return await response.json();}catch(error){return null;}}
<?php if ($ad): ?>
track('impression');
let left=<?=$duration?>;const timer=document.getElementById('ad-timer');
function renderTimer(){timer.textContent=left>0?`Aguarde ${left}s`:'Anúncio concluído';button.textContent=left>0?`Aguarde ${left}s`:(interested?'Continuar para conectar':skipLabel);}
renderTimer();
const interval=setInterval(()=>{left=Math.max(0,left-1);renderTimer();if(left===0){clearInterval(interval);finished=true;button.disabled=false;const actions=document.getElementById('sponsored-actions');actions?.classList.add('is-visible');actions?.setAttribute('aria-hidden','false');document.querySelector('.sponsored-screen')?.classList.add('actions-visible');track('view_complete');}},1000);
document.getElementById('interest-button')?.addEventListener('click',async(event)=>{const interestButton=event.currentTarget;interestButton.disabled=true;interestPending=track('interest',{interest:'yes'});const result=await interestPending;interestPending=null;if(!result?.ok){interestButton.disabled=false;errorBox.textContent='Não foi possível abrir esta oferta. Tente novamente.';errorBox.hidden=false;return;}if(leadCapture){document.getElementById('lead-overlay').hidden=false;document.getElementById('lead-name')?.focus();interestButton.disabled=false;return;}if(!result.offer_queued){interestButton.disabled=false;errorBox.textContent='Não foi possível guardar seu interesse. Tente novamente.';errorBox.hidden=false;return;}interested=true;interestButton.textContent='Interesse registrado · conectando';interestButton.classList.add('done');errorBox.hidden=true;if(finished)renderTimer();setTimeout(()=>button?.click(),350);});
document.getElementById('lead-cancel')?.addEventListener('click',()=>{document.getElementById('lead-overlay').hidden=true;});
document.getElementById('lead-form')?.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,send=document.getElementById('lead-send'),leadError=document.getElementById('lead-error');send.disabled=true;leadError.hidden=true;try{const body=new FormData(form);body.set('csrf',csrf);body.set('delivery_token',proof);const response=await fetch('../portal/api/ad_lead.php',{method:'POST',credentials:'same-origin',body});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Não foi possível registrar seu interesse.');interested=true;document.getElementById('lead-overlay').hidden=true;const interestButton=document.getElementById('interest-button');interestButton.textContent='Oferta solicitada · conectando';interestButton.classList.add('done');interestButton.disabled=true;renderTimer();setTimeout(()=>button?.click(),250);}catch(error){leadError.textContent=error.message;leadError.hidden=false;send.disabled=false;}});
const video=document.getElementById('ad-video'),sound=document.getElementById('sound-button');sound?.addEventListener('click',()=>{video.muted=!video.muted;sound.textContent=video.muted?'Ativar som':'Desativar som';sound.setAttribute('aria-pressed',String(!video.muted));video.play().catch(()=>{});});
<?php endif; ?>
button?.addEventListener('click',async()=>{if(button.dataset.retryReady==='1'){location.href='index.php?<?=v3_h(v3_hotspot_query($partner))?>&step=options';return;}if(submitting||!finished&&adId)return;submitting=true;button.disabled=true;button.textContent='Conectando…';errorBox.hidden=true;if(interestPending)await interestPending;if(adId&&!interested){await track('interest',{interest:'no'});await track('skipped');}try{const response=await fetch('api/courtesy_grant.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf,ad_token:proof})});const data=await response.json();if(!response.ok||!data.ok){blockCourtesyUntil(data.retry_at);throw new Error(data.error||'Não foi possível conectar agora. Tente novamente.');}location.href=data.connect_url;}catch(error){errorBox.textContent=error.message;errorBox.hidden=false;if(!retryBlocked){button.textContent='Tentar conectar novamente';button.disabled=false;submitting=false;}}});
</script><?php endif; ?>
<?php if($courtesyRetryAt): ?><script>
(() => {
  const button = document.querySelector('.courtesy-entry-button[data-courtesy-retry-at]');
  if (!button) return;
  const formatWait = seconds => {
    seconds = Math.max(1, Math.ceil(seconds));
    const days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), minutes = Math.floor(seconds % 3600 / 60);
    if (days > 0) return `${days}d ${String(hours).padStart(2, '0')}h`;
    if (hours > 0) return `${hours}h ${String(minutes).padStart(2, '0')}min`;
    if (minutes > 0) return `${minutes}min ${String(seconds % 60).padStart(2, '0')}s`;
    return `${seconds}s`;
  };
  const update = () => {
    const remaining = Number(button.dataset.courtesyRetryAt) - Math.floor(Date.now() / 1000);
    if (remaining > 0) { button.textContent = `Disponível novamente em ${formatWait(remaining)}`; return; }
    button.textContent = button.dataset.courtesyReadyLabel;
    button.href = button.dataset.courtesyTarget;
    button.classList.remove('is-disabled');
    button.removeAttribute('aria-disabled');
    button.removeAttribute('tabindex');
  };
  button.addEventListener('click', event => { if (button.getAttribute('aria-disabled') === 'true') event.preventDefault(); });
  update();
  setInterval(update, 1000);
})();
</script><?php endif; ?>
</body>
</html>
