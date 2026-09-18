<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/control_center_navigation.php';

// Compatibilidade com favoritos antigos: instalações agora pertencem à
// edição de cada estabelecimento e não possuem mais uma área global.
$partnerId = max(0,(int)($_GET['partner_id'] ?? $_GET['id'] ?? 0));
$hotspotId = max(0,(int)($_GET['hotspot_id'] ?? $_GET['point_id'] ?? 0));
$destination = $partnerId > 0
    ? fs_control_center_partner_url($partnerId,'points',$hotspotId > 0 ? ['point_id'=>$hotspotId] : [])
    : 'estabelecimentos.php';
header('Location: ' . $destination,true,302);
exit;
