<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/cli/migration_framework.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$invalidSql="SELECT COUNT(*) FROM partners p WHERE p.self_service_enabled=1
    AND (p.access_purpose IS NULL OR NOT EXISTS (
      SELECT 1 FROM partner_portal_configurations c WHERE c.partner_id=p.id AND c.state='published'
    ))";
$auditSql="SELECT COUNT(*) FROM partner_admin_audit WHERE action='migration.self_service_disabled'
    AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.migration'))='044'";
$beforeInvalid=(int)$pdo->query($invalidSql)->fetchColumn();
$beforeAudit=(int)$pdo->query($auditSql)->fetchColumn();
$source=(string)file_get_contents(__DIR__.'/../migrations/044_partner_self_service_invariant.sql');
$statements=fs_migration_sql_statements($source);
if(count($statements)!==2)throw new RuntimeException('Migração 044 não contém duas instruções.');

$pdo->beginTransaction();
try{
    foreach($statements as $statement)$pdo->exec($statement);
    $duringInvalid=(int)$pdo->query($invalidSql)->fetchColumn();
    $duringAudit=(int)$pdo->query($auditSql)->fetchColumn();
    if($duringInvalid!==0)throw new RuntimeException('A migração 044 não fechou todos os gates inválidos.');
    if($duringAudit!==$beforeAudit+$beforeInvalid)throw new RuntimeException('A migração 044 não registrou exatamente os gates alterados.');
    $pdo->rollBack();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}

$afterInvalid=(int)$pdo->query($invalidSql)->fetchColumn();
$afterAudit=(int)$pdo->query($auditSql)->fetchColumn();
if($afterInvalid!==$beforeInvalid||$afterAudit!==$beforeAudit)throw new RuntimeException('Rollback da prova 044 não restaurou o estado inicial.');
echo "OK: migração 044 validada em transação e revertida; candidatos={$beforeInvalid}.\n";
