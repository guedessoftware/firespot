-- Rastreabilidade das validações de credenciais da carteira própria.
-- Tokens e segredos continuam cifrados; somente datas e hints são exibíveis.

ALTER TABLE payment_wallets
  ADD COLUMN IF NOT EXISTS access_token_validated_at DATETIME NULL AFTER credential_hint,
  ADD COLUMN IF NOT EXISTS webhook_secret_validated_at DATETIME NULL AFTER webhook_secret_configured_at,
  ADD COLUMN IF NOT EXISTS active_partner_id INT AS (CASE WHEN active=1 AND partner_id IS NOT NULL THEN partner_id ELSE NULL END) STORED AFTER active,
  ADD UNIQUE INDEX IF NOT EXISTS uq_payment_wallet_active_partner (active_partner_id);

UPDATE payment_wallets
SET access_token_validated_at=COALESCE(access_token_validated_at,updated_at),
    webhook_secret_validated_at=CASE
      WHEN webhook_secret_encrypted IS NOT NULL AND webhook_secret_encrypted<>''
        THEN COALESCE(webhook_secret_validated_at,webhook_secret_configured_at,updated_at)
      ELSE webhook_secret_validated_at
    END
WHERE access_token_validated_at IS NULL
   OR (webhook_secret_encrypted IS NOT NULL AND webhook_secret_encrypted<>'' AND webhook_secret_validated_at IS NULL);
