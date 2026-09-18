(() => {
  'use strict';

  const dataNode = document.getElementById('simulator-data');
  const screen = document.getElementById('sim-screen');
  const portal = document.getElementById('sim-portal');
  const currentStep = document.getElementById('sim-current-step');
  if (!dataNode || !screen || !portal) return;

  let config;
  try { config = JSON.parse(dataNode.dataset.config || '{}'); } catch (_) { return; }

  const partner = config.partner || {};
  const capabilities = config.capabilities || {};
  const navigation = config.navigation || {};
  const policy = config.policy || {};
  const theme = config.theme || {};
  const plans = Array.isArray(config.plans) ? config.plans : [];
  const ads = Array.isArray(config.ads) ? config.ads : [];
  const progressKeys = ['entry','offer','validation','release','connected'];
  let selectedPlan = null;
  let selectedJourney = '';
  let authenticated = false;
  let pendingOffer = null;
  let activeTimer = null;
  const visibleModes = [
    capabilities.courtesy ? 'courtesy' : '',
    capabilities.sales ? 'paid' : '',
    capabilities.subscriber ? 'subscriber' : '',
  ].filter(Boolean);
  const welcomeEnabled = navigation.welcome_screen_enabled !== false;
  const directMode = navigation.single_option_direct_enabled === true && visibleModes.length === 1 ? visibleModes[0] : '';

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const safeImage = value => {
    const url = String(value || '').trim();
    if (/^\/(?!\/)[A-Za-z0-9_./%-]+$/.test(url)) return url;
    try { const parsed = new URL(url); return ['http:','https:'].includes(parsed.protocol) ? parsed.href : ''; } catch (_) { return ''; }
  };
  const money = cents => `R$ ${(Number(cents || 0) / 100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
  const duration = minutes => {
    const value = Number(minutes || 0);
    if (value > 0 && value % 1440 === 0) { const days = value / 1440; return `${days} ${days === 1 ? 'dia' : 'dias'}`; }
    if (value > 0 && value % 60 === 0) { const hours = value / 60; return `${hours} ${hours === 1 ? 'hora' : 'horas'}`; }
    return `${value} minutos`;
  };
  const speed = kbps => {
    const value = Number(kbps || 0);
    if (value <= 0) return 'sem limite específico';
    if (value >= 1000) return `${(value / 1000).toLocaleString('pt-BR',{maximumFractionDigits:1})} Mbps`;
    return `${value} Kbps`;
  };
  const brand = () => {
    const logo = safeImage(theme.logo_url);
    return `<span class="portal-signal" aria-hidden="true"><i></i><i></i><i></i><i></i></span><div class="portal-brand-mark${logo ? ' has-logo' : ''}">${logo ? `<img src="${escapeHtml(logo)}" alt="${escapeHtml(theme.brand_name || partner.name)}">` : escapeHtml(theme.logo_letter || 'F')}</div>`;
  };
  const brandTitle = () => theme.show_title ? `<p class="portal-eyebrow">${escapeHtml(theme.brand_name || partner.name)}</p>` : '';

  function clearActiveTimer() {
    if (activeTimer !== null) window.clearInterval(activeTimer);
    activeTimer = null;
  }

  function updateProgress(key) {
    const index = Math.max(0,progressKeys.indexOf(key));
    document.querySelectorAll('.sim-progress li').forEach((item,itemIndex) => {
      item.classList.toggle('is-complete',itemIndex < index);
      item.classList.toggle('is-active',itemIndex === index);
    });
  }

  function show(html,label,progress) {
    clearActiveTimer();
    screen.innerHTML = html;
    portal.scrollTop = 0;
    if (currentStep) currentStep.textContent = label;
    updateProgress(progress);
  }

  function page(content,className = '') {
    return `<div class="portal-page ${className}"><div class="portal-shell">${content}</div></div>`;
  }

  function backButton(action = 'options',label = 'Voltar às modalidades') {
    return `<button class="portal-back" type="button" data-portal-action="${escapeHtml(action)}">← ${escapeHtml(label)}</button>`;
  }

  function journeyBackButton() {
    if (!directMode) return backButton();
    return welcomeEnabled ? backButton('landing','Voltar às boas-vindas') : '';
  }

  function renderCaptive() {
    selectedPlan = null;
    selectedJourney = '';
    authenticated = false;
    pendingOffer = null;
    show(`<div class="portal-captive"><section class="portal-captive-card"><div class="portal-wifi-icon">⌁</div><p class="portal-eyebrow">Login da rede Wi-Fi</p><h1>Entrar na rede</h1><p>Esta rede precisa abrir o portal para liberar seu acesso à internet.</p><div class="portal-network"><span><strong>${escapeHtml(partner.name)}</strong><small>Rede Wi-Fi conectada</small></span><b>Sem internet</b></div><button class="portal-button" type="button" data-portal-action="landing">Abrir portal de acesso</button></section></div>`,'Login do Wi-Fi','entry');
  }

  function courtesyOffer() {
    if (!capabilities.courtesy) return '';
    const sponsored = partner.purpose === 'sponsored' || capabilities.requires_ad;
    return `<article class="portal-panel portal-access-choice"><div class="portal-access-icon">${sponsored ? '▶' : '✓'}</div><div><h2>${sponsored ? 'Internet patrocinada' : 'Usar cortesia'}</h2><p class="portal-access-meta">${escapeHtml(duration(policy.grant_minutes))} grátis${sponsored ? ' após o anúncio' : ''}</p></div><button class="portal-button" type="button" data-portal-action="courtesy">${sponsored ? 'Assistir e conectar' : 'Usar cortesia'}</button></article>`;
  }

  function subscriberOffer() {
    if (!capabilities.subscriber) return '';
    return `<article class="portal-panel portal-access-choice"><div class="portal-access-icon">F</div><div><h2>Benefício FIRENETWORK</h2><p class="portal-access-meta">Conta ou convite</p></div><button class="portal-button" type="button" data-portal-action="subscriber">Acessar benefício</button></article>`;
  }

  function paidOffer() {
    if (!capabilities.sales) return '';
    return `<article class="portal-panel portal-access-choice"><div class="portal-access-icon">★</div><div><h2>Comprar acesso</h2><p class="portal-access-meta">Pix ou cartão</p></div><button class="portal-button" type="button" data-portal-action="plans">Ver planos</button></article>`;
  }

  function plansMarkup() {
    if (!capabilities.sales) return '';
    const cards = plans.length ? plans.map((plan,index) => `<article class="portal-plan${index === 0 ? ' featured' : ''}">${index === 0 ? '<span class="portal-popular">Mais escolhido</span>' : ''}<h3>${escapeHtml(plan.name)}</h3><div class="portal-price">${escapeHtml(money(plan.price_cents))}</div><div class="portal-duration">${escapeHtml(duration(plan.duration_minutes))} de acesso</div><ul><li>Download: ${escapeHtml(speed(plan.download_kbps))}</li><li>Upload: ${escapeHtml(speed(plan.upload_kbps))}</li><li>Liberação automática</li></ul><button class="portal-button" type="button" data-plan-id="${Number(plan.id)}" data-plan-source="${escapeHtml(plan.source)}"><span class="portal-modern-label">Escolher este acesso</span><span class="portal-compact-label">Wi-Fi ${escapeHtml(duration(plan.duration_minutes))} — ${escapeHtml(money(plan.price_cents))}</span><span class="portal-plan-pill-label"><span>${escapeHtml(plan.name)}</span><strong>${escapeHtml(money(plan.price_cents))}</strong></span></button></article>`).join('') : '<div class="portal-status is-error">Nenhum plano ativo está disponível nesta configuração.</div>';
    return `<section class="portal-panel">${journeyBackButton()}${brand()}<p class="portal-eyebrow">Acesso premium</p><h1 class="portal-title">Escolha seu plano</h1><p class="portal-muted">A liberação acontece após a confirmação do pagamento.</p><div class="portal-plan-grid">${cards}</div></section>`;
  }

  function renderConfiguredEntry() {
    if (welcomeEnabled) return renderLanding();
    return renderAfterWelcome();
  }

  function renderAfterWelcome() {
    if (directMode === 'paid') return renderPlans();
    if (directMode === 'courtesy') return beginCourtesy();
    if (directMode === 'subscriber') return beginSubscriber();
    return renderOptions();
  }

  function renderLanding(preferredRoute = '') {
    if (preferredRoute) return renderOptions(preferredRoute);
    pendingOffer = null;
    selectedJourney = '';
    show(page(`<section class="portal-panel portal-entry-card">${brand()}${brandTitle()}<p class="portal-eyebrow">Seja bem-vindo</p><h1 class="portal-title">Conecte-se ao Wi-Fi</h1><p class="portal-muted">Escolha como deseja acessar.</p><button class="portal-button" type="button" data-portal-action="entry-next">Continuar</button></section><p class="portal-footer">${escapeHtml(partner.name)} · Conexão protegida</p>`,'portal-page--welcome'),'Boas-vindas','entry');
  }

  function renderOptions(preferredRoute = '') {
    pendingOffer = null;
    selectedJourney = preferredRoute;
    const offers = `${courtesyOffer()}${paidOffer()}${subscriberOffer()}` || '<div class="portal-status is-error">Nenhuma modalidade está ativa nesta configuração.</div>';
    const back = welcomeEnabled ? '<button class="portal-back" type="button" data-portal-action="landing">← Voltar</button>' : '';
    show(page(`<section class="portal-panel portal-options-heading">${brand()}<h1 class="portal-title">Escolha seu acesso</h1></section><section class="portal-access-grid">${offers}</section>${back}`,'portal-page--options'),'Escolha do acesso','offer');
  }

  function renderPlans() {
    selectedJourney = 'paid';
    if (!capabilities.sales) return renderBlocked('Compra indisponível','A configuração funcional desta unidade não oferece acesso pago.');
    show(page(`${plansMarkup()}<p class="portal-footer">${escapeHtml(partner.name)} · Pagamento seguro</p>`,'portal-page--plans'),'Planos premium','offer');
  }

  function beginSubscriber() {
    selectedJourney = 'subscriber';
    if (!capabilities.subscriber) return renderBlocked('Benefício indisponível','A configuração funcional desta unidade não oferece o benefício FIRENETWORK.');
    show(page(`<section class="portal-panel">${journeyBackButton()}${brand()}<p class="portal-eyebrow">Benefício FIRENETWORK</p><h1 class="portal-title">Autorize este aparelho</h1><p class="portal-muted">Cada aparelho recebe uma autorização própria e nunca compartilha a senha do titular.</p><div class="portal-methods"><button class="portal-method" type="button" data-portal-action="subscriber-owner"><span>◎</span>Entrar como titular</button><button class="portal-method" type="button" data-portal-action="subscriber-invite"><span>◇</span>Usar convite único</button></div><div class="portal-notice">A simulação não consulta o HubSoft nem cria aparelho.</div></section>`),'Autorização FIRENETWORK','validation');
  }

  function renderSubscriberOwner() {
    show(page(`<section class="portal-panel">${backButton('subscriber')}${brand()}<p class="portal-eyebrow">Minha Conta</p><h1 class="portal-title">Como deseja entrar?</h1><p class="portal-muted">Os dois métodos confirmam o mesmo titular e resolvem o mesmo benefício.</p><div class="portal-methods"><button class="portal-method" type="button" data-portal-action="subscriber-owner-otp"><span>✦</span>Código por SMS</button><button class="portal-method" type="button" data-portal-action="subscriber-owner-password"><span>●</span>Senha do aplicativo</button></div><div class="portal-notice">A simulação não envia código nem consulta credenciais reais.</div></section>`),'Entrada do titular','validation');
  }

  function renderSubscriberOwnerOtp() {
    show(page(`<section class="portal-panel">${backButton('subscriber-owner')}${brand()}<p class="portal-eyebrow">Código por SMS</p><h1 class="portal-title">Confirme o titular</h1><form class="portal-form" data-sim-form="subscriber-owner-otp"><label>CPF ou CNPJ<input value="123.456.789-09" inputmode="numeric"></label><button class="portal-button" type="submit">Receber código simulado</button></form><div class="portal-notice">No ambiente real, o código vai somente ao telefone confirmado no HubSoft.</div></section>`),'Identificação por código','validation');
  }

  function renderSubscriberOwnerPassword() {
    show(page(`<section class="portal-panel">${backButton('subscriber-owner')}${brand()}<p class="portal-eyebrow">Senha do aplicativo</p><h1 class="portal-title">Entre como titular</h1><form class="portal-form" data-sim-form="subscriber-owner-password"><label>CPF ou CNPJ<input value="123.456.789-09" inputmode="numeric" autocomplete="username"></label><label>Senha FIRENETWORK<input type="password" value="simulacao" autocomplete="current-password"></label><button class="portal-button" type="submit">Validar senha simulada</button></form><div class="portal-notice">No ambiente real, a senha é validada diretamente no HubSoft e não é salva no FireSpot.</div></section>`),'Identificação por senha','validation');
  }

  function renderSubscriberOtp() {
    show(page(`<section class="portal-panel">${backButton('subscriber-owner')}${brand()}<p class="portal-eyebrow">Código de segurança</p><h1 class="portal-title">Verifique o contato confirmado</h1><form class="portal-form" data-sim-form="subscriber-otp"><label>Código de 6 dígitos<input value="123456" inputmode="numeric" maxlength="6"></label><button class="portal-button" type="submit">Confirmar e autorizar aparelho</button></form></section>`),'Verificação do titular','validation');
  }

  function renderSubscriberInvite() {
    show(page(`<section class="portal-panel">${backButton('subscriber')}${brand()}<p class="portal-eyebrow">Convite único</p><h1 class="portal-title">Use o código do titular</h1><form class="portal-form" data-sim-form="subscriber-invite"><label>Código do convite<input value="FIRE2345" maxlength="48"></label><button class="portal-button" type="submit">Consumir convite e autorizar</button></form><div class="portal-notice">O mesmo convite não poderá ser usado por outro aparelho.</div></section>`),'Resgate do convite','validation');
  }

  function renderSubscriberReady(kind) {
    selectedJourney = 'subscriber';
    show(page(`<section class="portal-panel portal-success">${brand()}<div class="portal-success-icon">✓</div><p class="portal-eyebrow">Aparelho autorizado</p><h1>Benefício FIRENETWORK pronto</h1><p class="portal-muted">${kind === 'primary' ? 'Este navegador foi definido como aparelho principal.' : 'O convite foi consumido e este aparelho ficou autorizado.'}</p><div class="portal-wifi"><div><span>Perfil simulado</span><strong>FIRENETWORK Família</strong></div><div><span>Credencial</span><strong>Individual</strong></div><div><span>Tempo</span><strong>Ilimitado</strong></div></div><button class="portal-button" type="button" data-portal-action="connect">Conectar com benefício</button></section>`),'Benefício liberado','release');
  }

  function renderBlocked(title,message) {
    show(page(`<section class="portal-panel">${backButton()}${brand()}<p class="portal-eyebrow">Configuração atual</p><h1 class="portal-title">${escapeHtml(title)}</h1><div class="portal-status is-error">${escapeHtml(message)}</div><button class="portal-button secondary" type="button" data-portal-action="landing">Voltar às opções</button></section>`),'Fluxo indisponível','validation');
  }

  function beginCourtesy() {
    selectedJourney = 'courtesy';
    if (!capabilities.courtesy_enabled) return renderBlocked('Cortesia indisponível','A finalidade oferece cortesia, mas a política está desativada para esta unidade.');
    if (capabilities.auth_required && !authenticated) return renderIdentification();
    if (capabilities.requires_ad) {
      if (!ads.length) return renderBlocked('Campanha indisponível','A política exige propaganda, mas nenhuma campanha ativa e elegível foi encontrada.');
      return renderAdvertisement(ads[0]);
    }
    renderCourtesyRequest();
  }

  function renderIdentification() {
    show(page(`<section class="portal-panel">${journeyBackButton()}${brand()}<p class="portal-eyebrow">Identificação obrigatória</p><h1 class="portal-title">Entre para continuar</h1><p class="portal-muted">A política desta unidade exige uma conta${policy.auth_mode === 'account_device' ? ' associada a este dispositivo' : ''}.</p><form class="portal-form" data-sim-form="identification"><label>CPF ou usuário<input name="username" value="123.456.789-00" autocomplete="off"></label><label>Senha<input type="password" name="password" value="simulacao" autocomplete="off"></label><button class="portal-button" type="submit">Simular identificação</button></form><div class="portal-notice">Os dados acima são fictícios e não serão enviados ou validados.</div></section>`),'Identificação do visitante','validation');
  }

  function renderAdvertisement(ad,previewOnly = false) {
    const media = safeImage(ad.media_url);
    const poster = safeImage(ad.poster_url);
    const isVideo = ad.media_type === 'video';
    const mediaMarkup = media ? (isVideo ? `<video class="portal-ad-media fit-${ad.fit_mode === 'cover' ? 'cover' : 'contain'}" autoplay muted loop playsinline poster="${escapeHtml(poster)}"><source src="${escapeHtml(media)}" type="video/mp4"></video><button class="portal-ad-sound" type="button" data-portal-action="ad-sound">Ativar som</button>` : `<img class="portal-ad-media fit-${ad.fit_mode === 'cover' ? 'cover' : 'contain'}" src="${escapeHtml(media)}" alt="${escapeHtml(ad.title)}">`) : '';
    const waitSeconds = Math.min(5,Math.max(2,Number(ad.duration_sec || 5)));
    const contextNote = previewOnly && !capabilities.requires_ad ? 'Esta campanha está ativa, mas a política atual não exige propaganda; por isso ela não aparece na jornada normal.' : `Duração configurada no portal real: ${Number(ad.duration_sec)} segundos · campanha ${ad.scope === 'global' ? 'global' : 'da unidade'}.`;
    show(`<div class="portal-ad-screen">${mediaMarkup}<div class="portal-ad-shade"></div><div class="portal-ad-top"><strong>${escapeHtml(ad.title)}</strong><span id="sim-ad-status">Pular em <b id="sim-ad-timer">${waitSeconds}</b>s</span></div><div class="portal-ad-actions" id="sim-ad-actions" aria-hidden="true">${ad.has_offer ? `<button class="portal-ad-interest" id="sim-ad-interest" type="button" data-portal-action="ad-interest" data-ad-id="${Number(ad.id)}">${escapeHtml(ad.interest_button_text || 'Tenho interesse')}</button>` : ''}<button class="portal-ad-skip" id="sim-ad-continue" type="button" data-portal-action="${previewOnly ? 'landing' : 'courtesy-request'}" disabled>Pular em ${waitSeconds}s</button><small>${escapeHtml(contextNote)} O interesse não interrompe a conexão.</small></div></div>`,'Exibição da propaganda','validation');
    let left = waitSeconds;
    activeTimer = window.setInterval(() => {
      left -= 1;
      const timer = document.getElementById('sim-ad-timer');
      if (timer) timer.textContent = String(Math.max(0,left));
      if (left <= 0) {
        clearActiveTimer();
        const status = document.getElementById('sim-ad-status');
        const button = document.getElementById('sim-ad-continue');
        const label = pendingOffer ? 'Continuar para conectar' : String(ad.skip_button_text || 'Pular e conectar');
        if (status) status.textContent = 'Tempo obrigatório concluído';
        if (button) { button.textContent = label; button.disabled = false; }
        const actions = document.getElementById('sim-ad-actions'); actions?.classList.add('is-visible'); actions?.setAttribute('aria-hidden','false');
        screen.querySelector('.portal-ad-screen')?.classList.add('actions-visible');
      } else {
        const button = document.getElementById('sim-ad-continue');
        if (button) button.textContent = `Pular em ${left}s`;
      }
    },1000);
  }

  function renderCourtesyRequest() {
    show(page(`<section class="portal-panel">${journeyBackButton()}${brand()}<p class="portal-eyebrow">Cortesia</p><h1 class="portal-title">Solicite seu acesso gratuito</h1><div class="portal-wifi"><div><span>Tempo disponível</span><strong>${escapeHtml(duration(policy.grant_minutes))}</strong></div><div><span>Consumo</span><strong>${policy.consumption_mode === 'online' ? 'Tempo conectado' : 'Tempo corrido'}</strong></div><div><span>Controle</span><strong>${policy.enforcement_method === 'radius' ? 'RADIUS' : 'MikroTik local'}</strong></div></div><button class="portal-button" type="button" data-portal-action="courtesy-release">Liberar cortesia simulada</button><div class="portal-notice">Nenhuma concessão será registrada.</div></section>`),'Solicitação da cortesia','validation');
  }

  function renderCourtesyReleased() {
    show(page(`<section class="portal-panel portal-success">${brand()}<div class="portal-success-icon">✓</div><p class="portal-eyebrow">Cortesia liberada</p><h1>${escapeHtml(duration(policy.grant_minutes))} disponíveis</h1><p class="portal-muted">A credencial temporária está pronta para autenticar o dispositivo no Hotspot.</p><div class="portal-status">Liberação simulada via ${policy.enforcement_method === 'radius' ? 'RADIUS' : 'MikroTik local'}.</div><button class="portal-button" type="button" data-portal-action="connect">Conectar ao Wi-Fi</button></section>`),'Cortesia liberada','release');
  }

  function selectPlan(id,source) {
    selectedPlan = plans.find(plan => Number(plan.id) === Number(id) && String(plan.source) === String(source)) || null;
    if (!selectedPlan) return renderBlocked('Plano indisponível','O plano selecionado não faz parte do catálogo ativo desta unidade.');
    selectedJourney = 'paid';
    renderCheckout();
  }

  function renderCheckout() {
    const plan = selectedPlan;
    if (!plan) return renderPlans();
    show(page(`<section class="portal-panel">${backButton('plans','Trocar plano')}${brand()}<p class="portal-eyebrow">Acesso premium</p><h1 class="portal-title">Como deseja pagar?</h1><div class="portal-summary"><div><h2>${escapeHtml(plan.name)}</h2><p>${escapeHtml(duration(plan.duration_minutes))} de acesso</p></div><strong>${escapeHtml(money(plan.price_cents))}</strong></div><div class="portal-methods"><button class="portal-method" type="button" data-portal-action="pix"><span>◇</span>Pix</button><button class="portal-method" type="button" data-portal-action="card"><span>▣</span>Cartão</button></div><div class="portal-notice">A simulação não cria cobrança real.</div></section>`),'Forma de pagamento','validation');
  }

  function qrMarkup() {
    return Array.from({length:81},(_,index) => `<i class="${((index * 7 + Math.floor(index / 9) * 3) % 5) < 2 ? 'is-dark' : ''}"></i>`).join('');
  }

  function renderPix() {
    show(page(`<section class="portal-panel">${backButton('checkout','Trocar forma de pagamento')}${brand()}<p class="portal-eyebrow">Pagamento Pix</p><h1 class="portal-title">Agora é só pagar</h1><p class="portal-muted">A liberação acontece automaticamente após a confirmação.</p><div class="portal-qr" aria-label="QR Code ilustrativo">${qrMarkup()}</div><code class="portal-pix-code">00020126...FIRESPOT-SIMULACAO...6304ABCD</code><div class="portal-status">Aguardando confirmação do pagamento simulado…</div><button class="portal-button" type="button" data-portal-action="payment-approved">Simular Pix aprovado</button></section>`),'Pagamento via Pix','validation');
  }

  function renderCard() {
    show(page(`<section class="portal-panel">${backButton('checkout','Trocar forma de pagamento')}${brand()}<p class="portal-eyebrow">Pagamento com cartão</p><h1 class="portal-title">Dados do cartão</h1><form class="portal-form" data-sim-form="card"><label>Número do cartão<input value="5031 4332 1540 6351" inputmode="numeric"></label><label>Nome impresso<input value="CLIENTE SIMULAÇÃO"></label><div class="portal-methods"><label>Validade<input value="11/30"></label><label>CVV<input value="123"></label></div><button class="portal-button" type="submit">Simular cartão aprovado</button></form><div class="portal-notice">Dados ilustrativos. Nada é enviado ao Mercado Pago.</div></section>`),'Pagamento com cartão','validation');
  }

  function renderPaymentApproved() {
    show(page(`<section class="portal-panel portal-success"><div class="portal-step">3 de 3</div>${brand()}<div class="portal-success-icon">✓</div><p class="portal-eyebrow">Pagamento confirmado</p><h1>Tudo certo!</h1><p class="portal-muted">Você possui aproximadamente ${escapeHtml(duration(selectedPlan.duration_minutes))} de crédito disponível.</p><div class="portal-status">Crédito e credencial simulados com sucesso.</div><button class="portal-button" type="button" data-portal-action="connect">Conectar à internet</button></section>`),'Acesso pago liberado','release');
  }

  function renderConnecting() {
    const message = selectedJourney === 'courtesy' ? 'Sua cortesia está liberada.' : (selectedJourney === 'subscriber' ? 'Seu aparelho e benefício foram confirmados.' : 'Seu pagamento foi confirmado.');
    show(page(`<section class="portal-panel portal-success">${brand()}<div class="portal-success-icon">⌁</div><p class="portal-eyebrow">Autenticação do Hotspot</p><h1>Conectando ao Wi-Fi…</h1><p class="portal-muted">${message} Enviando a credencial simulada ao equipamento da unidade.</p><div class="portal-status">Negociando acesso com a rede…</div></section>`),'Conectando ao Wi-Fi','release');
    activeTimer = window.setInterval(() => { clearActiveTimer(); renderConnected(); },1200);
  }

  function renderConnected() {
    const accessLabel = selectedJourney === 'courtesy' ? `Cortesia de ${duration(policy.grant_minutes)}` : (selectedJourney === 'subscriber' ? 'Benefício FIRENETWORK · acesso incluído' : `${selectedPlan.name} · ${duration(selectedPlan.duration_minutes)}`);
    const offerAction = pendingOffer && selectedJourney === 'courtesy' ? `<div class="portal-notice">Seu interesse em “${escapeHtml(pendingOffer.title)}” foi guardado durante a conexão.</div><button class="portal-button" type="button" data-portal-action="offer-open">Abrir oferta salva</button>` : '';
    show(page(`<section class="portal-panel portal-success">${brand()}<div class="portal-success-icon">✓</div><p class="portal-eyebrow">Wi-Fi conectado</p><h1>Você já está online!</h1><p class="portal-muted">A experiência completa terminou com a autenticação simulada do dispositivo.</p><div class="portal-wifi"><div><span>Rede</span><strong>${escapeHtml(partner.name)}</strong></div><div><span>Acesso</span><strong>${escapeHtml(accessLabel)}</strong></div><div><span>Status</span><strong>Conectado</strong></div></div>${offerAction}<button class="portal-button secondary" type="button" data-portal-action="restart">Executar novamente</button></section>`),'Wi-Fi conectado','connected');
  }

  function renderSavedOffer() {
    show(page(`<section class="portal-panel portal-success">${brand()}<div class="portal-success-icon">↗</div><p class="portal-eyebrow">Oferta pós-conexão</p><h1>${escapeHtml(pendingOffer?.title || 'Oferta')}</h1><p class="portal-muted">No ambiente real, este botão abre o endereço da campanha somente agora, com o Wi-Fi já conectado.</p><div class="portal-notice">Nesta simulação nenhum site externo será aberto e nenhuma métrica será gravada.</div><button class="portal-button secondary" type="button" data-portal-action="restart">Encerrar simulação</button></section>`),'Oferta aberta','connected');
  }

  screen.addEventListener('submit',event => {
    const form = event.target.closest('[data-sim-form]');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.simForm === 'identification') { authenticated = true; beginCourtesy(); }
    if (form.dataset.simForm === 'card') renderPaymentApproved();
    if (form.dataset.simForm === 'subscriber-owner-otp') renderSubscriberOtp();
    if (form.dataset.simForm === 'subscriber-owner-password') renderSubscriberReady('primary');
    if (form.dataset.simForm === 'subscriber-otp') renderSubscriberReady('primary');
    if (form.dataset.simForm === 'subscriber-invite') renderSubscriberReady('guest');
  });

  screen.addEventListener('click',event => {
    const planButton = event.target.closest('[data-plan-id]');
    if (planButton) return selectPlan(planButton.dataset.planId,planButton.dataset.planSource);
    const button = event.target.closest('[data-portal-action]');
    if (!button || button.disabled) return;
    const action = button.dataset.portalAction;
    if (action === 'restart') renderCaptive();
    else if (action === 'landing') renderConfiguredEntry();
    else if (action === 'entry-next') renderAfterWelcome();
    else if (action === 'options') renderOptions();
    else if (action === 'plans') renderPlans();
    else if (action === 'courtesy') beginCourtesy();
    else if (action === 'subscriber') beginSubscriber();
    else if (action === 'subscriber-owner') renderSubscriberOwner();
    else if (action === 'subscriber-owner-otp') renderSubscriberOwnerOtp();
    else if (action === 'subscriber-owner-password') renderSubscriberOwnerPassword();
    else if (action === 'subscriber-invite') renderSubscriberInvite();
    else if (action === 'courtesy-request') renderCourtesyRequest();
    else if (action === 'courtesy-release') renderCourtesyReleased();
    else if (action === 'checkout') renderCheckout();
    else if (action === 'pix') renderPix();
    else if (action === 'card') renderCard();
    else if (action === 'payment-approved') renderPaymentApproved();
    else if (action === 'connect') renderConnecting();
    else if (action === 'ad-interest') {
      pendingOffer = ads.find(ad => Number(ad.id) === Number(button.dataset.adId)) || null;
      button.textContent = 'Interesse registrado · continuando'; button.disabled = true; button.classList.add('is-done');
      const continueButton = document.getElementById('sim-ad-continue');
      if (continueButton && !continueButton.disabled) { continueButton.textContent = 'Continuar para conectar'; window.setTimeout(()=>continueButton.click(),350); }
    }
    else if (action === 'ad-sound') {
      const video = screen.querySelector('video'); if (video) { video.muted = !video.muted; button.textContent = video.muted ? 'Ativar som' : 'Desativar som'; }
    }
    else if (action === 'offer-open') renderSavedOffer();
  });

  document.addEventListener('click',event => {
    const actionButton = event.target.closest('[data-sim-action]');
    if (actionButton?.dataset.simAction === 'restart') renderCaptive();
    const routeButton = event.target.closest('[data-sim-route]');
    if (!routeButton || routeButton.disabled) return;
    if (routeButton.dataset.simRoute === 'subscriber') beginSubscriber();
    else if (routeButton.dataset.simRoute === 'courtesy') beginCourtesy();
    else if (routeButton.dataset.simRoute === 'ad') renderAdvertisement(ads[0],true);
    else renderPlans();
  });

  screen.addEventListener('error',event => {
    if (event.target instanceof HTMLImageElement) {
      event.target.hidden = true;
      const status = document.createElement('div');
      status.className = 'portal-status is-error';
      status.textContent = 'A imagem configurada não pôde ser carregada.';
      event.target.insertAdjacentElement('afterend',status);
    }
  },true);

  renderCaptive();
})();
