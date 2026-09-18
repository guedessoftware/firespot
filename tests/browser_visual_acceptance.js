'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root=path.resolve(__dirname,'..');
const output=process.env.FIRESPOT_BROWSER_AUDIT_OUTPUT||path.join('/tmp','firespot-browser-audit');
const executablePath=process.env.FIRESPOT_CHROMIUM_EXECUTABLE||undefined;
const origin='https://firecdn.com.br';

function php(file,args){
  return execFileSync('php',[path.join(root,file),...args],{cwd:root,encoding:'utf8',maxBuffer:32*1024*1024});
}
function withoutScripts(html,base){
  const clean=html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi,'');
  return clean.replace(/<head(\s[^>]*)?>/i,match=>`${match}<base href="${base}">`);
}
function safeName(value){return value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/gi,'-').replace(/^-|-$/g,'').toLowerCase();}

const centralInventory=JSON.parse(php('tests/control_center_authenticated_render_test.php',['--inventory']));
const partnerInventory=JSON.parse(php('tests/partner_portal_authenticated_render_test.php',['--inventory']));
let applications=[
  {
    name:'central',base:`${origin}/dashboard/`,inventory:centralInventory,
    render:item=>php('tests/control_center_authenticated_render_test.php',['--render',item.route,item.query]),
    variants:[['desktop',1440,900,'light'],['desktop',1440,900,'dark'],['tablet',1024,768,'light'],['tablet',1024,768,'dark'],['mobile',390,844,'light'],['mobile',390,844,'dark']],
  },
  {
    name:'estabelecimento',base:`${origin}/portal/host/`,inventory:partnerInventory,
    render:item=>php('tests/partner_portal_authenticated_render_test.php',['--render',item.page]),
    variants:[['desktop',1440,900,'light'],['tablet',1024,768,'light'],['mobile',390,844,'light']],
  },
  {
    name:'central_avancada',base:`${origin}/dashboard/`,inventory:[
      {label:'Estabelecimento Multipontos / portal',section:'portal'},
      {label:'Estabelecimento Multipontos / points',section:'points'},
    ],
    render:item=>php('tests/control_center_authenticated_render_test.php',['--render-advanced-partner',item.section]),
    variants:[['desktop',1440,900,'light'],['desktop',1440,900,'dark'],['tablet',1024,768,'light'],['tablet',1024,768,'dark'],['mobile',390,844,'light'],['mobile',390,844,'dark']],
  },
];
if(process.env.FIRESPOT_BROWSER_AUDIT_SCOPE){
  applications=applications.filter(item=>item.name===process.env.FIRESPOT_BROWSER_AUDIT_SCOPE);
  if(!applications.length)throw new Error('FIRESPOT_BROWSER_AUDIT_SCOPE inválido.');
}
if(process.env.FIRESPOT_BROWSER_AUDIT_MATCH){
  const matcher=new RegExp(process.env.FIRESPOT_BROWSER_AUDIT_MATCH,'i');
  applications=applications.map(item=>({...item,inventory:item.inventory.filter(route=>matcher.test(route.label))})).filter(item=>item.inventory.length);
  if(!applications.length)throw new Error('FIRESPOT_BROWSER_AUDIT_MATCH não encontrou páginas.');
}
const screenshotKeys=new Set([
  'central|Visão geral|desktop|light','central|Visão geral|desktop|dark',
  'central|Estabelecimento / portal|desktop|light','central|Estabelecimento / points|mobile|light',
  'central|Planos / partner-access|tablet|dark','central|Configurações / governance|mobile|dark',
  'estabelecimento|Painel do estabelecimento / summary|desktop|light',
  'estabelecimento|Painel do estabelecimento / portal|desktop|light',
  'estabelecimento|Painel do estabelecimento / nas|mobile|light',
  'estabelecimento|Painel do estabelecimento / hotspots|mobile|light',
  'estabelecimento|Painel do estabelecimento / analytics|tablet|light',
  'central_avancada|Estabelecimento Multipontos / portal|desktop|light',
  'central_avancada|Estabelecimento Multipontos / portal|mobile|dark',
]);

(async()=>{
  fs.mkdirSync(output,{recursive:true});
  const browser=await chromium.launch({headless:true,executablePath,args:['--no-sandbox','--disable-dev-shm-usage']});
  let checks=0,combinations=0,screenshots=0;
  const failures=[];
  try{
    for(const application of applications){
      const page=await browser.newPage({ignoreHTTPSErrors:true});
      let assetFailures=[];
      page.on('response',response=>{
        const type=response.request().resourceType();
        if(['stylesheet','image','font'].includes(type)&&response.status()>=400)assetFailures.push(`${response.status()} ${response.url()}`);
      });
      await page.route('**/*',route=>route.request().resourceType()==='script'?route.abort():route.continue());
      await page.goto(application.base,{waitUntil:'domcontentloaded'}).catch(()=>{});
      for(const item of application.inventory){
        const html=withoutScripts(application.render(item),application.base);
        for(const [viewport,width,height,theme] of application.variants){
          combinations++;assetFailures=[];
          await page.setViewportSize({width,height});
          await page.emulateMedia({colorScheme:theme});
          await page.setContent(html,{waitUntil:'networkidle',timeout:30000});
          await page.evaluate(mode=>{
            document.documentElement.setAttribute('data-theme',mode);
            document.body?.setAttribute('data-theme',mode);
            if(document.body?.classList.contains('host-admin')&&innerWidth<=760){
              document.querySelector('.host-nav a.active')?.scrollIntoView({block:'nearest',inline:'center'});
            }
          },theme);
          /* Aguarda as transições legítimas do shell antes de medir as cores finais. */
          await page.waitForTimeout(260);
          const geometry=await page.evaluate(()=>{
            const html=document.documentElement,body=document.body;
            const main=document.querySelector('main');
            const h1=main?.querySelector('h1');
            return {
              rootOverflow:Math.max(html.scrollWidth-html.clientWidth,body.scrollWidth-html.clientWidth),
              main:!!main,
              heading:!!h1,
              headingVisible:!!h1&&h1.getBoundingClientRect().width>0&&h1.getBoundingClientRect().height>0,
              theme:document.documentElement.getAttribute('data-theme'),
            };
          });
          const label=item.label;
          const prefix=`${application.name} · ${label} · ${viewport}/${theme}`;
          const expect=(condition,message)=>{checks++;if(!condition)failures.push(`${prefix}: ${message}`);};
          expect(geometry.main,'conteúdo principal ausente');
          expect(geometry.heading&&geometry.headingVisible,'título principal ausente ou invisível');
          expect(geometry.rootOverflow<=1,`overflow horizontal global de ${geometry.rootOverflow}px`);
          expect(geometry.theme===theme,'tema solicitado não foi aplicado');
          expect(assetFailures.length===0,`assets indisponíveis: ${assetFailures.join(', ')}`);

          const contrastFailures=await page.evaluate(()=>{
            const parse=value=>{const match=String(value).match(/rgba?\((\d+(?:\.\d+)?)[, ]+(\d+(?:\.\d+)?)[, ]+(\d+(?:\.\d+)?)(?:[, /]+(\d+(?:\.\d+)?))?\)/);return match?[+match[1],+match[2],+match[3],match[4]===undefined?1:+match[4]]:null;};
            const luminance=rgb=>{const channels=rgb.slice(0,3).map(value=>{const normalized=value/255;return normalized<=.04045?normalized/12.92:Math.pow((normalized+.055)/1.055,2.4);});return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];};
            const ratio=(a,b)=>{const first=luminance(a),second=luminance(b);return (Math.max(first,second)+.05)/(Math.min(first,second)+.05);};
            const background=element=>{for(let current=element;current;current=current.parentElement){const style=getComputedStyle(current);if(style.backgroundImage!=='none')return null;const color=parse(style.backgroundColor);if(color&&color[3]>.98)return color;}return [255,255,255,1];};
            const failures=[];
            for(const element of document.querySelectorAll('h1,h2,h3,p,small,label,a,button,th,td,dt,dd,span,strong,code')){
              if(element.closest('[aria-hidden="true"]'))continue;
              if(!Array.from(element.childNodes).some(node=>node.nodeType===Node.TEXT_NODE&&node.textContent.trim()!==''))continue;
              const rect=element.getBoundingClientRect(),style=getComputedStyle(element);
              if(rect.width<=0||rect.height<=0||style.visibility==='hidden'||style.opacity==='0')continue;
              const foreground=parse(style.color),back=background(element);if(!foreground||!back||foreground[3]<.98)continue;
              const fontSize=parseFloat(style.fontSize)||16,fontWeight=parseInt(style.fontWeight,10)||400;
              const threshold=fontSize>=24||(fontSize>=18.66&&fontWeight>=700)?3:4.5;
              const measured=ratio(foreground,back);
              if(measured+0.01<threshold)failures.push(`${element.tagName.toLowerCase()}${element.className?'.'+String(element.className).trim().replace(/\s+/g,'.'):''} "${element.textContent.trim().replace(/\s+/g,' ').slice(0,48)}" ${measured.toFixed(2)}<${threshold} fg=${foreground.slice(0,3).join(',')} bg=${back.slice(0,3).join(',')}`);
              if(failures.length>=8)break;
            }
            return failures;
          });
          expect(contrastFailures.length===0,`contraste insuficiente: ${contrastFailures.join('; ')}`);

          if(viewport==='desktop'&&theme==='light'){
            const session=await page.context().newCDPSession(page);
            const tree=await session.send('Accessibility.getFullAXTree');await session.detach();
            const namedRoles=new Set(['button','link','textbox','combobox','checkbox','radio']);
            const unnamed=tree.nodes.filter(node=>!node.ignored&&namedRoles.has(node.role?.value)&&!String(node.name?.value||'').trim());
            const unnamedSummary=unnamed.map(node=>`${node.role?.value||'controle'}#${node.backendDOMNodeId||'?'}`).join(', ');
            expect(unnamed.length===0,`${unnamed.length} controle(s) sem nome na árvore de acessibilidade${unnamedSummary?`: ${unnamedSummary}`:''}`);
          }

          if(application.name==='estabelecimento'&&item.page==='portal'&&viewport==='desktop'&&theme==='light'){
            await page.addScriptTag({path:path.join(root,'portal/host/assets/host-admin.js')});
            await page.click('[data-host-modal-open="host-modal-simulation"]');
            const simulationModal=await page.evaluate(()=>{
              const modal=document.getElementById('host-modal-simulation'),frame=modal?.querySelector('[data-host-modal-frame]');
              const rect=modal?.querySelector('[role="dialog"]')?.getBoundingClientRect();
              return {open:modal?.hidden===false,src:frame?.getAttribute('src')||'',visible:!!rect&&rect.width>500&&rect.height>500};
            });
            expect(simulationModal.open&&simulationModal.visible,'janela da simulação não abriu sobre a página');
            expect(simulationModal.src.includes('/simulador/?source=partner&embed=1'),'simulação modal não usa o modo incorporado seguro');
            await page.click('#host-modal-simulation .host-modal__close');
            const simulationClosed=await page.evaluate(()=>{const modal=document.getElementById('host-modal-simulation'),frame=modal?.querySelector('[data-host-modal-frame]');return modal?.hidden===true&&!frame?.hasAttribute('src');});
            expect(simulationClosed,'fechar a simulação não descarregou sua janela');

            await page.click('[data-host-modal-open="host-modal-preview"]');
            const previewModal=await page.evaluate(()=>{const modal=document.getElementById('host-modal-preview'),frame=modal?.querySelector('[data-host-modal-frame]');return modal?.hidden===false&&String(frame?.getAttribute('src')||'').startsWith('/admin/portal_preview.php?');});
            expect(previewModal,'prévia do rascunho não abriu na janela sobreposta');
            await page.click('#host-modal-preview .host-modal__close');
          }

          if(application.name==='estabelecimento'&&item.page==='hotspots'&&viewport==='desktop'&&theme==='light'){
            await page.addScriptTag({path:path.join(root,'portal/host/assets/host-admin.js')});
            const pointTrigger=await page.$('[data-host-modal-open^="host-modal-point-"]');
            expect(!!pointTrigger,'nenhum ponto disponível para validar a janela de administração');
            if(pointTrigger){
              await pointTrigger.click();
              const pointModal=await page.evaluate(()=>{
                const modal=Array.from(document.querySelectorAll('.host-modal')).find(candidate=>candidate.hidden===false);
                const dialog=modal?.querySelector('[role="dialog"]'),policies=modal?.querySelector('.host-point-policies');
                return {open:!!modal&&modal.hidden===false,policies:!!policies&&policies.getBoundingClientRect().height>0,overflow:dialog?dialog.scrollWidth-dialog.clientWidth:999};
              });
              expect(pointModal.open&&pointModal.policies,'janela do ponto não exibe as regras comerciais efetivas');
              expect(pointModal.overflow<=1,`janela do ponto possui overflow horizontal de ${pointModal.overflow}px`);
              await page.click('.host-modal:not([hidden]) .host-modal__close');
            }
          }

          if(item===application.inventory[0]&&viewport==='desktop'&&theme==='light'){
            await page.emulateMedia({colorScheme:theme,reducedMotion:'reduce'});
            const moving=await page.evaluate(()=>Array.from(document.querySelectorAll('a,button')).filter(element=>{
              const style=getComputedStyle(element),duration=`${style.transitionDuration},${style.animationDuration}`;
              const milliseconds=value=>{const parsed=parseFloat(value)||0;return String(value).trim().endsWith('ms')?parsed:parsed*1000;};
              return duration.split(',').some(value=>milliseconds(value)>.01);
            }).length);
            expect(moving===0,`${moving} controle(s) mantêm animação com redução de movimento`);
            await page.emulateMedia({colorScheme:theme,reducedMotion:'no-preference'});
          }

          const key=`${application.name}|${label}|${viewport}|${theme}`;
          if(screenshotKeys.has(key)){
            const file=path.join(output,`${safeName(key)}.png`);
            await page.screenshot({path:file,fullPage:false});screenshots++;
          }

          await page.evaluate(()=>{document.activeElement?.blur?.();window.scrollTo(0,0);});
          let keyboardVisible=false,focusIndicator=false;
          for(let tab=0;tab<8;tab++){
            await page.keyboard.press('Tab');
            const focus=await page.evaluate(()=>{
              const el=document.activeElement;if(!el||el===document.body)return {visible:false,indicator:false};
              const r=el.getBoundingClientRect(),s=getComputedStyle(el);
              return {visible:r.width>0&&r.height>0&&r.right>0&&r.left<innerWidth&&r.bottom>0&&r.top<innerHeight,
                indicator:(parseFloat(s.outlineWidth)||0)>0||s.boxShadow!=='none'||s.textDecorationLine.includes('underline')};
            });
            keyboardVisible=keyboardVisible||focus.visible;focusIndicator=focusIndicator||(focus.visible&&focus.indicator);
          }
          expect(keyboardVisible,'navegação por teclado não alcançou controle visível');
          expect(focusIndicator,'foco de teclado visível não foi identificado');
        }
      }
      await page.close();
    }
  }finally{await browser.close();}
  if(failures.length){
    process.stderr.write(`FALHA: ${failures.length} ocorrências em ${combinations} combinações.\n${failures.join('\n')}\n`);
    process.exit(1);
  }
  process.stdout.write(`OK: ${applications.reduce((sum,item)=>sum+item.inventory.length,0)} páginas, ${combinations} combinações responsivas, ${checks} verificações de navegador e ${screenshots} capturas de evidência.\n`);
})().catch(error=>{process.stderr.write(`${error.stack||error}\n`);process.exit(1);});
