<?php declare(strict_types=1);

// /hotspot/portal/vip.php — Acesso VIP com prefill via clientes_info
// Usa CPF recebido em POST['username'] (ou GET para debug)

$DEBUG = isset($_GET['debug']);

// ===== Helpers de include (evita 500 por path) =====
function require_first(array $cands): void
{
  foreach ($cands as $p) {
    if (@is_file($p)) {
      require_once $p;
      return;
    }
  }
  http_response_code(500);
  echo $GLOBALS['DEBUG']
    ? "<h3>Erro: config/db não encontrados</h3><pre>" . htmlspecialchars(print_r($cands, true)) . "</pre>"
    : "Erro interno (configuração).";
  exit;
}

// Raiz do projeto (/hotspot)
$BASE = dirname(__DIR__);

// Sessão + CSRF
require_first([$BASE . '/app/session_boot.php']);
require_first([$BASE . '/app/csrf.php']);

// Config / DB
require_first([$BASE . '/app/config.php', $BASE . '/config.php']);
require_first([$BASE . '/app/db.php', $BASE . '/db.php']);

#var_dump($_POST);

function only_digits(string $s): string
{
  return preg_replace('/\D+/', '', $s);
}
function money_br(int $c): string
{
  return 'R$ ' . number_format($c / 100, 2, ',', '.');
}

$cpfKey = only_digits((string) ($_POST['username'] ?? $_GET['username'] ?? ''));

if ($cpfKey === '') {
  http_response_code(400);
  echo $DEBUG
    ? 'CPF não informado: envie POST username=XXXXXXXXXXX ou use ?username=XXXXXXXXXXX&debug=1'
    : 'CPF não informado.';
  exit;
}

try {
  $pdo = db();
  if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  }

  // ===== Busca cliente em clientes_info =====
  // Campos esperados: cpf, nome, email, telefone
  $sqlUser = 'SELECT cpf, nome, email, telefone
                FROM clientes_info
               WHERE REPLACE(REPLACE(REPLACE(cpf,".",""),"-","")," ","") = ?
               LIMIT 1';
  $st = $pdo->prepare($sqlUser);
  $st->execute([$cpfKey]);
  $user = $st->fetch();

  $nomeLog = $user['nome'] ?? '';
  $cpfLog = only_digits($user['cpf'] ?? $cpfKey);
  $emailLog = $user['email'] ?? '';
  $foneLog = only_digits($user['telefone'] ?? '');

  // ===== Carrega planos ativos =====
  $stmt = $pdo->query(
    'SELECT id, nome, grupo, preco_centavos, down_kbps, up_kbps, duracao_min, descricao
       FROM planos
      WHERE ativo = 1
   ORDER BY ordem ASC, preco_centavos ASC, nome ASC'
  );
  $planos = $stmt->fetchAll();

} catch (Throwable $e) {
  http_response_code(500);
  echo $DEBUG
    ? '<h3>Erro interno</h3><pre>' . htmlspecialchars($e->getMessage()) . "</pre>"
    : 'Erro interno ao carregar VIP.';
  exit;
}

// ===== Render =====
?>
<!doctype html>
<html lang="pt-BR" data-theme="light">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acesso VIP · Escolha seu plano</title>
  <link rel="stylesheet" href="assets/css/portal.css">
  <style>
    .plans {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px
    }

    @media (max-width:960px) {
      .plans {
        grid-template-columns: 1fr
      }
    }

    .plan {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 8px
    }

    .plan h3 {
      margin: 0;
      font-size: 18px
    }

    .plan .price {
      font-size: 24px;
      font-weight: 700
    }

    .plan .meta {
      color: var(--muted);
      font-size: 13px
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    @media (max-width:840px) {
      .grid {
        grid-template-columns: 1fr
      }
    }
  </style>
</head>

<body>
  <header class="header"><strong>Acesso VIP</strong>
    <nav><a href="../index.php">Dashboard</a></nav>
  </header>
  <main class="container">
    <h2>Escolha seu plano VIP</h2>
    <p class="notice">Selecione um plano para gerar o <strong>Pix dinâmico</strong>.</p>

    <form class="card form" method="post" action="vip_checkout.php">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <!-- reenviamos o CPF como 'username' para o checkout -->
      <input type="hidden" name="username" value="<?= htmlspecialchars($cpfLog) ?>">
      <input type="hidden" name="mac" value="<?= htmlspecialchars($_POST['mac']) ?>">
      <input type="hidden" name="ip" value="<?= htmlspecialchars($_POST['ip']) ?>">

      <div class="plans">
        <?php if (!empty($planos)):
          foreach ($planos as $p): ?>
            <label class="plan">
              <input type="radio" name="plano_id" value="<?= (int) $p['id'] ?>" required>
              <h3>
                <?= htmlspecialchars($p['nome']) ?>
                <?php if (!empty($p['grupo'])): ?>
                  <small style="color:var(--muted)">· <?= htmlspecialchars($p['grupo']) ?></small>
                <?php endif; ?>
              </h3>
              <div class="price"><?= money_br((int) $p['preco_centavos']) ?></div>
              <div class="meta">Velocidade: ↓ <?= (int) $p['down_kbps'] ?> kbps · ↑ <?= (int) $p['up_kbps'] ?> kbps</div>
              <div class="meta">Duração: <?= (int) $p['duracao_min'] ?> min</div>
              <?php if (!empty($p['descricao'])): ?>
                <p><?= nl2br(htmlspecialchars($p['descricao'])) ?></p>
              <?php endif; ?>
            </label>
          <?php endforeach; else: ?>
          <div class="card">Nenhum plano ativo encontrado.</div>
        <?php endif; ?>
      </div>

      <h2>Seus dados</h2>
      <div class="grid">
        <div class="card">
          <label>Nome</label>
          <input type="text" name="nome" value="<?= htmlspecialchars($nomeLog) ?>" required>
          <label>CPF</label>
          <input type="text" name="cpf" value="<?= htmlspecialchars($cpfLog) ?>" placeholder="Somente números" required
            pattern="\d{11}">
        </div>
        <div class="card">
          <label>E-mail</label>
          <input type="email" name="email" value="<?= htmlspecialchars($emailLog) ?>" required>
          <label>Telefone</label>
          <input type="tel" name="telefone" value="<?= htmlspecialchars($foneLog) ?>" placeholder="(DDD) 99999-9999"
            pattern="\d{10,13}">
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px">
        <button class="btn primary" type="submit">Gerar Pix e pagar</button>
        <a class="btn" href="index.php">Cancelar</a>
      </div>
    </form>

    <?php if ($DEBUG): ?>
      <pre style="margin-top:16px;padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap">
  DEBUG:
  cpfKey (POST/GET): <?= htmlspecialchars($cpfKey) . "\n" ?>
  cpfLog (form):     <?= htmlspecialchars($cpfLog) . "\n" ?>
  User encontrado:   <?= $user ? 'sim' : 'não' . "\n" ?>
  Planos ativos:     <?= is_array($planos) ? count($planos) : 0 ?>
        </pre>
    <?php endif; ?>
  </main>
  <footer class="footer">© <?= date('Y') ?></footer>
</body>

</html>