<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/control_center_navigation.php';
require_once __DIR__ . '/../../app/control_center_partners.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo 'Método não permitido.';exit;}
$partnerId=max(0,(int)($_POST['partner_id']??0));
$flash=['ok'=>false,'message'=>'Não foi possível atualizar a equipe.'];
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('partner.administrators.manage');
    if($partnerId<=0)throw new InvalidArgumentException('Estabelecimento inválido.');
    $pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    partner_central_partner($pdo,$partnerId);
    $action=(string)($_POST['action']??'');
    if($action==='team_invite'){
        $invite=partner_admin_invite($pdo,$partnerId,(string)($_POST['email']??''),(string)($_POST['role']??'viewer'),'firespot',admin_id());
        $_SESSION['control_center_invite_link']=(string)$invite['url'];
        $flash=['ok'=>true,'message'=>'Convite criado. Copie o link exibido uma única vez.'];
    }elseif($action==='team_invite_reissue'){
        $invitationId=max(0,(int)($_POST['invitation_id']??0));
        $statement=$pdo->prepare('SELECT email,role FROM partner_admin_invitations WHERE id=? AND partner_id=? AND accepted_at IS NULL AND revoked_at IS NULL LIMIT 1');$statement->execute([$invitationId,$partnerId]);$previous=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$previous)throw new RuntimeException('Convite pendente não encontrado.');
        $invite=partner_admin_invite($pdo,$partnerId,(string)$previous['email'],(string)$previous['role'],'firespot',admin_id());
        partner_admin_audit($pdo,$partnerId,'firespot',admin_id(),'invitation.reissued','invitation',(int)$invite['id']);
        $_SESSION['control_center_invite_link']=(string)$invite['url'];
        $flash=['ok'=>true,'message'=>'Novo link emitido; o anterior foi revogado.'];
    }elseif($action==='team_invite_revoke'){
        partner_admin_revoke_invitation($pdo,$partnerId,max(0,(int)($_POST['invitation_id']??0)),'firespot',admin_id());
        $flash=['ok'=>true,'message'=>'Convite revogado.'];
    }elseif($action==='team_member_update'){
        partner_admin_update_membership($pdo,$partnerId,max(0,(int)($_POST['membership_id']??0)),(string)($_POST['role']??'viewer'),isset($_POST['active'])?1:0,'firespot',admin_id());
        $flash=['ok'=>true,'message'=>'Vínculo administrativo atualizado.'];
    }elseif($action==='team_sessions_revoke'){
        partner_admin_revoke_user_sessions($pdo,$partnerId,max(0,(int)($_POST['user_id']??0)),'firespot',admin_id());
        $flash=['ok'=>true,'message'=>'Sessões do usuário revogadas neste contexto.'];
    }else throw new InvalidArgumentException('Ação de equipe inválida.');
}catch(Throwable $error){$flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar a equipe.')];}
$_SESSION['control_center_flash']=$flash;
$redirect='../'.fs_control_center_partner_url($partnerId,'team');
header('Location: '.$redirect,true,303);exit;
