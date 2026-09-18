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

$partnerId=max(0,(int)($_POST['partner_id']??0));$action=(string)($_POST['action']??'');
$flash=['ok'=>false,'message'=>'Não foi possível atualizar o Portal V3.'];
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('partner.portal.manage');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    partner_central_partner($pdo,$partnerId);$actorId=admin_id();

    if($action==='journey_prepare'){
        fs_portal_config_create_draft($pdo,$partnerId,'firespot',$actorId);
        $flash=['ok'=>true,'message'=>'Rascunho da jornada preparado. O portal publicado não foi alterado.'];
    }elseif($action==='journey_preset'){
        fs_portal_config_apply_preset($pdo,$partnerId,(string)($_POST['preset_code']??''),'firespot',$actorId);
        $flash=['ok'=>true,'message'=>'Preset funcional aplicado somente ao rascunho.'];
    }elseif($action==='journey_save'){
        $payload=$_POST;
        foreach(['paid_access_enabled','promotional_ads_enabled','allow_global_ads','lead_capture_enabled','welcome_screen_enabled','single_option_direct_enabled'] as $field)$payload[$field]=isset($_POST[$field])?1:0;
        fs_portal_config_save_draft($pdo,$partnerId,$payload,'firespot',$actorId,false);
        $flash=['ok'=>true,'message'=>'Jornada salva em rascunho. Nenhuma modalidade ativa foi modificada.'];
    }elseif($action==='journey_discard'){
        if(fs_portal_presentation_get($pdo,$partnerId,'draft'))throw new RuntimeException('Descarte primeiro o rascunho visual associado.');
        fs_portal_config_discard_draft($pdo,$partnerId,'firespot',$actorId);
        $flash=['ok'=>true,'message'=>'Rascunho funcional descartado; o portal publicado foi preservado.'];
    }elseif($action==='presentation_prepare'){
        fs_portal_config_create_draft($pdo,$partnerId,'firespot',$actorId);
        fs_portal_presentation_prepare($pdo,$partnerId,$actorId);
        $flash=['ok'=>true,'message'=>'Apresentação V3 preparada a partir do portal atual, sem ativação.'];
    }elseif($action==='presentation_save'){
        $payload=$_POST;$payload['show_title']=isset($_POST['show_title'])?1:0;
        fs_portal_presentation_save_uploads($pdo,$partnerId,$payload,$_FILES,$actorId);
        $flash=['ok'=>true,'message'=>'Modelo visual, identidade e conteúdo salvos somente no rascunho.'];
    }elseif($action==='presentation_validate'){
        $validation=fs_portal_presentation_validate($pdo,$partnerId,null,true);
        $flash=['ok'=>!empty($validation['ready']),'message'=>!empty($validation['ready'])?'Checklist validado. Ainda falta aprovar as duas prévias.':'Checklist atualizado com '.count($validation['blocks']).' bloqueio(s).'];
    }elseif($action==='preview_approve'){
        $viewport=(string)($_POST['viewport']??'');fs_portal_presentation_approve_preview($pdo,$partnerId,$viewport,$actorId);
        $flash=['ok'=>true,'message'=>'Prévia '.($viewport==='mobile'?'móvel':'desktop').' aprovada. Isso não ativa o Portal V3.'];
    }elseif($action==='presentation_mark_ready'){
        fs_portal_presentation_mark_ready($pdo,$partnerId,$actorId);
        $flash=['ok'=>true,'message'=>'Migração marcada como pronta para decisão manual. Nenhuma ativação foi executada.'];
    }elseif($action==='presentation_discard'){
        fs_portal_presentation_discard($pdo,$partnerId,$actorId);
        $flash=['ok'=>true,'message'=>'Rascunho visual descartado. O portal atual permanece intacto.'];
    }elseif($action==='presentation_reopen'){
        fs_portal_presentation_reopen($pdo,$partnerId,$actorId);
        $flash=['ok'=>true,'message'=>'Apresentação reaberta para ajustes. As aprovações anteriores foram invalidadas.'];
    }else{
        throw new InvalidArgumentException('Ação do Portal V3 inválida.');
    }
}catch(Throwable $error){$flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar o Portal V3.')];}

$_SESSION['control_center_flash']=$flash;
header('Location: ../'.fs_control_center_partner_url($partnerId,'portal'),true,303);exit;
