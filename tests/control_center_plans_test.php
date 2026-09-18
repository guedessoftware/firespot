<?php

declare(strict_types=1);

$root=dirname(__DIR__);require_once $root.'/app/control_center_plans.php';
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$filters=fs_control_center_subscription_filters(['q'=>str_repeat('x',150),'status'=>'invalid','plan'=>'BAD PLAN','page'=>-2,'per_page'=>500]);
$length=function_exists('mb_strlen')?mb_strlen($filters['q'],'UTF-8'):strlen($filters['q']);
$expect($length===100,'Busca de assinatura não foi limitada.');
$expect($filters['status']==='all'&&$filters['plan']==='all','Filtros inválidos não usam fallback seguro.');
$expect($filters['page']===1&&$filters['per_page']===100,'Paginação de assinaturas não foi limitada.');
$navigation=(string)file_get_contents($root.'/app/control_center_navigation.php');
foreach(['Planos FireSpot','Assinaturas e cotas','Planos de acesso globais','Planos por estabelecimento'] as $label)$expect(str_contains($navigation,$label),'Aba de Planos ausente: '.$label);
$page=(string)file_get_contents($root.'/dashboard/planos.php');
$expect(str_contains($page,'Planos FireSpot versionados'),'Catálogo de produto FireSpot não está exposto.');
$expect(str_contains($page,'Assinaturas e consumo de cotas'),'Visão global de assinaturas ausente.');
$expect(str_contains($page,'Cota ≥ 80%'),'Assinaturas não identificam cotas próximas do limite.');
$expect(str_contains($page,'Planos de acesso por estabelecimento')&&str_contains($page,"'access-plans'"),'Catálogos próprios não possuem consolidação somente-leitura e retorno canônico.');
$expect(str_contains($page,"fs_control_center_partner_url((int)\$row['id'],'contract')"),'Atribuição não encaminha ao contrato do estabelecimento.');
$expect(str_contains($page,'Estabelecimento → Planos de acesso'),'Plano próprio ainda aponta para Financeiro/Portal.');
$expect(!str_contains($page,'Pontos globais')&&!str_contains($page,'Inventário de pontos'),'Planos criou uma visão global detalhada de pontos.');
$api=(string)file_get_contents($root.'/dashboard/api/planos_data.php');
$expect(str_contains($api,"admin_has_capability('partner.plans.manage')")&&str_contains($api,"jerr('forbidden', 403)"),'Escrita do catálogo global não exige capacidade.');
$expect(!str_contains($api,'DELETE FROM planos'),'Catálogo global ainda exclui histórico fisicamente.');
$expect(!str_contains($api,"'detail'=>\$e->getMessage()"),'API expõe detalhes internos de erro.');
echo 'OK: '.$checks." verificações da Central de Planos.\n";
