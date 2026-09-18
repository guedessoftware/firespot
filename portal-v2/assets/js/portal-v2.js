/**
 * Portal V2 - JavaScript Inteligente
 * FireSpot WiFi Hotspot
 * Design focado em conversão para planos pagos
 */

(function() {
  'use strict';

  const BRAZIL_DDDS = new Set([
    '11','12','13','14','15','16','17','18','19',
    '21','22','24','27','28',
    '31','32','33','34','35','37','38',
    '41','42','43','44','45','46','47','48','49',
    '51','53','54','55',
    '61','62','63','64','65','66','67','68','69',
    '71','73','74','75','77','79',
    '81','82','83','84','85','86','87','88','89',
    '91','92','93','94','95','96','97','98','99'
  ]);

  const Validation = (() => {
    const digitsOnly = (value = '') => String(value).replace(/\D+/g, '');

    function isValidCPF(value) {
      const digits = digitsOnly(value);
      if (digits.length !== 11) return false;
      if (/^(\d)\1{10}$/.test(digits)) return false;

      const calcDigit = (sliceLength) => {
        let sum = 0;
        let weight = sliceLength + 1;
        for (let i = 0; i < sliceLength; i++) {
          sum += parseInt(digits.charAt(i), 10) * (weight - i);
        }
        const mod = (sum * 10) % 11;
        return mod === 10 ? 0 : mod;
      };

      const digit1 = calcDigit(9);
      if (digit1 !== parseInt(digits.charAt(9), 10)) return false;

      const digit2 = calcDigit(10);
      return digit2 === parseInt(digits.charAt(10), 10);
    }

    function normalizePhoneDigits(digits) {
      let normalized = digitsOnly(digits);
      normalized = normalized.replace(/^0+/, '');
      if (normalized.length >= 12 && normalized.startsWith('55')) {
        normalized = normalized.slice(2);
      }
      return normalized;
    }

    function validatePhoneDigits(digits) {
      const len = digits.length;
      if (len < 11) return { valid: false, reason: 'too_short' };
      if (len > 11) return { valid: false, reason: 'too_long' };
      if (/^(\d)\1+$/.test(digits)) return { valid: false, reason: 'repeated_digits' };

      const ddd = digits.slice(0, 2);
      if (!BRAZIL_DDDS.has(ddd)) {
        return { valid: false, reason: 'invalid_ddd' };
      }

      if (digits.charAt(2) !== '9') {
        return { valid: false, reason: 'invalid_mobile' };
      }

      return { valid: true };
    }

    function formatCPF(digits) {
      if (digits.length !== 11) return digits;
      return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`;
    }

    function formatPhone(digits) {
      if (digits.length === 11) {
        return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
      }
      if (digits.length === 10) {
        return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
      }
      return digits;
    }

    function classifyIdentifier(rawValue) {
      const trimmed = String(rawValue || '').trim();
      const digits = digitsOnly(trimmed);
      if (!digits) {
        return { valid: false, reason: 'empty', sanitized: '', formatted: '', raw: trimmed };
      }

      if (digits.length === 11 && isValidCPF(digits)) {
        const sanitizedCpf = digits.slice(0, 11);
        return {
          valid: true,
          type: 'cpf',
          sanitized: sanitizedCpf,
          formatted: formatCPF(sanitizedCpf),
          raw: trimmed
        };
      }

      const normalizedPhone = normalizePhoneDigits(digits);
      const phoneCheck = validatePhoneDigits(normalizedPhone);

      if (phoneCheck.valid) {
        return {
          valid: true,
          type: 'phone',
          sanitized: normalizedPhone,
          formatted: formatPhone(normalizedPhone),
          raw: trimmed
        };
      }

      const reason = phoneCheck.reason || (digits.length === 11 ? 'invalid_cpf' : 'invalid_phone');
      return {
        valid: false,
        reason,
        sanitized: normalizedPhone,
        formatted: trimmed,
        raw: trimmed
      };
    }

    return {
      digitsOnly,
      isValidCPF,
      normalizePhoneDigits,
      isValidPhone: (value) => {
        const normalized = normalizePhoneDigits(value);
        return validatePhoneDigits(normalized).valid;
      },
      classifyIdentifier,
      formatCPF,
      formatPhone,
      validatePhoneDigits
    };
  })();

  window.showScreen = function(screenId) {
    document.querySelectorAll('.screen').forEach(screen => {
      screen.classList.remove('active');
    });
    
    // Mostra a tela selecionada
    const targetScreen = document.getElementById(screenId);
    if (targetScreen) {
      targetScreen.classList.add('active');
      attachIdentifierValidation(targetScreen);
      
      // Scroll suave para o topo
      window.scrollTo({ top: 0, behavior: 'smooth' });
      
      // Inicializa funcionalidades específicas da tela
      if (screenId === 'tela-upgrade') {
        startCountdown();
      }
    }
  };

  // ===== CONTADOR REGRESSIVO (Tela Upgrade) =====
  
  let countdownInterval;
  
  function startCountdown() {
    clearInterval(countdownInterval);
    
    let totalSeconds = 154; // 2:34
    const timerElement = document.getElementById('countdown-timer');
    const progressElement = document.getElementById('progress-fill');
    const totalTime = 15 * 60; // 15 minutos totais
    
    countdownInterval = setInterval(() => {
      if (totalSeconds <= 0) {
        clearInterval(countdownInterval);
        // Mostra tela de planos quando tempo acabar
        showScreen('tela-plans');
        return;
      }
      
      totalSeconds--;
      
      // Atualiza o timer
      const minutes = Math.floor(totalSeconds / 60);
      const seconds = totalSeconds % 60;
      timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
      
      // Atualiza a barra de progresso
      const remainingTime = totalSeconds;
      const percentageUsed = ((totalTime - remainingTime) / totalTime) * 100;
      progressElement.style.width = Math.min(percentageUsed, 100) + '%';
      
      // Quando faltar menos de 1 minuto, mostra urgência
      if (totalSeconds === 60) {
        document.querySelector('.alert').innerHTML = '⚠️ <strong>ÚLTIMO MINUTO!</strong> Evite ser desconectado';
        document.querySelector('.alert').style.background = '#ff4444';
        document.querySelector('.alert').style.color = '#fff';
      }
      
    }, 1000);
  }

  // ===== SELEÇÃO DE PLANO =====
  
  window.selectPlan = function(planElement) {
    if (!planElement) return;

    document.querySelectorAll('.plan-card').forEach(card => {
      card.classList.remove('selected');
    });

    planElement.classList.add('selected');

    const planName = planElement.getAttribute('data-plan-name')
      || (planElement.querySelector('.plan-info-title')
        ? planElement.querySelector('.plan-info-title').textContent
        : '');
    const planPrice = planElement.getAttribute('data-plan-price') || '0.00';
    const planPriceDisplay = planElement.getAttribute('data-plan-price-display')
      || ('R$ ' + planPrice.replace('.', ','));
    const planId = planElement.getAttribute('data-plan-id') || '';

    const nameTarget = document.getElementById('selected-plan-name');
    if (nameTarget) nameTarget.textContent = planName;

    const priceTarget = document.getElementById('selected-plan-price');
    if (priceTarget) priceTarget.textContent = planPriceDisplay;

    const planIdInput = document.getElementById('plan_id');
    if (planIdInput) planIdInput.value = planId;

    const planPriceInput = document.getElementById('plan_price');
    if (planPriceInput) planPriceInput.value = planPrice;

    const summaryPlan = document.getElementById('summary-plan');
    if (summaryPlan) summaryPlan.textContent = planName;

    const summaryPrice = document.getElementById('summary-price');
    if (summaryPrice) summaryPrice.textContent = planPriceDisplay;

    const selectionBar = document.getElementById('selection-bar');
    if (selectionBar) {
      selectionBar.style.transform = 'scale(1.02)';
      setTimeout(() => {
        selectionBar.style.transform = 'scale(1)';
      }, 200);
    }
  };

  // ===== CONTINUAR COM ACESSO GRÁTIS =====
  
  window.continueWithFree = function() {
    // Fecha o aviso e volta para a navegação
    alert('Você continuará usando o acesso grátis limitado. Boa navegação!');
    showScreen('tela-welcome');
  };

  // ===== COMEÇAR A NAVEGAR (Pós-pagamento) =====
  
  window.startBrowsing = function() {
    // Redireciona para o portal do cliente ou página de navegação
    window.location.href = '../portal/cliente.php';
  };

  // ===== SOCIAL PROOF DINÂMICO =====
  
  function updateSocialProof() {
    const socialCountElement = document.getElementById('social-count');
    if (socialCountElement) {
      // Número aleatório entre 250 e 500 (simulação de pessoas que compraram hoje)
      const baseCount = 250 + Math.floor(Math.random() * 250);
      socialCountElement.textContent = baseCount;
    }
  }

  // ===== GATILHO INTELIGENTE DE UPGRADE =====
  
  let sessionStartTime = Date.now();
  let upgradePromptShown = false;
  
  function checkUpgradePrompt() {
    // Após 3 minutos de uso gratuito, mostra a tela de upgrade
    const minutesElapsed = (Date.now() - sessionStartTime) / 1000 / 60;
    
    if (minutesElapsed >= 3 && !upgradePromptShown) {
      upgradePromptShown = true;
      showScreen('tela-upgrade');
    }
  }

  // Verifica a cada 30 segundos
  setInterval(checkUpgradePrompt, 30000);

  // ===== PERSUASÃO VISUAL =====
  
  function addVisualPersuasion() {
    // Piscar levemente o plano recomendado
    const recommendedPlan = document.querySelector('.plan-card.recommended')
      || document.querySelector('.plan-card');
    if (recommendedPlan) {
      setInterval(() => {
        recommendedPlan.style.boxShadow = '0 10px 24px rgba(255, 122, 0, 0.28)';
        setTimeout(() => {
          recommendedPlan.style.boxShadow = '0 12px 28px rgba(255, 122, 0, 0.45)';
        }, 1500);
      }, 3000);
    }
  }

  // ===== VALIDAÇÃO DE FORMULÁRIO =====
  
  function messageForReason(reason) {
    switch (reason) {
      case 'empty':
        return 'Informe seu telefone com DDD ou um CPF válido.';
      case 'too_short':
        return 'Informe 11 dígitos (DDD + 9 + número) ou um CPF com 11 dígitos.';
      case 'too_long':
        return 'Digite apenas os números do telefone com DDD (11 dígitos) ou um CPF com 11 dígitos.';
      case 'invalid_cpf':
        return 'CPF inválido. Verifique os números e tente novamente.';
      case 'invalid_phone':
        return 'Telefone inválido. Use DDD + 9 + número, apenas dígitos.';
      case 'invalid_ddd':
        return 'DDD inválido. Verifique o código da sua região (ex.: 11, 21, 92).';
      case 'invalid_mobile':
        return 'Número móvel inválido. Após o DDD, celulares devem começar com 9.';
      case 'repeated_digits':
        return 'Número inválido. Evite sequências com todos os dígitos iguais.';
      default:
        return 'Não reconhecemos esse número. Tente novamente.';
    }
  }

  function getFieldErrorElement(input) {
    if (!input || !input.id) return null;
    return document.getElementById(`${input.id}-error`);
  }

  function showFieldError(input, message) {
    if (!input) return;
    const errorEl = getFieldErrorElement(input);
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
    input.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
  }

  function clearFieldError(input) {
    if (!input) return;
    const errorEl = getFieldErrorElement(input);
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.hidden = true;
    }
    input.classList.remove('is-invalid');
    input.setAttribute('aria-invalid', 'false');
    delete input.dataset.identifierType;
  }

  function validateIdentifierField(input, options = {}) {
    const { showErrors = false, keepFormatted = false } = options;
    const result = Validation.classifyIdentifier(input ? input.value : '');

    if (result.valid) {
      clearFieldError(input);
      if (keepFormatted && result.formatted) {
        input.value = result.formatted;
      }
      if (result.type) {
        input.dataset.identifierType = result.type;
      }
      return result;
    }

    if (showErrors) {
      showFieldError(input, messageForReason(result.reason));
    }
    if (input) {
      delete input.dataset.identifierType;
    }
    return result;
  }

  function handleIdentifierInput(event) {
    const input = event && event.target ? event.target : null;
    if (!input) return;
    const digits = Validation.digitsOnly(input.value);
    if (digits !== input.value) {
      input.value = digits;
    }
    clearFieldError(input);
  }

  function setupIdentifierField(input) {
    if (!input || input.dataset.identifierBound === '1') return;
    input.dataset.identifierBound = '1';

    input.addEventListener('input', handleIdentifierInput);
    input.addEventListener('blur', () => {
      validateIdentifierField(input, { showErrors: true, keepFormatted: true });
    });

    const form = input.form;
    if (form) bindIdentifierForm(form);
  }

  function bindIdentifierForm(form) {
    if (!form || form.dataset.identifierSubmitBound === '1') return;
    form.dataset.identifierSubmitBound = '1';

    form.addEventListener('submit', (e) => {
      const fields = Array.from(form.querySelectorAll('[data-identifier="cpf-or-phone"]'));
      if (fields.length === 0) return;

      let firstInvalid = null;
      fields.forEach((field) => {
        const result = validateIdentifierField(field, { showErrors: true });
        if (!result.valid) {
          if (!firstInvalid) firstInvalid = field;
          return;
        }
        field.value = result.sanitized;
        field.dataset.identifierType = result.type || '';
      });

      if (firstInvalid) {
        e.preventDefault();
        firstInvalid.focus();
      }
    });
  }

  function attachIdentifierValidation(root = document) {
    const scope = root || document;
    const fields = Array.from(scope.querySelectorAll('[data-identifier="cpf-or-phone"]'));
    fields.forEach(setupIdentifierField);
  }

  function initIdentifierValidation() {
    attachIdentifierValidation(document);
  }

  // ===== ANIMAÇÕES DE ENTRADA =====
  
  function animateElements() {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, {
      threshold: 0.1,
      rootMargin: '0px 0px -50px 0px'
    });

    document.querySelectorAll('.plan-card, .compare-card').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(el);
    });
  }

  // ===== DETECÇÃO DE SAÍDA (Exit Intent) =====
  
  let exitIntentShown = false;
  
  document.addEventListener('mouseout', function(e) {
    // Se o mouse saiu pela parte superior da página
    if (e.clientY < 10 && !exitIntentShown) {
      exitIntentShown = true;
      
      // Se está na tela de boas-vindas, mostra oferta especial
      const activeScreen = document.querySelector('.screen.active');
      if (activeScreen && activeScreen.id === 'tela-welcome') {
        const confirmExit = confirm(
          '🎉 OFERTA ESPECIAL!\n\n' +
          'Antes de sair: Ganhe 20% de desconto em qualquer plano!\n\n' +
          'Deseja ver os planos com desconto?'
        );
        
        if (confirmExit) {
          showScreen('tela-plans');
          // Aplica desconto visual (opcional)
          applyDiscount(20);
        }
      }
    }
  });

  // ===== APLICAR DESCONTO =====
  
  function applyDiscount(percentage) {
    document.querySelectorAll('.plan-card').forEach(card => {
      const priceElement = card.querySelector('.plan-price');
      const currentPrice = parseFloat(card.getAttribute('data-plan-price'));
      if (!priceElement || Number.isNaN(currentPrice)) return;
      const discountedPrice = (currentPrice * (1 - percentage / 100)).toFixed(2);
      const currentDisplay = card.getAttribute('data-plan-price-display')
        || ('R$ ' + currentPrice.toFixed(2).replace('.', ','));
      const discountedDisplay = 'R$ ' + discountedPrice.replace('.', ',');
      
      // Mostra preço antigo e novo preço
      priceElement.innerHTML = `
        <span class="plan-price-old">${currentDisplay}</span>
        ${discountedDisplay}
      `;
      
      card.setAttribute('data-plan-price', discountedPrice);
      card.setAttribute('data-plan-price-display', discountedDisplay);

      if (card.classList.contains('selected')) {
        selectPlan(card);
      }
    });
    
    // Adiciona badge de desconto
    const header = document.querySelector('#tela-plans .screen-header');
    if (header) {
      const discountBadge = document.createElement('span');
      discountBadge.className = 'badge-small';
      discountBadge.style.background = '#ff4444';
      discountBadge.style.color = '#fff';
      discountBadge.style.border = 'none';
      discountBadge.textContent = `${percentage}% OFF`;
      header.appendChild(discountBadge);
    }
  }

  Validation.attachIdentifierValidation = attachIdentifierValidation;
  Validation.validateIdentifierField = (input, options) => validateIdentifierField(input, options);
  Validation.messageForReason = messageForReason;

  window.PortalV2Validation = Validation;

  // ===== INICIALIZAÇÃO =====

  document.addEventListener('DOMContentLoaded', function() {
    console.log('🔥 Portal V2 inicializado - Design focado em conversão');
    
    if (window.PORTAL_V2_START_SCREEN) {
      showScreen(String(window.PORTAL_V2_START_SCREEN));
    }

    // Inicializa funcionalidades
    updateSocialProof();
    initIdentifierValidation();
    animateElements();
    addVisualPersuasion();
    
    // Pré-seleciona o plano recomendado
    const recommendedPlan = document.querySelector('.plan-card.recommended');
    if (recommendedPlan) {
      selectPlan(recommendedPlan);
    } else {
      const firstPlan = document.querySelector('.plan-card');
      if (firstPlan) selectPlan(firstPlan);
    }
    
    // Atualiza social proof a cada 2 minutos
    setInterval(updateSocialProof, 120000);
  });

})();
