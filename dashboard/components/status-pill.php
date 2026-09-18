<?php

declare(strict_types=1);

if (!function_exists('fs_cc_escape')) {
    function fs_cc_escape($value): string
    {
        return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    }
}

function fs_cc_status_pill(string $label, string $tone = 'neutral'): string
{
    if (!in_array($tone,['success','warning','danger','info','neutral'],true)) $tone = 'neutral';
    return '<span class="fs-cc-pill is-' . $tone . '">' . fs_cc_escape($label) . '</span>';
}

