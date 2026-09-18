<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/partner_admin.php';
require_once __DIR__ . '/../app/partner_ads.php';
require_once __DIR__ . '/../app/partner_finance.php';
require_once __DIR__ . '/../app/admin_auth.php';
require_once __DIR__ . '/../portal-v3/_boot.php';

$tests = 0;
function partner_admin_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) throw new RuntimeException($message);
}

$expectedCapabilities = [
    'owner' => ['team.manage','theme.manage','plans.manage','hotspot_commercial.manage','wallet.manage','ads.manage','reports.view','reports.export','sales.view','finance.view','monetization.view','monetization.summary.view','ads.campaigns.view','ads.earnings.view','ads.leads.view','infrastructure.view','nas.manage','nas.prepare','nas.retire','hotspots.manage','courtesy.view','courtesy.manage','courtesy.override.manage','audit.view'],
    'manager' => ['theme.manage','plans.manage','hotspot_commercial.manage','wallet.manage','ads.manage','reports.view','sales.view','monetization.view','ads.campaigns.view','infrastructure.view','nas.manage','nas.prepare','nas.retire','hotspots.manage','courtesy.view','courtesy.manage','audit.view'],
    'finance' => ['wallet.manage','reports.view','reports.export','sales.view','finance.view','monetization.view','monetization.summary.view','ads.earnings.view','audit.view'],
    'marketing' => ['theme.manage','ads.manage','reports.view','monetization.view','ads.campaigns.view','ads.leads.view','audit.view'],
    'viewer' => ['reports.view','infrastructure.view','courtesy.view','audit.view'],
];
foreach ($expectedCapabilities as $role => $expected) {
    partner_admin_expect(partner_admin_capabilities($role) === $expected, "Capacidades incorretas para {$role}.");
    foreach ($expected as $capability) partner_admin_expect(partner_admin_role_has($role,$capability), "{$role} perdeu {$capability}.");
}
partner_admin_expect(!partner_admin_role_has('viewer','team.manage'), 'Leitura não pode administrar equipe.');
partner_admin_expect(!partner_admin_role_has('viewer','nas.manage'), 'Leitura não pode administrar NAS.');
partner_admin_expect(partner_admin_role_has('manager','nas.manage'), 'Gerente precisa administrar o NAS próprio quando o plano permitir.');
partner_admin_expect(partner_admin_role_has('manager','wallet.manage'), 'Gerente do Plano Máximo precisa administrar a carteira do estabelecimento.');
partner_admin_expect(partner_admin_role_has('manager','hotspot_commercial.manage'), 'Gerente precisa administrar as modalidades de cada ponto sem receber acesso à carteira.');
partner_admin_expect(!partner_admin_role_has('finance','hotspot_commercial.manage'), 'Financeiro não pode mudar o funcionamento do ponto Hotspot.');
partner_admin_expect(partner_admin_role_has('manager','sales.view'), 'Gerente precisa consultar as vendas da unidade.');
partner_admin_expect(!partner_admin_role_has('manager','finance.view'), 'Gerente não pode consultar a futura projeção de recebíveis.');
partner_admin_expect(!partner_admin_role_has('finance','ads.leads.view'), 'Financeiro não pode consultar dados pessoais de leads.');
partner_admin_expect(!partner_admin_role_has('marketing','ads.earnings.view'), 'Marketing não pode consultar valores de repasse.');
partner_admin_expect(!partner_admin_role_has('marketing','monetization.summary.view'), 'Marketing não pode consultar contrato e Marketplace.');
partner_admin_expect(!partner_admin_role_has('manager','ads.earnings.view'), 'Gerente não pode consultar valores de repasse.');
partner_admin_expect(!partner_admin_role_has('finance','ads.campaigns.view'), 'Financeiro não recebe acesso operacional às campanhas.');

$_SESSION['admin_role'] = 'viewer';
partner_admin_expect(!admin_has_capability('partners.manage'), 'Viewer FireSpot não pode alterar estabelecimentos.');
partner_admin_expect(!admin_has_capability('partner.billing.manage'), 'Viewer FireSpot não pode alterar recebimentos.');
$_SESSION['admin_role'] = 'manager';
partner_admin_expect(admin_has_capability('partners.manage'), 'Manager FireSpot deve manter a operação comum de estabelecimentos.');
partner_admin_expect(admin_has_capability('partner.portal.manage'), 'Manager FireSpot deve poder operar o portal técnico.');
partner_admin_expect(admin_has_capability('partner.plans.manage'), 'Manager FireSpot deve poder operar planos da unidade.');
partner_admin_expect(admin_has_capability('partner.administrators.view'), 'Manager FireSpot deve poder consultar responsáveis sem alterá-los.');
partner_admin_expect(!admin_has_capability('partner.purpose.manage'), 'Manager FireSpot não pode alterar finalidade.');
partner_admin_expect(!admin_has_capability('partner.billing.manage'), 'Manager FireSpot não pode alterar credenciais financeiras.');
partner_admin_expect(!admin_has_capability('partner.subscriptions.manage'), 'Manager FireSpot não pode alterar contratos ou assinaturas.');
partner_admin_expect(!admin_has_capability('partner.network.manage'), 'Manager FireSpot não pode alterar rede e RADIUS.');
partner_admin_expect(!admin_has_capability('partner.rollout.manage'), 'Manager FireSpot não pode promover rollout.');
$_SESSION['admin_role'] = 'admin';
partner_admin_expect(admin_has_capability('partner.billing.manage'), 'Administrador FireSpot precisa operar recebimentos.');

foreach (['paid','hybrid'] as $purpose) {
    $partner = ['access_purpose'=>$purpose,'ads_enabled'=>0];
    partner_admin_expect(partner_purpose_module_allowed($partner,'plans'), "{$purpose} precisa permitir planos.");
    partner_admin_expect(partner_purpose_module_allowed($partner,'billing'), "{$purpose} precisa permitir recebimentos.");
    partner_admin_expect(partner_purpose_module_allowed($partner,'finance'), "{$purpose} precisa permitir o financeiro.");
}
foreach (['free','sponsored'] as $purpose) {
    $partner = ['access_purpose'=>$purpose,'ads_enabled'=>1];
    partner_admin_expect(!partner_purpose_module_allowed($partner,'plans'), "{$purpose} não pode permitir planos.");
    partner_admin_expect(!partner_purpose_module_allowed($partner,'billing'), "{$purpose} não pode permitir recebimentos.");
    partner_admin_expect(!partner_purpose_module_allowed($partner,'finance'), "{$purpose} não pode permitir o financeiro.");
}
partner_admin_expect(partner_purpose_module_allowed(['access_purpose'=>'sponsored','ads_enabled'=>1],'ads'), 'Anúncios habilitados devem liberar o módulo.');
partner_admin_expect(partner_purpose_module_allowed(['access_purpose'=>'free','ads_enabled'=>0],'monetization'), 'Contrato e repasses devem ser consultáveis independentemente da finalidade do portal.');
partner_admin_expect(!partner_purpose_module_allowed(['access_purpose'=>'sponsored','ads_enabled'=>0],'ads'), 'Flag de anúncios desligada deve bloquear o módulo.');

partner_admin_expect(partner_ads_http_url('https://example.com/campaign') === 'https://example.com/campaign', 'HTTPS válido foi recusado.');
partner_admin_expect(partner_ads_http_url('http://example.com/campaign') === 'http://example.com/campaign', 'HTTP válido foi recusado.');
partner_admin_expect(partner_ads_button_text("  Quero\nesta vaga  ",'Tenho interesse')==='Quero esta vaga','Texto do botão não foi normalizado como texto simples.');
try { partner_ads_button_text(str_repeat('x',61),'Tenho interesse'); $longButtonAccepted=true; } catch(InvalidArgumentException $e) { $longButtonAccepted=false; }
partner_admin_expect(!$longButtonAccepted,'Texto excessivo foi aceito para botão de campanha.');
foreach (['javascript:alert(1)','data:text/html,test','file:///etc/passwd'] as $url) {
    try { partner_ads_http_url($url); $blocked = false; } catch (InvalidArgumentException $e) { $blocked = true; }
    partner_admin_expect($blocked, "Esquema perigoso aceito: {$url}");
}
$hotspotPartner=['gateway_ip'=>'10.50.0.1','dns_name'=>'wifi.example.test'];
partner_admin_expect(v3_hotspot_login_url($hotspotPartner,'http://10.50.0.1/login')==='http://10.50.0.1/login','Endpoint do gateway configurado foi recusado.');
partner_admin_expect(v3_hotspot_login_url($hotspotPartner,'https://wifi.example.test/login')==='https://wifi.example.test/login','Endpoint DNS configurado foi recusado.');
partner_admin_expect(v3_hotspot_login_url($hotspotPartner,'https://evil.example/login')===null,'Credencial poderia ser enviada para host externo.');
partner_admin_expect(v3_hotspot_login_url($hotspotPartner,'https://wifi.example.test/collect')===null,'Credencial poderia ser enviada para caminho arbitrário.');
$privateDnsPartner=['gateway_ip'=>'10.115.0.1','dns_name'=>'00000001-teste.hotspot.internal'];
partner_admin_expect(v3_hotspot_login_url($privateDnsPartner,'http://00000001-teste.hotspot.internal/login?dst=https%3A%2F%2Fwww.google.com')==='http://10.115.0.1/login?dst=https%3A%2F%2Fwww.google.com','Nome DNS privado do Hotspot não foi substituído pelo gateway alcançável.');
partner_admin_expect(v3_hotspot_login_url($privateDnsPartner,'http://10.115.0.1/login')==='http://10.115.0.1/login','Endpoint direto do gateway privado foi alterado indevidamente.');
partner_admin_expect(v3_hotspot_login_url(['gateway_ip'=>'','dns_name'=>'00000001-teste.hotspot.internal'],'http://00000001-teste.hotspot.internal/login')===null,'Nome DNS privado foi devolvido ao navegador sem um gateway válido.');
partner_admin_expect(v3_hotspot_login_url($privateDnsPartner,'http://00000001-teste.hotspot.internal/redirect/login')===null,'Caminho que apenas termina em login foi aceito como endpoint do Hotspot.');
$chapChallengeHex='000102030405060708090a0b0c0d0e0f';
$chapChallengeOctal='\\000\\001\\002\\003\\004\\005\\006\\007\\010\\011\\012\\013\\014\\015\\016\\017';
partner_admin_expect(fs_hotspot_login_password('secret','01',$chapChallengeHex)==='740e86463bda3a4d7017d6e0fba0699d','Resposta HTTP-CHAP hexadecimal foi calculada incorretamente.');
partner_admin_expect(fs_hotspot_login_password('secret','\\001',$chapChallengeOctal)==='740e86463bda3a4d7017d6e0fba0699d','Bytes octais do HTTP-CHAP não foram interpretados corretamente.');
partner_admin_expect(fs_hotspot_login_password('secret',"\x01",hex2bin($chapChallengeHex))==='740e86463bda3a4d7017d6e0fba0699d','Bytes puros do HTTP-CHAP não foram preservados.');
partner_admin_expect(fs_hotspot_login_password('secret','','')==='secret','Fallback PAP alterou a senha original.');
partner_admin_expect(fs_hotspot_login_password('secret','01','')===null,'Contexto CHAP parcial enviaria senha em texto puro.');
$_SERVER['HTTP_HOST']='attacker.example.test';
partner_admin_expect(strpos(v3_public_base_url(),'attacker.example.test')===false,'Origem pública confiou no cabeçalho Host.');
partner_admin_expect(fs_normalize_public_base_url('https://firecdn.com.br/')==='https://firecdn.com.br','Origem pública válida não foi normalizada.');
foreach(['javascript:alert(1)','https://user:pass@firecdn.com.br','https://firecdn.com.br?next=evil'] as $baseUrl) {
    try { fs_normalize_public_base_url($baseUrl); $blocked=false; } catch(InvalidArgumentException $e) { $blocked=true; }
    partner_admin_expect($blocked,"Origem pública insegura foi aceita: {$baseUrl}");
}

$root = dirname(__DIR__);
$requestPhp = [];
foreach (['app','dashboard','portal','portal-v3','simulador'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $requestPhp[] = $file->getPathname();
}
foreach ($requestPhp as $file) {
    $source = (string)file_get_contents($file);
    $relativePath=substr($file,strlen($root)+1);
    if(str_starts_with($relativePath,'app/cli/')&&strpos($source,"PHP_SAPI!=='cli'")!==false)continue;
    partner_admin_expect(!preg_match('/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i',$source), 'DDL encontrado em caminho de requisição: ' . substr($file,strlen($root)+1));
}

foreach (['host_user_save.php','host_user_delete.php','host_users_list.php'] as $endpoint) {
    $source = (string)file_get_contents($root . '/dashboard/api/' . $endpoint);
    partner_admin_expect(strpos($source,'endpoint_retired') !== false, "Endpoint legado ainda ativo: {$endpoint}");
}

$hostSources = '';
foreach (glob($root . '/portal/host/*.php') ?: [] as $file) $hostSources .= (string)file_get_contents($file);
partner_admin_expect(strpos($hostSources,"\$_POST['partner_code']") === false, 'Painel ainda aceita partner_code como fronteira de autorização.');
partner_admin_expect(strpos($hostSources,"'finance' => ['sales.view','finance','finance.view']") !== false, 'A área financeira não exige papel e entitlement no servidor.');
partner_admin_expect(strpos($hostSources,'fs_partner_has_entitlement') !== false&&strpos($hostSources,'partner_admin_require_feature_context') !== false, 'O portal não aplica entitlements no servidor.');
$partnerFinanceSource=(string)file_get_contents($root.'/app/partner_finance.php');
partner_admin_expect(strpos($partnerFinanceSource,'o.partner_id=:partner_id') !== false, 'Consultas financeiras não possuem isolamento obrigatório por estabelecimento.');
partner_admin_expect(strpos($partnerFinanceSource,'device_mac') === false&&strpos($partnerFinanceSource,'device_ip') === false&&strpos($partnerFinanceSource,'radius_username') === false, 'Financeiro tenta expor identificadores técnicos do visitante.');
partner_admin_expect(strpos((string)file_get_contents($root.'/portal/host/forgot.php'),'token=') === false, 'Recuperação pública contém exposição de token.');
partner_admin_expect(strpos((string)file_get_contents($root.'/portal/host/delete.php'),'partner_ads_toggle') === false, 'Rota legada de exclusão pode reativar anúncio.');
$legacyAdDelete=(string)file_get_contents($root.'/portal/host/delete.php');
$legacyAdToggle=(string)file_get_contents($root.'/portal/host/toggle_active.php');
$monetizationExport=(string)file_get_contents($root.'/portal/host/monetization_export.php');
partner_admin_expect(strpos($legacyAdDelete,"partner_admin_require_feature_context(\$pdo, 'ads.manage', 'ads', 'ads.manage', true)")!==false&&strpos($legacyAdToggle,"partner_admin_require_feature_context(\$pdo,'ads.manage','ads','ads.manage',true)")!==false,'Rotas legadas de anúncio contornam o entitlement contratado.');
partner_admin_expect(strpos($monetizationExport,"partner_admin_require_feature_context(\$pdo,'ads.earnings.view','monetization','monetization.view',false)")!==false,'Exportação direta de monetização contorna o entitlement contratado.');
$panelActions=(string)file_get_contents($root.'/portal/host/panel_actions.php');
partner_admin_expect(strpos($panelActions,"partner_admin_require_context(\$pdo")===false&&strpos($panelActions,"partner_admin_require_feature_context(\$pdo,'ads.leads.view','monetization','ads.manage',true)")!==false,'Controlador mantém ação direta sem entitlement do plano, inclusive revogação administrativa de lead.');
partner_admin_expect(strpos($panelActions,"partner_admin_require_feature_context(\$pdo,'wallet.manage','billing','wallet.manage',false)")!==false&&strpos($panelActions,'wallet.connection_tested')!==false,'Teste sem preenchimento da carteira contorna papel, plano ou auditoria.');
$hostBoot=(string)file_get_contents($root.'/portal/host/_boot.php');
partner_admin_expect(strpos($hostBoot,'function host_require_login')===false,'Helper legado permite criar novas rotas autenticadas sem exigir entitlement do plano.');
$marketplaceCallback=(string)file_get_contents($root.'/admin/mercadopago.php');
partner_admin_expect(strpos($panelActions,"partner_admin_require_feature_context(\$pdo,'wallet.manage','monetization','monetization.view',true)")!==false,'OAuth do Marketplace contorna o adicional de monetização contratado.');
partner_admin_expect(strpos($marketplaceCallback,"partner_admin_require_feature_context(\$pdo,'wallet.manage','monetization','monetization.view',true)")!==false&&strpos($marketplaceCallback,"fs_marketplace_oauth_finish(\$pdo,\$state,\$code,'partner_admin',(int)\$authorizedContext['user_id'])")!==false,'Callback OAuth conclui autorização depois de o entitlement, papel, adicional ou painel terem sido revogados.');
partner_admin_expect(strpos($marketplaceCallback,"admin_require_capability('monetization.manage')")!==false,'Callback OAuth da Central conclui autorização depois de a capacidade administrativa ser revogada.');
$partnerAdminSource=(string)file_get_contents($root.'/app/partner_admin.php');
partner_admin_expect(strpos($partnerAdminSource,"fs_partner_require_entitlement(\$pdo,(int)\$invitation['partner_id'],'team.manage',true)")!==false&&strpos($partnerAdminSource,"fs_partner_require_quota(\$pdo,(int)\$invitation['partner_id'],'max_admin_users',0)")!==false,'Convite antigo pode atravessar downgrade, suspensão ou redução da cota ao ser aceito.');
$centralPartnerSource=(string)file_get_contents($root.'/dashboard/estabelecimento.php');
$centralPartnerActions='';
foreach (['partner.php','point.php','courtesy.php','commerce.php','portal.php','team.php'] as $actionFile) $centralPartnerActions.=(string)file_get_contents($root.'/dashboard/actions/'.$actionFile);
partner_admin_expect(strpos($centralPartnerActions,"admin_require_capability('partners.manage')") !== false, 'Central de estabelecimentos não protege mutações cadastrais e de ponto.');
foreach (['partner.network.manage','partner.courtesy.manage','partner.subscriptions.manage','partner.billing.manage','partners.deactivate'] as $capability) {
    partner_admin_expect(strpos($centralPartnerActions,"'{$capability}'") !== false,"Central não separa a capacidade sensível {$capability}.");
}
$receiptsAdminSource=(string)file_get_contents($root.'/dashboard/recebimentos.php');
partner_admin_expect(strpos($receiptsAdminSource,"admin_require_capability('partner.billing.manage')") !== false, 'Recebimentos não exige capacidade sensível.');
partner_admin_expect(strpos($receiptsAdminSource,'partner_central_save_plan') === false && strpos($receiptsAdminSource,'partner_central_save_portal') === false && strpos($receiptsAdminSource,'partner_central_save_theme') === false, 'Recebimentos voltou a gravar planos ou Portal fora do estabelecimento.');
partner_admin_expect(strpos($receiptsAdminSource,'AND partner_id IS NULL') !== false, 'Edição global de carteira pode alcançar credencial de um estabelecimento.');
partner_admin_expect(strpos((string)file_get_contents($root.'/dashboard/anuncios.php'),"admin_has_capability('ads.global.manage')") !== false, 'Campanhas globais não exigem capacidade sensível.');
partner_admin_expect(strpos((string)file_get_contents($root.'/dashboard/api/host_apply.php'),"admin_has_capability('partner.network.manage')") !== false, 'Aplicação no NAS não exige capacidade de rede.');
partner_admin_expect(strpos((string)file_get_contents($root.'/dashboard/api/host_apply.php'),'fs_public_base_url(') !== false, 'Aplicação no NAS ainda deriva a origem pública da requisição.');
$systemConfigSource=(string)file_get_contents($root.'/dashboard/configuracoes.php');
$systemActionSource=(string)file_get_contents($root.'/dashboard/actions/system.php');
partner_admin_expect(strpos($systemConfigSource,'actions/system.php')!==false&&strpos($systemActionSource,"settings_set('public_base_url'")!==false&&strpos($systemActionSource,"admin_require_capability('system.settings.manage')")!==false,'URL pública não possui configuração central protegida.');
$subscriberAdminSource=(string)file_get_contents($root.'/app/subscriber_admin.php');
$subscriberPageSource=(string)file_get_contents($root.'/dashboard/assinantes.php');
partner_admin_expect(strpos($subscriberAdminSource,'fs_subscriber_admin_mapping_delete')!==false&&strpos($subscriberAdminSource,"active=0")!==false&&strpos($subscriberAdminSource,'subscriber_entitlements WHERE plan_mapping_id=?')!==false,'Exclusão de mapeamento não exige inatividade e ausência de vínculos.');
partner_admin_expect(strpos($subscriberAdminSource,'fs_subscriber_admin_mapping_migrate')!==false&&strpos($subscriberAdminSource,"MAPPING_MIGRATION_PENDING")!==false&&strpos($subscriberAdminSource,'plan_mapping.migrated')!==false,'Migração de mapeamento não força revalidação ou não registra auditoria.');
partner_admin_expect(strpos($subscriberPageSource,"admin_require_capability('subscribers.manage')")!==false&&strpos($subscriberPageSource,"\$action==='mapping_delete'")!==false&&strpos($subscriberPageSource,"\$action==='mapping_migrate'")!==false,'Ciclo de vida dos mapeamentos não está protegido pela capacidade de assinantes.');
$kickEndpointSource=(string)file_get_contents($root.'/dashboard/api/kick_session.php');
$kickServiceSource=(string)file_get_contents($root.'/app/session_kick.php');
partner_admin_expect(strpos($kickEndpointSource,"admin_has_capability('partner.network.manage')")!==false&&strpos($kickEndpointSource,'admin_require_csrf')!==false,'Encerramento de sessão não exige capacidade de rede e CSRF.');
partner_admin_expect(strpos($kickEndpointSource,'fs_radius_db()')!==false&&strpos($kickEndpointSource,'fs_session_kick(')!==false&&strpos($kickEndpointSource,'ros_exec(')===false,'Endpoint de encerramento voltou a usar um MikroTik global.');
partner_admin_expect(strpos($kickServiceSource,"\$session['nasipaddress']")!==false&&strpos($kickServiceSource,'fs_nas_base_connection($nas)')!==false,'Encerramento não resolve o NAS a partir do accounting.');
$cookieRemovePosition=strpos($kickServiceSource,"'/ip hotspot cookie remove");
$activeRemovePosition=strpos($kickServiceSource,"'/ip hotspot active remove");
partner_admin_expect($cookieRemovePosition!==false&&$activeRemovePosition!==false&&$cookieRemovePosition<$activeRemovePosition,'Encerramento remove a sessão antes do cookie e permite reconexão automática.');
partner_admin_expect(strpos($kickEndpointSource,"\$result['out']")===false,'Encerramento expõe a saída bruta do RouterOS ao navegador.');
$partnerAdminSource=(string)file_get_contents($root.'/app/partner_admin.php');
partner_admin_expect(strpos($partnerAdminSource,"/admin/convite.php")!==false&&strpos($partnerAdminSource,"/admin/redefinir.php")!==false,'Convites ou redefinições ainda usam a rota administrativa longa.');
foreach(['index.php','painel.php','portal_preview.php','unidade.php','recuperar.php','redefinir.php','convite.php','sair.php'] as $entrypoint) {
    partner_admin_expect(is_file($root.'/admin/'.$entrypoint),'Entrada curta ausente: admin/'.$entrypoint);
}
$hostPanelSource=(string)file_get_contents($root.'/portal/host/index.php');
$hostPortalSource=(string)file_get_contents($root.'/portal/host/pages/portal.php');
$hostPortalIdentitySource=(string)file_get_contents($root.'/portal/host/pages/portal_identity.php');
$hostPanelCss=(string)file_get_contents($root.'/portal/host/assets/host-admin.css');
partner_admin_expect(strpos($hostPanelSource,"host_admin_asset_url('host-admin.css')")!==false&&strpos($hostPanelSource,'class="host-admin"')!==false,'Painel do estabelecimento não carrega sua folha visual dedicada.');
partner_admin_expect(strpos($hostPanelCss,'input[type="color"]')!==false&&strpos($hostPanelCss,'input[type="file"]')!==false,'Folha administrativa não trata cores e uploads.');
partner_admin_expect(strpos($hostPanelCss,'@media (max-width: 480px)')!==false,'Painel administrativo não possui ajuste para celular.');
$simulatorSource=(string)file_get_contents($root.'/simulador/index.php');
$simulatorDomain=(string)file_get_contents($root.'/app/portal_simulator.php');
$simulatorJs=(string)file_get_contents($root.'/simulador/assets/simulator.js');
partner_admin_expect(is_file($root.'/simulador/assets/simulator.css')&&$simulatorJs!=='','Simulador não possui seus ativos isolados.');
partner_admin_expect(strpos($centralPartnerSource,'/simulador/?source=control')!==false,'Central FireSpot não oferece acesso ao simulador por estabelecimento.');
partner_admin_expect(strpos($hostPanelSource.$hostPortalSource,'/simulador/?source=partner')!==false,'Painel do estabelecimento não oferece acesso ao simulador.');
partner_admin_expect(strpos($simulatorSource,"admin_has_capability('partners.view')")!==false&&strpos($simulatorSource,'partner_admin_context($pdo)')!==false,'Simulador não separa as fronteiras de autenticação dos dois painéis.');
partner_admin_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i',$simulatorSource.$simulatorDomain),'Simulador contém mutação de dados.');
partner_admin_expect(!preg_match('/(?:checkout_create|payment_status|courtesy_grant|ad_track|host_apply)\.php/i',$simulatorJs),'Simulador chama endpoint operacional real.');
partner_admin_expect(strpos($simulatorSource,"connect-src 'none'")!==false&&strpos($simulatorSource,'Cache-Control: no-store')!==false,'Simulador não bloqueia integrações externas nem cache sensível.');
partner_admin_expect(strpos($simulatorJs,'portal-ad-screen')!==false&&strpos($simulatorJs,'offer-open')!==false&&strpos($simulatorJs,'continueButton.click()')!==false,'Simulador não representa anúncio em tela inteira, avanço por interesse e oferta pós-conexão.');
$guestAccessSource = (string)file_get_contents($root.'/app/guest_access.php');
partner_admin_expect(strpos($guestAccessSource,"Carteira histórica do pedido") !== false && strpos($guestAccessSource,"fs_wallet_assert_usable(\$wallet)") !== false, 'Pedidos históricos não preservam a carteira usada na criação.');
$adGrantSource = (string)file_get_contents($root.'/portal/api/ad_grant.php');
partner_admin_expect(strpos($adGrantSource,"REQUEST_METHOD") !== false && strpos($adGrantSource,"csrf_check") !== false, 'Concessão por anúncio não exige POST e CSRF.');
partner_admin_expect(strpos($adGrantSource,"(int)(\$proof['partner_id'] ?? 0) === \$partnerId") !== false, 'Prova de anúncio não está vinculada a partner_id.');
$courtesyGrantSource = (string)file_get_contents($root.'/portal-v3/api/courtesy_grant.php');
partner_admin_expect(strpos($courtesyGrantSource,"'connect_url' => 'courtesy_connect.php'") !== false, 'Cortesia V3 retorna destino de conexão incorreto.');
$v3IndexSource=(string)file_get_contents($root.'/portal-v3/index.php');
partner_admin_expect(strpos($v3IndexSource,'entry-card')!==false&&strpos($v3IndexSource,'Conecte-se ao Wi-Fi')!==false&&strpos($v3IndexSource,'step=options')!==false,'Portal V3 não possui boas-vindas universais antes das modalidades.');
partner_admin_expect(strpos($v3IndexSource,"['welcome', 'options', 'plans']")!==false&&strpos($v3IndexSource,'access-choice--courtesy')!==false&&strpos($v3IndexSource,'access-choice--paid')!==false&&strpos($v3IndexSource,'access-choice--subscriber')!==false,'Cortesia, compra premium e benefício não estão isolados na seleção do Portal V3.');
partner_admin_expect(strpos($v3IndexSource,'Escolha seu acesso')!==false&&strpos($v3IndexSource,'access-choice-meta')!==false&&strpos($v3IndexSource,'Cada opção seguirá sua própria validação')===false,'Seleção do Portal V3 voltou a exibir explicações longas.');
partner_admin_expect(strpos($v3IndexSource,'fs_portal_config_single_visible_mode')!==false&&strpos($v3IndexSource,"\$directMode === 'courtesy' && !\$courtesyCanStart")!==false,'Navegação direta não limita o salto a uma modalidade ou pode ignorar o bloqueio da cortesia.');
$v3PortalCss=(string)file_get_contents($root.'/portal-v3/assets/css/portal-v3.css');
partner_admin_expect(strpos($v3PortalCss,'body.portal-entry-page')!==false&&strpos($v3PortalCss,'height:100svh')!==false&&strpos($v3PortalCss,'overflow:hidden')!==false&&strpos($v3PortalCss,'body.portal-stage-options .access-choice-grid')!==false,'Entrada móvel do Portal V3 não está limitada ao viewport sem rolagem.');
partner_admin_expect(strpos($v3IndexSource,'v3-plan-pill-label')!==false&&strpos($v3PortalCss,'.portal-signal')!==false&&strpos($v3PortalCss,'background-size:40px 40px,40px 40px,auto')!==false,'Portal V3 não usa o padrão visual unificado de grade, sinal e planos em cápsula.');
partner_admin_expect(strpos($v3IndexSource,"'&return=1'")!==false&&strpos((string)file_get_contents($root.'/portal-v3/sucesso.php'),'confirmReturn')!==false,'Retorno com crédito não exige uma confirmação dedicada.');
partner_admin_expect(strpos($simulatorJs,'function renderOptions(')!==false&&strpos($simulatorJs,'function renderPlans(')!==false&&strpos($simulatorJs,'portal-entry-card')!==false,'Simulador não reproduz boas-vindas, modalidades e planos em etapas independentes.');
partner_admin_expect(strpos($simulatorJs,'Escolha seu acesso')!==false&&strpos($simulatorJs,'portal-access-meta')!==false&&strpos((string)file_get_contents($root.'/simulador/assets/simulator.css'),'.portal-page--options')!==false,'Simulador não reproduz a entrada móvel compacta do Portal V3.');
partner_admin_expect(strpos($simulatorJs,'portal-plan-pill-label')!==false&&strpos($simulatorJs,'portal-signal')!==false,'Simulador não espelha os componentes visuais compartilhados do Portal V3.');
partner_admin_expect(strpos($simulatorJs,'function renderConfiguredEntry()')!==false&&strpos($simulatorJs,"visibleModes.length === 1")!==false,'Simulador não reproduz as preferências de navegação publicadas.');
partner_admin_expect(strpos($hostPanelSource.$hostPortalIdentitySource,'fs-pv-entry-card')!==false&&strpos((string)file_get_contents($root.'/dashboard/portal_preview.php'),'fs_portal_view_model')!==false,'Prévia da Central não usa o mesmo motor das boas-vindas do Portal V3.');
partner_admin_expect(strpos($hostPanelSource.$hostPortalIdentitySource,'fs-pv-signal')!==false&&strpos((string)file_get_contents($root.'/dashboard/portal_preview.php'),'fs_portal_skin_view_file')!==false,'Prévia da Central não usa o renderer único do Portal V3.');
$legacyAdSource=(string)file_get_contents($root.'/portal/anuncio.php');
$v3CourtesySource=(string)file_get_contents($root.'/portal-v3/courtesy.php');
partner_admin_expect(strpos($centralPartnerSource,'name="auth_mode"')!==false&&strpos($centralPartnerSource,'value="anonymous"')!==false,'Central do estabelecimento não expõe a autenticação por dispositivo da cortesia.');
partner_admin_expect(strpos($v3CourtesySource,"'Acesso patrocinado' : 'Acesso rápido'")!==false,'Intersticial de autenticação não diferencia acesso direto de patrocinado.');
partner_admin_expect(strpos($v3CourtesySource,'fs_courtesy_rollout_resolve')<strpos($v3CourtesySource,'fs_ad_delivery_begin'),'Portal V3 inicia o anúncio antes de validar se pode conceder a cortesia.');
partner_admin_expect(strpos($v3IndexSource,'v3_courtesy_preflight')!==false&&strpos($v3IndexSource,'data-courtesy-retry-at')!==false&&strpos($v3IndexSource,'aria-disabled="true"')!==false,'Entrada do Portal V3 não antecipa o bloqueio e o tempo restante da cortesia.');
partner_admin_expect(strpos($v3CourtesySource,'v3_courtesy_preflight')<strpos($v3CourtesySource,'fs_ad_delivery_begin'),'Página V3 inicia a entrega publicitária antes da pré-validação de elegibilidade.');
partner_admin_expect(strpos($courtesyGrantSource,'fs_courtesy_rollout_resolve')<strpos($courtesyGrantSource,'fs_ad_delivery_complete'),'Endpoint V3 conclui a entrega publicitária antes de validar o rollout da cortesia.');
partner_admin_expect(strpos($courtesyGrantSource,"'retry_at' => \$result['retry_at'] ?? null")!==false,'Endpoint V3 não devolve o próximo horário quando uma concessão é negada após revalidação.');
partner_admin_expect(strpos($courtesyGrantSource,'ativação controlada')===false,'Endpoint V3 ainda expõe o estado técnico do rollout ao visitante.');
partner_admin_expect(strpos($legacyAdSource,'window.open(LINK_URL')===false&&strpos($v3CourtesySource,'window.open(')===false&&strpos($legacyAdSource,'skipBtn?.click()')!==false&&strpos($v3CourtesySource,'button?.click()')!==false,'Interesse abre destino externo ou não avança internamente após o registro.');
partner_admin_expect(strpos($legacyAdSource,'view_complete')!==false&&strpos($v3CourtesySource,'view_complete')!==false,'Portais não distinguem conclusão do tempo obrigatório.');
partner_admin_expect(strpos($legacyAdSource,'disabled>Pular em')!==false&&strpos($v3CourtesySource,'disabled>Pular em')!==false&&strpos($legacyAdSource,"classList.add('is-visible')")!==false&&strpos($v3CourtesySource,"classList.add('is-visible')")!==false,'Ações publicitárias não iniciam ocultas e bloqueadas até o prazo nos dois portais.');
$offerSource=(string)file_get_contents($root.'/portal/oferta.php');
partner_admin_expect(strpos($offerSource,"REQUEST_METHOD'] === 'POST'")!==false&&strpos($offerSource,'csrf_check')!==false,'Abertura da oferta pós-conexão não exige POST e CSRF.');
partner_admin_expect(strpos($offerSource,"'destination_open'")!==false&&strpos($offerSource,"Referrer-Policy: no-referrer")!==false,'Oferta pós-conexão não protege o token ou não registra a abertura real.');
$courtesyConnectSource=(string)file_get_contents($root.'/portal-v3/courtesy_connect.php');
partner_admin_expect(strpos($courtesyConnectSource,'partner_ads_pending_offer_url')!==false&&strpos($courtesyConnectSource,'name="dst"')!==false,'Conexão V3 não retorna à oferta interna após autenticar.');
$legacyConnectSource=(string)file_get_contents($root.'/portal/conect.php');
partner_admin_expect(strpos($legacyConnectSource,'partner_ads_session_offer_url')!==false,'Conexão legada não retorna à oferta interna após autenticar.');
$v3BootSource = (string)file_get_contents($root.'/portal-v3/_boot.php');
$hotspotLoginSource = (string)file_get_contents($root.'/app/hotspot_login.php');
partner_admin_expect(strpos($v3BootSource,'v3_hotspot_login_url') !== false && strpos($v3BootSource,'fs_hotspot_login_url') !== false && strpos($hotspotLoginSource,'$allowedHosts') !== false, 'Portal V3 não limita o destino das credenciais ao hotspot da unidade.');
partner_admin_expect(strpos($hotspotLoginSource,"str_ends_with(\$value, '.hotspot.internal')")!==false&&strpos($hotspotLoginSource,'FILTER_FLAG_IPV4')!==false&&strpos($hotspotLoginSource,"return 'http://' . \$gateway")!==false,'Fallback da zona privada não exige o gateway IPv4 da instalação.');
foreach (['connect.php','courtesy_connect.php','subscriber_login.php'] as $v3ConnectFile) {
    $v3ConnectSource=(string)file_get_contents($root.'/portal-v3/'.$v3ConnectFile);
    partner_admin_expect(strpos($v3ConnectSource,'fs_hotspot_login_password')!==false&&strpos($v3ConnectSource,'name="password"')!==false&&strpos($v3ConnectSource,'name="response"')===false,'Fluxo V3 não envia o HTTP-CHAP pelo campo password: '.$v3ConnectFile);
}
$visitorLoginSource = (string)file_get_contents($root.'/portal/login.php');
partner_admin_expect(strpos($visitorLoginSource,"strncmp(\$n,'//',2)") !== false, 'Retorno do login visitante aceita URL protocol-relative.');
partner_admin_expect(!is_file($root.'/dashboard/assets/js/host_acessos.js'), 'JavaScript do CRUD legado de acessos ainda existe.');

echo 'Testes de segurança do painel concluídos: ' . $tests . " verificações.\n";
