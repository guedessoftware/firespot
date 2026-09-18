<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!admin_has_capability('partner.purpose.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão para validar finalidades.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}
admin_require_csrf($_POST, true);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/partner_admin.php';
require_once __DIR__ . '/../../app/partner_purpose.php';

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $partnerId = (int)($_POST['partner_id'] ?? 0);
    $purpose = trim((string)($_POST['access_purpose'] ?? ''));
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido.');
    if (!array_key_exists($purpose, partner_purpose_labels())) throw new InvalidArgumentException('Selecione uma finalidade válida.');

    $st = $pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1');
    $st->execute([$partnerId]);
    $partner = $st->fetch();
    if (!$partner) throw new RuntimeException('Estabelecimento não encontrado.');

    // Estes dois controles pertencem ao mesmo formulário da finalidade. A
    // pré-validação precisa considerar o que está na tela, ainda não o banco.
    $partner['ads_enabled'] = isset($_POST['ads_enabled']) ? 1 : 0;
    $partner['allow_global_ads'] = isset($_POST['allow_global_ads']) ? 1 : 0;

    $readiness = partner_purpose_readiness($pdo, $partner, $purpose);
    unset($readiness['policy']);

    echo json_encode([
        'ok' => true,
        'current_purpose' => (string)($partner['access_purpose'] ?? ''),
        'current_label' => partner_purpose_label((string)($partner['access_purpose'] ?? '')),
        'readiness' => $readiness,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 400);
    echo json_encode([
        'ok' => false,
        'error' => partner_admin_public_error($e, 'Não foi possível validar esta finalidade.'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
