<?php
// /dashboard/api/planos_data.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();

/* ===== Includes robustos ===== */
function require_first(array $candidates): void {
  foreach ($candidates as $path) {
    if (is_file($path)) { require_once $path; return; }
  }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'config_or_db_not_found','hint'=>$candidates]);
  exit;
}
$BASE = dirname(__DIR__);              // /dashboard
require_first([$BASE.'/app/config.php', $BASE.'/config.php', dirname($BASE).'/app/config.php', dirname($BASE).'/config.php']);
require_first([$BASE.'/app/db.php',     $BASE.'/db.php',     dirname($BASE).'/app/db.php',     dirname($BASE).'/db.php']);

$pdo = db();

/* ===== Helpers ===== */
function jerr(string $m, int $c=400, array $meta=[]){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]+$meta, JSON_UNESCAPED_UNICODE); exit; }
function as_int($v, int $d=0){ return is_numeric($v) ? (int)$v : $d; }
function as_bool01($v){ return is_string($v) ? (int)in_array(strtolower(trim($v)),['1','true','yes','sim','on'],true) : (int)!!$v; }
function cents_from_input($v): int {
  if ($v === null || $v === '') return 0;
  if (is_numeric($v)) return (int)$v;            // já em centavos
  $s = trim((string)$v);
  $s = preg_replace('/^R\$\s*/i','', $s);
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  return (int) round(((float)$s) * 100);
}
function sanitize_order(string $order, bool $hasGrupo): array {
  $map = [
    'atualizado_em' => ['col'=>'atualizado_em','dir'=>'DESC'],
    'nome'          => ['col'=>'nome','dir'=>'ASC'],
    'preco_centavos'=> ['col'=>'preco_centavos','dir'=>'ASC'],
    'ordem'         => ['col'=>'ordem','dir'=>'ASC'],
  ];
  if ($hasGrupo) $map['grupo'] = ['col'=>'grupo','dir'=>'ASC'];
  return $map[$order] ?? $map['atualizado_em'];
}
// Substitua a função table_has_column atual por esta versão:
function table_has_column(PDO $pdo, string $table, string $col): bool {
  $sql = 'SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = ?
             AND COLUMN_NAME  = ?';
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}

/* ===== Descoberta de colunas ===== */
$hasGrupo = table_has_column($pdo, 'planos', 'grupo');

/* ===== Roteamento ===== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

try {
  /* ---- Diagnóstico rápido ---- */
  if ($action === 'diag') {
    $cols = $pdo->query('SHOW COLUMNS FROM planos')->fetchAll(PDO::FETCH_COLUMN, 0);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM planos')->fetchColumn();
    echo json_encode(['ok'=>true,'diag'=>['columns'=>$cols,'hasGrupo'=>$hasGrupo,'count'=>$count]]);
    exit;
  }

  /* ---- LIST ---- */
  if ($method === 'GET' && $action === 'list') {
    $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $ativo = isset($_GET['ativo']) ? trim((string)$_GET['ativo']) : '';
    $order = sanitize_order((string)($_GET['order'] ?? 'atualizado_em'), $hasGrupo);

    $where = []; $params = [];
    if ($q !== '') {
      if ($hasGrupo) {
        $where[] = '(nome LIKE ? OR grupo LIKE ? OR descricao LIKE ?)';
        $like = '%'.$q.'%'; array_push($params, $like, $like, $like);
      } else {
        $where[] = '(nome LIKE ? OR descricao LIKE ?)';
        $like = '%'.$q.'%'; array_push($params, $like, $like);
      }
    }
    if ($ativo === '0' || $ativo === '1') { $where[] = 'ativo = ?'; $params[] = (int)$ativo; }

    $fields = 'id, nome'
      .($hasGrupo ? ', grupo' : '')
      .', preco_centavos, down_kbps, up_kbps, duracao_min, descricao, ativo, ordem, criado_em, atualizado_em';

    $sql = "SELECT $fields FROM planos";
    if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
    $sql .= ' ORDER BY '.$order['col'].' '.$order['dir'].', id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true, 'planos'=>$rows, 'count'=>count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ---- Escrita: exige CSRF ---- */
  if ($method === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) jerr('invalid_csrf', 403);
    if (!admin_has_capability('partner.plans.manage')) jerr('forbidden', 403);

    if ($action === 'create') {
      $nome           = trim((string)($_POST['nome'] ?? ''));
      $grupo          = trim((string)($_POST['grupo'] ?? ''));
      $preco_centavos = as_int($_POST['preco_centavos'] ?? cents_from_input($_POST['preco'] ?? 0));
      $down_kbps      = as_int($_POST['down_kbps'] ?? ((int)($_POST['down_mbps'] ?? 0) * 1000));
      $up_kbps        = as_int($_POST['up_kbps']   ?? ((int)($_POST['up_mbps']   ?? 0) * 1000));
      $duracao_min    = as_int($_POST['duracao_min'] ?? 0);
      $ordem          = as_int($_POST['ordem'] ?? 100);
      $descricao      = isset($_POST['descricao']) ? trim((string)$_POST['descricao']) : null;
      $ativo          = as_bool01($_POST['ativo'] ?? 1);

      if ($nome === '') jerr('nome_required');
      if ($hasGrupo && $grupo === '') jerr('grupo_required');
      if ($duracao_min <= 0) jerr('duracao_min_invalid');

      if ($hasGrupo) {
        $ins = $pdo->prepare('INSERT INTO planos (nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min, descricao, ativo, ordem)
                              VALUES (?,?,?,?,?,?,?,?,?)');
        $ok = $ins->execute([$nome,$grupo,$preco_centavos,$down_kbps,$up_kbps,$duracao_min,$descricao,$ativo,$ordem]);
      } else {
        $ins = $pdo->prepare('INSERT INTO planos (nome, preco_centavos, down_kbps, up_kbps, duracao_min, descricao, ativo, ordem)
                              VALUES (?,?,?,?,?,?,?,?)');
        $ok = $ins->execute([$nome,$preco_centavos,$down_kbps,$up_kbps,$duracao_min,$descricao,$ativo,$ordem]);
      }
      if (!$ok) jerr('insert_failed', 500);

      $id = (int)$pdo->lastInsertId();
      $fields = 'id, nome'.($hasGrupo?', grupo':'').', preco_centavos, down_kbps, up_kbps, duracao_min, descricao, ativo, ordem, criado_em, atualizado_em';
      $row = $pdo->query("SELECT $fields FROM planos WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=>true, 'id'=>$id, 'plano'=>$row]); exit;
    }

    if ($action === 'update') {
      $id = as_int($_POST['id'] ?? 0);
      if ($id <= 0) jerr('id_required');

      $fields = []; $vals = [];
      if (isset($_POST['nome']))            { $fields[]='nome=?';            $vals[] = trim((string)$_POST['nome']); }
      if ($hasGrupo && isset($_POST['grupo'])) { $fields[]='grupo=?';       $vals[] = trim((string)$_POST['grupo']); }
      if (isset($_POST['preco_centavos']) || isset($_POST['preco'])) {
        $cent = isset($_POST['preco_centavos']) ? as_int($_POST['preco_centavos']) : cents_from_input($_POST['preco']);
        $fields[]='preco_centavos=?'; $vals[] = $cent;
      }
      if (isset($_POST['down_kbps']) || isset($_POST['down_mbps'])) {
        $v = isset($_POST['down_kbps']) ? as_int($_POST['down_kbps']) : ((int)($_POST['down_mbps'] ?? 0) * 1000);
        $fields[]='down_kbps=?'; $vals[] = $v;
      }
      if (isset($_POST['up_kbps']) || isset($_POST['up_mbps'])) {
        $v = isset($_POST['up_kbps']) ? as_int($_POST['up_kbps']) : ((int)($_POST['up_mbps'] ?? 0) * 1000);
        $fields[]='up_kbps=?'; $vals[] = $v;
      }
      if (isset($_POST['duracao_min']))     { $fields[]='duracao_min=?';     $vals[] = as_int($_POST['duracao_min']); }
      if (isset($_POST['descricao']))       { $fields[]='descricao=?';       $vals[] = trim((string)$_POST['descricao']); }
      if (isset($_POST['ativo']))           { $fields[]='ativo=?';           $vals[] = as_bool01($_POST['ativo']); }
      if (isset($_POST['ordem']))           { $fields[]='ordem=?';           $vals[] = as_int($_POST['ordem'], 100); }

      if (!$fields) jerr('no_fields_to_update');

      $sql = 'UPDATE planos SET '.implode(', ', $fields).' WHERE id = ?';
      $vals[] = $id;
      $stmt = $pdo->prepare($sql);
      $ok = $stmt->execute($vals);
      if (!$ok) jerr('update_failed', 500);

      $fields = 'id, nome'.($hasGrupo?', grupo':'').', preco_centavos, down_kbps, up_kbps, duracao_min, descricao, ativo, ordem, criado_em, atualizado_em';
      $row = $pdo->prepare("SELECT $fields FROM planos WHERE id=?");
      $row->execute([$id]);
      echo json_encode(['ok'=>true, 'plano'=>$row->fetch(PDO::FETCH_ASSOC)]);
      exit;
    }

    if ($action === 'toggle') {
      $id    = as_int($_POST['id'] ?? 0);
      $ativo = as_bool01($_POST['ativo'] ?? 0);
      if ($id <= 0) jerr('id_required');

      $stmt = $pdo->prepare('UPDATE planos SET ativo = ? WHERE id = ?');
      $ok = $stmt->execute([$ativo, $id]);
      if (!$ok) jerr('toggle_failed', 500);

      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete') {
      $id = as_int($_POST['id'] ?? 0);
      if ($id <= 0) jerr('id_required');

      $stmt = $pdo->prepare('UPDATE planos SET ativo=0 WHERE id = ?');
      $ok = $stmt->execute([$id]);
      if (!$ok) jerr('delete_failed', 500);

      echo json_encode(['ok'=>true]); exit;
    }

    jerr('invalid_action');
  }

  jerr('method_not_allowed', 405);
} catch (Throwable $e) {
  error_log('[planos api] '.get_class($e).': '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error'], JSON_UNESCAPED_UNICODE);
}

if (($_GET['action'] ?? '') === 'diag') {
  echo json_encode([
    'ok' => true,
    'session_id' => session_id(),
    'has_admin'  => !empty($_SESSION['admin']),
    'user'       => $_SESSION['user'] ?? null,
  ]);
  exit;
}
