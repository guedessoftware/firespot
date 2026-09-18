<?php

declare(strict_types=1);

// Bibliotecas internas não possuem uma página pública de navegação.
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
