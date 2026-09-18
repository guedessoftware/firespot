<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/ad_platform.php';

$options=getopt('',['file:','dry-run']);
$file=trim((string)($options['file']??''));$dryRun=array_key_exists('dry-run',$options);
if($file===''||!is_file($file)||!is_readable($file)){
    fwrite(STDERR,"Uso: php app/cli/ad_platform_revenue_import.php --file=/caminho/relatorio.csv [--dry-run]\n");
    exit(2);
}

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if(!fs_ad_platform_schema_ready($pdo))throw new RuntimeException('A migração 057 ainda não foi aplicada.');
$handle=fopen($file,'rb');if(!$handle)throw new RuntimeException('Não foi possível abrir o relatório.');
$headers=fgetcsv($handle);$required=['date','placement_code','impressions','clicks','rewarded_completed','estimated_cents'];
if(!is_array($headers))throw new RuntimeException('O CSV está vazio.');
$headers=array_map(static fn($value):string=>strtolower(trim((string)$value)),$headers);
foreach($required as $column)if(!in_array($column,$headers,true))throw new RuntimeException('Coluna obrigatória ausente: '.$column);
$index=array_flip($headers);$rows=[];$line=1;
while(($data=fgetcsv($handle))!==false){
    $line++;if(count($data)===1&&trim((string)$data[0])==='')continue;
    $get=static fn(string $name,string $default=''):string=>trim((string)($data[$index[$name]??-1]??$default));
    $date=$get('date');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$errors=DateTimeImmutable::getLastErrors();
    if(!$parsed||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$parsed->format('Y-m-d')!==$date)throw new RuntimeException("Data inválida na linha {$line}.");
    $placement=$get('placement_code');if(!in_array($placement,fs_ad_platform_placements(),true))throw new RuntimeException("Posicionamento inválido na linha {$line}.");
    $partnerId=max(0,(int)$get('partner_id'));$hotspotId=max(0,(int)$get('hotspot_id'));
    if($hotspotId>0&&$partnerId<=0)throw new RuntimeException("hotspot_id exige partner_id na linha {$line}.");
    $integer=static function(string $name)use($get,$line):int{$value=$get($name,'0');if(!preg_match('/^-?[0-9]+$/',$value))throw new RuntimeException("Valor inteiro inválido em {$name}, linha {$line}.");return (int)$value;};
    $row=['date'=>$date,'placement'=>$placement,'partner_id'=>$partnerId?:null,'hotspot_id'=>$hotspotId?:null,'impressions'=>$integer('impressions'),'clicks'=>$integer('clicks'),'rewarded_completed'=>$integer('rewarded_completed'),'estimated_cents'=>$integer('estimated_cents'),'finalized_cents'=>$get('finalized_cents')===''?null:$integer('finalized_cents'),'invalid_adjustment_cents'=>$integer('invalid_adjustment_cents')];
    foreach(['impressions','clicks','rewarded_completed','estimated_cents'] as $field)if($row[$field]<0)throw new RuntimeException("{$field} não pode ser negativo na linha {$line}.");
    $rows[]=$row;
}
fclose($handle);
if(!$rows)throw new RuntimeException('O relatório não contém linhas de dados.');

$sourceChecksum=hash_file('sha256',$file);$pdo->beginTransaction();$imported=0;
try{
    $placementQuery=$pdo->prepare('SELECT id FROM ad_platform_placements WHERE code=? LIMIT 1');
    $scopeQuery=$pdo->prepare('SELECT 1 FROM partner_hotspots WHERE id=? AND partner_id=? LIMIT 1');
    $upsert=$pdo->prepare("INSERT INTO platform_ad_revenue_daily (dimension_key,revenue_date,provider_code,placement_id,partner_id,hotspot_id,impressions,clicks,rewarded_completed,estimated_cents,finalized_cents,invalid_traffic_adjustment_cents,state,source_checksum,imported_at) VALUES (?,?,'google_ad_manager',?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE impressions=VALUES(impressions),clicks=VALUES(clicks),rewarded_completed=VALUES(rewarded_completed),estimated_cents=VALUES(estimated_cents),finalized_cents=VALUES(finalized_cents),invalid_traffic_adjustment_cents=VALUES(invalid_traffic_adjustment_cents),state=VALUES(state),source_checksum=VALUES(source_checksum),imported_at=NOW(),updated_at=NOW()");
    foreach($rows as $row){
        $placementQuery->execute([$row['placement']]);$placementId=(int)$placementQuery->fetchColumn();if($placementId<=0)throw new RuntimeException('Posicionamento não cadastrado: '.$row['placement']);
        if($row['hotspot_id']){$scopeQuery->execute([$row['hotspot_id'],$row['partner_id']]);if(!$scopeQuery->fetchColumn())throw new RuntimeException('Ponto não pertence ao estabelecimento informado.');}
        $dimension=hash('sha256',implode('|',[$row['date'],'google_ad_manager',$placementId,$row['partner_id']??0,$row['hotspot_id']??0]));
        $state=$row['finalized_cents']===null?'estimated':'finalized';
        $upsert->execute([$dimension,$row['date'],$placementId,$row['partner_id'],$row['hotspot_id'],$row['impressions'],$row['clicks'],$row['rewarded_completed'],$row['estimated_cents'],$row['finalized_cents'],$row['invalid_adjustment_cents'],$state,$sourceChecksum]);
        $imported++;
    }
    if($dryRun)$pdo->rollBack();else$pdo->commit();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}

echo ($dryRun?'VALIDADO':'IMPORTADO').": {$imported} linha(s); receita Google atribuída à FireSpot.\n";
