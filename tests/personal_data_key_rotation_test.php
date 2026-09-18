<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = 0;
function personal_rotation_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$rotation = (string) file_get_contents($root . '/app/cli/rotate_personal_data_key.php');
$installer = (string) file_get_contents('/opt/firespot-ops/firespot_rotate_personal_data_key_root.sh');

personal_rotation_expect(str_contains($rotation, "PHP_SAPI !== 'cli'"), 'A rotina precisa ser exclusivamente CLI.');
personal_rotation_expect(str_contains($rotation, "posix_geteuid() !== 0"), 'A aplicação da rotação precisa exigir root.');
personal_rotation_expect(str_contains($rotation, 'PERSONAL_DATA_KEY_ROTATION_PENDING'), 'A rotina precisa exigir o marcador de migração.');
personal_rotation_expect(str_contains($rotation, 'DRY-RUN OK'), 'A rotina precisa possuir validação sem escrita.');
personal_rotation_expect(str_contains($rotation, "status='pending' AND expires_at>NOW()"), 'Desafios OTP ativos precisam bloquear a janela.');
personal_rotation_expect(str_contains($rotation, "status='created' AND expires_at>NOW()"), 'Convites ativos precisam bloquear a janela.');
personal_rotation_expect(str_contains($rotation, "status IN ('pending','sending')"), 'Mensagens em processamento precisam bloquear a janela.');
foreach (['subscriber_external_links', 'promo_queue', 'ad_leads'] as $table) {
    personal_rotation_expect(str_contains($rotation, "'{$table}'"), 'A rotina não cobre a tabela criptografada ' . $table . '.');
}
personal_rotation_expect(str_contains($rotation, 'Há identificadores MAC ativos'), 'A rotina precisa parar se não puder preservar a identificação MAC.');
personal_rotation_expect(str_contains($rotation, '$pdo->beginTransaction()'), 'As mudanças precisam usar transação.');
personal_rotation_expect(str_contains($rotation, '$pdo->rollBack()'), 'A transação precisa ter rollback.');
personal_rotation_expect(str_contains($rotation, "target_encrypted='PURGED'"), 'Desafios encerrados precisam descartar o destino pessoal.');
personal_rotation_expect(str_contains($rotation, 'revoked_at=COALESCE(revoked_at,NOW())'), 'Cookies persistentes derivados da chave antiga precisam ser revogados.');
personal_rotation_expect(str_contains($rotation, "identifier_type<>'mac'"), 'Identificadores não recuperáveis precisam ser invalidados.');
personal_rotation_expect(str_contains($rotation, 'PERSONAL_DATA_KEY_ROTATED_AT'), 'A conclusão precisa ficar registrada sem expor a chave.');
personal_rotation_expect(!str_contains($rotation, 'echo $oldKey') && !str_contains($rotation, 'echo $newKey'), 'A rotina não pode imprimir chaves.');

personal_rotation_expect(str_contains($installer, 'mariadb-dump --protocol=socket'), 'O instalador precisa preservar as tabelas antes da rotação.');
personal_rotation_expect(str_contains($installer, 'chmod -R go-rwx'), 'O backup precisa ficar restrito.');
foreach (['apache2', 'promo-worker', 'cron'] as $service) {
    personal_rotation_expect(str_contains($installer, "systemctl stop {$service}"), 'O escritor concorrente ' . $service . ' precisa ser interrompido.');
}
personal_rotation_expect(str_contains($installer, 'trap restore_services EXIT'), 'Os serviços precisam ser restaurados também em falha.');
personal_rotation_expect(str_contains($installer, '--apply'), 'O instalador precisa executar a etapa efetiva após o dry-run.');
personal_rotation_expect(str_contains($installer, 'runuser -u www-data'), 'A leitura final precisa ser testada como usuário web.');

echo "OK: {$checks} verificações da rotação da chave de dados pessoais.\n";
