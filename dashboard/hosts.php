<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
require_once __DIR__ . '/../app/control_center_navigation.php';

admin_require_page();

// Fachada de compatibilidade somente para favoritos e links GET antigos.
// Os antigos controladores misturavam vários domínios nesta rota; qualquer
// POST precisa usar agora uma ação canônica, com capacidade e CSRF próprios.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(410);
    header('Allow: GET');
    exit('A edição antiga de estabelecimentos foi encerrada. Reabra a conta pela Central FireSpot.');
}

$partnerId = max(0, (int)($_GET['partner_id'] ?? $_GET['id'] ?? 0));
$createRequested = (string)($_GET['mode'] ?? '') === 'create'
    || isset($_GET['create'])
    || isset($_GET['showCreate']);

if ($createRequested) {
    header('Location: estabelecimento.php?mode=create', true, 302);
    exit;
}

if ($partnerId <= 0) {
    header('Location: estabelecimentos.php', true, 302);
    exit;
}

$section = fs_control_center_legacy_partner_section((string)($_GET['central_tab'] ?? $_GET['tab'] ?? 'summary'));
$extra = [];
if ($section === 'points') {
    $pointId = max(0, (int)($_GET['point_id'] ?? $_GET['hotspot_id'] ?? 0));
    if ($pointId > 0) $extra['point_id'] = $pointId;
}

header('Location: ' . fs_control_center_partner_url($partnerId, $section, $extra), true, 302);
exit;
