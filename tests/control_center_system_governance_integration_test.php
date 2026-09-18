<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/control_center_system.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$state = fs_control_center_system_governance($pdo);
$expect(array_keys($state) === ['schema_version','schema_registered','schema_target','schema_pending','hotspot_apply_pilot_approved','portal_v3_activation','privacy_job','subscriber_retention_job'], 'Contrato da governança divergiu.');
$registered = array_map('intval', $pdo->query("SELECT version FROM schema_migrations WHERE state IN ('adopted','applied') ORDER BY version")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$pending = array_values(array_diff(range(1, 55), $registered));
$expect($state['schema_target'] === 55 && $state['schema_registered'] === count($registered) && $state['schema_pending'] === $pending, 'Governança não conciliou o ledger atual.');
$expect($state['schema_version'] === ($registered ? max($registered) : 0), 'Versão instalada divergente do ledger.');
$expect($state['hotspot_apply_pilot_approved'] === false, 'Gate remoto não permanece fechado.');
$expect($state['portal_v3_activation'] === 'frozen', 'Ativação do Portal V3 não permanece congelada.');
foreach (['privacy_job','subscriber_retention_job'] as $job) {
    $expect(is_array($state[$job]) && isset($state[$job]['state'],$state[$job]['label']), 'Rotina de retenção ausente: ' . $job . '.');
}

$page = (string)file_get_contents($root . '/dashboard/configuracoes.php');
$expect(str_contains($page,'Versão, gates e retenção')&&str_contains($page,'nenhuma ação de ativação disponível'),'Configurações não expõe governança somente leitura.');
$expect(!str_contains($page,'partner_hotspot_apply_pilot_gate')&&!str_contains($page,'migrations.php" --apply'),'Página de governança tenta alterar gate ou schema.');

echo 'OK: ' . $checks . " verificações da governança global do Sistema.\n";
