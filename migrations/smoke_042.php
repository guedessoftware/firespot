<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$columns = ['welcome_screen_enabled','single_option_direct_enabled'];
$in = implode(',',array_fill(0,count($columns),'?'));
$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_configurations' AND COLUMN_NAME IN ($in)");
$st->execute($columns);
if ((int)$st->fetchColumn() !== count($columns)) throw new RuntimeException('Preferencias de navegacao do Portal V3 incompletas.');

$invalid = (int)$pdo->query('SELECT COUNT(*) FROM partner_portal_configurations WHERE welcome_screen_enabled NOT IN (0,1) OR single_option_direct_enabled NOT IN (0,1)')->fetchColumn();
if ($invalid !== 0) throw new RuntimeException('Preferencias de navegacao com valores invalidos.');

echo "Smoke 042 OK: preferencias de navegacao disponiveis e validas.\n";
