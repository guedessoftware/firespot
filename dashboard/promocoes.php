<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

/**
 * Promoções — clientes_info (unificada)
 * - 1 formulário, 1 textarea
 * - Segmento: TODOS | SEXO | IDADE | ANIVERSÁRIO | BUSCA | MANUAL
 * - Filtro adicional: Aceitou Termos
 * - Enfileira em promo_queue; worker envia 1 a cada 10s
 */

$titulo = 'Promoções';
$pageId = 'promocoes';

$ROOT = realpath(__DIR__ . '/..');
$APP  = $ROOT . '/app';

/* ===== Conexão DB robusta ===== */
$pdo = null; $fatal_db = null;
try {
  if (is_file($APP . '/db.php')) require_once $APP . '/db.php';
  if (!($pdo instanceof PDO)) {
    if (function_exists('getPDO'))      $pdo = getPDO();
    elseif (function_exists('db'))      $pdo = db();
    elseif (function_exists('pdo'))     $pdo = pdo();
  }
  if (!($pdo instanceof PDO)) {
    $db_host = getenv('DB_HOST');
    $db_name = getenv('DB_NAME');
    $db_user = getenv('DB_USER');
    $db_pass = getenv('DB_PASS');
    if (is_file($APP . '/config.php')) include $APP . '/config.php';
    if (!$db_host || !$db_name) throw new Exception('Configuração de DB ausente');
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
} catch (Throwable $e) { $fatal_db = $e->getMessage(); }

/* ===== Tabela base fixa ===== */
$CLIENTS_TABLE  = 'clientes_info';
$COL_ID         = 'id';
$COL_NOME       = 'nome';
$COL_CPF        = 'cpf';
$COL_PHONE      = 'telefone';
$COL_DN         = 'data_nascimento';
$COL_TERMO      = 'aceitou_termos';
$COL_SEXO       = 'sexo'; // ENUM('M','F','O')

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensure_queue(PDO $pdo){
  $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='promo_queue'");
  if (!(bool)$st->fetchColumn()) throw new RuntimeException('Migração 011 pendente: tabela promo_queue ausente.');
}
function clean_phone($s){
  $s = preg_replace('/[^+0-9]/','', (string)$s);
  if (strpos($s,'00')===0) $s = '+'.substr($s,2);
  return $s;
}
function prefix_ddi($n, $ddi){
  if ($ddi==='') return $n;
  if (strpos($n,'+')===0) return $n;
  if (strpos($n,$ddi)===0) return $n;
  return $ddi.$n;
}
function enqueue_numbers(PDO $pdo, array $numbers, $msg){
  ensure_queue($pdo);
  $ins = $pdo->prepare("INSERT INTO promo_queue (to_msisdn, msg) VALUES (:to, :msg)");
  $n=0; foreach ($numbers as $to){ $to=trim($to); if($to==='') continue; $ins->execute([':to'=>$to, ':msg'=>$msg]); $n++; }
  return $n;
}

$flash = $_SESSION['promo_flash'] ?? null;
unset($_SESSION['promo_flash']);
$ddi_default = '+55';
$csrf = csrf_token();

/* ===== POST: Enfileirar campanha ===== */
if (!$fatal_db && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='enqueue') {
  try {
    if (!csrf_check($_POST['csrf'] ?? '')) {
      throw new Exception('Sessão expirada ou token de segurança inválido. Recarregue a página.');
    }
    $msg   = trim((string)($_POST['mensagem'] ?? ''));
    $ddi   = trim((string)($_POST['ddi'] ?? ''));
    $stype = trim((string)($_POST['segment_type'] ?? 'all'));
    $aceitou = !empty($_POST['aceitou']); // filtro adicional opcional

    if ($msg==='') throw new Exception('Mensagem vazia.');

    $numbers = [];

    if ($stype === 'manual') {
      $lista = (string)($_POST['numbers'] ?? '');
      if ($lista==='') throw new Exception('Informe ao menos um número no envio manual.');
      $arr = preg_split('/[\\n,;\\s]+/', $lista);
      foreach ($arr as $raw) {
        $n = clean_phone(trim($raw));
        if ($n==='') continue;
        $numbers[] = prefix_ddi($n, $ddi);
      }
      if (!$numbers) throw new Exception('Lista sem números válidos.');
    } else {
      // monta consulta por segmento
      $where = ["`$COL_PHONE` IS NOT NULL","`$COL_PHONE`<>''"];
      $params = [];

      if ($aceitou) { $where[] = "`$COL_TERMO` = 1"; }

      switch ($stype) {
        case 'all':
          // sem filtro extra
          break;
        case 'sexo':
          $sx = strtoupper(trim((string)($_POST['sexo'] ?? '')));
          if (!in_array($sx,['M','F','O'], true)) throw new Exception('Sexo inválido.');
          $where[] = "`$COL_SEXO` = :sx"; $params[':sx'] = $sx; break;
        case 'idade':
          $min = isset($_POST['idade_min']) && $_POST['idade_min']!=='' ? max(0, (int)$_POST['idade_min']) : null;
          $max = isset($_POST['idade_max']) && $_POST['idade_max']!=='' ? max(0, (int)$_POST['idade_max']) : null;
          if ($min===null && $max===null) throw new Exception('Defina pelo menos uma das idades (mín ou máx).');
          if ($min!==null) $where[] = "TIMESTAMPDIFF(YEAR, `$COL_DN`, CURDATE()) >= :imin";
          if ($max!==null) $where[] = "TIMESTAMPDIFF(YEAR, `$COL_DN`, CURDATE()) <= :imax";
          if ($min!==null) $params[':imin'] = $min;
          if ($max!==null) $params[':imax'] = $max;
          break;
        case 'aniversario':
          $mes = (int)($_POST['mes'] ?? 0);
          if ($mes<1 || $mes>12) throw new Exception('Mês inválido.');
          $where[] = "MONTH(`$COL_DN`) = :mes"; $params[':mes'] = $mes; break;
        case 'busca':
          $q = trim((string)($_POST['q'] ?? ''));
          if ($q==='') throw new Exception('Informe um termo de busca (nome ou CPF).');
          $where[] = "(`$COL_NOME` LIKE :q OR `$COL_CPF` LIKE :q)"; $params[':q'] = '%'.$q.'%'; break;
        default:
          throw new Exception('Tipo de segmentação inválido.');
      }

      $sql = "SELECT `$COL_PHONE` AS fone FROM `$CLIENTS_TABLE` WHERE ".implode(' AND ', $where);
      $st = $pdo->prepare($sql);
      foreach ($params as $k=>$v) $st->bindValue($k,$v);
      $st->execute();
      while ($r = $st->fetch()) {
        $n = clean_phone($r['fone']);
        if ($n==='') continue;
        $numbers[] = prefix_ddi($n, $ddi);
      }
      if (!$numbers) throw new Exception('Nenhum telefone encontrado com os filtros aplicados.');
    }

    $enq = enqueue_numbers($pdo, $numbers, $msg);
    $flash = ['ok'=>true, 'text'=>"Enfileirados $enq números. O worker fará o envio a cada 10s."];
  } catch (Throwable $e) {
    $flash = ['ok'=>false, 'text'=>$e->getMessage()];
  }
  $_SESSION['promo_flash'] = $flash;
  header('Location: promocoes.php', true, 303);
  exit;
}

ob_start(); ?>
<div class="content">
  <section class="fs-context-callout">
    <div>
      <span>Campanha por mensagem</span>
      <strong>Segmente clientes e acompanhe o envio sem misturar com anúncios do portal.</strong>
      <p>Os destinatários são enfileirados e processados gradualmente pelo worker de mensageria.</p>
    </div>
    <a href="anuncios.php">Gerenciar anúncios visuais</a>
  </section>
  <div class="card">
    <div class="card-header">
      <div>
        <h2>Nova promoção por mensagem</h2>
        <div class="muted">Escolha a segmentação e enfileire a campanha. O worker envia <strong>1 mensagem a cada 10s</strong>.</div>
      </div>
    </div>

    <?php if ($fatal_db): ?>
      <div class="muted">⚠️ Erro de banco: <?= h($fatal_db) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="muted"><?= $flash['ok'] ? '✅' : '⚠️' ?> <?= h($flash['text']) ?></div>
    <?php endif; ?>

    <form method="post" id="promoForm">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
      <input type="hidden" name="action" value="enqueue" />

      <!-- Mensagem única -->
      <div>
        <label for="mensagem"><strong>Mensagem</strong></label>
        <textarea name="mensagem" id="mensagem" rows="4" required placeholder="Escreva o texto promocional..."></textarea>
      </div>

      <!-- Linha 1: DDI + Segmento + Aceitou termos -->
      <div class="card-header">
        <div class="header-actions">
          <div>
            <label for="ddi"><strong>DDI/Prefixo</strong></label>
            <input type="text" name="ddi" id="ddi" value="<?= h($ddi_default) ?>" />
          </div>
          <div>
            <label for="segment_type"><strong>Segmento</strong></label>
            <select name="segment_type" id="segment_type">
              <option value="all">Todos</option>
              <option value="sexo">Sexo</option>
              <option value="idade">Idade</option>
              <option value="aniversario">Aniversariantes do mês</option>
              <option value="busca">Busca (Nome/CPF)</option>
              <option value="manual">Manual</option>
            </select>
          </div>
          <div>
            <label><strong>Filtro adicional</strong></label>
            <div><label><input type="checkbox" name="aceitou" /> Apenas quem aceitou termos</label></div>
          </div>
        </div>
        <div class="header-actions">
          <button type="submit" class="theme-btn">📥 Enfileirar</button>
        </div>
      </div>

      <!-- Controles dinâmicos por segmento (mostrados via JS) -->
      <div id="seg_sexo" class="header-actions" hidden>
        <div>
          <label for="sexo"><strong>Sexo</strong></label>
          <select name="sexo" id="sexo">
            <option value="M">Masculino</option>
            <option value="F">Feminino</option>
            <option value="O">Outros</option>
          </select>
        </div>
      </div>

      <div id="seg_idade" class="header-actions" hidden>
        <div>
          <label for="idade_min"><strong>Idade mínima</strong></label>
          <input type="number" name="idade_min" id="idade_min" min="0" placeholder="ex.: 18" />
        </div>
        <div>
          <label for="idade_max"><strong>Idade máxima</strong></label>
          <input type="number" name="idade_max" id="idade_max" min="0" placeholder="ex.: 35" />
        </div>
      </div>

      <div id="seg_aniversario" class="header-actions" hidden>
        <div>
          <label for="mes"><strong>Mês</strong></label>
          <select name="mes" id="mes">
            <option value="1">Janeiro</option><option value="2">Fevereiro</option><option value="3">Março</option><option value="4">Abril</option>
            <option value="5">Maio</option><option value="6">Junho</option><option value="7">Julho</option><option value="8">Agosto</option>
            <option value="9">Setembro</option><option value="10">Outubro</option><option value="11">Novembro</option><option value="12">Dezembro</option>
          </select>
        </div>
      </div>

      <div id="seg_busca" class="header-actions" hidden>
        <div>
          <label for="q"><strong>Buscar</strong></label>
          <input type="text" name="q" id="q" placeholder="Nome ou CPF" />
        </div>
      </div>

      <div id="seg_manual" class="header-actions" hidden>
        <div>
          <label for="numbers"><strong>Números</strong></label>
          <textarea name="numbers" id="numbers" rows="3" placeholder="5591111111111&#10;5592222222222"></textarea>
        </div>
      </div>
    </form>
  </div>
</div>

<?php $conteudo = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
