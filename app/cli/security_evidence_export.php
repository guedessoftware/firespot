<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/db.php';

$outputDirectory='';
foreach(array_slice($argv,1) as $argument){if(str_starts_with($argument,'--output-dir='))$outputDirectory=substr($argument,13);}
if($outputDirectory===''||$outputDirectory[0]!=='/')throw new InvalidArgumentException('Informe --output-dir com caminho absoluto.');
if(!is_dir($outputDirectory)&&!mkdir($outputDirectory,0700,true)&&!is_dir($outputDirectory))throw new RuntimeException('Não foi possível criar o diretório de evidências do banco.');
chmod($outputDirectory,0700);

$pdo=db();$tables=['radacct','radpostauth','nas'];$exported=[];
foreach($tables as $table){
    $exists=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$exists->execute([$table]);if(!$exists->fetchColumn())continue;
    $path=$outputDirectory.'/'.$table.'.jsonl.gz';$handle=gzopen($path,'wb9');if($handle===false)throw new RuntimeException('Não foi possível abrir a evidência '.$table.'.');
    $count=0;
    try{
        $statement=$pdo->query('SELECT * FROM `'.$table.'`',PDO::FETCH_ASSOC);
        while($row=$statement->fetch(PDO::FETCH_ASSOC)){$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(gzwrite($handle,$json."\n")===false)throw new RuntimeException('Falha ao gravar a evidência '.$table.'.');$count++;}
    }finally{gzclose($handle);}
    chmod($path,0600);$exported[$table]=$count;
}
$manifest=$outputDirectory.'/COUNTS.json';file_put_contents($manifest,json_encode($exported,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);chmod($manifest,0600);
echo 'Evidência SQL exportada: '.implode(', ',array_map(static fn(string $table,int $count):string=>$table.'='.$count,array_keys($exported),$exported)).".\n";
