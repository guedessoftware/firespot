-- Corrige a resolução do nome privado do portal cativo.
-- `.local` é reservado a mDNS em clientes móveis; o MikroTik usa DNS unicast.

UPDATE partners
SET dns_name = CASE
    WHEN dns_name IS NULL OR TRIM(dns_name) = '' OR LOWER(TRIM(dns_name)) = 'local'
      THEN CONCAT(LOWER(REPLACE(code,'_','-')),'.hotspot.internal')
    ELSE CONCAT(LOWER(LEFT(TRIM(dns_name),CHAR_LENGTH(TRIM(dns_name))-6)),'.hotspot.internal')
  END,
  updated_at = NOW()
WHERE dns_name IS NULL
   OR TRIM(dns_name) = ''
   OR LOWER(TRIM(dns_name)) = 'local'
   OR LOWER(TRIM(dns_name)) LIKE '%.local';

UPDATE partner_hotspots
SET dns_name = CASE
    WHEN dns_name IS NULL OR TRIM(dns_name) = '' OR LOWER(TRIM(dns_name)) = 'local'
      THEN CONCAT(LOWER(REPLACE(code,'_','-')),'.hotspot.internal')
    ELSE CONCAT(LOWER(LEFT(TRIM(dns_name),CHAR_LENGTH(TRIM(dns_name))-6)),'.hotspot.internal')
  END,
  updated_at = NOW()
WHERE dns_name IS NULL
   OR TRIM(dns_name) = ''
   OR LOWER(TRIM(dns_name)) = 'local'
   OR LOWER(TRIM(dns_name)) LIKE '%.local';
