<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/portal_configuration.php';
require_once __DIR__ . '/../../app/courtesy_rollout.php';
if (env('APP_ENV') !== 'local' || env('DB_DATABASE') !== 'firespot_local' || env('DB_HOST') !== 'db' || env('FIRESPOT_LAB') !== '1') exit(64);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// A separate Compose project/volume prevents importing customers or changing a production NAS.
$marker = $pdo->query("SELECT svalue FROM app_settings WHERE skey='firespot_lab_seeded'")->fetchColumn();
if ($marker === '1') { echo "Fixtures do laboratório preservadas.\n"; return; }
foreach (['partners','nas','guest_orders'] as $table) {
    if ((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) throw new RuntimeException('Fixtures exigem banco local vazio de negócios.');
}
$radiusSecret = trim((string)file_get_contents('/run/secrets/chr_radius_secret'));
$adminPassword = trim((string)file_get_contents('/run/secrets/chr_admin_password'));
if (!preg_match('/^[a-f0-9]{48}$/D',$radiusSecret) || !preg_match('/^[a-f0-9]{48}$/D',$adminPassword)) throw new RuntimeException('Segredos do CHR inválidos.');
$pdo->beginTransaction();
try {
    $st=$pdo->prepare("INSERT INTO nas (nasname,shortname,type,secret,server,mgmt_username,mgmt_password,mgmt_port) VALUES ('10.203.30.2','FireSpot-LAB','mikrotik',?,NULL,'admin',?,22)");
    $st->execute([$radiusSecret,$adminPassword]); $nasId=(int)$pdo->lastInsertId();
    $st=$pdo->prepare("INSERT INTO radius_servers (name,host,port,secret) VALUES ('RADIUS laboratório','10.203.30.4',1812,?)"); $st->execute([$radiusSecret]); $radiusId=(int)$pdo->lastInsertId();
    $st=$pdo->prepare("INSERT INTO nas_interfaces (nas_id,interface_name,interface_type,description) VALUES (?,'ether3','ether','Trunk VLAN 10 e 20 do laboratório')"); $st->execute([$nasId]); $interfaceId=(int)$pdo->lastInsertId();
    $st=$pdo->prepare("INSERT INTO partners (code,name,require_auth,portal_mode,access_purpose,nas_id,nas_interface_id,radius_ip,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name) VALUES ('FIRESPOT-LAB','FireSpot — Laboratório',0,'v3','hybrid',?,?,'10.203.30.4',10,'10.203.10.1','10.203.10.100','10.203.10.199','10.203.10.1','lab-a.hotspot.internal')");
    $st->execute([$nasId,$interfaceId]); $partnerId=(int)$pdo->lastInsertId();
    $st=$pdo->prepare("INSERT INTO partner_hotspots (partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    foreach ([10=>'A',20=>'B'] as $vlan=>$letter) {
        $st->execute([$partnerId,'LAB-'.$letter,'Cliente '.$letter.' — VLAN '.$vlan,$nasId,$interfaceId,$vlan,"10.203.{$vlan}.1","10.203.{$vlan}.100","10.203.{$vlan}.199","10.203.{$vlan}.1",'lab-'.strtolower($letter).'.hotspot.internal','10.203.30.4',$vlan===10?1:0]);
        $hotspotId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO partner_hotspot_commercial_policies (hotspot_id,partner_id,paid_access_enabled,courtesy_mode,payment_window_enabled,payment_window_minutes,payment_window_daily_limit,payment_window_cooldown_minutes,payment_window_period_minutes,updated_by_type) VALUES (?,?,1,'direct',1,2,3,10,1440,'firespot')")->execute([$hotspotId,$partnerId]);
    }
    $pdo->prepare("INSERT INTO partner_payment_plans (partner_id,name,description,price_cents,duration_minutes,download_kbps,upload_kbps,active) VALUES (?,'Plano do laboratório','Pagamento simulado, sem cobrança',500,5,512,256,1)")->execute([$partnerId]);
    $setting=$pdo->prepare('INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    foreach (['courtesy_cutover_enabled'=>'1','courtesy_radius_ready'=>'1'] as $key=>$value) $setting->execute([$key,$value]);
    fs_courtesy_policy_save($pdo,$partnerId,array_merge(fs_courtesy_policy_defaults(),['auth_mode'=>'anonymous','grant_minutes'=>2,'credit_validity_minutes'=>10]),true);
    fs_courtesy_rollout_save($pdo,$partnerId,'v3','enforce','laboratorio');
    $media='/assets/ads/firespot-lab.svg';
    if (!copy(__DIR__.'/ad.svg','/var/www/html'.$media)) throw new RuntimeException('Falha ao instalar anúncio demonstrativo.');
    $pdo->prepare("INSERT INTO custom_ads (title,media_type,media_url,image_url,duration_sec,partner_id,partner_code,ad_kind,active) VALUES ('Anúncio demonstrativo FireSpot','image',?,?,5,?,'FIRESPOT-LAB','owned',1)")->execute([$media,$media,$partnerId]);
    fs_portal_config_apply_preset($pdo,$partnerId,'hybrid');
    fs_portal_config_save_draft($pdo,$partnerId,['subscriber_access_mode'=>'deny','welcome_screen_enabled'=>1,'single_option_direct_enabled'=>0]);
    fs_portal_config_publish($pdo,$partnerId);
    $setting->execute(['firespot_lab_seeded','1']);
    $pdo->commit();
} catch (Throwable $error) { if($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
echo "LAB-A e LAB-B criados, cortesia de 2 minutos, plano simulado de 5 minutos.\n";
