ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS payment_window_daily_limit SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER payment_window_minutes,
  ADD COLUMN IF NOT EXISTS payment_window_cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER payment_window_daily_limit,
  ADD COLUMN IF NOT EXISTS payment_window_period_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER payment_window_cooldown_minutes;
