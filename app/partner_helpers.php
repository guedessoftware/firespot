<?php
// Funções utilitárias para parceiros/hosts

if (!function_exists('partner_profile')) {
    function partner_profile(array $row): string
    {
        foreach (['access_profile', 'partner_type', 'segment', 'category'] as $key) {
            if (!empty($row[$key])) {
                return strtolower(trim((string) $row[$key]));
            }
        }
        return 'default';
    }
}
