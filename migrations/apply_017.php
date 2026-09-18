<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/017_unified_courtesy_policy.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 017.');
$pdo->exec($sql);

$requiredTables = ['courtesy_policy_defaults', 'courtesy_partner_policies', 'courtesy_grants'];
foreach ($requiredTables as $table) {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $st->execute([$table]);
    if (!$st->fetchColumn()) throw new RuntimeException('Tabela ausente após a migração: ' . $table);
}

$default = $pdo->query('SELECT * FROM courtesy_policy_defaults WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$default || (string) $default['enforcement_method'] !== 'radius' || (string) $default['consumption_mode'] !== 'online') {
    throw new RuntimeException('A política global de cortesia não foi inicializada corretamente.');
}

$missing = (int) $pdo->query('SELECT COUNT(*) FROM partners p LEFT JOIN courtesy_partner_policies cp ON cp.partner_id=p.id WHERE cp.partner_id IS NULL')->fetchColumn();
if ($missing > 0) throw new RuntimeException('Existem estabelecimentos sem política inicial após a migração.');

echo "Migração 017 aplicada. Política unificada criada sem alterar os fluxos ativos.\n";
