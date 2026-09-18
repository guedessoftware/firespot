<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/control_center_dashboard.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$overview = fs_control_center_management_overview($pdo);
$partnerCount = (int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$activePartnerCount = (int)$pdo->query('SELECT COUNT(*) FROM partners WHERE active=1')->fetchColumn();
$planTotal = array_sum(array_column($overview['plans'],'total'));
$walletAlerts = (int)$pdo->query("SELECT COUNT(*) FROM payment_wallets WHERE active=1 AND (access_token_validated_at IS NULL OR webhook_secret_encrypted IS NULL OR webhook_secret_encrypted='' OR webhook_secret_validated_at IS NULL)")->fetchColumn();

$expect((int)$overview['partners']['total'] === $partnerCount,'Total gerencial não concilia com estabelecimentos.');
$expect((int)$overview['partners']['active'] === $activePartnerCount,'Ativos gerenciais não conciliam com estabelecimentos.');
$expect($planTotal === $activePartnerCount,'Distribuição por plano não concilia com estabelecimentos ativos.');
$expect((int)$overview['quotas_near_limit'] >= 0 && (int)$overview['quotas_near_limit'] <= $activePartnerCount,'Alerta de cotas saiu do domínio de estabelecimentos.');
$expect((int)$overview['wallet_alerts'] === $walletAlerts,'Alertas de carteira/webhook não conciliam com o inventário sanitizado.');
$expect((int)$overview['infrastructure_pending'] >= 0 && (int)$overview['infrastructure_failed'] >= 0,'Resumo de infraestrutura retornou contagem inválida.');

$source = (string)file_get_contents($root . '/app/control_center_dashboard.php');
$page = (string)file_get_contents($root . '/dashboard/index.php');
$list = (string)file_get_contents($root . '/dashboard/estabelecimentos.php');
$expect(!str_contains($source,'SELECT h.*') && !str_contains($page,'point_id'),'Visão geral criou inventário global detalhado de pontos.');
$expect(str_contains($page,'Visão dos estabelecimentos') && str_contains($page,'Assinaturas por Plano FireSpot'),'Visão geral não renderiza o bloco gerencial contratado.');
$expect(str_contains($page,'Cotas próximas do limite') && str_contains($page,'Carteiras/webhooks') && str_contains($page,'Solicitações pendentes'),'Alertas gerenciais estão incompletos.');
$expect(str_contains($list,"format'=>'csv'") && str_contains($list,'Mostrar responsáveis'),'Inventário sanitizado ou responsáveis opcionais estão ausentes.');
$expect(str_contains($list,"['return'=>\$listReturnUrl]") && str_contains((string)file_get_contents($root.'/dashboard/components/partner-context-header.php'),"context['return_url']"),'Filtros da lista não sobrevivem à navegação de retorno.');

$filtered = fs_control_center_partners($pdo,['points'=>'multiple','owners'=>'1','per_page'=>10]);
$export = fs_control_center_partner_export_rows($pdo,['points'=>'multiple'],true);
$expect(count($filtered['rows']) <= 10 && count($export) === (int)$filtered['total'],'Exportação não respeita os mesmos filtros da lista.');
foreach ($export as $row) {
    $expect(array_key_exists('responsible_summary',$row),'Exportação autorizada não trouxe o resumo opcional de responsáveis.');
    $expect(!array_key_exists('password_hash',$row) && !array_key_exists('access_token_encrypted',$row) && !array_key_exists('webhook_secret_encrypted',$row),'Exportação incluiu credencial.');
}
$expect(fs_control_center_csv_cell('=2+2') === "'=2+2" && fs_control_center_csv_cell('@cmd') === "'@cmd",'Exportação CSV não neutraliza fórmulas.');
$expect(fs_control_center_partner_list_return('https://example.test/x') === 'estabelecimentos.php','Retorno externo não falhou fechado.');
$expect(fs_control_center_partner_list_return('estabelecimentos.php?q=Loja&points=multiple&page=2&ignored=x') === 'estabelecimentos.php?q=Loja&points=multiple&page=2','Retorno não preserva somente filtros permitidos.');

echo "OK: {$checks} verificações da visão gerencial.\n";
