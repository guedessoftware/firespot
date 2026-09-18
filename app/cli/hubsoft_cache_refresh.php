<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../hubsoft_cache.php';

try {
    $result = hubsoft_cache_refresh(['source' => 'cron']);
} catch (Throwable $error) {
    fwrite(STDERR, '[hubsoft-cache] status=failed code=' . get_class($error) . "\n");
    exit(1);
}

if (empty($result['ok'])) {
    $code = preg_replace('/[^a-z0-9_-]/i', '', (string)($result['code'] ?? 'failed')) ?: 'failed';
    $message = 'status=skipped code=' . $code;
    if (!empty($result['next_allowed'])) $message .= ' next_allowed=' . (string)$result['next_allowed'];
    echo '[hubsoft-cache] ' . $message . "\n";
    exit(in_array($code, ['spacing','limit_reached','locked','disabled'], true) ? 0 : 1);
}

$processed = (int)($result['processed'] ?? ($result['stats']['processed'] ?? 0));
$updated = (int)($result['updated'] ?? ($result['stats']['updated'] ?? 0));
$skipped = (int)($result['skipped'] ?? ($result['stats']['skipped'] ?? 0));
$errors = (array)($result['errors'] ?? []);

echo '[hubsoft-cache] status=' . ($errors ? 'partial' : 'ok')
    . ' processed=' . $processed
    . ' updated=' . $updated
    . ' skipped=' . $skipped
    . ' errors=' . count($errors) . "\n";
exit($errors ? 2 : 0);
