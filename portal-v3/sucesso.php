<?php
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../app/portal_v3_payment_window.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partner = v3_context($pdo);
$publicId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['order'] ?? '')));
$token = v3_order_token($publicId);
$order = fs_guest_order_for_session($pdo, $publicId, $token);
if (!$order || (int) $order['partner_id'] !== (int) $partner['id']) v3_fail('Pedido não encontrado nesta sessão.', 404);
$partner = v3_context_for_order($pdo,$order,$partner);
if (in_array((string)$order['status'],['payment_failed','cancelled','refunded'],true)) {
    $expired = strtolower((string)($order['payment_status_detail'] ?? '')) === 'expired'
        || fs_guest_payment_is_expired($order);
    v3_forget_order($publicId,(int)$partner['id']);
    header('Location: index.php?' . v3_hotspot_query($partner) . '&payment=' . ($expired ? 'expired' : 'not_approved'));
    exit;
}
v3_remember_order($order, $token);
$credit = null;
if ($order['status'] === 'paid') {
    $credit = fs_guest_credit_balance($pdo, $order);
    if ((int) $credit['remaining_seconds'] <= 0) {
        v3_clear_recovery_cookie((int) $partner['id']);
        header('Location: index.php?' . v3_hotspot_query($partner) . '&credit=exhausted');
        exit;
    }
}
$confirmReturn = $order['status'] === 'paid' && (string)($_GET['return'] ?? '') === '1';
$returnState = $confirmReturn ? v3_paid_return_state($pdo,$order) : null;
if ($confirmReturn && !empty($returnState['handoff_pending'])) {
    header('Location: sucesso.php?order=' . rawurlencode($publicId));
    exit;
}
$returnDestination = $confirmReturn && !empty($returnState['connected'])
    ? v3_paid_return_destination($partner,$pdo)
    : null;
if ($returnDestination !== null) {
    header('Location: ' . $returnDestination, true, 302);
    exit;
}
$returnCanConnect = $confirmReturn && !empty($returnState['connect_allowed']);
$returnConnected = $confirmReturn && !empty($returnState['connected']);
$remainingSeconds = $confirmReturn
    ? (int)($returnState['remaining_seconds'] ?? 0)
    : (int)($credit['remaining_seconds'] ?? 0);
$remainingLabel = v3_duration((int)max(1,ceil($remainingSeconds / 60)));
$paymentWindow = $order['status'] === 'pending'
    ? fs_v3_payment_window_status($pdo,$partner,$order)
    : ['enabled'=>false,'state'=>'not_applicable','can_start'=>false];
$reauthUrl = $order['status'] === 'pending' ? fs_v3_payment_window_reauth_url($partner) : null;
$pix = $order['status'] === 'pending' ? v3_pending_pix($publicId) : null;
$pendingTitle = 'Verificando o Pix';
$pendingMessage = 'A confirmação continua automática.';
if (!empty($paymentWindow['enabled'])) {
    if ($paymentWindow['state'] === 'active') {
        $pendingTitle = 'Conclua o Pix';
        $pendingMessage = 'Internet temporária ativa.';
    } elseif ($paymentWindow['state'] === 'cooling_down') {
        $pendingTitle = 'Tempo utilizado';
        $pendingMessage = 'Aguarde a próxima liberação.';
    } elseif ($paymentWindow['state'] === 'daily_limit') {
        $pendingTitle = 'Limite de tentativas atingido';
        $pendingMessage = 'O Pix continua válido.';
    } elseif ($paymentWindow['state'] === 'available') {
        $pendingTitle = 'Janela disponível';
        $pendingMessage = 'Você pode liberar internet novamente.';
    } elseif ($paymentWindow['state'] === 'unused') {
        $pendingTitle = 'Internet para o Pix';
        $pendingMessage = 'Libere uma janela temporária.';
    }
}
$theme = v3_theme_current();
?>
<!doctype html>
<html lang="pt-BR" data-v3-theme="<?= v3_h($theme['theme_mode']) ?>" data-v3-preset="<?= v3_h($theme['theme_preset']) ?>" data-v3-layout="<?= v3_theme_layout($theme) ?>" data-v3-show-title="<?= (int)$theme['show_title'] ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="<?= v3_h($theme['background_color']) ?>">
  <title>Liberando acesso — <?= v3_h($theme['brand_name']) ?></title>
  <link rel="stylesheet" href="assets/css/portal-v3.css?v=18">
  <?= v3_theme_css($theme) ?>
</head>
<body class="success-page">
<main class="shell narrow">
  <section class="panel success-panel<?= $confirmReturn ? ' return-panel' : '' ?>">
    <?php if ($confirmReturn): ?>
    <?= v3_brand_mark($theme) ?>
    <div class="success-icon" aria-hidden="true">✓</div>
    <p class="eyebrow">Acesso reconhecido</p>
    <h1><?= $returnConnected ? 'Conexão ativa' : 'Acesso disponível' ?></h1>
    <section class="return-credit-summary" aria-label="Saldo do acesso">
      <span>Tempo restante</span>
      <strong><?= v3_h($remainingLabel) ?></strong>
    </section>
    <div class="status-box" role="status">
      <?php if ($returnConnected): ?>Internet já liberada neste aparelho.<?php elseif ($returnCanConnect): ?>Crédito pronto para reconectar.<?php elseif (!empty($returnState['online_elsewhere'])): ?>Este acesso já está em uso.<?php else: ?>Este crédito pertence ao aparelho da compra.<?php endif; ?>
    </div>
    <?php if ($returnCanConnect): ?><a class="button primary" href="connect.php?order=<?= v3_h($publicId) ?>&amp;return=manual">Liberar acesso</a><?php endif; ?>
    <?php else: ?>
    <div class="step">3 de 3</div>
    <?= v3_brand_mark($theme) ?>
    <div id="success-icon" class="success-icon<?= $order['status'] === 'paid' ? '' : ' is-pending' ?>" aria-hidden="true"><?= $order['status'] === 'paid' ? '✓' : '⌛' ?></div>
    <p class="eyebrow brand-title"><?= v3_h($theme['brand_name']) ?></p>
    <h1 id="success-title"><?= $order['status'] === 'paid' ? 'Pagamento confirmado!' : v3_h($pendingTitle) ?></h1>
    <p id="success-message" class="muted"><?php if ($credit): ?>Crédito disponível: <?= v3_h($remainingLabel) ?>.<?php else: ?><?= v3_h($pendingMessage) ?><?php endif; ?></p>
    <section id="payment-window-card" class="payment-window-card"<?= empty($paymentWindow['enabled']) || $order['status'] === 'paid' ? ' hidden' : '' ?> aria-labelledby="payment-window-title">
      <div class="payment-window-card__head">
        <span class="payment-window-chip">Internet para o Pix</span>
        <h2 id="payment-window-title">Consultando sua janela…</h2>
      </div>
      <div class="payment-window-countdown">
        <span id="payment-window-countdown-label">Disponibilidade</span>
        <strong id="payment-window-countdown">—</strong>
        <small id="payment-window-next-at"></small>
      </div>
      <div class="payment-window-lower">
        <div class="payment-window-metrics">
          <div><span>Tentativas</span><strong><b id="payment-window-attempts">—</b> de <b id="payment-window-limit">—</b></strong></div>
          <div><span>Duração</span><strong id="payment-window-duration">—</strong></div>
        </div>
        <section id="payment-pix-card" class="payment-pix-card" hidden aria-label="QR Code para pagamento Pix">
          <img id="payment-pix-image" alt="QR Code do Pix para pagamento em outro celular" hidden>
          <div class="payment-pix-card__body">
            <strong>Pix em outro celular</strong>
            <span>Escaneie ou copie o código.</span>
            <button id="copy-payment-pix" class="button secondary" type="button">Copiar código Pix</button>
          </div>
        </section>
      </div>
      <p id="payment-window-explanation" class="payment-window-explanation"></p>
    </section>
    <div id="success-status" class="status-box" role="status" aria-live="polite">Verificando o pagamento automaticamente…</div>
    <div class="success-actions">
      <button id="retry-window-button" class="button primary" type="button" hidden>Liberar internet</button>
      <button id="open-portal-button" class="button primary" type="button" hidden>Abrir portal</button>
      <button id="refresh-payment-button" class="button secondary" type="button"<?= $order['status'] === 'paid' ? ' hidden' : '' ?>>Verificar Pix</button>
    </div>
    <a id="connect-button" class="button primary" href="connect.php?order=<?= v3_h($publicId) ?>"<?= $order['status'] === 'paid' ? '' : ' hidden' ?>>Conectar à internet</a>
    <?php endif; ?>
  </section>
</main>
<?php if (!$confirmReturn): ?>
<script>
window.PORTAL_V3_SUCCESS={order:<?= json_encode($publicId) ?>,csrf:<?= json_encode(csrf_token()) ?>,confirmReturn:false,status:<?= json_encode((string)$order['status']) ?>,apiPaymentWindow:'api/payment_window.php',reauthUrl:<?= json_encode($reauthUrl,JSON_UNESCAPED_SLASHES) ?>,restartUrl:<?= json_encode('index.php?' . v3_hotspot_query($partner) . '&payment=expired',JSON_UNESCAPED_SLASHES) ?>,paymentWindow:<?= json_encode($paymentWindow,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,pix:<?= json_encode($pix,JSON_UNESCAPED_SLASHES) ?>};
(function(){
  const cfg=window.PORTAL_V3_SUCCESS;
  const button=document.getElementById('connect-button');
  const refreshButton=document.getElementById('refresh-payment-button');
  const retryButton=document.getElementById('retry-window-button');
  const openPortalButton=document.getElementById('open-portal-button');
  const statusEl=document.getElementById('success-status');
  const titleEl=document.getElementById('success-title');
  const messageEl=document.getElementById('success-message');
  const iconEl=document.getElementById('success-icon');
  const windowCard=document.getElementById('payment-window-card');
  const windowTitle=document.getElementById('payment-window-title');
  const countdownLabel=document.getElementById('payment-window-countdown-label');
  const countdownEl=document.getElementById('payment-window-countdown');
  const nextAtEl=document.getElementById('payment-window-next-at');
  const attemptsEl=document.getElementById('payment-window-attempts');
  const limitEl=document.getElementById('payment-window-limit');
  const durationEl=document.getElementById('payment-window-duration');
  const explanationEl=document.getElementById('payment-window-explanation');
  const pixCard=document.getElementById('payment-pix-card');
  const pixImage=document.getElementById('payment-pix-image');
  const pixCopyButton=document.getElementById('copy-payment-pix');
  let pollAttempts=0, pollTimer=null, checking=false, countdownTimer=null, currentWindow=null, countdownStartedAt=0, reauthTimer=null, leavingForPortal=false, pixCopyCode='';
  function durationLabel(seconds){
    const minutes=Math.max(1,Math.ceil(Number(seconds||0)/60));
    if(minutes%1440===0){const days=minutes/1440;return days+(days===1?' dia':' dias');}
    if(minutes%60===0){const hours=minutes/60;return hours+(hours===1?' hora':' horas');}
    return minutes+' minutos';
  }
  function clockLabel(seconds){
    seconds=Math.max(0,Math.ceil(Number(seconds||0)));
    if(seconds>=3600){const hours=Math.floor(seconds/3600),minutes=Math.ceil((seconds%3600)/60);return hours+'h'+(minutes?' '+minutes+'min':'');}
    const minutes=Math.floor(seconds/60),rest=seconds%60;
    return String(minutes).padStart(2,'0')+':'+String(rest).padStart(2,'0');
  }
  function dateLabel(value){
    if(!value)return '';
    const date=new Date(value);
    if(Number.isNaN(date.getTime()))return '';
    const now=new Date(),tomorrow=new Date(now);tomorrow.setDate(now.getDate()+1);
    const same=(a,b)=>a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate();
    const time=date.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
    if(same(date,now))return 'Disponível hoje às '+time;
    if(same(date,tomorrow))return 'Disponível amanhã às '+time;
    return 'Disponível em '+date.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'})+' às '+time;
  }
  function remainingSeconds(){
    if(!currentWindow)return 0;
    const base=currentWindow.state==='active'?currentWindow.current_remaining_seconds:currentWindow.retry_after;
    return Math.max(0,Number(base||0)-Math.floor((Date.now()-countdownStartedAt)/1000));
  }
  function paintCountdown(){
    if(!currentWindow)return;
    const remaining=remainingSeconds();
    if(currentWindow.state==='available'||currentWindow.state==='unused')countdownEl.textContent='Agora';
    else countdownEl.textContent=clockLabel(remaining);
    if(remaining===0&&(currentWindow.state==='active'||currentWindow.state==='cooling_down'||currentWindow.state==='daily_limit')){
      window.clearInterval(countdownTimer);countdownTimer=null;
      window.setTimeout(function(){check(true);},250);
    }
  }
  function openPortal(){
    if(leavingForPortal||!cfg.reauthUrl)return;
    leavingForPortal=true;window.clearTimeout(pollTimer);window.clearInterval(countdownTimer);
    statusEl.classList.remove('error');statusEl.textContent='Abrindo o portal…';
    const separator=cfg.reauthUrl.indexOf('?')===-1?'?':'&';
    window.location.replace(cfg.reauthUrl+separator+'firespot_return='+Date.now());
  }
  function schedulePortalReturn(){
    if(!cfg.reauthUrl||leavingForPortal)return;
    window.clearTimeout(reauthTimer);
    statusEl.textContent='Tempo encerrado · retornando ao portal…';
    reauthTimer=window.setTimeout(openPortal,1500);
  }
  function renderPix(pix){
    if(!pix||typeof pix!=='object'){
      pixCopyCode='';pixImage.hidden=true;pixImage.removeAttribute('src');pixCopyButton.disabled=true;pixCard.hidden=true;windowCard.classList.remove('has-pix');return;
    }
    const code=typeof pix.qr_code==='string'?pix.qr_code.trim():'';
    const image=typeof pix.qr_code_base64==='string'?pix.qr_code_base64.trim():'';
    const hasImage=/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(image);
    pixCopyCode=code;
    pixImage.hidden=!hasImage;
    if(hasImage)pixImage.src=image;else pixImage.removeAttribute('src');
    pixCopyButton.disabled=!code;
    pixCard.classList.toggle('without-image',!hasImage);
    pixCard.hidden=!hasImage&&!code;
    windowCard.classList.toggle('has-pix',!pixCard.hidden);
  }
  async function copyPix(){
    if(!pixCopyCode)return false;
    let copied=false;
    try{
      if(!navigator.clipboard||typeof navigator.clipboard.writeText!=='function')throw new Error('clipboard_unavailable');
      await navigator.clipboard.writeText(pixCopyCode);copied=true;
    }catch(error){
      const field=document.createElement('textarea');
      try{
        field.value=pixCopyCode;field.readOnly=true;field.style.position='fixed';field.style.opacity='0';field.style.pointerEvents='none';
        document.body.appendChild(field);field.select();copied=document.execCommand('copy');
      }catch(fallbackError){copied=false;}finally{field.remove();}
    }
    if(!copied){statusEl.classList.add('error');statusEl.textContent='Não foi possível copiar. Use outro celular para escanear o QR Code.';return false;}
    pixCopyButton.textContent='Código copiado!';
    window.setTimeout(function(){pixCopyButton.textContent='Copiar código Pix';},1800);
    return true;
  }
  function renderWindow(policy){
    if(cfg.confirmReturn||cfg.status==='paid'||!policy||!policy.enabled){windowCard.hidden=true;return;}
    currentWindow=policy;countdownStartedAt=Date.now();windowCard.hidden=false;
    windowCard.dataset.state=policy.state;
    attemptsEl.textContent=String(policy.attempts_used);
    limitEl.textContent=String(policy.daily_limit);
    durationEl.textContent=String(policy.window_minutes)+' min';
    retryButton.hidden=!policy.can_start;
    retryButton.textContent='Liberar '+policy.window_minutes+' min';
    openPortalButton.hidden=!cfg.reauthUrl||!policy.window_used||!['cooling_down','daily_limit'].includes(policy.state);
    nextAtEl.textContent=dateLabel(policy.state==='active'?policy.current_expires_at:policy.next_available_at);
    iconEl.className='success-icon is-pending';iconEl.textContent='⌛';
    if(policy.state==='active'){
      titleEl.textContent='Conclua o Pix';
      messageEl.textContent='Internet temporária ativa.';
      windowTitle.textContent='Janela ativa';countdownLabel.textContent='Termina em';
      nextAtEl.textContent=(nextAtEl.textContent||'').replace('Disponível ','Encerra ');
      explanationEl.textContent='Use este tempo para concluir o Pix.';
    }else if(policy.state==='cooling_down'){
      titleEl.textContent='Tempo utilizado';
      messageEl.textContent='Aguarde a próxima liberação.';
      windowTitle.textContent='Em espera';countdownLabel.textContent='Libera em';
      explanationEl.textContent='Pix válido · confirmação automática.';
    }else if(policy.state==='daily_limit'){
      titleEl.textContent='Limite de tentativas atingido';
      messageEl.textContent='O Pix continua válido.';
      windowTitle.textContent='Sem tentativas';countdownLabel.textContent='Renova em';
      explanationEl.textContent='Use dados móveis ou outro aparelho para pagar.';
    }else{
      titleEl.textContent=policy.window_used?'Janela disponível':'Internet para o Pix';
      messageEl.textContent=policy.window_used?'Você pode liberar internet novamente.':'Libere uma janela temporária.';
      windowTitle.textContent='Pronta para uso';countdownLabel.textContent='Disponível';nextAtEl.textContent='';
      explanationEl.textContent='Ao liberar, usa 1 tentativa.';
    }
    if(countdownTimer)window.clearInterval(countdownTimer);
    paintCountdown();countdownTimer=window.setInterval(paintCountdown,1000);
  }
  function scheduleCheck(){
    window.clearTimeout(pollTimer);
    pollTimer=window.setTimeout(check,pollAttempts<150?4000:15000);
  }
  async function check(manual){
    if(checking||leavingForPortal)return;
    const previousState=currentWindow&&currentWindow.state;
    const windowEnded=previousState==='active'&&remainingSeconds()===0;
    checking=true;pollAttempts++;
    if(manual){refreshButton.disabled=true;refreshButton.textContent='Verificando…';statusEl.textContent='Consultando o Pix…';}
    try {
      const response=await fetch('api/payment_status.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order:cfg.order,csrf:cfg.csrf})});
      const data=await response.json();
      if(data.reauth_url)cfg.reauthUrl=data.reauth_url;
      if(data.pix)renderPix(data.pix);
      if(data.payment_window)renderWindow(data.payment_window);
      if(data.status==='paid' && data.granted){
        titleEl.textContent='Pagamento confirmado!';
        messageEl.textContent='Você ainda possui aproximadamente '+durationLabel(data.remaining_seconds)+' de crédito.';
        iconEl.className='success-icon';iconEl.textContent='✓';windowCard.hidden=true;pixCard.hidden=true;refreshButton.hidden=true;retryButton.hidden=true;openPortalButton.hidden=true;
        if(data.connected){
          statusEl.textContent='Internet liberada. Você já pode navegar.';button.hidden=true;
          window.clearTimeout(pollTimer);window.clearInterval(countdownTimer);return;
        }
        if(data.handoff_status==='pending'||data.handoff_status==='sending'||(data.handoff_status==='waiting_session'&&Number(data.handoff_attempts||0)<4)){
          statusEl.textContent='Pagamento confirmado. Finalizando a conexão…';button.hidden=true;scheduleCheck();return;
        }
        if(Number(data.handoff_retry_after||0)>0){
          statusEl.textContent='Pagamento confirmado. Atualizando a sessão ativa…';button.hidden=true;scheduleCheck();return;
        }
        statusEl.textContent='Pagamento confirmado. Toque para concluir a conexão.';
        button.hidden=false;return;
      }
      if(data.status==='payment_failed' || data.status==='cancelled' || data.status==='refunded'){
        window.clearTimeout(pollTimer);window.clearInterval(countdownTimer);window.clearTimeout(reauthTimer);
        renderPix(null);windowCard.hidden=true;refreshButton.hidden=true;retryButton.hidden=true;openPortalButton.hidden=true;
        iconEl.className='success-icon is-pending';iconEl.textContent='↻';
        titleEl.textContent=data.payment_expired?'Pix vencido':'Pagamento não aprovado';
        messageEl.textContent='Você já pode iniciar uma nova compra.';
        statusEl.classList.remove('error');statusEl.textContent='Retornando às opções de acesso…';
        window.setTimeout(function(){window.location.replace(data.restart_url||cfg.restartUrl);},1200);
        return;
      }
      if(previousState==='active'&&data.payment_window&&data.payment_window.state!=='active'){
        schedulePortalReturn();
        return;
      }
      statusEl.classList.remove('error');
      statusEl.textContent='Pix pendente · verificação automática ativa';
    } catch(error) {
      statusEl.textContent='Não foi possível atualizar agora. Tentaremos novamente em instantes.';
      if(windowEnded){schedulePortalReturn();return;}
    } finally {
      checking=false;refreshButton.disabled=false;refreshButton.textContent='Verificar Pix';
    }
    scheduleCheck();
  }
  async function retryWindow(){
    retryButton.disabled=true;retryButton.textContent='Liberando…';
    try{
      if(pixCopyCode)await copyPix();
      const response=await fetch(cfg.apiPaymentWindow,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order:cfg.order,csrf:cfg.csrf})});
      const data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.error||'Não foi possível liberar outra janela.');
      if(data.connect_url){
        statusEl.classList.remove('error');statusEl.textContent='Autenticando o acesso temporário…';
        window.location.href=data.connect_url;return;
      }
      if(data.payment_window)renderWindow(data.payment_window);
      statusEl.classList.remove('error');statusEl.textContent='Internet temporária liberada. Abra o aplicativo do banco para concluir o Pix.';
    }catch(error){statusEl.textContent=error.message;statusEl.classList.add('error');window.setTimeout(function(){check(true);},500);}
    finally{retryButton.disabled=false;retryButton.textContent=currentWindow?'Liberar '+currentWindow.window_minutes+' min':'Liberar internet';}
  }
  refreshButton.addEventListener('click',function(){check(true);});
  retryButton.addEventListener('click',retryWindow);
  openPortalButton.addEventListener('click',openPortal);
  pixCopyButton.addEventListener('click',copyPix);
  document.addEventListener('visibilitychange',function(){
    if(document.visibilityState==='visible'&&!cfg.confirmReturn)check(true);
  });
  window.addEventListener('pageshow',function(event){
    if(event.persisted&&!cfg.confirmReturn)check(true);
  });
  renderPix(cfg.pix);
  renderWindow(cfg.paymentWindow);
  if(!cfg.confirmReturn)check();
})();
</script>
<?php endif; ?>
</body>
</html>
