#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * FireSpot — limpeza de tokens de login
 * - Remove tokens expirados (expires_at < NOW())
 * - Remove tokens usados há > 1 dia (opcional, retenção curta p/ auditoria)
 * - Evita concorrência via GET_LOCK
 * - Força fuso -04:00
 * Uso manual: php cleanup_login_tokens.php --verbose
 */

@ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';

$VERBOSE = in_array('--verbose', $argv ?? [], true);

function logv($msg)
{
    global $VERBOSE;
    if ($VERBOSE)
        fwrite(STDOUT, date('Y-m-d H:i:s') . " " . $msg . PHP_EOL);
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Fuso local (America/Manaus, UTC-04:00)
    $pdo->exec("SET time_zone='-04:00'");

    // Lock global para evitar corridas se o timer disparar em paralelo
    $stmt = $pdo->query("SELECT GET_LOCK('firespot_login_tokens_cleanup', 5) AS got");
    $got = (int) ($stmt->fetchColumn() ?: 0);
    if ($got !== 1) {
        logv("[skip] não obteve lock (outra execução em andamento).");
        exit(0);
    }

    // 1) Deleta expirados — tokens são gerados com UTC_TIMESTAMP(), então compare com UTC
    $del1 = $pdo->prepare("DELETE FROM login_tokens WHERE expires_at < UTC_TIMESTAMP()");
    $del1->execute();
    $n1 = $del1->rowCount();

    // 2) Deleta tokens já utilizados há mais de 1 dia (retenção mínima)
    $del2 = $pdo->prepare("DELETE FROM login_tokens WHERE used_at IS NOT NULL AND used_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
    $del2->execute();
    $n2 = $del2->rowCount();

    // 3) (opcional) higieniza prévias muito antigas que nunca foram usadas, > 7 dias
    $del3 = $pdo->prepare("DELETE FROM login_tokens WHERE used_at IS NULL AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)");
    $del3->execute();
    $n3 = $del3->rowCount();

    logv("[ok] expirados: {$n1}, usados>1d: {$n2}, velhos>7d: {$n3}");

} catch (Throwable $e) {
    // registra no journal
    fwrite(STDERR, "[cleanup_login_tokens] " . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        $pdo->query("SELECT RELEASE_LOCK('firespot_login_tokens_cleanup')");
    } catch (Throwable $e) {
    }
}
