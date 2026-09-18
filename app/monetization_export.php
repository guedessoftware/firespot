<?php

declare(strict_types=1);

function fs_monetization_export_date(?string $value,string $fallback):string
{
    $value=trim((string)$value);
    if($value==='')return $fallback;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    $errors=DateTimeImmutable::getLastErrors();
    if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Período de exportação inválido.');
    return $value;
}

function fs_monetization_export_rows(PDO $pdo,?int $partnerId,string $from,string $to,int $limit=20000):array
{
    $from=fs_monetization_export_date($from,date('Y-m-d',strtotime('-90 days')));
    $to=fs_monetization_export_date($to,date('Y-m-d'));
    if($to<$from)throw new InvalidArgumentException('A data final deve ser posterior à inicial.');
    $limit=min(50000,max(1,$limit));$params=[$from.' 00:00:00',$to.' 23:59:59'];
    try{$hotspotReady=(bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_deliveries' AND COLUMN_NAME='hotspot_id'")->fetchColumn();}
    catch(Throwable $e){$hotspotReady=false;}
    $hotspotSelect=$hotspotReady?'h.code hotspot_code,h.name hotspot_name':'NULL hotspot_code,NULL hotspot_name';
    $hotspotJoin=$hotspotReady?' LEFT JOIN ad_deliveries d ON d.public_id=l.source_id AND d.partner_id=l.partner_id LEFT JOIN partner_hotspots h ON h.id=d.hotspot_id AND h.partner_id=l.partner_id':'';
    $sql="SELECT l.id,l.occurred_at,p.code partner_code,p.name partner_name,{$hotspotSelect},c.name campaign_name,l.source_type,l.source_id,l.amount_cents,l.status,l.reason_code,l.settlement_id,l.approved_at,s.status settlement_status,s.paid_at settlement_paid_at,s.provider_reference FROM monetization_ledger l JOIN partners p ON p.id=l.partner_id{$hotspotJoin} LEFT JOIN ad_campaigns c ON c.id=l.campaign_id LEFT JOIN partner_settlements s ON s.id=l.settlement_id WHERE l.occurred_at BETWEEN ? AND ?";
    if($partnerId!==null){$sql.=' AND l.partner_id=?';$params[]=$partnerId;}
    $sql.=" ORDER BY l.occurred_at DESC,l.id DESC LIMIT {$limit}";$st=$pdo->prepare($sql);$st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_monetization_csv_cell($value):string
{
    $value=(string)$value;
    return preg_match('/^[=+\-@]/u',$value)?("'".$value):$value;
}

function fs_monetization_write_csv(array $rows):void
{
    $output=fopen('php://output','wb');if($output===false)throw new RuntimeException('Não foi possível gerar o arquivo.');
    fwrite($output,"\xEF\xBB\xBF");
    fputcsv($output,['ID','Data','Estabelecimento','Código','Instalação','Código da instalação','Campanha','Origem','Referência do evento','Valor (centavos)','Status','Motivo','Fechamento','Status do fechamento','Pago em','Referência do repasse'],';');
    foreach($rows as $row)fputcsv($output,array_map('fs_monetization_csv_cell',[
        $row['id'],$row['occurred_at'],$row['partner_name'],$row['partner_code'],$row['hotspot_name'],$row['hotspot_code'],$row['campaign_name'],$row['source_type'],$row['source_id'],$row['amount_cents'],$row['status'],$row['reason_code'],$row['settlement_id'],$row['settlement_status'],$row['settlement_paid_at'],$row['provider_reference'],
    ]),';');
    fclose($output);
}
