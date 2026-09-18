<?php
// SEC-004: operação RADIUS arbitrária retirada do caminho público.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'endpoint_retired']);
exit;

@ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../app/config.php'; // $config
require_once __DIR__ . '/radius_vip.php'; // usa rad_db()
require_once __DIR__ . '/../../app/lib/routeros.php';   // se quiser derrubar a sessão via MikroTik

$u = trim((string) ($_GET['u'] ?? $_POST['u'] ?? ''));
$grant_min = (int) ($_GET['grant_min'] ?? $_POST['grant_min'] ?? 0); // opcional: dar novo crédito após zerar

if ($u === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'username ausente']);
    exit;
}

try {
    $rad = rad_db();
    $rad->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // usado histórico
    $st = $rad->prepare("SELECT COALESCE(SUM(acctsessiontime),0) FROM radacct WHERE username=?");
    $st->execute([$u]);
    $used = (int) $st->fetchColumn();

    // define Max-All-Session = used (+ opcional grant)
    $new_allowed = $used + max(0, $grant_min * 60);

    // normaliza radcheck
    $rad->beginTransaction();
    $del = $rad->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Max-All-Session'");
    $del->execute([$u]);
    $ins = $rad->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)");
    $ins->execute([$u, 'Max-All-Session', (string) $new_allowed]);
    $rad->commit();

    // opcional: derruba sessão ativa para aplicar já
    try {
        // se você tem utilidades por user:
        ros_exec([
            "/ip hotspot active remove [find where user=$u]"
        ]);
    } catch (Throwable $e) { /* best effort */
    }

    echo json_encode([
        'ok' => true,
        'username' => $u,
        'used_seconds' => $used,
        'new_allowed' => $new_allowed,
        'remaining' => max(0, $new_allowed - $used)
    ]);
} catch (Throwable $e) {
    if (isset($rad) && $rad->inTransaction())
        $rad->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
}
