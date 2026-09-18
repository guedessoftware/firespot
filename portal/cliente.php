<?php
// /portal/cliente.php — exibe/oculta “Conectar” e mostra VIP + tempo restante
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/adsense.php';
require_once __DIR__ . '/../app/partner_hotspots.php';
// Fallback para aplicar VIP em caso de pagamentos marcados como 'paid' mas ainda não refletidos no RADIUS
if (file_exists(__DIR__ . '/api/radius_vip.php')) {
  require_once __DIR__ . '/api/radius_vip.php'; // vip_apply_radius()
}

$csrf = csrf_token();

if (!isset($_SESSION['cliente_username']) || $_SESSION['cliente_username'] === '') {
  header('Location: index.php');
  exit;
}

$username = $_SESSION['cliente_username'];
$mac = $_SESSION['hotspot_device_info']['mac'] ?? '';
$ip = $_SESSION['hotspot_device_info']['ip'] ?? '';


function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function cpf_mask($cpf)
{
  $d = preg_replace('/\D+/', '', (string) $cpf);
  if (strlen($d) !== 11)
    return $cpf;
  return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
}
function fmt_seconds($s)
{
  $s = (int) $s;
  if ($s <= 0)
    return '0m';
  $h = intdiv($s, 3600);
  $m = intdiv($s % 3600, 60);
  return $h > 0 ? ($h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm') : ($m . 'm');
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

// Nome
$nome = null;
try {
  $st = $pdo->prepare("SELECT nome FROM clientes_info WHERE cpf = ? LIMIT 1");
  $st->execute([$username]);
  $nome = $st->fetchColumn();
} catch (\Throwable $e) {
}

// Grupos (mantido)
$isProviderCustomer = false;
$hasPremium = false; // ativo (tempo > 0)
$hasPlanoPadrao = false;
$hadPremiumGroup = false; // apenas presença de grupo
try {
  $st = $pdo->prepare("SELECT groupname FROM radusergroup WHERE username=?");
  $st->execute([$username]);
  $groups = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $isProviderCustomer = in_array('ISP_UNL', $groups, true);
  $hadPremiumGroup = in_array('PREMIUM_DAY', $groups, true);
  $hasPlanoPadrao = in_array('Plano_Padrao', $groups, true);
} catch (\Throwable $e) {
}

// Best-effort: se existir pedido VIP 'paid' ainda não aplicado, aplica agora
try {
  if (function_exists('vip_apply_radius')) {
    // Verifica se a coluna vip_applied_at existe
    $colCheck = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vip_orders' AND COLUMN_NAME='vip_applied_at' LIMIT 1");
    $colCheck->execute();
    $hasVipApplied = (bool)$colCheck->fetchColumn();
    if ($hasVipApplied) {
      $st = $pdo->prepare("SELECT external_ref FROM vip_orders WHERE (username=? OR cpf=?) AND status='paid' AND vip_applied_at IS NULL ORDER BY paid_at DESC LIMIT 1");
      $st->execute([$username, $username]);
      if ($ref = $st->fetchColumn()) {
        if (empty($_SESSION['last_promoted_ref']) || $_SESSION['last_promoted_ref'] !== $ref) {
          $res = vip_apply_radius((string)$ref, []);
          $_SESSION['last_promoted_ref'] = $ref;
        }
      }
    }
  }
} catch (\Throwable $e) { /* silencioso, não bloquear UI */ }

// === NOVO: Status VIP por RADIUS (Max-All-Session - SUM(radacct)) ===
$vipAllowed = 0;
$vipUsed = 0;
$vipRemaining = 0;
$vipOnline = false;
try {
  // permitido total acumulado
  $st = $pdo->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
  $st->execute([$username]);
  $vipAllowed = (int) ($st->fetchColumn() ?: 0);

  // usado histórico
  $st = $pdo->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
  $st->execute([$username]);
  $vipUsed = (int) ($st->fetchColumn() ?: 0);

  $vipRemaining = max(0, $vipAllowed - $vipUsed);

  // online?
  $st = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE username=? AND acctstoptime IS NULL");
  $st->execute([$username]);
  $vipOnline = ((int) $st->fetchColumn()) > 0;

  // VIP ativo somente se houver saldo de tempo (independente do grupo)
  $hasPremium = $vipRemaining > 0;
} catch (\Throwable $e) {
}

// Uso diário (somente para Plano_Padrao) — janela móvel de 24h para maior compatibilidade de timezone
$dailyUsed = false;
$nextTryAt = null;
if (!$isProviderCustomer && !$hasPremium && $hasPlanoPadrao) {
  try {
    // Último início de sessão registrado (qualquer plano)
    $q = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(acctstarttime)) FROM radacct WHERE username = ?");
    $q->execute([$username]);
    $lastStartTs = (int) ($q->fetchColumn() ?: 0);

    if ($lastStartTs > 0) {
      $window = 24 * 60 * 60; // 24h
      $now = time();
      if (($now - $lastStartTs) < $window) {
        $dailyUsed = true;
        $nextTryAt = $lastStartTs + $window;
      }
    }
  } catch (\Throwable $e) {
    // ignora erros nesta métrica
  }
}

// Hotspot ctx disponível?
$hasHotspotCtx = !empty($_SESSION['hotspot_ctx']['data']['link-login-only']);

$displayName = $nome ?: cpf_mask($username);
$badge = $isProviderCustomer ? "Cliente do Provedor • acesso ilimitado"
  : ($hasPremium ? ("Acesso VIP ativo • restante: " . fmt_seconds($vipRemaining))
    : "Visitante • acesso com limites");
// Aviso one-time pós-promocao
$showProviderNotice = !empty($_SESSION['notice_provider_unl']);
if ($showProviderNotice) unset($_SESSION['notice_provider_unl']);

// Minutos do anúncio (config) — usa minutos do host atual (parceiro) quando houver contexto
$adMinutes = (int) settings_get('ad_minutes', getenv('AD_MINUTES_DEFAULT') ?: 10);
if ($adMinutes <= 0) $adMinutes = 10; if ($adMinutes > 120) $adMinutes = 120;
try {
  // Detecta parceiro atual pelo "Acesso rápido" (fast_id) armazenado na sessão
  $fastId = trim((string)($_SESSION['portal_fast_id'] ?? ''));
  if ($fastId !== '') {
    // Verifica se a tabela partners existe
    $hasPartners = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners'")->fetchColumn();
    if ($hasPartners) {
      $row=fs_partner_hotspot_resolve($pdo,$fastId,false,false);
      if(!$row){$st = $pdo->prepare("SELECT free_minutes, active FROM partners WHERE code=? LIMIT 1");$st->execute([$fastId]);$row=$st->fetch(PDO::FETCH_ASSOC)?:null;}
      if ($row) {
        if ((int)($row['active'] ?? 0) === 1) {
          $min = (int)($row['free_minutes'] ?? 0);
          if ($min > 0) { $adMinutes = $min; if ($adMinutes > 120) $adMinutes = 120; }
        }
      }
    }
  }
} catch (\Throwable $e) { /* ignora falhas de leitura do host */ }
$adCooldownMin = (int) settings_get('ad_cooldown_minutes', getenv('AD_COOLDOWN_DEFAULT') ?: 180);
if ($adCooldownMin < 0) $adCooldownMin = 0;
$adDailyCap = (int) settings_get('ad_daily_cap', getenv('AD_DAILY_CAP') ?: 0);

// Cooldown — quando o anúncio estará disponível novamente para este dispositivo
$adCooldownUntil = null; // timestamp (int) ou null
if ($hasPlanoPadrao && !$hasPremium && !$isProviderCustomer) {
  try {
    // Usa a mesma chave do backend:
    //  - MAC quando disponível;
    //  - senão DID:<cookie> se existir;
    //  - senão USR:<username>
    $macKey = '';
    if ($mac !== '') {
      $macKey = $mac;
    } elseif (!empty($_COOKIE['fs_did'])) {
      $did = preg_replace('/[^A-Fa-f0-9]/','', (string)$_COOKIE['fs_did']);
      $did = substr($did, 0, 28); // 4+28 = 32
      $macKey = 'DID:' . $did;
    } else {
      $macKey = 'USR:' . $username;
    }
    // cooldown
    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(granted_at) FROM ad_grants WHERE mac=? ORDER BY granted_at DESC LIMIT 1");
    $st->execute([$macKey]);
    $lastTs = (int) $st->fetchColumn();
    if ($lastTs) {
      $adCooldownUntil = $lastTs + ($adCooldownMin * 60);
    }
    // limite diário: se excedido, calcula próximo horário
    if ($adDailyCap > 0) {
      $st2 = $pdo->prepare("SELECT COUNT(*) FROM ad_grants WHERE mac=? AND granted_at >= (NOW() - INTERVAL 24 HOUR)");
      $st2->execute([$macKey]);
      $count24 = (int)$st2->fetchColumn();
      if ($count24 >= $adDailyCap) {
        $st3 = $pdo->prepare("SELECT UNIX_TIMESTAMP(MIN(granted_at)) FROM ad_grants WHERE mac=? AND granted_at >= (NOW() - INTERVAL 24 HOUR)");
        $st3->execute([$macKey]);
        $minTs = (int)$st3->fetchColumn();
        $nextDaily = $minTs ? ($minTs + 24*60*60) : (time() + 24*60*60);
        $adCooldownUntil = max((int)$adCooldownUntil, $nextDaily);
      }
    }
    if ($adCooldownUntil && $adCooldownUntil <= time()) $adCooldownUntil = null; // já liberado
  } catch (\Throwable $e) {
    // sem tabela ou erro — ignore
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Minha Conta</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <?= adsense_head_snippet() ?>
  <style>
    .actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    @media (max-width:700px) {
      .actions {
        grid-template-columns: 1fr;
      }
    }

    .pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: #eef2ff;
      color: #4338ca;
      font-size: 12px;
      border: 1px solid #e5e7eb;
    }

    .pill.vip {
      background: #fff7ed;
      color: #9a3412;
    }

    .pill.visit {
      background: #f1f5f9;
      color: #0f172a;
    }

    /* Added: explicit category pill for Provedor */
    .pill.prov {
      background: #ecfdf5;
      color: #065f46;
    }

    .card .row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .btn-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .option {
      padding: 12px;
      border: 1px dashed var(--border, #ddd);
      border-radius: 12px;
      background: #fafafa
    }

    .option h4 {
      margin: 0 0 6px 0
    }

    .muted {
      color: #6b7280
    }

    .option.danger {
      border-color: #fecaca;
      background: #fef2f2;
    }

    .option.danger h4 {
      color: #b91c1c;
    }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const deleteForm = document.getElementById('account-delete-form');
      const deleteBtn = document.getElementById('btn-delete-account');
      const deleteError = document.getElementById('account-delete-error');
      if (deleteForm && deleteBtn) {
        deleteForm.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          if (!confirm('Tem certeza que deseja excluir a sua conta? Esta ação não pode ser desfeita.')) {
            return;
          }
          deleteBtn.disabled = true;
          const originalLabel = deleteBtn.textContent;
          deleteBtn.textContent = 'Excluindo...';
          if (deleteError) {
            deleteError.style.display = 'none';
            deleteError.textContent = '';
          }
          try {
            const fd = new FormData(deleteForm);
            const resp = await fetch('api/account_delete.php', {
              method: 'POST',
              credentials: 'same-origin',
              body: fd
            });
            const data = await resp.json().catch(() => null);
            if (!resp.ok || !data || !data.ok) {
              const errMsg = data && data.error ? data.error : 'Falha ao excluir a conta.';
              throw new Error(errMsg);
            }
            window.location.href = 'index.php?account_deleted=1';
          } catch (error) {
            console.error(error);
            if (deleteError) {
              deleteError.textContent = 'Não foi possível concluir a exclusão: ' + (error?.message || 'erro inesperado.');
              deleteError.style.display = 'block';
            } else {
              alert('Não foi possível excluir a conta. Tente novamente em instantes.');
            }
          } finally {
            deleteBtn.disabled = false;
            deleteBtn.textContent = originalLabel;
          }
        });
      }
    });
  </script>
</head>

<body>
  <div class="header inline">
    <a href="index.php">← Portal</a>
    <strong>MINHA CONTA</strong>
    <a href="logout.php">Sair</a>
  </div>

  <div class="container">
    <div class="card">
      <?php if (!empty($showProviderNotice)): ?>
        <div class="notice" style="color:#166534;border-color:#bbf7d0;background:#ecfdf5; margin-bottom:10px;">
          Você foi identificado como <b>cliente do provedor</b>. Seu acesso ilimitado está ativo.
        </div>
      <?php endif; ?>
      <div class="row">
        <div>
          <h2 style="margin:0 0 4px 0;">Olá, <?= h($displayName) ?> 👋</h2>
          <?php
            // Added: explicit category variables and pill rendering
            $category = $isProviderCustomer ? 'Provedor' : ($hasPremium ? 'Premium' : 'Visitante');
            $catClass = $isProviderCustomer ? 'prov' : ($hasPremium ? 'vip' : 'visit');
          ?>
          <span class="pill <?= h($catClass) ?>">Categoria: <?= h($category) ?></span>
          <span class="pill <?= $isProviderCustomer ? '' : ($hasPremium ? 'vip' : 'visit') ?>">
            <?= h($badge) ?>
          </span>
        </div>
      </div>

      <p class="muted" style="margin:10px 0 16px;">
        Conecte-se ao Wi-Fi, consulte seu histórico ou gerenciar seu acesso.
      </p>

      <div class="actions">
        <!-- Minhas ações -->
        <div class="option">
          <h4>Minhas ações</h4>
          <div class="btn-row">
            <a class="btn" href="historico.php">📜 Histórico</a>
            <a class="btn" href="dispositivos.php">🧰 Meus dispositivos</a>
            


            <?php if ($isProviderCustomer): ?>
              <a class="btn primary" href="hotspot_do_login.php">✅ Conectar agora</a>

            <?php elseif ($hasPlanoPadrao && !$hasPremium): ?>
              <?php if (!$adCooldownUntil): ?>
                <a class="btn primary" href="anuncio.php">🎬 Assistir anúncio e liberar <?= h($adMinutes) ?> min</a>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn primary" href="hotspot_do_login.php">✅ Conectar agora</a>
            <?php endif; ?>

            <a class="btn" href="logout.php">🚪 Sair</a>
          </div>

          <?php if ($isProviderCustomer): ?>
            <small class="muted">Conexão automática disponível para clientes do provedor.</small>
          <?php else: ?>
            <?php if ($hasPlanoPadrao && !$hasPremium && !$isProviderCustomer && $adCooldownUntil): ?>
              <div class="notice" style="margin:10px 0;color:#1f2937;border:1px solid #e5e7eb;background:#f9fafb;">
                Anúncio disponível novamente às <b><?= h(date('H:i', $adCooldownUntil)) ?></b>.
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Acessos adicionais -->
        <div class="option">
          <h4>Acesso VIP</h4>
          <?php if ($hasPremium): ?>
            <p>Seu <b>Acesso VIP</b> está ativo.
              <?php if ($vipRemaining > 0): ?>
                <br>Tempo restante: <span class="pill vip"><?= h(fmt_seconds($vipRemaining)) ?></span>
              <?php endif; ?>
            </p>
            <div class="btn-row">
              <a class="btn success" href="hotspot_do_login.php">⚡ Conectar como VIP</a>
            </div>
          <?php else: ?>
            <?php if ($hasPlanoPadrao && $dailyUsed): ?>
              <p>⛔ Você está sem tempo/saldo hoje no Plano Padrão. Que tal liberar <b>acesso agora</b>?</p>
            <?php else: ?>
              <p>Melhore sua experiência com mais tempo e velocidade.</p>
            <?php endif; ?>
            <div class="btn-row">
              <form id="vip-launch" action="../portal/vip.php" method="post" style="display:inline;">
                <input type="hidden" name="username" value="<?= h($username) ?>">
                <input type="hidden" name="mac" value="<?= h($mac) ?>">
                <input type="hidden" name="ip" value="<?= h($ip) ?>">
              </form>
              <button id="btn-vip" class="btn success">⭐ Seja VIP:: Adiquira Tempo</button>
              <script>
                document.getElementById('btn-vip')?.addEventListener('click', () => {
                  document.getElementById('vip-launch')?.submit();
                });
              </script>
            </div>
            <small class="muted">VIP oferece mais tempo, mais velocidade e menos interrupções.</small>
          <?php endif; ?>
        </div>

        <div class="option danger">
          <h4>Excluir minha conta</h4>
          <p class="muted">Ao confirmar, removeremos suas credenciais e dispositivos associados de maneira permanente e irreversível.</p>
          <form id="account-delete-form" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" id="btn-delete-account" class="btn danger">Excluir minha conta</button>
          </form>
          <div id="account-delete-error" class="notice" style="display:none;margin-top:10px;color:#b91c1c;border-color:#fecaca;background:#fee2e2;"></div>
        </div>
      </div>

      <?php
        // Bloco pequeno AdSense no portal, se configurado
        $slotPortal = settings_get('adsense_slot_portal', getenv('ADSENSE_SLOT_PORTAL') ?: '');
        if ($slotPortal) {
      ?>
        <div class="ad-small" style="margin-top:16px;">
          <div class="muted" style="margin-bottom:6px;">Patrocinado</div>
          <?= adsense_block($slotPortal, ['style' => 'display:block; width:100%; min-height: 90px']) ?>
        </div>
      <?php } ?>

      <?php if ($hasPremium && $vipRemaining <= 0): ?>
        <div class="notice" style="margin-top:12px; color:#b91c1c; background:#fef2f2; border:1px solid #fee2e2;">
          Seu VIP está sem saldo agora. Compre um novo pacote para continuar conectado sem interrupções.
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
