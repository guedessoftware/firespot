<?php

// Compatibilidade: a rota antiga nunca apaga e nunca reativa. Ela apenas
// Desativa o anúncio pertencente ao estabelecimento, preservando eventos e métricas.
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../../app/partner_ads.php';

$pdo = host_db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!csrf_check($_POST['_csrf'] ?? $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Sessão expirada. Recarregue a página.');
        }
        $context = partner_admin_require_feature_context($pdo, 'ads.manage', 'ads', 'ads.manage', true);
        $adId = (int)($_POST['id'] ?? 0);
        $ad = partner_ads_owned($pdo, (int)$context['partner_id'], $adId);
        if (!$ad) throw new RuntimeException('Anúncio não encontrado.');
        $st = $pdo->prepare('UPDATE custom_ads SET active=0 WHERE id=? AND partner_id=?');
        $st->execute([$adId, (int)$context['partner_id']]);
        partner_admin_audit($pdo, (int)$context['partner_id'], 'partner_admin', (int)$context['user_id'], 'ad.deactivated', 'ad', $adId, ['legacy_delete_route' => true]);
        host_flash('success', 'Anúncio desativado; as métricas foram preservadas.');
    } catch (Throwable $e) {
        host_flash('error', partner_admin_public_error($e));
    }
}
host_redirect('ads');
