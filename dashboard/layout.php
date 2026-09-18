<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
require_once __DIR__ . '/../app/control_center_navigation.php';
admin_require_page();

// A interface moderna é o único shell ativo. Remove uma preferência antiga
// que poderia manter administradores presos ao layout descontinuado.
unset($_SESSION['dashboard_ui']);

if (!function_exists('fs_admin_escape')) {
    function fs_admin_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fs_admin_icon')) {
    function fs_admin_icon(string $name, string $class = 'fs-icon'): string
    {
        $paths = [
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'store' => '<path d="M3 9l2-5h14l2 5"/><path d="M5 13v8h14v-8"/><path d="M9 21v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
            'plans' => '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>',
            'finance' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
            'campaigns' => '<path d="M3 11l18-5v12L3 13z"/><path d="M11.6 15.4L13 21H7l-1.7-7"/>',
            'reports' => '<path d="M4 19V9M10 19V5M16 19v-8M22 19H2"/>',
            'network' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01M10 7h8M10 17h8"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.09 14H3v-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63h.01A1.7 1.7 0 0 0 10 3.09V3h4v.09A1.7 1.7 0 0 0 15 4.64a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9v.01A1.7 1.7 0 0 0 20.91 10H21v4h-.09A1.7 1.7 0 0 0 19.4 15z"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/>',
            'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>',
            'chevron' => '<path d="M9 18l6-6-6-6"/>',
            'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
            'collapse' => '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>',
            'device' => '<rect x="5" y="2" width="14" height="20" rx="3"/><path d="M10 18h4"/>',
            'subscriber' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/><path d="M18 3l1.5 1.5L22 2"/>',
            'access' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M15 4l4 4M14 9l2 2"/>',
            'wallet' => '<path d="M4 6h15a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a3 3 0 0 1 3-3h12"/><path d="M16 12h5"/>',
            'audit' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        ];
        $path = $paths[$name] ?? $paths['overview'];
        return '<svg class="' . fs_admin_escape($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }
}

$current = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$titulo = isset($titulo) ? (string) $titulo : 'Dashboard';
$conteudo = isset($conteudo) ? (string) $conteudo : '<p>Bem-vindo ao painel administrativo do FireSpot!</p>';
$pageId = isset($pageId) ? (string) $pageId : '';

$pageMeta = fs_control_center_page_meta();
$meta = $pageMeta[$current] ?? ['title' => $titulo, 'section' => 'FireSpot', 'description' => 'Administração central do FireSpot.'];

$navGroups = fs_control_center_nav_groups();
$tabsByPage = fs_control_center_tabs();
$sectionTabs = $tabsByPage[$current] ?? [];

$adminValue = $_SESSION['admin_name'] ?? $_SESSION['admin'];
$adminLabel = is_string($adminValue) && trim($adminValue) !== '' ? trim($adminValue) : 'Administrador';
$adminInitial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($adminLabel, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($adminLabel, 0, 1));

$assetVersion = static function (string $relative): string {
    $path = __DIR__ . '/' . ltrim($relative, '/');
    return is_file($path) ? (string) filemtime($path) : '1';
};

$pageAssets = [
    'dashboard' => ['styles' => ['pages/control-center.css'], 'chart' => true, 'scripts' => ['kpis.js', 'table-online-fixed.js', 'charts.js', 'poller.js']],
    'usuarios' => ['styles' => ['pages/usuarios.css'], 'scripts' => ['usuarios.js']],
    'dispositivos' => ['styles' => ['pages/dispositivos.css']],
    'privacidade' => ['styles' => ['pages/control-center.css','pages/relatorios.css'], 'scripts' => ['privacidade.js']],
    'anuncios' => ['styles' => ['pages/anuncios.css']],
    'configuracoes' => ['styles' => ['pages/configuracoes.css','pages/control-center.css']],
    'recebimentos' => ['styles' => ['pages/recebimentos.css']],
    'host_acessos' => ['styles' => ['pages/control-center.css','pages/host-acessos.css']],
    'aud_vendas' => ['styles' => ['pages/auditoria-vendas.css'], 'scripts' => ['auditoria-vendas.js']],
    'assinantes' => ['styles' => ['pages/assinantes.css','pages/control-center.css'], 'scripts' => ['assinantes.js','assinantes-mappings.js']],
    'planos' => ['styles' => ['pages/control-center.css','pages/planos.css'], 'scripts' => ['planos.js']],
    'relatorios' => ['styles' => ['pages/relatorios.css'], 'chart' => true, 'scripts' => ['relatorios.js']],
    'vendas' => ['styles' => ['pages/vendas.css'], 'chart' => true, 'scripts' => ['vendas.js']],
    'estabelecimentos' => ['styles' => ['pages/control-center.css']],
    'estabelecimento' => ['styles' => ['pages/control-center.css'], 'scripts' => ['control-center.js']],
    'financeiro' => ['styles' => ['pages/control-center.css']],
    'monetizacao' => ['styles' => ['pages/control-center.css']],
    'campanhas' => ['styles' => ['pages/control-center.css']],
    'promocoes' => ['scripts' => ['promocoes.js']],
    'infraestrutura' => ['styles' => ['pages/control-center.css']],
    'nas' => ['styles' => ['pages/nas.css'], 'scripts' => ['nas.js']],
    'integracoes' => ['styles' => ['pages/control-center.css'], 'scripts' => ['integracoes.js']],
];
$assets = $pageAssets[$pageId] ?? ['scripts' => []];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#101828">
  <meta name="firespot-csrf" content="<?= fs_admin_escape(csrf_token()) ?>">
  <title><?= fs_admin_escape($meta['title']) ?> - FireSpot</title>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <script src="assets/js/theme-bootstrap.js?v=<?= fs_admin_escape($assetVersion('assets/js/theme-bootstrap.js')) ?>"></script>
  <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= fs_admin_escape($assetVersion('assets/css/dashboard.css')) ?>">
  <link rel="stylesheet" href="assets/css/dashboard-modern.css?v=<?= fs_admin_escape($assetVersion('assets/css/dashboard-modern.css')) ?>">
  <?php foreach (($assets['styles'] ?? []) as $stylesheet): ?>
    <link rel="stylesheet" href="assets/css/<?= fs_admin_escape($stylesheet) ?>?v=<?= fs_admin_escape($assetVersion('assets/css/' . $stylesheet)) ?>">
  <?php endforeach; ?>
</head>
<body class="fs-admin sidebar-closed" data-theme="light" data-page="<?= fs_admin_escape($pageId) ?>">
  <a class="fs-skip-link" href="#main-content">Ir para o conteúdo</a>

  <div class="dashboard fs-shell">
    <aside class="sidebar fs-sidebar" aria-label="Navegação principal">
      <div class="fs-brand">
        <a class="fs-brand__link" href="index.php" aria-label="FireSpot — Visão geral">
          <img src="assets/img/logo-dark.png" id="logo-img" alt="Fire Network">
          <span class="fs-brand__product">FireSpot</span>
        </a>
        <button id="toggle-sidebar-density" class="fs-icon-btn fs-sidebar__collapse" type="button" aria-label="Recolher menu" title="Recolher menu">
          <?= fs_admin_icon('collapse') ?>
        </button>
      </div>

      <nav class="fs-nav" aria-label="Seções do painel">
        <?php foreach ($navGroups as $group): ?>
          <section class="fs-nav__group" aria-labelledby="nav-<?= fs_admin_escape(strtolower($group['label'])) ?>">
            <h2 id="nav-<?= fs_admin_escape(strtolower($group['label'])) ?>" class="fs-nav__label"><?= fs_admin_escape($group['label']) ?></h2>
            <ul>
              <?php foreach ($group['items'] as $item): ?>
                <?php if (!empty($item['capability']) && !admin_has_capability($item['capability'])) continue; ?>
                <?php $isActive = in_array($current, $item['pages'], true); ?>
                <li>
                  <a href="<?= fs_admin_escape($item['href']) ?>" class="fs-nav__link<?= $isActive ? ' active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?> title="<?= fs_admin_escape($item['label']) ?>">
                    <?= fs_admin_icon($item['icon']) ?>
                    <span class="fs-nav__text"><?= fs_admin_escape($item['label']) ?></span>
                    <?= $isActive ? '<span class="fs-nav__active-dot" aria-hidden="true"></span>' : '' ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      </nav>

      <div class="fs-sidebar__footer">
        <span class="fs-system-dot" aria-hidden="true"></span>
        <span class="fs-sidebar__footer-text"><strong>FireSpot</strong><small>Central de operações</small></span>
        <span class="fs-version">V3</span>
      </div>
    </aside>

    <div id="sidebar-backdrop" class="backdrop fs-backdrop" aria-hidden="true"></div>

    <main class="main-content fs-main" id="main-content">
      <header class="fs-topbar">
        <div class="fs-topbar__start">
          <button id="toggle-menu" class="fs-icon-btn menu-btn" type="button" aria-label="Abrir menu" aria-expanded="false">
            <?= fs_admin_icon('menu') ?>
          </button>
          <div class="fs-topbar__context">
            <span class="fs-topbar__eyebrow"><?= fs_admin_escape($meta['section']) ?></span>
            <span class="fs-topbar__divider" aria-hidden="true">/</span>
            <strong><?= fs_admin_escape($meta['title']) ?></strong>
          </div>
        </div>

        <div class="fs-topbar__actions">
          <small id="last-refresh" class="muted fs-last-refresh" aria-live="polite"></small>
          <button id="toggle-theme" class="fs-icon-btn theme-btn" type="button" aria-label="Ativar tema escuro" title="Alternar tema">
            <?= fs_admin_icon('moon', 'fs-icon fs-theme-icon fs-theme-icon--moon') ?>
            <?= fs_admin_icon('sun', 'fs-icon fs-theme-icon fs-theme-icon--sun') ?>
          </button>
          <details class="fs-user-menu">
            <summary aria-label="Abrir opções da conta">
              <span class="fs-avatar"><?= fs_admin_escape($adminInitial) ?></span>
              <span class="fs-user-menu__identity"><strong><?= fs_admin_escape($adminLabel) ?></strong><small>Administrador</small></span>
              <?= fs_admin_icon('chevron', 'fs-icon fs-user-menu__chevron') ?>
            </summary>
            <div class="fs-user-menu__popover">
              <div class="fs-user-menu__header"><strong><?= fs_admin_escape($adminLabel) ?></strong><small>Sessão administrativa</small></div>
              <form method="post" action="logout.php" class="fs-user-menu__logout-form">
                <input type="hidden" name="csrf" value="<?= fs_admin_escape(csrf_token()) ?>">
                <button type="submit" class="is-danger"><?= fs_admin_icon('logout') ?><span>Sair</span></button>
              </form>
            </div>
          </details>
        </div>
      </header>

      <div class="fs-page">
        <section class="fs-page-heading" aria-labelledby="page-title">
          <div>
            <p class="fs-page-heading__eyebrow"><?= fs_admin_escape($meta['section']) ?></p>
            <h1 id="page-title"><?= fs_admin_escape($meta['title']) ?></h1>
            <p><?= fs_admin_escape($meta['description']) ?></p>
          </div>
        </section>

        <?php if ($sectionTabs): ?>
          <nav class="fs-section-tabs" aria-label="Navegação de <?= fs_admin_escape($meta['section']) ?>">
            <?php foreach ($sectionTabs as $tab): ?>
              <?php
                $tabFile = basename((string) parse_url($tab['href'], PHP_URL_PATH));
                $tabQuery = [];
                parse_str((string)(parse_url($tab['href'], PHP_URL_QUERY) ?? ''), $tabQuery);
                $tabSection = (string)($tabQuery['section'] ?? '');
                $currentSection = (string)($_GET['section'] ?? (isset($section) ? (string)$section : ($current === 'assinantes.php' ? 'overview' : '')));
                $tabActive = $tabFile === $current && $tabSection === $currentSection;
              ?>
              <a href="<?= fs_admin_escape($tab['href']) ?>" class="<?= $tabActive ? 'active' : '' ?>" <?= $tabActive ? 'aria-current="page"' : '' ?>>
                <?= fs_admin_icon($tab['icon']) ?>
                <span><?= fs_admin_escape($tab['label']) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>

        <section class="content fs-content">
          <?= $conteudo ?>
        </section>
      </div>
    </main>
  </div>

  <script defer src="assets/js/common.js?v=<?= fs_admin_escape($assetVersion('assets/js/common.js')) ?>"></script>
  <script defer src="assets/js/ui.js?v=<?= fs_admin_escape($assetVersion('assets/js/ui.js')) ?>"></script>
  <?php if (!empty($assets['chart'])): ?>
    <script defer src="assets/vendor/chartjs/chart.umd.min.js?v=<?= fs_admin_escape($assetVersion('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
  <?php endif; ?>
  <?php foreach (($assets['scripts'] ?? []) as $script): ?>
    <script defer src="assets/js/<?= fs_admin_escape($script) ?>?v=<?= fs_admin_escape($assetVersion('assets/js/' . $script)) ?>"></script>
  <?php endforeach; ?>
  <script defer src="assets/js/main.js?v=<?= fs_admin_escape($assetVersion('assets/js/main.js')) ?>"></script>
</body>
</html>
