<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/020_courtesy_classic_rollout_split.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 020.');
$pdo->exec($sql);

$partners = (int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$signup = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE portal='classic_signup'")->fetchColumn();
$login = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE portal='classic_login'")->fetchColumn();
$legacyKey = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE portal='classic'")->fetchColumn();
if ($signup !== $partners || $login !== $partners || $legacyKey !== 0) {
    throw new RuntimeException('A separação dos rollouts clássicos ficou incompleta.');
}

echo "Migração 020 aplicada. Cadastro e login clássico agora possuem rollouts independentes.\n";
