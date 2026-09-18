<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = 0;

function hubsoft_security_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$runtimeFiles = [
    'app/config.php',
    'app/hubsoft_api.php',
    'app/hubsoft_cache.php',
    'app/subscriber_hubsoft.php',
    'app/subscriber_accounts.php',
    'conta/index.php',
    'dashboard/configuracoes.php',
    'dashboard/integracoes.php',
    'dashboard/actions/integration.php',
    'migrations/apply_038.php',
    'migrations/apply_039.php',
];
$legacyNames = [
    'HUBSOFT_API_URL',
    'HUBSOFT_CLIENT_ID',
    'HUBSOFT_CLIENT_SECRET',
    'HUBSOFT_USERNAME',
    'HUBSOFT_PASSWORD',
    'HUBSOFT_GRANT_TYPE',
    'HUBSOFT_CONNECT_TIMEOUT_SECONDS',
    'HUBSOFT_TIMEOUT_SECONDS',
    'HUBSOFT_CACHE_RUNS_PER_DAY',
    'HUBSOFT_CACHE_BATCH',
];

foreach ($runtimeFiles as $relativePath) {
    $contents = (string) file_get_contents($root . '/' . $relativePath);
    foreach ($legacyNames as $legacyName) {
        hubsoft_security_expect(
            strpos($contents, $legacyName) === false,
            "{$relativePath} voltou a depender de {$legacyName}."
        );
    }
}

$panel = (string) file_get_contents($root . '/dashboard/integracoes.php');
$integrationAction = (string) file_get_contents($root . '/dashboard/actions/integration.php');
hubsoft_security_expect(
    strpos($integrationAction, "admin_require_capability('system.integrations.manage')") !== false,
    'A gravação da integração deixou de exigir a capacidade administrativa.'
);
hubsoft_security_expect(
    strpos($integrationAction, "csrf_check(\$_POST['csrf']??'')") !== false,
    'A gravação da integração deixou de validar CSRF.'
);
foreach (['client_secret', 'password'] as $secretField) {
    hubsoft_security_expect(
        !preg_match('/<input[^>]+name="' . preg_quote($secretField, '/') . '"[^>]+value=/i', $panel),
        "O campo {$secretField} não pode ser devolvido preenchido ao navegador."
    );
}
$subscriberPanel=(string)file_get_contents($root.'/dashboard/assinantes.php');
hubsoft_security_expect(strpos($subscriberPanel,'hubsoft_credentials_save')===false&&strpos($subscriberPanel,'hubsoft_connection_test')===false,'Assinantes voltou a administrar credenciais técnicas do HubSoft.');
hubsoft_security_expect(strpos($subscriberPanel,'integracoes.php?section=hubsoft')!==false,'Assinantes não encaminha a dependência técnica para Integrações.');

$accessRules = (string) file_get_contents($root . '/.htaccess');
hubsoft_security_expect(
    preg_match('/FilesMatch[^\n]+firespot/i', $accessRules) === 1,
    'A chave local deixou de estar bloqueada pelo servidor web.'
);

$ignored = (string) file_get_contents($root . '/.gitignore');
hubsoft_security_expect(
    strpos($ignored, '.env.firespot-key') !== false,
    'A chave local deixou de estar excluída do controle de versão.'
);

$subscriberAdapter=(string)file_get_contents($root.'/app/subscriber_hubsoft.php');
hubsoft_security_expect(strpos($subscriberAdapter,"/api/v1/integracao/cliente/autenticacao/")!==false,'O login do assinante deixou de usar o endpoint oficial de autenticação HubSoft.');
hubsoft_security_expect(strpos($subscriberAdapter,"if(\$result['status']===401")===false,'O login voltou a reenviar a senha do assinante após HTTP 401.');
$subscriberAccounts=(string)file_get_contents($root.'/app/subscriber_accounts.php');
foreach(['fs_personal_encrypt($password)',"\$_SESSION['password']",'error_log($password)'] as $unsafePattern)hubsoft_security_expect(strpos($subscriberAccounts,$unsafePattern)===false,"A senha do assinante passou a ser persistida ou registrada ({$unsafePattern}).");
$passwordMigration=(string)file_get_contents($root.'/migrations/039_subscriber_hubsoft_password_login.sql');
hubsoft_security_expect(preg_match('/^\s*[A-Za-z_]*(password|senha|secret|payload)[A-Za-z_]*\s+/mi',$passwordMigration)!==1,'A tabela de tentativas passou a aceitar conteúdo secreto.');
$accountPage=(string)file_get_contents($root.'/conta/index.php');
hubsoft_security_expect(strpos($accountPage,'autocomplete="current-password"')!==false&&strpos($accountPage,'type="password"')!==false,'O formulário deixou de tratar a senha como credencial do navegador.');

echo "OK: {$checks} verificações de segurança da configuração HubSoft.\n";
