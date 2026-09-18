<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/portal_configuration.php';
if (env('APP_ENV') !== 'local' || env('DB_DATABASE') !== 'firespot_local' || env('FIRESPOT_LAB') !== '1') exit(64);
$preset=$argv[1]??'hybrid';
if (!in_array($preset,['hybrid','paid','free_quick','free_sponsored'],true)) throw new InvalidArgumentException('Preset inválido.');
$pdo=db();
$id=(int)$pdo->query("SELECT id FROM partners WHERE code='FIRESPOT-LAB'")->fetchColumn();
if(!$id) throw new RuntimeException('Fixtures não instaladas.');
$pdo->beginTransaction();
try {
    fs_portal_config_apply_preset($pdo,$id,$preset);
    fs_portal_config_save_draft($pdo,$id,['subscriber_access_mode'=>'deny','welcome_screen_enabled'=>1,'single_option_direct_enabled'=>0]);
    $config=fs_portal_config_publish($pdo,$id);
    $pdo->prepare("UPDATE partner_hotspot_commercial_policies SET paid_access_enabled=?,courtesy_mode=? WHERE partner_id=? AND hotspot_id IN (SELECT id FROM partner_hotspots WHERE partner_id=? AND code IN ('LAB-A','LAB-B'))")->execute([(int)$config['paid_access_enabled'],$config['courtesy_mode'],$id,$id]);
    $pdo->commit();
} catch(Throwable $error) { if($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
echo "Preset publicado no laboratório: {$preset}.\n";
