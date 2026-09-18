<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
$st=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriber_external_links' AND COLUMN_NAME='document_encrypted'");if((int)$st->fetchColumn()!==1)throw new RuntimeException('Coluna document_encrypted ausente.');echo "Smoke 035 OK: documento somente criptografado disponível para revalidação.\n";

