<?php
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';

$username = $_SESSION['cliente_username'] ?? '';
if ($username === '') {
  header("Location: index.php");
  exit;
}

function fmtB($n)
{
  $n = (float) $n;
  $u = ['B', 'KB', 'MB', 'GB', 'TB'];
  $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) {
    $n /= 1024;
    $i++;
  }
  return number_format($n, 2, ',', '.') . ' ' . $u[$i];
}
function fmtDT($s)
{
  if (!$s)
    return '—';
  $t = strtotime($s);
  if ($t <= 0)
    return '—';
  return date('d/m/Y H:i', $t);
}

$rows = [];
try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  $st = $pdo->prepare("
    SELECT
      acctstarttime, acctstoptime,
      nasipaddress, framedipaddress, callingstationid,
      acctinputoctets, acctoutputoctets
    FROM radacct
    WHERE username = ?
    ORDER BY acctstarttime DESC
    LIMIT 20
  ");
  $st->execute([$username]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
  $rows = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Histórico</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    /* Wrapper com rolagem horizontal no desktop se precisar */
    .table-wrap {
      overflow-x: auto;
    }

    /* Aparência padrão (desktop) usa a tabela normal */
    .table {
      width: 100%;
      border-collapse: collapse
    }

    .table th,
    .table td {
      border-bottom: 1px solid var(--border, #e5e7eb);
      padding: 10px 8px;
      text-align: left;
    }

    .table thead th {
      color: var(--muted, #6b7280);
      font-weight: 600;
      font-size: 13px;
      white-space: nowrap;
    }

    .table tbody tr:hover {
      background: rgba(0, 0, 0, .04);
    }

    @media (prefers-color-scheme: dark) {
      .table tbody tr:hover {
        background: rgba(255, 255, 255, .06);
      }
    }

    /* Chips pequenos */
    .chip {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 12px;
      line-height: 1;
      border: 1px solid var(--border, #e5e7eb);
      background: var(--card, #fff);
      color: var(--text, #111);
    }

    .chip.live {
      background: #ecfdf5;
      border-color: #a7f3d0;
      color: #065f46;
    }

    .muted {
      color: var(--muted, #6b7280);
    }

    /* ===== Mobile-first: transforma linhas em cards ===== */
    @media (max-width: 720px) {
      .table thead {
        display: none;
      }

      .table,
      .table tbody,
      .table tr,
      .table td {
        display: block;
        width: 100%;
      }

      .table tr {
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 12px;
        padding: 10px;
        margin-bottom: 12px;
        background: var(--card, #fff);
      }

      .table td {
        border: 0;
        padding: 6px 0;
        display: flex;
        justify-content: space-between;
        gap: 10px;
      }

      .table td::before {
        content: attr(data-label);
        color: var(--muted, #6b7280);
        min-width: 42%;
        font-size: 13px;
      }
    }
  </style>
</head>

<body>
  <div class="header inline">
    <a href="cliente.php">← Minha Conta</a>
    <strong>Histórico de conexões</strong>
    <span></span>
  </div>

  <div class="container">
    <div class="card">
      <?php if (empty($rows)): ?>
        <div class="muted">Sem registros.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Início</th>
                <th>Término</th>
                <th>IP</th>
                <th>NAS</th>
                <th>MAC</th>
                <th>Download</th>
                <th>Upload</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $isLive = empty($r['acctstoptime']);
                $endCol = $isLive ? '<span class="chip live">em andamento</span>' : htmlspecialchars(fmtDT($r['acctstoptime']));
                ?>
                <tr>
                  <td data-label="Início"><?= htmlspecialchars(fmtDT($r['acctstarttime'])) ?></td>
                  <td data-label="Término"><?= $endCol ?></td>
                  <td data-label="IP"><?= htmlspecialchars($r['framedipaddress'] ?: '—') ?></td>
                  <td data-label="NAS"><?= htmlspecialchars($r['nasipaddress'] ?: '—') ?></td>
                  <td data-label="MAC"><?= htmlspecialchars($r['callingstationid'] ?: '—') ?></td>
                  <td data-label="Download"><?= fmtB($r['acctoutputoctets']) ?></td>
                  <td data-label="Upload"><?= fmtB($r['acctinputoctets']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <small class="muted">Exibindo as últimas <?= count($rows) ?> sessões.</small>
      <?php endif; ?>
    </div>
  </div>
</body>

</html>