ALTER TABLE nas_health
  ADD COLUMN IF NOT EXISTS hotspot_host_count INT(11) UNSIGNED DEFAULT NULL AFTER interface_count;
