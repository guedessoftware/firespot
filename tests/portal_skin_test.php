<?php

declare(strict_types=1);

require_once __DIR__.'/../app/portal_view_model.php';
require_once __DIR__.'/../portal-v3/_boot.php';

$checks=0;
function portal_skin_expect(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}

$catalog=fs_portal_skin_builtin_catalog();
portal_skin_expect(array_keys($catalog)===['balanced','quick-connect','sponsored-focus','access-catalog','institutional'],'Catálogo visual inicial incorreto.');
foreach($catalog as $code=>$skin){
    portal_skin_expect((int)$skin['version']===1,'Skin sem versão: '.$code);
    portal_skin_expect(in_array('welcome',$skin['manifest']['components'],true),'Skin sem boas-vindas: '.$code);
    portal_skin_expect(in_array('plans',$skin['manifest']['components'],true),'Skin sem catálogo de planos: '.$code);
    $view=fs_portal_skin_view_file($code);
    portal_skin_expect(is_file($view),'View ausente: '.$code);
    $source=(string)file_get_contents($view);
    portal_skin_expect(!preg_match('/\b(?:PDO|mysqli|db\s*\(|RouterOS|checkout\.php|courtesy\.php)\b/i',$source),'Skin contém regra funcional: '.$code);
}
portal_skin_expect(fs_portal_skin_from_legacy_theme('modern')==='balanced','Tema modern não migra para Equilibrado.');
portal_skin_expect(fs_portal_skin_from_legacy_theme('compact_blue')==='quick-connect','Tema compacto não migra para Conexão rápida.');
portal_skin_expect(fs_portal_plain_text('<script>alert(1)</script> Bem-vindo',40)==='alert(1) Bem-vindo','Conteúdo HTML não foi neutralizado.');
portal_skin_expect(fs_portal_vm_route('index.php?hotspot=ABC&step=options')==='index.php?hotspot=ABC&step=options','Rota GET canônica recusada.');
portal_skin_expect(fs_portal_vm_route('checkout.php','POST')==='checkout.php','Rota POST canônica recusada.');
foreach(['https://example.com','../checkout.php','other.php','/portal-v3/index.php'] as $invalid){
    try{fs_portal_vm_route($invalid,'GET');portal_skin_expect(false,'Rota não autorizada aceita: '.$invalid);}catch(InvalidArgumentException $error){portal_skin_expect(true,'Rota inválida bloqueada.');}
}

$theme=portal_theme_defaults(['id'=>7,'name'=>'Estabelecimento teste']);
portal_skin_expect(abs(portal_theme_contrast_ratio('#000000','#ffffff')-21.0)<0.0001,'Cálculo de contraste máximo incorreto.');
$visualTheme=portal_theme_visual($theme);
portal_skin_expect(portal_theme_contrast_ratio($visualTheme['accent_text'],$visualTheme['panel_color'])>=4.5,'Cor secundária textual clara sem contraste WCAG AA.');
$darkVisualTheme=portal_theme_visual(array_merge($theme,['theme_mode'=>'dark']));
portal_skin_expect(portal_theme_contrast_ratio($darkVisualTheme['accent_text'],$darkVisualTheme['panel_color'])>=4.5,'Cor secundária textual escura sem contraste WCAG AA.');
$customVisualTheme=portal_theme_visual(array_merge($theme,['secondary_color'=>'#eeeeee']));
portal_skin_expect(portal_theme_contrast_ratio($customVisualTheme['accent_text'],$customVisualTheme['panel_color'])>=4.5,'Cor secundária personalizada não recebeu variante textual acessível.');
$themeCss=v3_theme_css($visualTheme);
portal_skin_expect(str_contains($themeCss,'--accent-ink:'),'CSS do Portal V3 não expõe a cor textual acessível.');
$common=[
    'stage'=>'options','partner_id'=>7,'theme'=>$theme,'has_courtesy'=>true,'is_sponsored'=>true,'courtesy_minutes'=>20,
    'courtesy_can_start'=>true,'courtesy_url'=>'courtesy.php?hotspot=ABC','has_sales'=>true,'plans_url'=>'index.php?hotspot=ABC&step=plans',
    'subscriber_enabled'=>true,'subscriber_url'=>'subscriber.php?hotspot=ABC','checkout_url'=>'checkout.php','hotspot_query'=>'hotspot=ABC',
    'plans'=>[['id'=>9,'source'=>'partner','name'=>'1 hora','price_cents'=>500,'duration_minutes'=>60,'download_kbps'=>10000,'upload_kbps'=>5000]],
];
$signature=null;
foreach(array_keys($catalog) as $code){
    $vm=fs_portal_view_model($common+['preview'=>true,'preview_viewport'=>'mobile','presentation'=>['skin_code'=>$code,'skin_version'=>1,'identity'=>[],'content'=>[]]]);
    portal_skin_expect($vm['skin']['code']===$code,'ViewModel perdeu a skin: '.$code);
    portal_skin_expect(array_column($vm['options'],'type')===['courtesy','paid','subscriber'],'Skin alterou modalidades: '.$code);
    $current=json_encode([array_column($vm['options'],'url'),$vm['plans'][0]['url'],$vm['plans'][0]['price_cents'],$vm['plans'][0]['duration_minutes']]);
    if($signature===null)$signature=$current;
    portal_skin_expect($signature===$current,'Skin alterou rota, preço ou duração: '.$code);
    ob_start();require fs_portal_skin_view_file($code);$html=(string)ob_get_clean();
    portal_skin_expect(str_contains($html,'data-v3-skin="'.$code.'"'),'Renderer não identificou a skin: '.$code);
    portal_skin_expect(str_contains($html,'Prévia segura')&&str_contains($html,'button')&&str_contains($html,'disabled'),'Prévia permite ação funcional: '.$code);
    portal_skin_expect(!str_contains($html,'name="csrf"'),'Prévia expôs formulário transacional: '.$code);
}

$migration=(string)file_get_contents(__DIR__.'/../migrations/050_portal_v3_presentations.sql');
foreach(['portal_skin_catalog','partner_portal_presentations','partner_portal_migrations','preview_mobile_approved','preview_desktop_approved'] as $needle)portal_skin_expect(str_contains($migration,$needle),'Migração 050 incompleta: '.$needle);
$service=(string)file_get_contents(__DIR__.'/../app/portal_skin.php');
portal_skin_expect(!str_contains($service,'UPDATE partners SET portal_mode'),'Serviço visual pode ativar o Portal V3.');
portal_skin_expect(!str_contains($service,'hotspot_apply'),'Serviço visual pode aplicar configuração de hotspot.');
portal_skin_expect(str_contains($service,'fs_portal_presentation_reopen'),'Candidata não pode ser reaberta para ajustes.');
$action=(string)file_get_contents(__DIR__.'/../dashboard/actions/portal.php');
portal_skin_expect(!str_contains($action,'fs_portal_config_publish'),'Ação canônica publica jornada durante preparação.');
portal_skin_expect(!str_contains($action,'portal_mode'),'Ação canônica altera o modo do portal.');
portal_skin_expect(!str_contains($action,'activation'),'Ação canônica contém ativação indevida.');
$page=(string)file_get_contents(__DIR__.'/../dashboard/estabelecimento.php');
portal_skin_expect(str_contains($page,'Nenhuma ativação automática'),'Aviso de migração manual ausente.');
portal_skin_expect(!str_contains($page,'name="action" value="portal_activate"'),'Tela expõe ativação prematura.');

echo 'OK: '.$checks." verificações do Portal V3 unificado e skins.\n";
