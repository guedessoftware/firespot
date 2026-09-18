<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/control_center_navigation.php';
require_once __DIR__ . '/../../app/control_center_partners.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo 'Método não permitido.';exit;}
$partnerId=max(0,(int)($_POST['partner_id']??0));$flash=['ok'=>false,'message'=>'Não foi possível atualizar a cortesia.'];
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('partner.courtesy.manage');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);partner_central_partner($pdo,$partnerId);
    $action=(string)($_POST['action']??'');$pointId=max(0,(int)($_POST['point_id']??0));
    if($action==='courtesy_draft_save'){
        $draft=fs_partner_courtesy_save_draft($pdo,$partnerId,$_POST,0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.draft_saved','courtesy_policy_revision',(int)($draft['id']??0));$flash=['ok'=>true,'message'=>'Rascunho de cortesia salvo. A política publicada continua ativa.'];
    }elseif($action==='courtesy_preset'){
        $input=fs_partner_courtesy_preset((string)($_POST['preset_code']??''));$draft=fs_partner_courtesy_save_draft($pdo,$partnerId,$input,0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.preset_applied','courtesy_policy_revision',(int)($draft['id']??0),['preset_code'=>(string)($_POST['preset_code']??'')]);$flash=['ok'=>true,'message'=>'Preset copiado para o rascunho; revise antes de publicar.'];
    }elseif($action==='courtesy_restore'){
        $draft=fs_partner_courtesy_restore_draft($pdo,$partnerId,max(0,(int)($_POST['revision_id']??0)),0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.revision_restored','courtesy_policy_revision',(int)($draft['id']??0),['source_revision'=>(int)($draft['restored_from_revision']??0)]);$flash=['ok'=>true,'message'=>'Revisão histórica copiada para o rascunho.'];
    }elseif($action==='courtesy_publish'){
        $published=fs_partner_courtesy_publish($pdo,$partnerId,0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.policy_published','partner',$partnerId,['revision'=>(int)($published['revision']??0)]);$flash=['ok'=>true,'message'=>'Política de cortesia publicada para novas concessões.'];
    }elseif($action==='courtesy_override_save'){
        if($pointId<=0)throw new InvalidArgumentException('Ponto inválido.');$override=fs_partner_courtesy_override_save_draft($pdo,$partnerId,$pointId,$_POST,0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.override_draft_saved','partner_hotspot',$pointId,['override_id'=>(int)$override['id']]);$flash=['ok'=>true,'message'=>'Exceção do ponto salva como rascunho.'];
    }elseif($action==='courtesy_override_publish'){
        if($pointId<=0)throw new InvalidArgumentException('Ponto inválido.');fs_partner_courtesy_override_publish($pdo,$partnerId,$pointId,0);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.override_published','partner_hotspot',$pointId);$flash=['ok'=>true,'message'=>'Exceção publicada para novas concessões deste ponto.'];
    }elseif($action==='courtesy_override_retire'){
        if($pointId<=0)throw new InvalidArgumentException('Ponto inválido.');fs_partner_courtesy_override_retire($pdo,$partnerId,$pointId);partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'courtesy.override_retired','partner_hotspot',$pointId);$flash=['ok'=>true,'message'=>'Exceção retirada; o ponto voltou a herdar a política geral.'];
    }else throw new InvalidArgumentException('Ação de cortesia inválida.');
}catch(Throwable $error){$flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar a cortesia.')];}
$_SESSION['control_center_flash']=$flash;header('Location: ../'.fs_control_center_partner_url($partnerId,'courtesy'),true,303);exit;
