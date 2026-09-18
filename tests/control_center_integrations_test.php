<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/control_center_integrations.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$expect(fs_control_center_integration_code('http 403 / segredo?') === 'HTTP_403_SEGREDO_', 'Código público não foi normalizado.');
$expect(fs_control_center_integration_code('') === 'NOT_RECORDED', 'Fallback do código de teste está incorreto.');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$invalid = fs_control_center_integration_test_status($pdo, '../invalid');
$expect($invalid['last_test_code'] === 'PROVIDER_INVALID' && $invalid['last_test_ok'] === null, 'Provedor inválido não falhou fechado.');
foreach (['mercadopago','messaging','adsense'] as $provider) {
    $state = fs_control_center_integration_test_status($pdo, $provider);
    $expect(array_keys($state) === ['last_test_at','last_test_ok','last_test_code'], 'Contrato do diagnóstico divergiu para ' . $provider . '.');
    $expect((bool)preg_match('/^[A-Z0-9_:-]{1,80}$/', $state['last_test_code']), 'Código de diagnóstico não está sanitizado para ' . $provider . '.');
}

$wallet = fs_control_center_global_wallet($pdo, false);
foreach (['access_token','webhook_secret','access_token_encrypted','webhook_secret_encrypted'] as $secretField) {
    $expect(!array_key_exists($secretField, $wallet), 'Status da carteira expôs ' . $secretField . '.');
}
$walletStatus = fs_control_center_global_wallet_status($pdo);
$expect(array_keys($walletStatus) === ['configured','token_ready','webhook_ready','last_test_at','source'], 'Contrato sanitizado da carteira global divergiu.');

$page = (string)file_get_contents($root . '/dashboard/integracoes.php');
$action = (string)file_get_contents($root . '/dashboard/actions/integration.php');
foreach (['Dependências','Última falha','payments_connection_test','messaging_test','adsense_configuration_test'] as $marker) {
    $expect(str_contains($page . $action, $marker), 'Integrações não expõe o contrato de diagnóstico: ' . $marker . '.');
}
$expect(str_contains($action,"admin_require_capability('system.integrations.manage')")&&str_contains($action,'csrf_check'),'Testes de integração não exigem capacidade e CSRF.');
$expect(str_contains($action,"!empty(\$capability['ok'])")&&!str_contains($action,"!empty(\$capability['oauth_ok'])"),'HubSoft ainda registra OAuth como sucesso quando a consulta funcional falha.');

echo 'OK: ' . $checks . " verificações do diagnóstico de Integrações.\n";
