<?php

declare(strict_types=1);

require_once __DIR__.'/_boot.php';
$pdo=conta_db();
if(!fs_subscriber_feature_enabled($pdo,'subscriber_account_enabled',false))conta_redirect('/conta/');
$account=fs_subscriber_require($pdo);$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sua sessão expirou. Recarregue a página.');
        $action=(string)($_POST['action']??'');
        if($action==='reauthenticate'){
            $result=fs_subscriber_reauthentication_start($pdo,(int)$account['id']);
            fs_subscriber_logout($pdo,false);
            $_SESSION['subscriber_login_challenge']=$result['challenge'];$_SESSION['subscriber_login_hint']=$result['contact_hint'];$_SESSION['subscriber_after_login']='/conta/excluir.php';
            conta_redirect('/conta/verificar.php');
        }
        if($action!=='anonymize'||!fs_subscriber_recent_otp())throw new RuntimeException('Confirme novamente sua identidade antes de excluir a conta.');
        if(trim((string)($_POST['confirmation']??''))!=='EXCLUIR')throw new RuntimeException('Digite EXCLUIR para confirmar.');
        $accountId=(int)$account['id'];fs_subscriber_account_anonymize($pdo,$accountId);
        unset($_SESSION['subscriber_account_id'],$_SESSION['subscriber_session_version'],$_SESSION['subscriber_authenticated_at'],$_SESSION['subscriber_auth_method'],$_SESSION['subscriber_after_login']);fs_subscriber_clear_cookie();session_regenerate_id(true);
        conta_flash('success','Sua conta FireSpot foi anonimizada. O contrato FIRENETWORK não foi cancelado.');conta_redirect('/conta/');
    }catch(Throwable $e){$error=conta_error($e);}
}

conta_layout_start('Excluir Minha Conta · FIRENETWORK');?>
<section class="account-card account-auth stack">
  <div><span class="account-eyebrow">Privacidade</span><h1>Excluir a conta FireSpot</h1><p>Esta ação revoga sessões, convites, aparelhos e acessos incluídos e anonimiza os dados locais. Compras e registros que precisam permanecer por obrigação financeira ou de segurança ficam associados apenas a um identificador interno sem seus dados de contato.</p></div>
  <div class="notice error"><strong>Isto não cancela seu contrato FIRENETWORK.</strong> Para cancelar ou alterar o serviço do provedor, use os canais de atendimento da FIRENETWORK.</div>
  <?php if($error):?><div class="notice error"><?=conta_h($error)?></div><?php endif;?>
  <?php if(!fs_subscriber_recent_otp()):?><p>Por segurança, confirme sua identidade com um novo código enviado ao contato cadastrado no HubSoft.</p><form method="post"><input type="hidden" name="csrf" value="<?=conta_h(csrf_token())?>"><input type="hidden" name="action" value="reauthenticate"><button type="submit">Enviar código de confirmação</button></form>
  <?php else:?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=conta_h(csrf_token())?>"><input type="hidden" name="action" value="anonymize"><label>Digite EXCLUIR para confirmar<input name="confirmation" autocomplete="off" required></label><button class="danger" type="submit">Anonimizar e encerrar minha conta FireSpot</button></form><?php endif;?>
  <a class="button" href="/conta/">Cancelar</a>
</section>
<?php conta_layout_end();
