<?php

declare(strict_types=1);

/**
 * Contadores agregados do funil público. Não recebe nem armazena identidade,
 * MAC, IP, cookie, sessão ou payload do visitante.
 */
function fs_partner_portal_metric_event(PDO $pdo, array $partner, string $eventCode): void
{
    $allowed=['portal_open','options_view','paid_options_view','checkout_view','courtesy_selection','subscriber_selection'];
    if(!in_array($eventCode,$allowed,true))return;
    $partnerId=(int)($partner['id']??0);
    $hotspotId=(int)(function_exists('fs_partner_hotspot_id')?(fs_partner_hotspot_id($partner)??0):0);
    if($partnerId<=0||$hotspotId<=0)return;
    try{
        $metricDate=(new DateTimeImmutable('now',new DateTimeZone('America/Manaus')))->format('Y-m-d');
        $statement=$pdo->prepare('INSERT INTO partner_portal_daily_events (metric_date,partner_id,hotspot_id,event_code,event_count) SELECT ?,h.partner_id,h.id,?,1 FROM partner_hotspots h WHERE h.id=? AND h.partner_id=? AND h.active=1 ON DUPLICATE KEY UPDATE event_count=event_count+1,updated_at=NOW()');
        $statement->execute([$metricDate,$eventCode,$hotspotId,$partnerId]);
    }catch(Throwable $error){
        // Compatibilidade entre o deploy do código e a migração 048. Métrica
        // nunca pode interromper o acesso do visitante.
    }
}
