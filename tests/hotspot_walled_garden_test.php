<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/hotspot_walled_garden.php';

$expected = [
    'mercadopago.com',
    'www.mercadopago.com',
    'sdk.mercadopago.com',
    'api.mercadopago.com',
    'mercadopago.com.br',
    'www.mercadopago.com.br',
    'www.mercadolibre.com',
    'api.mercadolibre.com',
    'http2.mlstatic.com',
];
$hosts = array_column(fs_hotspot_payment_hosts(), 'host');
if ($hosts !== $expected) throw new RuntimeException('A lista padrão de hosts de pagamento foi alterada incorretamente.');
if (count($hosts) !== count(array_unique($hosts))) throw new RuntimeException('Há hosts de pagamento duplicados.');

$commands = implode("\n", fs_hotspot_payment_walled_garden_commands());
$config = fs_hotspot_payment_walled_garden_config();
foreach ($expected as $host) {
    if (substr_count($commands, 'dst-host="' . $host . '"') !== 2) {
        throw new RuntimeException('Aplicação automática não substitui de forma idempotente: ' . $host);
    }
    if (substr_count($config, 'dst-host="' . $host . '"') !== 1) {
        throw new RuntimeException('Script manual não contém exatamente uma regra para: ' . $host);
    }
}
foreach (fs_hotspot_obsolete_payment_hosts() as $host) {
    if (strpos($commands, 'remove [find where dst-host="' . $host . '"]') === false) {
        throw new RuntimeException('Regra curinga obsoleta não será removida: ' . $host);
    }
    if (strpos($config, $host) !== false) {
        throw new RuntimeException('Script manual ainda contém regra curinga inválida: ' . $host);
    }
}

echo "Walled garden de pagamentos validado: " . count($expected) . " hosts.\n";
