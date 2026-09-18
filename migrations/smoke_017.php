<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/courtesy_policy.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!fs_courtesy_schema_ready($pdo)) throw new RuntimeException('Estrutura da cortesia unificada incompleta.');

$default = $pdo->query('SELECT * FROM courtesy_policy_defaults WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$errors = fs_courtesy_policy_validate(fs_courtesy_policy_normalize($default ?: []));
if ($errors) throw new RuntimeException('Política global inválida: ' . implode('; ', $errors));

$orphans = (int) $pdo->query('SELECT COUNT(*) FROM courtesy_partner_policies cp LEFT JOIN partners p ON p.id=cp.partner_id WHERE p.id IS NULL')->fetchColumn();
if ($orphans > 0) throw new RuntimeException('Foram encontradas políticas de estabelecimentos inexistentes.');

$partners = (int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$overrides = (int) $pdo->query('SELECT COUNT(*) FROM courtesy_partner_policies')->fetchColumn();
echo 'Smoke 017 concluído. Padrão válido, ' . $partners . ' estabelecimentos e ' . $overrides . " políticas próprias.\n";
