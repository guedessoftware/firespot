/**
 * Portal V2 Checkout Controller
 */
(function () {
  'use strict';

  const cfg = window.CHECKOUT_CONFIG || {};
  cfg.context = cfg.context || {};
  const successUrl = cfg.successUrl || (cfg.urls && cfg.urls.success) || 'vip_sucesso.php';
  if (!cfg.plan || !cfg.csrfToken) {
    return;
  }

  const baseCustomer = cfg.customer || {};

  const formEl = document.getElementById('checkout-customer-form');
  if (!formEl) {
    return;
  }

  const state = {
    method: 'pix',
    orderRef: null,
    pollingTimer: null,
    pollingTarget: null,
    generating: false,
    paying: false,
  };

  const feedbackEl = document.getElementById('checkout-feedback');
  const pixBtn = document.getElementById('btn-generate-pix');
  const copyBtn = document.getElementById('btn-copy-pix');
  const ticketLink = document.getElementById('btn-open-ticket');
  const qrImg = document.getElementById('pix-qr-image');
  const qrText = document.getElementById('pix-code');
  const pixStatus = document.getElementById('pix-status');
  const cardStatus = document.getElementById('card-status');
  const tabs = Array.from(document.querySelectorAll('.tab-btn'));
  const panels = Array.from(document.querySelectorAll('.payment-panel'));
  const cardButton = document.getElementById('btn-pay-card');
  const docType = document.getElementById('form-doc-type');

  const runtimeKey = '__firespotCheckoutRuntime';
  const runtime = typeof window !== 'undefined'
    ? (window[runtimeKey] = window[runtimeKey] || {})
    : {};

  let cardFormInstance = runtime.cardFormInstance || null;
  let cardFormReady = runtime.cardFormReady === true;
  let cardInitPending = false;
  let cardInitFailed = false;
  let cardContextReset = false;

  function digitsOnly(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function normalizeCpf(value) {
    const digits = digitsOnly(value);
    return digits.length > 11 ? digits.slice(-11) : digits;
  }

  function normalizePhone(value) {
    let digits = digitsOnly(value);
    digits = digits.replace(/^0+/, '');
    if (digits.length >= 12 && digits.startsWith('55')) {
      digits = digits.slice(2);
    }
    if (digits.length > 11) {
      digits = digits.slice(-11);
    }
    return digits;
  }

  function setStatus(target, message, type) {
    if (!target) return;
    target.textContent = message || '';
    target.style.display = message ? 'block' : 'none';
    target.classList.remove('success', 'error');
    if (type) {
      target.classList.add(type);
    }
  }
  function cardReasonMessage(code, detail) {
    const normalized = String(code || '').toLowerCase();
    const detailNorm = String(detail || '').toLowerCase();
    if (detailNorm.includes('cc_rejected_bad_filled_security_code')) {
      return 'O código de segurança (CVV) foi recusado. Confira os números e tente novamente.';
    }
    if (detailNorm.includes('cc_rejected_bad_filled_date')) {
      return 'A validade informada não é aceita. Ajuste o mês/ano e tente novamente.';
    }
    if (detailNorm.includes('cc_rejected_bad_filled_card_number')) {
      return 'O número do cartão não foi reconhecido. Revise os dígitos e tente outra vez.';
    }
    if (detailNorm.includes('cc_rejected_insufficient_amount')) {
      return 'O emissor não autorizou por falta de saldo ou limite disponível.';
    }
    if (detailNorm.includes('cc_rejected_high_risk')) {
      return 'Pagamento recusado por análise de risco do emissor. Tente outro cartão ou método.';
    }
    if (detailNorm.includes('cc_rejected_call_for_authorize')) {
      return 'O emissor pediu autorização por telefone. Entre em contato com o banco e tente novamente.';
    }
    if (detailNorm.includes('cc_rejected_blacklist')) {
      return 'Pagamento recusado pelo emissor. Use outro cartão ou método.';
    }
    if (normalized === 'rejected') {
      return 'Pagamento não aprovado pelo emissor do cartão. Revise os dados ou use outro cartão.';
    }
    return 'Não foi possível processar o pagamento. Verifique os dados do cartão ou tente outro método.';
  }

  function clearStatus(target) {
    if (!target) return;
    target.textContent = '';
    target.style.display = 'none';
    target.classList.remove('success', 'error');
  }

  function setLoading(context, loading) {
    if (context === 'pix') {
      state.generating = loading;
      if (pixBtn) {
        pixBtn.disabled = loading;
      }
    } else if (context === 'card') {
      state.paying = loading;
      if (cardButton) {
        cardButton.disabled = loading;
      }
    }
  }

  function collectCustomerFromForm() {
    const name = (formEl.querySelector('#customer-name') || {}).value || '';
    const cpf = normalizeCpf((formEl.querySelector('#customer-cpf') || {}).value || baseCustomer.cpf || '');
    const phone = normalizePhone((formEl.querySelector('#customer-phone') || {}).value || baseCustomer.phone || '');
    const email = (formEl.querySelector('#customer-email') || {}).value || baseCustomer.email || '';
    const username = (document.getElementById('checkout-username') || {}).value || baseCustomer.username || '';
    return { name: name.trim(), cpf, phone, email: email.trim(), username: username.trim() };
  }

  function validateCustomer(data) {
    if (!data.name || data.name.length < 3) {
      throw new Error('Informe seu nome completo.');
    }
    if (data.cpf.length !== 11) {
      throw new Error('Informe um CPF válido.');
    }
    if (data.phone.length < 10) {
      throw new Error('Informe um telefone válido.');
    }
    if (!data.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
      throw new Error('Informe um e-mail válido.');
    }
  }

  function toggleMethod(method) {
    state.method = method;
    tabs.forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.method === method);
    });
    panels.forEach((panel) => {
      panel.classList.toggle('active', panel.dataset.method === method);
    });
    clearStatus(feedbackEl);
  }

  tabs.forEach((btn) => {
    btn.addEventListener('click', () => {
      const method = btn.dataset.method;
      if (!method) return;
      if (method === 'card') {
        if (!cfg.publicKey) {
          setStatus(feedbackEl, 'Cartão de crédito indisponível: configure a chave pública do Mercado Pago.', 'error');
          return;
        }
        initCardForm();
        if (!cardFormReady && cardInitPending) {
          setStatus(cardStatus, 'Carregando módulo de cartão de crédito...', 'info');
        }
      }
      toggleMethod(method);
    });
  });

  function stopPolling() {
    if (state.pollingTimer) {
      clearTimeout(state.pollingTimer);
      state.pollingTimer = null;
    }
    state.pollingTarget = null;
  }

  function getPollingStatusEl() {
    if (state.pollingTarget === 'card') {
      return cardStatus;
    }
    return pixStatus;
  }

  async function pollPaymentStatus() {
    if (!state.orderRef) return;
    if (!cfg.api || !cfg.api.paymentStatus) return;
    try {
      const res = await fetch(cfg.api.paymentStatus, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ ref: state.orderRef, csrf_token: cfg.csrfToken }),
      });
      const data = await res.json();
      if (!data || data.ok === false) {
        throw new Error(data && data.error ? data.error : 'Erro ao verificar pagamento.');
      }
      if (data.status === 'paid') {
        const target = getPollingStatusEl();
        await promoteVip(state.orderRef);
        setStatus(target, 'Pagamento confirmado! Você será redirecionado em instantes.', 'success');
        stopPolling();
        setTimeout(() => {
          window.location.href = successUrl + '?ref=' + encodeURIComponent(state.orderRef);
        }, 1200);
        return;
      }
    } catch (err) {
      error_log('[checkout] status', err);
    }
    state.pollingTimer = setTimeout(pollPaymentStatus, 4000);
  }

  function error_log(label, err) {
    if (typeof console !== 'undefined' && console.error) {
      console.error(label, err);
    }
  }

  async function bindSession(token, customer) {
    try {
      if (!cfg.api || !cfg.api.paySessionBind) {
        return;
      }
      await fetch(cfg.api.paySessionBind, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token,
          csrf_token: cfg.csrfToken,
          ip: cfg.context.ip || '',
          mac: cfg.context.mac || '',
          minutes: cfg.plan.durationMinutes || null,
          phone: customer.phone || '',
        }),
      });
    } catch (err) {
      error_log('[checkout] bind session', err);
    }
  }

  async function promoteVip(ref) {
    if (!ref || !cfg.api || !cfg.api.promoteVip) {
      return;
    }
    try {
      await fetch(cfg.api.promoteVip, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ref, csrf_token: cfg.csrfToken }),
      });
    } catch (err) {
      error_log('[checkout] promote vip', err);
    }
  }

  function waitForMercadoPago(maxWaitMs) {
    const root = typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : null);
    const timeout = typeof maxWaitMs === 'number' && maxWaitMs > 0 ? maxWaitMs : 10000;
    if (!root) {
      return Promise.reject(new Error('mp_sdk_unavailable'));
    }
    if (typeof root.MercadoPago !== 'undefined') {
      return Promise.resolve(root.MercadoPago);
    }
    return new Promise((resolve, reject) => {
      const started = Date.now();
      const timer = setInterval(() => {
        if (typeof root.MercadoPago !== 'undefined') {
          clearInterval(timer);
          resolve(root.MercadoPago);
          return;
        }
        if (Date.now() - started >= timeout) {
          clearInterval(timer);
          reject(new Error('mp_sdk_timeout'));
        }
      }, 250);
    });
  }

  function resetCardInputsForMp() {
    const ids = [
      'form-card-number',
      'form-card-expiration-date',
      'form-card-security-code',
      'form-card-holder-name',
      'form-card-holder-email',
      'form-doc-number',
      'form-installments',
      'form-issuer',
    ];
    ids.forEach((id) => {
      const el = document.getElementById(id);
      if (el && el.parentNode) {
        const clone = el.cloneNode(true);
        if ('value' in clone) {
          clone.value = el.value || '';
        }
        el.parentNode.replaceChild(clone, el);
      }
    });
  }

  function enablePixActions(data) {
    if (qrText) {
      qrText.value = data.qr_code || '';
      qrText.disabled = false;
    }
    if (qrImg) {
      if (data.qr_code_base64) {
        qrImg.src = data.qr_code_base64;
        qrImg.style.display = 'block';
      } else {
        qrImg.style.display = 'none';
      }
    }
    if (copyBtn) {
      copyBtn.disabled = !(data.qr_code && data.qr_code.length);
    }
    if (ticketLink) {
      if (data.ticket_url) {
        ticketLink.href = data.ticket_url;
        ticketLink.style.display = 'inline-flex';
      } else {
        ticketLink.style.display = 'none';
      }
    }
  }

  async function createPix() {
    if (!pixBtn) return;
    clearStatus(feedbackEl);
    clearStatus(pixStatus);
    const customer = collectCustomerFromForm();
    try {
      validateCustomer(customer);
    } catch (err) {
      setStatus(feedbackEl, err.message, 'error');
      return;
    }

    setLoading('pix', true);
    try {
      if (!cfg.api || !cfg.api.create) {
        throw new Error('Configuração de pagamento indisponível.');
      }
      const payload = {
        csrf_token: cfg.csrfToken,
        plan_id: cfg.plan.id,
        payment_method: 'pix',
        customer,
        context: cfg.context,
      };
      const res = await fetch(cfg.api.create, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || data.ok === false) {
        throw new Error(data && data.error ? data.error : 'Falha ao gerar Pix.');
      }
      state.orderRef = data.external_ref;
      enablePixActions(data);
      setStatus(pixStatus, 'Escaneie o QR Code ou copie o código para concluir o pagamento.', 'success');
      bindSession(state.orderRef, customer);
      stopPolling();
      state.pollingTarget = 'pix';
      state.pollingTimer = setTimeout(pollPaymentStatus, 4000);
    } catch (err) {
      setStatus(pixStatus, err.message || 'Não foi possível gerar o Pix.', 'error');
    } finally {
      setLoading('pix', false);
    }
  }

  async function copyPixCode() {
    if (!qrText || !qrText.value) return;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(qrText.value);
      } else {
        qrText.select();
        document.execCommand('copy');
      }
      setStatus(pixStatus, 'Código PIX copiado. Conclua o pagamento no seu banco.', 'success');
    } catch (err) {
      setStatus(pixStatus, 'Não foi possível copiar automaticamente. Copie manualmente.', 'error');
    }
  }

  async function submitCard(formData) {
    clearStatus(feedbackEl);
    clearStatus(cardStatus);
    const customer = collectCustomerFromForm();
    try {
      validateCustomer(customer);
    } catch (err) {
      setStatus(feedbackEl, err.message, 'error');
      return;
    }

    if (!formData || !formData.token || !formData.paymentMethodId) {
      setStatus(cardStatus, 'Preencha os dados do cartão corretamente.', 'error');
      return;
    }

    setLoading('card', true);
    try {
      if (!cfg.api || !cfg.api.create) {
        throw new Error('Configuração de pagamento indisponível.');
      }
      const payload = {
        csrf_token: cfg.csrfToken,
        plan_id: cfg.plan.id,
        payment_method: 'card',
        customer,
        context: cfg.context,
        card: {
          token: formData.token,
          payment_method_id: formData.paymentMethodId,
          issuer_id: formData.issuerId || '',
          installments: parseInt(formData.installments, 10) || 1,
        },
      };

      const res = await fetch(cfg.api.create, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || data.ok === false) {
        const errMsg = data && data.error ? data.error : 'Pagamento não autorizado.';
        throw new Error(errMsg);
      }

      state.orderRef = data.external_ref;
      bindSession(state.orderRef, customer);
      const statusMsg = data.message || 'Pagamento enviado para análise.';
      const status = (data.status || '').toLowerCase();
      const statusDetail = data.status_detail || '';

      if (status === 'approved') {
        await promoteVip(state.orderRef);
        setStatus(cardStatus, statusMsg, 'success');
        window.location.href = data.redirect_url || (successUrl + '?ref=' + encodeURIComponent(state.orderRef));
      } else if (status === 'in_process') {
        setStatus(cardStatus, statusMsg, 'success');
        stopPolling();
        state.pollingTarget = 'card';
        state.pollingTimer = setTimeout(pollPaymentStatus, 4000);
      } else {
        const friendly = cardReasonMessage(status, statusDetail);
        setStatus(cardStatus, friendly, 'error');
      }
    } catch (err) {
      const detail = err && err.message ? String(err.message) : '';
      const friendly = cardReasonMessage('', detail);
      setStatus(cardStatus, friendly, 'error');
    } finally {
      setLoading('card', false);
    }
  }

  if (pixBtn) {
    pixBtn.addEventListener('click', createPix);
  }
  if (copyBtn) {
    copyBtn.addEventListener('click', copyPixCode);
  }

  const planAmountCents = Number(cfg.plan.priceCents || cfg.plan.price_cents || 0);

  async function initCardForm() {
    if (!cfg.publicKey || cardFormReady || cardInitPending) {
      return;
    }

    cardInitFailed = false;
    clearStatus(cardStatus);
    cardInitPending = true;

    const initPromise = runtime.cardFormInitPromise ? runtime.cardFormInitPromise.catch(() => null) : null;
    runtime.cardFormInitPromise = (async () => {
      if (initPromise) {
        const existing = await initPromise;
        if (existing) {
          return existing;
        }
      }
      const MercadoPagoCtor = await waitForMercadoPago(12000);
      if (typeof MercadoPagoCtor !== 'function') {
        throw new Error('mp_sdk_invalid');
      }
      if (runtime.cardFormInstance) {
        return runtime.cardFormInstance;
      }

      if (cardContextReset) {
        resetCardInputsForMp();
        cardContextReset = false;
      }

      const mp = new MercadoPagoCtor(cfg.publicKey, { locale: 'pt-BR' });
      const instance = mp.cardForm({
        amount: (planAmountCents / 100).toFixed(2),
        autoMount: true,
        form: {
          id: 'checkout-customer-form',
          cardNumber: { id: 'form-card-number', placeholder: '0000 0000 0000 0000' },
          expirationDate: { id: 'form-card-expiration-date', placeholder: 'MM/AA' },
          securityCode: { id: 'form-card-security-code', placeholder: '123' },
          cardholderName: { id: 'form-card-holder-name', placeholder: 'Nome do titular' },
          cardholderEmail: { id: 'form-card-holder-email', placeholder: 'email@dominio.com' },
          issuer: { id: 'form-issuer', placeholder: 'Banco emissor' },
          identificationType: { id: 'form-doc-type', placeholder: 'Documento' },
          identificationNumber: { id: 'form-doc-number', placeholder: '00000000000' },
          installments: { id: 'form-installments', placeholder: 'Parcelas' },
        },
        callbacks: {
          onFormMounted: function (error) {
            if (error) {
              error_log('[checkout] card form mount', error);
              setStatus(cardStatus, 'Não foi possível carregar o formulário do cartão. Atualize a página e tente novamente.', 'error');
            }
          },
          onSubmit: function (event) {
            event.preventDefault();
            if (!cardFormInstance) {
              setStatus(cardStatus, 'Pagamento com cartão não está pronto. Atualize a página.', 'error');
              return;
            }
            const data = cardFormInstance.getCardFormData();
            submitCard(data);
          },
          onFetching: function () {
            setLoading('card', true);
            return function () {
              setLoading('card', false);
            };
          },
          onCardTokenReceived: function () {},
          onValidityChange: function () {},
        },
      });

      runtime.cardFormInstance = instance;
      runtime.cardFormReady = true;
      return instance;
    })();

    try {
      cardFormInstance = await runtime.cardFormInitPromise;
      if (!cardFormInstance) {
        throw new Error('mp_cardform_uninitialized');
      }
      cardFormReady = true;
      if (docType) {
        docType.value = 'CPF';
      }
      if (cardButton) {
        cardButton.disabled = false;
      }
      clearStatus(cardStatus);
    } catch (err) {
      runtime.cardFormInstance = null;
      runtime.cardFormReady = false;
      runtime.cardFormInitPromise = null;

      const message = err && err.message ? String(err.message) : '';
      if (message.toLowerCase().includes('context')) {
        runtime.cardFormInitPromise = null;
        runtime.cardFormInstance = null;
        runtime.cardFormReady = false;
        cardContextReset = true;
        cardInitPending = false;
        error_log('[checkout] mp init retry', err);
        return initCardForm();
      }

      cardInitFailed = true;
      if (cardButton) {
        cardButton.disabled = true;
      }
      setStatus(cardStatus, 'Não foi possível habilitar pagamentos com cartão. Verifique sua conexão e tente novamente.', 'error');
      error_log('[checkout] mp init', err);
    } finally {
      cardInitPending = false;
      if (!cardInitFailed) {
        runtime.cardFormInitPromise = null;
      }
    }
  }

  if (cardButton) {
    cardButton.disabled = !cardFormReady;
  }
  if (cardFormReady && docType) {
    docType.value = 'CPF';
  }
  if (cfg.publicKey && !cardFormReady) {
    initCardForm();
  }

  formEl.addEventListener('submit', function (event) {
    if (state.method === 'card') {
      if (!cardFormReady) {
        event.preventDefault();
        initCardForm();
      }
      return;
    }
    event.preventDefault();
    createPix();
  });
})();
