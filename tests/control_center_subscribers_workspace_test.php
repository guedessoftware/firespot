<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);require_once $root.'/app/control_center_navigation.php';
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};

$tabs=fs_control_center_tabs()['assinantes.php']??[];
$expect(array_column($tabs,'label')===['Visão geral','Ativação','Planos e perfis','Contas e aparelhos','Atendimento','Observabilidade'],'Assinantes não possui as seis áreas canônicas.');
$expect(!in_array('Integração HubSoft',array_column($tabs,'label'),true),'Credenciais HubSoft continuam dentro das abas de Assinantes.');

$page=(string)file_get_contents($root.'/dashboard/assinantes.php');
$layout=(string)file_get_contents($root.'/dashboard/layout.php');
$expect(str_contains($page,"require_once __DIR__.'/../app/hubsoft_cache.php'")&&str_contains($page,'fs_hubsoft_capability_status'),'Assinantes não carrega explicitamente o status funcional HubSoft.');
$expect(str_contains($page,"['overview','rollout','mappings','accounts','support','observability']"),'Roteamento de Assinantes não reconhece todas as áreas.');
$expect(str_contains($page,"in_array(\$currentSection,['accounts','support'],true)")&&str_contains($page,"\$supportMode&&\$canManage"),'Consulta de contas não está separada das ações de atendimento.');
$expect(str_contains($page,"\$currentSection==='observability'")&&str_contains($page,'Observabilidade agregada'),'Observabilidade não possui seção própria.');
$expect(str_contains($page,'integracoes.php?section=hubsoft')&&str_contains($page,'OAuth, credenciais e testes técnicos'),'Assinantes não aponta a configuração técnica para Integrações.');
$expect(str_contains($layout,'isset($section)')&&str_contains($layout,"'pages/assinantes.css','pages/control-center.css'"),'Shell não reconhece a seção atual ou os componentes do workspace de Assinantes.');
$expect(!preg_match('/access_token.*(?:echo|print)|client_secret.*(?:echo|print)/i',$page),'Assinantes pode renderizar credencial HubSoft.');

echo "OK: {$checks} verificações do workspace de Assinantes.\n";
