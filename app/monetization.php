<?php

declare(strict_types=1);

require_once __DIR__ . '/marketplace.php';
require_once __DIR__ . '/payment_wallets.php';
require_once __DIR__ . '/payment_provider.php';
require_once __DIR__ . '/credential_crypto.php';

function fs_monetization_schema_ready(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monetization_ledger'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fs_monetization_save_agreement(PDO $pdo, int $partnerId, array $data, ?int $adminId): int
{
    $model = (string)($data['model'] ?? 'subscription');
    $feeType = (string)($data['access_fee_type'] ?? 'none');
    $billing = (string)($data['billing_source'] ?? 'mercadopago');
    if (!in_array($model, ['subscription','revenue_share','hybrid'], true)) throw new InvalidArgumentException('Modelo comercial inválido.');
    if (!in_array($feeType, ['none','percentage','fixed'], true)) throw new InvalidArgumentException('Tipo de comissão inválido.');
    if (!in_array($billing, ['mercadopago','external_firenetwork'], true)) throw new InvalidArgumentException('Origem de cobrança inválida.');
    $monthly = max(0, (int)($data['monthly_fee_cents'] ?? 0));
    $feeValue = max(0, (int)($data['access_fee_value'] ?? 0));
    if ($feeType === 'percentage' && $feeValue > 10000) throw new InvalidArgumentException('A comissão percentual não pode superar 100%.');
    if ($model === 'subscription') { $feeType = 'none'; $feeValue = 0; }
    if ($model === 'revenue_share') $monthly = 0;
    if (in_array($model, ['revenue_share','hybrid'], true) && ($feeType === 'none' || $feeValue < 1)) throw new InvalidArgumentException('Defina a comissão das vendas para este modelo.');
    if (in_array($model, ['subscription','hybrid'], true) && $monthly < 1) throw new InvalidArgumentException('Defina a mensalidade deste modelo.');
    $startsAt = trim((string)($data['starts_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $startsTs = strtotime($startsAt);
    if ($startsTs === false) throw new InvalidArgumentException('Início de vigência inválido.');
    if ($startsTs > time() + 300) throw new InvalidArgumentException('A ativação futura de contratos ainda não é suportada. Use o início atual.');
    $startsAt = date('Y-m-d H:i:s', $startsTs);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$partnerId]);
        if (!$st->fetchColumn()) throw new RuntimeException('Estabelecimento não encontrado.');
        if (in_array($model,['revenue_share','hybrid'],true)) {
            $account=$pdo->prepare("SELECT 1 FROM marketplace_accounts WHERE partner_id=? AND provider='mercadopago' AND status='active' LIMIT 1");
            $account->execute([$partnerId]);
            if (!$account->fetchColumn()) throw new RuntimeException('Autorize primeiro a conta Mercado Pago Marketplace do estabelecimento.');
        }
        $v = $pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM partner_monetization_agreements WHERE partner_id=?');
        $v->execute([$partnerId]);
        $version = (int)$v->fetchColumn();
        $pdo->prepare("UPDATE partner_monetization_agreements SET status='ended',ends_at=COALESCE(ends_at,?),updated_at=NOW() WHERE partner_id=? AND status='active' AND (ends_at IS NULL OR ends_at>?)")
            ->execute([$startsAt,$partnerId,$startsAt]);
        $st = $pdo->prepare("INSERT INTO partner_monetization_agreements
            (partner_id,model,monthly_fee_cents,access_fee_type,access_fee_value,advertising_enabled,billing_source,status,version,starts_at,accepted_at,created_by_admin_id)
            VALUES (?,?,?,?,?,?,?,'active',?,?,NOW(),?)");
        $st->execute([$partnerId,$model,$monthly,$feeType,$feeValue,!empty($data['advertising_enabled'])?1:0,$billing,$version,$startsAt,$adminId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_monetization_agreements(PDO $pdo, ?int $partnerId = null): array
{
    $sql = 'SELECT a.*,p.name partner_name,p.code partner_code FROM partner_monetization_agreements a JOIN partners p ON p.id=a.partner_id';
    $params = [];
    if ($partnerId !== null) { $sql .= ' WHERE a.partner_id=?'; $params[] = $partnerId; }
    $sql .= ' ORDER BY (a.status=\'active\') DESC,a.starts_at DESC,a.id DESC';
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_monetization_save_advertiser(PDO $pdo, array $data): int
{
    $id = max(0, (int)($data['id'] ?? 0));
    $legal = trim((string)($data['legal_name'] ?? ''));
    if ($legal === '') throw new InvalidArgumentException('Informe a razão social do anunciante.');
    $values = [$legal,trim((string)($data['trade_name'] ?? '')) ?: null,preg_replace('/\D+/', '', (string)($data['tax_id'] ?? '')) ?: null,trim((string)($data['contact_name'] ?? '')) ?: null,trim((string)($data['contact_email'] ?? '')) ?: null,preg_replace('/\D+/', '', (string)($data['contact_phone'] ?? '')) ?: null,!empty($data['active'])?1:0];
    if ($id > 0) {
        $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            $current=$pdo->prepare('SELECT active FROM advertisers WHERE id=? LIMIT 1 FOR UPDATE');$current->execute([$id]);$wasActive=$current->fetchColumn();if($wasActive===false)throw new RuntimeException('Anunciante não encontrado.');
            $st = $pdo->prepare('UPDATE advertisers SET legal_name=?,trade_name=?,tax_id=?,contact_name=?,contact_email=?,contact_phone=?,active=?,updated_at=NOW() WHERE id=?');$st->execute(array_merge($values,[$id]));
            if((int)$wasActive===1&&(int)$values[6]===0)$pdo->prepare("UPDATE ad_campaigns SET status='paused',updated_at=NOW() WHERE advertiser_id=? AND status='active'")->execute([$id]);
            if($ownsTransaction)$pdo->commit();return $id;
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    $st = $pdo->prepare('INSERT INTO advertisers (legal_name,trade_name,tax_id,contact_name,contact_email,contact_phone,active) VALUES (?,?,?,?,?,?,?)');
    $st->execute($values);
    return (int)$pdo->lastInsertId();
}

function fs_monetization_advertisers(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM advertisers ORDER BY active DESC,trade_name,legal_name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_monetization_save_campaign(PDO $pdo, array $data, ?int $adminId): int
{
    $id = max(0, (int)($data['id'] ?? 0));
    $type = (string)($data['campaign_type'] ?? 'commercial');
    $status = (string)($data['status'] ?? 'draft');
    if (!in_array($type,['institutional','commercial'],true)) throw new InvalidArgumentException('Tipo de campanha inválido.');
    if (!in_array($status,['draft','awaiting_payment','active','paused','exhausted','completed','cancelled'],true)) throw new InvalidArgumentException('Status de campanha inválido.');
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Informe o nome da campanha.');
    $starts = strtotime((string)($data['starts_at'] ?? ''));
    $ends = strtotime((string)($data['ends_at'] ?? ''));
    if ($starts === false || $ends === false || $ends <= $starts) throw new InvalidArgumentException('Informe um período válido para a campanha.');
    $advertiserId = max(0,(int)($data['advertiser_id'] ?? 0)) ?: null;
    if ($type === 'commercial' && !$advertiserId) throw new InvalidArgumentException('Campanhas comerciais exigem anunciante.');
    if ($advertiserId) {
        $st=$pdo->prepare('SELECT active FROM advertisers WHERE id=? LIMIT 1');$st->execute([$advertiserId]);
        if ((int)($st->fetchColumn()?:0)!==1) throw new InvalidArgumentException('Selecione um anunciante ativo.');
    }
    $budget = max(0,(int)($data['budget_cents'] ?? 0));
    // O criativo e a segmentação podem ser preparados como rascunho antes de
    // o Financeiro definir o contrato e receber o orçamento. Fora do estado
    // draft, uma campanha comercial continua obrigada a possuir orçamento.
    if ($type === 'commercial' && $budget < 1 && $status !== 'draft') throw new InvalidArgumentException('Defina o orçamento financeiro antes de retirar a campanha do rascunho.');
    $leadCapture=!empty($data['lead_capture_enabled']);
    $offerMessage=substr(trim((string)($data['offer_message'] ?? '')),0,500) ?: null;
    if ($leadCapture && $offerMessage===null) throw new InvalidArgumentException('Informe a mensagem aprovada da oferta para captar contatos.');
    $offerValidUntil=trim((string)($data['offer_valid_until'] ?? '')) ?: null;
    if ($offerValidUntil!==null && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$offerValidUntil)) throw new InvalidArgumentException('Validade da oferta inválida.');
    $funded=0;$spent=0;
    if($id>0){
        $st=$pdo->prepare('SELECT advertiser_id,campaign_type,status,funded_cents,spent_cents FROM ad_campaigns WHERE id=? LIMIT 1');$st->execute([$id]);$stored=$st->fetch(PDO::FETCH_ASSOC);
        if(!$stored)throw new RuntimeException('Campanha não encontrada.');
        if(in_array((string)$stored['status'],['completed','cancelled'],true))throw new RuntimeException('Campanhas encerradas são imutáveis. Crie uma nova campanha para outra vigência.');
        $funded=(int)$stored['funded_cents'];$spent=(int)$stored['spent_cents'];
        if($budget<$funded||$budget<$spent)throw new InvalidArgumentException('O orçamento não pode ficar abaixo do valor já confirmado ou consumido.');
        if(($type!==(string)$stored['campaign_type']||$advertiserId!==(isset($stored['advertiser_id'])?(int)$stored['advertiser_id']:null))&&($funded>0||$spent>0))throw new RuntimeException('Tipo e anunciante não podem mudar após movimentação financeira. Crie outra campanha.');
    }
    if($type==='commercial'&&$status==='active'){
        if($funded<1)$status='awaiting_payment';
        elseif($funded<=$spent)$status='exhausted';
    }
    $fields = [
        $advertiserId,$name,$type,$status,date('Y-m-d H:i:s',$starts),date('Y-m-d H:i:s',$ends),$budget,
        max(0,(int)($data['advertiser_view_cpm_cents'] ?? 0)),max(0,(int)($data['advertiser_click_cents'] ?? 0)),max(0,(int)($data['advertiser_lead_cents'] ?? 0)),
        $leadCapture?1:0,$offerMessage,$offerValidUntil,
        substr(trim((string)($data['consent_version'] ?? 'offer-v1')),0,32),min(720,max(1,(int)($data['frequency_window_hours'] ?? 24))),min(100,max(1,(int)($data['max_views_per_device'] ?? 1))),
    ];
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE ad_campaigns SET advertiser_id=?,name=?,campaign_type=?,status=?,starts_at=?,ends_at=?,budget_cents=?,advertiser_view_cpm_cents=?,advertiser_click_cents=?,advertiser_lead_cents=?,lead_capture_enabled=?,offer_message=?,offer_valid_until=?,consent_version=?,frequency_window_hours=?,max_views_per_device=?,updated_at=NOW() WHERE id=?');
        $st->execute(array_merge($fields,[$id]));
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO ad_campaigns (advertiser_id,name,campaign_type,status,starts_at,ends_at,budget_cents,advertiser_view_cpm_cents,advertiser_click_cents,advertiser_lead_cents,lead_capture_enabled,offer_message,offer_valid_until,consent_version,frequency_window_hours,max_views_per_device,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute(array_merge($fields,[$adminId]));
    return (int)$pdo->lastInsertId();
}

function fs_monetization_campaigns(PDO $pdo, ?int $partnerId = null): array
{
    $sql = 'SELECT c.*,a.trade_name advertiser_name,a.legal_name advertiser_legal_name';
    $params=[];
    if ($partnerId !== null) $sql .= ',cp.status partner_campaign_status,cp.partner_view_cpm_cents,cp.partner_click_cents,cp.partner_lead_cents';
    $sql .= ' FROM ad_campaigns c LEFT JOIN advertisers a ON a.id=c.advertiser_id';
    if ($partnerId !== null) { $sql .= ' JOIN ad_campaign_partners cp ON cp.campaign_id=c.id AND cp.partner_id=?'; $params[]=$partnerId; }
    $sql .= ' ORDER BY c.starts_at DESC,c.id DESC';
    $st=$pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_monetization_set_campaign_status(PDO $pdo,int $campaignId,string $status):void
{
    if(!in_array($status,['active','paused','completed','cancelled'],true))throw new InvalidArgumentException('Situação de campanha inválida.');
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT campaign_type,status,funded_cents,spent_cents FROM ad_campaigns WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$campaignId]);$campaign=$st->fetch(PDO::FETCH_ASSOC);
        if(!$campaign)throw new RuntimeException('Campanha não encontrada.');
        if(in_array((string)$campaign['status'],['completed','cancelled'],true)&&(string)$campaign['status']!==$status)throw new RuntimeException('Campanha encerrada não pode ser reativada.');
        if($status==='active'&&$campaign['campaign_type']==='commercial'&&(int)$campaign['funded_cents']<=(int)$campaign['spent_cents'])throw new RuntimeException('A campanha não possui saldo confirmado disponível.');
        $pdo->prepare('UPDATE ad_campaigns SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$campaignId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_set_advertiser_active(PDO $pdo,int $advertiserId,bool $active):void
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT id FROM advertisers WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$advertiserId]);if(!$st->fetchColumn())throw new RuntimeException('Anunciante não encontrado.');
        $pdo->prepare('UPDATE advertisers SET active=?,updated_at=NOW() WHERE id=?')->execute([$active?1:0,$advertiserId]);
        if(!$active)$pdo->prepare("UPDATE ad_campaigns SET status='paused',updated_at=NOW() WHERE advertiser_id=? AND status='active'")->execute([$advertiserId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_assign_campaign(PDO $pdo, int $campaignId, int $partnerId, array $rates): void
{
    $st=$pdo->prepare('SELECT * FROM ad_campaigns WHERE id=? LIMIT 1'); $st->execute([$campaignId]); $campaign=$st->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) throw new RuntimeException('Campanha não encontrada.');
    $view=max(0,(int)($rates['partner_view_cpm_cents']??0)); $click=max(0,(int)($rates['partner_click_cents']??0)); $lead=max(0,(int)($rates['partner_lead_cents']??0));
    if ($view>(int)$campaign['advertiser_view_cpm_cents'] || $click>(int)$campaign['advertiser_click_cents'] || $lead>(int)$campaign['advertiser_lead_cents']) throw new InvalidArgumentException('A remuneração do estabelecimento não pode superar o preço cobrado do anunciante.');
    $status=(string)($rates['status']??'active'); if(!in_array($status,['invited','active','suspended','ended'],true)) throw new InvalidArgumentException('Status de participação inválido.');
    if($status==='active'&&$campaign['campaign_type']==='commercial'){
        $agreement=fs_monetization_current_agreement($pdo,$partnerId);
        if(!$agreement||(int)$agreement['advertising_enabled']!==1)throw new InvalidArgumentException('Ative primeiro a publicidade remunerada no contrato do estabelecimento.');
    }
    $max=isset($rates['max_views_per_device'])&&$rates['max_views_per_device']!==''?min(100,max(1,(int)$rates['max_views_per_device'])):null;
    $st=$pdo->prepare('INSERT INTO ad_campaign_partners (campaign_id,partner_id,status,partner_view_cpm_cents,partner_click_cents,partner_lead_cents,max_views_per_device,joined_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status),partner_view_cpm_cents=VALUES(partner_view_cpm_cents),partner_click_cents=VALUES(partner_click_cents),partner_lead_cents=VALUES(partner_lead_cents),max_views_per_device=VALUES(max_views_per_device),updated_at=NOW()');
    $st->execute([$campaignId,$partnerId,$status,$view,$click,$lead,$max]);
}

function fs_monetization_add_campaign_funds(PDO $pdo, int $campaignId, int $amountCents, string $reference): void
{
    if ($amountCents <= 0) throw new InvalidArgumentException('Valor de recarga inválido.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT budget_cents,funded_cents,status FROM ad_campaigns WHERE id=? LIMIT 1 FOR UPDATE'); $st->execute([$campaignId]); $c=$st->fetch(PDO::FETCH_ASSOC);
        if(!$c) throw new RuntimeException('Campanha não encontrada.');
        $funded=(int)$c['funded_cents']+$amountCents;
        if($funded>(int)$c['budget_cents'])throw new RuntimeException('O crédito confirmado supera o orçamento contratado.');
        $newStatus=in_array((string)$c['status'],['draft','awaiting_payment'],true)&&$funded>0?'active':(string)$c['status'];
        $pdo->prepare('UPDATE ad_campaigns SET funded_cents=?,status=?,updated_at=NOW() WHERE id=?')->execute([$funded,$newStatus,$campaignId]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_ledger_adjust(PDO $pdo, int $partnerId, int $amountCents, string $reason, ?int $agreementId = null): int
{
    if ($amountCents === 0) throw new InvalidArgumentException('O ajuste não pode ser zero.');
    $source=bin2hex(random_bytes(16)); $event=hash('sha256','adjustment:'.$source);
    $st=$pdo->prepare("INSERT INTO monetization_ledger (partner_id,agreement_id,source_type,source_id,event_key,amount_cents,status,reason_code,rule_snapshot,occurred_at,approved_at) VALUES (?,?,'adjustment',?,?,?,'approved',?,?,NOW(),NOW())");
    $st->execute([$partnerId,$agreementId,$source,$event,$amountCents,substr($reason,0,40),json_encode(['reason'=>$reason],JSON_UNESCAPED_UNICODE)]);
    return (int)$pdo->lastInsertId();
}

function fs_monetization_review_ledger(PDO $pdo,int $ledgerId,bool $approve,string $reason=''):void
{
    $status=$approve?'approved':'rejected';$st=$pdo->prepare("UPDATE monetization_ledger SET status=?,reason_code=?,approved_at=IF(?='approved',NOW(),NULL),updated_at=NOW() WHERE id=? AND status='pending' AND settlement_id IS NULL");$st->execute([$status,substr($reason,0,40)?:null,$status,$ledgerId]);if($st->rowCount()!==1)throw new RuntimeException('Lançamento pendente não encontrado.');
}

function fs_monetization_reverse_ledger(PDO $pdo,int $ledgerId,string $reason):int
{
    $reason=trim($reason);if($reason==='')throw new InvalidArgumentException('Informe o motivo da reversão.');$pdo->beginTransaction();
    try{$st=$pdo->prepare('SELECT * FROM monetization_ledger WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$ledgerId]);$entry=$st->fetch(PDO::FETCH_ASSOC);if(!$entry||in_array($entry['status'],['rejected','reversed'],true))throw new RuntimeException('Lançamento indisponível para reversão.');
        if(!empty($entry['settlement_id'])&&$entry['status']!=='settled')throw new RuntimeException('O lançamento já pertence a um fechamento em andamento.');
        $event=hash('sha256','reverse:'.$ledgerId);$st=$pdo->prepare("INSERT INTO monetization_ledger (partner_id,agreement_id,campaign_id,source_type,source_id,event_key,amount_cents,status,reason_code,rule_snapshot,occurred_at,approved_at,reversed_entry_id) VALUES (?,?,?,'adjustment',?,? ,?,'approved','REVERSAL',?,NOW(),NOW(),?)");
        $st->execute([(int)$entry['partner_id'],$entry['agreement_id']?:null,$entry['campaign_id']?:null,'reverse-'.$ledgerId,$event,-(int)$entry['amount_cents'],json_encode(['reason'=>$reason,'original_entry_id'=>$ledgerId],JSON_UNESCAPED_UNICODE),$ledgerId]);$id=(int)$pdo->lastInsertId();
        if($entry['status']!=='settled')$pdo->prepare("UPDATE monetization_ledger SET status='reversed',reason_code='REVERSED',updated_at=NOW() WHERE id=?")->execute([$ledgerId]);
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_partner_balance(PDO $pdo, int $partnerId): array
{
    $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN amount_cents ELSE 0 END),0) receivable_cents,COALESCE(SUM(CASE WHEN status='settled' THEN amount_cents ELSE 0 END),0) settled_cents,COALESCE(SUM(CASE WHEN status='approved' THEN amount_cents ELSE 0 END),0) approved_cents FROM monetization_ledger WHERE partner_id=?");
    $st->execute([$partnerId]); return $st->fetch(PDO::FETCH_ASSOC)?:['receivable_cents'=>0,'settled_cents'=>0,'approved_cents'=>0];
}

function fs_monetization_create_settlement(PDO $pdo, int $partnerId, string $start, string $end): int
{
    if (!strtotime($start)||!strtotime($end)||$end<$start) throw new InvalidArgumentException('Período de fechamento inválido.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,amount_cents FROM monetization_ledger WHERE partner_id=? AND status=\'approved\' AND settlement_id IS NULL AND DATE(occurred_at) BETWEEN ? AND ? ORDER BY id FOR UPDATE');
        $st->execute([$partnerId,$start,$end]); $items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$items) throw new RuntimeException('Não há créditos aprovados neste período.');
        $total=array_sum(array_map(static fn($r)=>(int)$r['amount_cents'],$items));
        if($total<=0)throw new RuntimeException('O total líquido do período não gera repasse positivo.');
        $ins=$pdo->prepare("INSERT INTO partner_settlements (partner_id,period_start,period_end,status,advertising_cents,total_cents) VALUES (?,?,?,'open',?,?)");
        $ins->execute([$partnerId,$start,$end,$total,$total]); $id=(int)$pdo->lastInsertId();
        $itemSt=$pdo->prepare('INSERT INTO partner_settlement_items (settlement_id,ledger_id) VALUES (?,?)');
        $mark=$pdo->prepare('UPDATE monetization_ledger SET settlement_id=? WHERE id=? AND settlement_id IS NULL');
        foreach($items as $item){$itemSt->execute([$id,(int)$item['id']]);$mark->execute([$id,(int)$item['id']]);}
        if($ownsTransaction)$pdo->commit(); return $id;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_mark_settlement_paid(PDO $pdo, int $settlementId, string $method, string $reference, ?int $adminId): void
{
    if(!in_array($method,['pix','mercadopago'],true)||trim($reference)==='') throw new InvalidArgumentException('Informe o meio e a referência do repasse.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT id,status FROM partner_settlements WHERE id=? LIMIT 1 FOR UPDATE");$st->execute([$settlementId]);$s=$st->fetch(PDO::FETCH_ASSOC);
        if(!$s||(string)$s['status']!=='approved')throw new RuntimeException('Aprove o fechamento antes de registrar o pagamento.');
        $pdo->prepare("UPDATE partner_settlements SET status='paid',payment_method=?,provider_reference=?,paid_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$method,substr($reference,0,120),$settlementId]);
        $pdo->prepare("UPDATE monetization_ledger SET status='settled',updated_at=NOW() WHERE settlement_id=? AND status='approved'")->execute([$settlementId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_approve_settlement(PDO $pdo,int $settlementId,?int $adminId):void
{
    $st=$pdo->prepare("UPDATE partner_settlements SET status='approved',approved_by_admin_id=?,approved_at=NOW(),updated_at=NOW() WHERE id=? AND status='open'");
    $st->execute([$adminId,$settlementId]);
    if($st->rowCount()!==1)throw new RuntimeException('Fechamento aberto não encontrado.');
}

function fs_monetization_settlements(PDO $pdo, ?int $partnerId = null): array
{
    $sql='SELECT s.*,p.name partner_name FROM partner_settlements s JOIN partners p ON p.id=s.partner_id';$params=[];
    if($partnerId!==null){$sql.=' WHERE s.partner_id=?';$params[]=$partnerId;}$sql.=' ORDER BY s.period_end DESC,s.id DESC';
    $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_monetization_order_signature(string $publicId): string{return hash_hmac('sha256',$publicId,fs_credential_key());}

function fs_monetization_create_order(PDO $pdo,string $type,int $amountCents,string $payerEmail,array $refs=[]):array
{
    if(!in_array($type,['campaign','subscription'],true)||$amountCents<=0||!filter_var($payerEmail,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Dados da cobrança inválidos.');
    $campaignId=max(0,(int)($refs['campaign_id']??0))?:null;$partnerId=max(0,(int)($refs['partner_id']??0))?:null;$agreementId=max(0,(int)($refs['agreement_id']??0))?:null;
    if($type==='campaign'&&!$campaignId)throw new InvalidArgumentException('Selecione a campanha.');if($type==='subscription'&&(!$partnerId||!$agreementId))throw new InvalidArgumentException('Selecione o contrato da mensalidade.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        if($type==='campaign'){
            $st=$pdo->prepare('SELECT budget_cents,funded_cents FROM ad_campaigns WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$campaignId]);$c=$st->fetch(PDO::FETCH_ASSOC);if(!$c)throw new RuntimeException('Campanha não encontrada.');
            $pending=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM monetization_orders WHERE campaign_id=? AND status='pending' AND expires_at>NOW()");$pending->execute([$campaignId]);
            $available=(int)$c['budget_cents']-(int)$c['funded_cents']-(int)$pending->fetchColumn();if($amountCents>$available)throw new RuntimeException('A cobrança supera o orçamento contratado ainda disponível.');
        }
        $public=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$external='MON-'.date('YmdHis').'-'.bin2hex(random_bytes(5));
        $st=$pdo->prepare("INSERT INTO monetization_orders (public_id,order_token_hash,external_ref,order_type,campaign_id,partner_id,agreement_id,wallet_id,provider,payer_email,status,amount_cents,expires_at) VALUES (?,?,?,?,?,?,?,NULL,'mercadopago',?,'pending',?,DATE_ADD(NOW(),INTERVAL 1 DAY))");
        $st->execute([$public,hash('sha256',$token),$external,$type,$campaignId,$partnerId,$agreementId,$payerEmail,$amountCents]);
        $order=['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'order_token'=>$token,'external_ref'=>$external,'order_type'=>$type,'campaign_id'=>$campaignId,'partner_id'=>$partnerId,'agreement_id'=>$agreementId,'amount_cents'=>$amountCents,'payer_email'=>$payerEmail];
        if($ownsTransaction)$pdo->commit();return $order;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_start_pix(PDO $pdo,array $order):array
{
    $wallet=fs_global_wallet();fs_wallet_assert_usable($wallet);
    $description=$order['order_type']==='campaign'?'Crédito de campanha FireSpot':'Mensalidade FireSpot';
    $notification=rtrim(fs_public_base_url($pdo),'/').'/dashboard/api/monetization_webhook.php?order='.rawurlencode((string)$order['public_id']).'&sig='.rawurlencode(fs_monetization_order_signature((string)$order['public_id'])).'&source_news=webhooks';
    try{$payment=fs_payment_create_pix($wallet,$order,$description,(string)$order['payer_email'],$notification);}
    catch(Throwable $e){$pdo->prepare("UPDATE monetization_orders SET status='payment_failed',payment_status_detail='PROVIDER_CREATE_FAILED',updated_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$order['id']]);throw $e;}
    if(($payment['id']??'')==='')throw new RuntimeException('O Mercado Pago não retornou o pagamento.');
    $pdo->prepare('UPDATE monetization_orders SET provider_payment_id=?,payment_method=\'pix\',payment_status_detail=?,qr_code=?,ticket_url=?,updated_at=NOW() WHERE id=?')->execute([(string)$payment['id'],substr((string)$payment['status_detail'],0,96),(string)$payment['qr_code'],(string)$payment['ticket_url'],(int)$order['id']]);
    return $payment;
}

function fs_monetization_order_mark(PDO $pdo,array $order,array $payment):array
{
    if((string)($payment['external_reference']??'')!==(string)$order['external_ref'])throw new RuntimeException('Referência da cobrança divergente.');
    $amount=(int)round((float)($payment['transaction_amount']??0)*100);if($amount!==(int)$order['amount_cents'])throw new RuntimeException('Valor da cobrança divergente.');
    $normalized=fs_payment_normalize_status((string)($payment['status']??''));
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM monetization_orders WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([(int)$order['id']]);$stored=$st->fetch(PDO::FETCH_ASSOC);if(!$stored)throw new RuntimeException('Cobrança não encontrada.');$oldStatus=(string)$stored['status'];
        if($oldStatus==='refunded')$normalized='refunded';
        elseif($oldStatus==='paid'&&$normalized!=='refunded')$normalized='paid';
        $pdo->prepare('UPDATE monetization_orders SET provider_payment_id=?,payment_status_detail=?,status=?,paid_at=IF(?=\'paid\',COALESCE(paid_at,NOW()),paid_at),updated_at=NOW() WHERE id=?')->execute([(string)($payment['id']??''),substr((string)($payment['status_detail']??''),0,96),$normalized,$normalized,(int)$stored['id']]);
        if($oldStatus!=='paid'&&$oldStatus!=='refunded'&&$normalized==='paid'){
            if($stored['order_type']==='campaign'){
                $st=$pdo->prepare('SELECT budget_cents,funded_cents,status FROM ad_campaigns WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([(int)$stored['campaign_id']]);$campaign=$st->fetch(PDO::FETCH_ASSOC);if(!$campaign)throw new RuntimeException('Campanha da cobrança não encontrada.');
                if((int)$campaign['funded_cents']+(int)$stored['amount_cents']>(int)$campaign['budget_cents'])throw new RuntimeException('A confirmação supera o orçamento contratado da campanha.');
                $newStatus=in_array((string)$campaign['status'],['draft','awaiting_payment'],true)?'active':(string)$campaign['status'];
                $pdo->prepare('UPDATE ad_campaigns SET funded_cents=funded_cents+?,status=?,updated_at=NOW() WHERE id=?')->execute([(int)$stored['amount_cents'],$newStatus,(int)$stored['campaign_id']]);
            }elseif($stored['order_type']==='subscription'){
                $pdo->prepare('UPDATE partner_monetization_agreements SET paid_until=GREATEST(COALESCE(paid_until,CURRENT_DATE),DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH)),updated_at=NOW() WHERE id=? AND partner_id=?')->execute([(int)$stored['agreement_id'],(int)$stored['partner_id']]);
            }
        }
        if($oldStatus==='paid'&&$normalized==='refunded'&&$stored['order_type']==='campaign')$pdo->prepare("UPDATE ad_campaigns SET status='cancelled',updated_at=NOW() WHERE id=? AND status NOT IN ('completed','cancelled')")->execute([(int)$stored['campaign_id']]);
        if($ownsTransaction)$pdo->commit();$stored['status']=$normalized;return $stored;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function fs_monetization_orders(PDO $pdo,int $limit=100):array{return $pdo->query('SELECT o.*,c.name campaign_name,p.name partner_name FROM monetization_orders o LEFT JOIN ad_campaigns c ON c.id=o.campaign_id LEFT JOIN partners p ON p.id=o.partner_id ORDER BY o.id DESC LIMIT '.min(500,max(1,$limit)))->fetchAll(PDO::FETCH_ASSOC)?:[];}

function fs_monetization_record_external_subscription(PDO $pdo,int $agreementId,int $amountCents,string $reference):int
{
    $reference=trim($reference);if($amountCents<=0||$reference==='')throw new InvalidArgumentException('Informe valor e referência do faturamento externo.');$pdo->beginTransaction();
    try{$st=$pdo->prepare("SELECT * FROM partner_monetization_agreements WHERE id=? AND status='active' AND billing_source='external_firenetwork' LIMIT 1 FOR UPDATE");$st->execute([$agreementId]);$a=$st->fetch(PDO::FETCH_ASSOC);if(!$a||!in_array($a['model'],['subscription','hybrid'],true))throw new RuntimeException('Contrato externo indisponível.');
        $public=bin2hex(random_bytes(16));$external='EXT-'.date('YmdHis').'-'.bin2hex(random_bytes(4));$st=$pdo->prepare("INSERT INTO monetization_orders (public_id,order_token_hash,external_ref,order_type,partner_id,agreement_id,provider,provider_payment_id,payment_method,status,amount_cents,paid_at) VALUES (?,?,?,'subscription',?,?,'external_firenetwork',?,'external','paid',?,NOW())");$st->execute([$public,hash('sha256',random_bytes(32)),$external,(int)$a['partner_id'],$agreementId,substr($reference,0,96),$amountCents]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE partner_monetization_agreements SET paid_until=GREATEST(COALESCE(paid_until,CURRENT_DATE),DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH)),updated_at=NOW() WHERE id=?')->execute([$agreementId]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
