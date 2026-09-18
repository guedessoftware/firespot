<?php

declare(strict_types=1);

if (!defined('FIRESPOT_HOST_PANEL_ENTRY')) {
    http_response_code(404);
    exit;
}

/** Controlador único das mutações do painel; toda ação repete autorização no servidor. */
function host_panel_handle_post(PDO $pdo, int $partnerId): void
{
    $returnPage=preg_replace('/[^a-z_]/','',(string)($_POST['return_page']??'summary'))?:'summary';
    try {
        if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $action=(string)($_POST['action']??'');
        if($action==='portal_presentation_prepare'){
            $actionContext=partner_admin_require_feature_context($pdo,'theme.manage','portal','portal.presentation.manage',true);
            fs_portal_presentation_prepare($pdo,$partnerId,(int)$actionContext['user_id'],'partner_admin');
            host_flash('success','Rascunho visual V3 preparado. O portal ativo não foi alterado.');
        }elseif($action==='portal_presentation_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'theme.manage','portal','portal.presentation.manage',true);
            // Campos ausentes pertencem à identidade visual única e devem ser
            // preservados no rascunho, não convertidos implicitamente em zero.
            $payload=$_POST;
            if(array_key_exists('show_title',$_POST))$payload['show_title']=isset($_POST['show_title'])?1:0;
            fs_portal_presentation_save_uploads($pdo,$partnerId,$payload,$_FILES,(int)$actionContext['user_id'],'partner_admin');
            host_flash('success','Modelo visual e conteúdo salvos somente no rascunho.');
        }elseif($action==='portal_presentation_validate'){
            partner_admin_require_feature_context($pdo,'theme.manage','portal','portal.presentation.manage',true);
            $validation=fs_portal_presentation_validate($pdo,$partnerId,null,true);
            host_flash(!empty($validation['ready'])?'success':'error',!empty($validation['ready'])?'Rascunho validado e pronto para revisão da FireSpot.':'O checklist encontrou '.count($validation['blocks']).' bloqueio(s).');
        }elseif($action==='portal_presentation_discard'){
            $actionContext=partner_admin_require_feature_context($pdo,'theme.manage','portal','portal.presentation.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            if(!fs_portal_presentation_get($pdo,$partnerId,'draft'))throw new RuntimeException('Somente um rascunho ainda não enviado à revisão pode ser descartado.');
            fs_portal_presentation_discard($pdo,$partnerId,(int)$actionContext['user_id'],'partner_admin');
            host_flash('success','Rascunho visual descartado. O portal ativo foi preservado.');
        }elseif($action==='theme_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'theme.manage','theme','branding.manage',true);
            partner_central_save_theme($pdo,$partnerId,$_POST,$_FILES);
            $identitySynced=false;
            // O editor é único: quando houver rascunho V3, a mesma identidade
            // visual é incorporada nele sem publicar nem ativar a migração.
            if(fs_portal_presentation_get($pdo,$partnerId,'draft')
                &&fs_portal_config_module_allowed($pdo,$actionContext,'portal')
                &&fs_partner_has_entitlement($pdo,$partnerId,'portal.presentation.manage',true)){
                $savedTheme=portal_theme_get($pdo,$actionContext,company_get());
                fs_portal_presentation_save($pdo,$partnerId,[
                    'brand_name'=>(string)($savedTheme['brand_name']??$actionContext['name']??'FireSpot'),
                    'brand_subtitle'=>(string)($savedTheme['brand_subtitle']??''),
                    'show_title'=>(int)($savedTheme['show_title']??1),
                    'theme_mode'=>(string)($savedTheme['theme_mode']??'light'),
                    'primary_color'=>(string)($savedTheme['primary_color']??'#ff9f1c'),
                    'secondary_color'=>(string)($savedTheme['secondary_color']??'#ff6b00'),
                    'background_color'=>(string)($savedTheme['background_color']??'#071225'),
                    'logo_light_path'=>(string)($savedTheme['logo_light_path']??''),
                    'logo_dark_path'=>(string)($savedTheme['logo_dark_path']??''),
                ],(int)$actionContext['user_id'],'partner_admin');
                $identitySynced=true;
            }
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'theme.updated','theme',$partnerId);
            host_flash('success',$identitySynced?'Identidade visual atualizada e incorporada ao rascunho V3.':'Identidade visual atualizada com sucesso.');
        }elseif($action==='plan_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'plans.manage','plans','guest_plans.manage',true);
            $message=partner_central_save_plan($pdo,$partnerId,$_POST);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],(int)($_POST['id']??0)>0?'plan.updated':'plan.created','plan',(int)($_POST['id']??$pdo->lastInsertId()));
            host_flash('success',$message);
        }elseif($action==='plan_toggle'){
            $actionContext=partner_admin_require_feature_context($pdo,'plans.manage','plans','guest_plans.manage',true);
            $planId=(int)($_POST['id']??0);$message=partner_central_toggle_plan($pdo,$partnerId,$planId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'plan.status_changed','plan',$planId);
            host_flash('success',$message);
        }elseif($action==='wallet_replace'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            // Somente o plano máximo pode iniciar o recebimento próprio pelo
            // painel. Uma unidade legada já independente continua autorizada
            // a substituir sua carteira sem receber um upgrade implícito.
            $canActivateIndependent=fs_partner_independence_plan_allows_wallet_activation($pdo,$partnerId,true);
            $walletId=partner_central_replace_partner_wallet($pdo,$partnerId,$_POST,$canActivateIndependent);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.replaced','wallet',$walletId,['environment'=>$_POST['environment']??'production']);
            host_flash('success','Carteira validada e conectada ao estabelecimento. Os novos pagamentos usarão o recebimento próprio.');
        }elseif($action==='wallet_token_rotate'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $walletId=partner_central_rotate_partner_wallet_token($pdo,$partnerId,(string)($_POST['access_token']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.token_rotated','wallet',$walletId);
            host_flash('success','Access Token validado e rotacionado. A carteira e o histórico foram preservados.');
        }elseif($action==='wallet_webhook_rotate'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $walletId=partner_central_rotate_partner_wallet_webhook($pdo,$partnerId,(string)($_POST['webhook_secret']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.webhook_secret_rotated','wallet',$walletId);
            host_flash('success','Assinatura secreta atualizada e aprovada no teste HMAC local, sem cobrança.');
        }elseif($action==='wallet_webhook_test'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $walletId=partner_central_test_partner_wallet_webhook($pdo,$partnerId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.webhook_tested','wallet',$walletId,['result'=>'ok']);
            host_flash('success','O manifesto assinado foi validado localmente pelo mesmo verificador do webhook. Nenhuma cobrança foi criada.');
        }elseif($action==='wallet_connection_test'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',false);
            $lastTest=(int)($_SESSION['host_wallet_connection_test_at']??0);
            if($lastTest>time()-10)throw new RuntimeException('Aguarde alguns segundos antes de repetir o teste da carteira.');
            $_SESSION['host_wallet_connection_test_at']=time();
            $result=partner_central_test_partner_wallet_connection($pdo,$partnerId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.connection_tested','wallet',(int)$result['wallet_id'],['provider'=>$result['provider'],'api'=>$result['api'],'webhook'=>$result['webhook']]);
            if($result['ready'])host_flash('success','Conexão aprovada: API do gateway e assinatura do webhook estão operacionais. Nenhuma cobrança foi criada.');
            elseif($result['api']==='ok')host_flash('warning','Teste concluído: a API do gateway está operacional, mas a assinatura do webhook requer atenção. Nenhuma cobrança foi criada.');
            elseif($result['webhook']==='ok')host_flash('warning','Teste concluído: a assinatura do webhook está válida, mas a conexão com a API do gateway requer atenção. Nenhuma cobrança foi criada.');
            else host_flash('error','A conexão com a API e a assinatura do webhook requerem atenção. Revise as credenciais da carteira. Nenhuma cobrança foi criada.');
        }elseif($action==='wallet_restore'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','billing','wallet.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $walletId=partner_central_restore_partner_wallet($pdo,$partnerId,(int)($_POST['wallet_id']??0));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'wallet.restored','wallet',$walletId);
            host_flash('success','Carteira histórica revalidada e restaurada. A carteira anterior permaneceu no histórico.');
        }elseif($action==='marketplace_start'){
            $actionContext=partner_admin_require_feature_context($pdo,'wallet.manage','monetization','monetization.view',true);
            $url=fs_marketplace_oauth_start($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'marketplace.authorization_started','marketplace_account',$partnerId);
            header('Location: '.$url);exit;
        }elseif($action==='ad_partner_policy_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'ads.manage','monetization','ad.inventory.manage',true);
            $policy=fs_ad_platform_save_partner_policy($pdo,$partnerId,$_POST,'partner_admin',(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'ad_inventory.partner_policy_updated','partner',$partnerId,['state'=>$policy['state'],'version'=>(int)$policy['version'],'revenue_mode'=>'firespot_managed']);
            host_flash('success','Política publicitária atualizada. A conta Google e a receita permanecem administradas pela FireSpot.');
        }elseif($action==='ad_hotspot_policy_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'ads.manage','monetization','ad.inventory.manage',true);
            $hotspotId=(int)($_POST['hotspot_id']??0);
            $policy=fs_ad_platform_save_hotspot_policy($pdo,$partnerId,$hotspotId,$_POST,'partner_admin',(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'ad_inventory.hotspot_policy_updated','partner_hotspot',$hotspotId,['inherited'=>!empty($policy['inherited']),'state'=>$policy['state'],'version'=>(int)$policy['version']]);
            host_flash('success',!empty($policy['inherited'])?'O ponto voltou a herdar a política publicitária do estabelecimento.':'A regra própria deste ponto foi atualizada.');
        }elseif($action==='ad_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'ads.manage','ads','ads.manage',true);
            $saved=partner_ads_save($pdo,$partnerId,$_POST,$_FILES);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],$saved['created']?'ad.created':'ad.updated','ad',$saved['id'],['active'=>$saved['active']]);
            host_flash('success',$saved['created']?'Anúncio criado.':'Anúncio atualizado.');
        }elseif($action==='ad_toggle'){
            $actionContext=partner_admin_require_feature_context($pdo,'ads.manage','ads','ads.manage',true);$adId=(int)($_POST['id']??0);
            $status=partner_ads_toggle($pdo,$partnerId,$adId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],$status?'ad.activated':'ad.deactivated','ad',$adId);
            host_flash('success',$status?'Anúncio ativado.':'Anúncio desativado; as métricas foram preservadas.');
        }elseif($action==='lead_revoke'){
            $actionContext=partner_admin_require_feature_context($pdo,'ads.leads.view','monetization','ads.manage',true);
            $st=$pdo->prepare('SELECT public_id FROM ad_leads WHERE id=? AND partner_id=? LIMIT 1');$st->execute([(int)($_POST['id']??0),$partnerId]);$public=(string)($st->fetchColumn()?:'');
            if($public===''||!fs_ad_revoke_lead_public($pdo,$public,fs_ad_lead_revoke_signature($public)))throw new RuntimeException('Lead não encontrado.');
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'lead.revoked','ad_lead',(int)$_POST['id']);
            host_flash('success','Consentimento revogado e dados pessoais removidos.');
        }elseif($action==='team_invite'){
            $actionContext=partner_admin_require_feature_context($pdo,'team.manage','team','team.manage',true);
            $invite=partner_admin_invite($pdo,$partnerId,(string)($_POST['email']??''),(string)($_POST['role']??'viewer'),'partner_admin',(int)$actionContext['user_id']);
            $_SESSION['host_invite_link']=$invite['url'];host_flash('success','Convite criado. Compartilhe o link abaixo por um canal controlado.');
        }elseif($action==='team_invite_reissue'){
            $actionContext=partner_admin_require_feature_context($pdo,'team.manage','team','team.manage',true);
            $st=$pdo->prepare('SELECT email,role FROM partner_admin_invitations WHERE id=? AND partner_id=? AND accepted_at IS NULL LIMIT 1');$st->execute([(int)($_POST['id']??0),$partnerId]);$old=$st->fetch();
            if(!$old)throw new RuntimeException('Convite não encontrado.');
            $invite=partner_admin_invite($pdo,$partnerId,$old['email'],$old['role'],'partner_admin',(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'invitation.reissued','invitation',$invite['id']);
            $_SESSION['host_invite_link']=$invite['url'];host_flash('success','Novo link emitido; o anterior foi revogado.');
        }elseif($action==='team_invite_revoke'){
            $actionContext=partner_admin_require_feature_context($pdo,'team.manage','team','team.manage',true);
            partner_admin_revoke_invitation($pdo,$partnerId,(int)($_POST['id']??0),'partner_admin',(int)$actionContext['user_id']);host_flash('success','Convite revogado.');
        }elseif($action==='team_update'){
            $actionContext=partner_admin_require_feature_context($pdo,'team.manage','team','team.manage',true);
            partner_admin_update_membership($pdo,$partnerId,(int)($_POST['id']??0),(string)($_POST['role']??'viewer'),isset($_POST['active'])?1:0,'partner_admin',(int)$actionContext['user_id']);host_flash('success','Vínculo atualizado.');
        }elseif($action==='team_revoke_sessions'){
            $actionContext=partner_admin_require_feature_context($pdo,'team.manage','team','team.manage',true);
            partner_admin_revoke_user_sessions($pdo,$partnerId,(int)($_POST['user_id']??0),'partner_admin',(int)$actionContext['user_id']);host_flash('success','Sessões do usuário revogadas.');
        }elseif($action==='nas_register'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.manage','infrastructure','nas.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $created=fs_partner_nas_register($pdo,$partnerId,$_POST,(int)$actionContext['user_id'],(string)($_POST['idempotency_key']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.registered','nas',(int)$created['nas_id'],['request_id'=>(int)$created['request_id']]);
            host_flash('success','NAS cadastrado. A verificação segura foi enfileirada; acompanhe o estado antes de criar pontos nele.');
        }elseif($action==='nas_update'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.manage','infrastructure','nas.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $nasId=(int)($_POST['nas_id']??0);
            $requestId=fs_partner_nas_update_credentials($pdo,$partnerId,$nasId,$_POST,(int)$actionContext['user_id'],(string)($_POST['idempotency_key']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.credentials_updated','nas',$nasId,['request_id'=>$requestId]);
            host_flash('success','Dados do NAS atualizados e verificação segura enfileirada. Senhas e segredos anteriores não são exibidos.');
        }elseif($action==='nas_policy_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.manage','infrastructure','nas.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $nasId=(int)($_POST['nas_id']??0);
            $policy=fs_partner_nas_hotspot_policy_save($pdo,$partnerId,$nasId,$_POST,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.hotspot_policy_updated','nas',$nasId,[
                'vlan_start'=>(int)$policy['vlan_start'],'vlan_end'=>(int)$policy['vlan_end'],'prefix_length'=>(int)$policy['prefix_length'],
            ]);
            host_flash('success','Política de novos pontos atualizada. Pontos existentes e o RouterOS não foram alterados.');
        }elseif($action==='nas_sync'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.manage','infrastructure','nas.manage',true);
            $nasId=(int)($_POST['nas_id']??0);
            $requestId=fs_partner_nas_queue_operation($pdo,$partnerId,$nasId,'nas_sync',(int)$actionContext['user_id'],(string)($_POST['idempotency_key']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.sync_requested','nas',$nasId,['request_id'=>$requestId]);
            host_flash('success','Sincronização do inventário enfileirada. Nenhuma alteração remota foi executada ao abrir a página.');
        }elseif($action==='nas_prepare'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.prepare','infrastructure','nas.prepare',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $nasId=(int)($_POST['nas_id']??0);
            $requestId=fs_partner_nas_queue_operation($pdo,$partnerId,$nasId,'nas_prepare',(int)$actionContext['user_id'],(string)($_POST['idempotency_key']??''));
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.prepare_requested','nas',$nasId,['request_id'=>$requestId]);
            host_flash('success','Preparação do NAS enfileirada de forma auditável.');
        }elseif($action==='nas_retire'){
            $actionContext=partner_admin_require_feature_context($pdo,'nas.retire','infrastructure','nas.retire',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $nasId=(int)($_POST['nas_id']??0);
            fs_partner_nas_retire($pdo,$partnerId,$nasId,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'nas.retired','nas',$nasId);
            host_flash('success','NAS aposentado e credenciais revogadas. O histórico foi preservado.');
        }elseif(in_array($action,['hotspot_apply','hotspot_deactivate'],true)){
            throw new RuntimeException('A aplicação ou retirada remota de pontos permanece sob controle da Central FireSpot.');
        }elseif($action==='hotspot_commercial_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspot_commercial.manage','infrastructure','hotspots.draft.manage',true);
            $hotspotId=(int)($_POST['hotspot_id']??0);
            $pdo->beginTransaction();
            try{
                // Mantém a mesma ordem de locks das revisões de cortesia:
                // estabelecimento primeiro, ponto depois.
                fs_partner_courtesy_lock_partner($pdo,$partnerId);
                $commercial=fs_partner_hotspot_commercial_save($pdo,$partnerId,$hotspotId,$_POST,'partner_admin',(int)$actionContext['user_id']);
                $courtesyInput=$_POST;
                $courtesyInput['override_groups']=['enabled','grant','auth','device_limit','account_limit','cooldown'];
                if((string)$commercial['courtesy_mode']==='disabled')unset($courtesyInput['enabled']);else $courtesyInput['enabled']='1';
                fs_partner_courtesy_override_save_draft($pdo,$partnerId,$hotspotId,$courtesyInput,(int)$actionContext['user_id']);
                fs_partner_courtesy_override_publish($pdo,$partnerId,$hotspotId,(int)$actionContext['user_id']);
                partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.commercial_policy_updated','partner_hotspot',$hotspotId,[
                    'paid_access_enabled'=>(int)$commercial['paid_access_enabled'],
                    'courtesy_mode'=>(string)$commercial['courtesy_mode'],
                    'payment_window_enabled'=>(int)$commercial['payment_window_enabled'],
                    'payment_window_minutes'=>(int)$commercial['payment_window_minutes'],
                    'payment_window_daily_limit'=>(int)$commercial['payment_window_daily_limit'],
                    'payment_window_cooldown_minutes'=>(int)$commercial['payment_window_cooldown_minutes'],
                    'payment_window_period_minutes'=>(int)$commercial['payment_window_period_minutes'],
                ]);
                $pdo->commit();
            }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            host_flash('success','Cobrança, cortesia e janela Pix atualizadas somente neste ponto. O RouterOS não foi alterado.');
        }elseif($action==='hotspot_configuration_request_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspots.manage','infrastructure','hotspots.draft.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $hotspotId=(int)($_POST['hotspot_id']??0);
            $request=fs_partner_hotspot_configuration_request_save($pdo,$partnerId,$hotspotId,$_POST,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.configuration_requested','partner_hotspot',$hotspotId,[
                'request_id'=>(int)$request['id'],'revision'=>(int)$request['revision'],'requested_nas_id'=>(int)$request['nas_id'],
            ]);
            host_flash('success','Configuração enviada para revisão da FireSpot. O ponto e o RouterOS permanecem inalterados.');
        }elseif($action==='hotspot_configuration_request_cancel'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspots.manage','infrastructure','hotspots.draft.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $hotspotId=(int)($_POST['hotspot_id']??0);$requestId=fs_partner_hotspot_configuration_request_cancel($pdo,$partnerId,$hotspotId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.configuration_cancelled','partner_hotspot',$hotspotId,['request_id'=>$requestId]);
            host_flash('success','Solicitação cancelada. A configuração efetiva do ponto não foi alterada.');
        }elseif($action==='hotspot_draft_create'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspots.manage','infrastructure','hotspots.draft.manage',true);
            $hotspotId=fs_partner_hotspot_draft_create($pdo,$partnerId,$_POST);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.draft_created','partner_hotspot',$hotspotId);
            host_flash('success','Rascunho criado com VLAN e rede reservadas. A FireSpot fará a revisão e decidirá uma eventual aplicação.');
        }elseif($action==='hotspot_draft_update'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspots.manage','infrastructure','hotspots.draft.manage',true);
            $hotspotId=(int)($_POST['hotspot_id']??0);fs_partner_hotspot_draft_update($pdo,$partnerId,$hotspotId,$_POST);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.draft_updated','partner_hotspot',$hotspotId);
            host_flash('success','Rascunho atualizado. Nenhuma configuração remota foi alterada.');
        }elseif($action==='hotspot_draft_discard'){
            $actionContext=partner_admin_require_feature_context($pdo,'hotspots.manage','infrastructure','hotspots.draft.manage',true);
            partner_admin_require_current_password($pdo,$actionContext,(string)($_POST['current_password']??''));
            $hotspotId=(int)($_POST['hotspot_id']??0);fs_partner_hotspot_draft_discard($pdo,$partnerId,$hotspotId);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'hotspot.draft_discarded','partner_hotspot',$hotspotId);
            host_flash('success','Rascunho descartado e reserva liberada. Nenhum NAS foi alterado.');
        }elseif($action==='courtesy_draft_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.manage','portal','courtesy.manage',true);
            $draft=fs_partner_courtesy_save_draft($pdo,$partnerId,$_POST,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.draft_saved','courtesy_policy_revision',(int)$draft['id']);
            host_flash('success','Rascunho de cortesia salvo. A política em produção não foi alterada.');
        }elseif($action==='courtesy_preset'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.manage','portal','courtesy.manage',true);
            $presetCode=(string)($_POST['preset_code']??'');$draft=fs_partner_courtesy_save_draft($pdo,$partnerId,fs_partner_courtesy_preset($presetCode),(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.preset_drafted','courtesy_policy_revision',(int)$draft['id'],['preset_code'=>$presetCode]);
            host_flash('success','Preset carregado no rascunho. Revise o efeito antes de publicar.');
        }elseif($action==='courtesy_restore'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.manage','portal','courtesy.manage',true);
            $draft=fs_partner_courtesy_restore_draft($pdo,$partnerId,(int)($_POST['revision_id']??0),(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.revision_restored','courtesy_policy_revision',(int)$draft['id'],['restored_from_revision'=>(int)$draft['restored_from_revision']]);
            host_flash('success','Revisão histórica copiada para o rascunho. A produção não mudou; revise e publique quando estiver pronto.');
        }elseif($action==='courtesy_publish'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.manage','portal','courtesy.manage',true);
            $published=fs_partner_courtesy_publish($pdo,$partnerId,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.published','courtesy_policy',$partnerId,['policy_revision'=>$published['policy_revision']]);
            host_flash('success','Política publicada. Concessões já emitidas mantêm o snapshot anterior.');
        }elseif($action==='courtesy_override_save'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.override.manage','portal','courtesy.hotspot_override.manage',true);
            $override=fs_partner_courtesy_override_save_draft($pdo,$partnerId,(int)($_POST['hotspot_id']??0),$_POST,(int)$actionContext['user_id']);
            partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.override_draft_saved','partner_hotspot',(int)$override['hotspot_id'],['override_id'=>(int)$override['id']]);
            host_flash('success','Exceção do ponto salva como rascunho.');
        }elseif($action==='courtesy_override_publish'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.override.manage','portal','courtesy.hotspot_override.manage',true);$hotspotId=(int)($_POST['hotspot_id']??0);
            fs_partner_courtesy_override_publish($pdo,$partnerId,$hotspotId,(int)$actionContext['user_id']);partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.override_published','partner_hotspot',$hotspotId);host_flash('success','Exceção publicada para novas concessões deste ponto.');
        }elseif($action==='courtesy_override_retire'){
            $actionContext=partner_admin_require_feature_context($pdo,'courtesy.override.manage','portal','courtesy.hotspot_override.manage',true);$hotspotId=(int)($_POST['hotspot_id']??0);
            fs_partner_courtesy_override_retire($pdo,$partnerId,$hotspotId);partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$actionContext['user_id'],'courtesy.override_retired','partner_hotspot',$hotspotId);host_flash('success','Exceção retirada; o ponto voltou a herdar a política geral.');
        }else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $error){host_flash('error',partner_admin_public_error($error));}
    host_redirect($returnPage);
}
