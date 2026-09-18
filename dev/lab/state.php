<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/env.php';
if (env('APP_ENV') !== 'local' || env('DB_DATABASE') !== 'firespot_local' || env('FIRESPOT_LAB') !== '1') exit(64);
$pdo=db();
$orders=$pdo->query("SELECT o.id,o.public_id,o.provider_payment_id,o.status,o.radius_phase,o.radius_coa_status,o.radius_coa_error_code,o.radius_paid_baseline_seconds,r.acctsessionid,r.acctsessiontime,r.acctstoptime FROM guest_orders o LEFT JOIN radacct r ON r.username=o.radius_username WHERE o.partner_id=(SELECT id FROM partners WHERE code='FIRESPOT-LAB') ORDER BY o.id DESC,r.radacctid DESC")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['orders'=>$orders,'active_accounting'=>(int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE nasipaddress='10.203.30.2' AND acctstoptime IS NULL")->fetchColumn()],JSON_THROW_ON_ERROR)."\n";
