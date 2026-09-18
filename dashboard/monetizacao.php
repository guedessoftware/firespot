<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
admin_require_capability('monetization.view');
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/monetization.php';
require_once __DIR__ . '/../app/ad_platform.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/components/status-pill.php';

function mon_cents(mixed $value): int
{
    $value=trim((string)$value);
    if($value==='')return 0;
    if(str_contains($value,','))$value=str_replace(',','.',str_replace('.','',$value));
    return max(0,(int)round((float)$value*100));
}

function mon_money(mixed $cents): string
{
    return 'R$ '.number_format(((int)$cents)/100,2,',','.');
}

function mon_dt(mixed $value): string
{
    $timestamp=strtotime((string)$value);
    return $timestamp?date('Y-m-d H:i:s',$timestamp):'';
}

function mon_redirect(string $section): never
{
    header('Location: monetizacao.php?'.http_build_query(['section'=>$section]),true,303);
    exit;
}

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$section=(string)($_GET['section']??'overview');
if(!in_array($section,['overview','ad_network','agreements','funding','marketplace','settlements','ledger'],true))$section='overview';
$flash=$_SESSION['monetization_flash']??null;
$charge=$_SESSION['monetization_charge']??null;
unset($_SESSION['monetization_flash'],$_SESSION['monetization_charge']);

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $returnSection=(string)($_POST['return_section']??'overview');
    if(!in_array($returnSection,['ad_network','agreements','funding','marketplace','settlements','ledger'],true))$returnSection='overview';
    try{
        if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
        admin_require_capability('monetization.manage');
        $action=(string)($_POST['action']??'');
        if($action==='ad_platform_save'){
            fs_ad_platform_save_settings($pdo,$_POST,admin_id());
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Rede publicitária salva. A veiculação continua condicionada às políticas dos estabelecimentos e pontos.'];
            $returnSection='ad_network';
        }elseif($action==='agreement_save'){
            fs_monetization_save_agreement($pdo,(int)($_POST['partner_id']??0),[
                'model'=>$_POST['model']??'subscription',
                'monthly_fee_cents'=>mon_cents($_POST['monthly_fee']??0),
                'access_fee_type'=>$_POST['access_fee_type']??'none',
                'access_fee_value'=>($_POST['access_fee_type']??'none')==='percentage'?(int)round((float)str_replace(',','.',(string)($_POST['access_fee']??0))*100):mon_cents($_POST['access_fee']??0),
                'advertising_enabled'=>!empty($_POST['advertising_enabled']),
                'billing_source'=>$_POST['billing_source']??'mercadopago',
                'starts_at'=>mon_dt($_POST['starts_at']??'now'),
            ],admin_id());
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Nova versão do contrato financeiro ativada e a anterior encerrada.'];
            $returnSection='agreements';
        }elseif($action==='campaign_terms_save'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));
            $query=$pdo->prepare('SELECT * FROM ad_campaigns WHERE id=? LIMIT 1');
            $query->execute([$campaignId]);
            $campaign=$query->fetch();
            if(!$campaign)throw new RuntimeException('Campanha não encontrada.');
            fs_monetization_save_campaign($pdo,[
                'id'=>$campaignId,
                'advertiser_id'=>$campaign['advertiser_id']??0,
                'name'=>$campaign['name'],
                'campaign_type'=>$campaign['campaign_type'],
                'status'=>$campaign['status'],
                'starts_at'=>$campaign['starts_at'],
                'ends_at'=>$campaign['ends_at'],
                'budget_cents'=>mon_cents($_POST['budget']??0),
                'advertiser_view_cpm_cents'=>mon_cents($_POST['view_cpm']??0),
                'advertiser_click_cents'=>mon_cents($_POST['click_price']??0),
                'advertiser_lead_cents'=>mon_cents($_POST['lead_price']??0),
                'lead_capture_enabled'=>!empty($campaign['lead_capture_enabled']),
                'offer_message'=>$campaign['offer_message']??'',
                'offer_valid_until'=>$campaign['offer_valid_until']??'',
                'consent_version'=>$campaign['consent_version']??'offer-v1',
                'frequency_window_hours'=>$campaign['frequency_window_hours']??24,
                'max_views_per_device'=>$campaign['max_views_per_device']??1,
            ],admin_id());
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Contrato financeiro da campanha atualizado sem alterar sua composição.'];
            $returnSection='funding';
        }elseif($action==='assignment_terms_save'){
            $assignmentId=max(0,(int)($_POST['assignment_id']??0));
            $query=$pdo->prepare('SELECT * FROM ad_campaign_partners WHERE id=? LIMIT 1');
            $query->execute([$assignmentId]);
            $assignment=$query->fetch();
            if(!$assignment)throw new RuntimeException('Participação financeira não encontrada.');
            fs_monetization_assign_campaign($pdo,(int)$assignment['campaign_id'],(int)$assignment['partner_id'],[
                'status'=>$assignment['status'],
                'max_views_per_device'=>$assignment['max_views_per_device']??'',
                'partner_view_cpm_cents'=>mon_cents($_POST['partner_view_cpm']??0),
                'partner_click_cents'=>mon_cents($_POST['partner_click']??0),
                'partner_lead_cents'=>mon_cents($_POST['partner_lead']??0),
            ]);
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Valores de remuneração do estabelecimento atualizados.'];
            $returnSection='funding';
        }elseif($action==='campaign_charge'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));
            $amount=mon_cents($_POST['amount']??0);
            $order=fs_monetization_create_order($pdo,'campaign',$amount,(string)($_POST['payer_email']??''),['campaign_id'=>$campaignId]);
            $payment=fs_monetization_start_pix($pdo,$order);
            $_SESSION['monetization_charge']=['public_id'=>$order['public_id'],'amount'=>$amount,'qr_code'=>$payment['qr_code']??'','qr_image'=>$payment['qr_code_base64']??'','ticket_url'=>$payment['ticket_url']??''];
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Cobrança Pix criada. O crédito só será liberado após confirmação do provedor.'];
            $returnSection='funding';
        }elseif($action==='subscription_charge'){
            $agreementId=max(0,(int)($_POST['agreement_id']??0));
            $query=$pdo->prepare("SELECT * FROM partner_monetization_agreements WHERE id=? AND status='active' LIMIT 1");
            $query->execute([$agreementId]);
            $agreement=$query->fetch();
            if(!$agreement||!in_array($agreement['model'],['subscription','hybrid'],true)||$agreement['billing_source']!=='mercadopago')throw new RuntimeException('Contrato indisponível para cobrança no Mercado Pago.');
            $order=fs_monetization_create_order($pdo,'subscription',(int)$agreement['monthly_fee_cents'],(string)($_POST['payer_email']??''),['partner_id'=>(int)$agreement['partner_id'],'agreement_id'=>$agreementId]);
            $payment=fs_monetization_start_pix($pdo,$order);
            $_SESSION['monetization_charge']=['public_id'=>$order['public_id'],'amount'=>(int)$agreement['monthly_fee_cents'],'qr_code'=>$payment['qr_code']??'','qr_image'=>$payment['qr_code_base64']??'','ticket_url'=>$payment['ticket_url']??''];
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Mensalidade Pix criada. A vigência paga depende da confirmação.'];
            $returnSection='funding';
        }elseif($action==='subscription_external'){
            fs_monetization_record_external_subscription($pdo,max(0,(int)($_POST['agreement_id']??0)),mon_cents($_POST['amount']??0),(string)($_POST['reference']??''));
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Mensalidade FIRENETWORK registrada e conciliada.'];
            $returnSection='funding';
        }elseif($action==='order_reconcile'){
            $query=$pdo->prepare('SELECT * FROM monetization_orders WHERE id=? LIMIT 1');
            $query->execute([max(0,(int)($_POST['id']??0))]);
            $order=$query->fetch();
            if(!$order||empty($order['provider_payment_id']))throw new RuntimeException('Cobrança indisponível.');
            $payment=fs_payment_get(fs_global_wallet(),(string)$order['provider_payment_id']);
            fs_monetization_order_mark($pdo,$order,$payment);
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Cobrança conciliada com o Mercado Pago.'];
            $returnSection='funding';
        }elseif($action==='marketplace_start'){
            $url=fs_marketplace_oauth_start($pdo,max(0,(int)($_POST['partner_id']??0)),'firespot',admin_id());
            header('Location: '.$url);
            exit;
        }elseif($action==='marketplace_disconnect'){
            fs_marketplace_disconnect($pdo,max(0,(int)($_POST['partner_id']??0)));
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Autorização Marketplace revogada.'];
            $returnSection='marketplace';
        }elseif($action==='settlement_create'){
            fs_monetization_create_settlement($pdo,max(0,(int)($_POST['partner_id']??0)),(string)($_POST['period_start']??''),(string)($_POST['period_end']??''));
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Fechamento criado com os créditos aprovados do período.'];
            $returnSection='settlements';
        }elseif($action==='settlement_approve'){
            fs_monetization_approve_settlement($pdo,max(0,(int)($_POST['id']??0)),admin_id());
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Fechamento aprovado e liberado para repasse.'];
            $returnSection='settlements';
        }elseif($action==='settlement_paid'){
            fs_monetization_mark_settlement_paid($pdo,max(0,(int)($_POST['id']??0)),(string)($_POST['payment_method']??''),(string)($_POST['provider_reference']??''),admin_id());
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Repasse registrado e lançamentos liquidados.'];
            $returnSection='settlements';
        }elseif($action==='ledger_adjust'){
            $raw=trim((string)($_POST['amount']??''));
            $negative=str_starts_with($raw,'-');
            $amount=mon_cents(ltrim($raw,'-'));
            if($negative)$amount=-$amount;
            fs_monetization_ledger_adjust($pdo,max(0,(int)($_POST['partner_id']??0)),$amount,(string)($_POST['reason']??''));
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Ajuste compensatório lançado no ledger.'];
            $returnSection='ledger';
        }elseif($action==='ledger_reverse'){
            fs_monetization_reverse_ledger($pdo,max(0,(int)($_POST['id']??0)),(string)($_POST['reason']??''));
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Reversão compensatória registrada sem apagar o histórico.'];
            $returnSection='ledger';
        }elseif($action==='ledger_review'){
            $approve=(string)($_POST['decision']??'')==='approve';
            $reason=trim((string)($_POST['reason']??''));
            if(!$approve&&$reason==='')throw new InvalidArgumentException('Informe o motivo da rejeição.');
            fs_monetization_review_ledger($pdo,max(0,(int)($_POST['id']??0)),$approve,$reason);
            $_SESSION['monetization_flash']=['ok'=>true,'message'=>$approve?'Lançamento aprovado.':'Lançamento rejeitado sem gerar saldo.'];
            $returnSection='ledger';
        }else throw new InvalidArgumentException('Ação financeira inválida.');
    }catch(Throwable $error){
        $_SESSION['monetization_flash']=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível concluir a operação financeira.')];
    }
    mon_redirect($returnSection);
}

$partners=$pdo->query('SELECT id,name,code,active FROM partners ORDER BY active DESC,name,id')->fetchAll()?:[];
$agreements=fs_monetization_agreements($pdo);
$campaigns=fs_monetization_campaigns($pdo);
$assignments=$pdo->query('SELECT cp.*,c.name campaign_name,p.name partner_name FROM ad_campaign_partners cp JOIN ad_campaigns c ON c.id=cp.campaign_id JOIN partners p ON p.id=cp.partner_id ORDER BY cp.updated_at DESC,cp.id DESC')->fetchAll()?:[];
$marketplace=$pdo->query('SELECT m.id,m.partner_id,m.seller_user_id,m.credential_hint,m.token_expires_at,m.status,m.last_error,m.authorized_at,m.updated_at,p.name partner_name FROM marketplace_accounts m JOIN partners p ON p.id=m.partner_id ORDER BY m.updated_at DESC')->fetchAll()?:[];
$orders=fs_monetization_orders($pdo,50);
$settlements=fs_monetization_settlements($pdo);
$ledger=$pdo->query('SELECT l.*,p.name partner_name,c.name campaign_name FROM monetization_ledger l JOIN partners p ON p.id=l.partner_id LEFT JOIN ad_campaigns c ON c.id=l.campaign_id ORDER BY l.id DESC LIMIT 100')->fetchAll()?:[];
$totals=$pdo->query("SELECT COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN amount_cents ELSE 0 END),0) payable,COALESCE(SUM(CASE WHEN status='settled' THEN amount_cents ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) pending_review FROM monetization_ledger")->fetch()?:[];
$adPlatformReady=fs_ad_platform_schema_ready($pdo);
$adPlatform=fs_ad_platform_settings($pdo);
$adPlacements=fs_ad_platform_placement_rows($pdo);
$adDeliverySummary=fs_ad_platform_delivery_summary($pdo,null,30);
$adRevenueSummary=fs_ad_platform_revenue_summary($pdo,null,30);
$adRecentDeliveries=fs_ad_platform_recent_deliveries($pdo,null,30);
$runtimeIssues=[];
if(!$adPlatformReady)$runtimeIssues[]='Migração 057 da rede publicitária ainda não foi aplicada.';
if($section==='ad_network'&&(string)env('GOOGLE_AD_MANAGER_PRODUCTION_APPROVED','0')!=='1')$runtimeIssues[]='Produção Google ainda não aprovada no ambiente; somente teste é permitido.';
if($section==='ad_network'&&(string)env('GOOGLE_AD_WALLED_GARDEN_VALIDATED','0')!=='1')$runtimeIssues[]='Carregamento Google antes da autenticação ainda não foi validado no captive portal.';
if(!function_exists('sodium_crypto_secretbox'))$runtimeIssues[]='Extensão Sodium indisponível para proteger tokens e dados pessoais.';
if(!function_exists('curl_init'))$runtimeIssues[]='Extensão cURL indisponível para o Mercado Pago.';
if(trim((string)env('PAYMENT_CREDENTIAL_KEY',''))==='')$runtimeIssues[]='PAYMENT_CREDENTIAL_KEY não configurada.';
$globalWallet=fs_global_wallet();
if(trim((string)($globalWallet['access_token']??''))===''||trim((string)($globalWallet['public_key']??''))==='')$runtimeIssues[]='Carteira global Mercado Pago não está pronta.';
if(trim((string)env('MERCADOPAGO_CLIENT_ID',''))===''||trim((string)env('MERCADOPAGO_CLIENT_SECRET',''))==='')$runtimeIssues[]='Credenciais OAuth Marketplace não configuradas.';
$canManage=admin_has_capability('monetization.manage');
$csrf=csrf_token();
$titulo='Monetização e repasses';
$pageId='monetizacao';
$statusTone=static fn(string $status):string=>in_array($status,['active','paid','approved'],true)?'success':(in_array($status,['pending','open','awaiting_payment'],true)?'warning':(in_array($status,['failed','cancelled','rejected'],true)?'danger':'neutral'));
ob_start();
?>
<?php if($flash):?><div class="notice <?=!empty($flash['ok'])?'success':'error'?>"><?=htmlspecialchars((string)$flash['message'])?></div><?php endif;?>
<?php if($runtimeIssues):?><section class="notice error"><strong>Operação financeira com pendências.</strong><ul><?php foreach($runtimeIssues as $issue):?><li><?=htmlspecialchars($issue)?></li><?php endforeach;?></ul></section><?php endif;?>
<?php if($charge):?><section class="card"><h2>Cobrança Pix pendente — <?=mon_money($charge['amount'])?></h2><?php if(!empty($charge['qr_image'])):?><img src="<?=htmlspecialchars((string)$charge['qr_image'])?>" alt="QR Code Pix" width="220"><?php endif;?><p class="fs-cc-break-word"><?=htmlspecialchars((string)($charge['qr_code']??''))?></p><?php if(!empty($charge['ticket_url'])):?><a class="btn" href="<?=htmlspecialchars((string)$charge['ticket_url'])?>" target="_blank" rel="noopener">Abrir no Mercado Pago</a><?php endif;?></section><?php endif;?>
<section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Financeiro</span><h2>Monetização, publicidade e repasses</h2><p>Rede publicitária FireSpot, campanhas diretas, demanda Google, contratos, créditos e fechamentos.</p></div><a class="btn primary" href="campanhas.php?section=commercial">Abrir Campanhas</a></section>
<section class="fs-cc-kpis"><article class="card"><span>A receber</span><strong><?=mon_money($totals['payable']??0)?></strong><small>publicidade e ajustes</small></article><article class="card"><span>Já repassado</span><strong><?=mon_money($totals['paid']??0)?></strong><small>lançamentos liquidados</small></article><article class="card"><span>Revisões pendentes</span><strong><?=(int)($totals['pending_review']??0)?></strong><small>entradas do ledger</small></article><article class="card"><span>Marketplace ativo</span><strong><?=count(array_filter($marketplace,static fn(array $account):bool=>(string)$account['status']==='active'))?></strong><small>contas autorizadas</small></article></section>

<?php if($section==='overview'):?>
<section class="fs-cc-workspace-grid"><a class="card" href="?section=ad_network"><span>Publicidade</span><strong>Rede FireSpot</strong><small>Provedor, posicionamentos, privacidade e modo de teste.</small></a><a class="card" href="?section=agreements"><span>Contratos</span><strong>Modelos de monetização</strong><small>Mensalidade, comissão e publicidade remunerada.</small></a><a class="card" href="?section=funding"><span>Cobrança</span><strong>Créditos e conciliação</strong><small>Orçamento de campanhas e mensalidades.</small></a><a class="card" href="?section=marketplace"><span>Mercado Pago</span><strong>Contas Marketplace</strong><small>Autorização de contas recebedoras.</small></a><a class="card" href="?section=settlements"><span>Repasses</span><strong>Fechamentos</strong><small>Apuração, aprovação e pagamento.</small></a><a class="card" href="?section=ledger"><span>Auditoria</span><strong>Ledger financeiro</strong><small>Lançamentos, revisões e ajustes compensatórios.</small></a><a class="card" href="recebimentos.php?section=wallets"><span>Carteiras</span><strong>Destinos financeiros</strong><small>Carteira global e inventário por estabelecimento.</small></a></section>

<?php elseif($section==='ad_network'):?>
<?php if(!$adPlatformReady):?>
<section class="card"><span class="pill">Preparação pendente</span><h2>Rede publicitária ainda não instalada no banco</h2><p>A migração 057 está pronta e nasce completamente desligada. A conta da aplicação não possui permissão DDL; aplique-a pelo runner administrativo antes de configurar o provedor.</p><code>sudo php app/cli/migrations.php --apply</code></section>
<?php else:?>
<section class="notice"><strong>Pré-requisitos de produção:</strong> conta e domínio aprovados, <code>ads.txt</code> conferido, política pública vigente e carregamento dos domínios Google validado no captive portal. Esta tela não altera o walled garden do RouterOS.</section>
<section class="fs-cc-kpis"><article class="card"><span>Provedor</span><strong><?=htmlspecialchars(fs_ad_platform_provider_label((string)$adPlatform['provider']))?></strong><small><?=htmlspecialchars($adPlatform['provider'])?></small></article><article class="card"><span>Veiculação</span><strong><?=!empty($adPlatform['enabled'])?'Ativa':'Desligada'?></strong><small><?=htmlspecialchars($adPlatform['configuration_status'])?></small></article><article class="card"><span>Ambiente</span><strong><?=!empty($adPlatform['test_mode'])?'Teste':'Produção'?></strong><small>nenhuma cobrança no modo simulado</small></article><article class="card"><span>Receita</span><strong>FireSpot</strong><small>não gera saldo ao estabelecimento</small></article></section>
<section class="fs-cc-kpis"><article class="card"><span>Entregas · 30 dias</span><strong><?=(int)$adDeliverySummary['deliveries']?></strong><small><?=(int)$adDeliverySummary['simulations']?> em simulação</small></article><article class="card"><span>Recompensas concluídas</span><strong><?=(int)$adDeliverySummary['rewarded_ready']?></strong><small><?=(int)$adDeliverySummary['accesses_granted']?> acessos vinculados</small></article><article class="card"><span>Impressões Google</span><strong><?=(int)$adRevenueSummary['impressions']?></strong><small><?=(int)$adRevenueSummary['rewarded_completed']?> recompensadas importadas</small></article><article class="card"><span>Receita estimada Google</span><strong><?=mon_money($adRevenueSummary['estimated_cents'])?></strong><small>FireSpot · fora do ledger do estabelecimento</small></article></section>
<?php if($canManage):?><section class="card fs-cc-editor"><div class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Rede central</span><h2>Google Ad Manager e preenchimento</h2><p>Campanhas diretas têm prioridade; o Google preenche somente o inventário remanescente.</p></div></div><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="ad_network"><input type="hidden" name="action" value="ad_platform_save"><div class="fs-cc-form-grid"><label><span>Provedor</span><select name="provider"><option value="off" <?=$adPlatform['provider']==='off'?'selected':''?>>Desligado</option><option value="mock" <?=$adPlatform['provider']==='mock'?'selected':''?>>Simulado</option><option value="google_ad_manager" <?=$adPlatform['provider']==='google_ad_manager'?'selected':''?>>Google Ad Manager</option></select></label><label><span>Network code</span><input name="network_code" inputmode="numeric" value="<?=htmlspecialchars((string)($adPlatform['network_code']??''))?>" placeholder="12345678"></label><label><span>Privacidade inicial</span><select name="privacy_mode"><option value="non_personalized" <?=$adPlatform['privacy_mode']==='non_personalized'?'selected':''?>>Anúncios não personalizados</option><option value="consent_based" <?=$adPlatform['privacy_mode']==='consent_based'?'selected':''?>>Conforme consentimento</option></select></label><label><span>Confirmação de produção</span><input name="production_confirmation" autocomplete="off" placeholder="ATIVAR GOOGLE"></label><label class="fs-cc-check"><input type="checkbox" name="enabled" value="1" <?=!empty($adPlatform['enabled'])?'checked':''?>><span>Permitir veiculação central</span></label><label class="fs-cc-check"><input type="checkbox" name="test_mode" value="1" <?=!empty($adPlatform['test_mode'])?'checked':''?>><span>Manter modo de teste</span></label></div><h3>Posicionamentos</h3><?php foreach($adPlacements as $placement):?><div class="fs-cc-form-grid"><label class="fs-cc-check"><input type="checkbox" name="active_<?=htmlspecialchars($placement['code'])?>" value="1" <?=!empty($placement['active'])?'checked':''?>><span><?=htmlspecialchars($placement['name'])?></span></label><label class="fs-cc-span-two"><span>Ad unit path</span><input name="placement_<?=htmlspecialchars($placement['code'])?>" value="<?=htmlspecialchars((string)($placement['google_ad_unit_path']??''))?>" placeholder="/12345678/firespot/<?=htmlspecialchars($placement['code'])?>"></label></div><?php endforeach;?><div class="notice"><strong>Titularidade:</strong> toda receita do preenchimento Google pertence à FireSpot. Esta configuração não cria crédito no ledger do estabelecimento.</div><button class="btn primary" type="submit">Salvar rede publicitária</button></form></section><?php endif;?>
<section class="card"><h2>Últimas entregas da rede</h2><p class="muted">Eventos operacionais próprios; valores do Google entram somente pela importação conciliada.</p><table class="simple-table"><thead><tr><th>Data</th><th>Estabelecimento / ponto</th><th>Posicionamento</th><th>Origem</th><th>Resultado</th><th>Ambiente</th></tr></thead><tbody><?php if(!$adRecentDeliveries):?><tr><td colspan="6">Nenhuma entrega registrada.</td></tr><?php endif;?><?php foreach($adRecentDeliveries as $delivery):?><tr><td><?=htmlspecialchars((string)$delivery['created_at'])?></td><td><?=htmlspecialchars((string)$delivery['partner_name'])?><br><small><?=htmlspecialchars((string)$delivery['hotspot_name'])?></small></td><td><?=htmlspecialchars((string)$delivery['placement_name'])?></td><td><?=htmlspecialchars(fs_ad_platform_source_label((string)$delivery['source_code']))?></td><td><?=htmlspecialchars((string)$delivery['state'])?><?=$delivery['required_ad_count']>1?' · '.(int)$delivery['completed_ad_count'].'/'.(int)$delivery['required_ad_count']:''?></td><td><?=!empty($delivery['is_simulation'])?'Teste':'Produção'?></td></tr><?php endforeach;?></tbody></table></section>
<?php endif;?>

<?php elseif($section==='agreements'):?>
<?php if($canManage):?><details class="card fs-cc-editor" open><summary>Novo contrato financeiro do estabelecimento</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="agreements"><input type="hidden" name="action" value="agreement_save"><div class="fs-cc-form-grid"><label><span>Estabelecimento</span><select name="partner_id" required><?php foreach($partners as $partner):?><option value="<?=(int)$partner['id']?>"><?=htmlspecialchars($partner['name'])?></option><?php endforeach;?></select></label><label><span>Modelo</span><select name="model"><option value="subscription">Mensalidade</option><option value="revenue_share">Participação nas vendas</option><option value="hybrid">Híbrido</option></select></label><label><span>Mensalidade (R$)</span><input name="monthly_fee" inputmode="decimal" value="0,00"></label><label><span>Comissão</span><select name="access_fee_type"><option value="none">Sem comissão</option><option value="percentage">Percentual</option><option value="fixed">Valor fixo</option></select></label><label><span>Percentual ou valor</span><input name="access_fee" inputmode="decimal" value="0"></label><label><span>Origem da mensalidade</span><select name="billing_source"><option value="mercadopago">Mercado Pago</option><option value="external_firenetwork">Faturamento FIRENETWORK</option></select></label><label><span>Início</span><input type="datetime-local" name="starts_at" value="<?=date('Y-m-d\TH:i')?>"></label><label class="fs-cc-check"><input type="checkbox" name="advertising_enabled" value="1"><span>Publicidade remunerada habilitada</span></label></div><div class="fs-cc-form-actions"><button class="btn primary" type="submit">Ativar nova versão</button></div></form></details><?php endif;?>
<section class="card"><h2>Contratos vigentes e históricos</h2><table class="simple-table"><thead><tr><th>Estabelecimento</th><th>Modelo</th><th>Mensalidade</th><th>Comissão</th><th>Publicidade</th><th>Situação</th><th>Versão</th></tr></thead><tbody><?php foreach($agreements as $agreement):?><tr><td><?=htmlspecialchars($agreement['partner_name'])?></td><td><?=htmlspecialchars($agreement['model'])?></td><td><?=mon_money($agreement['monthly_fee_cents'])?></td><td><?=$agreement['access_fee_type']==='percentage'?number_format((int)$agreement['access_fee_value']/100,2,',','.').'%':mon_money($agreement['access_fee_value'])?></td><td><?=$agreement['advertising_enabled']?'Sim':'Não'?></td><td><?=fs_cc_status_pill((string)$agreement['status'],$statusTone((string)$agreement['status']))?></td><td>v<?=(int)$agreement['version']?></td></tr><?php endforeach;?></tbody></table></section>

<?php elseif($section==='funding'):?>
<?php if($canManage):?><section class="fs-cc-grid-two"><details class="card fs-cc-editor"><summary>Contrato financeiro da campanha</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="campaign_terms_save"><div class="fs-cc-form-grid"><label class="fs-cc-span-two"><span>Campanha</span><select name="campaign_id"><?php foreach($campaigns as $campaign):if($campaign['campaign_type']!=='commercial')continue;?><option value="<?=(int)$campaign['id']?>"><?=htmlspecialchars($campaign['name'])?> · orçamento <?=mon_money($campaign['budget_cents'])?></option><?php endforeach;?></select></label><label><span>Orçamento contratado (R$)</span><input name="budget" required></label><label><span>Preço CPM (R$)</span><input name="view_cpm" value="0,00"></label><label><span>Preço por clique (R$)</span><input name="click_price" value="0,00"></label><label><span>Preço por lead (R$)</span><input name="lead_price" value="0,00"></label></div><p class="muted">Composição, vigência, frequência e criativos não são alterados aqui.</p><button class="btn primary" type="submit">Salvar contrato financeiro</button></form></details><details class="card fs-cc-editor"><summary>Crédito pré-pago da campanha</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="campaign_charge"><div class="fs-cc-form-grid"><label class="fs-cc-span-two"><span>Campanha</span><select name="campaign_id"><?php foreach($campaigns as $campaign):if($campaign['campaign_type']!=='commercial')continue;?><option value="<?=(int)$campaign['id']?>"><?=htmlspecialchars($campaign['name'])?> · disponível <?=mon_money(max(0,(int)$campaign['budget_cents']-(int)$campaign['funded_cents']))?></option><?php endforeach;?></select></label><label><span>Valor (R$)</span><input name="amount" required></label><label><span>E-mail do pagador</span><input type="email" name="payer_email" required></label></div><button class="btn primary" type="submit">Gerar Pix</button></form></details></section>
<section class="fs-cc-grid-two"><details class="card fs-cc-editor"><summary>Cobrar mensalidade no Mercado Pago</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="subscription_charge"><label><span>Contrato ativo</span><select name="agreement_id"><?php foreach($agreements as $agreement):if($agreement['status']!=='active'||!in_array($agreement['model'],['subscription','hybrid'],true)||$agreement['billing_source']!=='mercadopago')continue;?><option value="<?=(int)$agreement['id']?>"><?=htmlspecialchars($agreement['partner_name'])?> · <?=mon_money($agreement['monthly_fee_cents'])?></option><?php endforeach;?></select></label><label><span>E-mail do pagador</span><input type="email" name="payer_email" required></label><button class="btn primary" type="submit">Gerar Pix da mensalidade</button></form></details><details class="card fs-cc-editor"><summary>Conciliar mensalidade FIRENETWORK</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="subscription_external"><label><span>Contrato externo</span><select name="agreement_id"><?php foreach($agreements as $agreement):if($agreement['status']!=='active'||!in_array($agreement['model'],['subscription','hybrid'],true)||$agreement['billing_source']!=='external_firenetwork')continue;?><option value="<?=(int)$agreement['id']?>"><?=htmlspecialchars($agreement['partner_name'])?></option><?php endforeach;?></select></label><label><span>Valor faturado (R$)</span><input name="amount" required></label><label><span>Referência da fatura</span><input name="reference" maxlength="96" required></label><button class="btn primary" type="submit">Registrar conciliação</button></form></details></section><?php endif;?>
<section class="card"><h2>Valores por estabelecimento participante</h2><table class="simple-table"><thead><tr><th>Campanha</th><th>Estabelecimento</th><th>CPM</th><th>Clique</th><th>Lead</th><th>Ação</th></tr></thead><tbody><?php foreach($assignments as $assignment):?><tr><td><?=htmlspecialchars($assignment['campaign_name'])?></td><td><?=htmlspecialchars($assignment['partner_name'])?></td><td><?=mon_money($assignment['partner_view_cpm_cents'])?></td><td><?=mon_money($assignment['partner_click_cents'])?></td><td><?=mon_money($assignment['partner_lead_cents'])?></td><td><?php if($canManage):?><details class="fs-cc-editor"><summary>Editar valores</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="assignment_terms_save"><input type="hidden" name="assignment_id" value="<?=(int)$assignment['id']?>"><label><span>Repasse CPM</span><input name="partner_view_cpm" value="<?=number_format((int)$assignment['partner_view_cpm_cents']/100,2,',','.')?>"></label><label><span>Repasse por clique</span><input name="partner_click" value="<?=number_format((int)$assignment['partner_click_cents']/100,2,',','.')?>"></label><label><span>Repasse por lead</span><input name="partner_lead" value="<?=number_format((int)$assignment['partner_lead_cents']/100,2,',','.')?>"></label><button class="btn" type="submit">Salvar valores</button></form></details><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>
<section class="card"><h2>Cobranças comerciais</h2><table class="simple-table"><thead><tr><th>Data</th><th>Tipo</th><th>Destino</th><th>Valor</th><th>Situação</th><th>Ação</th></tr></thead><tbody><?php foreach($orders as $order):?><tr><td><?=htmlspecialchars($order['created_at'])?></td><td><?=htmlspecialchars($order['order_type'])?></td><td><?=htmlspecialchars($order['campaign_name']?:$order['partner_name']?:'—')?></td><td><?=mon_money($order['amount_cents'])?></td><td><?=fs_cc_status_pill((string)$order['status'],$statusTone((string)$order['status']))?></td><td><?php if($canManage&&!empty($order['provider_payment_id'])):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="funding"><input type="hidden" name="action" value="order_reconcile"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><button class="btn" type="submit">Conciliar</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>

<?php elseif($section==='marketplace'):?>
<?php if($canManage):?><details class="card fs-cc-editor" open><summary>Autorizar conta Marketplace</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="marketplace"><input type="hidden" name="action" value="marketplace_start"><label><span>Estabelecimento</span><select name="partner_id"><?php foreach($partners as $partner):?><option value="<?=(int)$partner['id']?>"><?=htmlspecialchars($partner['name'])?></option><?php endforeach;?></select></label><p>A autorização ocorre no Mercado Pago; a FireSpot não solicita nem exibe a senha do responsável.</p><button class="btn primary" type="submit">Iniciar OAuth</button></form></details><?php endif;?>
<section class="card"><h2>Contas Marketplace</h2><table class="simple-table"><thead><tr><th>Estabelecimento</th><th>Conta</th><th>Situação</th><th>Credencial</th><th>Autorizada</th><th>Ação</th></tr></thead><tbody><?php if(!$marketplace):?><tr><td colspan="6">Nenhuma conta autorizada.</td></tr><?php endif;?><?php foreach($marketplace as $account):?><tr><td><?=htmlspecialchars($account['partner_name'])?></td><td><?=htmlspecialchars($account['seller_user_id'])?></td><td><?=fs_cc_status_pill((string)$account['status'],$statusTone((string)$account['status']))?></td><td><?=htmlspecialchars($account['credential_hint'])?></td><td><?=htmlspecialchars($account['authorized_at'])?></td><td><?php if($canManage&&$account['status']==='active'):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="marketplace"><input type="hidden" name="action" value="marketplace_disconnect"><input type="hidden" name="partner_id" value="<?=(int)$account['partner_id']?>"><button class="btn" type="submit">Revogar</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>

<?php elseif($section==='settlements'):?>
<?php if($canManage):?><details class="card fs-cc-editor"><summary>Novo fechamento</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="settlements"><input type="hidden" name="action" value="settlement_create"><div class="fs-cc-form-grid"><label class="fs-cc-span-two"><span>Estabelecimento</span><select name="partner_id"><?php foreach($partners as $partner):?><option value="<?=(int)$partner['id']?>"><?=htmlspecialchars($partner['name'])?></option><?php endforeach;?></select></label><label><span>De</span><input type="date" name="period_start" required></label><label><span>Até</span><input type="date" name="period_end" required></label></div><button class="btn primary" type="submit">Criar fechamento</button></form></details><?php endif;?>
<section class="card"><h2>Fechamentos de publicidade</h2><table class="simple-table"><thead><tr><th>Estabelecimento</th><th>Período</th><th>Total</th><th>Situação</th><th>Repasse</th></tr></thead><tbody><?php foreach($settlements as $settlement):?><tr><td><?=htmlspecialchars($settlement['partner_name'])?></td><td><?=htmlspecialchars($settlement['period_start'])?> — <?=htmlspecialchars($settlement['period_end'])?></td><td><?=mon_money($settlement['total_cents'])?></td><td><?=fs_cc_status_pill((string)$settlement['status'],$statusTone((string)$settlement['status']))?></td><td><?php if($canManage&&$settlement['status']==='open'):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="settlements"><input type="hidden" name="action" value="settlement_approve"><input type="hidden" name="id" value="<?=(int)$settlement['id']?>"><button class="btn" type="submit">Aprovar</button></form><?php elseif($canManage&&$settlement['status']==='approved'):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="settlements"><input type="hidden" name="action" value="settlement_paid"><input type="hidden" name="id" value="<?=(int)$settlement['id']?>"><select name="payment_method"><option value="pix">Pix</option><option value="mercadopago">Mercado Pago</option></select><input name="provider_reference" placeholder="Referência" required><button class="btn" type="submit">Marcar pago</button></form><?php else:?><?=htmlspecialchars($settlement['provider_reference']?:'—')?><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>

<?php elseif($section==='ledger'):?>
<section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Auditoria financeira</span><h2>Ledger imutável e ajustes compensatórios</h2><p>Entradas históricas não são apagadas; correções geram lançamentos de revisão ou reversão.</p></div><a class="btn" href="api/monetization_export.php?csrf=<?=rawurlencode($csrf)?>">Exportar 90 dias</a></section>
<?php if($canManage):?><details class="card fs-cc-editor"><summary>Ajuste compensatório</summary><form method="post" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="ledger"><input type="hidden" name="action" value="ledger_adjust"><div class="fs-cc-form-grid"><label><span>Estabelecimento</span><select name="partner_id"><?php foreach($partners as $partner):?><option value="<?=(int)$partner['id']?>"><?=htmlspecialchars($partner['name'])?></option><?php endforeach;?></select></label><label><span>Valor positivo ou negativo</span><input name="amount" required></label><label class="fs-cc-span-two"><span>Motivo</span><input name="reason" maxlength="100" required></label></div><button class="btn primary" type="submit">Lançar ajuste</button></form></details><?php endif;?>
<section class="card"><h2>Últimos lançamentos</h2><table class="simple-table"><thead><tr><th>Data</th><th>Estabelecimento</th><th>Origem</th><th>Campanha</th><th>Valor</th><th>Situação</th><th>Fechamento/ação</th></tr></thead><tbody><?php foreach($ledger as $entry):?><tr><td><?=htmlspecialchars($entry['occurred_at'])?></td><td><?=htmlspecialchars($entry['partner_name'])?></td><td><?=htmlspecialchars($entry['source_type'])?></td><td><?=htmlspecialchars($entry['campaign_name']?:'—')?></td><td><?=mon_money($entry['amount_cents'])?></td><td><?=fs_cc_status_pill((string)$entry['status'],$statusTone((string)$entry['status']))?></td><td><?=htmlspecialchars((string)($entry['settlement_id']?:'—'))?><?php if($canManage&&$entry['status']==='pending'):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="ledger"><input type="hidden" name="action" value="ledger_review"><input type="hidden" name="id" value="<?=(int)$entry['id']?>"><input name="reason" maxlength="100" placeholder="Motivo ao rejeitar"><button class="btn" name="decision" value="approve" type="submit">Aprovar</button><button class="btn" name="decision" value="reject" type="submit">Rejeitar</button></form><?php elseif($canManage&&in_array($entry['status'],['approved','settled'],true)):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="return_section" value="ledger"><input type="hidden" name="action" value="ledger_reverse"><input type="hidden" name="id" value="<?=(int)$entry['id']?>"><input name="reason" maxlength="100" placeholder="Motivo da reversão" required><button class="btn" type="submit">Reverter</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>
<?php endif;?>
<?php
$conteudo=(string)ob_get_clean();
require __DIR__.'/layout.php';
