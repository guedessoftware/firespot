<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/partner_admin.php';
require_once __DIR__ . '/../app/partner_ads.php';
require_once __DIR__ . '/../app/payment_wallets.php';
require_once __DIR__ . '/../app/partner_central.php';

$host = DB_HOST;
$port = '3306';
if (strpos($host, ':') !== false) {
    [$hostOnly,$portMaybe] = explode(':',$host,2);
    if ($hostOnly !== '') $host = $hostOnly;
    if (ctype_digit($portMaybe)) $port = $portMaybe;
}
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$testAdminUsername=trim((string)env('TEST_DB_ADMIN_USERNAME',DB_USERNAME));
$testAdminPassword=(string)env('TEST_DB_ADMIN_PASSWORD',DB_PASSWORD);
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$testAdminUsername,$testAdminPassword,$options);
$database = 'firespot_partner_admin_test_' . bin2hex(random_bytes(4));
try{$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
catch(PDOException $error){
    $accessDenied=in_array((string)$error->getCode(),['42000','HY000'],true)&&in_array((int)($error->errorInfo[1]??0),[1044,1045,1142],true);
    if($accessDenied&&$testAdminUsername===DB_USERNAME){echo "SKIP: integração do painel exige TEST_DB_ADMIN_USERNAME para schema descartável; conta da aplicação permaneceu sem DDL.\n";exit(0);}throw$error;
}
$checks = 0;

function partner_integration_expect(bool $condition,string $message):void
{
    global $checks;
    $checks++;
    if(!$condition)throw new RuntimeException($message);
}

function partner_integration_throws(callable $callback,string $message):void
{
    try{$callback();$threw=false;}catch(Throwable $e){$threw=true;}
    partner_integration_expect($threw,$message);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$testAdminUsername,$testAdminPassword,$options);
    $pdo->exec("CREATE TABLE partners (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,code VARCHAR(32) NOT NULL UNIQUE,name VARCHAR(150) NOT NULL,
      active TINYINT NOT NULL DEFAULT 1,portal_mode ENUM('inherit','classic','v2','v3') NOT NULL DEFAULT 'inherit',
      independent_billing TINYINT NOT NULL DEFAULT 0,payment_wallet_id INT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE payment_wallets (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,provider VARCHAR(32) NOT NULL,name VARCHAR(120) NOT NULL,
      environment ENUM('sandbox','production') NOT NULL DEFAULT 'production',public_key VARCHAR(255) NOT NULL,
      access_token_encrypted TEXT NOT NULL,credential_hint VARCHAR(32) NULL,active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE custom_ads (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,title VARCHAR(200) NOT NULL,image_url VARCHAR(500) NOT NULL,link_url VARCHAR(500) NULL,
      duration_sec INT NOT NULL DEFAULT 15,weight INT NOT NULL DEFAULT 1,active TINYINT NOT NULL DEFAULT 1,partner_code VARCHAR(32) NULL,
      start_date DATE NULL,end_date DATE NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE custom_ads_events (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,ad_id BIGINT NOT NULL,username VARCHAR(64) NULL,mac VARCHAR(32) NULL,
      event ENUM('impression','interest_yes','interest_no') NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE ad_grants (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,username VARCHAR(32) NOT NULL,mac VARCHAR(32) NOT NULL,ip VARCHAR(64) NULL,minutes INT NOT NULL,granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE login_tokens (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,code VARCHAR(16) NOT NULL UNIQUE,username VARCHAR(64) NOT NULL,ip VARCHAR(64) NULL,mac VARCHAR(32) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,expires_at DATETIME NOT NULL) ENGINE=InnoDB");

    $pdo->exec("INSERT INTO partners (code,name) VALUES ('unit-a','Unidade A'),('unit-b','Unidade B')");
    $migration=(string)file_get_contents(__DIR__.'/../migrations/022_partner_admin_portal.sql');
    $pdo->exec($migration);
    partner_integration_expect((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('host_users','host_password_resets')")->fetchColumn()===2,'Migração limpa não criou as tabelas de identidade e reset.');
    partner_integration_expect((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='custom_ads' AND COLUMN_NAME IN ('partner_code','partner_id','start_date','end_date')")->fetchColumn()===4,'Migração limpa não completou as colunas de anúncios.');
    $password='correct-password-123';
    $st=$pdo->prepare('INSERT INTO host_users (email,password_hash,partner_code,name) VALUES (?,?,?,?)');
    $st->execute(['owner@example.test',password_hash($password,PASSWORD_DEFAULT),'unit-a','Owner A']);

    // Segunda aplicação valida idempotência e executa o backfill da conta
    // que representa um ambiente legado já preenchido.
    $pdo->exec($migration);
    $ownerUserId=(int)$pdo->query("SELECT id FROM host_users WHERE email='owner@example.test'")->fetchColumn();
    $ownerMembershipId=(int)$pdo->query("SELECT id FROM partner_admin_memberships WHERE user_id={$ownerUserId} AND partner_id=1")->fetchColumn();
    partner_integration_expect($ownerMembershipId>0,'Conta legada não foi migrada como owner.');
    $migration024=(string)file_get_contents(__DIR__.'/../migrations/024_ad_media_and_interest_flow.sql');
    $pdo->exec($migration024);
    $migration025=(string)file_get_contents(__DIR__.'/../migrations/025_ad_button_labels.sql');
    $pdo->exec($migration025);
    partner_integration_expect((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='custom_ads' AND COLUMN_NAME IN ('media_type','media_url','poster_url','fit_mode','interest_button_text','skip_button_text')")->fetchColumn()===6,'Migrações de campanha não completaram mídia e textos dos botões.');

    $_SERVER['REMOTE_ADDR']='192.0.2.10';
    $_SESSION=[];
    $pdo->prepare("INSERT INTO partner_admin_memberships (user_id,partner_id,role,active,created_by_type,created_by_id) VALUES (?,?, 'manager',1,'firespot',1)")->execute([$ownerUserId,2]);
    $authenticated=partner_admin_authenticate($pdo,'OWNER@EXAMPLE.TEST',$password);
    partner_integration_expect(count($authenticated['memberships'])===2,'Login não retornou as duas unidades.');
    partner_integration_expect(empty($_SESSION['host_membership_id'])&&!empty($_SESSION['partner_admin_pending_user_id']),'Login multiunidade não exigiu escolha explícita.');
    partner_admin_select_membership($pdo,$ownerUserId,$ownerMembershipId);
    $context=partner_admin_context($pdo);
    partner_integration_expect((int)($context['partner_id']??0)===1,'Contexto selecionado não ficou isolado na unidade A.');
    partner_integration_throws(static fn()=>partner_admin_select_membership($pdo,$ownerUserId,999999),'Seleção aceitou vínculo inexistente.');

    $_SESSION=[];
    for($attempt=0;$attempt<5;$attempt++)partner_integration_throws(static fn()=>partner_admin_authenticate($pdo,'missing@example.test','wrong-password'),'Credencial inválida foi aceita.');
    partner_integration_expect(partner_admin_login_blocked($pdo,'missing@example.test'),'Rate limit não bloqueou a quinta falha.');
    $attemptRow=$pdo->query('SELECT origin_hash,identity_hash FROM partner_admin_login_attempts LIMIT 1')->fetch();
    partner_integration_expect(strlen((string)$attemptRow['origin_hash'])===64&&strlen((string)$attemptRow['identity_hash'])===64,'Rate limit persistiu chave sem hash.');
    $_SERVER['REMOTE_ADDR']='198.51.100.10';
    for($attempt=0;$attempt<10;$attempt++)partner_admin_login_failed($pdo,'distributed@example.test');
    $_SERVER['REMOTE_ADDR']='198.51.100.11';
    partner_integration_expect(partner_admin_login_blocked($pdo,'distributed@example.test'),'Rate limit por identidade não bloqueou origens distribuídas.');
    $_SERVER['REMOTE_ADDR']='203.0.113.10';
    for($attempt=0;$attempt<25;$attempt++)partner_admin_login_failed($pdo,'spray-'.$attempt.'@example.test');
    partner_integration_expect(partner_admin_login_blocked($pdo,'new-target@example.test'),'Rate limit por origem não bloqueou pulverização de identidades.');
    $_SERVER['REMOTE_ADDR']='192.0.2.10';

    $_SESSION=[];
    $authenticated=partner_admin_authenticate($pdo,'owner@example.test',$password);
    partner_admin_select_membership($pdo,$ownerUserId,$ownerMembershipId);
    partner_admin_revoke_user_sessions($pdo,1,$ownerUserId,'firespot',1);
    partner_integration_expect(partner_admin_context($pdo)===null,'Revogação não invalidou a sessão na requisição seguinte.');

    $_SERVER['HTTP_HOST']='attacker.example.test';
    $invite=partner_admin_invite($pdo,1,'second-owner@example.test','owner','firespot',1);
    partner_integration_expect(strlen($invite['token'])===64&&strpos((string)$pdo->query('SELECT token_hash FROM partner_admin_invitations WHERE id='.(int)$invite['id'])->fetchColumn(),$invite['token'])===false,'Convite não foi armazenado somente como hash.');
    partner_integration_expect(strpos($invite['url'],'attacker.example.test')===false,'Link de convite confiou no cabeçalho Host.');
    partner_integration_expect(strpos($invite['url'],'/admin/convite.php?token=')!==false,'Link de convite não usa a rota administrativa curta.');
    $accepted=partner_admin_accept_invitation($pdo,$invite['token'],'Owner B','another-password-123');
    $secondUserId=(int)$accepted['user_id'];
    partner_integration_throws(static fn()=>partner_admin_accept_invitation($pdo,$invite['token'],'Owner B','another-password-123'),'Convite foi reutilizado.');
    partner_integration_throws(static fn()=>partner_admin_invite($pdo,1,'owner@example.test','viewer','firespot',1),'Convite duplicado poderia alterar um vínculo existente.');

    $expired=partner_admin_invite($pdo,1,'expired@example.test','viewer','firespot',1);
    $pdo->prepare('UPDATE partner_admin_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?')->execute([(int)$expired['id']]);
    partner_integration_expect(partner_admin_invitation($pdo,$expired['token'])===null,'Convite expirado permaneceu válido.');
    $revoked=partner_admin_invite($pdo,1,'revoked@example.test','viewer','firespot',1);
    partner_admin_revoke_invitation($pdo,1,(int)$revoked['id'],'firespot',1);
    partner_integration_expect(partner_admin_invitation($pdo,$revoked['token'])===null,'Convite revogado permaneceu válido.');

    partner_admin_update_membership($pdo,1,$ownerMembershipId,'viewer',0,'firespot',1);
    $secondMembershipId=(int)$pdo->query("SELECT id FROM partner_admin_memberships WHERE user_id={$secondUserId} AND partner_id=1")->fetchColumn();
    partner_integration_throws(static fn()=>partner_admin_update_membership($pdo,1,$secondMembershipId,'viewer',0,'firespot',1),'Último owner foi removido.');
    partner_integration_throws(static fn()=>partner_admin_set_user_active($pdo,$secondUserId,0,'firespot',1),'Identidade do último owner foi desativada.');
    partner_integration_throws(static fn()=>partner_admin_set_user_active($pdo,$secondUserId,1,'firespot',1,2),'Alteração de identidade aceitou partner_id sem vínculo.');

    $resetToken=bin2hex(random_bytes(32));
    $beforeVersion=(int)$pdo->query("SELECT auth_version FROM host_users WHERE id={$secondUserId}")->fetchColumn();
    $pdo->prepare('INSERT INTO host_password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$secondUserId,hash('sha256',$resetToken)]);
    partner_admin_password_reset($pdo,$resetToken,'changed-password-123');
    $resetUser=$pdo->query("SELECT password_hash,auth_version FROM host_users WHERE id={$secondUserId}")->fetch();
    partner_integration_expect(password_verify('changed-password-123',$resetUser['password_hash'])&&(int)$resetUser['auth_version']===$beforeVersion+1,'Reset não trocou senha e auth_version.');
    partner_integration_throws(static fn()=>partner_admin_password_reset($pdo,$resetToken,'changed-again-123'),'Token de reset foi reutilizado.');
    partner_integration_throws(static fn()=>partner_admin_password_reset($pdo,bin2hex(random_bytes(32)),'short'),'Senha curta foi aceita.');

    $pdo->exec("INSERT INTO custom_ads (title,media_type,media_url,image_url,link_url,interest_button_text,skip_button_text,active,partner_code,partner_id) VALUES ('B','image','https://example.test/b.png','https://example.test/b.png',NULL,'Tenho interesse','Pular e conectar',1,NULL,2),('Global','image','https://example.test/g.png','https://example.test/g.png','https://example.test/offer','Quero esta vaga','Agora não',1,NULL,NULL)");
    $adB=(int)$pdo->query("SELECT id FROM custom_ads WHERE title='B'")->fetchColumn();
    partner_integration_expect(partner_ads_owned($pdo,1,$adB)===null,'Anúncio de outra unidade atravessou ownership.');
    $partnerA=$pdo->query('SELECT * FROM partners WHERE id=1')->fetch();
    $pdo->exec('UPDATE partners SET ads_enabled=1,allow_global_ads=0 WHERE id=1');
    $partnerA=$pdo->query('SELECT * FROM partners WHERE id=1')->fetch();
    partner_integration_expect(partner_ads_eligible($pdo,$partnerA)===[],'Anúncio de outra unidade virou fallback.');
    $pdo->exec('UPDATE partners SET allow_global_ads=1 WHERE id=1');
    $partnerA=$pdo->query('SELECT * FROM partners WHERE id=1')->fetch();
    $eligible=partner_ads_eligible($pdo,$partnerA);
    partner_integration_expect(count($eligible)===1&&$eligible[0]['title']==='Global'&&$eligible[0]['interest_button_text']==='Quero esta vaga'&&$eligible[0]['skip_button_text']==='Agora não','Campanha global autorizada ou seus textos não foram selecionados isoladamente.');
    $_SESSION=[];
    $globalAdId=(int)$eligible[0]['id'];
    partner_ads_track($pdo,1,$globalAdId,'view_complete',null,'AA:BB:CC:DD:EE:FF');
    $offerToken=partner_ads_queue_offer($pdo,1,$globalAdId);
    partner_integration_expect(is_string($offerToken)&&strlen($offerToken)===48,'Interesse não criou oferta pendente opaca.');
    partner_integration_expect((string)$pdo->query('SELECT token_hash FROM ad_pending_offers ORDER BY id DESC LIMIT 1')->fetchColumn()===hash('sha256',$offerToken),'Oferta pendente não armazenou somente o hash do token.');
    $pending=partner_ads_pending_offer($pdo,1,$offerToken);
    partner_integration_expect(($pending['link_url']??'')==='https://example.test/offer','Oferta pendente perdeu o destino validado.');
    $offerUrl=partner_ads_pending_offer_url($pdo,1);
    partner_integration_expect(is_string($offerUrl)&&strpos($offerUrl,'/portal/oferta.php?token=')!==false,'Destino pós-conexão não usa a página interna do FireSpot.');
    partner_ads_track($pdo,1,$globalAdId,'destination_open',null,null);
    partner_integration_expect((int)$pdo->query("SELECT COUNT(*) FROM custom_ads_events WHERE event='destination_open'")->fetchColumn()===1,'Abertura da oferta não foi registrada separadamente.');
    partner_ads_consume_offer($pdo,$offerToken,'dismiss');
    partner_integration_expect(partner_ads_pending_offer($pdo,1,$offerToken)===null,'Oferta consumida permaneceu reutilizável.');

    $pdo->exec("INSERT INTO payment_wallets (partner_id,provider,name,environment,public_key,access_token_encrypted,active) VALUES (2,'mercadopago','Wallet B','production','PK','opaque',1)");
    $walletId=(int)$pdo->lastInsertId();
    $partnerA['id']=1;$partnerA['independent_billing']=1;$partnerA['payment_wallet_id']=$walletId;
    partner_integration_throws(static fn()=>fs_wallet_for_partner($pdo,$partnerA,false),'Carteira de outra unidade foi aceita.');

    $pdo->exec("CREATE TABLE planos (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,nome VARCHAR(120) NOT NULL,descricao VARCHAR(255) NULL,preco_centavos INT NOT NULL,duracao_min INT NOT NULL,down_kbps INT NOT NULL DEFAULT 0,up_kbps INT NOT NULL DEFAULT 0,ordem INT NOT NULL DEFAULT 100,ativo TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE partner_payment_plans (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,partner_id INT NOT NULL,name VARCHAR(120) NOT NULL,description VARCHAR(255) NULL,price_cents INT NOT NULL,duration_minutes INT NOT NULL,download_kbps INT NOT NULL DEFAULT 0,upload_kbps INT NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 100,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO partner_payment_plans (partner_id,name,price_cents,duration_minutes) VALUES (1,'Local',1000,60)");
    $localPlanId=(int)$pdo->lastInsertId();
    $pdo->exec("UPDATE partners SET access_purpose='free',portal_mode='v3' WHERE id=1");
    partner_central_assert_plan_deactivation_allowed($pdo,1,$localPlanId);
    $pdo->exec("UPDATE partners SET access_purpose='paid' WHERE id=1");
    partner_integration_throws(static fn()=>partner_central_assert_plan_deactivation_allowed($pdo,1,$localPlanId),'Último catálogo de finalidade paga pôde ser removido.');
    $pdo->exec("INSERT INTO planos (nome,preco_centavos,duracao_min) VALUES ('Global',1200,120)");
    partner_central_assert_plan_deactivation_allowed($pdo,1,$localPlanId);

    partner_integration_expect((int)$pdo->query('SELECT COUNT(*) FROM partner_admin_audit')->fetchColumn()>0,'Ações administrativas não geraram auditoria.');
    echo "Integração do painel concluída: {$checks} verificações.\n";
} finally {
    $server->exec('DROP DATABASE `' . $database . '`');
}
