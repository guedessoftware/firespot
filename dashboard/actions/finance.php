<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

$flash = ['ok'=>false,'message'=>'Não foi possível atualizar a política financeira.'];

try {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        throw new RuntimeException('Sessão expirada. Recarregue a página.');
    }
    admin_require_capability('system.settings.manage');
    if ((string)($_POST['action'] ?? '') !== 'pix_settings_save') {
        throw new InvalidArgumentException('Ação financeira inválida.');
    }

    $expireMinutes = (int)($_POST['payment_pix_expire_minutes'] ?? 10);
    if ($expireMinutes < 5 || $expireMinutes > 60) {
        throw new InvalidArgumentException('A validade do QR Code deve ficar entre 5 e 60 minutos.');
    }
    $checkoutInfo = trim((string)($_POST['payment_pix_checkout_info'] ?? ''));
    if (mb_strlen($checkoutInfo) > 1000) {
        throw new InvalidArgumentException('A orientação do checkout deve ter no máximo 1.000 caracteres.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        settings_set('payment_pix_enabled', !empty($_POST['payment_pix_enabled']) ? '1' : '0');
        settings_set('payment_pix_expire_minutes', (string)$expireMinutes);
        settings_set('payment_pix_checkout_info', $checkoutInfo);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $flash = ['ok'=>true,'message'=>'Política global do checkout Pix atualizada.'];
} catch (Throwable $error) {
    $flash = ['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar a política financeira.')];
}

$_SESSION['finance_flash'] = $flash;
header('Location: ../financeiro.php?section=settings', true, 303);
exit;
