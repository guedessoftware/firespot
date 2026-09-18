// assets/js/common.js
(() => {
  if (window.FIRESPOT) return; // singleton
  const N = {};

  // Config
  N.config = {
    API_URL: 'api/dashboard_data.php',
    POLL_MS: 8000
  };

  // Utils
  N.utils = {
    hashString(str){ let h=5381,i=str.length; while(i) h=(h*33)^str.charCodeAt(--i); return (h>>>0).toString(36); },
    escapeHtml(s){
      return String(s ?? '').replace(/[&<>"'`=\/]/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#x27;','`':'&#x60;','=':'&#x3D;','/':'&#x2F;'
      }[c]));
    },
    // >>> ROBUSTO a string/NaN/negativos
    formatBytes(v){
      const b = Number(v);
      if (!isFinite(b) || b <= 0) return '0 B';
      const units = ['B','KB','MB','GB','TB','PB'];
      const i = Math.min(units.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
      const val = b / Math.pow(1024, i);
      const digits = (i === 0) ? 0 : 1;
      return `${val.toFixed(digits)} ${units[i]}`;
    },
    formatHour(iso){ const m=String(iso||'').match(/\s(\d{2}):\d{2}:\d{2}/); return m?`${m[1]}h`:String(iso||''); },
    formatDate(iso){ if(!iso) return ''; const [y,m,d]=String(iso).split('-'); return `${d}/${m}`; },
    lockCanvasSize(canvas, w=900, h=280){
      if (!canvas.getAttribute('width'))  canvas.setAttribute('width', w);
      if (!canvas.getAttribute('height')) canvas.setAttribute('height', h);
      canvas.style.width  = canvas.getAttribute('width')  + 'px';
      canvas.style.height = canvas.getAttribute('height') + 'px';
      canvas.style.display = 'block';
    },
    stampRefresh(){
      const el = document.getElementById('last-refresh');
      if (el) el.textContent = `Atualizado às ${new Date().toLocaleTimeString()}`;
    }
  };

  window.FIRESPOT = N;
})();

// DEBUG: habilita por query (?fsdebug=1) ou localStorage('firespot-debug'='1')
(() => {
  const N = window.FIRESPOT;
  if (!N) return;

  function q(name){ return new URLSearchParams(location.search).get(name); }
  const fromQuery = q('fsdebug');
  if (fromQuery) localStorage.setItem('firespot-debug', fromQuery);
  const on = (localStorage.getItem('firespot-debug') === '1');

  N.config = N.config || {};
  N.config.DEBUG = !!on;

  if (on) {
    // badge discreto no canto
    const b = document.createElement('div');
    b.textContent = 'DEBUG ON';
    Object.assign(b.style, {
      position:'fixed', bottom:'8px', right:'8px', zIndex:9999,
      padding:'4px 8px', fontSize:'12px',
      background:'rgba(0,0,0,.6)', color:'#fff', borderRadius:'8px'
    });
    document.addEventListener('DOMContentLoaded', ()=> document.body.appendChild(b));
  }
})();
document.addEventListener('submit',(event)=>{
  const form=event.target.closest('form[data-confirm]');
  if(!form)return;
  const message=form.dataset.confirm||'Confirma esta operação?';
  if(!window.confirm(message))event.preventDefault();
});

document.addEventListener('click',(event)=>{
  const field=event.target.closest('[data-select-on-click]');
  if(field&&typeof field.select==='function')field.select();
});
