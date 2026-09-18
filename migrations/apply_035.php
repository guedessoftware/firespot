<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$sql=file_get_contents(__DIR__.'/035_subscriber_revalidation.sql');if($sql===false)throw new RuntimeException('Não foi possível ler a migração 035.');db()->exec($sql);echo "Migração 035 aplicada. Revalidação criptografada habilitada.\n";

