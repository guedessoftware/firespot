-- Métricas diárias anônimas por estabelecimento e instalação.
-- Nenhum MAC, IP, usuário ou outro identificador pessoal é materializado.

CREATE TABLE IF NOT EXISTS partner_portal_daily_events (
  metric_date DATE NOT NULL,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  event_code VARCHAR(48) NOT NULL,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_date,partner_id,hotspot_id,event_code),
  KEY idx_partner_portal_events_hotspot (partner_id,hotspot_id,metric_date),
  CONSTRAINT fk_partner_portal_events_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_portal_events_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_daily_metrics (
  metric_date DATE NOT NULL,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  sessions_count INT UNSIGNED NOT NULL DEFAULT 0,
  unique_devices INT UNSIGNED NOT NULL DEFAULT 0,
  new_visitors INT UNSIGNED NOT NULL DEFAULT 0,
  returning_visitors INT UNSIGNED NOT NULL DEFAULT 0,
  session_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
  input_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  portal_opens INT UNSIGNED NOT NULL DEFAULT 0,
  options_views INT UNSIGNED NOT NULL DEFAULT 0,
  paid_option_views INT UNSIGNED NOT NULL DEFAULT 0,
  checkout_views INT UNSIGNED NOT NULL DEFAULT 0,
  courtesy_selections INT UNSIGNED NOT NULL DEFAULT 0,
  subscriber_selections INT UNSIGNED NOT NULL DEFAULT 0,
  courtesy_requests INT UNSIGNED NOT NULL DEFAULT 0,
  courtesy_activated INT UNSIGNED NOT NULL DEFAULT 0,
  courtesy_denied_or_failed INT UNSIGNED NOT NULL DEFAULT 0,
  courtesy_exhausted INT UNSIGNED NOT NULL DEFAULT 0,
  orders_created INT UNSIGNED NOT NULL DEFAULT 0,
  orders_paid INT UNSIGNED NOT NULL DEFAULT 0,
  orders_pending INT UNSIGNED NOT NULL DEFAULT 0,
  orders_failed INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  refunds_count INT UNSIGNED NOT NULL DEFAULT 0,
  coa_applied INT UNSIGNED NOT NULL DEFAULT 0,
  coa_attention INT UNSIGNED NOT NULL DEFAULT 0,
  subscriber_accesses INT UNSIGNED NOT NULL DEFAULT 0,
  subscriber_failures INT UNSIGNED NOT NULL DEFAULT 0,
  ad_impressions INT UNSIGNED NOT NULL DEFAULT 0,
  ad_completions INT UNSIGNED NOT NULL DEFAULT 0,
  ad_clicks INT UNSIGNED NOT NULL DEFAULT 0,
  ad_interests INT UNSIGNED NOT NULL DEFAULT 0,
  leads_created INT UNSIGNED NOT NULL DEFAULT 0,
  operation_successes INT UNSIGNED NOT NULL DEFAULT 0,
  operation_failures INT UNSIGNED NOT NULL DEFAULT 0,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_date,partner_id,hotspot_id),
  KEY idx_partner_metrics_hotspot_date (partner_id,hotspot_id,metric_date),
  CONSTRAINT fk_partner_daily_metrics_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_daily_metrics_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE partner_daily_metrics
  ADD COLUMN IF NOT EXISTS new_visitors INT UNSIGNED NOT NULL DEFAULT 0 AFTER unique_devices,
  ADD COLUMN IF NOT EXISTS returning_visitors INT UNSIGNED NOT NULL DEFAULT 0 AFTER new_visitors,
  ADD COLUMN IF NOT EXISTS portal_opens INT UNSIGNED NOT NULL DEFAULT 0 AFTER output_bytes,
  ADD COLUMN IF NOT EXISTS options_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER portal_opens,
  ADD COLUMN IF NOT EXISTS paid_option_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER options_views,
  ADD COLUMN IF NOT EXISTS checkout_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER paid_option_views,
  ADD COLUMN IF NOT EXISTS courtesy_selections INT UNSIGNED NOT NULL DEFAULT 0 AFTER checkout_views,
  ADD COLUMN IF NOT EXISTS subscriber_selections INT UNSIGNED NOT NULL DEFAULT 0 AFTER courtesy_selections,
  ADD COLUMN IF NOT EXISTS courtesy_exhausted INT UNSIGNED NOT NULL DEFAULT 0 AFTER courtesy_denied_or_failed,
  ADD COLUMN IF NOT EXISTS orders_pending INT UNSIGNED NOT NULL DEFAULT 0 AFTER orders_paid,
  ADD COLUMN IF NOT EXISTS orders_failed INT UNSIGNED NOT NULL DEFAULT 0 AFTER orders_pending,
  ADD COLUMN IF NOT EXISTS coa_applied INT UNSIGNED NOT NULL DEFAULT 0 AFTER refunds_count,
  ADD COLUMN IF NOT EXISTS coa_attention INT UNSIGNED NOT NULL DEFAULT 0 AFTER coa_applied,
  ADD COLUMN IF NOT EXISTS ad_interests INT UNSIGNED NOT NULL DEFAULT 0 AFTER ad_clicks;

CREATE TABLE IF NOT EXISTS partner_daily_metric_reasons (
  metric_date DATE NOT NULL,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  reason_scope ENUM('courtesy','payment','subscriber','operation') NOT NULL,
  reason_code VARCHAR(64) NOT NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_date,partner_id,hotspot_id,reason_scope,reason_code),
  KEY idx_partner_metric_reasons (partner_id,metric_date,reason_scope),
  CONSTRAINT fk_partner_metric_reasons_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_metric_reasons_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_analytics_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric_date DATE NOT NULL,
  status ENUM('running','succeeded','failed') NOT NULL,
  hotspot_count INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  error_code VARCHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_partner_analytics_runs (metric_date,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
