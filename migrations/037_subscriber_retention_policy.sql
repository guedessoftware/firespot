-- Política mínima e configurável de retenção do domínio FIRENETWORK.
-- Mantém eventos agregáveis e remove contexto técnico/pessoal temporário.
INSERT INTO app_settings (skey,svalue) VALUES
  ('subscriber_retention_challenge_days','7'),
  ('subscriber_retention_challenge_record_days','30'),
  ('subscriber_retention_network_context_days','30'),
  ('subscriber_retention_trusted_device_days','90'),
  ('subscriber_retention_audit_origin_days','30'),
  ('subscriber_retention_inactive_identifier_days','180')
ON DUPLICATE KEY UPDATE skey=VALUES(skey);
