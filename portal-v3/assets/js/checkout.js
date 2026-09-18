(function () {
  'use strict';

  const cfg = window.PORTAL_V3_CONFIG || {};
  const errorBox = document.getElementById('payment-error');
  const methods = document.getElementById('checkout-methods');
  const pixButton = document.getElementById('pay-with-pix');
  const cardButton = document.getElementById('pay-with-card');
  const cardCheckout = document.getElementById('card-checkout');
  const cardBack = document.getElementById('back-to-pix');
  const loading = document.getElementById('payment-loading');
  const linkAccount = document.getElementById('link-account-purchase');
  let cardController = null;
  let cardStarting = false;
  let sdkPromise = null;

  // Alguns navegadores restauram o estado anterior do formulário. A associação
  // é sempre uma escolha explícita e deve começar desmarcada.
  if (linkAccount) linkAccount.checked = false;

  function setError(message) {
    errorBox.textContent = message || 'Não foi possível iniciar o pagamento.';
    errorBox.hidden = false;
  }

  function clearError() {
    errorBox.hidden = true;
    errorBox.textContent = '';
  }

  function setBusy(button, busy) {
    if (!button) return;
    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  async function createPayment(formData, selectedPaymentMethod) {
    clearError();
    const response = await fetch(cfg.apiCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: cfg.csrf,
        form_data: formData,
        selected_payment_method: selectedPaymentMethod || '',
        link_account: Boolean(cfg.canLinkAccount && linkAccount && linkAccount.checked)
      })
    });
    const data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Não foi possível gerar o pagamento. Tente novamente.');
    }
    window.location.href = data.success_url || (cfg.successUrl + '?order=' + encodeURIComponent(data.order));
  }

  pixButton.addEventListener('click', async function () {
    setBusy(pixButton, true);
    try {
      await createPayment({
        payment_method_id: 'pix',
        payer: { email: String(cfg.payerEmail || '') }
      }, 'bank_transfer');
    } catch (error) {
      setError(error.message);
      setBusy(pixButton, false);
    }
  });

  function showMethods() {
    document.body.classList.remove('checkout-card-open');
    cardCheckout.hidden = true;
    methods.hidden = false;
    clearError();
  }

  function loadCardSdk() {
    if (typeof window.MercadoPago === 'function') return Promise.resolve();
    if (sdkPromise) return sdkPromise;
    sdkPromise = new Promise(function (resolve, reject) {
      const script = document.createElement('script');
      script.src = 'https://sdk.mercadopago.com/js/v2';
      script.async = true;
      script.onload = function () {
        if (typeof window.MercadoPago === 'function') resolve();
        else reject(new Error('SDK indisponível.'));
      };
      script.onerror = function () { reject(new Error('SDK indisponível.')); };
      document.head.appendChild(script);
    });
    return sdkPromise;
  }

  async function startCardBrick() {
    if (cardStarting || cardController) return;
    if (!cfg.publicKey) {
      setError('O pagamento com cartão está indisponível agora. Use o Pix.');
      return;
    }
    cardStarting = true;
    loading.hidden = false;
    try {
      await loadCardSdk();
      const mp = new window.MercadoPago(cfg.publicKey, { locale: 'pt-BR' });
      cardController = await mp.bricks().create('payment', 'paymentBrick_container', {
        initialization: {
          amount: Number(cfg.amount || 0),
          payer: { email: String(cfg.payerEmail || '') }
        },
        customization: {
          visual: { style: { theme: 'default' } },
          paymentMethods: { creditCard: 'all', maxInstallments: 12 }
        },
        callbacks: {
          onReady: function () { loading.hidden = true; },
          onError: function () {
            loading.hidden = true;
            setError('Não foi possível carregar o cartão. Use o Pix ou tente novamente.');
          },
          onSubmit: function (submission) {
            const formData = submission && submission.formData ? submission.formData : submission;
            const selected = submission && (submission.selectedPaymentMethod || submission.paymentMethod) || 'credit_card';
            return createPayment(formData, selected).catch(function (error) {
              setError(error.message);
              throw error;
            });
          }
        }
      });
    } catch (error) {
      loading.hidden = true;
      setError('Não foi possível abrir o cartão. Use o Pix ou tente novamente.');
    } finally {
      cardStarting = false;
    }
  }

  cardButton.addEventListener('click', function () {
    clearError();
    methods.hidden = true;
    cardCheckout.hidden = false;
    document.body.classList.add('checkout-card-open');
    startCardBrick();
  });

  cardBack.addEventListener('click', showMethods);
})();
