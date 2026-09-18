<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('PAYMENT_CREDENTIAL_KEY='.base64_encode(str_repeat('W',32)));
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/portal_configuration.php';
require_once __DIR__.'/../app/credential_crypto.php';
$checks=0;function portal_config_db_expect(bool $ok,string $message):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$partner=$pdo->query("SELECT p.* FROM partners p JOIN partner_hotspots h ON h.partner_id=p.id AND h.active=1 WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM partner_portal_configurations c WHERE c.partner_id=p.id AND c.state='draft') ORDER BY p.id LIMIT 1")->fetch();
if(!$partner)throw new RuntimeException('Nenhum estabelecimento isolado para o teste.');$partnerId=(int)$partner['id'];
$pdo->beginTransaction();
try{
    $pdo->prepare("UPDATE partners SET portal_mode='v3',independent_billing=1 WHERE id=?")->execute([$partnerId]);
    $st=$pdo->prepare("INSERT INTO payment_wallets (partner_id,provider,name,environment,public_key,access_token_encrypted,credential_hint,active) VALUES (?,'mercadopago','Carteira transacional','sandbox','TEST-public',?,'test',1)");$st->execute([$partnerId,fs_encrypt_credential('TEST-access-token')]);$walletId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE partners SET payment_wallet_id=? WHERE id=?')->execute([$walletId,$partnerId]);
    $pdo->prepare("INSERT INTO partner_payment_plans (partner_id,name,description,price_cents,duration_minutes,download_kbps,upload_kbps,sort_order,active) VALUES (?,'Plano transacional','Somente teste',500,60,1000,500,999,1)")->execute([$partnerId]);
    $draft=fs_portal_config_apply_preset($pdo,$partnerId,'paid','system',null);portal_config_db_expect($draft['state']==='draft'&&$draft['preset_code']==='paid','Preset não criou rascunho versionado.');
    // O preset altera somente capacidades. O teste precisa declarar a navegação
    // inicial para não herdar a personalização do estabelecimento real escolhido.
    $draft=fs_portal_config_save_draft($pdo,$partnerId,['welcome_screen_enabled'=>1,'single_option_direct_enabled'=>0],'system',null);
    $partner=$pdo->query('SELECT * FROM partners WHERE id='.$partnerId)->fetch();$validation=fs_portal_config_validate($pdo,$partner,$draft);portal_config_db_expect($validation['ready']===true,'Preset pago transacional não ficou pronto: '.implode(',',array_column($validation['blocks'],'code')));
    $first=fs_portal_config_publish($pdo,$partnerId,'system',null);portal_config_db_expect($first['state']==='published','Publicação atômica não ativou a revisão.');
    $custom=fs_portal_config_save_draft($pdo,$partnerId,['promotional_ads_enabled'=>1,'lead_capture_enabled'=>1,'welcome_screen_enabled'=>0,'single_option_direct_enabled'=>1],'system',null);portal_config_db_expect(fs_portal_config_label($custom)==='Personalizado','Alteração após preset não virou Personalizado.');
    $second=fs_portal_config_publish($pdo,$partnerId,'system',null);portal_config_db_expect((int)$second['revision']>(int)$first['revision']&&(int)$second['lead_capture_enabled']===1&&(int)$second['welcome_screen_enabled']===0&&(int)$second['single_option_direct_enabled']===1,'Segunda publicação não preservou a configuração personalizada e sua navegação.');
    $rolled=fs_portal_config_rollback($pdo,$partnerId,(int)$first['id'],'system',null);portal_config_db_expect($rolled['state']==='published'&&$rolled['source']==='rollback'&&(int)$rolled['paid_access_enabled']===1&&(int)$rolled['lead_capture_enabled']===0&&(int)$rolled['welcome_screen_enabled']===1&&(int)$rolled['single_option_direct_enabled']===0,'Rollback não restaurou a revisão escolhida.');
    $publishedCount=(int)$pdo->query("SELECT COUNT(*) FROM partner_portal_configurations WHERE partner_id={$partnerId} AND state='published'")->fetchColumn();portal_config_db_expect($publishedCount===1,'Mais de uma revisão publicada após rollback.');
    $pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
echo "OK: {$checks} verificações transacionais de publicação e rollback.\n";
