<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};

$navigation=(string)file_get_contents($root.'/app/control_center_navigation.php');
$system=(string)file_get_contents($root.'/dashboard/configuracoes.php');
$systemAction=(string)file_get_contents($root.'/dashboard/actions/system.php');
$integrations=(string)file_get_contents($root.'/dashboard/integracoes.php');
$integrationAction=(string)file_get_contents($root.'/dashboard/actions/integration.php');
$subscribers=(string)file_get_contents($root.'/dashboard/assinantes.php');
$finance=(string)file_get_contents($root.'/dashboard/financeiro.php');
$financeAction=(string)file_get_contents($root.'/dashboard/actions/finance.php');
$campaigns=(string)file_get_contents($root.'/dashboard/campanhas.php');
$campaignAction=(string)file_get_contents($root.'/dashboard/actions/campaign.php');
$infrastructure=(string)file_get_contents($root.'/dashboard/infraestrutura.php');
$infrastructureDomain=(string)file_get_contents($root.'/app/infrastructure_status.php');

$expect(str_contains($system,'actions/system.php')&&str_contains($systemAction,"admin_require_capability('system.settings.manage')")&&str_contains($systemAction,'csrf_check'),'Configuração geral não possui controlador POST protegido.');
foreach(['payment_pix_','promo_api_','adsense_','ad_minutes','radius_secret','sudo -n'] as $foreignConcern)$expect(!str_contains($system,$foreignConcern),'Configurações gerais ainda mistura o domínio '.$foreignConcern.'.');
$expect(str_contains($integrationAction,"admin_require_capability('system.integrations.manage')")&&str_contains($integrationAction,'csrf_check'),'Integrações não exige capacidade e CSRF.');
foreach(['payments_connection_test','messaging_test','adsense_configuration_test'] as $testAction)$expect(str_contains($integrationAction,$testAction),'Teste explícito ausente de Integrações: '.$testAction.'.');
foreach(['Dependências','Última falha'] as $diagnosticLabel)$expect(str_contains($integrations,$diagnosticLabel),'Cards de Integrações não informam '.$diagnosticLabel.'.');
foreach(['hubsoft_credentials_save','hubsoft_connection_test','messaging_save','adsense_save'] as $action)$expect(str_contains($integrationAction,$action),'Ação técnica ausente de Integrações: '.$action.'.');
$expect(!preg_match('/<input[^>]+name="(?:client_secret|password|promo_api_hash)"[^>]+value=/i',$integrations),'Integrações tenta devolver um segredo persistido ao navegador.');
$expect(!str_contains($subscribers,'hubsoft_credentials_save')&&!str_contains($subscribers,'hubsoft_connection_test')&&str_contains($subscribers,'integracoes.php?section=hubsoft'),'Assinantes ainda administra a integração técnica HubSoft.');
$expect(str_contains($financeAction,"settings_set('payment_pix_enabled'")&&str_contains($financeAction,"admin_require_capability('system.settings.manage')")&&str_contains($finance,'Política global do Pix'),'Política Pix global não foi movida para Financeiro.');
$expect(str_contains($campaignAction,"settings_set('ad_minutes'")&&str_contains($campaignAction,"settings_set('custom_ads_default'")&&str_contains($campaigns,'Publicidade e cortesia patrocinada'),'Política global de anúncios não foi movida para Campanhas.');
$expect(str_contains($navigation,'financeiro.php?section=settings')&&str_contains($navigation,'campanhas.php?section=settings')&&!str_contains($navigation,'configuracoes.php?section=payments')&&!str_contains($navigation,'configuracoes.php?section=advertising'),'Abas canônicas ainda apontam políticas de negócio para Configurações.');
$expect(str_contains($navigation,"infraestrutura.php?section=logs")&&str_contains($navigation,'Logs sanitizados'),'Eventos sanitizados de infraestrutura não possuem aba canônica.');
$expect(str_contains($infrastructure,'Nenhuma ação remota é executada')&&!str_contains($infrastructure,'partner_hotspots')&&!str_contains($infrastructure,'hotspot_id'),'Infraestrutura criou inventário global de pontos ou ação remota implícita.');
$expect(str_contains($infrastructureDomain,"['/usr/bin/systemctl','is-active',\$unit]")&&!preg_match('/systemctl[^\n]*(?:start|stop|restart|reload)/',$infrastructureDomain),'Diagnóstico do FreeRADIUS não é estritamente de leitura.');
foreach(['radius_secret','access_token','webhook_secret','error_detail'] as $unsafeField)$expect(!str_contains($infrastructure,$unsafeField),'Infraestrutura tenta renderizar campo técnico sensível: '.$unsafeField.'.');
$expect(!str_contains($infrastructure,"['payload']")&&!str_contains($infrastructure,'[\"payload\"]'),'Infraestrutura tenta renderizar o payload bruto de uma fila.');
$expect(str_contains($infrastructureDomain,"WHERE r.status IN ('failed','retry')")&&str_contains($infrastructureDomain,'system_integration_audit'),'Eventos sanitizados não possuem fontes operacionais delimitadas.');
$expect(!str_contains($infrastructureDomain,'last_error')&&!str_contains($infrastructureDomain,'metadata'),'Diagnóstico consulta conteúdo bruto de erro ou metadata.');

echo "OK: {$checks} verificações dos workspaces de Sistema.\n";
