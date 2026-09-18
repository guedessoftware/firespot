<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_rollout.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$json = in_array('--json', $argv ?? [], true);
if (!fs_courtesy_rollout_schema_ready($pdo)) {
    fwrite(STDERR, "A migração 019 ainda não foi aplicada.\n");
    exit(1);
}

$settings = [
    'cutover_enabled' => fs_courtesy_setting_bool($pdo, 'courtesy_cutover_enabled', false),
    'radius_ready' => fs_courtesy_setting_bool($pdo, 'courtesy_radius_ready', false),
    'mikrotik_ready' => fs_courtesy_setting_bool($pdo, 'courtesy_mikrotik_ready', false),
];
$partners = $pdo->query('SELECT id,code,name FROM partners ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$report = [];
$effectiveEnforce = 0;
foreach ($partners as $partner) {
    $policy = fs_courtesy_policy_resolve($pdo, (int) $partner['id']);
    foreach (fs_courtesy_rollout_portals() as $portal => $label) {
        $rollout = fs_courtesy_rollout_resolve($pdo, (int) $partner['id'], $portal, $policy);
        if ($rollout['effective_mode'] === 'enforce') $effectiveEnforce++;
        $report[] = array_merge($rollout, [
            'partner_code' => (string) $partner['code'],
            'partner_name' => (string) $partner['name'],
            'portal_label' => $label,
            'enforcement_method' => (string) $policy['enforcement_method'],
        ]);
    }
}

if ($json) {
    echo json_encode([
        'safe' => $effectiveEnforce === 0,
        'settings' => $settings,
        'effective_enforce' => $effectiveEnforce,
        'rollouts' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo 'Gates: cutover=' . ($settings['cutover_enabled'] ? 'on' : 'off')
    . ', radius=' . ($settings['radius_ready'] ? 'ready' : 'blocked')
    . ', mikrotik=' . ($settings['mikrotik_ready'] ? 'ready' : 'blocked') . PHP_EOL;
foreach ($report as $item) {
    echo sprintf(
        "%s / %s: solicitado=%s, efetivo=%s, método=%s%s\n",
        $item['partner_name'],
        $item['portal_label'],
        $item['requested_mode'],
        $item['effective_mode'],
        $item['enforcement_method'],
        $item['blocked_by'] ? ', bloqueio=' . $item['blocked_by'] : ''
    );
}
echo 'Resumo: ' . count($report) . ' rollouts, ' . $effectiveEnforce . " efetivamente em enforce.\n";
