<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../hubsoft_cache.php';

date_default_timezone_set('America/Manaus');
$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$result = fs_hubsoft_capability_probe($app);
if (!empty($result['ok'])) {
    echo 'hubsoft_capability=ok code=' . $result['code'] . ' result=' . $result['result'] . PHP_EOL;
    exit(0);
}
echo 'hubsoft_capability=failed code=' . $result['code'] . PHP_EOL;
exit(2);
