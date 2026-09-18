<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/courtesy_policy.php';
require_once __DIR__ . '/../app/courtesy_radius.php';
require_once __DIR__ . '/../app/courtesy_shadow.php';
require_once __DIR__ . '/../app/courtesy_access.php';

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
$database = 'firespot_courtesy_radius_test_' . bin2hex(random_bytes(4));
try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $error) {
    $accessDenied = in_array((string)$error->getCode(), ['42000','HY000'], true)
        && in_array((int)($error->errorInfo[1] ?? 0), [1044,1045,1142], true);
    if ($accessDenied && $testAdminUsername === DB_USERNAME) {
        echo "SKIP: integração de cortesia exige TEST_DB_ADMIN_USERNAME para criar schema descartável; conta da aplicação permaneceu sem DDL.\n";
        exit(0);
    }
    throw $error;
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $testAdminUsername, $testAdminPassword, $options);
    $pdo->exec("CREATE TABLE app_settings (
        skey VARCHAR(100) NOT NULL PRIMARY KEY,
        svalue TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE partners (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        name VARCHAR(150) NOT NULL,
        require_auth TINYINT NOT NULL DEFAULT 0,
        free_minutes INT NOT NULL DEFAULT 5,
        max_uses_total INT NULL,
        max_uses_per_device INT NOT NULL DEFAULT 1,
        window_per_device_minutes INT NOT NULL DEFAULT 60,
        active TINYINT NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO partners (code,name) VALUES ('radius-test','Radius Test')");
    $pdo->exec("CREATE TABLE radcheck (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NULL,
        attribute VARCHAR(64) NOT NULL,
        op CHAR(2) NOT NULL DEFAULT '==',
        value VARCHAR(253) NOT NULL,
        UNIQUE KEY unique_username (username,attribute)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE radreply (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        attribute VARCHAR(64) NOT NULL,
        op CHAR(2) NOT NULL DEFAULT '=',
        value VARCHAR(253) NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE radusergroup (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NULL,
        groupname VARCHAR(64) NOT NULL,
        priority INT NOT NULL DEFAULT 1,
        UNIQUE KEY ix_user_group (username,groupname)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE radacct (
        radacctid BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NULL,
        acctstarttime DATETIME NULL,
        acctupdatetime DATETIME NULL,
        acctstoptime DATETIME NULL,
        acctsessiontime INT UNSIGNED NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $migration17 = file_get_contents(__DIR__ . '/../migrations/017_unified_courtesy_policy.sql');
    $migration18 = file_get_contents(__DIR__ . '/../migrations/018_courtesy_radius_shadow.sql');
    if ($migration17 === false || $migration18 === false) throw new RuntimeException('Migrações de teste ausentes.');
    $pdo->exec($migration17);
    $pdo->exec($migration18);

    $now = strtotime('2026-08-09 12:00:00 UTC');
    if ($now === false) throw new RuntimeException('Data de teste inválida.');
    $shadow = fs_courtesy_shadow_capture($pdo, [
        'allowed' => true,
        'code' => 'LEGACY_GRANTED',
        'minutes' => 5,
    ], [
        'partner_id' => 1,
        'device_key' => '11:22:33:44:55:66',
        'mac' => '11:22:33:44:55:66',
        'portal' => 'test',
        'source' => 'shadow_integration',
    ]);
    if (!$shadow || empty($shadow['match'])) throw new RuntimeException('Shadow mode não registrou uma decisão equivalente.');
    if ((int) $pdo->query('SELECT COUNT(*) FROM courtesy_shadow_events')->fetchColumn() !== 1) {
        throw new RuntimeException('Evento shadow não foi persistido.');
    }

    $savedPolicy = fs_courtesy_policy_save($pdo, 1, array_merge(fs_courtesy_policy_defaults(), [
        'grant_minutes' => 7,
        'credit_validity_minutes' => 60,
        'auth_mode' => 'anonymous',
        'device_max_grants' => 2,
        'device_period_minutes' => 120,
        'radius_group' => 'courtesy_test',
    ]));
    if ((int) $savedPolicy['grant_minutes'] !== 7 || (int) $savedPolicy['revision'] !== 2) {
        throw new RuntimeException('Editor unificado não persistiu/revisionou a política.');
    }
    $legacyPartner = $pdo->query('SELECT require_auth,free_minutes,max_uses_per_device,window_per_device_minutes FROM partners WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if ((int) $legacyPartner['require_auth'] !== 0 || (int) $legacyPartner['free_minutes'] !== 7 || (int) $legacyPartner['max_uses_per_device'] !== 2 || (int) $legacyPartner['window_per_device_minutes'] !== 120) {
        throw new RuntimeException('Campos compatíveis do legado não foram sincronizados.');
    }

    $migration19 = file_get_contents(__DIR__ . '/../migrations/019_courtesy_portal_rollout.sql');
    $migration20 = file_get_contents(__DIR__ . '/../migrations/020_courtesy_classic_rollout_split.sql');
    $migration21 = file_get_contents(__DIR__ . '/../migrations/021_courtesy_legacy_radius_archive.sql');
    if ($migration19 === false || $migration20 === false || $migration21 === false) throw new RuntimeException('Migrações de rollout/arquivo de teste ausentes.');
    $pdo->exec($migration19);
    $pdo->exec($migration20);
    $pdo->exec($migration21);
    if ((int) $pdo->query('SELECT COUNT(*) FROM courtesy_portal_rollouts')->fetchColumn() !== 5) {
        throw new RuntimeException('Rollouts iniciais não foram criados para todos os portais.');
    }
    $classicModes = $pdo->query("SELECT portal,mode FROM courtesy_portal_rollouts WHERE portal LIKE 'classic_%' ORDER BY portal")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($classicModes['classic_signup'] ?? '') !== 'shadow' || ($classicModes['classic_login'] ?? '') !== 'legacy') {
        throw new RuntimeException('Cadastro e login clássico não receberam rollouts independentes seguros.');
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('courtesy_legacy_radius_cleanup_runs','courtesy_legacy_radius_archive')")->fetchColumn() !== 2) {
        throw new RuntimeException('Arquivo recuperável de credenciais legadas não foi criado.');
    }
    $qrShadow = fs_courtesy_shadow_capture($pdo, [
        'allowed' => true,
        'code' => 'LEGACY_GRANTED',
        'minutes' => 7,
    ], [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:02',
        'mac' => '02:00:00:00:00:02',
        'portal' => 'qr_ad',
        'source' => 'qr_check',
    ]);
    if (!$qrShadow || empty($qrShadow['match'])) throw new RuntimeException('Shadow do QR não respeitou o rollout ativo.');
    $classicSignupShadow = fs_courtesy_shadow_capture($pdo, [
        'allowed' => true,
        'code' => 'LEGACY_SIGNUP_TRIAL',
        'minutes' => 7,
    ], [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:03',
        'mac' => '02:00:00:00:00:03',
        'portal' => 'classic_signup',
        'source' => 'signup_trial',
    ]);
    if (!$classicSignupShadow) throw new RuntimeException('Shadow do cadastro clássico não respeitou o rollout ativo.');
    $classicLoginShadow = fs_courtesy_shadow_capture($pdo, [
        'allowed' => true,
        'code' => 'LEGACY_GRANTED',
        'minutes' => 7,
    ], [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:04',
        'mac' => '02:00:00:00:00:04',
        'portal' => 'classic_login',
        'source' => 'login',
    ]);
    if ($classicLoginShadow !== null) throw new RuntimeException('Login clássico em legacy gerou observação indevida.');
    $beforeSafeGate = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_grants WHERE legacy_source IS NULL")->fetchColumn();
    $safeGate = fs_courtesy_access_issue($pdo, [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:01',
        'portal' => 'v2',
        'source' => 'safe_gate_test',
        'idempotency_key' => 'safe-gate-test',
    ], $pdo, $now);
    $afterSafeGate = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_grants WHERE legacy_source IS NULL")->fetchColumn();
    if (!empty($safeGate['handled']) || ($safeGate['mode'] ?? '') !== 'shadow' || $beforeSafeGate !== $afterSafeGate) {
        throw new RuntimeException('Gate fechado criou uma concessão ou tomou o fluxo legado.');
    }
    try {
        fs_courtesy_rollout_save($pdo, 1, 'v2', 'enforce', 'integration-test');
        throw new RuntimeException('Rollout aceitou enforce com o gate global fechado.');
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'gate global') === false) throw $e;
    }

    $_SESSION = [];
    $idempotencyContext = [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:10',
        'account_key' => 'integration-account',
        'portal' => 'v2',
    ];
    $sessionKeyA = fs_courtesy_access_idempotency($idempotencyContext, 'integration');
    $sessionKeyB = fs_courtesy_access_idempotency($idempotencyContext, 'integration');
    if ($sessionKeyA === '' || $sessionKeyA !== $sessionKeyB) {
        throw new RuntimeException('Idempotência de sessão não reutilizou a ação corrente.');
    }

    $pdo->prepare("UPDATE app_settings SET svalue='1' WHERE skey IN ('courtesy_cutover_enabled','courtesy_radius_ready')")->execute();
    fs_courtesy_rollout_save($pdo, 1, 'v2', 'enforce', 'integration-test');
    $facadeContext = [
        'partner_id' => 1,
        'device_key' => '02:00:00:00:00:11',
        'mac' => '02:00:00:00:00:11',
        'portal' => 'v2',
        'source' => 'facade_integration',
        'idempotency_key' => 'facade-integration-request',
    ];
    $facadeActive = fs_courtesy_access_issue($pdo, $facadeContext, $pdo, $now);
    if (empty($facadeActive['handled']) || ($facadeActive['code'] ?? '') !== 'ACTIVE') {
        throw new RuntimeException('Fachada não provisionou a concessão com rollout em enforce.');
    }
    $reconnectContext = $facadeContext;
    $reconnectContext['idempotency_key'] = 'facade-reconnect-request';
    $facadeReused = fs_courtesy_access_issue($pdo, $reconnectContext, $pdo, $now + 1);
    if (empty($facadeReused['handled']) || ($facadeReused['code'] ?? '') !== 'ACTIVE'
        || empty($facadeReused['reconnected'])
        || ($facadeReused['grant']['public_id'] ?? '') !== ($facadeActive['grant']['public_id'] ?? '')) {
        throw new RuntimeException('Fachada não recuperou a concessão ativa na reconexão.');
    }
    fs_courtesy_radius_delete_credentials($pdo, (string)$facadeActive['grant']['username']);
    $pdo->prepare("UPDATE courtesy_grants SET status='revoked',ended_at=?,radius_cleaned_at=?,updated_at=? WHERE public_id=?")
        ->execute([gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), (string)$facadeActive['grant']['public_id']]);

    $reserved = fs_courtesy_reserve($pdo, [
        'partner_id' => 1,
        'device_key' => 'AA:BB:CC:DD:EE:FF',
        'mac' => 'AA:BB:CC:DD:EE:FF',
        'portal' => 'test',
        'source' => 'radius_integration',
        'idempotency_key' => 'radius-test-request',
    ], $now);
    if (($reserved['code'] ?? '') !== 'RESERVED') throw new RuntimeException('Reserva de integração falhou.');
    $publicId = (string) $reserved['grant']['public_id'];

    $active = fs_courtesy_radius_provision($pdo, $publicId, $pdo, $now);
    if (($active['code'] ?? '') !== 'ACTIVE') throw new RuntimeException('Provisionamento não ativou a concessão.');
    $username = (string) $active['grant']['username'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username=?');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() !== 5) throw new RuntimeException('Atributos radcheck incompletos.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM radreply WHERE username=?');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() !== 2) throw new RuntimeException('Atributos radreply incompletos.');

    $pdo->prepare('INSERT INTO radacct (username,acctstarttime,acctupdatetime,acctstoptime,acctsessiontime) VALUES (?,?,?,?,?)')
        ->execute([$username, '2026-08-09 12:00:00', '2026-08-09 12:02:00', '2026-08-09 12:02:00', 120]);
    $reconnect = fs_courtesy_radius_prepare_reconnect($pdo, $publicId, $pdo, $now + 120);
    if (($reconnect['code'] ?? '') !== 'ACTIVE' || (int) $reconnect['grant']['remaining_seconds'] !== 300) {
        throw new RuntimeException('Saldo de reconexão incorreto.');
    }

    $pdo->prepare('UPDATE radacct SET acctsessiontime=420,acctupdatetime=?,acctstoptime=? WHERE username=?')
        ->execute(['2026-08-09 12:07:00', '2026-08-09 12:07:00', $username]);
    $reconciled = fs_courtesy_radius_reconcile($pdo, $publicId, $pdo, $now + 420);
    if (($reconciled['status'] ?? '') !== 'exhausted' || empty($reconciled['cleaned'])) {
        throw new RuntimeException('Reconciliação não encerrou/limpou a concessão.');
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username=?');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() !== 0) throw new RuntimeException('Credencial RADIUS não foi limpa.');

    echo "Integração concluída: política, gates, reserva, RADIUS, reconexão, saldo e limpeza.\n";
} finally {
    $server->exec('DROP DATABASE `' . $database . '`');
}
