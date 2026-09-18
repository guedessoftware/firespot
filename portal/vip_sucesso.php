<?php
require_once __DIR__ . '/../app/db.php';
$ref_raw = $_GET['ref'] ?? '';
$ref = htmlspecialchars($ref_raw);
$valorTxt = '';
$minutos = null;
try {
  if ($ref_raw) {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $q = $pdo->prepare('SELECT valor_centavos, duracao_min FROM vip_orders WHERE external_ref=? LIMIT 1');
    $q->execute([$ref_raw]);
    if ($row = $q->fetch()) {
      if (isset($row['valor_centavos']))
        $valorTxt = 'R$ ' . number_format(((int)$row['valor_centavos'])/100, 2, ',', '.');
      if (isset($row['duracao_min']))
        $minutos = (int)$row['duracao_min'];
    }
  }
} catch (Throwable $e) { /* silencioso */ }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pagamento aprovado</title>
  <link rel="stylesheet" href="assets/css/portal.css">
</head>
<body>
  <main class="container">
    <h1>Pagamento aprovado ✅</h1>
    <?php if ($ref): ?><p>Referência: <code><?= $ref ?></code></p><?php endif; ?>
    <?php if ($valorTxt !== '' || $minutos !== null): ?>
      <p>
        <?php if ($valorTxt !== ''): ?>Valor: <strong><?= htmlspecialchars($valorTxt) ?></strong><br><?php endif; ?>
        <?php if ($minutos !== null): ?>Acesso VIP: <strong><?= (int)$minutos ?></strong> minuto(s)<?php endif; ?>
      </p>
    <?php endif; ?>
    <p>Seu acesso VIP foi ativado. Boa navegação!</p>
    <a class="btn" href="cliente.php">Voltar ao painel</a>
  </main>
</body>
</html>
