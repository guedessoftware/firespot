#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../subscriber_retention.php';

echo json_encode(['status'=>'ok','retention'=>fs_subscriber_retention_run(db())],JSON_UNESCAPED_SLASHES),PHP_EOL;
