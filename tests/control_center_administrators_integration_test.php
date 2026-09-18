<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/control_center_administrators.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$filters = fs_control_center_administrator_filters(['q'=>str_repeat('x', 150), 'page'=>'0']);
$queryLength = function_exists('mb_strlen') ? mb_strlen($filters['q'], 'UTF-8') : strlen($filters['q']);
$expect($queryLength === 100 && $filters['page'] === 1 && $filters['per_page'] === 50, 'Filtros da supervisão não foram limitados.');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$expected = (int)$pdo->query('SELECT COUNT(*) FROM partner_admin_memberships')->fetchColumn();
$result = fs_control_center_administrator_supervision($pdo);
$expect($result['total'] === $expected, 'Total de vínculos não concilia com o banco.');
$expect(count($result['rows']) <= 50 && $result['pages'] >= 1, 'Supervisão não respeita paginação no servidor.');
foreach ($result['rows'] as $row) {
    $expect((int)$row['partner_id'] > 0, 'Vínculo sem estabelecimento válido.');
    foreach (['password_hash','auth_version','token_hash'] as $secretField) $expect(!array_key_exists($secretField, $row), 'Supervisão retornou campo sensível: ' . $secretField . '.');
}

$missing = fs_control_center_administrator_supervision($pdo, ['q'=>'__firespot_missing_supervision_fixture__']);
$expect($missing['total'] === 0 && $missing['rows'] === [], 'Busca inexistente não retornou estado vazio.');
$invitations = fs_control_center_pending_administrator_invitations($pdo);
foreach ($invitations as $invitation) {
    $expect(!array_key_exists('token_hash', $invitation) && !array_key_exists('id', $invitation), 'Convite global expôs identificador ou token técnico.');
}

echo 'OK: ' . $checks . " verificações da supervisão global de administradores.\n";
