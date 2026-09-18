<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_policy.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$json = in_array('--json', $argv ?? [], true);

if (!fs_courtesy_schema_ready($pdo)) {
    fwrite(STDERR, "A migração 017 ainda não foi aplicada.\n");
    exit(1);
}

$partners = $pdo->query('SELECT id FROM partners ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$report = [];
$invalid = 0;
foreach ($partners as $partnerRow) {
    $policy = fs_courtesy_policy_resolve($pdo, (int) $partnerRow['id']);
    $errors = fs_courtesy_policy_validate($policy);
    $warnings = array_merge((array) ($policy['migration_warnings'] ?? []), fs_courtesy_policy_warnings($policy));
    if ($errors) $invalid++;
    $report[] = [
        'partner_id' => (int) $policy['partner_id'],
        'code' => $policy['partner_code'],
        'name' => $policy['partner_name'],
        'active' => $policy['partner_active'],
        'source' => $policy['policy_source'],
        'revision' => $policy['policy_revision'],
        'minutes' => (int) $policy['grant_minutes'],
        'auth_mode' => $policy['auth_mode'],
        'device_max_grants' => $policy['device_max_grants'],
        'device_period_minutes' => $policy['device_period_minutes'],
        'enforcement_method' => $policy['enforcement_method'],
        'errors' => $errors,
        'warnings' => array_values(array_unique($warnings)),
    ];
}

if ($json) {
    echo json_encode([
        'ok' => $invalid === 0,
        'partners' => count($report),
        'invalid' => $invalid,
        'policies' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($invalid === 0 ? 0 : 2);
}

foreach ($report as $item) {
    echo sprintf(
        "[%s] %s: %d min, %s, %s uso(s)/%s min, %s\n",
        $item['errors'] ? 'ERRO' : ($item['warnings'] ? 'REVISAR' : 'OK'),
        $item['name'],
        $item['minutes'],
        $item['auth_mode'],
        $item['device_max_grants'] === null ? 'ilimitado' : (string) $item['device_max_grants'],
        $item['device_period_minutes'] === null ? 'sem período' : (string) $item['device_period_minutes'],
        $item['enforcement_method']
    );
    foreach ($item['errors'] as $error) echo '  erro: ' . $error . PHP_EOL;
    foreach ($item['warnings'] as $warning) echo '  atenção: ' . $warning . PHP_EOL;
}

echo 'Resumo: ' . count($report) . ' políticas, ' . $invalid . " inválidas.\n";
exit($invalid === 0 ? 0 : 2);
