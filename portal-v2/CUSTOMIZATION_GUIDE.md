# 🎨 Guia Rápido de Personalização - Portal V2

## Mudanças Rápidas (5 minutos)

### 1. Alterar Cores Principais

Edite `/var/www/html/hotspot/portal-v2/assets/css/portal-v2.css`:

```css
:root {
  /* SUAS CORES AQUI */
  --primary: #3b82f6;        /* Azul principal (botões, links) */
  --secondary: #8b5cf6;      /* Roxo secundário */
  --accent: #f59e0b;         /* Laranja destaque */
}
```

**Exemplos de paletas:**

#### Paleta Verde Natureza
```css
--primary: #10b981;
--secondary: #14b8a6;
--accent: #f59e0b;
```

#### Paleta Roxa Premium
```css
--primary: #8b5cf6;
--secondary: #a855f7;
--accent: #ec4899;
```

#### Paleta Vermelha Energética
```css
--primary: #ef4444;
--secondary: #f97316;
--accent: #facc15;
```

---

### 2. Mudar Gradiente do Fundo

No mesmo arquivo CSS, procure por:

```css
body {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
```

**Exemplos:**

#### Azul para Ciano
```css
background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);
```

#### Verde para Azul
```css
background: linear-gradient(135deg, #10b981 0%, #3b82f6 100%);
```

#### Pôr do Sol
```css
background: linear-gradient(135deg, #f97316 0%, #dc2626 100%);
```

#### Noturno
```css
background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
```

---

### 3. Trocar Fonte

#### Passo 1: Baixe a fonte desejada
- Faça o download do arquivo `.woff2` da família escolhida (ex.: exportando do Google Fonts ou outra fundição).
- Salve o arquivo em `portal-v2/assets/fonts/` com um nome simples, por exemplo `MinhaFonte-Regular.woff2`.

#### Passo 2: Atualize o `@font-face`
No topo do arquivo `portal-v2/assets/css/portal-v2.css`, ajuste o bloco `@font-face`:
```css
@font-face {
  font-family: 'Minha Fonte';
  src: url('../fonts/MinhaFonte-Regular.woff2') format('woff2');
  font-weight: 100 900; /* ajuste se a fonte não for variável */
  font-style: normal;
  font-display: swap;
}
```
Caso use uma família com múltiplos pesos, declare um bloco por peso.

#### Passo 3: Aplique a fonte no CSS
Altere a variável tipográfica no mesmo arquivo (ou no CSS específico da página):
```css
:root {
  --font-sans: 'Minha Fonte', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
```
Repita o processo em outros arquivos CSS (`home.css`, `notice.css`) se eles definirem pilhas de fontes próprias.

---

### 4. Ajustar Tamanhos de Fonte

No CSS:

```css
:root {
  /* TAMANHOS BASE */
  --font-size-sm: 0.875rem;    /* Textos pequenos */
  --font-size-base: 1rem;      /* Texto normal */
  --font-size-lg: 1.125rem;    /* Texto grande */
  --font-size-xl: 1.25rem;     /* Subtítulos */
  --font-size-2xl: 1.5rem;     /* Títulos de card */
  --font-size-3xl: 1.875rem;   /* Títulos de seção */
  --font-size-4xl: 2.25rem;    /* Título principal (hero) */
}
```

**Para aumentar tudo em 10%:**
```css
:root {
  --font-size-sm: 0.96rem;
  --font-size-base: 1.1rem;
  --font-size-lg: 1.24rem;
  --font-size-xl: 1.38rem;
  --font-size-2xl: 1.65rem;
  --font-size-3xl: 2.06rem;
  --font-size-4xl: 2.48rem;
}
```

---

### 5. Personalizar Cards

#### Card com Sombra Colorida
```css
.access-card {
  box-shadow: 0 20px 60px rgba(59, 130, 246, 0.3); /* Azul */
}

.access-card--featured {
  box-shadow: 0 20px 60px rgba(139, 92, 246, 0.4); /* Roxo */
}
```

#### Card com Borda Grossa
```css
.access-card {
  border: 3px solid var(--primary);
}
```

#### Card com Background Gradiente
```css
.access-card--featured {
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
}
```

---

### 6. Modificar Textos da Página

Edite `/portal-v2/index.php`:

#### Título Hero
```php
<h1 class="hero-title">
  Conecte-se ao<br>
  <span class="gradient-text">SEU TEXTO AQUI</span>
</h1>
```

#### Subtítulo Hero
```php
<p class="hero-subtitle">
  Sua mensagem personalizada aqui
</p>
```

#### Nomes dos Cards
```php
<h2 class="card-title">Seu Título Customizado</h2>
```

---

### 7. Adicionar Logo Personalizado

Substitua a logo no header:

```php
<img src="CAMINHO/PARA/SUA/LOGO.png" alt="Sua Empresa" class="logo">
```

E ajuste o tamanho no CSS se necessário:
```css
.logo {
  height: 50px; /* Ajuste conforme necessário */
  width: auto;
}
```

---

### 8. Remover/Adicionar Seções

#### Remover Seção de Features

Em `/portal-v2/index.php`, comente ou delete:

```php
<!-- <section class="features-section">
  ... todo o conteúdo ...
</section> -->
```

#### Adicionar Nova Seção

```php
<section class="custom-section">
  <div class="container">
    <h2 class="section-title">Sua Nova Seção</h2>
    <p>Conteúdo personalizado aqui</p>
  </div>
</section>
```

E adicione o CSS:
```css
.custom-section {
  background: white;
  padding: var(--space-3xl);
  border-radius: var(--radius-xl);
  margin-top: var(--space-2xl);
}
```

---

### 9. Customizar Botões

#### Botão com Animação de Pulse
```css
.btn-primary {
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.8; }
}
```

#### Botão com Gradiente
```css
.btn-primary {
  background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
}
```

#### Botão Arredondado
```css
.btn {
  border-radius: var(--radius-full); /* Totalmente arredondado */
}
```

---

### 10. Ajustar Espaçamentos

No CSS:

```css
:root {
  --space-xs: 0.25rem;   /* 4px */
  --space-sm: 0.5rem;    /* 8px */
  --space-md: 1rem;      /* 16px */
  --space-lg: 1.5rem;    /* 24px */
  --space-xl: 2rem;      /* 32px */
  --space-2xl: 3rem;     /* 48px */
  --space-3xl: 4rem;     /* 64px */
}
```

**Para espaçamentos mais compactos:**
```css
:root {
  --space-xs: 0.2rem;
  --space-sm: 0.4rem;
  --space-md: 0.8rem;
  --space-lg: 1.2rem;
  --space-xl: 1.6rem;
  --space-2xl: 2.4rem;
  --space-3xl: 3.2rem;
}
```

---

## 🎨 Geradores de Paletas de Cores

- [Coolors.co](https://coolors.co/) - Gerador de paletas
- [Adobe Color](https://color.adobe.com/) - Roda de cores
- [Color Hunt](https://colorhunt.co/) - Paletas prontas
- [UI Colors](https://uicolors.app/) - Paletas para UI

---

## 🔧 Testando Mudanças

### 1. Edite o arquivo
Use seu editor preferido (nano, vim, VS Code, etc.)

### 2. Salve o arquivo
Ctrl+S ou `:wq` (vim)

### 3. Limpe o cache do navegador
- Chrome: Ctrl+Shift+R
- Firefox: Ctrl+Shift+R
- Safari: Cmd+Shift+R

### 4. Recarregue a página
F5 ou clique em atualizar

---

## 💡 Dicas Profissionais

1. **Faça mudanças incrementais** - Teste cada alteração antes de fazer a próxima
2. **Mantenha backups** - Copie o arquivo antes de editar
3. **Use variáveis CSS** - Facilita mudanças globais
4. **Teste em múltiplos dispositivos** - Desktop, tablet, mobile
5. **Valide cores** - Use ferramentas de contraste para acessibilidade

---

## ⚠️ O Que NÃO Fazer

❌ Não altere estrutura HTML sem testar  
❌ Não remova classes CSS usadas no JavaScript  
❌ Não modifique lógica PHP sem entender o código  
❌ Não use cores com baixo contraste  
❌ Não esqueça de testar em mobile  

---

## 📱 Visualização Rápida em Mobile

### Usando Chrome DevTools
1. F12 para abrir DevTools
2. Ctrl+Shift+M para toggle device mode
3. Escolha dispositivo (iPhone, iPad, etc.)
4. Teste interações

### Usando Firefox DevTools
1. F12 para abrir DevTools
2. Ctrl+Shift+M para responsive design mode
3. Escolha dispositivo
4. Teste

---

## 🚀 Checklist de Personalização Completa

- [ ] Escolher paleta de cores
- [ ] Aplicar cores no CSS
- [ ] Trocar fonte (opcional)
- [ ] Ajustar tamanhos de fonte
- [ ] Modificar gradiente do fundo
- [ ] Adicionar logo personalizada
- [ ] Customizar textos da página
- [ ] Ajustar espaçamentos
- [ ] Personalizar botões
- [ ] Testar em desktop
- [ ] Testar em tablet
- [ ] Testar em mobile
- [ ] Validar acessibilidade
- [ ] Fazer backup final

---

**Tempo estimado**: 15-30 minutos para personalização completa  
**Dificuldade**: ⭐⭐☆☆☆ (Fácil)
