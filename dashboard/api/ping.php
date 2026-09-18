
<?php
// Importa a conexão com o banco de dados
require_once __DIR__ . '/../../app/db.php';

// Define o tipo de resposta como JSON
header('Content-Type: application/json');

try {
	// Testa a conexão com o banco
	db()->query('SELECT 1');
	echo json_encode(['ok' => true]);
	} catch (Throwable $e) {
		// Não devolve detalhes de conexão/SQL ao cliente público.
		error_log('[dashboard ping] ' . get_class($e));
		http_response_code(500);
		echo json_encode([
			'ok' => false,
			'err' => 'database_unavailable'
		]);
}
