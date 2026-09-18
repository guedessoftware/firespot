<?php

declare(strict_types=1);

require_once __DIR__ . '/infrastructure_status.php';
require_once __DIR__ . '/partner_entitlements.php';

/** @return array<string,mixed> */
function fs_control_center_system_governance(PDO $pdo): array
{
    $registered = [];
    try {
        $registered = array_map('intval', $pdo->query("SELECT version FROM schema_migrations WHERE state IN ('adopted','applied') ORDER BY version")->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $ignored) {
    }
    $target = 55;
    $pending = array_values(array_diff(range(1, $target), $registered));
    $jobs = fs_infrastructure_jobs($pdo);
    return [
        'schema_version'=>$registered ? max($registered) : 0,
        'schema_registered'=>count($registered),
        'schema_target'=>$target,
        'schema_pending'=>$pending,
        'hotspot_apply_pilot_approved'=>fs_partner_hotspot_apply_pilot_approved($pdo),
        'portal_v3_activation'=>'frozen',
        'privacy_job'=>$jobs['privacy_cleanup'] ?? null,
        'subscriber_retention_job'=>$jobs['subscriber_retention'] ?? null,
    ];
}
