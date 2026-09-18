<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$wanted=['access_token_validated_at','webhook_secret_validated_at'];
$in=implode(',',array_fill(0,count($wanted),'?'));
$statement=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_wallets' AND COLUMN_NAME IN ($in)");
$statement->execute($wanted);$found=$statement->fetchAll(PDO::FETCH_COLUMN)?:[];
if(count(array_intersect($wanted,$found))!==count($wanted))throw new RuntimeException('Datas de validação da carteira ausentes.');
$invalid=(int)$pdo->query("SELECT COUNT(*) FROM payment_wallets WHERE active=1 AND access_token_validated_at IS NULL")->fetchColumn();
if($invalid>0)throw new RuntimeException('Carteira ativa sem registro de validação do token.');
$duplicate=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT partner_id FROM payment_wallets WHERE partner_id IS NOT NULL AND active=1 GROUP BY partner_id HAVING COUNT(*)>1) duplicated")->fetchColumn();
if($duplicate>0)throw new RuntimeException('Estabelecimento com mais de uma carteira própria ativa.');
$invalidBinding=(int)$pdo->query("SELECT COUNT(*) FROM partners p LEFT JOIN payment_wallets w ON w.id=p.payment_wallet_id AND w.partner_id=p.id AND w.active=1 WHERE p.independent_billing=1 AND (p.payment_wallet_id IS NULL OR w.id IS NULL)")->fetchColumn();
if($invalidBinding>0)throw new RuntimeException('Recebimento independente aponta para carteira ausente, inativa ou de outro estabelecimento.');
echo "Smoke 049 OK: ciclo de credenciais da carteira rastreável.\n";
