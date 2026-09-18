<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/partner_finance.php';

$tests = 0;
function partner_finance_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) throw new RuntimeException($message);
}

$today = new DateTimeImmutable('2026-08-10 12:00:00', new DateTimeZone('America/Manaus'));
$filters = partner_finance_filters([], $today);
partner_finance_expect($filters['from'] === '2026-07-12', 'Período padrão não inclui os últimos 30 dias.');
partner_finance_expect($filters['to'] === '2026-08-10', 'Data final padrão incorreta.');
partner_finance_expect($filters['date_until'] === '2026-08-11 00:00:00', 'Limite superior não é exclusivo.');
partner_finance_expect($filters['status'] === '' && $filters['page'] === 1 && $filters['hotspot_id'] === null, 'Filtros padrão incorretos.');

$filters = partner_finance_filters(['from'=>'2026-08-01','to'=>'2026-08-10','status'=>'paid','hotspot_id'=>'15','p'=>'3'], $today);
partner_finance_expect($filters['status'] === 'paid', 'Situação válida foi descartada.');
partner_finance_expect($filters['page'] === 3, 'Página válida foi descartada.');
partner_finance_expect($filters['date_from'] === '2026-08-01 00:00:00', 'Início do filtro incorreto.');
partner_finance_expect($filters['hotspot_id'] === 15, 'Instalação válida foi descartada.');

foreach ([
    ['from'=>'2026-02-30','to'=>'2026-08-10'],
    ['from'=>'2026-08-11','to'=>'2026-08-10'],
    ['from'=>'2025-01-01','to'=>'2026-08-10'],
    ['from'=>'2026-08-01','to'=>'2026-08-10','status'=>'approved'],
    ['from'=>'2026-08-01','to'=>'2026-08-10','hotspot_id'=>'0'],
    ['from'=>'2026-08-01','to'=>'2026-08-10','hotspot_id'=>'-1'],
    ['from'=>'2026-08-01','to'=>'2026-08-10','hotspot_id'=>'outro'],
] as $invalid) {
    try {
        partner_finance_filters($invalid, $today);
        $accepted = true;
    } catch (InvalidArgumentException $e) {
        $accepted = false;
    }
    partner_finance_expect(!$accepted, 'Filtro financeiro inválido foi aceito.');
}

partner_finance_expect(partner_finance_status_label('paid') === 'Pago', 'Status pago não foi traduzido.');
partner_finance_expect(partner_finance_status_label('unexpected') === 'Desconhecido', 'Status desconhecido não falhou de modo seguro.');
partner_finance_expect(partner_finance_status_class('payment_failed') === 'is-failed', 'Classe de falha incorreta.');
partner_finance_expect(partner_finance_payment_method_label('pix') === 'Pix', 'Pix não foi formatado.');
partner_finance_expect(partner_finance_payment_method_label(null) === 'Não informado', 'Pagamento ausente não foi tratado.');

echo "OK: {$tests} verificações do financeiro do estabelecimento.\n";
