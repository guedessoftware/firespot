<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root=dirname(__DIR__);$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};

$activeDashboardPhp=[];
foreach(glob($root.'/dashboard/*.php')?:[] as $file){
    if(basename($file)==='hosts.php')continue;
    $activeDashboardPhp[$file]=(string)file_get_contents($file);
}
$activePartnerPhp=[];
foreach(array_merge(glob($root.'/portal/host/*.php')?:[],glob($root.'/portal/host/pages/*.php')?:[]) as $file)$activePartnerPhp[$file]=(string)file_get_contents($file);

foreach($activeDashboardPhp as $file=>$source){
    $expect(!preg_match('/\sstyle\s*=/i',$source),'Central mantém style inline em '.basename($file).'.');
    $expect(!preg_match('/\son[a-z]+\s*=/i',$source),'Central mantém handler inline em '.basename($file).'.');
    $expect(!preg_match('/<script(?![^>]+\bsrc=|[^>]+type="application\/json")[^>]*>/i',$source),'Central mantém JavaScript executável embutido em '.basename($file).'.');
}

foreach($activePartnerPhp as $file=>$source){
    $expect(!preg_match('/\sstyle\s*=/i',$source),'Painel do estabelecimento mantém style inline em '.basename($file).'.');
    $expect(!preg_match('/\son[a-z]+\s*=/i',$source),'Painel do estabelecimento mantém handler inline em '.basename($file).'.');
}

$entry=$activePartnerPhp[$root.'/portal/host/index.php']??'';
$expect(!preg_match('/<script(?![^>]+\bsrc=|[^>]+type="application\/json")[^>]*>/i',$entry),'Entrada do painel mantém JavaScript executável embutido.');
$expect(str_contains($entry,'host-admin.js')&&str_contains($entry,'host-admin.css'),'Painel não carrega os assets dedicados consolidados.');
$expect(str_contains($entry,'host-nav__group')&&str_contains($entry,"'Geral'")&&str_contains($entry,"'Financeiro'"),'Navegação contextual do painel não está agrupada por domínio.');
$hostCss=(string)file_get_contents($root.'/portal/host/assets/host-admin.css');
$hostJs=(string)file_get_contents($root.'/portal/host/assets/host-admin.js');
$expect(str_contains($hostCss,'.host-top__inner')&&str_contains($hostCss,'flex-wrap: nowrap')&&str_contains($hostCss,'overflow-x: auto')&&str_contains($hostCss,'scrollbar-width: thin'),'Cabeçalho unificado não mantém a navegação compacta e rolável.');
$expect(substr_count($hostCss,'width: min(100%, 1240px)')>=2,'Cabeçalho e conteúdo do estabelecimento não compartilham a mesma largura máxima.');
$expect(str_contains($hostJs,".host-nav a.active")&&str_contains($hostJs,"scrollIntoView({block:'nearest',inline:'center'})"),'Navegação móvel não posiciona a área ativa no viewport.');

$reports=$activeDashboardPhp[$root.'/dashboard/relatorios.php']??'';
$privacy=$activeDashboardPhp[$root.'/dashboard/privacidade.php']??'';
$campaigns=$activeDashboardPhp[$root.'/dashboard/campanhas.php']??'';
$expect(!str_contains($reports,'deleted_accounts')&&!str_contains($reports,'custom_ads_events'),'Relatórios mantém tabelas de outros domínios.');
$expect(str_contains($privacy,'deleted_accounts_stats')&&str_contains($privacy,'Referência anônima'),'Privacidade não contém a auditoria sanitizada de clientes.');
$expect(str_contains($campaigns,'Métricas por campanha'),'Campanhas não contém seu desempenho canônico.');
$expect(!str_contains((string)file_get_contents($root.'/dashboard/assets/js/auditoria-vendas.js'),'tr.innerHTML'),'Auditoria de vendas ainda injeta dados da API via innerHTML.');

$login=$activeDashboardPhp[$root.'/dashboard/login.php']??'';
$expect(str_contains($login,'assets/css/pages/login.css')&&!str_contains($login,'<style'),'Login administrativo não usa sua folha de estilo dedicada.');
$layout=(string)file_get_contents($root.'/dashboard/layout.php');
$expect(substr_count($layout,'name="firespot-csrf"')===1,'Shell administrativo não possui uma única fonte canônica do token CSRF.');
foreach($activeDashboardPhp as $file=>$source){
    if(basename($file)==='layout.php')continue;
    $expect(!str_contains($source,'name="firespot-csrf"'),'Página duplica o meta CSRF que pertence ao shell: '.basename($file).'.');
}
$salesJs=(string)file_get_contents($root.'/dashboard/assets/js/vendas.js');
$usersJs=(string)file_get_contents($root.'/dashboard/assets/js/usuarios.js');
$plansJs=(string)file_get_contents($root.'/dashboard/assets/js/planos.js');
$pollerJs=(string)file_get_contents($root.'/dashboard/assets/js/poller.js');
$expect(!preg_match('/\sstyle\s*=/i',$salesJs.$usersJs.$plansJs.$pollerJs),'Assets da Central ainda geram atributo style inline.');
$expect(str_contains($salesJs,'escapeHtml(r.external_ref')&&str_contains($salesJs,'escapeHtml(r.refund_notes'),'Tabela de vendas não escapa todos os campos textuais da API.');
$expect(str_contains($usersJs,'var safeUsername = escHtml')&&str_contains($usersJs,'dl.replaceChildren()'),'Tabela ou datalist de clientes ainda interpola valores sem proteção.');
$expect(str_contains($plansJs,"\"'\":'&#39;'")&&str_contains($plansJs,'class="plan-description"'),'Catálogo de planos não protege aspas simples ou ainda usa composição visual inline.');
$expect(str_contains($pollerJs,'<progress max="100"'),'Indicadores da visão geral ainda dependem de largura inline.');

$hosts=(string)file_get_contents($root.'/dashboard/hosts.php');
$expect(substr_count($hosts,"header('Location:")>=3&&str_contains($hosts,'http_response_code(410)'),'Compatibilidade de hosts não trata criação, lista, contexto e POST antigo.');
$expect(substr_count($hosts,"require_once")===2&&!str_contains($hosts,'<form'),'Fachada hosts.php voltou a ser uma implementação paralela.');

echo 'OK: '.$checks." verificações da consolidação visual.\n";
