<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$legacyPartners = (int)$pdo->query("SELECT COUNT(*) FROM partners WHERE dns_name IS NULL OR TRIM(dns_name)='' OR LOWER(TRIM(dns_name))='local' OR LOWER(TRIM(dns_name)) LIKE '%.local'")->fetchColumn();
$legacyHotspots = (int)$pdo->query("SELECT COUNT(*) FROM partner_hotspots WHERE dns_name IS NULL OR TRIM(dns_name)='' OR LOWER(TRIM(dns_name))='local' OR LOWER(TRIM(dns_name)) LIKE '%.local'")->fetchColumn();
$divergentDefaults = (int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id WHERE h.is_default=1 AND NOT(h.dns_name<=>p.dns_name)')->fetchColumn();

if ($legacyPartners !== 0 || $legacyHotspots !== 0 || $divergentDefaults !== 0) {
    fwrite(STDERR,"Smoke 031 falhou: partner_local={$legacyPartners}, hotspot_local={$legacyHotspots}, principal_divergente={$divergentDefaults}.\n");
    exit(1);
}
echo "Smoke 031 OK: nomes DNS privados migrados e instalações principais sincronizadas.\n";
