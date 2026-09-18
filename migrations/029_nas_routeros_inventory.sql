ALTER TABLE nas_health
  ADD COLUMN IF NOT EXISTS hotspot_server_count INT UNSIGNED NULL AFTER hotspot_host_count,
  ADD COLUMN IF NOT EXISTS vlan_count INT UNSIGNED NULL AFTER hotspot_server_count,
  ADD COLUMN IF NOT EXISTS radius_hotspot_count INT UNSIGNED NULL AFTER vlan_count,
  ADD COLUMN IF NOT EXISTS firespot_radius_count INT UNSIGNED NULL AFTER radius_hotspot_count;
