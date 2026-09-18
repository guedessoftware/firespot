<?php
// /portal/dispositivos.php — Portal: dispositivos do usuário + edição de apelido (mobile-first, PHP 7.4)
require_once __DIR__ . '/../app/session_boot.php';
if (!isset($_SESSION['cliente_username']) || $_SESSION['cliente_username'] === '') {
  header('Location: index.php');
  exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

$csrf = csrf_token();
$username = $_SESSION['cliente_username'];

function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function has_str($haystack, $needle)
{
  if ($needle === '')
    return false;
  return stripos((string) $haystack, (string) $needle) !== false;
}
function os_emoji($os)
{
  $t = strtolower((string) $os);
  if ($t === '')
    return '📱';
  if (has_str($t, 'android'))
    return '🤖';
  if (has_str($t, 'ios') || has_str($t, 'ipad') || has_str($t, 'mac'))
    return '🍎';
  if (has_str($t, 'windows'))
    return '🪟';
  if (has_str($t, 'linux'))
    return '🐧';
  return '📱';
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

// Atualização de apelido
$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'label') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Requisição inválida.';
  } else {
    $id = (int) ($_POST['id'] ?? 0);
    $label = trim((string) ($_POST['device_label'] ?? ''));
    if ($id <= 0) {
      $error = 'Item inválido.';
    } else {
      try {
        $chk = $pdo->prepare("SELECT id FROM clientes_dispositivos WHERE id=? AND username=? LIMIT 1");
        $chk->execute([$id, $username]);
        if (!$chk->fetchColumn()) {
          $error = 'Dispositivo não encontrado.';
        } else {
          if ($label === '')
            $label = null;
          $up = $pdo->prepare("UPDATE clientes_dispositivos SET device_label=? WHERE id=? LIMIT 1");
          $up->execute([$label, $id]);
          $notice = 'Apelido atualizado.';
        }
      } catch (\Throwable $e) {
        $error = 'Erro ao salvar.';
      }
    }
  }
}

// Lista + status online (normaliza MAC nos dois lados)
$sql = "
SELECT 
  d.id, d.mac, d.os, d.os_version, d.browser, d.browser_version,
  d.first_seen, d.last_seen, d.first_ip, d.last_ip, d.server_name,
  d.device_label, d.visits,
  CASE WHEN ro.online > 0 THEN 1 ELSE 0 END AS online_now
FROM clientes_dispositivos d
LEFT JOIN (
  SELECT username,
         REPLACE(REPLACE(UPPER(callingstationid), '-', ''), ':','') AS mac_norm,
         COUNT(*) AS online
  FROM radacct
  WHERE (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00')
    AND username = ?
  GROUP BY username, mac_norm
) ro ON ro.username = ?
     AND ro.mac_norm = REPLACE(REPLACE(UPPER(d.mac), '-', ''), ':','')
WHERE d.username = ?
ORDER BY d.last_seen DESC";
$st = $pdo->prepare($sql);
$st->execute([$username, $username, $username]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meus Dispositivos</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    /* Layout mobile-first */
    .dev-list {
      display: grid;
      gap: 12px;
    }

    .dev-card {
      border: 1px solid var(--borda, #ddd);
      border-radius: 12px;
      padding: 12px;
      background: var(--card, #fff);
    }

    .row-top {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
    }

    .row-bot {
      display: grid;
      gap: 8px;
      margin-top: 10px;
    }

    .muted {
      color: #6b7280
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 2px 8px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      font-size: 12px;
    }

    .badge.online {
      background: #ecfdf5;
      color: #065f46;
      border-color: #a7f3d0;
    }

    .badge.off {
      background: #f8fafc;
      color: #334155;
    }

    .kv {
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: 6px;
    }

    .kv b {
      font-weight: 600
    }

    .label-form {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
    }

    .label-form input[type="text"] {
      min-width: 220px;
    }

    code {
      background: #f3f4f6;
      padding: 1px 6px;
      border-radius: 6px;
    }

    @media (min-width: 720px) {
      .dev-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (min-width: 1080px) {
      .dev-list {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
  </style>
</head>

<body>
  <div class="header inline">
    <a href="cliente.php">← Minha Conta</a>
    <strong>Meus Dispositivos</strong>
    <span></span>
  </div>

  <div class="container">
    <?php if ($notice): ?>
      <div class="notice" style="color:#065f46;border-color:#a7f3d0;background:#ecfdf5; margin-bottom:10px;">
        <?= h($notice) ?></div>
    <?php elseif ($error): ?>
      <div class="notice" style="color:#b91c1c;border-color:#fecaca;background:#fee2e2; margin-bottom:10px;">
        <?= h($error) ?></div>
    <?php endif; ?>

    <div class="dev-list">
      <?php if (!$rows): ?>
        <div class="dev-card">
          <div style="font-weight:700">Nenhum dispositivo registrado ainda.</div>
          <p class="muted" style="margin:6px 0 0 0;">Ao conectar-se ao Wi-Fi, seu dispositivo será registrado
            automaticamente aqui.</p>
        </div>
      <?php else:
        foreach ($rows as $r): ?>
          <div class="dev-card">
            <div class="row-top">
              <div>
                <div style="font-weight:700">
                  <?= os_emoji($r['os']) ?>     <?= h($r['os'] ?: 'Dispositivo') ?>
                  <?php if ($r['os_version']): ?><small class="muted"> <?= h($r['os_version']) ?></small><?php endif; ?>
                </div>
                <div class="muted">
                  <?= h($r['browser'] ?: 'Navegador') ?>
                  <?php if ($r['browser_version']): ?> <small> <?= h($r['browser_version']) ?></small><?php endif; ?>
                </div>
              </div>
              <div>
                <?php if ((int) $r['online_now'] === 1): ?>
                  <span class="badge online">🟢 Online agora</span>
                <?php else: ?>
                  <span class="badge off">⚪ Offline</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="row-bot">
              <div class="kv">
                <b>MAC</b> <span><code><?= h($r['mac']) ?></code></span>
                <b>Primeiro acesso</b> <span><?= h($r['first_seen']) ?></span>
                <b>Último acesso</b> <span><?= h($r['last_seen']) ?></span>
                <b>IP (último)</b> <span><?= h($r['last_ip'] ?: '—') ?></span>
                <b>Servidor</b> <span><?= h($r['server_name'] ?: '—') ?></span>
                <b>Visitas</b> <span><strong><?= (int) $r['visits'] ?></strong></span>
              </div>

              <form method="post" class="label-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="label">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <label>Apelido:
                  <input type="text" name="device_label" value="<?= h((string) $r['device_label']) ?>" maxlength="150"
                    placeholder="ex.: iPhone do João">
                </label>
                <button class="btn">Salvar</button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
    </div>
  </div>
</body>

</html>