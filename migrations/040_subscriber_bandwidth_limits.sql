-- Limite de velocidade do benefício FIRENETWORK.
-- O perfil garante um limite seguro inclusive quando o serviço elegível ainda
-- não possui mapeamento. O mapeamento pode substituir esse limite por produto.
ALTER TABLE subscriber_benefit_profiles
  ADD COLUMN IF NOT EXISTS download_kbps INT UNSIGNED NOT NULL DEFAULT 10000 AFTER revalidation_minutes,
  ADD COLUMN IF NOT EXISTS upload_kbps INT UNSIGNED NOT NULL DEFAULT 3000 AFTER download_kbps;

ALTER TABLE subscriber_plan_mappings
  ADD COLUMN IF NOT EXISTS download_kbps INT UNSIGNED NULL AFTER benefit_profile_id,
  ADD COLUMN IF NOT EXISTS upload_kbps INT UNSIGNED NULL AFTER download_kbps;

UPDATE subscriber_benefit_profiles
SET download_kbps=10000
WHERE download_kbps=0;

UPDATE subscriber_benefit_profiles
SET upload_kbps=3000
WHERE upload_kbps=0;
