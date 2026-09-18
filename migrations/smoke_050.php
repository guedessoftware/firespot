<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach(['portal_skin_catalog','partner_portal_presentations','partner_portal_migrations'] as $table){
    $statement=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$statement->execute([$table]);if((int)$statement->fetchColumn()!==1)throw new RuntimeException('Tabela ausente: '.$table);
}
$previewColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_migrations' AND COLUMN_NAME IN ('preview_mobile_approved','preview_desktop_approved','preview_approved_by_id','preview_approved_at')")->fetchAll(PDO::FETCH_COLUMN)?:[];
if(count(array_unique($previewColumns))!==4)throw new RuntimeException('Confirmações de prévia móvel e desktop ausentes.');
$skins=$pdo->query("SELECT code FROM portal_skin_catalog WHERE active=1 AND code IN ('balanced','quick-connect','sponsored-focus','access-catalog','institutional')")->fetchAll(PDO::FETCH_COLUMN)?:[];
if(count(array_unique($skins))!==5)throw new RuntimeException('Catálogo inicial de modelos visuais incompleto.');
$missing=(int)$pdo->query('SELECT COUNT(*) FROM partners p LEFT JOIN partner_portal_migrations m ON m.partner_id=p.id WHERE m.partner_id IS NULL')->fetchColumn();
if($missing>0)throw new RuntimeException('Estabelecimento sem estado de migração V3.');
$activatedWithoutPublished=(int)$pdo->query("SELECT COUNT(*) FROM partner_portal_migrations WHERE status IN ('v3_active','rollback_available') AND source_portal_mode<>'v3'")->fetchColumn();
if($activatedWithoutPublished>0)throw new RuntimeException('Migração marcada ativa sem origem V3.');
echo "Smoke 050 OK: skins e migração manual do Portal V3 versionadas.\n";
