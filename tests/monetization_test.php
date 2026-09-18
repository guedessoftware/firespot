<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if(getenv('PERSONAL_DATA_KEY')===false||trim((string)getenv('PERSONAL_DATA_KEY'))==='')putenv('PERSONAL_DATA_KEY='.str_repeat('monetization-test-key-',4));

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/ad_monetization.php';
require_once __DIR__ . '/../app/monetization_export.php';

function mon_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

mon_assert(fs_monetization_access_fee(['model'=>'revenue_share','access_fee_type'=>'percentage','access_fee_value'=>1000],500)===50,'Percentual em basis points incorreto.');
mon_assert(fs_monetization_access_fee(['model'=>'hybrid','access_fee_type'=>'fixed','access_fee_value'=>75],500)===75,'Tarifa fixa incorreta.');
mon_assert(fs_monetization_access_fee(['model'=>'subscription','access_fee_type'=>'fixed','access_fee_value'=>75],500)===0,'Mensalidade não pode gerar split.');
mon_assert(fs_monetization_access_fee(['model'=>'revenue_share','access_fee_type'=>'fixed','access_fee_value'=>900],500)===500,'Comissão não pode superar a venda.');
mon_assert(fs_ad_normalize_phone('(92) 99999-1234')==='+5592999991234','Normalização de telefone incorreta.');

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$partnerId=(int)$pdo->query('SELECT id FROM partners WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
if($partnerId<=0)throw new RuntimeException('Não há estabelecimento para o teste transacional.');
$pdo->beginTransaction();
try{
    $pdo->prepare("INSERT INTO partner_monetization_agreements (partner_id,model,access_fee_type,access_fee_value,advertising_enabled,status,version,starts_at) VALUES (?,'subscription','none',0,1,'active',999999,DATE_SUB(NOW(),INTERVAL 1 MINUTE))")->execute([$partnerId]);
    $agreementId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO advertisers (legal_name,active) VALUES ('Teste transacional FireSpot',1)")->execute();$advertiserId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ad_campaigns (advertiser_id,name,campaign_type,status,starts_at,ends_at,budget_cents,funded_cents,advertiser_view_cpm_cents,advertiser_lead_cents,lead_capture_enabled) VALUES (?,'Teste transacional','commercial','active',DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 HOUR),1000,1000,1000,20,1)")->execute([$advertiserId]);$campaignId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ad_campaign_partners (campaign_id,partner_id,status,partner_view_cpm_cents,partner_lead_cents,joined_at) VALUES (?,?,'active',500,10,NOW())")->execute([$campaignId,$partnerId]);
    $pdo->prepare("INSERT INTO custom_ads (title,image_url,media_type,media_url,duration_sec,weight,active,campaign_id,ad_kind,lead_capture_enabled) VALUES ('Teste transacional','/pixel.png','image','/pixel.png',5,1,1,?,'commercial',1)")->execute([$campaignId]);$adId=(int)$pdo->lastInsertId();
    $insert=$pdo->prepare("INSERT INTO ad_deliveries (public_id,token_hash,campaign_id,ad_id,partner_id,session_hash,device_hash,state,started_at,ready_at,expires_at,completed_at,connected_at) VALUES (?,?,?,?,?,?,?,'connected',DATE_SUB(NOW(),INTERVAL 10 SECOND),DATE_SUB(NOW(),INTERVAL 5 SECOND),DATE_ADD(NOW(),INTERVAL 10 MINUTE),NOW(),NOW())");
    $deliveries=[];foreach([1,2] as $i){$public=bin2hex(random_bytes(16));$insert->execute([$public,hash('sha256',bin2hex(random_bytes(32))),$campaignId,$adId,$partnerId,hash('sha256','session'.$i),hash('sha256','device'.$i)]);$deliveries[]=['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'campaign_id'=>$campaignId,'partner_id'=>$partnerId,'device_hash'=>hash('sha256','device'.$i),'is_simulation'=>0];}
    $firstQualified=fs_ad_charge_event($pdo,$deliveries[0],'view');
    if(!$firstQualified){$debug=['agreement'=>fs_monetization_current_agreement($pdo,$partnerId),'campaign'=>$pdo->query('SELECT * FROM ad_campaigns WHERE id='.(int)$campaignId)->fetch(),'assignment'=>$pdo->query('SELECT * FROM ad_campaign_partners WHERE campaign_id='.(int)$campaignId)->fetch()];throw new RuntimeException('Primeira visualização deveria ser qualificada: '.json_encode($debug));}
    mon_assert(fs_ad_charge_event($pdo,$deliveries[1],'view'),'Segunda visualização deveria ser qualificada.');
    mon_assert(fs_ad_charge_event($pdo,$deliveries[1],'lead'),'Lead deveria ser qualificado.');
    mon_assert(!fs_ad_charge_event($pdo,$deliveries[1],'view'),'A mesma visualização não pode ser cobrada duas vezes.');
    mon_assert(!fs_ad_charge_event($pdo,$deliveries[1],'lead'),'O mesmo lead não pode ser cobrado duas vezes.');
    $st=$pdo->prepare('SELECT spent_cents,advertiser_view_remainder_millis FROM ad_campaigns WHERE id=?');$st->execute([$campaignId]);$campaign=$st->fetch();
    mon_assert((int)$campaign['spent_cents']===22,'Consumo da campanha não conciliou CPM e lead.');
    $st=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM monetization_ledger WHERE campaign_id=?');$st->execute([$campaignId]);
    mon_assert((int)$st->fetchColumn()===11,'Repasse ao estabelecimento não conciliou carry de CPM e lead.');
    $export=fs_monetization_export_rows($pdo,$partnerId,date('Y-m-d',strtotime('-1 day')),date('Y-m-d',strtotime('+1 day')));
    $exportRow=array_values(array_filter($export,static fn(array $row):bool=>(string)$row['campaign_name']==='Teste transacional'))[0]??null;
    mon_assert(is_array($exportRow)&&!array_key_exists('phone_encrypted',$exportRow)&&!array_key_exists('name_encrypted',$exportRow),'Exportação financeira não pode incluir dados pessoais de leads.');
    $settlementId=fs_monetization_create_settlement($pdo,$partnerId,date('Y-m-d'),date('Y-m-d'));
    $paymentBlocked=false;try{fs_monetization_mark_settlement_paid($pdo,$settlementId,'pix','teste-sem-aprovacao',null);}catch(RuntimeException $e){$paymentBlocked=true;}
    mon_assert($paymentBlocked,'Fechamento aberto não pode ser marcado como pago.');
    fs_monetization_approve_settlement($pdo,$settlementId,null);fs_monetization_mark_settlement_paid($pdo,$settlementId,'pix','teste-aprovado',null);
    $st=$pdo->prepare('SELECT COUNT(*) FROM monetization_ledger WHERE campaign_id=? AND status=\'settled\'');$st->execute([$campaignId]);
    mon_assert((int)$st->fetchColumn()===2,'A aprovação e o repasse devem liquidar todos os lançamentos do fechamento.');
    $pdo->prepare('UPDATE ad_campaigns SET budget_cents=1500 WHERE id=?')->execute([$campaignId]);
    $order=fs_monetization_create_order($pdo,'campaign',500,'teste@example.com',['campaign_id'=>$campaignId]);
    $paid=['id'=>'pay-test-'.$order['id'],'external_reference'=>$order['external_ref'],'transaction_amount'=>5.0,'status'=>'approved','status_detail'=>'accredited'];
    fs_monetization_order_mark($pdo,$order,$paid);
    fs_monetization_order_mark($pdo,$order,$paid);
    $st=$pdo->prepare('SELECT funded_cents FROM ad_campaigns WHERE id=?');$st->execute([$campaignId]);
    mon_assert((int)$st->fetchColumn()===1500,'Webhook repetido não pode duplicar o crédito da campanha.');
    $pendingEvent=hash('sha256','pending-review-'.$campaignId);$pdo->prepare("INSERT INTO monetization_ledger (partner_id,agreement_id,campaign_id,source_type,source_id,event_key,amount_cents,status,occurred_at) VALUES (?,?,?,'adjustment','pending-test',?,7,'pending',NOW())")->execute([$partnerId,$agreementId,$campaignId,$pendingEvent]);$pendingId=(int)$pdo->lastInsertId();fs_monetization_review_ledger($pdo,$pendingId,true,'TEST_APPROVED');
    $st=$pdo->prepare('SELECT status FROM monetization_ledger WHERE id=?');$st->execute([$pendingId]);mon_assert($st->fetchColumn()==='approved','Revisão manual deve aprovar lançamento pendente uma única vez.');
    $leadPublic=bin2hex(random_bytes(16));$pdo->prepare("INSERT INTO ad_leads (public_id,delivery_id,campaign_id,ad_id,partner_id,name_encrypted,phone_encrypted,phone_hash,phone_last4,consent_version,consent_text_hash,consent_at,status,message_status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,'offer-v1',?,NOW(),'qualified','failed',DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$leadPublic,$deliveries[0]['id'],$campaignId,$adId,$partnerId,fs_personal_encrypt('Cliente Teste'),fs_personal_encrypt('+5592999991234'),fs_personal_hash('+5592999991234'),'1234',hash('sha256','consent')]);$leadId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO promo_queue (to_msisdn,msg,purpose,reference_type,reference_id,idempotency_key,status,attempts,max_attempts,scheduled_at,failed_at) VALUES ('PURGED','PURGED','ad_offer','ad_lead',?,?,'error',5,5,NOW(),NOW())")->execute([$leadId,hash('sha256','ad_lead:'.$leadPublic)]);$queueId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE ad_leads SET message_queue_id=? WHERE id=?')->execute([$queueId,$leadId]);
    $retry=fs_ad_retry_offer($pdo,$queueId);mon_assert((int)$retry['partner_id']===$partnerId,'Reenvio deve permanecer vinculado ao estabelecimento original.');$st=$pdo->prepare('SELECT status,attempts,payload_encrypted FROM promo_queue WHERE id=?');$st->execute([$queueId]);$retried=$st->fetch();mon_assert($retried['status']==='pending'&&(int)$retried['attempts']===0&&!empty($retried['payload_encrypted']),'Reenvio deve reconstruir payload protegido e reutilizar a fila sem duplicar lead.');
    mon_assert(fs_ad_revoke_leads_by_phone($pdo,'(92) 99999-1234')===1,'Exclusão por telefone deve localizar o lead por hash sem consultar o número em claro.');$st=$pdo->prepare('SELECT status,name_encrypted,phone_encrypted FROM ad_leads WHERE id=?');$st->execute([$leadId]);$revoked=$st->fetch();mon_assert($revoked['status']==='revoked'&&$revoked['name_encrypted']===null&&$revoked['phone_encrypted']===null,'Revogação deve remover os dados pessoais do lead.');$st=$pdo->prepare('SELECT status,payload_encrypted FROM promo_queue WHERE id=?');$st->execute([$queueId]);$purged=$st->fetch();mon_assert($purged['status']==='cancelled'&&$purged['payload_encrypted']===null,'Revogação deve cancelar e purgar a oferta ainda pendente.');
    $refunded=$paid;$refunded['status']='refunded';$refunded['status_detail']='refunded';fs_monetization_order_mark($pdo,$order,$refunded);fs_monetization_order_mark($pdo,$order,$refunded);
    $st=$pdo->prepare('SELECT status FROM ad_campaigns WHERE id=?');$st->execute([$campaignId]);
    mon_assert($st->fetchColumn()==='cancelled','Estorno do orçamento deve congelar a campanha sem apagar o histórico.');
    $terminalBlocked=false;try{fs_monetization_save_campaign($pdo,['id'=>$campaignId,'advertiser_id'=>$advertiserId,'name'=>'Teste transacional','campaign_type'=>'commercial','status'=>'paused','starts_at'=>date('Y-m-d H:i:s',strtotime('-1 hour')),'ends_at'=>date('Y-m-d H:i:s',strtotime('+1 hour')),'budget_cents'=>1500],null);}catch(RuntimeException $e){$terminalBlocked=true;}
    mon_assert($terminalBlocked,'Campanha encerrada não pode ser alterada e reativada por edição.');
    $pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

echo "Monetization tests passed.\n";
