<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_rollout.php';

$hours = 24;
$json = in_array('--json', $argv ?? [], true);
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--hours=(\d+)$/', (string) $arg, $match)) $hours = max(1, min(8760, (int) $match[1]));
}
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));

$st = $pdo->prepare('SELECT COUNT(*) AS total,
        COALESCE(SUM(decision_match=1),0) AS matched,
        COALESCE(SUM(decision_match=0),0) AS mismatched,
        COALESCE(SUM(policy_allowed=1),0) AS policy_allowed,
        COALESCE(SUM(legacy_allowed=1),0) AS legacy_allowed
    FROM courtesy_shadow_events WHERE created_at>=?');
$st->execute([$cutoff]);
$summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$groups = [];
$st = $pdo->prepare('SELECT portal,source,legacy_code,policy_code,decision_match,COUNT(*) AS total
    FROM courtesy_shadow_events WHERE created_at>=?
    GROUP BY portal,source,legacy_code,policy_code,decision_match
    ORDER BY decision_match ASC,total DESC,portal,source LIMIT 100');
$st->execute([$cutoff]);
$groups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rolloutCounts = [];
if (fs_courtesy_rollout_schema_ready($pdo)) {
    foreach ($pdo->query('SELECT portal,mode,COUNT(*) AS total FROM courtesy_portal_rollouts GROUP BY portal,mode')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rolloutCounts[(string) $row['portal']][(string) $row['mode']] = (int) $row['total'];
    }
}
$eventCounts = [];
$st = $pdo->prepare('SELECT portal,COUNT(*) AS total,COUNT(DISTINCT partner_id) AS partners,
        COALESCE(SUM(decision_match=0),0) AS mismatched,MAX(created_at) AS last_event_at
    FROM courtesy_shadow_events WHERE created_at>=? GROUP BY portal');
$st->execute([$cutoff]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $eventCounts[(string) $row['portal']] = $row;

$coverage = [];
foreach (fs_courtesy_rollout_portals() as $portal => $label) {
    $events = $eventCounts[$portal] ?? [];
    $coverage[] = [
        'portal' => $portal,
        'label' => $label,
        'legacy_partners' => (int) ($rolloutCounts[$portal]['legacy'] ?? 0),
        'shadow_partners' => (int) ($rolloutCounts[$portal]['shadow'] ?? 0),
        'enforce_partners' => (int) ($rolloutCounts[$portal]['enforce'] ?? 0),
        'events' => (int) ($events['total'] ?? 0),
        'observed_partners' => (int) ($events['partners'] ?? 0),
        'mismatched' => (int) ($events['mismatched'] ?? 0),
        'last_event_at' => $events['last_event_at'] ?? null,
    ];
}

if ($json) {
    echo json_encode([
        'hours' => $hours,
        'summary' => array_map('intval', $summary),
        'coverage' => $coverage,
        'decisions' => $groups,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo 'Shadow mode — últimas ' . $hours . ' horas' . PHP_EOL;
echo 'Eventos: ' . (int) ($summary['total'] ?? 0)
    . ' | iguais: ' . (int) ($summary['matched'] ?? 0)
    . ' | divergentes: ' . (int) ($summary['mismatched'] ?? 0)
    . ' | legado liberaria: ' . (int) ($summary['legacy_allowed'] ?? 0)
    . ' | política liberaria: ' . (int) ($summary['policy_allowed'] ?? 0) . PHP_EOL;
foreach ($coverage as $item) {
    echo sprintf(
        "Cobertura %-16s legado=%d shadow=%d enforce=%d | eventos=%d parceiros=%d divergências=%d%s\n",
        $item['portal'],
        $item['legacy_partners'],
        $item['shadow_partners'],
        $item['enforce_partners'],
        $item['events'],
        $item['observed_partners'],
        $item['mismatched'],
        $item['last_event_at'] ? ' último=' . $item['last_event_at'] : ''
    );
}
foreach ($groups as $row) {
    echo sprintf(
        "[%s] %s/%s: %s -> %s (%d)\n",
        (int) $row['decision_match'] === 1 ? 'IGUAL' : 'DIVERGE',
        $row['portal'],
        $row['source'],
        $row['legacy_code'],
        $row['policy_code'],
        (int) $row['total']
    );
}
