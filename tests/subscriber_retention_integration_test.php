<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/subscriber_retention.php';

$checks=0;
function retention_expect(bool $condition,string $message):void{global$checks;$checks++;if(!$condition)throw new RuntimeException($message);}

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->beginTransaction();
try{
    $profileId=(int)$pdo->query("SELECT id FROM subscriber_benefit_profiles WHERE active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $hotspot=$pdo->query('SELECT h.id,h.partner_id FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id WHERE h.active=1 AND p.active=1 ORDER BY h.id LIMIT 1')->fetch();
    if($profileId<=0||!$hotspot)throw new RuntimeException('Pré-requisitos do teste de retenção ausentes.');
    $public=bin2hex(random_bytes(16));$pdo->prepare("INSERT INTO subscriber_accounts (public_id,status,display_name,document_hash) VALUES (?,'active','Retenção transacional',?)")->execute([$public,hash('sha256','retention-'.$public)]);$accountId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_entitlements (account_id,benefit_profile_id,provider,status,result_code,verified_at,valid_until) VALUES (?,?,'hubsoft','active','TEST',NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$accountId,$profileId]);$entitlementId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_devices (public_id,account_id,label,device_kind,status,authorization_mode,device_token_hash,authorized_at) VALUES (?,?,'Retenção','guest','revoked','while_authorized',?,NOW())")->execute([bin2hex(random_bytes(16)),$accountId,hash('sha256',random_bytes(16))]);$deviceId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_device_identifiers (device_id,identifier_type,identifier_hash,identifier_hint,active,first_seen_at,last_seen_at,created_at,updated_at) VALUES (?,'mac',?,'AA:BB',0,DATE_SUB(NOW(),INTERVAL 200 DAY),DATE_SUB(NOW(),INTERVAL 200 DAY),DATE_SUB(NOW(),INTERVAL 200 DAY),DATE_SUB(NOW(),INTERVAL 200 DAY))")->execute([$deviceId,hash('sha256',random_bytes(16))]);$identifierId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_access_grants (public_id,account_id,device_id,entitlement_id,partner_id,hotspot_id,idempotency_key_hash,status,device_mac,device_ip,reservation_expires_at,ended_at,failure_code,failure_detail,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'failed','02:00:00:00:00:01','10.0.0.2',DATE_SUB(NOW(),INTERVAL 40 DAY),DATE_SUB(NOW(),INTERVAL 40 DAY),'TEST','detalhe transitório',DATE_SUB(NOW(),INTERVAL 40 DAY),DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([bin2hex(random_bytes(16)),$accountId,$deviceId,$entitlementId,(int)$hotspot['partner_id'],(int)$hotspot['id'],hash('sha256',random_bytes(16))]);$grantId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_login_challenges (public_id,account_id,document_hash,contact_type,contact_hint,target_encrypted,code_hash,status,expires_at,origin_hash,created_at,updated_at) VALUES (?,?,?,'phone','•••• 0000','cipher',?,'expired',DATE_SUB(NOW(),INTERVAL 8 DAY),?,DATE_SUB(NOW(),INTERVAL 8 DAY),DATE_SUB(NOW(),INTERVAL 8 DAY))")->execute([bin2hex(random_bytes(16)),$accountId,hash('sha256','doc-'.$public),hash('sha256','code-'.$public),hash('sha256','origin-'.$public)]);$challengeId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_trusted_devices (account_id,token_hash,user_agent_hash,expires_at,revoked_at,created_at,updated_at) VALUES (?,?,?,DATE_SUB(NOW(),INTERVAL 100 DAY),DATE_SUB(NOW(),INTERVAL 100 DAY),DATE_SUB(NOW(),INTERVAL 100 DAY),DATE_SUB(NOW(),INTERVAL 100 DAY))")->execute([$accountId,hash('sha256','token-'.$public),hash('sha256','ua-'.$public)]);$trustedId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_audit (account_id,actor_type,action,target_type,target_id,metadata,origin_hash,created_at) VALUES (?,'system','retention.test','account',?,'{}',?,DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([$accountId,(string)$accountId,hash('sha256','audit-'.$public)]);$auditId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_auth_attempts (account_id,document_hash,origin_hash,outcome,result_code,created_at) VALUES (?,?,?,'denied','INVALID_CREDENTIALS',DATE_SUB(NOW(),INTERVAL 40 DAY))")->execute([$accountId,hash('sha256','auth-doc-'.$public),hash('sha256','auth-origin-'.$public)]);$authAttemptId=(int)$pdo->lastInsertId();

    $result=fs_subscriber_retention_run($pdo);retention_expect(($result['challenges_purged']??0)>=1,'Desafio antigo não foi purgado.');
    $st=$pdo->prepare('SELECT target_encrypted,origin_hash FROM subscriber_login_challenges WHERE id=?');$st->execute([$challengeId]);$challenge=$st->fetch();retention_expect($challenge&&$challenge['target_encrypted']==='PURGED'&&$challenge['origin_hash']===null,'Desafio reteve contato ou origem.');
    $st=$pdo->prepare('SELECT device_mac,device_ip,failure_detail FROM subscriber_access_grants WHERE id=?');$st->execute([$grantId]);$grant=$st->fetch();retention_expect($grant&&$grant['device_mac']===null&&$grant['device_ip']===null&&$grant['failure_detail']===null,'Concessão reteve contexto de rede antigo.');
    $st=$pdo->prepare('SELECT origin_hash FROM subscriber_audit WHERE id=?');$st->execute([$auditId]);retention_expect($st->fetchColumn()===null,'Auditoria reteve origem antiga.');
    $st=$pdo->prepare('SELECT COUNT(*) FROM subscriber_trusted_devices WHERE id=?');$st->execute([$trustedId]);retention_expect((int)$st->fetchColumn()===0,'Navegador confiável antigo não foi removido.');
    $st=$pdo->prepare('SELECT COUNT(*) FROM subscriber_device_identifiers WHERE id=?');$st->execute([$identifierId]);retention_expect((int)$st->fetchColumn()===0,'Identificador inativo antigo não foi removido.');
    $st=$pdo->prepare('SELECT COUNT(*) FROM subscriber_auth_attempts WHERE id=?');$st->execute([$authAttemptId]);retention_expect((int)$st->fetchColumn()===0&&($result['auth_attempts_deleted']??0)>=1,'Tentativa antiga de autenticação não foi removida.');
    $pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
echo "OK: {$checks} verificações transacionais da política de retenção.\n";
