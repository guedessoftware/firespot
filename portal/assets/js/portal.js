// /portal/assets/js/portal.js
(function(){
  const $ = (s, r=document)=>r.querySelector(s);
  function detectDevice(){
    const ua = navigator.userAgent || '';
    let os = 'Unknown', dev='';
    if (/Android/i.test(ua)) { os='Android'; dev='🤖'; }
    else if (/iPhone|iPad|iPod/i.test(ua)) { os='iOS'; dev='🍎'; }
    else if (/Windows/i.test(ua)) { os='Windows'; dev='🪟'; }
    else if (/Linux/i.test(ua)) { os='Linux'; dev='🐧'; }
    return { ua, os, dev };
  }
  async function sendDevice(username){
    try{
      const d = detectDevice();
      const fd = new FormData();
      fd.set('username', username||'');
      fd.set('ua', d.ua);
      fd.set('os', d.os);
      await fetch('api/capture_device.php', { method:'POST', body: fd });
    }catch(e){ console.warn('capture_device', e); }
  }
  window.FIRESPOT_PORTAL = { sendDevice };

  // Abre links marcados para o navegador completo (fora do captive/webview)
  document.addEventListener('click', function(ev){
    const link = ev.target.closest('[data-open-full-browser]');
    if (!link) return;

    const href = link.getAttribute('href');
    if (!href) return;

    const absoluteUrl = new URL(href, window.location.href).href;
    const ua = navigator.userAgent || '';
    const isAndroid = /Android/i.test(ua);

    if (isAndroid) {
      ev.preventDefault();
      const intentPath = absoluteUrl.replace(/^https?:\/\//i, '');
      const intentUrl = 'intent://' + intentPath + '#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=' + encodeURIComponent(absoluteUrl) + ';end;';
      window.location.href = intentUrl;
      return;
    }

    // iOS/desktop: tenta nova aba, com fallback
    ev.preventDefault();
    const popup = window.open(absoluteUrl, '_blank', 'noopener');
    if (popup && typeof popup.focus === 'function') {
      popup.opener = null;
      popup.focus();
      return;
    }
    window.location.href = absoluteUrl;
  }, { passive: false });
})();
