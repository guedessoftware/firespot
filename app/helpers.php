<?php
/**
 * FireSpot - Funções auxiliares centralizadas
 * Evita duplicação de código comum
 */

if (!function_exists('only_digits')) {
    /**
     * Remove todos os caracteres não-numéricos de uma string
     */
    function only_digits($s) {
        return preg_replace('/\D+/', '', (string)$s);
    }
}

if (!function_exists('normalize_mac')) {
    /**
     * Normaliza endereço MAC para formato AA:BB:CC:DD:EE:FF
     * @param string $mac MAC address in any format
     * @return string|null Normalized MAC or null if invalid
     */
    function normalize_mac($mac) {
        $mac = strtoupper(str_replace(['-', '.', ' '], ':', trim((string)$mac)));
        if ($mac === '') return null;
        
        // Se for AABBCCDDEEFF sem separadores, adiciona ':'
        if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
            $mac = implode(':', str_split($mac, 2));
        }
        
        // Valida formato final AA:BB:CC:DD:EE:FF
        return preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac) ? $mac : null;
    }
}

if (!function_exists('normalize_ip')) {
    /**
     * Normaliza e valida endereço IP (v4 ou v6)
     * @param string $ip IP address
     * @return string|null Normalized IP or null if invalid
     */
    function normalize_ip($ip) {
        $ip = trim((string)$ip);
        if ($ip === '') return null;
        
        // Remove porta se existir (1.2.3.4:80 -> 1.2.3.4)
        if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $ip)) {
            $ip = preg_replace('/:(\d+)$/', '', $ip);
        }
        
        // Remove brackets de IPv6 ([::1] -> ::1)
        $ip = trim($ip, '[]');
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return $ip;
        
        return null;
    }
}

if (!function_exists('normalize_phone_br')) {
    /**
     * Normaliza telefone brasileiro para formato [DDD, número]
     * @param string $phone Phone number
     * @return array [DDD, number] or ['', '']
     */
    function normalize_phone_br($phone) {
        $digits = only_digits($phone);
        if ($digits === '') return ['', ''];
        
        // Remove código do país 55 se presente
        if (strlen($digits) >= 12 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        
        // Extrai DDD (2 primeiros dígitos) e número
        $ddd = substr($digits, 0, 2);
        $number = substr($digits, 2);
        
        return [$ddd, $number];
    }
}

if (!function_exists('format_money_br')) {
    /**
     * Formata centavos para formato brasileiro (R$ 1.234,56)
     * @param int $centavos Amount in cents
     * @return string Formatted money
     */
    function format_money_br($centavos) {
        return 'R$ ' . number_format(((int)$centavos) / 100, 2, ',', '.');
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * Obtém o IP real do cliente considerando proxies
     * @return string Client IP address
     */
    function get_client_ip() {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Se X-Forwarded-For tem múltiplos IPs, pega o primeiro
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                $normalized = normalize_ip($ip);
                if ($normalized) return $normalized;
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('envv')) {
    /**
     * Busca variável de ambiente com fallback
     * Compatível com sistemas que usam env() ou getenv()
     */
    function envv($key, $default = null) {
        if (function_exists('env')) return env($key, $default);
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}

if (!function_exists('log_audit')) {
    /**
     * Registra ação de auditoria em vendas
     * @param PDO $pdo Database connection
     * @param int $order_id Order ID
     * @param string $action Action name
     * @param string|null $old_status Old status
     * @param string|null $new_status New status
     * @param string|null $notes Additional notes
     * @param string|null $admin_username Admin username
     */
    function log_audit($pdo, $order_id, $action, $old_status = null, $new_status = null, $notes = null, $admin_username = null) {
        try {
            // Tenta obter admin da sessão se não fornecido
            if ($admin_username === null && isset($_SESSION['admin'])) {
                $admin_username = $_SESSION['admin'];
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO vip_orders_audit 
                (order_id, admin_username, action, old_status, new_status, notes, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $order_id,
                $admin_username,
                $action,
                $old_status,
                $new_status,
                $notes,
                get_client_ip()
            ]);
            
            return true;
        } catch (Throwable $e) {
            error_log("Falha ao registrar auditoria: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('table_has_column')) {
    /**
     * Verifica se uma tabela possui determinada coluna
     */
    function table_has_column(PDO $pdo, $table, $column) {
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ? 
                LIMIT 1
            ");
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ensure_vip_orders_payment_schema')) {
    /**
     * Garante que a tabela vip_orders tenha as colunas exigidas para métodos de pagamento e reembolso.
     */
    function ensure_vip_orders_payment_schema(PDO $pdo) {
        require_once __DIR__ . '/schema_guard.php';
        runtime_schema_require($pdo, 'vip_orders', [
            'payment_method','payment_method_detail','payment_installments','payment_expires_at',
            'mp_refund_id','refund_status','refund_amount_centavos','refunded_at','refund_notes',
        ]);
    }
}
