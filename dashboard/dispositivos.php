<?php
// /dashboard/dispositivos.php — Admin: inventário de dispositivos (fonte: clientes_dispositivos)
// Compatível PHP 7.4 — integra com layout.php — inclui Exportar CSV (⬇️ Baixar CSV)

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_str($haystack, $needle){
  if ($needle === '') return false;
  return stripos((string)$haystack, (string)$needle) !== false;
}
function os_emoji($os){
  $t = strtolower((string)$os);
  if ($t === '') return '📱';
  if (has_str($t,'android')) return '🤖';
  if (has_str($t,'ios') || has_str($t,'ipad') || has_str($t,'mac')) return '🍎';
  if (has_str($t,'windows')) return '🪟';
  if (has_str($t,'linux')) return '🐧';
  return '📱';
}

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
} catch (\Throwable $e) {
  http_response_code(500);
  echo "Erro de conexão ao banco.";
  exit;
}

// -------- Filtros & paginação --------
$q        = trim($_GET['q'] ?? '');
$onlyAct  = isset($_GET['ativos']) ? 1 : 0;
$page     = max(1, (int)($_GET['p'] ?? 1));
$per      = min(100, max(10, (int)($_GET['per'] ?? 25)));
$offset   = ($page - 1) * $per;

$where  = "1";
$params = [];

if ($q !== '') {
  $where .= " AND (username LIKE :q OR mac LIKE :q OR COALESCE(device_label,'') LIKE :q OR COALESCE(os,'') LIKE :q OR COALESCE(browser,'') LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}
if ($onlyAct) {
  $where .= " AND is_active = 1";
}

// -------- Export CSV (respeita filtros) --------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $sqlExp = "
    SELECT
      id, username, mac, first_seen, last_seen, first_ip, last_ip, server_name,
      os, os_version, browser, browser_version, user_agent,
      device_label, visits, is_active
    FROM clientes_dispositivos
    WHERE $where
    ORDER BY last_seen DESC, id DESC";
  $stx = $pdo->prepare($sqlExp);
  foreach ($params as $k=>$v) $stx->bindValue($k, $v, PDO::PARAM_STR);
  $stx->execute();

  $filename = 'dispositivos_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo "\xEF\xBB\xBF"; // BOM UTF-8

  $out = fopen('php://output', 'w');
  fputcsv($out, [
    'id','username','mac','first_seen','last_seen','first_ip','last_ip','server_name',
    'os','os_version','browser','browser_version','user_agent','device_label','visits','is_active'
  ]);
  while ($row = $stx->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['id'], $row['username'], $row['mac'],
      $row['first_seen'], $row['last_seen'],
      $row['first_ip'], $row['last_ip'], $row['server_name'],
      $row['os'], $row['os_version'], $row['browser'], $row['browser_version'],
      $row['user_agent'], $row['device_label'], (int)$row['visits'], (int)$row['is_active'],
    ]);
  }
  fclose($out);
  exit;
}

// -------- Total e lista p/ tela --------
$sqlCount = "SELECT COUNT(*) FROM clientes_dispositivos WHERE $where";
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$sql = "
SELECT
  id, username, mac, first_seen, last_seen, first_ip, last_ip, server_name,
  os, os_version, browser, browser_version, user_agent,
  device_label, visits, is_active
FROM clientes_dispositivos
WHERE $where
ORDER BY last_seen DESC, id DESC
LIMIT :lim OFFSET :ofs";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':ofs', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -------- Integra com layout.php --------
$titulo = "Dispositivos";
$pageId = "dispositivos";

ob_start();
?>

<div class="card">
  <h3 class="devices-title">Inventário de dispositivos</h3>

  <form id="devices-filter" method="get" class="tools-wrap">
    <input id="devices-search" class="fs-input" type="text" name="q" value="<?= h($q) ?>" aria-label="Buscar dispositivos"
           placeholder="Buscar por CPF/usuário, MAC, apelido, OS ou navegador">
    <label class="fs-checkbox">
      <input type="checkbox" name="ativos" value="1" <?= $onlyAct ? 'checked' : '' ?>> somente ativos
    </label>
    <select id="devices-per-page" class="fs-select" name="per" aria-label="Itens por página">
      <?php foreach ([25,50,100] as $opt): ?>
        <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/página</option>
      <?php endforeach; ?>
    </select>
    <div class="fs-actions">
      <button class="btn" type="submit">Filtrar</button>
      <?php
        $expParams = ['q'=>$q, 'ativos'=>$onlyAct?1:null, 'per'=>$per, 'export'=>'csv'];
        $expHref = '?'.http_build_query(array_filter($expParams, function($v){ return $v!==null && $v!==''; }));
      ?>
      <?php
  $expParams = ['q'=>$q, 'ativos'=>$onlyAct?1:null, 'per'=>$per, 'export'=>'csv'];
  $expHref = '?'.http_build_query(array_filter($expParams, function($v){ return $v!==null && $v!==''; }));
?>
<a class="btn btn-export" href="<?= h($expHref) ?>" title="Baixar lista de dispositivos em CSV">⬇️ Baixar CSV</a>

      <span class="muted">Total: <?= $total ?></span>
    </div>
  </form>

  <div id="devices-root" class="table-responsive">
    <table class="tabela">
      <thead>
        <tr>
          <th>Usuário</th>
          <th>MAC</th>
          <th>Dispositivo</th>
          <th>Navegador</th>
          <th>Primeiro/Último</th>
          <th>IP (1º / último)</th>
          <th>Servidor</th>
          <th>Visitas</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9">Nenhum dispositivo encontrado.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="devices-user"><?= h($r['username']) ?></div>
              <?php if (!empty($r['device_label'])): ?>
                <div class="muted">“<?= h($r['device_label']) ?>”</div>
              <?php endif; ?>
            </td>
            <td><code><?= h($r['mac']) ?></code></td>
            <td>
              <?= os_emoji($r['os']) ?>
              <?= h($r['os'] ?: '—') ?>
              <?php if ($r['os_version']): ?><small class="muted"> <?= h($r['os_version']) ?></small><?php endif; ?>
            </td>
            <td>
              <?= h($r['browser'] ?: '—') ?>
              <?php if ($r['browser_version']): ?><small class="muted"> <?= h($r['browser_version']) ?></small><?php endif; ?>
            </td>
            <td>
              <div><small class="muted">1º:</small> <?= h($r['first_seen']) ?></div>
              <div><small class="muted">últ.:</small> <?= h($r['last_seen']) ?></div>
            </td>
            <td>
              <div><?= h($r['first_ip'] ?: '—') ?></div>
              <div class="muted"><?= h($r['last_ip'] ?: '—') ?></div>
            </td>
            <td><?= h($r['server_name'] ?: '—') ?></td>
            <td><?= (int)$r['visits'] ?></td>
            <td>
              <?php if ((int)$r['is_active'] === 1): ?>
                <span class="badge devices-badge devices-badge--active">Ativo</span>
              <?php else: ?>
                <span class="badge devices-badge devices-badge--inactive">Inativo</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="devices-pagination">
    <?php
      $base = '?'.http_build_query(['q'=>$q,'per'=>$per,'ativos'=>$onlyAct?1:null]);
      $prev = $page>1 ? $page-1 : 1;
      $next = $page<$pages ? $page+1 : $pages;
    ?>
    <a class="btn" href="<?= $base.'&p=1' ?>">«</a>
    <a class="btn" href="<?= $base.'&p='.$prev ?>">‹</a>
    <span class="muted">página <?= $page ?> / <?= $pages ?></span>
    <a class="btn" href="<?= $base.'&p='.$next ?>">›</a>
    <a class="btn" href="<?= $base.'&p='.$pages ?>">»</a>
  </div>
  <?php endif; ?>
</div>
<?php
$conteudo = ob_get_clean();
include "layout.php";
