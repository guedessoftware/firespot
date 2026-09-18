<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('PAYMENT_CREDENTIAL_KEY=' . base64_encode(str_repeat('P', 32)));

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/partner_purpose.php';

$host = DB_HOST;
$port = '3306';
if (strpos($host, ':') !== false) {
    [$hostOnly, $portMaybe] = explode(':', $host, 2);
    if ($hostOnly !== '') $host = $hostOnly;
    if (ctype_digit($portMaybe)) $port = $portMaybe;
}
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$testAdminUsername = trim((string)env('TEST_DB_ADMIN_USERNAME', DB_USERNAME));
$testAdminPassword = (string)env('TEST_DB_ADMIN_PASSWORD', DB_PASSWORD);
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $testAdminUsername, $testAdminPassword, $options);
$database = 'firespot_partner_purpose_test_' . bin2hex(random_bytes(4));
try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $error) {
    $accessDenied = in_array((string)$error->getCode(), ['42000','HY000'], true) && in_array((int)($error->errorInfo[1] ?? 0), [1044,1045,1142], true);
    if ($accessDenied && $testAdminUsername === DB_USERNAME) {
        echo "SKIP: teste de finalidades exige TEST_DB_ADMIN_USERNAME para schema descartável; conta da aplicação permaneceu sem DDL.\n";
        exit(0);
    }
    throw $error;
}
$checks = 0;

function purpose_test_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

function purpose_test_requirement(array $readiness, string $code): ?array
{
    foreach (($readiness['requirements'] ?? []) as $requirement) {
        if (($requirement['code'] ?? '') === $code) return $requirement;
    }
    return null;
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $testAdminUsername, $testAdminPassword, $options);
    $pdo->exec("CREATE TABLE partners (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,code VARCHAR(32) NOT NULL UNIQUE,name VARCHAR(150) NOT NULL,
      active TINYINT NOT NULL DEFAULT 1,require_auth TINYINT NOT NULL DEFAULT 0,free_minutes INT NOT NULL DEFAULT 20,
      max_uses_total INT NULL,max_uses_per_device INT NOT NULL DEFAULT 1,window_per_device_minutes INT NOT NULL DEFAULT 1440,
      portal_mode ENUM('inherit','classic','v2','v3') NOT NULL DEFAULT 'v3',
      access_purpose ENUM('free','sponsored','paid','hybrid') NULL,self_service_enabled TINYINT NOT NULL DEFAULT 0,
      ads_enabled TINYINT NOT NULL DEFAULT 1,allow_global_ads TINYINT NOT NULL DEFAULT 0,
      independent_billing TINYINT NOT NULL DEFAULT 1,payment_wallet_id INT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE app_settings (skey VARCHAR(100) NOT NULL PRIMARY KEY,svalue TEXT NULL) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO app_settings (skey,svalue) VALUES ('courtesy_radius_ready','1')");
    $pdo->exec("CREATE TABLE custom_ads (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,partner_id INT NULL,title VARCHAR(200) NOT NULL,
      active TINYINT NOT NULL DEFAULT 1,start_date DATE NULL,end_date DATE NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE planos (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,nome VARCHAR(120) NOT NULL,descricao VARCHAR(255) NULL,
      preco_centavos INT NOT NULL,duracao_min INT NOT NULL,down_kbps INT NOT NULL DEFAULT 0,
      up_kbps INT NOT NULL DEFAULT 0,ordem INT NOT NULL DEFAULT 100,ativo TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE partner_payment_plans (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,partner_id INT NOT NULL,name VARCHAR(120) NOT NULL,
      description VARCHAR(255) NULL,price_cents INT NOT NULL,duration_minutes INT NOT NULL,
      download_kbps INT NOT NULL DEFAULT 0,upload_kbps INT NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 100,active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE payment_wallets (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,partner_id INT NULL,provider VARCHAR(32) NOT NULL,
      name VARCHAR(120) NOT NULL,environment ENUM('sandbox','production') NOT NULL DEFAULT 'production',
      public_key VARCHAR(255) NOT NULL,access_token_encrypted TEXT NOT NULL,credential_hint VARCHAR(32) NULL,
      active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");

    $pdo->exec("INSERT INTO partners (code,name,access_purpose) VALUES ('purpose-test','Finalidades','free')");
    $pdo->exec((string)file_get_contents(__DIR__ . '/../migrations/017_unified_courtesy_policy.sql'));
    $pdo->exec((string)file_get_contents(__DIR__ . '/../migrations/019_courtesy_portal_rollout.sql'));
    $pdo->exec("UPDATE app_settings SET svalue='1' WHERE skey='courtesy_cutover_enabled'");
    $encrypted = fs_encrypt_credential('fixture-payment-token');
    $st = $pdo->prepare("INSERT INTO payment_wallets (partner_id,provider,name,public_key,access_token_encrypted,credential_hint) VALUES (1,'mercadopago','Teste','fixture-public-key',?,'test')");
    $st->execute([$encrypted]);
    $walletId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE partners SET payment_wallet_id=? WHERE id=1')->execute([$walletId]);
    $pdo->exec("INSERT INTO partner_payment_plans (partner_id,name,price_cents,duration_minutes) VALUES (1,'Uma hora',1000,60)");
    $pdo->exec("INSERT INTO custom_ads (partner_id,title) VALUES (1,'Patrocinador local')");

    $partner = $pdo->query('SELECT * FROM partners WHERE id=1')->fetch();

    $freeRolloutBlocked = partner_purpose_readiness($pdo, $partner, 'free');
    purpose_test_expect($freeRolloutBlocked['ready'] === false, 'Portal V3 legado foi aceito para finalidade com cortesia.');
    purpose_test_expect(purpose_test_requirement($freeRolloutBlocked, 'courtesy_v3_rollout')['ready'] === false, 'Bloqueio do rollout V3 não foi identificado.');
    $pdo->exec("UPDATE courtesy_portal_rollouts SET mode='enforce' WHERE partner_id=1 AND portal='v3'");
    $free = partner_purpose_readiness($pdo, $partner, 'free');
    purpose_test_expect($free['ready'] === true, 'Gratuito rápido válido foi bloqueado.');
    purpose_test_expect(purpose_test_requirement($free, 'courtesy_ad_disabled')['ready'] === true, 'Gratuito rápido não confirmou anúncio opcional.');

    $sponsoredBlocked = partner_purpose_readiness($pdo, $partner, 'sponsored');
    purpose_test_expect($sponsoredBlocked['ready'] === false, 'Patrocinado sem anúncio obrigatório foi aceito.');
    purpose_test_expect(purpose_test_requirement($sponsoredBlocked, 'courtesy_ad_required')['ready'] === false, 'Bloqueio do anúncio obrigatório não foi identificado.');
    try {
        partner_purpose_change($pdo, 1, 'sponsored');
        $blockedChange = false;
    } catch (RuntimeException $e) {
        $blockedChange = strpos($e->getMessage(), 'Gratuito patrocinado') !== false;
    }
    purpose_test_expect($blockedChange, 'Falha não identificou a finalidade patrocinada solicitada.');
    purpose_test_expect((string)$pdo->query('SELECT access_purpose FROM partners WHERE id=1')->fetchColumn() === 'free', 'Falha alterou parcialmente a finalidade salva.');

    $pdo->exec('UPDATE courtesy_partner_policies SET requires_ad=1 WHERE partner_id=1');
    $sponsored = partner_purpose_readiness($pdo, $partner, 'sponsored');
    purpose_test_expect($sponsored['ready'] === true, 'Patrocinado completo foi bloqueado.');
    $partnerWithoutAds = $partner;
    $partnerWithoutAds['ads_enabled'] = 0;
    $sponsoredWithoutAds = partner_purpose_readiness($pdo, $partnerWithoutAds, 'sponsored');
    purpose_test_expect(purpose_test_requirement($sponsoredWithoutAds, 'ads_enabled')['ready'] === false, 'Patrocinado não indicou publicidade desativada.');

    $paidBlocked = partner_purpose_readiness($pdo, $partner, 'paid');
    purpose_test_expect($paidBlocked['ready'] === false, 'Pago com cortesia habilitada foi aceito.');
    purpose_test_expect(purpose_test_requirement($paidBlocked, 'courtesy_disabled')['ready'] === false, 'Pago não indicou que a cortesia deve ser desativada.');
    $pdo->exec('UPDATE courtesy_partner_policies SET enabled=0,requires_ad=0 WHERE partner_id=1');
    $paid = partner_purpose_readiness($pdo, $partner, 'paid');
    purpose_test_expect($paid['ready'] === true, 'Acesso pago completo foi bloqueado.');

    putenv('PAYMENT_CREDENTIAL_KEY');
    $paidWithoutKey = partner_purpose_readiness($pdo, $partner, 'paid');
    $walletRequirement = purpose_test_requirement($paidWithoutKey, 'payment_wallet');
    purpose_test_expect($walletRequirement['ready'] === false && strpos((string)$walletRequirement['message'], 'PAYMENT_CREDENTIAL_KEY') !== false, 'Chave de credencial ausente não foi identificada claramente.');
    putenv('PAYMENT_CREDENTIAL_KEY=' . base64_encode(str_repeat('P', 32)));

    $pdo->exec('UPDATE courtesy_partner_policies SET enabled=1,requires_ad=0 WHERE partner_id=1');
    $hybrid = partner_purpose_readiness($pdo, $partner, 'hybrid');
    purpose_test_expect($hybrid['ready'] === true, 'Modelo híbrido completo foi bloqueado.');
    purpose_test_expect(purpose_test_requirement($hybrid, 'courtesy_limited')['ready'] === true, 'Híbrido não reconheceu o limite por dispositivo.');

    echo "Finalidades comerciais concluídas: {$checks} verificações.\n";
} finally {
    $server->exec('DROP DATABASE `' . $database . '`');
}
