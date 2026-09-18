-- FireSpot: retomada de crédito anônimo do Portal V3 por estabelecimento e dispositivo.
-- Não altera tabelas nem fluxos do Portal clássico ou Portal V2.

ALTER TABLE guest_orders
  ADD INDEX IF NOT EXISTS idx_guest_orders_partner_mac_credit
    (partner_id, device_mac, status, radius_cleaned_at, paid_at);
