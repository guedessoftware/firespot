<?php
declare(strict_types=1);

require_once __DIR__ . '/session_boot.php';

/**
 * Mantém na sessão apenas a prova de que este navegador criou a ordem.
 * A referência não é armazenada em texto puro e a lista possui prazo e limite.
 */
function vip_order_session_bind(string $externalRef): void
{
    $externalRef = trim($externalRef);
    if ($externalRef === '') {
        return;
    }

    vip_order_session_prune();
    $_SESSION['_vip_order_refs'][hash('sha256', $externalRef)] = time();

    if (count($_SESSION['_vip_order_refs']) > 20) {
        asort($_SESSION['_vip_order_refs'], SORT_NUMERIC);
        $_SESSION['_vip_order_refs'] = array_slice($_SESSION['_vip_order_refs'], -20, null, true);
    }
}

function vip_order_session_owns(string $externalRef): bool
{
    $externalRef = trim($externalRef);
    if ($externalRef === '') {
        return false;
    }

    vip_order_session_prune();
    return isset($_SESSION['_vip_order_refs'][hash('sha256', $externalRef)]);
}

function vip_order_session_prune(): void
{
    $refs = $_SESSION['_vip_order_refs'] ?? [];
    if (!is_array($refs)) {
        $_SESSION['_vip_order_refs'] = [];
        return;
    }

    $minimumTimestamp = time() - 86400;
    foreach ($refs as $hash => $createdAt) {
        if (!is_string($hash) || !is_numeric($createdAt) || (int) $createdAt < $minimumTimestamp) {
            unset($refs[$hash]);
        }
    }
    $_SESSION['_vip_order_refs'] = $refs;
}
