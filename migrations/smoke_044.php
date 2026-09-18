<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$invalidPurpose = (int)$pdo->query("SELECT COUNT(*) FROM partners
    WHERE self_service_enabled=1 AND access_purpose IS NULL")->fetchColumn();
if ($invalidPurpose !== 0) throw new RuntimeException('Há autogestão ativa sem finalidade definida.');

$missingConfiguration = (int)$pdo->query("SELECT COUNT(*) FROM partners p
    LEFT JOIN partner_portal_configurations c ON c.partner_id=p.id AND c.state='published'
    WHERE p.self_service_enabled=1 AND c.id IS NULL")->fetchColumn();
if ($missingConfiguration !== 0) throw new RuntimeException('Há autogestão ativa sem configuração publicada.');

$legacySmoke = __DIR__ . '/smoke_022.php';
$process = proc_open(['/usr/bin/php',$legacySmoke],[
    0=>['file','/dev/null','r'],
    1=>['file','/dev/null','a'],
    2=>['file','/dev/null','a'],
],$pipes,__DIR__,null,['bypass_shell'=>true]);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('O smoke administrativo legado 022 ainda falha.');
}

echo "Smoke 044 OK: gates de autogestão possuem finalidade e configuração publicável.\n";
