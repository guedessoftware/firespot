<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
$st->execute(['subscriber_auth_attempts']);
if((int)$st->fetchColumn()!==1)throw new RuntimeException('Tabela subscriber_auth_attempts ausente.');
$columns=['document_hash','origin_hash','outcome','result_code','created_at'];
$in=implode(',',array_fill(0,count($columns),'?'));
$st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriber_auth_attempts' AND COLUMN_NAME IN ($in)");
$st->execute($columns);
if((int)$st->fetchColumn()!==count($columns))throw new RuntimeException('Estrutura de limitação da autenticação incompleta.');
$st=$pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE skey='subscriber_retention_auth_attempt_days'");$st->execute();
if((int)$st->fetchColumn()!==1)throw new RuntimeException('Retenção das tentativas de autenticação ausente.');
echo "Smoke 039 OK: autenticação HubSoft protegida e retenção configurada.\n";
