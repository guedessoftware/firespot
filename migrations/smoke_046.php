<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

require_once __DIR__ . '/../app/db.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach (['partner_nas_ownerships','hotspot_change_requests','partner_network_reservations'] as $table) {
    $statement=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $statement->execute([$table]);
    if ((int)$statement->fetchColumn()!==1) throw new RuntimeException('Tabela de infraestrutura ausente: ' . $table);
}
$columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspots'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach (['desired_config_version','applied_config_version','management_state','last_change_request_id'] as $column) {
    if (!in_array($column,$columns,true)) throw new RuntimeException('Coluna versionada ausente: ' . $column);
}
$duplicates=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT nas_id FROM partner_nas_ownerships GROUP BY nas_id HAVING COUNT(*)>1) x')->fetchColumn();
if ($duplicates!==0) throw new RuntimeException('Um NAS possui mais de um proprietário.');
$reservationColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_network_reservations'")->fetchAll(PDO::FETCH_COLUMN)?:[];
if(!in_array('active_nas_network',$reservationColumns,true))throw new RuntimeException('A reserva não protege conflito de rede por NAS.');
$reservationIndexes=$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_network_reservations' AND NON_UNIQUE=0")->fetchAll(PDO::FETCH_COLUMN)?:[];
if(!in_array('uq_partner_network_active_nas_network',$reservationIndexes,true))throw new RuntimeException('A unicidade de rede ativa por NAS está ausente.');
$activeWithoutNas=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots WHERE active=1 AND nas_id IS NULL')->fetchColumn();
if($activeWithoutNas!==0)throw new RuntimeException('Existe instalação ativa sem NAS associado.');
$requestScope=(int)$pdo->query("SELECT COUNT(*) FROM hotspot_change_requests r LEFT JOIN partner_nas_ownerships o ON o.nas_id=r.nas_id AND o.partner_id=r.partner_id LEFT JOIN partner_hotspots h ON h.id=r.hotspot_id WHERE o.nas_id IS NULL OR (r.hotspot_id IS NOT NULL AND (h.id IS NULL OR h.partner_id<>r.partner_id OR (r.operation='hotspot_deactivate' AND h.nas_id<>r.nas_id)))")->fetchColumn();
if($requestScope!==0)throw new RuntimeException('Fila de infraestrutura cruza NAS, instalação ou estabelecimento.');
$reservationScope=(int)$pdo->query("SELECT COUNT(*) FROM partner_network_reservations r LEFT JOIN partner_nas_ownerships o ON o.nas_id=r.nas_id AND o.partner_id=r.partner_id LEFT JOIN partner_hotspots h ON h.id=r.hotspot_id WHERE o.nas_id IS NULL OR (r.hotspot_id IS NOT NULL AND (h.id IS NULL OR h.partner_id<>r.partner_id OR (r.state='applied' AND h.nas_id<>r.nas_id)))")->fetchColumn();
if($reservationScope!==0)throw new RuntimeException('Reserva de rede cruza NAS, instalação ou estabelecimento.');
$foreignUse=(int)$pdo->query("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN partner_hotspots h ON h.nas_id=o.nas_id AND h.active=1 WHERE h.partner_id<>o.partner_id")->fetchColumn();
if($foreignUse!==0)throw new RuntimeException('NAS próprio está associado a instalação ativa de outro estabelecimento.');
$constraint=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspots' AND CONSTRAINT_NAME='chk_partner_hotspots_active_nas' AND CONSTRAINT_TYPE='CHECK'")->fetchColumn();
if((int)$constraint!==1)throw new RuntimeException('A regra de NAS obrigatório para ponto ativo não está no schema.');
echo "Smoke 046 OK: propriedade exclusiva, reservas e fila operacional disponíveis.\n";
