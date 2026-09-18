<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;
    if(!$condition)throw new RuntimeException($message);
};

$campaigns=(string)file_get_contents($root.'/dashboard/campanhas.php');
$campaignAction=(string)file_get_contents($root.'/dashboard/actions/campaign.php');
$finance=(string)file_get_contents($root.'/dashboard/monetizacao.php');
$navigation=(string)file_get_contents($root.'/app/control_center_navigation.php');
$monetization=(string)file_get_contents($root.'/app/monetization.php');

foreach(['overview','commercial','offers','performance','settings'] as $section)$expect(str_contains($campaigns,"'{$section}'"),"Seção {$section} ausente de Campanhas.");
$expect(str_contains($campaigns,'Anunciantes')&&str_contains($campaigns,'Vincular estabelecimento')&&str_contains($campaigns,'Vincular criativo'),'Operação comercial não foi consolidada em Campanhas.');
$expect(str_contains($campaigns,'Métricas por campanha')&&str_contains($campaigns,'Falhas definitivas disponíveis para reenvio'),'Ofertas ou desempenho não estão em Campanhas.');
$expect(!str_contains($campaigns,'name="budget"')&&!str_contains($campaigns,'name="view_cpm"')&&!str_contains($campaigns,'name="partner_view_cpm"'),'Campanhas voltou a aceitar orçamento ou remuneração.');
$expect(!str_contains($campaigns,'partner_hotspots')&&!str_contains($campaigns,'hotspot_id'),'Campanhas criou uma visão global de pontos.');
$expect(str_contains($campaignAction,"admin_require_capability('monetization.manage')")&&str_contains($campaignAction,'csrf_check'),'Ação de Campanhas não protege POST e CSRF.');
$expect(str_contains($campaignAction,"'budget_cents'=>\$stored['budget_cents']??0")&&!str_contains($campaignAction,"\$_POST['budget']"),'Edição operacional pode alterar o contrato financeiro da campanha.');
$expect(str_contains($campaignAction,"partner_view_cpm_cents'=>0")&&str_contains($campaignAction,'fs_monetization_assign_campaign'),'Novo vínculo operacional não inicia remuneração implícita.');
$expect(str_contains($campaignAction,"settings_set('ad_minutes'")&&str_contains($campaignAction,"settings_set('custom_ads_enabled'")&&str_contains($campaigns,'Publicidade e cortesia patrocinada'),'Regras globais de publicidade não estão em Campanhas.');
foreach(['campaign_terms_save','assignment_terms_save','campaign_charge','settlement_create','ledger_adjust','marketplace_start'] as $action)$expect(str_contains($finance,$action),"Ação financeira {$action} não está em Monetização.");
foreach(['advertiser_save','advertiser_toggle','campaign_save','campaign_status','campaign_assign','creative_link','offer_retry'] as $action)$expect(!str_contains($finance,"action==='{$action}'")&&!str_contains($finance,"value=\"{$action}\""),"Monetização ainda executa a ação de campanha {$action}.");
$expect(str_contains($navigation,'campanhas.php?section=offers')&&str_contains($navigation,'monetizacao.php?section=overview'),'Navegação não separa Ofertas de Repasses.');
$expect(str_contains($navigation,'campanhas.php?section=settings')&&!str_contains($navigation,'configuracoes.php?section=advertising'),'Navegação ainda envia regras de publicidade para Configurações do sistema.');
$expect(str_contains($monetization,"\$status !== 'draft'"),'Rascunho comercial ainda depende de orçamento antes da preparação criativa.');

echo "OK: {$checks} verificações da fronteira entre Campanhas e Financeiro.\n";
