<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/control_center_navigation.php';
require_once __DIR__ . '/../../app/control_center_partners.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo 'Método não permitido.';exit;}
$partnerId=max(0,(int)($_POST['partner_id']??0));$section=(string)($_POST['return_section']??'finance');
if(!in_array($section,['finance','access-plans'],true))$section='finance';
$flash=['ok'=>false,'message'=>'Não foi possível concluir a operação.'];
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    partner_central_partner($pdo,$partnerId);$action=(string)($_POST['action']??'');
    if($action==='access_plan_save'){
        admin_require_capability('partner.plans.manage');
        $payload=$_POST;$payload['id']=max(0,(int)($_POST['plan_id']??0));
        $pdo->beginTransaction();
        try{$message=partner_central_save_plan($pdo,$partnerId,$payload);$planId=(int)$payload['id'];if($planId<=0)$planId=(int)$pdo->lastInsertId();partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),$payload['id']>0?'plan.updated':'plan.created','plan',$planId);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>$message];
    }elseif($action==='access_plan_toggle'){
        admin_require_capability('partner.plans.manage');$planId=max(0,(int)($_POST['plan_id']??0));
        $pdo->beginTransaction();
        try{$message=partner_central_toggle_plan($pdo,$partnerId,$planId);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'plan.status_changed','plan',$planId);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>$message];
    }elseif($action==='billing_save'){
        admin_require_capability('partner.billing.manage');
        $independent=isset($_POST['independent_billing'])?1:0;$walletId=trim((string)($_POST['payment_wallet_id']??''))===''?null:(int)$_POST['payment_wallet_id'];
        $pdo->beginTransaction();
        try{partner_central_save_billing($pdo,$partnerId,$independent,$walletId);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'billing.updated','partner',$partnerId,['independent_billing'=>$independent,'wallet_id'=>$walletId]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Destino de recebimento atualizado.'];
    }elseif($action==='payment_window_save'){
        admin_require_capability('partner.billing.manage');
        $pdo->beginTransaction();
        try{$policy=partner_central_save_payment_window($pdo,$partnerId,$_POST);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'payment_window.updated','partner',$partnerId,$policy);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Política do checkout Pix atualizada para este estabelecimento.'];
    }elseif($action==='wallet_replace'){
        admin_require_capability('partner.billing.manage');
        $walletId=partner_central_replace_partner_wallet($pdo,$partnerId,$_POST,true);
        partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'wallet.replaced','payment_wallet',$walletId,['environment'=>(string)($_POST['environment']??'production')]);
        $flash=['ok'=>true,'message'=>'Nova carteira validada, protegida e vinculada. As credenciais não serão exibidas novamente.'];
    }elseif($action==='wallet_token_rotate'){
        admin_require_capability('partner.billing.manage');$walletId=partner_central_rotate_partner_wallet_token($pdo,$partnerId,(string)($_POST['access_token']??''));partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'wallet.token_rotated','payment_wallet',$walletId);$flash=['ok'=>true,'message'=>'Access Token validado e atualizado.'];
    }elseif($action==='wallet_webhook_rotate'){
        admin_require_capability('partner.billing.manage');$walletId=partner_central_rotate_partner_wallet_webhook($pdo,$partnerId,(string)($_POST['webhook_secret']??''));partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'wallet.webhook_rotated','payment_wallet',$walletId);$flash=['ok'=>true,'message'=>'Assinatura secreta do webhook validada e atualizada.'];
    }elseif($action==='wallet_webhook_test'){
        admin_require_capability('partner.billing.manage');$walletId=partner_central_test_partner_wallet_webhook($pdo,$partnerId);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'wallet.webhook_tested','payment_wallet',$walletId);$flash=['ok'=>true,'message'=>'Autoteste local da assinatura do webhook concluído.'];
    }elseif($action==='wallet_restore'){
        admin_require_capability('partner.billing.manage');$walletId=partner_central_restore_partner_wallet($pdo,$partnerId,max(0,(int)($_POST['wallet_id']??0)));partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'wallet.restored','payment_wallet',$walletId);$flash=['ok'=>true,'message'=>'Carteira histórica revalidada e restaurada.'];
    }else throw new InvalidArgumentException('Ação financeira inválida.');
}catch(Throwable $error){$flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível concluir a operação financeira.')];}
$_SESSION['control_center_flash']=$flash;
header('Location: ../'.fs_control_center_partner_url($partnerId,$section),true,303);exit;
