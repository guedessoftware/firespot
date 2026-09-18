#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../db.php';require_once __DIR__ . '/../partner_analytics.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$dateOption=null;$backfillDays=null;
foreach($argv as $arg){
    if(str_starts_with($arg,'--date='))$dateOption=substr($arg,7);
    elseif(str_starts_with($arg,'--backfill-days=')){
        $raw=substr($arg,16);if(!ctype_digit($raw)||(int)$raw<1||(int)$raw>366)throw new InvalidArgumentException('O backfill deve ficar entre 1 e 366 dias.');$backfillDays=(int)$raw;
    }
}
if($dateOption!==null&&$backfillDays!==null)throw new InvalidArgumentException('Use --date ou --backfill-days, nunca os dois juntos.');
$timezone=new DateTimeZone('America/Manaus');$today=new DateTimeImmutable('today',$timezone);
if($dateOption!==null)$dates=[fs_partner_analytics_date($dateOption)];
elseif($backfillDays!==null){$dates=[];$start=$today->modify('-'.($backfillDays-1).' days');for($date=$start;$date<=$today;$date=$date->modify('+1 day'))$dates[]=$date->format('Y-m-d');}
else $dates=[$today->modify('-1 day')->format('Y-m-d'),$today->format('Y-m-d')];
$total=0;foreach($dates as $date){$result=fs_partner_analytics_rollup($pdo,$date);$total+=(int)$result['hotspots'];}echo 'dates='.count($dates).' hotspots='.$total.PHP_EOL;
