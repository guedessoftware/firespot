<?php

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../../app/partner_central.php';
require_once __DIR__ . '/../../app/partner_ads.php';
require_once __DIR__ . '/../../app/partner_finance.php';
require_once __DIR__ . '/../../app/monetization.php';
require_once __DIR__ . '/../../app/ad_monetization.php';
require_once __DIR__ . '/../../app/ad_platform.php';
require_once __DIR__ . '/../../app/partner_hotspots.php';
require_once __DIR__ . '/../../app/partner_infrastructure.php';
require_once __DIR__ . '/../../app/partner_hotspot_commercial.php';
require_once __DIR__ . '/../../app/partner_courtesy_management.php';
require_once __DIR__ . '/../../app/partner_analytics.php';
require_once __DIR__ . '/../../app/partner_dashboard.php';
require_once __DIR__ . '/../../app/portal_skin.php';
require_once __DIR__ . '/../../app/settings.php';

$pdo = host_db();
$context = partner_admin_context($pdo);
if (!$context) { header('Location: ' . host_admin_url('login', ['expired'=>1])); exit; }
define('FIRESPOT_HOST_PANEL_VIEW',true);
$partnerId = (int)$context['partner_id'];
$role = (string)$context['role'];
$canViewSales = partner_admin_role_has($role,'sales.view');
$canViewFinance = partner_admin_role_has($role,'finance.view');
$portalConfig=fs_portal_config_for_partner($pdo,$context);
$enabled = (int)($context['self_service_enabled'] ?? 0) === 1 && !empty($portalConfig);
$page = preg_replace('/[^a-z_]/','',(string)($_GET['page'] ?? 'summary')) ?: 'summary';
// Compatibilidade dos favoritos antigos: a antiga página combinada abre a
// área de NAS quando contratada e, caso contrário, a consulta de pontos.
if($page==='infrastructure')$page=fs_partner_has_entitlement($pdo,$partnerId,'nas.view',false)?'nas':'hotspots';
// Favoritos antigos da identidade visual agora abrem a configuração unificada.
if(in_array($page,['theme','simulation'],true))$page='portal';
$advancedAnalyticsEnabled=partner_admin_role_has($role,'reports.view')
    &&fs_portal_config_module_allowed($pdo,$context,'analytics')
    &&fs_partner_has_entitlement($pdo,$partnerId,'reports.advanced',false);
// Relatórios avançados agora fazem parte de Métricas. O endereço antigo
// permanece compatível, enquanto planos básicos conservam sua visão simples.
if($page==='reports'&&$advancedAnalyticsEnabled)$page='analytics';
$pageRules = [
    'summary' => [null,'summary','portal.basic'],
    'portal' => ['theme.manage','portal','portal.presentation.manage'],
    'plans' => ['plans.manage','plans','guest_plans.manage'],
    'billing' => ['wallet.manage','billing','wallet.manage'],
    'finance' => ['sales.view','finance','finance.view'],
    'monetization' => ['monetization.view','monetization','monetization.view'],
    'ads' => ['ads.manage','ads','ads.manage'],
    'reports' => ['reports.view','reports','reports.basic'],
    'team' => ['team.manage','team','team.manage'],
    'nas' => ['infrastructure.view','infrastructure','nas.view'],
    'hotspots' => ['infrastructure.view','infrastructure','hotspots.view'],
    'courtesy' => ['courtesy.view','portal','courtesy.view'],
    'analytics' => ['reports.view','analytics','reports.advanced'],
    'audit' => ['audit.view','audit','portal.basic'],
];
if (!isset($pageRules[$page]) || ($pageRules[$page][0] && !partner_admin_role_has($role,$pageRules[$page][0]))
    || !fs_portal_config_module_allowed($pdo,$context,$pageRules[$page][1])
    || !fs_partner_has_entitlement($pdo,$partnerId,$pageRules[$page][2],false)) $page='summary';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('FIRESPOT_HOST_PANEL_ENTRY', true);
    require_once __DIR__ . '/panel_actions.php';
    host_panel_handle_post($pdo,$partnerId);
}

$flash=$_SESSION['host_flash']??null;unset($_SESSION['host_flash']);
$inviteLink=$_SESSION['host_invite_link']??null;unset($_SESSION['host_invite_link']);
$purpose=fs_portal_config_label($portalConfig);
$nav=[
 ['summary','Resumo',null,'summary','portal.basic','Geral'],
 ['portal','Portal V3','theme.manage','portal','portal.presentation.manage','Portal'],
 ['plans','Planos de acesso','plans.manage','plans','guest_plans.manage','Portal'],['courtesy','Cortesia padrão','courtesy.view','portal','courtesy.view','Portal'],
 ['nas','NAS','infrastructure.view','infrastructure','nas.view','Operação'],['hotspots','Pontos Hotspot','infrastructure.view','infrastructure','hotspots.view','Operação'],['analytics','Métricas','reports.view','analytics','reports.advanced','Operação'],['reports','Métricas','reports.view','reports','reports.basic','Operação'],
 ['billing','Carteiras','wallet.manage','billing','wallet.manage','Financeiro'],['finance','Vendas','sales.view','finance','finance.view','Financeiro'],['monetization','Monetização','monetization.view','monetization','monetization.view','Financeiro'],
 ['ads','Campanhas','ads.manage','ads','ads.manage','Gestão'],['team','Equipe','team.manage','team','team.manage','Gestão'],['audit','Auditoria','audit.view','audit','portal.basic','Gestão'],
];
if($advancedAnalyticsEnabled)$nav=array_values(array_filter($nav,static fn(array $item):bool=>$item[0]!=='reports'));
$readiness=null;$plans=[];$wallets=[];$activeWallet=null;$ads=[];$members=[];$invitations=[];$theme=[];$effectivePlans=[];$themePreviewPlans=[];$publicHostForDisplay='';
$presentation=null;$migrationState=['installed'=>false,'status'=>'schema_pending'];$skinCatalog=[];
$platformSubscription=fs_partner_current_subscription($pdo,$partnerId);$platformLimits=fs_partner_plan_limits($pdo,$partnerId);$formIdempotency=bin2hex(random_bytes(16));
$walletPlanEligible=fs_partner_independence_plan_allows_wallet_activation($pdo,$partnerId,false);
$walletCanActivate=fs_partner_independence_plan_allows_wallet_activation($pdo,$partnerId,true);
$platformStatusLabels=['trial'=>'Teste','active'=>'Ativo','grace'=>'Carência · somente leitura','past_due'=>'Em atraso · somente leitura','suspended'=>'Suspenso · somente leitura','ended'=>'Encerrado · plano Essencial'];
$platformQuotaLabels=['max_nas'=>'NAS próprios','max_hotspots'=>'Pontos','max_admin_users'=>'Usuários administrativos','custom_courtesy_overrides'=>'Exceções de cortesia'];
$platformQuotaUsage=[];
if($platformSubscription){foreach(array_keys($platformQuotaLabels) as $quota)$platformQuotaUsage[$quota]=fs_partner_quota_usage($pdo,$partnerId,$quota);}
$partnerNas=[];$changeRequests=[];$nasInterfacesByNas=[];$partnerAuditRows=[];$hotspotConfigurationRequests=[];$pointCommercialPolicies=[];
$courtesyEffective=[];$courtesyDraft=null;$courtesyOverrides=[];$courtesyHistory=[];$pointCourtesyPolicies=[];
$pixQrExpirationMinutes=max(5,min(60,(int)env('MP_PIX_EXPIRE_MINUTES',10)));
if($page==='hotspots'){try{$pixQrExpirationMinutes=max(5,min(60,(int)settings_get('payment_pix_expire_minutes',$pixQrExpirationMinutes)));}catch(Throwable $e){}}
$analyticsFilters=null;$analyticsReport=null;$analyticsError=null;
$partnerHotspots=fs_partner_hotspots_for_partner($pdo,$partnerId,false);
$partnerHotspotIds=array_fill_keys(array_map(static fn(array $hotspot):int=>(int)$hotspot['id'],$partnerHotspots),true);
try{$readiness=fs_portal_config_validate($pdo,$context,$portalConfig);}catch(Throwable $e){}

$summaryDashboard=fs_partner_dashboard_empty();$summaryDashboardError=null;
$summaryDashboard['overview']['total_hotspots']=count($partnerHotspots);
$summaryDashboard['overview']['active_hotspots']=count(array_filter($partnerHotspots,static fn(array $hotspot):bool=>(int)$hotspot['active']===1));
$summaryDashboardEnabled=partner_admin_role_has($role,'reports.view')
    &&fs_portal_config_module_allowed($pdo,$context,'analytics')
    &&fs_partner_has_entitlement($pdo,$partnerId,'reports.advanced',false);
if($page==='summary'&&$summaryDashboardEnabled){
    try{$summaryDashboard=fs_partner_dashboard($pdo,$partnerId,8,$canViewSales&&fs_portal_config_has_sales($portalConfig));}
    catch(Throwable $e){error_log('[partner summary dashboard] partner_id='.$partnerId.' code=LOAD_FAILED');$summaryDashboardError='Não foi possível atualizar as métricas operacionais agora.';}
}
if(fs_portal_config_has_sales($portalConfig)){try{$effectivePlans=fs_guest_plans($pdo,$context);}catch(Throwable $e){$effectivePlans=[];}}

if($page==='portal'){
    $theme=portal_theme_get($pdo,$context,company_get());
    $themePreviewPlans=$effectivePlans;
    $publicHostForDisplay=fs_public_host($pdo);
    $migrationState=fs_portal_migration_state($pdo,$partnerId);
    $presentation=fs_portal_presentation_get($pdo,$partnerId,'draft')?:fs_portal_presentation_get($pdo,$partnerId,'candidate');
    $skinCatalog=fs_portal_skin_catalog($pdo,true);
}
if($page==='plans'){$st=$pdo->prepare('SELECT * FROM partner_payment_plans WHERE partner_id=? ORDER BY sort_order,id');$st->execute([$partnerId]);$plans=$st->fetchAll()?:[];}
if($page==='billing'){$wallets=fs_wallets_for_partner($pdo,$partnerId);foreach($wallets as $wallet)if((int)$wallet['active']===1){$activeWallet=$wallet;break;}}
if($page==='ads'){$ads=partner_ads_for_partner($pdo,$partnerId);}
if($page==='monetization'){
    $canMonSummary=partner_admin_role_has($role,'monetization.summary.view');$canMonCampaigns=partner_admin_role_has($role,'ads.campaigns.view');$canMonEarnings=partner_admin_role_has($role,'ads.earnings.view');$canMonLeads=partner_admin_role_has($role,'ads.leads.view');
    $canMonInventory=partner_admin_role_has($role,'ads.manage')&&fs_partner_has_entitlement($pdo,$partnerId,'ad.inventory.manage',false);
    $monAdSchemaReady=fs_ad_platform_schema_ready($pdo);$monAdPlatform=fs_ad_platform_settings($pdo);$monAdPolicy=fs_ad_platform_partner_policy($pdo,$partnerId);$monAdPointPolicies=[];$monAdDeliverySummary=fs_ad_platform_delivery_summary($pdo,$partnerId,30);$monAdRevenueSummary=fs_ad_platform_revenue_summary($pdo,$partnerId,30);
    if($monAdSchemaReady)foreach($partnerHotspots as $hotspot)$monAdPointPolicies[(int)$hotspot['id']]=fs_ad_platform_partner_policy($pdo,$partnerId,(int)$hotspot['id']);
    $monAgreement=$canMonSummary?fs_monetization_current_agreement($pdo,$partnerId):null;
    $monAccount=$canMonSummary?fs_marketplace_account($pdo,$partnerId):null;
    $monBalance=$canMonEarnings?fs_monetization_partner_balance($pdo,$partnerId):[];
    $monCampaigns=$canMonCampaigns?fs_monetization_campaigns($pdo,$partnerId):[];
    $monSettlements=$canMonEarnings?fs_monetization_settlements($pdo,$partnerId):[];
    // O painel operacional usa somente identificação mascarada. Qualquer
    // acesso futuro ao contato integral exigirá capacidade e auditoria próprias.
    $monLeads=$canMonLeads?fs_ad_partner_leads($pdo,$partnerId,false,100):[];
    if($monLeads)partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$context['user_id'],'leads.viewed','ad_lead',null,['count'=>count($monLeads)]);
    $monLedgerSummary=$monLedgerByHotspot=[];if($canMonEarnings){$st=$pdo->prepare("SELECT source_type,status,COUNT(*) total,COALESCE(SUM(amount_cents),0) amount_cents FROM monetization_ledger WHERE partner_id=? GROUP BY source_type,status ORDER BY source_type,status");$st->execute([$partnerId]);$monLedgerSummary=$st->fetchAll()?:[];$st=$pdo->prepare("SELECT h.id,h.name,h.code,COUNT(*) total,COALESCE(SUM(l.amount_cents),0) amount_cents FROM monetization_ledger l JOIN ad_deliveries d ON d.public_id=l.source_id AND d.partner_id=l.partner_id JOIN partner_hotspots h ON h.id=d.hotspot_id WHERE l.partner_id=? GROUP BY h.id,h.name,h.code ORDER BY amount_cents DESC,h.name");$st->execute([$partnerId]);$monLedgerByHotspot=$st->fetchAll()?:[];}
}
if($page==='team'){$members=partner_admin_members($pdo,$partnerId);$invitations=partner_admin_pending_invitations($pdo,$partnerId);}
if(in_array($page,['nas','hotspots'],true)&&fs_partner_infrastructure_schema_ready($pdo)){
    $partnerNas=fs_partner_nas_list($pdo,$partnerId);
    $changeRequests=fs_partner_change_requests($pdo,$partnerId,30,$page);
    if($page==='hotspots'){
        if(fs_partner_hotspot_commercial_schema_ready($pdo)){
            try{$pointCommercialPolicies=fs_partner_hotspot_commercial_policies($pdo,$partnerId,$context,$portalConfig);}catch(Throwable $e){$pointCommercialPolicies=[];}
        }
        $hotspotConfigurationRequests=fs_partner_hotspot_configuration_requests($pdo,$partnerId);
        if(partner_admin_role_has($role,'courtesy.view')&&fs_partner_has_entitlement($pdo,$partnerId,'courtesy.view',false)){
            foreach($partnerHotspots as $hotspot){
                if(!(int)($hotspot['active']??0))continue;
                try{$pointCourtesyPolicies[(int)$hotspot['id']]=fs_courtesy_policy_resolve($pdo,$partnerId,(int)$hotspot['id']);}catch(Throwable $e){}
            }
        }
        $assignedNasIds=[];foreach($partnerNas as $nas)if(($nas['assignment_status']??'')==='ready')$assignedNasIds[]=(int)$nas['id'];
        if($assignedNasIds){$placeholders=implode(',',array_fill(0,count($assignedNasIds),'?'));$statement=$pdo->prepare("SELECT id,nas_id,interface_name,interface_type,description FROM nas_interfaces WHERE nas_id IN ({$placeholders}) ORDER BY nas_id,interface_name");$statement->execute($assignedNasIds);foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $interface)$nasInterfacesByNas[(int)$interface['nas_id']][]=$interface;}
    }
}
if($page==='audit'){
    $statement=$pdo->prepare("SELECT a.id,a.action,a.target_type,a.target_id,a.metadata,a.created_at,a.actor_type,CASE WHEN a.actor_type='partner_admin' THEN u.name ELSE NULL END actor_name FROM partner_admin_audit a LEFT JOIN host_users u ON a.actor_type='partner_admin' AND u.id=a.actor_id WHERE a.partner_id=? ORDER BY a.id DESC LIMIT 100");
    $statement->execute([$partnerId]);$partnerAuditRows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    partner_admin_audit($pdo,$partnerId,'partner_admin',(int)$context['user_id'],'audit.viewed','partner_admin_audit',null,['count'=>count($partnerAuditRows)]);
}
if($page==='courtesy'){
    $courtesyEffective=fs_courtesy_policy_resolve($pdo,$partnerId);$courtesyDraft=fs_partner_courtesy_revision($pdo,$partnerId,'draft');$courtesyOverrides=fs_partner_courtesy_overrides($pdo,$partnerId);$courtesyHistory=fs_partner_courtesy_history($pdo,$partnerId,10);
}
if($page==='analytics'){
    try{$analyticsFilters=fs_partner_analytics_filters($pdo,$partnerId,$_GET);$analyticsReport=fs_partner_analytics_report($pdo,$partnerId,$analyticsFilters);}catch(Throwable $e){$analyticsError=partner_admin_public_error($e,'Não foi possível carregar as métricas.');}
}

$financeError=null;
$financeFilters=partner_finance_filters([]);
$financeOverview=['paid_count'=>0,'paid_amount'=>0,'average_ticket'=>0,'pending_count'=>0,'pending_amount'=>0,'failed_count'=>0,'refunded_count'=>0];
$financePageSize=in_array((int)($_GET['page_size']??10),[10,20,50],true)?(int)($_GET['page_size']??10):10;
$financeSales=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'page_size'=>$financePageSize];
if($page==='finance'){
    try{$financeFilters=partner_finance_filters($_GET);if(!empty($financeFilters['hotspot_id'])&&!isset($partnerHotspotIds[(int)$financeFilters['hotspot_id']]))throw new InvalidArgumentException('O ponto selecionado não pertence a este estabelecimento.');}catch(InvalidArgumentException $e){$financeError=$e->getMessage();$financeFilters=partner_finance_filters([]);}
    try{
        $financeOverview=partner_finance_overview($pdo,$partnerId,$financeFilters);
        $financeSales=partner_finance_sales($pdo,$partnerId,$financeFilters,$financePageSize);
    }catch(Throwable $e){
        error_log('partner_finance: '.get_class($e).': '.$e->getMessage());
        $financeError='Não foi possível carregar as vendas agora. Tente novamente.';
    }
}
$financePageUrl=static function(int $targetPage)use($financeFilters,$financePageSize):string{
    return '?'.http_build_query([
        'page'=>'finance',
        'from'=>$financeFilters['from'],
        'to'=>$financeFilters['to'],
        'status'=>$financeFilters['status'],
        'hotspot_id'=>$financeFilters['hotspot_id'] ?: '',
        'page_size'=>$financePageSize,
        'p'=>max(1,$targetPage),
    ],'','&',PHP_QUERY_RFC3986);
};

$reportCourtesy=$reportSales=$reportPlans=$reportAds=$reportMembers=$reportByHotspot=$reportSubscriber=[];
$reportHotspotId=null;
if($page==='reports'||($page==='analytics'&&$advancedAnalyticsEnabled)){
 $requestedHotspot=trim((string)($_GET['hotspot_id']??''));if($requestedHotspot!==''&&ctype_digit($requestedHotspot)&&isset($partnerHotspotIds[(int)$requestedHotspot]))$reportHotspotId=(int)$requestedHotspot;
 $reportFrom=$page==='analytics'&&!empty($analyticsFilters['from'])?(string)$analyticsFilters['from']:date('Y-m-d',strtotime('-29 days'));
 $reportTo=$page==='analytics'&&!empty($analyticsFilters['to'])?(string)$analyticsFilters['to']:date('Y-m-d');
 $reportToExclusive=date('Y-m-d',strtotime($reportTo.' +1 day'));
 $hotspotClause=$reportHotspotId?' AND hotspot_id=?':'';
 $reportParams=$reportHotspotId?[$partnerId,$reportHotspotId,$reportFrom,$reportToExclusive]:[$partnerId,$reportFrom,$reportToExclusive];
 try{$st=$pdo->prepare("SELECT status,COUNT(*) total,COALESCE(SUM(amount_cents),0) amount FROM guest_orders WHERE partner_id=?{$hotspotClause} AND created_at>=? AND created_at<? GROUP BY status ORDER BY total DESC,status");$st->execute($reportParams);$reportSales=$st->fetchAll()?:[];}catch(Throwable $e){}
 try{$st=$pdo->prepare("SELECT plan_name,COUNT(*) total FROM guest_orders WHERE partner_id=?{$hotspotClause} AND created_at>=? AND created_at<? GROUP BY plan_name ORDER BY total DESC,plan_name LIMIT 10");$st->execute($reportParams);$reportPlans=$st->fetchAll()?:[];}catch(Throwable $e){}
 $adHotspotClause=$reportHotspotId?' AND e.hotspot_id=?':'';
 try{$st=$pdo->prepare("SELECT a.title,SUM(e.event='impression') impressions,SUM(e.event='view_complete') completed,SUM(e.event='interest_yes') interactions,SUM(e.event='destination_open') destination_opens FROM custom_ads_events e JOIN custom_ads a ON a.id=e.ad_id WHERE e.partner_id=?{$adHotspotClause} AND e.created_at>=? AND e.created_at<? GROUP BY a.id,a.title ORDER BY impressions DESC");$st->execute($reportParams);$reportAds=$st->fetchAll()?:[];}catch(Throwable $e){}
 try{$st=$pdo->prepare("SELECT u.name,u.email,u.last_login_at,m.role FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id WHERE m.partner_id=? AND m.active=1 ORDER BY u.last_login_at IS NULL,u.last_login_at DESC,u.name");$st->execute([$partnerId]);$reportMembers=$st->fetchAll()?:[];}catch(Throwable $e){}
 if($page==='reports'){
  try{$st=$pdo->prepare("SELECT status,COUNT(*) total FROM courtesy_grants WHERE partner_id=?{$hotspotClause} AND created_at>=? AND created_at<? GROUP BY status");$st->execute($reportParams);$reportCourtesy=$st->fetchAll()?:[];}catch(Throwable $e){}
  try{$st=$pdo->prepare("SELECT status,COALESCE(failure_code,'') failure_code,COUNT(*) total,COUNT(DISTINCT device_id) devices FROM subscriber_access_grants WHERE partner_id=?{$hotspotClause} AND created_at>=? AND created_at<? GROUP BY status,failure_code ORDER BY status,failure_code");$st->execute($reportParams);$reportSubscriber=$st->fetchAll()?:[];}catch(Throwable $e){}
  try{$st=$pdo->prepare("SELECT h.id,h.name,h.code,
	      (SELECT COUNT(*) FROM guest_orders o WHERE o.hotspot_id=h.id AND o.status='paid' AND o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) paid_sales,
      (SELECT COALESCE(SUM(o.amount_cents),0) FROM guest_orders o WHERE o.hotspot_id=h.id AND o.status='paid' AND o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) revenue,
      (SELECT COUNT(*) FROM courtesy_grants g WHERE g.hotspot_id=h.id AND g.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) courtesy,
	      (SELECT COUNT(*) FROM custom_ads_events e WHERE e.hotspot_id=h.id AND e.event='impression' AND e.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) impressions
	      ,(SELECT COUNT(*) FROM subscriber_access_grants s WHERE s.hotspot_id=h.id AND s.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) subscriber_accesses
	      ,(SELECT COUNT(DISTINCT s.device_id) FROM subscriber_access_grants s WHERE s.hotspot_id=h.id AND s.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) subscriber_devices
	      ,(SELECT COUNT(*) FROM subscriber_access_grants s WHERE s.hotspot_id=h.id AND s.status='failed' AND s.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) subscriber_failures
	    FROM partner_hotspots h WHERE h.partner_id=? ORDER BY h.is_default DESC,h.name,h.id");$st->execute([$partnerId]);$reportByHotspot=$st->fetchAll()?:[];}catch(Throwable $e){}
 }
}
$editPlan=null;if($page==='plans'&&!empty($_GET['edit'])){foreach($plans as $candidate)if((int)$candidate['id']===(int)$_GET['edit'])$editPlan=$candidate;}
$editAd=null;if($page==='ads'&&!empty($_GET['edit']))$editAd=partner_ads_owned($pdo,$partnerId,(int)$_GET['edit']);
function host_money(int $cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
function host_datetime(?string $value):string{
    if(!$value)return '—';
    $timestamp=strtotime($value);
    return $timestamp===false?'—':date('d/m/Y H:i',$timestamp);
}
function host_preview_duration(int $minutes):string{
    if($minutes>0&&$minutes%1440===0){$days=(int)($minutes/1440);return $days.($days===1?' dia':' dias');}
    if($minutes>0&&$minutes%60===0){$hours=(int)($minutes/60);return $hours.($hours===1?' hora':' horas');}
    return $minutes.' minutos';
}
function host_preview_speed(int $kbps):string{
    if($kbps<=0)return 'sem limite específico';
    if($kbps>=1000)return rtrim(rtrim(number_format($kbps/1000,1,',',''),'0'),',').' Mbps';
    return $kbps.' Kbps';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=host_h($context['name'])?> · Painel</title><link rel="stylesheet" href="/portal/assets/css/portal.css"><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="<?=host_h(host_admin_asset_url('host-admin.css'))?>"></head><body class="host-admin" data-page="<?=host_h($page)?>">
<header class="host-top"><div class="host-shell host-top__inner"><div class="host-top__row"><div class="host-brand"><span class="host-brand__mark"><img src="/portal/assets/img/logo-dark.png" alt="Fire Network"></span><div class="host-brand__identity"><strong><?=host_h($context['name'])?></strong><small>Painel do estabelecimento · <?=host_h($purpose)?> · <?=host_h(partner_admin_roles()[$role]??$role)?></small></div></div><div class="host-actions"><?php if(count(partner_admin_memberships_for_user($pdo,(int)$context['user_id']))>1):?><a class="btn" href="<?=host_h(host_admin_url('select'))?>">Trocar estabelecimento</a><?php endif;?><a class="btn danger" href="<?=host_h(host_admin_url('logout'))?>">Sair</a></div></div><?php if($enabled):?><nav class="host-nav" aria-label="Painel"><?php $visibleGroup=null;foreach($nav as [$key,$label,$cap,$module,$feature,$group]):if(($cap&&!partner_admin_role_has($role,$cap))||!fs_portal_config_module_allowed($pdo,$context,$module)||!fs_partner_has_entitlement($pdo,$partnerId,$feature,false))continue;if($visibleGroup!==$group){if($visibleGroup!==null):?></div><?php endif;$visibleGroup=$group;?><div class="host-nav__group"><span class="host-nav__label"><?=host_h($group)?></span><?php }?><a class="<?=$page===$key?'active':''?>" href="?page=<?=host_h($key)?>"><?=host_h($label)?></a><?php endforeach;if($visibleGroup!==null):?></div><?php endif;?></nav><?php endif;?></div></header>
<main class="host-shell">
<?php if(!$enabled):?><section class="card activation"><h1>Painel aguardando ativação</h1><p>A FireSpot ainda precisa classificar a finalidade e liberar a autogestão deste estabelecimento. Seu vínculo foi preservado e continua ativo.</p><p class="subtle">Nenhuma configuração de rede, RADIUS ou credencial global é exibida aqui.</p></section>
<?php else:?>
<?php if($flash):?><div class="notice <?=host_h($flash['type'])?>"><?=host_h($flash['message'])?></div><?php endif;?>
<?php if($inviteLink):?><div class="notice success"><strong>Link de ativação (exibido uma única vez)</strong><br><input class="host-invite-link" value="<?=host_h($inviteLink)?>" readonly data-select-on-click></div><?php endif;?>

<?php if($page==='summary'):?>
<?php require __DIR__ . '/pages/summary.php';?>

<?php elseif($page==='nas'):?>
<?php require __DIR__ . '/pages/nas.php';?>

<?php elseif($page==='hotspots'):?>
<?php require __DIR__ . '/pages/hotspots.php';?>

<?php elseif($page==='portal'):?>
<?php require __DIR__ . '/pages/portal.php';?>

<?php elseif($page==='audit'):?>
<?php require __DIR__ . '/pages/audit.php';?>

<?php elseif($page==='courtesy'):?>
<?php require __DIR__ . '/pages/courtesy.php';?>

<?php elseif($page==='analytics'):?>
<?php require __DIR__ . '/pages/analytics.php';?>

<?php elseif($page==='plans'):?>
<section class="stack"><article class="card"><h1><?=$editPlan?'Editar':'Novo'?> plano</h1><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="plan_save"><input type="hidden" name="return_page" value="plans"><input type="hidden" name="id" value="<?=(int)($editPlan['id']??0)?>"><div class="form-grid"><label>Nome<input name="name" value="<?=host_h($editPlan['name']??'')?>" required></label><label>Descrição<input name="description" value="<?=host_h($editPlan['description']??'')?>"></label><label>Preço (R$)<input name="price" inputmode="decimal" value="<?=isset($editPlan)?number_format((int)$editPlan['price_cents']/100,2,',',''):''?>" required></label><label>Duração (minutos)<input type="number" min="1" name="duration_minutes" value="<?=host_h($editPlan['duration_minutes']??'')?>" required></label><label>Download Kbps<input type="number" min="0" name="download_kbps" value="<?=host_h($editPlan['download_kbps']??0)?>"></label><label>Upload Kbps<input type="number" min="0" name="upload_kbps" value="<?=host_h($editPlan['upload_kbps']??0)?>"></label><label>Ordem<input type="number" min="0" name="sort_order" value="<?=host_h($editPlan['sort_order']??100)?>"></label><label><span>Situação</span><span><input type="checkbox" name="active" value="1" <?=!isset($editPlan)||!empty($editPlan['active'])?'checked':''?>> Ativo</span></label></div><div class="host-actions"><a class="btn" href="?page=plans">Limpar</a><button class="btn primary" type="submit">Salvar plano</button></div></form></article><article class="card"><h2>Planos próprios</h2><div class="table-wrap"><table><thead><tr><th>Plano</th><th>Valor</th><th>Duração</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($plans as $plan):?><tr><td><?=host_h($plan['name'])?></td><td><?=host_h(host_money((int)$plan['price_cents']))?></td><td><?=(int)$plan['duration_minutes']?> min</td><td><span class="pill <?=$plan['active']?'ok':'off'?>"><?=$plan['active']?'Ativo':'Inativo'?></span></td><td><div class="inline-form"><a class="btn" href="?page=plans&edit=<?=(int)$plan['id']?>">Editar</a><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="plan_toggle"><input type="hidden" name="return_page" value="plans"><input type="hidden" name="id" value="<?=(int)$plan['id']?>"><button class="btn" type="submit"><?=$plan['active']?'Desativar':'Ativar'?></button></form></div></td></tr><?php endforeach;?><?php if(!$plans):?><tr><td colspan="5">Nenhum plano próprio cadastrado. Até a criação do primeiro plano, o catálogo global pode ser usado por compatibilidade.</td></tr><?php endif;?></tbody></table></div></article></section>

<?php elseif($page==='billing'):?>
<?php require __DIR__ . '/pages/billing.php';?>

<?php elseif($page==='finance'):?>
<section class="stack host-finance">
  <article class="card host-finance__header">
    <div class="host-finance__heading">
      <div><span class="host-finance__eyebrow">Financeiro</span><h1>Vendas do estabelecimento</h1><p class="subtle">Cobranças de <?=host_h($context['name'])?>. Valores brutos, antes de taxas, estornos e liquidação.</p></div>
      <span class="pill"><?=host_h(date('d/m/Y',strtotime($financeFilters['from'])))?> — <?=host_h(date('d/m/Y',strtotime($financeFilters['to'])))?></span>
    </div>
    <form method="get" class="host-finance__filters">
      <input type="hidden" name="page" value="finance">
      <label>De<input type="date" name="from" value="<?=host_h($financeFilters['from'])?>" required></label>
      <label>Até<input type="date" name="to" value="<?=host_h($financeFilters['to'])?>" required></label>
      <label>Situação<select name="status"><option value="">Todas</option><?php foreach(partner_finance_statuses() as $statusKey=>$statusLabel):?><option value="<?=host_h($statusKey)?>" <?=$financeFilters['status']===$statusKey?'selected':''?>><?=host_h($statusLabel)?></option><?php endforeach;?></select></label>
      <?php if(count($partnerHotspots)>1):?><label>Ponto<select name="hotspot_id"><option value="">Todos os pontos</option><?php foreach($partnerHotspots as $hotspot):?><option value="<?=(int)$hotspot['id']?>" <?=(int)($financeFilters['hotspot_id']??0)===(int)$hotspot['id']?'selected':''?>><?=host_h($hotspot['name'])?></option><?php endforeach;?></select></label><?php endif;?>
      <label>Por página<select name="page_size"><option value="10" <?=$financePageSize===10?'selected':''?>>10 vendas</option><option value="20" <?=$financePageSize===20?'selected':''?>>20 vendas</option><option value="50" <?=$financePageSize===50?'selected':''?>>50 vendas</option></select></label>
      <button class="btn primary" type="submit">Aplicar filtros</button>
    </form>
  </article>

  <?php if($financeError):?><div class="notice error"><?=host_h($financeError)?></div><?php endif;?>

  <div class="grid-kpi host-finance__kpis">
    <article class="card kpi host-finance-kpi--revenue"><span>Faturamento bruto</span><strong><?=host_h(host_money((int)$financeOverview['paid_amount']))?></strong><small>Somente cobranças pagas</small></article>
    <article class="card kpi host-finance-kpi--paid"><span>Vendas pagas</span><strong><?=(int)$financeOverview['paid_count']?></strong><small>No período selecionado</small></article>
    <article class="card kpi host-finance-kpi--ticket"><span>Ticket médio</span><strong><?=host_h(host_money((int)$financeOverview['average_ticket']))?></strong><small>Média das vendas pagas</small></article>
    <article class="card kpi host-finance-kpi--pending"><span>Pendentes</span><strong><?=(int)$financeOverview['pending_count']?></strong><small><?=host_h(host_money((int)$financeOverview['pending_amount']))?> ainda não confirmados</small></article>
    <article class="card kpi host-finance-kpi--failed"><span>Falhas e cancelamentos</span><strong><?=(int)$financeOverview['failed_count']?></strong><small><?=(int)$financeOverview['refunded_count']?> reembolso(s) no período</small></article>
  </div>

  <article class="card host-finance__sales">
    <div class="host-finance__section-heading">
      <div><span class="host-finance__eyebrow">Detalhamento</span><h2>Vendas</h2><p class="subtle"><?=(int)$financeSales['total']?> registro(s) encontrado(s) · mais recentes primeiro.</p></div>
      <span class="pill">Página <?=(int)$financeSales['page']?> de <?=(int)$financeSales['pages']?></span>
    </div>
    <div class="table-wrap">
      <table class="host-finance__table">
        <thead><tr><th>Data</th><th>Venda</th><th>Ponto</th><th>Recebimento</th><th>Valor</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($financeSales['rows'] as $sale):?>
          <tr>
            <td><strong><?=host_h(host_datetime($sale['created_at']))?></strong><?php if(!empty($sale['paid_at'])):?><small>Pago em <?=host_h(host_datetime($sale['paid_at']))?></small><?php endif;?></td>
            <td><strong><?=host_h($sale['plan_name'])?></strong><small><?=(int)$sale['duration_minutes']?> min · <code><?=host_h($sale['external_ref'])?></code></small></td>
            <td><strong><?=host_h($sale['hotspot_name']?:'Origem legada')?></strong><?php if(!empty($sale['hotspot_code'])):?><small><?=host_h($sale['hotspot_code'])?></small><?php endif;?></td>
            <td><strong><?=host_h($sale['receiver_name'])?></strong><small><?=host_h(partner_finance_payment_method_label($sale['payment_method']))?></small></td>
            <td class="host-finance__amount"><?=host_h(host_money((int)$sale['amount_cents']))?></td>
            <td><span class="host-finance-status <?=host_h(partner_finance_status_class((string)$sale['status']))?>"><?=host_h(partner_finance_status_label((string)$sale['status']))?></span></td>
          </tr>
        <?php endforeach;?>
        <?php if(!$financeSales['rows']):?><tr><td colspan="6" class="host-finance__empty">Nenhuma venda encontrada para os filtros selecionados.</td></tr><?php endif;?>
        </tbody>
      </table>
    </div>
    <?php if((int)$financeSales['pages']>1):?>
      <nav class="host-finance__pager" aria-label="Paginação das vendas">
        <a class="btn compact <?=(int)$financeSales['page']<=1?'is-disabled':''?>" href="<?=(int)$financeSales['page']>1?host_h($financePageUrl((int)$financeSales['page']-1)):'#'?>" <?=(int)$financeSales['page']<=1?'aria-disabled="true" tabindex="-1"':''?>>Anterior</a>
        <span>Página <?=(int)$financeSales['page']?> de <?=(int)$financeSales['pages']?></span>
        <a class="btn compact <?=(int)$financeSales['page']>=(int)$financeSales['pages']?'is-disabled':''?>" href="<?=(int)$financeSales['page']<(int)$financeSales['pages']?host_h($financePageUrl((int)$financeSales['page']+1)):'#'?>" <?=(int)$financeSales['page']>=(int)$financeSales['pages']?'aria-disabled="true" tabindex="-1"':''?>>Próxima</a>
      </nav>
    <?php endif;?>
  </article>

  <details class="card host-finance__future">
    <summary><span class="pill">Em preparação</span><strong>Projeção de recebíveis</strong><small>Por que o faturamento bruto ainda não representa saldo disponível?</small></summary>
    <div><p>A projeção será liberada quando o sistema registrar datas de liquidação, taxas do provedor, estornos e eventuais retenções.</p><?php if(!$canViewFinance):?><small>Quando disponível, ficará restrita aos perfis Proprietário e Financeiro.</small><?php endif;?></div>
  </details>
</section>

<?php elseif($page==='monetization'):?>
<section class="stack">
  <article class="card"><h1>Monetização</h1><p class="subtle">Contrato comercial, campanhas remuneradas e repasses deste estabelecimento. Dados de outros estabelecimentos não são acessíveis aqui.</p><?php if(partner_admin_role_has($role,'ads.earnings.view')):?><a class="btn" href="/portal/host/monetization_export.php?csrf=<?=rawurlencode(csrf_token())?>">Exportar últimos 90 dias (CSV)</a><?php endif;?></article>
  <?php if(!empty($canMonInventory)&&$monAdSchemaReady):?><article class="card"><h2>Rede FireSpot · últimos 30 dias</h2><div class="grid-kpi"><div class="kpi"><span>Entregas</span><strong><?=(int)$monAdDeliverySummary['deliveries']?></strong></div><div class="kpi"><span>Acessos patrocinados</span><strong><?=(int)$monAdDeliverySummary['accesses_granted']?></strong></div><div class="kpi"><span>Impressões Google</span><strong><?=(int)$monAdRevenueSummary['impressions']?></strong></div><div class="kpi"><span>Receita Google</span><strong class="kpi__text">FireSpot</strong></div></div><p class="subtle">Os números permitem acompanhar o uso deste estabelecimento. A receita da demanda Google não entra no saldo nem nos repasses do estabelecimento.</p></article><?php endif;?>
  <div class="grid-kpi">
    <?php if(partner_admin_role_has($role,'monetization.summary.view')):?><article class="card kpi"><span>Modelo comercial</span><strong class="kpi__text"><?=host_h($monAgreement['model']??'Não contratado')?></strong></article><?php endif;?>
    <?php if(partner_admin_role_has($role,'ads.earnings.view')):?><article class="card kpi"><span>A receber de publicidade</span><strong><?=host_h(host_money((int)($monBalance['receivable_cents']??0)))?></strong></article><article class="card kpi"><span>Já repassado</span><strong><?=host_h(host_money((int)($monBalance['settled_cents']??0)))?></strong></article><?php endif;?>
    <?php if(partner_admin_role_has($role,'ads.campaigns.view')):?><article class="card kpi"><span>Campanhas comerciais</span><strong><?=count($monCampaigns)?></strong></article><?php endif;?>
    <?php if(partner_admin_role_has($role,'ads.leads.view')):?><article class="card kpi"><span>Interesses recentes</span><strong><?=count($monLeads)?></strong></article><?php endif;?>
  </div>
  <?php if(!empty($canMonInventory)):?><article class="card"><div class="host-actions"><div><span class="pill">Plano Máximo</span><h2>Inventário publicitário</h2><p class="subtle">Defina onde a rede FireSpot pode exibir campanhas. A conta Google e a receita do preenchimento pertencem à FireSpot.</p></div><span class="pill <?=fs_ad_platform_policy_effective_enabled($monAdPlatform,$monAdPolicy)?'ok':'off'?>"><?=fs_ad_platform_policy_effective_enabled($monAdPlatform,$monAdPolicy)?'Operacional':'Sem veiculação'?></span></div><?php if(!$monAdSchemaReady):?><p>A preparação da rede publicitária ainda não foi concluída pela Central.</p><?php else:?><form method="post" class="host-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="ad_partner_policy_save"><input type="hidden" name="return_page" value="monetization"><div class="form-grid"><label>Situação<select name="state"><option value="disabled" <?=$monAdPolicy['state']==='disabled'?'selected':''?>>Desativada</option><option value="enabled" <?=$monAdPolicy['state']==='enabled'?'selected':''?>>Participar da rede FireSpot</option></select></label><label>Privacidade<select name="privacy_mode"><option value="inherit" <?=$monAdPolicy['privacy_mode']==='inherit'?'selected':''?>>Herdar regra da FireSpot</option><option value="non_personalized" <?=$monAdPolicy['privacy_mode']==='non_personalized'?'selected':''?>>Não personalizada</option><option value="consent_based" <?=$monAdPolicy['privacy_mode']==='consent_based'?'selected':''?>>Conforme consentimento</option></select></label><label>Anúncios para liberar acesso<input type="number" min="1" max="2" name="rewarded_ad_count" value="<?=(int)$monAdPolicy['rewarded_ad_count']?>"></label><label>Internet concedida (min)<input type="number" min="1" max="120" name="reward_minutes" value="<?=(int)$monAdPolicy['reward_minutes']?>"></label><label>Usos por dispositivo<input type="number" min="1" max="100" name="max_rewards_per_device" value="<?=(int)$monAdPolicy['max_rewards_per_device']?>"></label><label>Janela de controle (min)<input type="number" min="60" max="43200" name="reward_window_minutes" value="<?=(int)$monAdPolicy['reward_window_minutes']?>"></label><label>Intervalo após uso (min)<input type="number" min="0" max="43200" name="reward_cooldown_minutes" value="<?=(int)$monAdPolicy['reward_cooldown_minutes']?>"></label></div><div class="form-grid"><label><input type="checkbox" name="paid_banner_enabled" value="1" <?=!empty($monAdPolicy['paid_banner_enabled'])?'checked':''?>> Banner discreto em etapas não transacionais da jornada paga</label><label><input type="checkbox" name="rewarded_access_enabled" value="1" <?=!empty($monAdPolicy['rewarded_access_enabled'])?'checked':''?>> Acesso gratuito por anúncio recompensado</label><label><input type="checkbox" name="allow_firespot_direct" value="1" <?=!empty($monAdPolicy['allow_firespot_direct'])?'checked':''?>> Campanhas diretas FireSpot</label><label><input type="checkbox" name="allow_partner_owned" value="1" <?=!empty($monAdPolicy['allow_partner_owned'])?'checked':''?>> Campanhas próprias</label><label><input type="checkbox" name="allow_google_backfill" value="1" <?=!empty($monAdPolicy['allow_google_backfill'])?'checked':''?>> Preenchimento Google</label></div><p class="subtle"><?=host_h(fs_ad_platform_reward_disclosure($monAdPolicy))?> Nenhum clique é exigido.</p><button class="btn primary" type="submit">Salvar política do estabelecimento</button></form><?php endif;?></article>
  <?php if($monAdSchemaReady):?><article class="card"><h2>Exceções por ponto</h2><p class="subtle">Por padrão, todos os pontos herdam a política acima. Uma exceção altera somente o ponto selecionado.</p><?php foreach($partnerHotspots as $hotspot):$pointPolicy=$monAdPointPolicies[(int)$hotspot['id']]??$monAdPolicy;?><details class="host-point-details"><summary><strong><?=host_h($hotspot['name'])?></strong> · <?=$pointPolicy['inherited']?'Herda política geral':'Regra própria'?></summary><form method="post" class="host-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="ad_hotspot_policy_save"><input type="hidden" name="return_page" value="monetization"><input type="hidden" name="hotspot_id" value="<?=(int)$hotspot['id']?>"><div class="form-grid"><label>Aplicação<select name="mode"><option value="inherit" <?=$pointPolicy['inherited']?'selected':''?>>Herdar estabelecimento</option><option value="custom" <?=!$pointPolicy['inherited']?'selected':''?>>Usar regra própria</option></select></label><label>Situação<select name="state"><option value="disabled" <?=$pointPolicy['state']==='disabled'?'selected':''?>>Desativada neste ponto</option><option value="enabled" <?=$pointPolicy['state']==='enabled'?'selected':''?>>Ativada neste ponto</option></select></label><label>Anúncios por acesso<input type="number" min="1" max="2" name="rewarded_ad_count" value="<?=(int)$pointPolicy['rewarded_ad_count']?>"></label><label>Minutos concedidos<input type="number" min="1" max="120" name="reward_minutes" value="<?=(int)$pointPolicy['reward_minutes']?>"></label><label>Usos por dispositivo<input type="number" min="1" max="100" name="max_rewards_per_device" value="<?=(int)$pointPolicy['max_rewards_per_device']?>"></label><label>Janela (min)<input type="number" min="60" max="43200" name="reward_window_minutes" value="<?=(int)$pointPolicy['reward_window_minutes']?>"></label><label>Intervalo (min)<input type="number" min="0" max="43200" name="reward_cooldown_minutes" value="<?=(int)$pointPolicy['reward_cooldown_minutes']?>"></label></div><div class="form-grid"><label><input type="checkbox" name="paid_banner_enabled" value="1" <?=!empty($pointPolicy['paid_banner_enabled'])?'checked':''?>> Banner na jornada paga</label><label><input type="checkbox" name="rewarded_access_enabled" value="1" <?=!empty($pointPolicy['rewarded_access_enabled'])?'checked':''?>> Acesso recompensado</label><label><input type="checkbox" name="allow_firespot_direct" value="1" <?=!empty($pointPolicy['allow_firespot_direct'])?'checked':''?>> Diretas FireSpot</label><label><input type="checkbox" name="allow_partner_owned" value="1" <?=!empty($pointPolicy['allow_partner_owned'])?'checked':''?>> Próprias</label><label><input type="checkbox" name="allow_google_backfill" value="1" <?=!empty($pointPolicy['allow_google_backfill'])?'checked':''?>> Google</label></div><button class="btn" type="submit">Salvar regra deste ponto</button></form></details><?php endforeach;?></article><?php endif;?><?php endif;?>
  <?php if(partner_admin_role_has($role,'monetization.summary.view')):?><article class="card"><h2>Contrato atual</h2><?php if($monAgreement):?><div class="form-grid"><div><strong>Modelo</strong><p><?=host_h($monAgreement['model'])?></p></div><div><strong>Mensalidade</strong><p><?=host_h(host_money((int)$monAgreement['monthly_fee_cents']))?></p></div><div><strong>Participação nas vendas</strong><p><?php if($monAgreement['access_fee_type']==='percentage'):?><?=number_format((int)$monAgreement['access_fee_value']/100,2,',','.')?>%<?php elseif($monAgreement['access_fee_type']==='fixed'):?><?=host_h(host_money((int)$monAgreement['access_fee_value']))?> por venda<?php else:?>Não aplicada<?php endif;?></p></div><div><strong>Publicidade remunerada</strong><p><?=!empty($monAgreement['advertising_enabled'])?'Habilitada':'Não habilitada'?></p></div><div><strong>Vigência</strong><p><?=host_h(host_datetime($monAgreement['starts_at']))?></p></div></div><?php else:?><p>Nenhum contrato de monetização foi ativado pela FireSpot para este estabelecimento.</p><?php endif;?></article>
  <article class="card"><h2>Mercado Pago Marketplace</h2><?php if($monAccount&&$monAccount['status']==='active'):?><p><span class="pill ok">Autorizado</span> Conta Mercado Pago <?=host_h($monAccount['seller_user_id'])?>. Token protegido <?=host_h($monAccount['credential_hint'])?>.</p><small class="subtle">A autorização fica preparada para um contrato com participação nas vendas. O token do recebedor é usado somente no servidor.</small><?php else:?><p>Proprietário ou Financeiro pode pré-autorizar a conta recebedora antes de a FireSpot ativar um contrato com participação. A FireSpot não solicita nem armazena a senha do Mercado Pago.</p><?php if(partner_admin_role_has($role,'wallet.manage')):?><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="marketplace_start"><input type="hidden" name="return_page" value="monetization"><button class="btn primary" type="submit">Autorizar no Mercado Pago</button></form><?php endif;?><?php endif;?></article><?php endif;?>
  <?php if(partner_admin_role_has($role,'ads.campaigns.view')):?><article class="card"><h2>Campanhas remuneradas</h2><div class="table-wrap"><table><thead><tr><th>Campanha</th><th>Período</th><th>Status</th><th>Remuneração</th><th>Orçamento</th></tr></thead><tbody><?php foreach($monCampaigns as $campaign):?><tr><td><strong><?=host_h($campaign['name'])?></strong><br><?=host_h($campaign['advertiser_name']?:$campaign['advertiser_legal_name'])?></td><td><?=host_h(host_datetime($campaign['starts_at']))?><br>até <?=host_h(host_datetime($campaign['ends_at']))?></td><td><span class="pill <?=$campaign['status']==='active'?'ok':'off'?>"><?=host_h($campaign['status'])?></span></td><td>CPM <?=host_h(host_money((int)$campaign['partner_view_cpm_cents']))?><br>Lead <?=host_h(host_money((int)$campaign['partner_lead_cents']))?></td><td><?=host_h(host_money((int)$campaign['spent_cents']))?> consumidos de <?=host_h(host_money((int)$campaign['funded_cents']))?></td></tr><?php endforeach;?><?php if(!$monCampaigns):?><tr><td colspan="5">Este estabelecimento ainda não participa de campanha comercial.</td></tr><?php endif;?></tbody></table></div></article><?php endif;?>
  <?php if(partner_admin_role_has($role,'ads.earnings.view')):?><article class="card"><h2>Créditos por origem</h2><div class="table-wrap"><table><thead><tr><th>Evento</th><th>Status</th><th>Quantidade</th><th>Valor</th></tr></thead><tbody><?php foreach($monLedgerSummary as $item):?><tr><td><?=host_h($item['source_type'])?></td><td><?=host_h($item['status'])?></td><td><?=(int)$item['total']?></td><td><?=host_h(host_money((int)$item['amount_cents']))?></td></tr><?php endforeach;?><?php if(!$monLedgerSummary):?><tr><td colspan="4">Ainda não há créditos financeiros.</td></tr><?php endif;?></tbody></table></div></article>
  <?php if(count($partnerHotspots)>1):?><article class="card"><h2>Contribuição por ponto</h2><p class="subtle">A divisão abaixo é analítica. Saldo, contrato e repasse continuam consolidados no estabelecimento.</p><div class="table-wrap"><table><thead><tr><th>Ponto</th><th>Eventos remunerados</th><th>Valor</th></tr></thead><tbody><?php foreach($monLedgerByHotspot as $item):?><tr><td><strong><?=host_h($item['name'])?></strong><br><small><?=host_h($item['code'])?></small></td><td><?=(int)$item['total']?></td><td><?=host_h(host_money((int)$item['amount_cents']))?></td></tr><?php endforeach;?><?php if(!$monLedgerByHotspot):?><tr><td colspan="3">Ainda não há créditos atribuídos aos pontos.</td></tr><?php endif;?></tbody></table></div></article><?php endif;?>
  <article class="card"><h2>Fechamentos e repasses</h2><div class="table-wrap"><table><thead><tr><th>Período</th><th>Valor</th><th>Status</th><th>Referência</th></tr></thead><tbody><?php foreach($monSettlements as $settlement):?><tr><td><?=host_h($settlement['period_start'])?> — <?=host_h($settlement['period_end'])?></td><td><?=host_h(host_money((int)$settlement['total_cents']))?></td><td><?=host_h($settlement['status'])?></td><td><?=host_h($settlement['provider_reference']?:'—')?></td></tr><?php endforeach;?><?php if(!$monSettlements):?><tr><td colspan="4">Nenhum fechamento realizado.</td></tr><?php endif;?></tbody></table></div></article><?php endif;?>
  <?php if(partner_admin_role_has($role,'ads.leads.view')):?><article class="card"><h2>Interesses nas ofertas</h2><p class="subtle">Dados exibidos somente aos perfis autorizados e com contato mascarado. Use-os apenas para a oferta consentida e não faça listas paralelas.</p><div class="table-wrap"><table><thead><tr><th>Data</th><th>Campanha</th><th>Contato</th><th>Consentimento</th><th>Mensagem</th><th>Situação do lead</th><th>Ação</th></tr></thead><tbody><?php foreach($monLeads as $lead):?><tr><td><?=host_h(host_datetime($lead['created_at']))?></td><td><?=host_h($lead['campaign_name']?:$lead['ad_title'])?></td><td><strong>Protegido</strong><br><?=host_h($lead['phone_masked'])?></td><td><?=host_h(host_datetime($lead['consent_at']))?><br><small><?=host_h($lead['consent_version'])?></small></td><td><?=host_h($lead['message_status'])?></td><td><?=host_h($lead['status'])?></td><td><?php if(!in_array($lead['status'],['revoked','anonymized'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="lead_revoke"><input type="hidden" name="return_page" value="monetization"><input type="hidden" name="id" value="<?=(int)$lead['id']?>"><button class="btn" type="submit">Revogar e apagar</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$monLeads):?><tr><td colspan="7">Nenhum interesse registrado.</td></tr><?php endif;?></tbody></table></div></article><?php endif;?>
</section>

<?php elseif($page==='ads'):?>
<?php $editAdRemoteMedia=$editAd&&preg_match('~^https?://~i',partner_ads_media_url($editAd))?partner_ads_media_url($editAd):'';?>
<section class="stack">
  <article class="card">
    <h1><?=$editAd?'Editar':'Novo'?> anúncio</h1>
    <p class="subtle">A mídia ocupa a tela do visitante. O interesse será guardado e a oferta só abrirá depois da conexão.</p>
    <form method="post" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="ad_save"><input type="hidden" name="return_page" value="ads"><input type="hidden" name="id" value="<?=(int)($editAd['id']??0)?>">
      <div class="form-grid">
        <label>Título<input name="title" maxlength="200" value="<?=host_h($editAd['title']??'')?>" required></label>
        <label>Tipo de mídia<select name="media_type"><option value="image" <?=($editAd['media_type']??'image')==='image'?'selected':''?>>Imagem</option><option value="video" <?=($editAd['media_type']??'image')==='video'?'selected':''?>>Vídeo MP4</option></select></label>
        <label>Arquivo local<input type="file" name="media_file" accept="image/jpeg,image/png,image/webp,video/mp4"><small>Imagem até 5 MB ou MP4 até 30 MB.</small></label>
        <label>ou URL da mídia<input type="url" name="media_url" maxlength="500" value="<?=host_h($editAdRemoteMedia)?>"></label>
        <label>Capa do vídeo (URL)<input type="url" name="poster_url" maxlength="500" value="<?=host_h($editAd['poster_url']??'')?>"></label>
        <label>Enquadramento<select name="fit_mode"><option value="contain" <?=($editAd['fit_mode']??'contain')==='contain'?'selected':''?>>Mostrar inteira</option><option value="cover" <?=($editAd['fit_mode']??'contain')==='cover'?'selected':''?>>Preencher e recortar</option></select></label>
        <label>URL da oferta<input type="url" name="link_url" maxlength="500" value="<?=host_h($editAd['link_url']??'')?>" placeholder="https://"></label>
        <label>Texto do botão de interesse<input name="interest_button_text" maxlength="60" value="<?=host_h($editAd['interest_button_text']??'Tenho interesse')?>" required></label>
        <label>Texto para seguir sem interesse<input name="skip_button_text" maxlength="60" value="<?=host_h($editAd['skip_button_text']??'Pular e conectar')?>" required></label>
        <label><span>Recebimento da oferta</span><span><input type="checkbox" name="lead_capture_enabled" value="1" <?=!empty($editAd['lead_capture_enabled'])?'checked':''?>> Pedir nome e celular</span><small>Opcional para o visitante e nunca bloqueia o Wi-Fi.</small></label>
        <label class="host-span-full">Mensagem enviada ao interessado<textarea name="offer_message" maxlength="500" rows="3" placeholder="Olá! Aqui está a oferta que você solicitou..."><?=host_h($editAd['offer_message']??'')?></textarea></label>
        <label>Tempo obrigatório (segundos)<input type="number" name="duration_sec" min="5" max="180" value="<?=(int)($editAd['duration_sec']??15)?>" required></label>
        <label>Início<input type="date" name="start_date" value="<?=host_h($editAd['start_date']??'')?>"></label><label>Fim<input type="date" name="end_date" value="<?=host_h($editAd['end_date']??'')?>"></label>
        <label><span>Situação</span><span><input type="checkbox" name="active" value="1" <?=!isset($editAd)||!empty($editAd['active'])?'checked':''?>> Ativo</span></label>
      </div>
      <div class="host-actions"><a class="btn" href="?page=ads">Limpar</a><button class="btn primary" type="submit">Salvar anúncio</button></div>
    </form>
  </article>
  <article class="card"><h2>Campanhas próprias</h2><div class="table-wrap"><table><thead><tr><th>Anúncio</th><th>Mídia</th><th>Período</th><th>Impressões</th><th>Interesses</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($ads as $ad):?><tr><td><strong><?=host_h($ad['title'])?></strong></td><td><?=partner_ads_media_type((string)($ad['media_type']??'image'))==='video'?'Vídeo':'Imagem'?> · <?=(int)$ad['duration_sec']?>s</td><td><?=host_h($ad['start_date']?:'sem início')?> — <?=host_h($ad['end_date']?:'sem fim')?></td><td><?=(int)$ad['impressions']?></td><td><?=(int)$ad['interactions']?></td><td><span class="pill <?=$ad['active']?'ok':'off'?>"><?=$ad['active']?'Ativo':'Inativo'?></span></td><td><div class="inline-form"><a class="btn" href="?page=ads&edit=<?=(int)$ad['id']?>">Editar</a><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="ad_toggle"><input type="hidden" name="return_page" value="ads"><input type="hidden" name="id" value="<?=(int)$ad['id']?>"><button class="btn" type="submit"><?=$ad['active']?'Desativar':'Ativar'?></button></form></div></td></tr><?php endforeach;?><?php if(!$ads):?><tr><td colspan="7">Nenhum anúncio próprio cadastrado.</td></tr><?php endif;?></tbody></table></div></article>
</section>

<?php elseif($page==='reports'):?>
<?php if(fs_portal_config_subscriber_enabled($pdo,$portalConfig)):?><section class="card"><h2>Acessos incluídos FIRENETWORK</h2><p class="subtle">Somente totais operacionais; nenhuma identidade de assinante é disponibilizada.</p><div class="form-grid"><?php foreach($reportSubscriber as $row):?><div><span class="pill"><?=host_h($row['status'])?></span><p><strong><?=(int)$row['total']?></strong> acessos · <strong><?=(int)$row['devices']?></strong> aparelhos<?php if($row['failure_code']):?><br><small><?=host_h($row['failure_code'])?></small><?php endif;?></p></div><?php endforeach;?><?php if(!$reportSubscriber):?><p>Sem acessos no período.</p><?php endif;?></div></section><?php endif;?>
<section class="stack"><article class="card"><div class="host-actions"><div><h1>Métricas dos últimos 30 dias</h1><p class="subtle">Visão essencial limitada a <?=host_h($context['name'])?>.</p></div><?php if(count($partnerHotspots)>1):?><form method="get" class="inline-form"><input type="hidden" name="page" value="reports"><label>Ponto<select name="hotspot_id"><option value="">Todos os pontos</option><?php foreach($partnerHotspots as $hotspot):?><option value="<?=(int)$hotspot['id']?>" <?=$reportHotspotId===(int)$hotspot['id']?'selected':''?>><?=host_h($hotspot['name'])?></option><?php endforeach;?></select></label><button class="btn primary" type="submit">Filtrar</button></form><?php endif;?></div></article><div class="form-grid"><?php if(fs_portal_config_has_courtesy($portalConfig)):?><article class="card"><h2>Cortesia</h2><?php foreach($reportCourtesy as $row):?><p><span class="pill"><?=host_h($row['status'])?></span> <strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$reportCourtesy):?><p>Sem concessões no período.</p><?php endif;?></article><?php endif;?><?php if(fs_portal_config_has_sales($portalConfig)):?><article class="card"><h2>Vendas</h2><?php foreach($reportSales as $row):?><p><span class="pill"><?=host_h($row['status'])?></span> <strong><?=(int)$row['total']?></strong><?php if($canViewSales):?> · <?=host_h(host_money((int)$row['amount']))?><?php endif;?></p><?php endforeach;?><?php if(!$reportSales):?><p>Sem vendas no período.</p><?php endif;?></article><article class="card"><h2>Planos mais usados</h2><?php foreach($reportPlans as $row):?><p><?=host_h($row['plan_name'])?> <strong><?=(int)$row['total']?></strong></p><?php endforeach;?><?php if(!$reportPlans):?><p>Sem pedidos no período.</p><?php endif;?></article><?php endif;?><?php if((int)$portalConfig['promotional_ads_enabled']===1):?><article class="card"><h2>Anúncios</h2><?php foreach($reportAds as $row):?><p><?=host_h($row['title'])?><br><strong><?=(int)$row['impressions']?></strong> impressões · <strong><?=(int)$row['completed']?></strong> concluídas · <strong><?=(int)$row['interactions']?></strong> interesses · <strong><?=(int)$row['destination_opens']?></strong> ofertas abertas</p><?php endforeach;?><?php if(!$reportAds):?><p>Sem eventos no período.</p><?php endif;?></article><?php endif;?><article class="card"><h2>Último acesso da equipe</h2><?php foreach($reportMembers as $member):?><p><strong><?=host_h($member['name']?:$member['email'])?></strong><br><?=host_h(partner_admin_roles()[$member['role']]??$member['role'])?> · <?=host_h($member['last_login_at']?:'Nunca acessou')?></p><?php endforeach;?><?php if(!$reportMembers):?><p>Sem membros ativos.</p><?php endif;?></article></div><?php if(count($partnerHotspots)>1&&!$reportHotspotId):?><article class="card"><h2>Comparativo por ponto</h2><div class="table-wrap"><table><thead><tr><th>Ponto</th><th>Vendas pagas</th><th>Faturamento bruto</th><th>Cortesias</th><th>Impressões</th><?php if(fs_portal_config_subscriber_enabled($pdo,$portalConfig)):?><th>Acessos FIRENETWORK</th><th>Aparelhos</th><th>Falhas</th><?php endif;?></tr></thead><tbody><?php foreach($reportByHotspot as $row):?><tr><td><strong><?=host_h($row['name'])?></strong><br><small><?=host_h($row['code'])?></small></td><td><?=(int)$row['paid_sales']?></td><td><?=$canViewSales?host_h(host_money((int)$row['revenue'])):'Restrito'?></td><td><?=(int)$row['courtesy']?></td><td><?=(int)$row['impressions']?></td><?php if(fs_portal_config_subscriber_enabled($pdo,$portalConfig)):?><td><?=(int)$row['subscriber_accesses']?></td><td><?=(int)$row['subscriber_devices']?></td><td><?=(int)$row['subscriber_failures']?></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></article><?php endif;?></section>

<?php elseif($page==='team'):?>
<section class="stack"><article class="card"><h1>Convidar membro</h1><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="team_invite"><input type="hidden" name="return_page" value="team"><label>E-mail<input type="email" name="email" required></label><label>Papel<select name="role"><?php foreach(partner_admin_roles() as $key=>$label):?><option value="<?=host_h($key)?>"><?=host_h($label)?></option><?php endforeach;?></select></label><button class="btn primary" type="submit">Criar convite</button></form></article><article class="card"><h2>Membros</h2><div class="table-wrap"><table><thead><tr><th>Pessoa</th><th>Papel e vínculo</th><th>Último acesso</th><th>Sessões</th></tr></thead><tbody><?php foreach($members as $member):?><tr><td><strong><?=host_h($member['name']?:'Sem nome')?></strong><br><?=host_h($member['email'])?><?php if((int)$member['active_partner_count']>1):?><br><small>Administra outros estabelecimentos</small><?php endif;?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="team_update"><input type="hidden" name="return_page" value="team"><input type="hidden" name="id" value="<?=(int)$member['membership_id']?>"><select name="role" aria-label="Papel de <?=host_h($member['name']?:$member['email'])?>"><?php foreach(partner_admin_roles() as $key=>$label):?><option value="<?=host_h($key)?>" <?=$member['role']===$key?'selected':''?>><?=host_h($label)?></option><?php endforeach;?></select><label><input type="checkbox" name="active" value="1" <?=$member['membership_active']?'checked':''?>> Ativo</label><button class="btn" type="submit">Salvar</button></form></td><td><?=host_h($member['last_login_at']?:'Nunca')?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="team_revoke_sessions"><input type="hidden" name="return_page" value="team"><input type="hidden" name="user_id" value="<?=(int)$member['user_id']?>"><button class="btn" type="submit">Revogar</button></form></td></tr><?php endforeach;?></tbody></table></div></article><article class="card"><h2>Convites pendentes</h2><div class="table-wrap"><table><thead><tr><th>E-mail</th><th>Papel</th><th>Validade</th><th>Ações</th></tr></thead><tbody><?php foreach($invitations as $invitation):?><tr><td><?=host_h($invitation['email'])?></td><td><?=host_h(partner_admin_roles()[$invitation['role']]??$invitation['role'])?></td><td><?=host_h($invitation['expires_at'])?></td><td><div class="inline-form"><?php foreach(['team_invite_reissue'=>'Reemitir','team_invite_revoke'=>'Revogar'] as $action=>$label):?><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="<?=host_h($action)?>"><input type="hidden" name="return_page" value="team"><input type="hidden" name="id" value="<?=(int)$invitation['id']?>"><button class="btn" type="submit"><?=host_h($label)?></button></form><?php endforeach;?></div></td></tr><?php endforeach;?><?php if(!$invitations):?><tr><td colspan="4">Nenhum convite pendente.</td></tr><?php endif;?></tbody></table></div></article></section>
<?php endif;?>
<?php endif;?>
</main><footer class="footer">© <?=date('Y')?> FireSpot · Painel do estabelecimento</footer><script src="<?=host_h(host_admin_asset_url('host-admin.js'))?>" defer></script></body></html>
