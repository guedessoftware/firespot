<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/personal_data_crypto.php';
require_once __DIR__ . '/monetization.php';
require_once __DIR__ . '/partner_hotspots.php';

function fs_ad_secret(): string
{
    $secret = trim((string)env('AD_PROOF_KEY', env('APP_KEY', '')));
    if (strlen($secret) < 32) $secret = hash('sha256', fs_personal_data_key(), false);
    return $secret;
}

function fs_ad_device_hash(array $context): ?string
{
    $value = strtoupper(trim((string)($context['mac'] ?? $context['did'] ?? $context['ip'] ?? '')));
    return $value === '' ? null : hash_hmac('sha256', $value, fs_ad_secret());
}

function fs_ad_delivery_begin(PDO $pdo, array $partner, array $ad, int $durationSeconds, array $context = [], bool $simulation = false): array
{
    if (!fs_monetization_schema_ready($pdo)) return ['token'=>'','public_id'=>'','ready_at'=>time()+$durationSeconds,'legacy'=>true];
    $partnerId=(int)($partner['id']??0);$adId=(int)($ad['id']??0);
    if($partnerId<=0||$adId<=0)throw new InvalidArgumentException('Contexto da publicidade inválido.');
    $hotspotId=fs_partner_hotspot_id($partner);
    if(fs_partner_hotspots_schema_ready($pdo)&&$hotspotId===null){$defaultHotspot=fs_partner_hotspot_default($pdo,$partnerId,true);$hotspotId=$defaultHotspot?fs_partner_hotspot_id($defaultHotspot):null;}
    if(fs_partner_hotspots_schema_ready($pdo)&&$hotspotId===null)throw new RuntimeException('Instalação técnica da publicidade não encontrada.');
    $st=$pdo->prepare("SELECT a.* FROM custom_ads a JOIN partners p ON p.id=? WHERE a.id=? AND a.active=1 AND p.active=1 AND p.ads_enabled=1 AND (a.partner_id=p.id OR (a.partner_id IS NULL AND p.allow_global_ads=1)) AND (a.start_date IS NULL OR a.start_date<=CURRENT_DATE) AND (a.end_date IS NULL OR a.end_date>=CURRENT_DATE) LIMIT 1");
    $st->execute([$partnerId,$adId]);$stored=$st->fetch(PDO::FETCH_ASSOC);
    if(!$stored)throw new RuntimeException('Anúncio não elegível.');
    $campaignId=max(0,(int)($stored['campaign_id']??0))?:null;
    if($campaignId){
        $elig=$pdo->prepare("SELECT 1 FROM ad_campaigns c JOIN ad_campaign_partners cp ON cp.campaign_id=c.id WHERE c.id=? AND cp.partner_id=? AND cp.status='active' AND c.status='active' AND c.starts_at<=NOW() AND c.ends_at>NOW() AND (c.campaign_type='institutional' OR (c.campaign_type='commercial' AND c.funded_cents>c.spent_cents AND EXISTS (SELECT 1 FROM partner_monetization_agreements ma WHERE ma.partner_id=cp.partner_id AND ma.status='active' AND ma.advertising_enabled=1 AND ma.starts_at<=NOW() AND (ma.ends_at IS NULL OR ma.ends_at>NOW())))) LIMIT 1");
        $elig->execute([$campaignId,$partnerId]);if(!$elig->fetchColumn())throw new RuntimeException('Campanha comercial indisponível.');
    }
    $token=bin2hex(random_bytes(32));$public=bin2hex(random_bytes(16));$duration=max(0,min(300,$durationSeconds));
    $sessionId=session_id()?:bin2hex(random_bytes(16));
    $hotspotSchemaReady=fs_partner_hotspots_schema_ready($pdo);
    if($hotspotSchemaReady){
        $st=$pdo->prepare("INSERT INTO ad_deliveries (public_id,token_hash,campaign_id,ad_id,partner_id,hotspot_id,session_hash,device_hash,state,is_simulation,started_at,ready_at,expires_at) VALUES (?,?,?,?,?,?,?,?,'started',?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND),DATE_ADD(NOW(),INTERVAL ? SECOND))");
        $st->execute([$public,hash('sha256',$token),$campaignId,$adId,$partnerId,$hotspotId,hash_hmac('sha256',$sessionId,fs_ad_secret()),fs_ad_device_hash($context),$simulation?1:0,$duration,$duration+1800]);
    }else{
        $st=$pdo->prepare("INSERT INTO ad_deliveries (public_id,token_hash,campaign_id,ad_id,partner_id,session_hash,device_hash,state,is_simulation,started_at,ready_at,expires_at) VALUES (?,?,?,?,?,?,?,'started',?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND),DATE_ADD(NOW(),INTERVAL ? SECOND))");
        $st->execute([$public,hash('sha256',$token),$campaignId,$adId,$partnerId,hash_hmac('sha256',$sessionId,fs_ad_secret()),fs_ad_device_hash($context),$simulation?1:0,$duration,$duration+1800]);
    }
    return ['token'=>$token,'public_id'=>$public,'ready_at'=>time()+$duration,'legacy'=>false];
}

function fs_ad_delivery_by_token(PDO $pdo, string $token, bool $forUpdate=false): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/i',$token))return null;
    $st=$pdo->prepare('SELECT *,ready_at<=NOW() proof_ready,expires_at>NOW() proof_alive FROM ad_deliveries WHERE token_hash=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $st->execute([hash('sha256',strtolower($token))]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_ad_delivery_assert_session(array $delivery): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)return;
    $sessionId=session_id();if($sessionId===''||!hash_equals((string)$delivery['session_hash'],hash_hmac('sha256',$sessionId,fs_ad_secret())))throw new RuntimeException('A comprovação pertence a outra sessão.');
}

function fs_ad_delivery_complete(PDO $pdo, string $token): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $delivery=fs_ad_delivery_by_token($pdo,$token,true);
        if(!$delivery)throw new RuntimeException('Comprovação da publicidade inválida.');
        fs_ad_delivery_assert_session($delivery);
        if((int)$delivery['is_simulation']===1){$pdo->prepare("UPDATE ad_deliveries SET state='completed',completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);if($ownsTransaction)$pdo->commit();return $delivery;}
        if(empty($delivery['proof_alive']))throw new RuntimeException('Comprovação da publicidade expirada.');
        if(empty($delivery['proof_ready']))throw new RuntimeException('O tempo obrigatório da publicidade ainda não terminou.');
        if(in_array((string)$delivery['state'],['rejected','expired'],true))throw new RuntimeException('Comprovação da publicidade indisponível.');
        $pdo->prepare("UPDATE ad_deliveries SET state=IF(state='connected','connected','completed'),completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);
        if($ownsTransaction)$pdo->commit();return array_merge($delivery,['state'=>'completed']);
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_ledger_insert(PDO $pdo, array $delivery, string $sourceType, int $amountCents, array $snapshot): void
{
    if($amountCents<=0)return;
    $eventKey=hash('sha256',$sourceType.':'.(string)$delivery['public_id']);
    $agreement=fs_monetization_current_agreement($pdo,(int)$delivery['partner_id']);
    $st=$pdo->prepare("INSERT IGNORE INTO monetization_ledger (partner_id,agreement_id,campaign_id,source_type,source_id,event_key,amount_cents,status,rule_snapshot,occurred_at,approved_at) VALUES (?,?,?,?,?,?,?,'approved',?,NOW(),NOW())");
    $st->execute([(int)$delivery['partner_id'],$agreement?(int)$agreement['id']:null,(int)$delivery['campaign_id'],$sourceType,(string)$delivery['public_id'],$eventKey,$amountCents,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function fs_ad_charge_event(PDO $pdo, array $delivery, string $metric): bool
{
    if(empty($delivery['campaign_id'])||(int)$delivery['is_simulation']===1)return false;
    $qualifiedColumn=['view'=>'qualified_view_at','click'=>'qualified_click_at','lead'=>'qualified_lead_at'][$metric]??null;if($qualifiedColumn===null)return false;
    $deliveryLock=$pdo->prepare("SELECT {$qualifiedColumn} FROM ad_deliveries WHERE id=? LIMIT 1 FOR UPDATE");$deliveryLock->execute([(int)$delivery['id']]);$alreadyQualified=$deliveryLock->fetchColumn();if($alreadyQualified!==null&&$alreadyQualified!==false)return false;
    $agreement=fs_monetization_current_agreement($pdo,(int)$delivery['partner_id'],true);
    if(!$agreement||(int)$agreement['advertising_enabled']!==1)return false;
    $st=$pdo->prepare('SELECT *,starts_at<=NOW() AND ends_at>NOW() time_eligible FROM ad_campaigns WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([(int)$delivery['campaign_id']]);$campaign=$st->fetch(PDO::FETCH_ASSOC);
    $st=$pdo->prepare("SELECT * FROM ad_campaign_partners WHERE campaign_id=? AND partner_id=? AND status='active' LIMIT 1 FOR UPDATE");$st->execute([(int)$delivery['campaign_id'],(int)$delivery['partner_id']]);$assignment=$st->fetch(PDO::FETCH_ASSOC);
    if(!$campaign||!$assignment||$campaign['campaign_type']!=='commercial'||$campaign['status']!=='active'||empty($campaign['time_eligible']))return false;
    $charge=0;$credit=0;$snapshot=['metric'=>$metric,'campaign_id'=>(int)$campaign['id'],'assignment_id'=>(int)$assignment['id']];
    if($metric==='view'){
        if(empty($delivery['device_hash']))return false;
        $max=(int)($assignment['max_views_per_device']?:$campaign['max_views_per_device']);$hours=max(1,(int)$campaign['frequency_window_hours']);
        $freq=$pdo->prepare("SELECT COUNT(*) FROM ad_deliveries WHERE campaign_id=? AND partner_id=? AND device_hash=? AND qualified_view_at IS NOT NULL AND started_at>=DATE_SUB(NOW(),INTERVAL ? HOUR) AND id<>?");
        $freq->execute([(int)$campaign['id'],(int)$delivery['partner_id'],$delivery['device_hash'],$hours,(int)$delivery['id']]);
        if((int)$freq->fetchColumn()>=$max)return false;
        $adTotal=(int)$campaign['advertiser_view_remainder_millis']+(int)$campaign['advertiser_view_cpm_cents'];
        $partnerTotal=(int)$assignment['partner_view_remainder_millis']+(int)$assignment['partner_view_cpm_cents'];
        $charge=intdiv($adTotal,1000);$credit=intdiv($partnerTotal,1000);
        $snapshot+=['advertiser_cpm_cents'=>(int)$campaign['advertiser_view_cpm_cents'],'partner_cpm_cents'=>(int)$assignment['partner_view_cpm_cents']];
        if((int)$campaign['spent_cents']+$charge>(int)$campaign['funded_cents'])return false;
        $pdo->prepare('UPDATE ad_campaigns SET advertiser_view_remainder_millis=?,spent_cents=spent_cents+?,status=IF(spent_cents+?>=funded_cents,\'exhausted\',status),updated_at=NOW() WHERE id=?')->execute([$adTotal%1000,$charge,$charge,(int)$campaign['id']]);
        $pdo->prepare('UPDATE ad_campaign_partners SET partner_view_remainder_millis=?,updated_at=NOW() WHERE id=?')->execute([$partnerTotal%1000,(int)$assignment['id']]);
        $pdo->prepare('UPDATE ad_deliveries SET qualified_view_at=COALESCE(qualified_view_at,NOW()),updated_at=NOW() WHERE id=?')->execute([(int)$delivery['id']]);
        fs_ad_ledger_insert($pdo,$delivery,'ad_view',$credit,$snapshot);
        return true;
    }
    if($metric==='lead'){$charge=(int)$campaign['advertiser_lead_cents'];$credit=(int)$assignment['partner_lead_cents'];}
    elseif($metric==='click'){$charge=(int)$campaign['advertiser_click_cents'];$credit=(int)$assignment['partner_click_cents'];}
    else return false;
    if($charge<=0||(int)$campaign['spent_cents']+$charge>(int)$campaign['funded_cents'])return false;
    $pdo->prepare('UPDATE ad_campaigns SET spent_cents=spent_cents+?,status=IF(spent_cents+?>=funded_cents,\'exhausted\',status),updated_at=NOW() WHERE id=?')->execute([$charge,$charge,(int)$campaign['id']]);
    $pdo->prepare("UPDATE ad_deliveries SET {$qualifiedColumn}=COALESCE({$qualifiedColumn},NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);
    fs_ad_ledger_insert($pdo,$delivery,'ad_'.$metric,$credit,$snapshot+['advertiser_cents'=>$charge,'partner_cents'=>$credit]);
    return true;
}

function fs_ad_delivery_attach_access(PDO $pdo, string $token, string $username): void
{
    $username=substr(trim($username),0,64);if($username==='')throw new InvalidArgumentException('Acesso RADIUS inválido.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $delivery=fs_ad_delivery_by_token($pdo,$token,true);
        if(!$delivery||empty($delivery['completed_at']))throw new RuntimeException('Comprovação da publicidade ainda não concluída.');
        fs_ad_delivery_assert_session($delivery);
        if(!empty($delivery['access_username'])&&!hash_equals((string)$delivery['access_username'],$username))throw new RuntimeException('A comprovação já está vinculada a outro acesso.');
        $pdo->prepare('UPDATE ad_deliveries SET access_username=COALESCE(access_username,?),updated_at=NOW() WHERE id=?')->execute([$username,(int)$delivery['id']]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_delivery_connect_id(PDO $pdo, int $deliveryId): array
{
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT *,ready_at<=NOW() proof_ready,expires_at>NOW() proof_alive FROM ad_deliveries WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$deliveryId]);$delivery=$st->fetch(PDO::FETCH_ASSOC);
        if(!$delivery)throw new RuntimeException('Comprovação da publicidade inválida.');
        if(empty($delivery['completed_at'])||empty($delivery['proof_ready']))throw new RuntimeException('A publicidade ainda não foi concluída.');
        if(empty($delivery['proof_alive']))throw new RuntimeException('Comprovação da publicidade expirada.');
        $pdo->prepare("UPDATE ad_deliveries SET state='connected',connected_at=COALESCE(connected_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);
        $delivery['state']='connected';$delivery['connected_at']=$delivery['connected_at']?:date('Y-m-d H:i:s');
        if(empty($delivery['qualified_view_at']))fs_ad_charge_event($pdo,$delivery,'view');
        $lead=$pdo->prepare("SELECT * FROM ad_leads WHERE delivery_id=? AND status='pending' LIMIT 1 FOR UPDATE");$lead->execute([(int)$delivery['id']]);$lead=$lead->fetch(PDO::FETCH_ASSOC);
        if($lead){
            fs_ad_charge_event($pdo,$delivery,'lead');
            $pdo->prepare("UPDATE ad_leads SET status='qualified',qualified_at=COALESCE(qualified_at,NOW()),updated_at=NOW() WHERE id=?")->execute([(int)$lead['id']]);
        }
        $pdo->commit();return $delivery;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_delivery_connect(PDO $pdo, string $token): array
{
    $delivery=fs_ad_delivery_by_token($pdo,$token,false);
    if(!$delivery)throw new RuntimeException('Comprovação da publicidade inválida.');
    return fs_ad_delivery_connect_id($pdo,(int)$delivery['id']);
}

function fs_ad_reconcile_radius_connections(PDO $pdo, int $limit=100): int
{
    $limit=min(500,max(1,$limit));
    $rows=$pdo->query("SELECT d.id FROM ad_deliveries d WHERE d.state='completed' AND d.access_username IS NOT NULL AND d.connected_at IS NULL AND d.completed_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) AND EXISTS (SELECT 1 FROM radacct r WHERE r.username=d.access_username AND r.acctstarttime>=d.completed_at) ORDER BY d.id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $count=0;foreach($rows as $row){try{fs_ad_delivery_connect_id($pdo,(int)$row['id']);$count++;}catch(Throwable $e){error_log('[ad reconcile] delivery='.(int)$row['id'].' '.$e->getMessage());}}
    return $count;
}

function fs_ad_charge_click_by_delivery_id(PDO $pdo,int $deliveryId):bool
{
    $pdo->beginTransaction();try{$st=$pdo->prepare("SELECT * FROM ad_deliveries WHERE id=? AND state='connected' AND connected_at IS NOT NULL LIMIT 1 FOR UPDATE");$st->execute([$deliveryId]);$delivery=$st->fetch(PDO::FETCH_ASSOC);if(!$delivery){$pdo->commit();return false;}$qualified=fs_ad_charge_event($pdo,$delivery,'click');$pdo->commit();return $qualified;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_normalize_phone(string $phone): string
{
    $digits=preg_replace('/\D+/','',$phone);
    if(strpos($digits,'55')===0&&strlen($digits)>=12)$digits=substr($digits,2);
    if(!preg_match('/^[1-9][0-9]{9,10}$/',$digits))throw new InvalidArgumentException('Informe um celular válido com DDD.');
    return '+55'.$digits;
}

function fs_ad_offer_message(PDO $pdo,string $publicId,string $name,array $ad):string
{
    $message=trim((string)($ad['offer_message']??$ad['campaign_offer']??''));
    if($message==='')$message='Olá, '.$name.'! Você demonstrou interesse em "'.(string)$ad['title'].'".';
    if(!empty($ad['link_url']))$message.=' '.trim((string)$ad['link_url']);
    $revokeUrl=rtrim(fs_public_base_url($pdo),'/').'/portal/oferta_revogar.php?lead='.rawurlencode($publicId).'&sig='.rawurlencode(fs_ad_lead_revoke_signature($publicId));
    return substr($message,0,750).' Para revogar: '.$revokeUrl;
}

function fs_ad_create_lead(PDO $pdo, string $deliveryToken, string $name, string $phone, bool $consented): array
{
    $name=trim(preg_replace('/\s+/u',' ',$name));
    $length=function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name);
    if($length<2||$length>80)throw new InvalidArgumentException('Informe seu nome.');
    if(!$consented)throw new InvalidArgumentException('Confirme que deseja receber esta oferta no celular.');
    $phone=fs_ad_normalize_phone($phone);$phoneHash=fs_personal_hash($phone);
    $pdo->beginTransaction();
    try{
        $delivery=fs_ad_delivery_by_token($pdo,$deliveryToken,true);
        if(!$delivery||empty($delivery['proof_alive'])||empty($delivery['completed_at']))throw new RuntimeException('Esta oferta não está mais disponível.');
        fs_ad_delivery_assert_session($delivery);
        $sameDelivery=$pdo->prepare('SELECT public_id,status,duplicate_of_id,message_status FROM ad_leads WHERE delivery_id=? LIMIT 1');$sameDelivery->execute([(int)$delivery['id']]);$same=$sameDelivery->fetch(PDO::FETCH_ASSOC);
        if($same){$pdo->commit();return ['public_id'=>(string)$same['public_id'],'status'=>(string)$same['status'],'duplicate'=>!empty($same['duplicate_of_id']),'message_queued'=>in_array((string)$same['message_status'],['queued','accepted'],true)];}
        $rate=$pdo->prepare('SELECT COUNT(*) FROM ad_leads l JOIN ad_deliveries d ON d.id=l.delivery_id WHERE d.session_hash=? AND l.created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$rate->execute([(string)$delivery['session_hash']]);if((int)$rate->fetchColumn()>=5)throw new RuntimeException('Limite temporário de solicitações atingido. Tente mais tarde.');
        $st=$pdo->prepare('SELECT a.*,c.lead_capture_enabled campaign_lead,c.offer_message campaign_offer,c.consent_version,c.offer_valid_until FROM custom_ads a LEFT JOIN ad_campaigns c ON c.id=a.campaign_id WHERE a.id=? LIMIT 1');$st->execute([(int)$delivery['ad_id']]);$ad=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ad||(!(int)($ad['lead_capture_enabled']??0)&&!(int)($ad['campaign_lead']??0)))throw new RuntimeException('Esta campanha não recebe contatos.');
        $existing=$pdo->prepare("SELECT id FROM ad_leads WHERE campaign_id<=>? AND partner_id=? AND phone_hash=? AND status IN ('pending','qualified') AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY id DESC LIMIT 1");
        $existing->execute([$delivery['campaign_id']?:null,(int)$delivery['partner_id'],$phoneHash]);$duplicate=(int)($existing->fetchColumn()?:0);
        $consent='Desejo receber no celular a oferta desta campanha. Posso revogar este consentimento.';$public=bin2hex(random_bytes(16));
        $status=$duplicate>0?'duplicate':'pending';
        $st=$pdo->prepare("INSERT INTO ad_leads (public_id,delivery_id,campaign_id,ad_id,partner_id,name_encrypted,phone_encrypted,phone_hash,phone_last4,consent_version,consent_text_hash,consent_at,status,duplicate_of_id,message_status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'none',DATE_ADD(NOW(),INTERVAL 180 DAY))");
        $st->execute([$public,(int)$delivery['id'],$delivery['campaign_id']?:null,(int)$delivery['ad_id'],(int)$delivery['partner_id'],$duplicate?null:fs_personal_encrypt($name),$duplicate?null:fs_personal_encrypt($phone),$duplicate?null:$phoneHash,$duplicate?null:substr(preg_replace('/\D+/','',$phone),-4),substr((string)($ad['consent_version']??'offer-v1'),0,32),hash('sha256',$consent),date('Y-m-d H:i:s'),$status,$duplicate?:null]);
        $leadId=(int)$pdo->lastInsertId();
        if(!$duplicate){
            $message=fs_ad_offer_message($pdo,$public,$name,$ad);
            $idempotency=hash('sha256','ad_lead:'.$public);$payload=fs_personal_encrypt(json_encode(['to'=>$phone,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $queue=$pdo->prepare("INSERT INTO promo_queue (to_msisdn,msg,purpose,reference_type,reference_id,idempotency_key,payload_encrypted,status,scheduled_at) VALUES ('ENCRYPTED','ENCRYPTED_AD_OFFER','ad_offer','ad_lead',?,?,?,'pending',NOW())");
            $queue->execute([$leadId,$idempotency,$payload]);$queueId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE ad_leads SET message_queue_id=?,message_status='queued' WHERE id=?")->execute([$queueId,$leadId]);
            if(!empty($delivery['connected_at'])){fs_ad_charge_event($pdo,$delivery,'lead');$pdo->prepare("UPDATE ad_leads SET status='qualified',qualified_at=NOW() WHERE id=?")->execute([$leadId]);$status='qualified';}
        }
        $pdo->commit();return ['public_id'=>$public,'status'=>$status,'duplicate'=>$duplicate>0,'message_queued'=>$duplicate===0];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_retry_offer(PDO $pdo,int $queueId):array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT q.id queue_id,q.status queue_status,l.id lead_id,l.public_id,l.partner_id,l.status lead_status,l.expires_at,l.name_encrypted,l.phone_encrypted,a.title,a.link_url,a.offer_message,a.active ad_active,a.end_date ad_ends_at,c.offer_message campaign_offer,c.offer_valid_until,c.ends_at campaign_ends_at,c.status campaign_status,c.name campaign_name,p.name partner_name,
          CASE WHEN c.offer_valid_until IS NOT NULL THEN c.offer_valid_until>=CURRENT_DATE
               WHEN c.ends_at IS NOT NULL THEN c.ends_at>=NOW()
               WHEN a.end_date IS NOT NULL THEN a.end_date>=CURRENT_DATE
               ELSE l.expires_at>NOW() END offer_alive
          FROM promo_queue q JOIN ad_leads l ON l.id=q.reference_id AND q.reference_type='ad_lead' JOIN custom_ads a ON a.id=l.ad_id JOIN partners p ON p.id=l.partner_id LEFT JOIN ad_campaigns c ON c.id=l.campaign_id WHERE q.id=? AND q.purpose='ad_offer' LIMIT 1 FOR UPDATE");
        $st->execute([$queueId]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['queue_status']!=='error')throw new RuntimeException('Falha de oferta indisponível para reenvio.');
        if(in_array((string)$row['lead_status'],['duplicate','revoked','anonymized'],true)||strtotime((string)$row['expires_at'])<=time())throw new RuntimeException('O consentimento não está mais disponível para reenvio.');
        if((int)$row['ad_active']!==1||(string)($row['campaign_status']??'')==='cancelled')throw new RuntimeException('A oferta foi desativada e não pode ser reenviada.');
        if(empty($row['offer_alive']))throw new RuntimeException('A validade da oferta terminou.');
        $name=fs_personal_decrypt((string)$row['name_encrypted']);$phone=fs_personal_decrypt((string)$row['phone_encrypted']);
        if($name===''||$phone==='')throw new RuntimeException('Os dados da oferta não estão mais disponíveis.');
        $message=fs_ad_offer_message($pdo,(string)$row['public_id'],$name,$row);$payload=fs_personal_encrypt(json_encode(['to'=>$phone,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $upd=$pdo->prepare("UPDATE promo_queue SET status='pending',attempts=0,scheduled_at=NOW(),accepted_at=NULL,failed_at=NULL,last_error=NULL,payload_encrypted=?,to_msisdn='ENCRYPTED',msg='ENCRYPTED_AD_OFFER',updated_at=NOW() WHERE id=? AND status='error'");$upd->execute([$payload,$queueId]);if($upd->rowCount()!==1)throw new RuntimeException('A oferta foi alterada por outro processo.');
        $pdo->prepare("UPDATE ad_leads SET message_status='queued',updated_at=NOW() WHERE id=?")->execute([(int)$row['lead_id']]);
        if($ownsTransaction)$pdo->commit();
        return ['partner_id'=>(int)$row['partner_id'],'lead_id'=>(int)$row['lead_id'],'campaign_name'=>(string)($row['campaign_name']??''),'partner_name'=>(string)$row['partner_name']];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_lead_revoke_signature(string $publicId):string{return hash_hmac('sha256','lead-revoke:'.$publicId,fs_ad_secret());}

function fs_ad_revoke_lead_public(PDO $pdo,string $publicId,string $signature):bool
{
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||!hash_equals(fs_ad_lead_revoke_signature($publicId),$signature))return false;
    $pdo->beginTransaction();
    try{$st=$pdo->prepare('SELECT id,message_queue_id,status FROM ad_leads WHERE public_id=? LIMIT 1 FOR UPDATE');$st->execute([$publicId]);$lead=$st->fetch(PDO::FETCH_ASSOC);if(!$lead){$pdo->rollBack();return false;}
        if($lead['status']!=='anonymized')$pdo->prepare("UPDATE ad_leads SET name_encrypted=NULL,phone_encrypted=NULL,phone_hash=NULL,status='revoked',message_status=IF(message_status IN ('none','queued'),'cancelled',message_status),anonymized_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$lead['id']]);
        if(!empty($lead['message_queue_id']))$pdo->prepare("UPDATE promo_queue SET status=IF(status IN ('pending','sending'),'cancelled',status),payload_encrypted=NULL,to_msisdn='PURGED',msg='PURGED',updated_at=NOW() WHERE id=?")->execute([(int)$lead['message_queue_id']]);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_revoke_leads_by_phone(PDO $pdo,string $phone):int
{
    $normalized=fs_ad_normalize_phone($phone);$phoneHash=fs_personal_hash($normalized);$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT id,message_queue_id FROM ad_leads WHERE phone_hash=? AND status NOT IN ('revoked','anonymized') FOR UPDATE");$st->execute([$phoneHash]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$rows){if($ownsTransaction)$pdo->commit();return 0;}
        $leadIds=array_map('intval',array_column($rows,'id'));$in=implode(',',array_fill(0,count($leadIds),'?'));
        $pdo->prepare("UPDATE ad_leads SET name_encrypted=NULL,phone_encrypted=NULL,phone_hash=NULL,status='revoked',message_status=IF(message_status IN ('none','queued'),'cancelled',message_status),anonymized_at=NOW(),updated_at=NOW() WHERE id IN ($in)")->execute($leadIds);
        $queueIds=array_values(array_filter(array_map('intval',array_column($rows,'message_queue_id'))));if($queueIds){$qin=implode(',',array_fill(0,count($queueIds),'?'));$pdo->prepare("UPDATE promo_queue SET status=IF(status IN ('pending','sending'),'cancelled',status),payload_encrypted=NULL,to_msisdn='PURGED',msg='PURGED',updated_at=NOW() WHERE id IN ($qin)")->execute($queueIds);}
        if($ownsTransaction)$pdo->commit();return count($leadIds);
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_ad_partner_leads(PDO $pdo, int $partnerId, bool $withPii=false, int $limit=100): array
{
    $st=$pdo->prepare('SELECT l.*,a.title ad_title,c.name campaign_name FROM ad_leads l JOIN custom_ads a ON a.id=l.ad_id LEFT JOIN ad_campaigns c ON c.id=l.campaign_id WHERE l.partner_id=? ORDER BY l.id DESC LIMIT '.min(500,max(1,$limit)));
    $st->execute([$partnerId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as &$row){$row['phone_masked']='•••• ••••-'.(string)$row['phone_last4'];if($withPii&&!in_array($row['status'],['revoked','anonymized'],true)){try{$row['name']=fs_personal_decrypt((string)$row['name_encrypted']);$row['phone']=fs_personal_decrypt((string)$row['phone_encrypted']);}catch(Throwable $e){$row['name']='';$row['phone']='';}}unset($row['name_encrypted'],$row['phone_encrypted'],$row['phone_hash']);}unset($row);
    return $rows;
}

function fs_ad_anonymize_expired_leads(PDO $pdo): int
{
    $pdo->beginTransaction();try{$ids=$pdo->query("SELECT message_queue_id FROM ad_leads WHERE expires_at<NOW() AND anonymized_at IS NULL AND message_queue_id IS NOT NULL FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN)?:[];$st=$pdo->prepare("UPDATE ad_leads SET name_encrypted=NULL,phone_encrypted=NULL,phone_hash=NULL,status='anonymized',message_status=IF(message_status IN ('none','queued'),'cancelled',message_status),anonymized_at=NOW(),updated_at=NOW() WHERE expires_at<NOW() AND anonymized_at IS NULL");$st->execute();$count=$st->rowCount();if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("UPDATE promo_queue SET status=IF(status IN ('pending','sending'),'cancelled',status),payload_encrypted=NULL,to_msisdn='PURGED',msg='PURGED',updated_at=NOW() WHERE id IN ($in)")->execute(array_map('intval',$ids));}$pdo->commit();return $count;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
