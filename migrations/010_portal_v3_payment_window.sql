ALTER TABLE guest_orders
  ADD COLUMN IF NOT EXISTS payment_window_token VARCHAR(40) DEFAULT NULL AFTER radius_cleaned_at,
  ADD COLUMN IF NOT EXISTS payment_window_started_at DATETIME DEFAULT NULL AFTER payment_window_token,
  ADD COLUMN IF NOT EXISTS payment_window_expires_at DATETIME DEFAULT NULL AFTER payment_window_started_at,
  ADD COLUMN IF NOT EXISTS payment_window_closed_at DATETIME DEFAULT NULL AFTER payment_window_expires_at,
  ADD INDEX IF NOT EXISTS idx_guest_orders_payment_window (payment_window_token,payment_window_closed_at);
