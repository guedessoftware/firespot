-- FireSpot: autenticidade dos webhooks de pagamento por carteira.
-- O segredo nunca e armazenado em texto puro. configured_at funciona como
-- corte de compatibilidade: pedidos anteriores continuam aceitando o callback
-- interno assinado; pedidos posteriores exigem tambem a assinatura do provedor.

ALTER TABLE payment_wallets
  ADD COLUMN IF NOT EXISTS webhook_secret_encrypted TEXT NULL AFTER access_token_encrypted,
  ADD COLUMN IF NOT EXISTS webhook_secret_hint VARCHAR(32) NULL AFTER webhook_secret_encrypted,
  ADD COLUMN IF NOT EXISTS webhook_secret_configured_at DATETIME NULL AFTER webhook_secret_hint;

