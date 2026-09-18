# Guia de Migração de Páginas para Portal V2

Este documento descreve como migrar páginas do portal original para o Portal V2, mantendo a funcionalidade intacta enquanto aplica o novo design.

## 📋 Checklist de Migração

### Antes de Começar
- [ ] Faça backup da página original
- [ ] Teste a página original para entender o fluxo
- [ ] Identifique todas as dependências (APIs, includes, etc.)
- [ ] Liste todos os formulários e validações

### Durante a Migração
- [ ] Copie a página para `portal-v2/`
- [ ] Atualize paths de includes (se necessário)
- [ ] Importe os estilos do V2
- [ ] Substitua classes CSS antigas pelas novas
- [ ] Mantenha toda lógica PHP intacta
- [ ] Teste funcionalidade completa

### Após Migração
- [ ] Teste em diferentes dispositivos
- [ ] Valide formulários
- [ ] Verifique console do navegador (sem erros)
- [ ] Teste fluxo completo end-to-end

## 🎨 Padrões de Design V2

### Header Padrão
```html
<header class="header-v2">
  <div class="container">
    <div class="header-content">
      <img src="../dashboard/assets/img/logo-light.png" alt="FireSpot" class="logo">
      <nav class="header-nav">
        <a href="termos.php" class="nav-link">Termos</a>
        <a href="index.php" class="nav-link">Voltar</a>
      </nav>
    </div>
  </div>
</header>
```

### Main Container
```html
<main class="main-v2">
  <div class="container">
    <!-- Conteúdo aqui -->
  </div>
</main>
```

### Footer Padrão
```html
<footer class="footer-v2">
  <div class="container">
    <div class="footer-content">
      <p>&copy; <?= date('Y') ?> FIRENETWORK. Todos os direitos reservados.</p>
      <div class="footer-links">
        <a href="termos.php">Termos</a>
        <span class="separator">•</span>
        <a href="termos.php#lgpd">Privacidade</a>
      </div>
    </div>
  </div>
</footer>
```

## 📝 Exemplos de Migração

### Exemplo 1: Formulário de Login

**ANTES (Portal Original):**
```html
<div class="card">
  <h2>Login</h2>
  <form method="POST" action="login.php">
    <input type="text" name="username" placeholder="Usuário">
    <input type="password" name="password" placeholder="Senha">
    <button type="submit">Entrar</button>
  </form>
</div>
```

**DEPOIS (Portal V2):**
```html
<div class="access-card">
  <h2 class="card-title">Login</h2>
  <form method="POST" action="login.php" class="form-v2">
    <div class="form-group">
      <label for="username" class="form-label">Usuário</label>
      <input type="text" id="username" name="username" class="form-input" placeholder="Digite seu usuário">
    </div>
    <div class="form-group">
      <label for="password" class="form-label">Senha</label>
      <input type="password" id="password" name="password" class="form-input" placeholder="Digite sua senha">
    </div>
    <button type="submit" class="btn btn-primary btn-lg">Entrar</button>
  </form>
</div>
```

### Exemplo 2: Mensagem de Erro

**ANTES:**
```html
<?php if ($erro): ?>
  <div class="notice error"><?= $erro ?></div>
<?php endif; ?>
```

**DEPOIS:**
```html
<?php if ($erro): ?>
  <div class="alert alert-error">
    <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
    </svg>
    <div>
      <strong>Erro</strong>
      <p><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>
<?php endif; ?>
```

## 🎯 Classes CSS Disponíveis no V2

### Layout
- `.container` - Container centralizado com max-width
- `.main-v2` - Main content wrapper
- `.header-v2` - Header sticky
- `.footer-v2` - Footer moderno

### Cards
- `.access-card` - Card padrão
- `.access-card--featured` - Card destacado
- `.card-badge` - Badge de destaque
- `.card-icon` - Ícone do card
- `.card-title` - Título do card
- `.card-description` - Descrição
- `.card-features` - Lista de features
- `.card-actions` - Grupo de botões

### Botões
- `.btn` - Botão base
- `.btn-primary` - Botão primário (azul)
- `.btn-secondary` - Botão secundário (roxo)
- `.btn-accent` - Botão destaque (laranja)
- `.btn-outline` - Botão outline
- `.btn-lg` - Botão grande
- `.btn-icon` - Ícone dentro do botão

### Formulários
```css
.form-v2 { /* Container do form */ }
.form-group { /* Grupo de campo */ }
.form-label { /* Label do campo */ }
.form-input { /* Input field */ }
.form-textarea { /* Textarea */ }
.form-select { /* Select dropdown */ }
.form-checkbox { /* Checkbox */ }
.form-radio { /* Radio button */ }
.form-help { /* Texto de ajuda */ }
.form-error { /* Mensagem de erro */ }
```

### Alerts
- `.alert` - Alert base
- `.alert-success` - Sucesso (verde)
- `.alert-error` - Erro (vermelho)
- `.alert-warning` - Aviso (amarelo)
- `.alert-info` - Info (azul)

### Grid
- `.access-grid` - Grid de cards
- `.features-grid` - Grid de features
- `.content-grid` - Grid genérico

## 🔧 CSS Adicional para Formulários

Adicione ao `portal-v2.css`:

```css
/* Forms */
.form-v2 {
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.form-label {
  font-weight: 600;
  color: var(--gray-700);
  font-size: var(--font-size-sm);
}

.form-input,
.form-textarea,
.form-select {
  width: 100%;
  padding: var(--space-md);
  border: 2px solid var(--gray-300);
  border-radius: var(--radius-md);
  font-size: var(--font-size-base);
  font-family: var(--font-sans);
  transition: var(--transition);
}

.form-input:focus,
.form-textarea:focus,
.form-select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-input::placeholder {
  color: var(--gray-400);
}

.form-help {
  font-size: var(--font-size-sm);
  color: var(--gray-500);
}

.form-error {
  font-size: var(--font-size-sm);
  color: var(--error);
}

.form-checkbox,
.form-radio {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}

.form-checkbox input,
.form-radio input {
  width: 20px;
  height: 20px;
  cursor: pointer;
}
```

## 📄 Template Base para Novas Páginas

```php
<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';

// Sua lógica PHP aqui

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Título da Página - FireSpot V2</title>
  <link rel="stylesheet" href="assets/css/portal-v2.css">
  <script defer src="assets/js/portal-v2.js"></script>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
</head>
<body>
  <header class="header-v2">
    <div class="container">
      <div class="header-content">
        <img src="../dashboard/assets/img/logo-light.png" alt="FireSpot" class="logo">
        <nav class="header-nav">
          <a href="index.php" class="nav-link">Início</a>
          <a href="termos.php" class="nav-link">Termos</a>
        </nav>
      </div>
    </div>
  </header>

  <main class="main-v2">
    <div class="container">
      
      <!-- Seu conteúdo aqui -->

    </div>
  </main>

  <footer class="footer-v2">
    <div class="container">
      <div class="footer-content">
        <p>&copy; <?= date('Y') ?> FIRENETWORK. Todos os direitos reservados.</p>
        <div class="footer-links">
          <a href="termos.php">Termos</a>
          <span class="separator">•</span>
          <a href="termos.php#lgpd">Privacidade</a>
        </div>
      </div>
    </div>
  </footer>
</body>
</html>
```

## 🚀 Próximas Páginas Sugeridas

1. **login.php** - Página de login de visitantes
2. **cadastro.php** - Formulário de registro
3. **cliente.php** - Dashboard do cliente logado
4. **vip_checkout.php** - Checkout de planos premium
5. **anuncio.php** - Página de anúncio para minutos grátis
6. **dispositivos.php** - Gerenciamento de dispositivos
7. **historico.php** - Histórico de conexões

## ⚠️ Avisos Importantes

1. **Nunca altere a lógica PHP** - Apenas o HTML/CSS deve mudar
2. **Mantenha todos os includes** - Session, CSRF, DB, etc.
3. **Preserve nomes de formulários** - name, id, action devem ser idênticos
4. **Teste sempre em ambiente de desenvolvimento primeiro**
5. **Mantenha compatibilidade com portal original** - Ambos devem coexistir

## 📚 Recursos

- Design System: `portal-v2/assets/css/portal-v2.css`
- JavaScript: `portal-v2/assets/js/portal-v2.js`
- Ícones: [Heroicons](https://heroicons.com/) (SVG inline)
- Fontes: `portal-v2/assets/fonts/`

---

**Última atualização**: 06/12/2025
