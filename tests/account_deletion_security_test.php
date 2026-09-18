<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/account_deletion.php';
require_once __DIR__ . '/../app/db.php';

$checks = 0;
$expect = static function (bool $condition,string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$reference = account_deletion_reference('12345678901','subject');
$expect((bool)preg_match('/^anon:[a-f0-9]{48}$/',$reference),'Referência anônima inválida.');
$expect(strpos($reference,'12345678901') === false,'Referência contém o CPF original.');

$legacy = [
    'username' => '12345678901',
    'cliente_info' => ['cpf' => '12345678901','telefone' => '5592999999999'],
    'radcheck' => [['attribute' => 'Cleartext-Password','value' => 'segredo']],
    'vip_orders' => [['cpf' => '12345678901','email' => 'pessoa@example.test']],
    'radacct_summary' => ['total' => 7,'primeira' => '2025-01-01'],
];
$receipt = account_deletion_public_receipt($legacy,'2026-09-06 00:00:00');
$encoded = json_encode($receipt,JSON_THROW_ON_ERROR);
foreach (['12345678901','5592999999999','segredo','pessoa@example.test','Cleartext-Password'] as $forbidden) {
    $expect(strpos($encoded,$forbidden) === false,"Recibo preservou dado proibido: {$forbidden}");
}
$expect(($receipt['counts']['radcheck'] ?? -1) === 1,'Contagem RADIUS legada incorreta.');
$expect(($receipt['counts']['vip_orders'] ?? -1) === 1,'Contagem de pedidos legada incorreta.');
$expect(($receipt['counts']['radacct'] ?? -1) === 7,'Resumo de accounting legado incorreto.');

$source = (string)file_get_contents(__DIR__ . '/../portal/api/account_delete.php');
$expect(strpos($source,'SELECT * FROM clientes_info') === false,'Autosserviço voltou a capturar cadastro integral.');
$expect(strpos($source,"SELECT attribute, op, value FROM radcheck") === false,'Autosserviço voltou a capturar senha RADIUS.');
$expect(strpos($source,'account_deletion_execute(') !== false,'Autosserviço não usa o serviço central de exclusão.');

$admin = (string)file_get_contents(__DIR__ . '/../dashboard/api/user_delete.php');
$expect(strpos($admin,"admin_has_capability('customers.delete')") !== false,'Exclusão administrativa não exige capacidade dedicada.');
$expect(strpos($admin,'account_deletion_execute(') !== false,'Exclusão administrativa não usa o serviço central.');

$pdo = db();
$total = (int)$pdo->query('SELECT COUNT(*) FROM deleted_accounts_log')->fetchColumn();
$anonymous = (int)$pdo->query("SELECT COUNT(*) FROM deleted_accounts_log WHERE username LIKE 'anon:%'")->fetchColumn();
$unsafe = (int)$pdo->query("SELECT COUNT(*) FROM deleted_accounts_log
    WHERE payload LIKE '%Cleartext-Password%'
       OR payload LIKE '%\"cpf\":%'
       OR payload LIKE '%\"telefone\":%'
       OR payload LIKE '%\"email\":%'")->fetchColumn();
$expect($anonymous === $total,'Ainda existem titulares em claro no log de exclusões.');
$expect($unsafe === 0,'Ainda existem snapshots ou credenciais no log de exclusões.');
$visible = fetch_deleted_accounts($pdo,1,0);
$visibleJson = json_encode($visible,JSON_THROW_ON_ERROR);
$expect(strpos($visibleJson,'Cleartext-Password') === false,'Relatório administrativo devolveu credencial legada.');
$expect(!$visible || str_starts_with((string)$visible[0]['username'],'anon:'),'Relatório administrativo devolveu titular em claro.');

echo "Account deletion security OK: {$checks} verificações.\n";
