<?php
// /app/db.php
declare(strict_types=1);

/**
 * Conexão PDO reutilizável usando config de /app/config.php
 * Config esperado (já enviado por você):
 *  define('DB_HOST', 'localhost');           // pode ser "host" ou "host:porta"
 *  define('DB_DATABASE', 'firespot');
 *  define('DB_USERNAME', 'root');
 *  define('DB_PASSWORD', 'sua_senha');
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO)
        return $pdo;

    // Carrega configurações
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        error_log('Config file not found at: ' . $configPath);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'CONFIG_NOT_FOUND']);
        exit;
    }
    require_once $configPath;

    // Valida constantes esperadas
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $c) {
        if (!defined($c)) {
            error_log("Missing required config constant: {$c}");
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'CONFIG_INVALID']);
            exit;
        }
    }

    // Extrai host e porta (aceita "host:porta")
    $host = DB_HOST;
    $port = '3306';
    if (strpos($host, ':') !== false) {
        [$hostOnly, $portMaybe] = explode(':', $host, 2);
        if ($hostOnly !== '')
            $host = $hostOnly;
        if (ctype_digit($portMaybe))
            $port = $portMaybe;
    }

    $db = DB_DATABASE;
    $user = DB_USERNAME;
    $pass = DB_PASSWORD;
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Evita warnings com sql_mode estrito em algumas instalações
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $opts);
    } catch (Throwable $e) {
        // Não exponha credenciais nem mensagens sensíveis ao cliente
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'DB_CONNECTION_FAILED']);
        exit;
    }

    return $pdo;
}
