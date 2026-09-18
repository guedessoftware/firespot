<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/control_center_navigation.php';
require_once $root . '/app/control_center_partners.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$groups = fs_control_center_nav_groups();
$expect(array_column($groups,'label') === ['Principal','Operação','Negócio','Sistema'],'A navegação não segue os quatro domínios canônicos.');
$items = [];
foreach ($groups as $group) foreach ($group['items'] as $item) $items[] = $item;
$labels = array_column($items,'label');
$expect(in_array('Estabelecimentos',$labels,true),'Estabelecimentos não está no menu canônico.');
$expect(in_array('Planos',$labels,true),'Planos não está no domínio Negócio.');
$expect(in_array('Integrações',$labels,true),'Integrações não está no domínio Sistema.');
$expect(!in_array('Pontos',$labels,true) && !in_array('Hotspots',$labels,true),'Foi criado um inventário global de pontos.');
$establishments = array_values(array_filter($items,static fn(array $item): bool => $item['label'] === 'Estabelecimentos'));
$expect(count($establishments) === 1 && $establishments[0]['href'] === 'estabelecimentos.php','O menu não abre a visão global canônica de estabelecimentos.');

$meta = fs_control_center_page_meta();
foreach (['estabelecimentos.php','estabelecimento.php','financeiro.php','campanhas.php','infraestrutura.php','integracoes.php','privacidade.php'] as $route) {
    $expect(isset($meta[$route]),'Metadados ausentes para ' . $route . '.');
    $expect(is_file($root . '/dashboard/' . $route),'Fachada canônica ausente: ' . $route . '.');
}

$sections = fs_control_center_partner_sections();
$expect(array_keys($sections) === ['summary','registration','contract','portal','access-plans','courtesy','points','finance','team','audit'],'Seções do estabelecimento divergiram do contrato.');
$expect($sections['points']['label'] === 'Pontos e NAS','Pontos não estão contextualizados no estabelecimento.');
$expect(fs_control_center_partner_section('team',false) === 'summary','Equipe foi exposta sem capacidade de visualização.');
$expect(fs_control_center_partner_section('points',false) === 'points','Seção operacional válida foi recusada.');
$expect(fs_control_center_partner_section('unknown',true) === 'summary','Seção desconhecida não falhou para o resumo.');
$expect(fs_control_center_partner_url(21,'points',['point_id'=>7]) === 'estabelecimento.php?id=21&section=points&point_id=7','URL contextual do ponto está incorreta.');
$expect(fs_control_center_partner_url(0,'points') === 'estabelecimentos.php','ID inválido não retorna para a lista segura.');
$expect(fs_control_center_legacy_partner_section('network') === 'points','Aba legada de rede não migra para Pontos e NAS.');
$expect(fs_control_center_legacy_partner_section('billing') === 'finance','Aba legada de cobrança não migra para Financeiro.');

$filters = fs_control_center_partner_filters([
    'q'=>str_repeat('a',150),'status'=>'invalid','plan'=>'INVALID PLAN','portal'=>'v3',
    'billing'=>'independent','points'=>'multiple','page'=>'0','per_page'=>'999',
]);
$queryLength = function_exists('mb_strlen') ? mb_strlen($filters['q'],'UTF-8') : strlen($filters['q']);
$expect($queryLength === 100,'Busca não foi limitada.');
$expect($filters['status'] === 'all' && $filters['plan'] === 'all','Filtros inválidos não falharam para valores seguros.');
$expect($filters['portal'] === 'v3' && $filters['billing'] === 'independent' && $filters['points'] === 'multiple','Filtros válidos foram descartados.');
$expect($filters['page'] === 1 && $filters['per_page'] === 100,'Paginação não foi limitada.');
$expect($filters['owners'] === 0,'Responsáveis foram habilitados implicitamente.');
$expect(fs_control_center_partner_filters(['owners'=>'1'])['owners'] === 1,'Visão opcional de responsáveis não foi reconhecida.');
$expect(fs_control_center_partner_list_return('https://example.test/estabelecimentos.php') === 'estabelecimentos.php','Retorno absoluto não falhou fechado.');
$expect(fs_control_center_csv_cell('+SUM(A1:A2)') === "'+SUM(A1:A2)",'Célula CSV executável não foi neutralizada.');

$layout = (string)file_get_contents($root . '/dashboard/layout.php');
$list = (string)file_get_contents($root . '/dashboard/estabelecimentos.php');
$partner = (string)file_get_contents($root . '/dashboard/estabelecimento.php');
$hotspots = (string)file_get_contents($root . '/dashboard/hotspots.php');
$service = (string)file_get_contents($root . '/app/control_center_partners.php');
$partnerAction = (string)file_get_contents($root . '/dashboard/actions/partner.php');
$pointAction = (string)file_get_contents($root . '/dashboard/actions/point.php');
$teamAction = (string)file_get_contents($root . '/dashboard/actions/team.php');
$commerceAction = (string)file_get_contents($root . '/dashboard/actions/commerce.php');
$portalAction = (string)file_get_contents($root . '/dashboard/actions/portal.php');
$receipts = (string)file_get_contents($root . '/dashboard/recebimentos.php');
$reports = (string)file_get_contents($root . '/dashboard/relatorios.php');
$privacy = (string)file_get_contents($root . '/dashboard/privacidade.php');
$hostsCompatibility = (string)file_get_contents($root . '/dashboard/hosts.php');
$accessSupervision = (string)file_get_contents($root . '/dashboard/host_acessos.php');
$salesAuditJs = (string)file_get_contents($root . '/dashboard/assets/js/auditoria-vendas.js');
$expect(str_contains($layout,'fs_control_center_nav_groups()') && str_contains($layout,'fs_control_center_page_meta()'),'Layout não consome o registro canônico.');
$expect(!str_contains($list,'SELECT * FROM partners'),'Lista global voltou a carregar formulários/dados completos.');
$expect(str_contains($service,'LIMIT ') && str_contains($service,'OFFSET '),'Consulta global não possui paginação no servidor.');
$expect(str_contains($service,'COUNT(*) points_total') && str_contains($service,'GROUP BY h.partner_id'),'Resumo de pontos não é agregado por estabelecimento.');
$expect(str_contains($service,'fs_control_center_partner_export_rows') && !str_contains($list,'access_token_encrypted'),'Exportação sanitizada de estabelecimentos está ausente ou expõe credencial.');
$expect(str_contains($list,'Mostrar responsáveis') && str_contains($list,'Supervisionar acessos') && str_contains($list,"['return'=>\$listReturnUrl]"),'Responsáveis opcionais, supervisão ou retorno com filtros estão ausentes.');
$expect(str_contains($partner,"fs_control_center_partner_url(\$partnerId,'points')"),'Página do estabelecimento não mantém Pontos e NAS em contexto.');
$expect(str_contains($partner,"context['section_label']")&&str_contains((string)file_get_contents($root.'/dashboard/components/partner-context-header.php'),'fs-cc-breadcrumb'),'Breadcrumb não informa estabelecimento e seção atual.');
$expect(!preg_match('/ssh2_|ros_exec|RouterOSAPI|hotspot_apply\s*\(/',$list . $partner),'Abrir a Central dispara ou embute operação remota.');
$expect(str_contains($hotspots,"'points'") && str_contains($hotspots,'estabelecimentos.php'),'Rota legada de hotspots não aponta para o contexto canônico.');
$expect(str_contains($partner,'Criar estabelecimento em rascunho')&&str_contains($partner,'Contrato e Plano FireSpot'),'Cadastro e contrato ainda não possuem interface canônica.');
$expect(str_contains($partner,'Prontidão em sete etapas')&&str_contains($partner,'Revisão e candidato')&&!str_contains($partner,'Revisão e ativação'),'Fluxo guiado ainda promete ativação ou não expõe sua prontidão real.');
$expect(str_contains($partner,'Atividade recente')&&str_contains($service,'fs_control_center_partner_onboarding'),'Resumo do estabelecimento não reúne onboarding e atividade recente sanitizada.');
$expect(str_contains($partner,'Equipe')&&str_contains($partner,'Auditoria'),'Equipe e auditoria ainda não possuem interface contextual.');
foreach([$partnerAction,$pointAction,$teamAction] as $actionSource){
    $expect(str_contains($actionSource,"REQUEST_METHOD")&&str_contains($actionSource,'csrf_check'),'Uma ação canônica não exige POST e CSRF.');
    $expect(!preg_match('/ros_exec|RouterOSAPI|hotspot_apply\s*\(/',$actionSource),'Uma ação de configuração local alcança operação remota.');
}
$expect(str_contains($partner,'Portal V3 único')&&str_contains($partner,'Nenhuma ativação automática'),'Portal e aparência ainda dependem da interface legada.');
$expect(str_contains($partner,'payment_window_save')&&str_contains($partner,'Salvar política de pagamento'),'Checkout Pix não está no Financeiro do estabelecimento.');
$expect(str_contains($commerceAction,"payment_window_save")&&str_contains($commerceAction,'partner_central_save_payment_window'),'Ação canônica da janela Pix ausente.');
$expect(str_contains($portalAction,'fs_portal_presentation_save_uploads')&&str_contains($portalAction,'presentation_mark_ready'),'Fluxo visual versionado não está ligado à Central.');
$expect(!str_contains($portalAction,'partner_central_save_portal')&&!str_contains($portalAction,'fs_portal_config_publish'),'Preparação visual pode ativar ou publicar o portal.');
$expect(str_contains($partnerAction,"admin_require_capability('partner.subscriptions.manage')")&&str_contains($teamAction,"admin_require_capability('partner.administrators.manage')"),'Ações contratuais ou de equipe não possuem capacidade específica.');
$expect(!str_contains($receipts,'portal_mode')&&!str_contains($receipts,'partner_payment_plans')&&!str_contains($receipts,'theme_save'),'Recebimentos ainda mistura Portal ou planos de acesso com o financeiro.');
$expect(!str_contains($receipts,'partner_hotspots')&&!str_contains($receipts,'orders_hotspot_id'),'Recebimentos criou uma visão global detalhada de pontos.');
$expect(str_contains($receipts,"AND partner_id IS NULL")&&str_contains($receipts,"'finance'"),'Carteiras próprias não estão protegidas pelo contexto do estabelecimento.');
$expect(!str_contains($reports,'deleted_accounts')&&!str_contains($reports,'custom_ads_events')&&!str_contains($reports,'Desempenho dos Anúncios'),'Relatórios ainda mistura privacidade ou desempenho de campanhas.');
$expect(str_contains($privacy,'deleted_accounts_stats')&&str_contains($privacy,'fetch_deleted_accounts'),'Clientes > Privacidade não assumiu a auditoria de exclusões.');
$expect(str_contains($layout,"'privacidade'")&&str_contains((string)file_get_contents($root.'/app/control_center_navigation.php'),'privacidade.php'),'Privacidade não foi registrada no shell e nas abas de Clientes.');
$expect(str_contains($hostsCompatibility,'fs_control_center_partner_url')&&str_contains($hostsCompatibility,'http_response_code(410)')&&!str_contains($hostsCompatibility,'<form'),'Fachada hosts.php ainda duplica formulários ou aceita POST legado.');
$expect(str_contains($accessSupervision,"'team'")&&str_contains($accessSupervision,'REQUEST_METHOD')&&str_contains($accessSupervision,'http_response_code(405)'),'Supervisão global de administradores não encaminha alterações para a equipe contextual.');
$expect(!str_contains($accessSupervision,'partner_admin_invite(')&&!str_contains($accessSupervision,'partner_admin_update_membership(')&&!str_contains($accessSupervision,'partner_admin_revoke_user_sessions('),'Supervisão global ainda mantém um segundo controlador de equipe.');
$expect(!str_contains($partner,'Abrir edição atual')&&!str_contains($partner,'hosts.php?partner_id='),'Página canônica ainda encaminha para a edição monolítica antiga.');
$expect(!str_contains($salesAuditJs,'tr.innerHTML')&&str_contains($salesAuditJs,'textContent'),'Auditoria financeira ainda interpola resposta da API como HTML executável.');

foreach (['financeiro.php','campanhas.php','infraestrutura.php','integracoes.php'] as $route) {
    $source = (string)file_get_contents($root . '/dashboard/' . $route);
    $expect(!preg_match('/access_token.*(?:echo|print)|webhook_secret.*(?:echo|print)/i',$source),'Fachada ' . $route . ' pode renderizar segredo.');
}

echo "OK: {$checks} verificações da reorganização da Central.\n";
