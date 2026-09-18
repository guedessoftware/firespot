<?php
// /hotspot/dashboard/api/users_export.php
// Exporta usuários (CSV) sem duplicatas, respeitando filtros da lista.
require_once __DIR__ . '/../../app/session_boot.php';
if (empty($_SESSION['admin'])) { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/../../app/db.php';

function allow($v,$opts,$def){ $v=strtolower((string)$v); return in_array($v,$opts,true)?$v:$def; }

$q      = trim($_GET['q'] ?? '');
$qDigits = preg_replace('/\D+/', '', $q);
$group  = trim($_GET['group'] ?? '');
$online = trim($_GET['online'] ?? ''); // ''|online|offline
$order  = allow($_GET['order'] ?? 'last_seen', ['last_seen','username','nome','visits30'],'last_seen');
$dir    = allow($_GET['dir']   ?? 'desc',      ['asc','desc'], 'desc');

$orderSql = [
  'last_seen' => 'l.last_seen',
  'username'  => 'u.username',
  'nome'      => 'ci.nome',
  'visits30'  => 'v.v30',
][$order] . ' ' . strtoupper($dir);

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $hasPhoneFilter = false;

  $baseUsersSql = "SELECT DISTINCT username FROM radcheck";

  // BASE CANÔNICA: um username por linha (radcheck ∩ clientes_info)
  // Evita duplicações causadas por múltiplos atributos no radcheck
  $sql = "
    SELECT
      u.username,
      ci.nome,
      ci.telefone,
      ci.email,
      ci.sexo,
      COALESCE(g.groups, '')                      AS groups,
      COALESCE(onl.online_cnt, 0)                 AS online_cnt,
      COALESCE(lim.allowed, 0)                    AS allowed,     -- segundos
      COALESCE(ua.used, 0)                        AS used,        -- segundos
      GREATEST(COALESCE(lim.allowed,0)-COALESCE(ua.used,0),0) AS remaining,
      COALESCE(v.v30, 0)                          AS visits30,
      l.last_seen
    FROM (
      %BASE_USERS%
    ) u
    INNER JOIN clientes_info ci
      ON ci.cpf = u.username

    /* agregados por usuário, cada um retorna UMA linha por username */
    LEFT JOIN (
      SELECT username, CAST(value AS UNSIGNED) AS allowed
      FROM radcheck
      WHERE attribute='Max-All-Session'
    ) lim ON lim.username = u.username

    LEFT JOIN (
      SELECT username, SUM(acctsessiontime) AS used
      FROM radacct
      GROUP BY username
    ) ua ON ua.username = u.username

    LEFT JOIN (
      SELECT username, COUNT(*) AS v30
      FROM radacct
      WHERE acctstarttime >= NOW() - INTERVAL 30 DAY
      GROUP BY username
    ) v ON v.username = u.username

    LEFT JOIN (
      SELECT username, MAX(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) AS last_seen
      FROM radacct
      GROUP BY username
    ) l ON l.username = u.username

    LEFT JOIN (
      SELECT username, COUNT(*) AS online_cnt
      FROM radacct
      WHERE acctstoptime IS NULL
      GROUP BY username
    ) onl ON onl.username = u.username

    LEFT JOIN (
      SELECT username, GROUP_CONCAT(groupname ORDER BY priority SEPARATOR ', ') AS groups
      FROM radusergroup
      GROUP BY username
    ) g ON g.username = u.username

    WHERE 1
  ";

  $p = [];

  if ($q !== '') {
    $filters = [];
    if ($qDigits !== '') {
      $filters[] = 'u.username LIKE :qcpf_digits';
      $p[':qcpf_digits'] = '%' . $qDigits . '%';
      if (strlen($qDigits) >= 3) {
        $filters[] = 'ci.telefone LIKE :qphone';
        $p[':qphone'] = '%' . $qDigits . '%';
        $hasPhoneFilter = true;
      }
    } else {
      $filters[] = 'u.username LIKE :qcpf';
      $p[':qcpf'] = '%' . $q . '%';
    }
    $filters[] = 'ci.nome LIKE :qnome';
    $p[':qnome'] = '%' . $q . '%';
    $sql .= ' AND (' . implode(' OR ', $filters) . ') ';
  }

  if ($group !== '') {
    $sql .= " AND u.username IN (SELECT username FROM radusergroup WHERE groupname = :grp) ";
    $p[':grp'] = $group;
  }

  if ($online === 'online') {
    $sql .= " AND COALESCE(onl.online_cnt,0) > 0 ";
  } elseif ($online === 'offline') {
    $sql .= " AND COALESCE(onl.online_cnt,0) = 0 ";
  }

  if ($hasPhoneFilter) {
    if (!isset($p[':qphone_union']) && isset($p[':qphone'])) {
      $p[':qphone_union'] = $p[':qphone'];
    }
    $baseUsersSql .= "\n      UNION\n      SELECT DISTINCT cpf FROM clientes_info WHERE telefone LIKE :qphone_union";
  }

  $sql = str_replace('%BASE_USERS%', $baseUsersSql, $sql);

  $sql .= " ORDER BY $orderSql ";

  $st = $pdo->prepare($sql);
  $st->execute($p);

  $fname = 'usuarios_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fname.'"');

  $out = fopen('php://output', 'w');
  fprintf($out, "\xEF\xBB\xBF"); // BOM p/ Excel

  fputcsv($out, [
    'username','nome','telefone','email','sexo','groups','online',
    'allowed_seconds','used_seconds','remaining_seconds','remaining_hm',
    'visits_30d','last_seen'
  ]);

  while ($r = $st->fetch()) {
    $remaining = (int)$r['remaining'];
    $h = intdiv($remaining, 3600);
    $m = intdiv($remaining % 3600, 60);
    $remaining_hm = sprintf('%dh %02dm', $h, $m);

    fputcsv($out, [
      $r['username'],
      $r['nome'],
      $r['telefone'],
      $r['email'],
      $r['sexo'],
      $r['groups'],
      ((int)$r['online_cnt'] > 0 ? 'online' : 'offline'),
      (int)$r['allowed'],
      (int)$r['used'],
      $remaining,
      $remaining_hm,
      (int)$r['visits30'],
      $r['last_seen'] ?: ''
    ]);
  }

  fclose($out);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Erro ao exportar: '.$e->getMessage();
}
