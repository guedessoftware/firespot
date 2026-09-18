<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/partner_ads.php';
require_once __DIR__ . '/../app/ad_monetization.php';
// Permitir acesso anônimo ao anúncio; o endpoint ad_grant decidirá se exige login
// Garante contexto do parceiro (para que conect.php use o dns_name correto)
$partnerSet = false;
try {
  $directCode = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
  if ($directCode !== '') {
    $_SESSION['portal_fast_id'] = $directCode; $partnerSet = true;
  } else {
    $nextRaw = isset($_GET['next']) ? (string)$_GET['next'] : '';
    $candidate = $nextRaw;
    // Se vier URL-encoded, decodifica 1-2 vezes até encontrar '?code='
    for ($i=0; $i<2 && $candidate !== '' && strpos($candidate, 'code=') === false; $i++) {
      $candidate = urldecode($candidate);
    }
    if ($candidate !== '') {
      $q = parse_url($candidate, PHP_URL_QUERY);
      if (is_string($q) && $q !== '') {
        parse_str($q, $arr);
        if (!empty($arr['code'])) {
          $_SESSION['portal_fast_id'] = (string)$arr['code'];
          $partnerSet = true;
        }
      } else if (preg_match('/[?&]code=([A-Za-z0-9_-]+)/', $candidate, $m)) {
        $_SESSION['portal_fast_id'] = $m[1];
        $partnerSet = true;
      }
    }
  }
  // Fallback: casar pelo HTTP_HOST com partners.dns_name
  if (!$partnerSet) {
    $hostHdr = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($hostHdr !== '') {
      try {
        $pdoTmp = db();
        $pdoTmp->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $st = $pdoTmp->prepare('SELECT code FROM partners WHERE dns_name=? AND active=1 LIMIT 1');
        $st->execute([$hostHdr]);
        if ($code = $st->fetchColumn()) {
          $_SESSION['portal_fast_id'] = (string)$code;
          $partnerSet = true;
        }
      } catch (\Throwable $e2) { /* ignore */ }
    }
  }
} catch (\Throwable $e) { /* ignore */ }
$adMinutes = (int) settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 10);
if ($adMinutes <= 0) $adMinutes = 10; if ($adMinutes > 120) $adMinutes = 120;
$currentPartner = null;
try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  $fastId = trim((string)($_SESSION['portal_fast_id'] ?? ''));
  if ($fastId !== '') {
    $hasPartners = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      $row=fs_partner_hotspot_resolve($pdo,$fastId,false,false);
      if(!$row){$st = $pdo->prepare("SELECT * FROM partners WHERE code=? LIMIT 1");$st->execute([$fastId]);$row=$st->fetch(PDO::FETCH_ASSOC)?:[];}
      if ($row) {
        if ((int)($row['active'] ?? 0) === 1) {
          $currentPartner = $row;
          $min = (int)($row['free_minutes'] ?? 0);
          if ($min > 0) { $adMinutes = $min; if ($adMinutes > 120) $adMinutes = 120; }
        }
      }
    }
  }
} catch (\Throwable $e) { /* ignore partner detection errors */ }

// Custom Ads settings
$customEnabled = settings_get('custom_ads_enabled', getenv('CUSTOM_ADS_ENABLED') !== false ? (string)getenv('CUSTOM_ADS_ENABLED') : '0');
$customDefault = (int) settings_get('custom_ads_default', getenv('CUSTOM_ADS_DEFAULT') ?: 5);
if ($customDefault < 5) $customDefault = 5; if ($customDefault > 180) $customDefault = 180;

// Seleção isolada por partner_id. A requisição nunca cria ou altera esquema.
$pickedAd = null;
try {
  $pdo = db();
  if (!empty($currentPartner)) $pickedAd = partner_ads_pick($pdo,$currentPartner);
} catch (\Throwable $e) { /* ignore */ }

// Prova server-side de que esta sessão permaneceu na tela até o término do
// contador. O token só é exigido pelo motor unificado quando requires_ad=1.
$hasVideoSlot = false; // Bloco AdSense comum nunca comprova anúncio recompensado.
if(empty($currentPartner))$currentPartner=['id'=>0,'code'=>'legacy-unavailable'];
$adProofDuration = $pickedAd
  ? max(1, (int)(($pickedAd['duration_sec'] ?? 0) ?: $customDefault))
  : ($hasVideoSlot ? 30 : 15);
$adProofToken = ($pickedAd || $hasVideoSlot) ? bin2hex(random_bytes(24)) : '';
$delivery = null;
if ($pickedAd && $currentPartner) {
  try {
    $device=$_SESSION['hotspot_device_info']??[];
    $delivery=fs_ad_delivery_begin($pdo,$currentPartner,$pickedAd,$adProofDuration,['mac'=>$device['mac']??'','ip'=>$device['ip']??''],false);
    if(!empty($delivery['token']))$adProofToken=(string)$delivery['token'];
  } catch (Throwable $e) { error_log('[ad delivery begin] '.get_class($e)); }
}
$adProofPartner = trim((string)($_SESSION['portal_fast_id'] ?? ''));
if (!isset($_SESSION['courtesy_ad_proofs']) || !is_array($_SESSION['courtesy_ad_proofs'])) {
  $_SESSION['courtesy_ad_proofs'] = [];
}
$nowProof = time();
foreach ($_SESSION['courtesy_ad_proofs'] as $savedToken => $savedProof) {
  if (!is_array($savedProof) || (int)($savedProof['expires_at'] ?? 0) <= $nowProof) {
    unset($_SESSION['courtesy_ad_proofs'][$savedToken]);
  }
}
if ($adProofToken !== '') {
  $_SESSION['courtesy_ad_proofs'][$adProofToken] = [
    'partner_code' => $adProofPartner,
    'partner_id' => (int)($currentPartner['id'] ?? 0),
    'hotspot_id' => (int)(fs_partner_hotspot_id($currentPartner ?? []) ?? 0),
    'ad_id' => (int)($pickedAd['id'] ?? 0),
    'ready_at' => $nowProof + $adProofDuration,
    'expires_at' => $nowProof + max(900, $adProofDuration + 300),
  ];
}
if (count($_SESSION['courtesy_ad_proofs']) > 12) {
  $_SESSION['courtesy_ad_proofs'] = array_slice($_SESSION['courtesy_ad_proofs'], -12, null, true);
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Assistir anúncio</title>
  <style>
    html,body{height:100%;margin:0}
    body{overflow:hidden;background:#000;color:#fff;font-family:system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, sans-serif}
    .ad-screen{position:relative;width:100vw;height:100vh;background:#000;display:flex;align-items:center;justify-content:center}
    .ad-media{width:100%;height:100%;object-fit:contain;background:#000}
    .ad-countdown{position:fixed;top:12px;right:12px;background:rgba(0,0,0,.6);color:#fff;padding:6px 10px;border-radius:999px;font-weight:700;font-size:14px;z-index:20}
    .ad-buttons{position:fixed;left:0;right:0;bottom:0;padding:max(12px,calc(env(safe-area-inset-bottom) + 10px));display:flex;gap:9px;justify-content:center;background:linear-gradient(to top,rgba(0,0,0,.68),rgba(0,0,0,0));z-index:20;opacity:0;visibility:hidden;transform:translateY(18px);pointer-events:none;transition:opacity .22s,transform .22s,visibility .22s}.ad-buttons.is-visible{opacity:1;visibility:visible;transform:none;pointer-events:auto}
    .ad-buttons .btn{flex:1;max-width:260px;min-height:46px;padding:10px 12px;border:none;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25)}
    .btn-like{background:#10b981;color:#042b20}
    .btn-like.done{background:#15803d;color:#fff}
    .btn-skip{background:#fff;color:#0f172a}
    .btn:disabled{cursor:not-allowed;opacity:.58}
    .ad-title{position:fixed;left:12px;top:12px;right:auto;color:#fff;font-size:14px;background:rgba(0,0,0,.45);padding:6px 10px;border-radius:10px;max-width:60vw;z-index:20}
    .ad-toast{position:fixed;left:50%;transform:translateX(-50%);bottom:90px;background:rgba(0,0,0,.85);color:#fff;padding:10px 14px;border-radius:10px;font-weight:600;z-index:30;opacity:0;pointer-events:none;transition:opacity .2s}
    .ad-toast.show{opacity:1}
    .sound-button{position:fixed;right:12px;top:58px;z-index:22;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:8px 12px;background:rgba(0,0,0,.65);color:#fff;font-weight:700}
    .lead-overlay{position:fixed;inset:0;z-index:50;display:grid;place-items:end center;padding:16px;background:rgba(0,0,0,.72);backdrop-filter:blur(5px)}.lead-overlay[hidden]{display:none}.lead-card{box-sizing:border-box;width:min(100%,440px);padding:20px;border-radius:20px;background:#fff;color:#0f172a;box-shadow:0 24px 70px rgba(0,0,0,.45)}.lead-card h2{margin:0 0 6px}.lead-card p{margin:0 0 14px;color:#475569}.lead-card label{display:grid;gap:6px;margin:11px 0;font-size:13px;font-weight:750}.lead-card input[type=text],.lead-card input[type=tel]{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:11px;font:inherit}.lead-consent{grid-template-columns:auto 1fr!important;align-items:start;font-weight:500!important}.lead-actions{display:grid;grid-template-columns:1fr 1.4fr;gap:8px;margin-top:14px}.lead-actions button{min-height:44px;border:0;border-radius:11px;font-weight:800}.lead-cancel{background:#e2e8f0}.lead-send{background:#10b981}.lead-error{color:#b91c1c!important;font-size:13px}
  </style>
</head>
<body>
  <?php if ($pickedAd): ?>
    <?php $dur = $adProofDuration; $interestButtonText=partner_ads_button_text($pickedAd['interest_button_text']??null,'Tenho interesse');$skipButtonText=partner_ads_button_text($pickedAd['skip_button_text']??null,'Pular e conectar');$leadCapture=!empty($pickedAd['lead_capture_enabled']); ?>
    <main class="ad-screen">
      <?php if (partner_ads_media_type((string)($pickedAd['media_type'] ?? 'image')) === 'video'): ?>
        <video id="ad-video" class="ad-media" autoplay muted loop playsinline preload="auto" poster="<?= htmlspecialchars((string)($pickedAd['poster_url'] ?? '')) ?>" style="object-fit:<?= partner_ads_fit_mode((string)($pickedAd['fit_mode'] ?? 'contain')) ?>"><source src="<?= htmlspecialchars(partner_ads_media_url($pickedAd)) ?>" type="video/mp4"></video>
        <button id="sound-button" class="sound-button" type="button">Ativar som</button>
      <?php else: ?>
        <img class="ad-media" src="<?= htmlspecialchars(partner_ads_media_url($pickedAd)) ?>" alt="<?= htmlspecialchars($pickedAd['title'] ?? 'Anúncio') ?>" style="object-fit:<?= partner_ads_fit_mode((string)($pickedAd['fit_mode'] ?? 'contain')) ?>">
      <?php endif; ?>
      <?php if (!empty($pickedAd['title'])): ?><div class="ad-title"><?= htmlspecialchars($pickedAd['title']) ?></div><?php endif; ?>
      <div class="ad-countdown">⏱ <span id="timer">--</span>s</div>
      <div class="ad-buttons" id="ad-actions" aria-hidden="true">
        <?php if (!empty($pickedAd['link_url'])||$leadCapture): ?><button id="btn-like" class="btn btn-like" type="button"><?=htmlspecialchars($interestButtonText)?></button><?php endif; ?>
        <button id="btn-skip" class="btn btn-skip" type="button" disabled>Pular em <?= (int)$dur ?>s</button>
      </div>
    </main>
    <?php if($leadCapture): ?><div id="lead-overlay" class="lead-overlay" hidden><form id="lead-form" class="lead-card"><h2>Receber esta oferta</h2><p>Nome e celular são usados somente para enviar a oferta. O preenchimento não é obrigatório para conectar.</p><label>Nome<input name="name" id="lead-name" type="text" maxlength="80" autocomplete="name" required></label><label>Celular com DDD<input name="phone" type="tel" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="(92) 99999-9999" required></label><label class="lead-consent"><input name="consent" type="checkbox" value="1" required><span>Quero receber esta oferta no celular e sei que posso revogar o consentimento.</span></label><p id="lead-error" class="lead-error" hidden></p><div class="lead-actions"><button id="lead-cancel" class="lead-cancel" type="button">Agora não</button><button id="lead-send" class="lead-send" type="submit">Enviar e conectar</button></div></form></div><?php endif; ?>
    <div id="ad-toast" class="ad-toast" aria-live="polite" aria-atomic="true" style="display:block">Obrigado pela preferência! 😊</div>
    <script>
      const AD_ID = <?= (int)$pickedAd['id'] ?>;
      const DURATION = <?= (int)$dur ?>;
      const SKIP_LABEL = <?= json_encode($skipButtonText) ?>;
      const AD_PROOF_TOKEN = <?= json_encode($adProofToken) ?>;
      const AD_CSRF = <?= json_encode(csrf_token()) ?>;
      const LEAD_CAPTURE = <?= json_encode($leadCapture) ?>;
      // impressão
      fetch('api/ad_track.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf='+encodeURIComponent(AD_CSRF)+'&action=impression&ad_id='+encodeURIComponent(AD_ID)});
      let left = DURATION, interested = false, finished = false, submitting = false, interestPending = null;
      const tEl = document.getElementById('timer');
      const likeBtn = document.getElementById('btn-like');
      const skipBtn = document.getElementById('btn-skip');
      function render(){ if(tEl) tEl.textContent = String(left); if(skipBtn)skipBtn.textContent=left>0?'Pular em '+left+'s':(interested?'Continuar para conectar':SKIP_LABEL); }
      render();
      const iv = setInterval(()=>{ left=Math.max(0,left-1); render(); if(left<=0){ clearInterval(iv); finished=true; skipBtn && (skipBtn.disabled=false); const actions=document.getElementById('ad-actions');actions?.classList.add('is-visible');actions?.setAttribute('aria-hidden','false');sendEvent('view_complete'); } }, 1000);
      const toastEl = document.getElementById('ad-toast');
      function showToast(){ if(!toastEl) return; toastEl.classList.add('show'); setTimeout(()=>{ toastEl.classList.remove('show'); }, 1500); }

      async function grantAndRedirect(){
        try{
          const resp = await fetch('api/ad_grant.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'csrf='+encodeURIComponent(AD_CSRF)+'&ad_token='+encodeURIComponent(AD_PROOF_TOKEN)
          });
          const data = await resp.json().catch(()=>null);
          if (!resp.ok || !data || !data.ok){
            if (data && (data.code === 'COOLDOWN' || data.code === 'DAILY_CAP')) { const when = new Date((data.retry_at||0)*1000).toLocaleTimeString(); alert('Aguarde antes de solicitar novamente. Tente às '+when+'.'); window.location.href='cliente.php'; return; }
            if (data && data.code === 'ASSOC_REQUIRED' && data.login){ window.location.href=data.login; return; }
            if (data && data.code === 'NO_CTX') { window.location.href='hotspot_do_login.php'; return; }
            if (data && data.code === 'NOT_ELIGIBLE') { window.location.href='hotspot_do_login.php'; return; }
            window.location.href='hotspot_do_login.php';
            return;
          }
          if (data && data.login_url) { window.location.href = data.login_url; return; }
        }catch(err){ console.error(err); alert('Não foi possível liberar o acesso no momento.'); window.location.href='cliente.php'; return; }
        window.location.href='hotspot_do_login.php';
      }

      async function sendInterest(val){
        try{ const response=await fetch('api/ad_track.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf='+encodeURIComponent(AD_CSRF)+'&action=interest&ad_id='+encodeURIComponent(AD_ID)+'&interest='+encodeURIComponent(val)+'&delivery_token='+encodeURIComponent(AD_PROOF_TOKEN)}); return await response.json(); }catch(e){return null;}
      }
      async function sendEvent(action){try{await fetch('api/ad_track.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf='+encodeURIComponent(AD_CSRF)+'&action='+encodeURIComponent(action)+'&ad_id='+encodeURIComponent(AD_ID)+'&delivery_token='+encodeURIComponent(AD_PROOF_TOKEN)});}catch(e){}}
      document.getElementById('btn-like')?.addEventListener('click', async event=>{
        const target=event.currentTarget;target.disabled=true;
        interestPending=sendInterest('yes');const result=await interestPending;interestPending=null;
        if(!result?.ok){target.disabled=false;return;}
        if(LEAD_CAPTURE){document.getElementById('lead-overlay').hidden=false;document.getElementById('lead-name')?.focus();target.disabled=false;return;}
        if(!result.offer_queued){target.disabled=false;return;}
        interested=true;target.textContent='Interesse registrado · conectando';target.classList.add('done');render();showToast();setTimeout(()=>skipBtn?.click(),350);
      });
      document.getElementById('lead-cancel')?.addEventListener('click',()=>{document.getElementById('lead-overlay').hidden=true;});
      document.getElementById('lead-form')?.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,send=document.getElementById('lead-send'),leadError=document.getElementById('lead-error');send.disabled=true;leadError.hidden=true;try{const body=new FormData(form);body.set('csrf',AD_CSRF);body.set('delivery_token',AD_PROOF_TOKEN);const response=await fetch('api/ad_lead.php',{method:'POST',credentials:'same-origin',body});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Não foi possível registrar seu interesse.');interested=true;document.getElementById('lead-overlay').hidden=true;likeBtn.textContent='Oferta solicitada · conectando';likeBtn.classList.add('done');likeBtn.disabled=true;render();showToast();setTimeout(()=>skipBtn?.click(),250);}catch(error){leadError.textContent=error.message;leadError.hidden=false;send.disabled=false;}});
      document.getElementById('btn-skip')?.addEventListener('click', async ()=>{if(!finished||submitting)return;submitting=true;skipBtn.disabled=true;if(interestPending)await interestPending;if(!interested){await sendInterest('no');await sendEvent('skipped');}grantAndRedirect();});
      const adVideo=document.getElementById('ad-video'),soundButton=document.getElementById('sound-button');soundButton?.addEventListener('click',()=>{adVideo.muted=!adVideo.muted;soundButton.textContent=adVideo.muted?'Ativar som':'Desativar som';adVideo.play().catch(()=>{});});
    </script>
  <?php elseif (!empty($currentPartner)): ?>
    <main class="ad-screen"><div style="max-width:560px;padding:28px;text-align:center"><h1>Anúncio indisponível</h1><p>Não existe uma campanha elegível para este estabelecimento agora. Nenhuma cortesia foi concedida.</p><a href="cliente.php" style="color:#fff">Voltar</a></div></main>
  <?php else: ?>
    <main class="ad-screen">
      <div id="ad-slot" class="ad-media" style="display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;">
        <?php $slotVideo = $hasVideoSlot; ?>
        <?php if ($slotVideo): ?>
          <span>Formato legado desativado.</span>
        <?php else: ?>
          <span id="ad-timer">Carregando…</span>
        <?php endif; ?>
      </div>
      <div class="ad-countdown">⏱ <span id="timer">--</span>s</div>
      <div class="ad-buttons" id="ad-actions" aria-hidden="true">
        <button id="btn-skip" class="btn btn-skip" type="button" disabled>Pular</button>
      </div>
    </main>
    <script>
      const hasSlot = <?= json_encode($hasVideoSlot) ?>;
      const AD_PROOF_TOKEN = <?= json_encode($adProofToken) ?>;
      const AD_CSRF = <?= json_encode(csrf_token()) ?>;
      let left = <?= (int)$adProofDuration ?>;
      const tEl = document.getElementById('timer');
      function render(){ if (tEl) tEl.textContent = String(left); if(skipBtn)skipBtn.textContent=left>0?'Pular em '+left+'s':'Pular e conectar'; }
      const skipBtn = document.getElementById('btn-skip');
      render();
      const iv = setInterval(()=>{ left=Math.max(0,left-1); render(); if (left<=0){ clearInterval(iv); skipBtn && (skipBtn.disabled=false); const actions=document.getElementById('ad-actions');actions?.classList.add('is-visible');actions?.setAttribute('aria-hidden','false'); } }, 1000);
      async function grantAndRedirect(){
        try{
          const resp = await fetch('api/ad_grant.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'csrf='+encodeURIComponent(AD_CSRF)+'&ad_token='+encodeURIComponent(AD_PROOF_TOKEN)
          });
          const data = await resp.json().catch(()=>null);
          if (!resp.ok || !data || !data.ok){
            if (data && (data.code === 'COOLDOWN' || data.code === 'DAILY_CAP')) { const when = new Date((data.retry_at||0)*1000).toLocaleTimeString(); alert('Aguarde antes de solicitar novamente. Tente às ' + when + '.'); window.location.href = 'cliente.php'; return; }
            if (data && data.code === 'ASSOC_REQUIRED' && data.login){ window.location.href = data.login; return; }
            if (data && data.code === 'NO_CTX') { window.location.href = 'hotspot_do_login.php'; return; }
            if (data && data.code === 'NOT_ELIGIBLE') { window.location.href = 'hotspot_do_login.php'; return; }
            window.location.href = 'hotspot_do_login.php';
            return;
          }
          if (data && data.login_url) { window.location.href = data.login_url; return; }
        }catch(err){ console.error(err); alert('Não foi possível liberar o acesso no momento.'); window.location.href = 'cliente.php'; return; }
        window.location.href = 'hotspot_do_login.php';
      }
      document.getElementById('btn-skip')?.addEventListener('click', grantAndRedirect);
    </script>
  <?php endif; ?>
</body>
</html>
