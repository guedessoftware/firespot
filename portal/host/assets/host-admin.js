(()=>{
  document.querySelectorAll('[data-select-on-click]').forEach(input=>input.addEventListener('click',()=>input.select()));
  const activeNavigation=document.querySelector('.host-nav a.active');
  if(activeNavigation&&window.matchMedia('(max-width: 760px)').matches){
    requestAnimationFrame(()=>activeNavigation.scrollIntoView({block:'nearest',inline:'center'}));
  }

  let activeModal=null;
  let modalOpener=null;
  const focusableSelector='button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  function syncNasInterfaces(container){
    const forms=container.matches?.('form')?[container]:Array.from(container.querySelectorAll('form'));
    forms.forEach(currentForm=>{
      const nasSelect=currentForm.querySelector('[data-host-nas-select]');
      const interfaceSelect=currentForm.querySelector('[data-host-interface-select]');
      if(!nasSelect||!interfaceSelect)return;
      const selectedNas=nasSelect.value;
      let firstVisible=null;
      Array.from(interfaceSelect.options).forEach(option=>{
        const visible=option.dataset.nasId===selectedNas;
        option.hidden=!visible;
        option.disabled=!visible;
        if(visible&&firstVisible===null)firstVisible=option;
      });
      const selected=interfaceSelect.selectedOptions[0];
      if((!selected||selected.disabled)&&firstVisible)firstVisible.selected=true;
    });
  }
  function closeHostModal(){
    if(!activeModal)return;
    const frame=activeModal.querySelector('[data-host-modal-frame]');
    if(frame)frame.removeAttribute('src');
    activeModal.hidden=true;
    document.body.classList.remove('host-modal-open');
    const restore=modalOpener;
    activeModal=null;
    modalOpener=null;
    restore?.focus();
  }
  function openHostModal(trigger){
    const modal=document.getElementById(trigger.dataset.hostModalOpen||'');
    if(!modal||!modal.matches('[data-host-modal]'))return;
    if(activeModal)closeHostModal();
    activeModal=modal;
    modalOpener=trigger;
    const frame=modal.querySelector('[data-host-modal-frame]');
    const frameSource=trigger.dataset.hostFrameSrc||'';
    if(frame&&frameSource)frame.setAttribute('src',frameSource);
    syncNasInterfaces(modal);
    modal.hidden=false;
    document.body.classList.add('host-modal-open');
    requestAnimationFrame(()=>{
      const first=modal.querySelector('input:not([type="hidden"]):not([disabled]),select:not([disabled]),button:not([disabled])');
      (first||modal.querySelector('[role="dialog"]'))?.focus();
    });
  }
  document.querySelectorAll('[data-host-modal-open]').forEach(trigger=>trigger.addEventListener('click',()=>openHostModal(trigger)));
  document.querySelectorAll('[data-host-modal-close]').forEach(trigger=>trigger.addEventListener('click',closeHostModal));
  document.querySelectorAll('[data-host-nas-select]').forEach(select=>{
    const updateAllocation=()=>{
      const preview=select.form?.querySelector('[data-host-allocation-preview]');
      if(preview)preview.textContent=`Próxima alocação: ${select.selectedOptions[0]?.dataset.nextAllocation||'indisponível'}`;
    };
    select.addEventListener('change',()=>{syncNasInterfaces(select.form||document);updateAllocation();});
    syncNasInterfaces(select.form||document);
    updateAllocation();
  });
  const ipFromLong=value=>[24,16,8,0].map(shift=>Math.floor(value/(2**shift))%256).join('.');
  document.querySelectorAll('[data-host-policy-form]').forEach(policyForm=>{
    const updatePolicyPreview=()=>{
      const preview=policyForm.querySelector('[data-host-policy-preview]');if(!preview)return;
      const vlan=Number(policyForm.elements.namedItem('vlan_start')?.value||0);
      const prefix=Number(policyForm.elements.namedItem('prefix_length')?.value||0);
      const gatewayOffset=Number(policyForm.elements.namedItem('gateway_offset')?.value||0);
      const poolStartOffset=Number(policyForm.elements.namedItem('pool_start_offset')?.value||0);
      const reserve=Number(policyForm.elements.namedItem('pool_end_reserve')?.value||0);
      if(vlan<1||vlan>255||prefix<16||prefix>30){preview.textContent='Prévia indisponível: revise VLAN e máscara.';return;}
      const base=10*(2**24)+vlan*(2**16);const size=2**(32-prefix);
      preview.textContent=`Exemplo com VLAN ${vlan}: 10.${vlan}.0.0/${prefix} · gateway ${ipFromLong(base+gatewayOffset)} · pool ${ipFromLong(base+poolStartOffset)}–${ipFromLong(base+size-1-reserve)}`;
    };
    policyForm.addEventListener('input',updatePolicyPreview);policyForm.addEventListener('change',updatePolicyPreview);updatePolicyPreview();
  });
  document.addEventListener('keydown',event=>{
    if(!activeModal)return;
    if(event.key==='Escape'){event.preventDefault();closeHostModal();return;}
    if(event.key!=='Tab')return;
    const focusable=Array.from(activeModal.querySelectorAll(focusableSelector)).filter(element=>element.offsetParent!==null);
    if(!focusable.length)return;
    const first=focusable[0],last=focusable[focusable.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });

  document.querySelectorAll('[data-analytics-filters]').forEach(filters=>{
    const period=filters.querySelector('[data-analytics-period]');
    const dates=Array.from(filters.querySelectorAll('[data-analytics-custom-date]'));
    const syncCustomDates=()=>{
      const custom=period?.value==='custom';
      dates.forEach(input=>{input.disabled=!custom;input.required=custom;});
    };
    period?.addEventListener('change',syncCustomDates);
    syncCustomDates();
  });

  const form=document.getElementById('host-theme-form');
  const preview=form?.querySelector('.js-host-theme-preview');
  if(!form||!preview)return;
  const preset=form.querySelector('.js-host-theme-preset');
  const mode=form.querySelector('.js-host-theme-mode');
  const showTitle=form.elements.namedItem('show_title');
  const previewTitle=form.querySelector('.js-host-preview-title');
  const previewImage=form.querySelector('.js-host-preview-image');
  const previewLetter=form.querySelector('.js-host-preview-letter');
  const previewBrandMark=form.querySelector('.fs-pv-brand-mark');
  const previewHero=form.querySelector('.fs-pv-hero');
  const colorNames=['primary_color','secondary_color','background_color','text_color','muted_text_color','hero_text_color','button_text_color','footer_text_color'];
  const palettes={
    modern:{primary_color:'#ff9f1c',secondary_color:'#ff6b00',background_color:'#071225',text_color:'#10213b',muted_text_color:'#61708a',hero_text_color:'#ffffff',button_text_color:'#10213b',footer_text_color:'#ffffff'},
    compact_blue:{primary_color:'#13aaf5',secondary_color:'#087bcf',background_color:'#050817',text_color:'#f4f7fb',muted_text_color:'#bdc9d9',hero_text_color:'#ffffff',button_text_color:'#ffffff',footer_text_color:'#ffffff'},
    compact_light:{primary_color:'#159fea',secondary_color:'#0877c9',background_color:'#eaf5ff',text_color:'#10213b',muted_text_color:'#61708a',hero_text_color:'#10213b',button_text_color:'#ffffff',footer_text_color:'#10213b'}
  };
  const originalLogos={light:previewImage?.dataset.lightUrl||'',dark:previewImage?.dataset.darkUrl||''};
  const temporaryLogos={light:'',dark:''};
  const logoUrls={...originalLogos};
  const field=name=>form.elements.namedItem(name);

  function effectiveMode(){
    if(preset?.value==='compact_blue')return 'dark';
    if(preset?.value==='compact_light')return 'light';
    return mode?.value==='dark'?'dark':'light';
  }
  function setPalette(){Object.entries(palettes[preset?.value]||palettes.modern).forEach(([name,value])=>{const input=field(name);if(input)input.value=value;});}
  function hexRgb(hex){const clean=String(hex||'#000000').replace('#','');return [parseInt(clean.slice(0,2),16),parseInt(clean.slice(2,4),16),parseInt(clean.slice(4,6),16)];}
  function mixHex(first,second,secondWeight){const a=hexRgb(first),b=hexRgb(second),weight=Math.max(0,Math.min(1,secondWeight));return `#${a.map((channel,index)=>Math.round(channel*(1-weight)+b[index]*weight).toString(16).padStart(2,'0')).join('')}`;}
  function luminance(hex){return hexRgb(hex).map(channel=>{const value=channel/255;return value<=.03928?value/12.92:Math.pow((value+.055)/1.055,2.4);}).reduce((total,value,index)=>total+value*[.2126,.7152,.0722][index],0);}
  function contrast(first,second){const values=[luminance(first),luminance(second)].sort((a,b)=>b-a);return (values[0]+.05)/(values[1]+.05);}
  function accessibleColor(color,background){if(contrast(color,background)>=4.5)return color;const target=contrast('#10213b',background)>=contrast('#ffffff',background)?'#10213b':'#ffffff';for(let step=1;step<=20;step++){const candidate=mixHex(color,target,step/20);if(contrast(candidate,background)>=4.5)return candidate;}return target;}
  function updateLogoCards(){
    ['light','dark'].forEach(variant=>{
      const card=form.querySelector(`[data-logo-card="${variant}"]`);
      const box=card?.querySelector('.host-theme-logo__preview');
      if(!box)return;
      const url=logoUrls[variant]||'';
      box.replaceChildren();
      if(url){const image=document.createElement('img');image.src=url;image.alt=variant==='light'?'Logo para tema claro':'Logo para tema escuro';box.appendChild(image);}
      else{const empty=document.createElement('span');empty.textContent='Sem logo personalizado';box.appendChild(empty);}
    });
  }
  function updatePreviewLogo(){
    if(!previewImage)return;
    const selected=effectiveMode(),fallback=selected==='dark'?'light':'dark',url=logoUrls[selected]||logoUrls[fallback]||'';
    previewImage.src=url;previewImage.hidden=url==='';
    if(previewLetter)previewLetter.hidden=url!=='';
    previewBrandMark?.classList.toggle('has-logo',url!=='');
    previewHero?.classList.toggle('has-logo',url!=='');
  }
  function updatePreview(){
    if(preset?.value==='compact_blue'&&mode)mode.value='dark';
    if(preset?.value==='compact_light'&&mode)mode.value='light';
    preview.dataset.preset=preset?.value||'modern';
    preview.dataset.mode=effectiveMode();
    preview.dataset.layout=String(preset?.value||'').startsWith('compact_')?'compact':'modern';
    const brand=field('primary_color')?.value||'#ff9f1c';
    const brand2=field('secondary_color')?.value||'#ff6b00';
    const background=field('background_color')?.value||'#071225';
    const dark=effectiveMode()==='dark';
    const fireSpotPalette=brand.toLowerCase()==='#ff9f1c'&&brand2.toLowerCase()==='#ff6b00'&&background.toLowerCase()==='#071225';
    const panel=dark?'#0f2038':'#ffffff';
    const variables={
      '--pv-bg':background,'--pv-bg2':fireSpotPalette?'#0c2445':mixHex(background,brand2,.13),'--pv-panel':panel,
      '--pv-ink':field('text_color')?.value||(dark?'#f4f7fb':'#10213b'),'--pv-muted':field('muted_text_color')?.value||(dark?'#bdc9d9':'#61708a'),
      '--pv-brand':brand,'--pv-brand2':brand2,'--pv-accent-ink':accessibleColor(brand2,panel),'--pv-brand-rgb':hexRgb(brand).join(','),'--pv-line':dark?'#29415f':'#dce3ed','--pv-soft':dark?'#172b46':'#f3f7fb',
      '--pv-hero1':fireSpotPalette?'#0d2342':mixHex(background,'#000000',.08),'--pv-hero2':fireSpotPalette?'#13375f':mixHex(background,brand,.16),
      '--pv-hero-ink':field('hero_text_color')?.value||'#ffffff','--pv-on-brand':field('button_text_color')?.value||'#ffffff','--pv-footer-ink':field('footer_text_color')?.value||'#ffffff',
      '--pv-popular-bg':dark?'#432900':'#fff1dc','--pv-popular-ink':dark?'#ffd18d':'#9d4d00'
    };
    Object.entries(variables).forEach(([name,value])=>preview.style.setProperty(name,value));
    if(previewTitle&&showTitle)previewTitle.hidden=!showTitle.checked;
    colorNames.forEach(name=>{const input=field(name),value=input?.closest('.host-theme-color')?.querySelector('.js-host-color-value');if(input&&value)value.textContent=input.value;});
    updatePreviewLogo();
  }

  preset?.addEventListener('change',()=>{setPalette();updatePreview();});
  mode?.addEventListener('change',updatePreview);
  showTitle?.addEventListener('change',updatePreview);
  colorNames.forEach(name=>field(name)?.addEventListener('input',updatePreview));
  form.querySelector('.js-host-reset-theme')?.addEventListener('click',()=>{setPalette();updatePreview();});
  ['light','dark'].forEach(variant=>{
    const input=field(`logo_${variant}`),remove=field(`remove_logo_${variant}`);
    input?.addEventListener('change',()=>{
      const file=input.files?.[0]||null;
      if(temporaryLogos[variant])URL.revokeObjectURL(temporaryLogos[variant]);
      temporaryLogos[variant]=file?URL.createObjectURL(file):'';
      logoUrls[variant]=temporaryLogos[variant]||originalLogos[variant];
      if(file&&remove)remove.checked=false;
      updateLogoCards();updatePreviewLogo();
    });
    remove?.addEventListener('change',()=>{
      if(remove.checked){input.value='';if(temporaryLogos[variant])URL.revokeObjectURL(temporaryLogos[variant]);temporaryLogos[variant]='';logoUrls[variant]='';}
      else logoUrls[variant]=originalLogos[variant];
      updateLogoCards();updatePreviewLogo();
    });
  });
  updateLogoCards();updatePreview();
})();
