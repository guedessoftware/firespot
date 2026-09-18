<?php
// Integração VIP via RADIUS (FreeRADIUS) — NUNCA sobrescreve a senha do usuário.
// - Usa Max-All-Session para tempo de conexão (somatório em radacct)
// - Atualiza Mikrotik-Rate-Limit / Acct-Interim-Interval
// - Persiste username na vip_orders para reuso
// Requer: app/db.php

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/public_url.php';

function envv($k, $def = null)
{
  if (function_exists('env'))
    return env($k, $def);
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $def;
}

function rad_db(): PDO
{
  static $pdo = null;
  if ($pdo instanceof PDO)
    return $pdo;
  $h = getenv('RADIUS_DB_HOST');
  $n = getenv('RADIUS_DB_NAME');
  $u = getenv('RADIUS_DB_USER');
  $p = getenv('RADIUS_DB_PASS');
  if (!$h || !$n)
    throw new RuntimeException('RADIUS_DB_* não configurado. Defina RADIUS_DB_HOST/NAME/USER/PASS.');
  $dsn = "mysql:host={$h};dbname={$n};charset=utf8mb4";
  $pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  // sanity check
  foreach (['radcheck', 'radreply', 'radacct'] as $t) {
    $q = $pdo->query("SHOW TABLES LIKE '" . $t . "'");
    if (!$q->fetchColumn())
      throw new RuntimeException('Tabela ausente no DB RADIUS: ' . $t);
  }
  return $pdo;
}

function radius_find_check(PDO $pdo, string $user, string $attr)
{
  $st = $pdo->prepare("SELECT id,op,value FROM radcheck WHERE username=? AND attribute=? LIMIT 1");
  $st->execute([$user, $attr]);
  return $st->fetch();
}

function radius_upsert_check(PDO $pdo, string $user, string $attr, string $op, $val): void
{
  $st = $pdo->prepare("SELECT id FROM radcheck WHERE username=? AND attribute=? LIMIT 1");
  $st->execute([$user, $attr]);
  if ($r = $st->fetch()) {
    // *** IMPORTANTE: NÃO alterar Cleartext-Password existente ***
    if (strcasecmp($attr, 'Cleartext-Password') === 0)
      return; // preserva
    $upd = $pdo->prepare("UPDATE radcheck SET op=?, value=? WHERE id=?");
    $upd->execute([$op, (string) $val, (int) $r['id']]);
  } else {
    $ins = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,?,?)");
    $ins->execute([$user, $attr, $op, (string) $val]);
  }
}

function radius_get_or_set_password(PDO $pdo, string $user, ?string $preferred = null): string
{
  $row = radius_find_check($pdo, $user, 'Cleartext-Password');
  if ($row && isset($row['value']) && $row['value'] !== '')
    return (string) $row['value'];
  // não existe: define UMA vez
  $pwd = ($preferred && $preferred !== '') ? $preferred : substr(bin2hex(random_bytes(8)), 0, 12);
  $ins = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)");
  $ins->execute([$user, 'Cleartext-Password', $pwd]);
  return $pwd;
}

function radius_add_seconds(PDO $pdo, string $user, int $seconds): int
{
  $st = $pdo->prepare("SELECT id, value FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
  $st->execute([$user]);
  if ($r = $st->fetch()) {
    $new = max(0, (int) $r['value']) + max(0, $seconds);
    $upd = $pdo->prepare("UPDATE radcheck SET value=? WHERE id=?");
    $upd->execute([$new, (int) $r['id']]);
    return $new;
  } else {
    $ins = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)");
    $ins->execute([$user, 'Max-All-Session', (string) max(0, $seconds)]);
    return max(0, $seconds);
  }
}

function radius_upsert_reply(PDO $pdo, string $user, string $attr, string $op, $val): void
{
  $st = $pdo->prepare("SELECT id FROM radreply WHERE username=? AND attribute=? LIMIT 1");
  $st->execute([$user, $attr]);
  if ($r = $st->fetch()) {
    $upd = $pdo->prepare("UPDATE radreply SET op=?, value=? WHERE id=?");
    $upd->execute([$op, (string) $val, (int) $r['id']]);
  } else {
    $ins = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?,?,?,?)");
    $ins->execute([$user, $attr, $op, (string) $val]);
  }
}

/**
 * Gera link de conexão via token (conect.php?id=...) com fallback.
 * Assinatura mantida; $password é ignorado (compatibilidade).
 *
 * Ordem:
 *  1) Helper local lt_create_token() se existir (sem HTTP)
 *  2) API /portal/api/login_token_create.php (se houver sessão/CSRF)
 *  3) Fallback DB direto em login_tokens
 * Nunca devolve credenciais RADIUS na URL. Se o token não puder ser criado,
 * a conexão deve permanecer pendente para retomada segura no portal.
 */
function build_hotspot_login_url(string $username, string $password = ''): string
{
  // --- helpers locais ---
  $envv = function (string $k, $def = null) {
    if (function_exists('env'))
      return env($k, $def);
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $def;
  };
  $mkBase = function () use ($envv) {
    $base = (string) $envv('PORTAL_BASE_URL', '');
    return $base === '' ? fs_public_base_url(db()) : fs_normalize_public_base_url($base);
  };
  $genCode = function (int $len = 8): string {
    $n = '';
    for ($i = 0; $i < $len; $i++)
      $n .= (string) random_int(0, 9);
    if ($n[0] === '0')
      $n[0] = (string) random_int(1, 9);
    return $n;
  };

  // saneia user (CPF)
  $u = preg_replace('/\D+/', '', $username);
  if ($u === '')
    return '';

  // TTL 1..30 (default 10)
  $ttl = (int) $envv('LOGIN_TOKEN_TTL_MIN', 10);
  if ($ttl < 1)
    $ttl = 10;
  if ($ttl > 30)
    $ttl = 30;

  // IP/MAC opcionais da sessão (se houver)
  if (session_status() === PHP_SESSION_NONE)
    @session_start();
  $ip = $_SESSION['hotspot_device_info']['ip'] ?? null;
  $mac = $_SESSION['hotspot_device_info']['mac'] ?? null;

  // =========================
  // 1) HELPER LOCAL (se existir)
  // =========================
  try {
    if (!function_exists('lt_create_token')) {
      $helper = __DIR__ . '/../app/login_token.php';
      if (is_file($helper))
        require_once $helper;
    }
    if (function_exists('lt_create_token')) {
      // lt_create_token deve retornar ['code'=>..., 'link'=>...]
      $tok = lt_create_token($u, $ttl, ['ip' => $ip, 'mac' => $mac]);
      if (is_array($tok) && !empty($tok['link'])) {
        return (string) $tok['link'];
      }
    }
  } catch (\Throwable $e) {
    // error_log('lt_create_token falhou: '.$e->getMessage());
  }

  // =========================
  // 2) API HTTP (dashboard)
  // =========================
  try {
    $base = $mkBase();
    $api = $base . '/portal/api/login_token_create.php';
    $payload = ['username' => $u, 'ttl_min' => $ttl];
    if ($ip)
      $payload['ip'] = $ip;
    if ($mac)
      $payload['mac'] = $mac;

    $hdrs = ['Content-Type: application/json'];
    if (!empty($_SESSION['csrf'])) {
      $hdrs[] = 'X-CSRF-Token: ' . $_SESSION['csrf'];
    }

    $ch = curl_init($api);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $hdrs,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_SSL_VERIFYPEER => true,
      // Se houver sessão web, envia cookie; em webhook/CLI isso é inócuo
      CURLOPT_COOKIE => 'PHPSESSID=' . session_id(),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($resp !== false && $code === 200) {
      $j = json_decode($resp, true);
      if (is_array($j) && !empty($j['ok']) && !empty($j['link'])) {
        return (string) $j['link'];
      }
    }
  } catch (\Throwable $e) {
    // error_log('API login_token_create falhou: '.$e->getMessage());
  }

  // =========================
  // 3) FALLBACK DB DIRETO (webhook/CLI)
  // =========================
  try {
    if (!function_exists('db')) {
      require_once __DIR__ . '/../app/db.php';
    }
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // gera code único
    $code = null;
    for ($t = 0; $t < 5; $t++) {
      $try = $genCode(8);
      $st = $pdo->prepare('SELECT 1 FROM login_tokens WHERE code=?');
      $st->execute([$try]);
      if (!$st->fetchColumn()) {
        $code = $try;
        break;
      }
    }
    if (!$code)
      throw new \RuntimeException('falha ao gerar code');

    // insere token (ajuste colunas conforme seu schema)
    $sql = 'INSERT INTO login_tokens (code, username, ip, mac, expires_at, created_at)
        VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), UTC_TIMESTAMP())';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code, $u, $ip, $mac, $ttl]);


    $base = $mkBase();
  return $base . '/portal/conect.php?id=' . rawurlencode($code) . '&open=1';

  } catch (\Throwable $e) {
    // error_log('fallback DB token falhou: '.$e->getMessage());
  }

  error_log('[radius_vip] login_token_unavailable');
  return '';
}


function vip_apply_radius(string $external_ref, array $opts = []): array
{
  $app = db();
  $app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $external_ref = trim($external_ref);
  if ($external_ref === '') {
    return ['ok' => false, 'err' => 'Referência ausente'];
  }

  $hasAppliedColumn = false;
  try {
    $col = $app->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vip_orders' AND COLUMN_NAME='vip_applied_at'");
    $col->execute();
    $hasAppliedColumn = (bool)$col->fetchColumn();
  } catch (Throwable $e) {
  }
  if (!$hasAppliedColumn) {
    return ['ok' => false, 'err' => 'A coluna vip_applied_at é obrigatória para uma liberação idempotente.'];
  }

  $app->beginTransaction();
  try {
    // Bloqueia a ordem durante toda a concessão para impedir duas aplicações concorrentes.
    $q = $app->prepare("SELECT o.*, p.duracao_min, p.down_kbps, p.up_kbps FROM vip_orders o LEFT JOIN planos p ON p.id=o.plano_id WHERE o.external_ref=? LIMIT 1 FOR UPDATE");
    $q->execute([$external_ref]);
    $order = $q->fetch();
    if (!$order) {
      $app->rollBack();
      return ['ok' => false, 'err' => 'Ordem não encontrada'];
    }
    if ((string)($order['status'] ?? '') !== 'paid') {
      $app->rollBack();
      return ['ok' => false, 'err' => 'Pagamento ainda não confirmado.'];
    }

    $legacyAlreadyApplied = empty($order['vip_applied_at']) && trim((string)($order['username'] ?? '')) !== '';
    if (!empty($order['vip_applied_at']) || $legacyAlreadyApplied) {
      if ($legacyAlreadyApplied) {
        $app->prepare("UPDATE vip_orders SET vip_applied_at=COALESCE(paid_at, updated_at, NOW()), updated_at=NOW() WHERE external_ref=?")
          ->execute([$external_ref]);
      }
      $app->commit();
      $rad = rad_db();
      $username = trim((string)($order['username'] ?? ''));
      $password = $username !== '' ? radius_get_or_set_password($rad, $username, null) : '';
      return [
        'ok' => true,
        'already_applied' => true,
        'username' => $username,
        'password' => $password,
        'added_seconds' => 0,
        'login_url' => $username !== '' ? build_hotspot_login_url($username, $password) : '',
      ];
    }
  } catch (Throwable $e) {
    if ($app->inTransaction()) {
      $app->rollBack();
    }
    return ['ok' => false, 'err' => 'Pedido: ' . $e->getMessage()];
  }

  $minutes = (int) ($order['duracao_min'] ?? 0);
  if ($minutes <= 0)
    $minutes = 1440;
  $add_sec = $minutes * 60;

  // Username estável: prioriza o já salvo na ordem
  $username = trim((string) ($order['username'] ?? ''));
  $cpf = preg_replace('/\D+/', '', (string) ($order['cpf'] ?? ''));
  $phone = preg_replace('/\D+/', '', (string) ($order['telefone'] ?? ''));
  if ($username === '')
    $username = $cpf ?: ($phone ?: ('vip' . substr($external_ref, -6)));

  try {
    $rad = rad_db();
    $rad->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    if ($app->inTransaction()) {
      $app->rollBack();
    }
    return ['ok' => false, 'err' => 'RADIUS: ' . $e->getMessage()];
  }
  $rad->beginTransaction();
  try {
    // *** NUNCA sobrescreve senha existente ***
    $password = radius_get_or_set_password($rad, $username, null);

    // crédito de tempo
    $total_allowed = radius_set_remaining($rad, $username, $add_sec);

    // perfil do plano
    $down = (int) ($order['down_kbps'] ?? 0);
    $up = (int) ($order['up_kbps'] ?? 0);
    if ($down > 0 || $up > 0) {
      $rate = ($down > 0 ? $down . 'k' : '') . '/' . ($up > 0 ? $up . 'k' : '');
      radius_upsert_reply($rad, $username, 'Mikrotik-Rate-Limit', ':=', $rate);
    }
    // interims de 60s
    radius_upsert_reply($rad, $username, 'Acct-Interim-Interval', ':=', '60');

    $rad->commit();
  } catch (Throwable $e) {
    if ($rad->inTransaction())
      $rad->rollBack();
    if ($app->inTransaction())
      $app->rollBack();
    return ['ok' => false, 'err' => 'RADIUS: ' . $e->getMessage()];
  }

  try {
    // Persiste a aplicação somente depois de o RADIUS confirmar a transação.
    $app->prepare("UPDATE vip_orders SET username=?, vip_applied_at=NOW(), updated_at=NOW() WHERE external_ref=? AND status='paid' AND vip_applied_at IS NULL")
      ->execute([$username, $external_ref]);
    $app->commit();
  } catch (Throwable $e) {
    if ($app->inTransaction()) {
      $app->rollBack();
    }
    return ['ok' => false, 'err' => 'Crédito aplicado no RADIUS, mas a confirmação local falhou. Não repita manualmente; verifique a auditoria.'];
  }

  // marca cliente VIP no app (apenas indicador visual)
  try {
    $cli_id = null;
    $st = null;
    if ($cpf) {
      $st = $app->prepare("SELECT id FROM clientes_info WHERE cpf=? LIMIT 1");
      $st->execute([$cpf]);
      $r = $st->fetch();
      if ($r)
        $cli_id = (int) $r['id'];
    }
    if (!$cli_id && $phone) {
      $st = $app->prepare("SELECT id FROM clientes_info WHERE telefone=? LIMIT 1");
      $st->execute([$phone]);
      $r = $st->fetch();
      if ($r)
        $cli_id = (int) $r['id'];
    }
    if ($cli_id) {
      $app->prepare("UPDATE clientes_info SET vip_ativo=1 WHERE id=?")->execute([$cli_id]);
    }
  } catch (Throwable $e) { /* não crítico */
  }

  $login_url = build_hotspot_login_url($username, $password);

  return ['ok' => true, 'username' => $username, 'password' => $password, 'added_seconds' => $add_sec, 'total_allowed' => isset($total_allowed) ? $total_allowed : null, 'login_url' => $login_url];
}

// Acrescenta a nova compra preservando o saldo não consumido.
// A idempotência por pedido é garantida por vip_applied_at em vip_apply_radius().
function radius_set_remaining(PDO $rad, string $user, int $grant_seconds): int
{
  // quanto já foi usado (histórico)
  $st = $rad->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
  $st->execute([$user]);
  $used = (int) $st->fetchColumn();

  // Preserva o saldo ainda não consumido e acrescenta apenas a nova compra.
  $st = $rad->prepare("SELECT id, CAST(value AS UNSIGNED) AS allowed FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
  $st->execute([$user]);
  if ($r = $st->fetch()) {
    $currentAllowed = max(0, (int)($r['allowed'] ?? 0));
    $remaining = max(0, $currentAllowed - $used);
    $allowed = $used + $remaining + max(0, $grant_seconds);
    $upd = $rad->prepare("UPDATE radcheck SET value=? WHERE id=?");
    $upd->execute([$allowed, (int) $r['id']]);
  } else {
    $allowed = $used + max(0, $grant_seconds);
    $ins = $rad->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)");
    $ins->execute([$user, 'Max-All-Session', (string) $allowed]);
  }
  return $allowed;
}
