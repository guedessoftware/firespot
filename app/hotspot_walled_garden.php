<?php

declare(strict_types=1);

/**
 * Hosts observados e validados no fluxo de pagamento do Portal V3.
 *
 * Regras exatas são usadas porque o RouterOS 7.23.2 marcou como inválidos os
 * curingas cadastrados em /ip hotspot walled-garden ip.
 */
function fs_hotspot_payment_hosts(): array
{
    return [
        ['host' => 'mercadopago.com', 'comment' => 'FireSpot Payment MP'],
        ['host' => 'www.mercadopago.com', 'comment' => 'FireSpot Payment MP Web'],
        ['host' => 'sdk.mercadopago.com', 'comment' => 'FireSpot Payment SDK'],
        ['host' => 'api.mercadopago.com', 'comment' => 'FireSpot Payment API'],
        ['host' => 'mercadopago.com.br', 'comment' => 'FireSpot Payment BR'],
        ['host' => 'www.mercadopago.com.br', 'comment' => 'FireSpot Payment Checkout'],
        ['host' => 'www.mercadolibre.com', 'comment' => 'FireSpot Payment Web'],
        ['host' => 'api.mercadolibre.com', 'comment' => 'FireSpot Payment ML API'],
        ['host' => 'http2.mlstatic.com', 'comment' => 'FireSpot Payment Assets'],
    ];
}

function fs_hotspot_obsolete_payment_hosts(): array
{
    return [
        '*.mercadopago.com',
        '*.mercadopago.com.br',
        '*.mlstatic.com',
    ];
}

/**
 * Comandos idempotentes usados na aplicação automática da configuração.
 */
function fs_hotspot_payment_walled_garden_commands(): array
{
    $commands = [];
    foreach (fs_hotspot_obsolete_payment_hosts() as $host) {
        $commands[] = '/ip hotspot walled-garden ip remove [find where dst-host="' . $host . '"]';
    }
    foreach (fs_hotspot_payment_hosts() as $rule) {
        $commands[] = '/ip hotspot walled-garden ip remove [find where dst-host="' . $rule['host'] . '"]';
        $commands[] = '/ip hotspot walled-garden ip add action=accept comment="' . $rule['comment'] . '" disabled=no dst-host="' . $rule['host'] . '"';
    }
    return $commands;
}

/**
 * Bloco compartilhado com o script exibido para instalação manual.
 */
function fs_hotspot_payment_walled_garden_config(): string
{
    $lines = [];
    foreach (fs_hotspot_payment_hosts() as $rule) {
        $lines[] = 'add action=accept comment="' . $rule['comment'] . '" disabled=no dst-host="' . $rule['host'] . '"';
    }
    return implode("\n", $lines);
}
