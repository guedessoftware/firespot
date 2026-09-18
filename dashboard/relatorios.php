<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

$titulo = 'Relatórios';
$pageId = 'relatorios';

// KPIs adicionais para relatórios
$totalUsuarios = (int) $pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute='Cleartext-Password'")->fetchColumn();
$onlineAgora = (int) $pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
$conexoesHoje = (int) $pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstarttime >= CURRENT_DATE()")->fetchColumn();

$topParceiros = [];
$hostRankingDays = 30;
$hasPartnerUses = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_uses'")->fetchColumn();
$hasPartners = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
$partnerLabels = [];
if ($hasPartners) {
  $stPartners = $pdo->query('SELECT code, name FROM partners');
  while ($row = $stPartners->fetch(PDO::FETCH_ASSOC)) {
    $code = strtoupper(trim((string)($row['code'] ?? '')));
    if ($code === '') {
      continue;
    }
    $partnerLabels[$code] = trim((string)($row['name'] ?? ''));
  }
}

if ($hasPartnerUses) {
  $sql = "
    SELECT UPPER(pu.code) AS code_upper,
           COUNT(*) AS total
      FROM partner_uses pu
     WHERE pu.used_at >= (NOW() - INTERVAL {$hostRankingDays} DAY)
     GROUP BY code_upper
    HAVING code_upper IS NOT NULL AND code_upper <> ''
     ORDER BY total DESC
     LIMIT 5
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    $code = strtoupper(trim((string)($row['code_upper'] ?? '')));
    if ($code === '') {
      continue;
    }
    $topParceiros[] = [
      'code' => $code,
      'partner_name' => $partnerLabels[$code] ?? '',
      'total' => (int)($row['total'] ?? 0),
    ];
  }
}

if (!$topParceiros) {
  $sql = "
    SELECT UPPER(SUBSTRING_INDEX(r.nasportid, '-', -1)) AS host_code,
           COUNT(*) AS total
      FROM radacct r
     WHERE r.acctstarttime >= DATE_SUB(NOW(), INTERVAL {$hostRankingDays} DAY)
       AND r.nasportid IS NOT NULL
       AND r.nasportid <> ''
     GROUP BY host_code
    HAVING host_code IS NOT NULL AND host_code <> ''
     ORDER BY total DESC
     LIMIT 5
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    $code = strtoupper(trim((string)($row['host_code'] ?? '')));
    if ($code === '') {
      continue;
    }
    $topParceiros[] = [
      'code' => $code,
      'partner_name' => $partnerLabels[$code] ?? '',
      'total' => (int)($row['total'] ?? 0),
    ];
  }
}

ob_start();
?>

<div class="card reports-separated-card">
  <h3 class="reports-card-title">Resumo Operacional</h3>
  <div class="content-grid reports-kpi-grid">
    <div class="kpi-small">
      <strong><?= number_format($totalUsuarios, 0, ',', '.') ?></strong>
      <span class="muted">Usuários ativos</span>
    </div>
    <div class="kpi-small">
      <strong><?= number_format($onlineAgora, 0, ',', '.') ?></strong>
      <span class="muted">Conectados agora</span>
    </div>
    <div class="kpi-small">
      <strong><?= number_format($conexoesHoje, 0, ',', '.') ?></strong>
      <span class="muted">Conexões hoje</span>
    </div>
  </div>
</div>

<!-- Indicadores agregados de estabelecimentos e infraestrutura. -->
<div class="charts-grid mt-16">
  <figure class="card chart-card">
    <h3 class="chart-title">Acessos por estabelecimento (últimos 30 dias)</h3>
    <div class="chart-controls reports-chart-controls">
      <label for="periodo-host">Período:</label>
      <select id="periodo-host">
        <option value="7">7 dias</option>
        <option value="15">15 dias</option>
        <option value="30" selected>30 dias</option>
        <option value="60">60 dias</option>
        <option value="90">90 dias</option>
      </select>
      <button type="button" id="reports-chart-refresh" class="btn btn-sm">Atualizar</button>
    </div>
    <div class="chart-wrap" role="img" aria-label="Gráfico de barras com número de acessos por estabelecimento">
      <canvas id="chartAcessosHost"></canvas>
    </div>
    <figcaption class="chart-caption">
      <p class="chart-desc">
        Comparativo agregado entre estabelecimentos no período selecionado, sem inventário global de pontos.
      </p>
    </figcaption>
  </figure>

  <figure class="card chart-card">
    <h3 class="chart-title">Acessos por NAS (últimos 30 dias)</h3>
    <div class="chart-wrap" role="img" aria-label="Gráfico de pizza com distribuição de acessos por NAS">
      <canvas id="chartAcessosNas"></canvas>
    </div>
    <figcaption class="chart-caption">
      <p class="chart-desc">
        Distribuição de acessos por servidor NAS no período selecionado.
      </p>
    </figcaption>
  </figure>
</div>

<div class="card reports-separated-card">
  <h3 class="reports-card-title">Estabelecimentos com mais acessos (30 dias)</h3>
  <?php if (!$topParceiros): ?>
    <p class="muted">Ainda não há registros suficientes para este relatório.</p>
  <?php else: ?>
    <table class="tabela">
      <thead>
        <tr>
              <th>Estabelecimento (código e nome)</th>
          <th>Total de liberações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topParceiros as $row): ?>
          <?php $partnerName = trim($row['partner_name'] ?? ''); ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($row['code']) ?></strong>
              <?php if ($partnerName !== ''): ?>
                <br><small class="muted"><?= htmlspecialchars($partnerName) ?></small>
              <?php endif; ?>
            </td>
            <td><?= number_format((int) $row['total'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
include 'layout.php';
