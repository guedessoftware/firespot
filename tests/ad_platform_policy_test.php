<?php

declare(strict_types=1);

require_once __DIR__.'/../app/ad_platform.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;
    if(!$condition)throw new RuntimeException($message);
};

$expect(fs_ad_platform_sources()===['firespot_direct','partner_owned','google_backfill'],'A prioridade comercial da rede mudou.');
$expect(fs_ad_platform_placement_allowed('welcome_banner','paid','welcome'),'A jornada paga perdeu o banner discreto de boas-vindas.');
$expect(fs_ad_platform_placement_allowed('plans_banner','paid','plan_selection'),'A seleção de planos perdeu o posicionamento permitido.');
$expect(!fs_ad_platform_placement_allowed('free_rewarded','paid','access_choice'),'Acesso pago não pode depender de anúncio recompensado.');
foreach(fs_ad_platform_transactional_stages() as $stage){
    foreach(fs_ad_platform_placements() as $placement){
        $expect(!fs_ad_platform_placement_allowed($placement,'paid',$stage),'Publicidade não pode entrar na etapa transacional '.$stage.'.');
    }
}
$expect(fs_ad_platform_placement_allowed('free_rewarded','free','access_choice'),'A jornada gratuita deve aceitar posicionamento recompensado.');
$expect(fs_ad_platform_placement_allowed('free_rewarded','sponsored','access_choice'),'A jornada patrocinada deve aceitar posicionamento recompensado.');
$expect(!fs_ad_platform_placement_allowed('free_rewarded','free','welcome'),'Anúncio recompensado exige escolha explícita.');

$mock=fs_ad_platform_provider_config(['provider'=>'mock']);
$expect($mock['enabled']&&$mock['test_mode'],'Provedor simulado precisa permanecer isolado de produção.');
$off=fs_ad_platform_provider_config([]);
$expect(!$off['enabled']&&!$off['test_mode'],'Publicidade deve nascer desligada.');
$google=fs_ad_platform_provider_config(['provider'=>'google_ad_manager','network_code'=>'12345678','test_mode'=>1]);
$expect($google['enabled']&&$google['test_mode'],'Google em teste deve conservar o bloqueio de produção.');
$expect(fs_ad_platform_ad_unit_path('/12345678/firespot/free_rewarded','12345678')==='/12345678/firespot/free_rewarded','Unidade Google válida foi recusada.');

foreach([
    static fn()=>fs_ad_platform_provider_config(['provider'=>'adsense']),
    static fn()=>fs_ad_platform_provider_config(['provider'=>'google_ad_manager','network_code'=>'pub-x']),
    static fn()=>fs_ad_platform_ad_unit_path('/87654321/firespot/free_rewarded','12345678'),
    static fn()=>fs_ad_platform_reward_policy(['ad_count'=>0,'reward_minutes'=>10]),
    static fn()=>fs_ad_platform_reward_policy(['ad_count'=>1,'reward_minutes'=>121]),
] as $invalid){
    $blocked=false;
    try{$invalid();}catch(InvalidArgumentException $e){$blocked=true;}
    $expect($blocked,'Configuração publicitária inválida não falhou fechada.');
}

$expect(fs_ad_platform_reward_disclosure(['ad_count'=>2,'reward_minutes'=>30])==='Assista a 2 anúncios e receba 30 minutos de internet gratuita.','Divulgação da recompensa ficou ambígua.');
$safe=fs_ad_platform_safe_settings();
$expect(!fs_ad_platform_policy_effective_enabled($safe,fs_ad_platform_partner_policy_defaults(21)),'Fallback sem schema não pode ativar publicidade.');
$expect(fs_ad_platform_policy_effective_enabled(['enabled'=>1,'configuration_status'=>'test_ready'],['state'=>'enabled']),'Política ativa em provedor de teste deveria ser elegível.');
$expect(!fs_ad_platform_policy_effective_enabled(['enabled'=>1,'configuration_status'=>'incomplete'],['state'=>'enabled']),'Configuração incompleta não pode veicular anúncio.');
$expect(!fs_ad_platform_policy_effective_enabled(['enabled'=>1,'configuration_status'=>'production_ready'],['state'=>'disabled']),'Estabelecimento desativado não pode herdar ativação global.');

$runtime=['available'=>true,'sources'=>fs_ad_platform_sources(),'settings'=>['provider'=>'google_ad_manager'],'placement'=>['google_ad_unit_path'=>'/12345678/firespot/free_rewarded']];
$choice=fs_ad_platform_inventory_choice($runtime,[
    ['id'=>10,'partner_id'=>21,'campaign_id'=>null],
    ['id'=>11,'partner_id'=>null,'campaign_id'=>4],
]);
$expect($choice['source']==='firespot_direct'&&(int)$choice['ad']['id']===11,'Campanha FireSpot não recebeu prioridade sobre peça própria.');
$partnerOnly=fs_ad_platform_inventory_choice(array_merge($runtime,['sources'=>['partner_owned','google_backfill']]),[['id'=>10,'partner_id'=>21,'campaign_id'=>null]]);
$expect($partnerOnly['source']==='partner_owned','Peça própria elegível não antecede o preenchimento Google.');
$googleOnly=fs_ad_platform_inventory_choice(array_merge($runtime,['sources'=>['google_backfill']]),[]);
$expect($googleOnly['provider']==='google_ad_manager','Inventário remanescente não chegou ao Google Ad Manager.');
$expect(fs_ad_platform_inventory_choice(array_merge($runtime,['available'=>false]),[])===null,'Política indisponível ainda selecionou inventário.');

$checkout=(string)file_get_contents(__DIR__.'/../portal/vip_checkout.php');
$success=(string)file_get_contents(__DIR__.'/../portal/vip_sucesso.php');
$expect(!str_contains($checkout,'adsense_head_snippet')&&!str_contains($checkout,'adsense_block'),'Checkout não pode carregar publicidade.');
$expect(!str_contains($success,'adsense_head_snippet')&&!str_contains($success,'adsense_block'),'Confirmação paga não pode carregar publicidade.');
$v3Index=(string)file_get_contents(__DIR__.'/../portal-v3/index.php');
$v3Boot=(string)file_get_contents(__DIR__.'/../portal-v3/_boot.php');
$v3Courtesy=(string)file_get_contents(__DIR__.'/../portal-v3/courtesy.php');
$v3Grant=(string)file_get_contents(__DIR__.'/../portal-v3/api/courtesy_grant.php');
$v3Event=(string)file_get_contents(__DIR__.'/../portal-v3/api/ad_platform_event.php');
$legacyAd=(string)file_get_contents(__DIR__.'/../portal/anuncio.php');
$expect(str_contains($v3Index,"'welcome_banner','welcome'")&&str_contains($v3Index,"'plans_banner','plan_selection'"),'Banners permitidos não estão limitados às duas etapas não transacionais.');
foreach(['checkout.php','api/checkout_create.php','api/payment_status.php','api/payment_window.php','sucesso.php','connect.php','payment-connect.php'] as $file){
    $source=(string)file_get_contents(__DIR__.'/../portal-v3/'.$file);
    $expect(!str_contains($source,'v3_ad_platform_banner')&&!str_contains($source,'googletag')&&!str_contains($source,'doubleclick'),'Publicidade entrou em uma etapa transacional: '.$file);
}
$expect(str_contains($v3Courtesy,'rewardedSlotGranted')&&str_contains($v3Courtesy,'ad-platform-start'),'Anúncio recompensado não depende da escolha explícita e do evento de recompensa do provedor.');
$expect(str_contains($v3Boot,'adsense_test_mode')&&str_contains($v3Boot,'if(testMode)googletag.setConfig'),'Banner Google não ativa o modo de teste oficial junto do modo simulado da plataforma.');
$expect(str_contains($v3Courtesy,"adsense_test_mode:'on'")&&str_contains($v3Courtesy,'if(config.test_mode)googletag.setConfig'),'Rewarded Google não ativa o modo de teste oficial junto do modo simulado da plataforma.');
$expect(str_contains($v3Courtesy,'partner_ads_eligible($pdo,$partner)')&&str_contains($v3Courtesy,'fs_ad_platform_inventory_choice($platformRuntime,$eligibleAds)'),'A jornada patrocinada não entrega campanhas diretas elegíveis antes do preenchimento Google.');
$expect(str_contains($v3Grant,'fs_ad_platform_delivery_consume')&&str_contains($v3Event,'fs_ad_platform_delivery_event'),'Evento do navegador ainda ignora a validação server-side antes da cortesia.');
$expect(!str_contains($legacyAd,'adsense_head_snippet')&&!str_contains($legacyAd,'adsense_block('),'Fluxo legado ainda usa AdSense comum como prova de recompensa.');
$importer=(string)file_get_contents(__DIR__.'/../app/cli/ad_platform_revenue_import.php');
$expect(str_contains($importer,"provider_code")&&str_contains($importer,"google_ad_manager")&&str_contains($importer,"--dry-run"),'Importação conciliada da receita Google não está disponível.');
$deployCheck=(string)file_get_contents(__DIR__.'/../app/cli/hotspot_commercial_policy_deploy_check.php');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_ad_platform_finalize_root.sh');
$expect(str_contains($deployCheck,"'pending-latest'")&&str_contains($deployCheck,'$inventoryLast'),'O verificador não identifica com segurança a última migração pendente.');
$expect(str_contains($finalizer,'--pending-latest')&&!str_contains($finalizer,'"${check}" --pending)'),'O finalizador pode confundir a migração 056 aplicada com a 057 pendente.');
$expect(str_contains($finalizer,'systemctl is-active --quiet apache2.service')&&!str_contains($finalizer,'systemctl reload apache2.service'),'A entrega PHP não deve recarregar o Apache nem depender de configuração TLS alheia ao escopo.');
$domain=(string)file_get_contents(__DIR__.'/../app/ad_platform.php');
$migration=(string)file_get_contents(__DIR__.'/../migrations/057_ad_platform_inventory.sql');
$terms=(string)file_get_contents(__DIR__.'/../portal/termos.php');
$central=(string)file_get_contents(__DIR__.'/../dashboard/monetizacao.php');
$partnerPanel=(string)file_get_contents(__DIR__.'/../portal/host/index.php');
$expect(str_contains($domain,'fs_ad_platform_session_hash()')&&str_contains($domain,'fs_ad_platform_device_hash')&&str_contains($domain,'fs_ad_platform_reward_cap_assert'),'A prova recompensada não está vinculada a sessão, dispositivo e limite de frequência.');
$expect(str_contains($domain,'proof_elapsed_seconds')&&str_contains($domain,'A conclusão do anúncio foi informada antes do tempo mínimo'),'Eventos recompensados rápidos não falham fechados.');
$expect(str_contains($domain,'GOOGLE_AD_MANAGER_PRODUCTION_APPROVED')&&str_contains($domain,'GOOGLE_AD_WALLED_GARDEN_VALIDATED')&&str_contains($domain,'GOOGLE_CMP_READY'),'Produção Google não possui os três bloqueios externos obrigatórios.');
$expect(str_contains($migration,"revenue_owner ENUM('firespot')")&&str_contains($central,'não gera saldo ao estabelecimento')&&str_contains($partnerPanel,'receita do preenchimento pertencem à FireSpot'),'A titularidade da receita Google não está fixada e claramente separada.');
$expect(str_contains($terms,'Anúncios não personalizados ou limitados são o padrão')&&str_contains($terms,'nenhum clique é exigido'),'O aviso público não explica privacidade e recompensa sem clique obrigatório.');
$expect(str_contains($central,'Últimas entregas da rede')&&str_contains($central,'Receita estimada Google')&&str_contains($partnerPanel,'Rede FireSpot · últimos 30 dias'),'Entregas, receita Google e desempenho do estabelecimento não possuem relatórios separados.');

echo "Testes da política da rede publicitária concluídos: {$checks} verificações.\n";
