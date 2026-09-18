<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/control_center_navigation.php';
require_once __DIR__ . '/../../app/control_center_partners.php';
require_once __DIR__ . '/../../app/partner_admin.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

$partnerId=max(0,(int)($_POST['partner_id']??0));
$pointId=max(0,(int)($_POST['point_id']??0));
$redirect='../' . fs_control_center_partner_url($partnerId,'points',$pointId>0?['point_id'=>$pointId]:[]);
$flash=['ok'=>false,'message'=>'Não foi possível concluir a operação.'];

try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('partners.manage');
    admin_require_capability('partner.network.manage');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $action=(string)($_POST['action']??'');

    if($action==='point_create_draft'){
        $pdo->beginTransaction();
        try{
            $pointId=fs_control_center_point_draft_create($pdo,$partnerId,$_POST,admin_id());
            $created=fs_partner_hotspot_by_id($pdo,$pointId,$partnerId,false);
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'hotspot.draft_created','partner_hotspot',$pointId,[
                'code'=>(string)($created['hotspot_code']??''),'nas_id'=>(int)($created['nas_id']??0),'vlan_id'=>(int)($created['vlan_id']??0),
            ]);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Rascunho do ponto criado. Nenhuma configuração foi aplicada ao NAS.'];
    }elseif($action==='point_update'){
        if($pointId<=0)throw new InvalidArgumentException('Ponto inválido.');
        $current=fs_partner_hotspot_by_id($pdo,$pointId,$partnerId,false);
        if(!$current)throw new RuntimeException('Ponto não encontrado neste estabelecimento.');
        $isDraft=(int)($current['hotspot_is_default']??0)===0&&(int)($current['hotspot_active']??$current['active']??0)===0&&(string)($current['management_state']??'')==='draft'&&(int)($current['applied_config_version']??0)===0;
        $pdo->beginTransaction();
        try{
            if($isDraft)fs_control_center_point_draft_update($pdo,$partnerId,$pointId,$_POST,admin_id());
            else fs_partner_hotspot_update($pdo,$partnerId,$pointId,fs_control_center_point_payload($pdo,$_POST,$current));
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),$isDraft?'hotspot.draft_updated':'hotspot.updated','partner_hotspot',$pointId,[
                'nas_id'=>(int)($_POST['nas_id']??0),'active'=>isset($_POST['active'])?1:0,
            ]);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>$isDraft?'Rascunho atualizado sem alterar o NAS.':'Configuração armazenada. Reaplicação remota não foi solicitada.'];
    }elseif($action==='point_discard_draft'){
        if($pointId<=0)throw new InvalidArgumentException('Ponto inválido.');
        $pdo->beginTransaction();
        try{
            fs_control_center_point_draft_discard($pdo,$partnerId,$pointId);
            partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'hotspot.draft_discarded','partner_hotspot',$pointId);
            $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        $flash=['ok'=>true,'message'=>'Rascunho descartado e reserva de rede liberada. Nenhum NAS foi alterado.'];
        $pointId=0;
    }else{
        throw new InvalidArgumentException('Ação de ponto inválida.');
    }
}catch(Throwable $error){
    $flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível salvar o ponto.')];
}

$_SESSION['control_center_flash']=$flash;
$redirect='../' . fs_control_center_partner_url($partnerId,'points',$pointId>0?['point_id'=>$pointId]:[]);
header('Location: '.$redirect,true,303);
exit;
