<?php
require_once __DIR__ . '/settings.php';

function adsense_is_enabled(): bool {
  // Integração legada de banner. Permanece desligada por padrão e não pode ser
  // usada como comprovação de anúncio recompensado.
  $v = settings_get('adsense_enabled', getenv('ADSENSE_ENABLED') !== false ? (string) getenv('ADSENSE_ENABLED') : '0');
  return $v === '1' || strtolower((string)$v) === 'true' || (string)$v === 'on';
}

function adsense_client_id(): string {
  $c = trim((string) settings_get('adsense_client', getenv('ADSENSE_CLIENT') ?: ''));
  return $c;
}

function adsense_head_snippet(): string {
  if (!adsense_is_enabled()) return '';
  $client = adsense_client_id();
  if ($client === '') return '';
  $client_q = htmlspecialchars($client, ENT_QUOTES, 'UTF-8');
  return '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client_q . '" crossorigin="anonymous"></script>';
}

function adsense_block(string $slot, array $opts = []): string {
  if (!adsense_is_enabled()) return '';
  $client = adsense_client_id();
  if ($client === '' || $slot === '') return '';
  $style = $opts['style'] ?? 'display:block';
  $format = $opts['format'] ?? 'auto';
  $full = !isset($opts['fullWidth']) || $opts['fullWidth'] ? 'true' : 'false';
  $client_h = htmlspecialchars($client, ENT_QUOTES, 'UTF-8');
  $slot_h = htmlspecialchars($slot, ENT_QUOTES, 'UTF-8');
  $style_h = htmlspecialchars($style, ENT_QUOTES, 'UTF-8');
  $format_h = htmlspecialchars($format, ENT_QUOTES, 'UTF-8');
  return <<<HTML
<ins class="adsbygoogle" style="$style_h" data-ad-client="$client_h" data-ad-slot="$slot_h" data-ad-format="$format_h" data-full-width-responsive="$full"></ins>
<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
HTML;
}
