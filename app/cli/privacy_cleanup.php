#!/usr/bin/env php
<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ad_monetization.php';
try{$count=fs_ad_anonymize_expired_leads(db());fwrite(STDOUT,"ad_leads_anonymized={$count}\n");exit(0);}catch(Throwable $e){fwrite(STDERR,"privacy_cleanup_failed=".get_class($e)."\n");exit(1);}
