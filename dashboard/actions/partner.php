<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/control_center_navigation.php';
require_once __DIR__ . '/../../app/control_center_partners.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);header('Allow: POST');echo 'Método não permitido.';exit;
}

$action=(string)($_POST['action']??'');
$partnerId=max(0,(int)($_POST['partner_id']??0));
$section=(string)($_POST['return_section']??'registration');
$allowedSections=['registration','contract'];
if(!in_array($section,$allowedSections,true))$section='registration';
$redirect=$partnerId>0?'../'.fs_control_center_partner_url($partnerId,$section):'../estabelecimento.php?mode=create';
$flash=['ok'=>false,'message'=>'Não foi possível concluir a operação.'];

try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if($action==='partner_create'){
        admin_require_capability('partners.create');
        admin_require_capability('partner.subscriptions.manage');
        $partnerId=fs_control_center_partner_create_draft($pdo,$_POST,admin_id());
        $section='portal';
        $flash=['ok'=>true,'message'=>'Estabelecimento criado como rascunho inativo. Continue pela experiência; nenhum portal ou equipamento foi ativado.'];
    }elseif($action==='registration_save'){
        admin_require_capability('partners.manage');
        if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
        fs_control_center_partner_save_registration($pdo,$partnerId,$_POST,admin_id());
        $flash=['ok'=>true,'message'=>'Cadastro atualizado. Nenhum equipamento foi consultado ou alterado.'];
    }elseif($action==='partner_deactivate'){
        admin_require_capability('partners.deactivate');
        if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
        $dependencies=fs_control_center_partner_deactivate($pdo,$partnerId,admin_id());
        $flash=['ok'=>true,'message'=>'Estabelecimento desativado; histórico e dependências foram preservados'.($dependencies?': '.implode(', ',$dependencies):'.')];
    }elseif($action==='subscription_save'){
        admin_require_capability('partner.subscriptions.manage');
        if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
        partner_central_partner($pdo,$partnerId);
        $pdo->beginTransaction();
        try{
            $subscription=fs_partner_assign_subscription($pdo,$partnerId,(string)($_POST['platform_plan_code']??''),(string)($_POST['subscription_status']??''),(string)($_POST['subscription_reason']??''),admin_id());
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'subscription.changed','partner_subscription',(int)$subscription['subscription_id'],['plan_code'=>$subscription['plan_code'],'status'=>$subscription['status']]);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Assinatura atualizada para '.$subscription['plan_name'].'. Nenhum serviço foi alterado nos NAS.'];
    }elseif($action==='self_service_save'){
        admin_require_capability('partner.portal.manage');
        if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
        partner_central_partner($pdo,$partnerId);
        $enabled=isset($_POST['self_service_enabled'])?1:0;
        $pdo->beginTransaction();
        try{
            $pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE')->execute([$partnerId]);
            $pdo->prepare('UPDATE partners SET self_service_enabled=?,updated_at=NOW() WHERE id=?')->execute([$enabled,$partnerId]);
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'self_service.updated','partner',$partnerId,['enabled'=>$enabled]);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>$enabled?'Painel do estabelecimento liberado; os módulos continuam limitados pelo Plano FireSpot.':'Painel do estabelecimento bloqueado.'];
    }elseif($action==='feature_override_save'){
        admin_require_capability('partner.subscriptions.manage');
        if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
        $pdo->beginTransaction();
        try{
            $override=fs_partner_create_feature_override($pdo,$partnerId,(string)($_POST['feature_code']??''),(string)($_POST['override_effect']??'enable')==='enable',$_POST['limit_value']??null,(int)($_POST['valid_days']??0),(string)($_POST['override_reason']??''),admin_id());
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'subscription.override_created','partner_feature_override',(int)$override['id'],['feature_code'=>$override['feature_code'],'enabled'=>$override['enabled'],'limit_value'=>$override['limit_value'],'valid_until'=>$override['valid_until']]);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Exceção contratual registrada até '.$override['valid_until'].'.'];
    }else{
        throw new InvalidArgumentException('Ação de estabelecimento inválida.');
    }
}catch(Throwable $error){
    $flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível salvar o estabelecimento.')];
    if($action==='partner_create')$_SESSION['control_center_create_values']=$_POST;
}

$_SESSION['control_center_flash']=$flash;
$redirect=$partnerId>0?'../'.fs_control_center_partner_url($partnerId,$section):'../estabelecimento.php?mode=create';
header('Location: '.$redirect,true,303);exit;
