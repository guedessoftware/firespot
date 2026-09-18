<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/account_deletion.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("SET time_zone='-04:00'");

$titulo = 'Privacidade dos clientes';
$pageId = 'privacidade';
$logsPerPage = 25;
$logPage = max(1, (int)($_GET['page'] ?? 1));
$stats = deleted_accounts_stats($pdo);
$totalLogs = (int)$stats['total'];
$totalPages = max(1, (int)ceil($totalLogs / $logsPerPage));
$logPage = min($logPage, $totalPages);
$deletedAccounts = fetch_deleted_accounts($pdo, $logsPerPage, ($logPage - 1) * $logsPerPage);

$pageUrl = static fn(int $page): string => 'privacidade.php?' . http_build_query(['page' => max(1, $page)]);

ob_start();
?>

<section class="card reports-separated-card">
  <header class="fs-cc-section-heading">
    <div>
      <span class="fs-cc-eyebrow">Clientes</span>
      <h2>Privacidade e exclusões</h2>
      <p>Recibos anônimos das exclusões solicitadas. Dados pessoais eliminados não são restaurados nem exibidos nesta tela.</p>
    </div>
  </header>
  <div class="content-grid reports-kpi-grid" aria-label="Indicadores de exclusão de contas">
    <article class="kpi-small">
      <strong><?= number_format($totalLogs, 0, ',', '.') ?></strong>
      <span class="muted">Exclusões registradas</span>
    </article>
    <article class="kpi-small">
      <strong><?= number_format((int)$stats['last_30_days'], 0, ',', '.') ?></strong>
      <span class="muted">Exclusões nos últimos 30 dias</span>
    </article>
  </div>
</section>

<section class="card">
  <h2 class="reports-card-title">Auditoria de exclusões de conta</h2>
  <?php if (!$deletedAccounts): ?>
    <p class="muted">Nenhuma exclusão registrada até o momento.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="tabela">
        <thead>
          <tr>
            <th>ID</th>
            <th>Referência anônima</th>
            <th>Data</th>
            <th>Origem</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deletedAccounts as $log): ?>
            <?php $logId = (int)$log['id']; ?>
            <tr>
              <td>#<?= $logId ?></td>
              <td><?= htmlspecialchars((string)$log['username'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$log['deleted_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$log['reason'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <button type="button" class="theme-btn js-log-toggle" data-target="privacy-log-<?= $logId ?>" aria-expanded="false">Detalhes</button>
              </td>
            </tr>
            <tr id="privacy-log-<?= $logId ?>" class="log-detail" hidden>
              <td colspan="5">
                <pre class="payload-json" data-log-id="<?= $logId ?>"><?= htmlspecialchars((string)json_encode($log['payload_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                <button type="button" class="theme-btn js-copy-log" data-log="<?= $logId ?>">Copiar recibo JSON</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="pager reports-pager" aria-label="Paginação da auditoria de privacidade">
        <?php if ($logPage > 1): ?>
          <a class="pager-link" href="<?= htmlspecialchars($pageUrl($logPage - 1), ENT_QUOTES, 'UTF-8') ?>">« Anterior</a>
        <?php else: ?>
          <span class="pager-disabled" aria-disabled="true">« Anterior</span>
        <?php endif; ?>
        <span class="pager-status">Página <?= $logPage ?> de <?= $totalPages ?></span>
        <?php if ($logPage < $totalPages): ?>
          <a class="pager-link" href="<?= htmlspecialchars($pageUrl($logPage + 1), ENT_QUOTES, 'UTF-8') ?>">Próxima »</a>
        <?php else: ?>
          <span class="pager-disabled" aria-disabled="true">Próxima »</span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php
$conteudo = (string)ob_get_clean();
require __DIR__ . '/layout.php';
