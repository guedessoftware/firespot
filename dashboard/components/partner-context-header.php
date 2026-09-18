<?php

declare(strict_types=1);

require_once __DIR__ . '/status-pill.php';

/** @param array<string,mixed> $context */
function fs_cc_partner_context_header(array $context): string
{
    $partner = (array)($context['partner'] ?? []);
    $subscription = (array)($context['subscription'] ?? []);
    $limits = (array)($context['limits'] ?? []);
    $usage = (array)($context['usage'] ?? []);
    $hotspots = (array)($context['hotspots'] ?? []);
    $activePoints = count(array_filter($hotspots,static fn(array $row): bool => (int)($row['active'] ?? 0) === 1));
    $portalLabels = ['inherit'=>'Padrão global','classic'=>'Clássico','v2'=>'V2','v3'=>'V3'];
    $portal = $portalLabels[(string)($partner['portal_mode'] ?? 'inherit')] ?? 'Padrão global';
    $plan = trim((string)($subscription['plan_name'] ?? '')) ?: 'Sem plano vigente';
    $status = (int)($partner['active'] ?? 0) === 1 ? fs_cc_status_pill('Ativo','success') : fs_cc_status_pill('Inativo','neutral');
    $hotspotLimit = (int)($limits['max_hotspots'] ?? 0);
    $hotspotUsage = (int)($usage['max_hotspots'] ?? count($hotspots));
    $returnUrl = fs_control_center_partner_list_return($context['return_url'] ?? '');
    $sectionLabel = trim((string)($context['section_label'] ?? 'Resumo')) ?: 'Resumo';
    $associatedNas = [];
    foreach ($hotspots as $hotspot) {
        $nasId = (int)($hotspot['nas_id'] ?? 0);
        if ($nasId > 0) $associatedNas[$nasId] = true;
    }

    ob_start();
    ?>
    <header class="fs-cc-partner-header" aria-labelledby="partner-context-title">
      <nav class="fs-cc-breadcrumb" aria-label="Caminho do estabelecimento"><a href="<?=fs_cc_escape($returnUrl)?>">Estabelecimentos</a><span aria-hidden="true">/</span><span><?=fs_cc_escape($partner['name'] ?? '')?></span><span aria-hidden="true">/</span><strong aria-current="page"><?=fs_cc_escape($sectionLabel)?></strong></nav>
      <div class="fs-cc-partner-header__identity">
        <div><span class="fs-cc-eyebrow">Estabelecimento <?= fs_cc_escape($partner['code'] ?? '') ?></span><h2 id="partner-context-title"><?= fs_cc_escape($partner['name'] ?? '') ?></h2></div>
        <?= $status ?>
      </div>
      <dl class="fs-cc-partner-header__facts">
        <div><dt>Portal</dt><dd><?= fs_cc_escape($portal) ?></dd></div>
        <div><dt>Plano FireSpot</dt><dd><?= fs_cc_escape($plan) ?></dd></div>
        <div><dt>Pontos</dt><dd><?= $activePoints ?> ativo(s) · <?= $hotspotUsage ?>/<?= $hotspotLimit > 0 ? $hotspotLimit : '—' ?></dd></div>
        <div><dt>NAS vinculados</dt><dd><?= count($associatedNas) ?> · gestão FireSpot</dd></div>
        <div><dt>Recebimento</dt><dd><?= (int)($partner['independent_billing'] ?? 0) === 1 ? 'Independente' : 'FireSpot' ?></dd></div>
      </dl>
    </header>
    <?php
    return (string)ob_get_clean();
}
