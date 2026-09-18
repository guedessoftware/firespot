-- Gates independentes para o rollout seguro do domínio FIRENETWORK.
INSERT INTO app_settings (skey,svalue) VALUES
  ('subscriber_access_enabled','0'),
  ('subscriber_account_enabled','0'),
  ('subscriber_invites_enabled','0'),
  ('subscriber_radius_enabled','0'),
  ('subscriber_authenticated_purchase_enabled','0')
ON DUPLICATE KEY UPDATE skey=VALUES(skey);
