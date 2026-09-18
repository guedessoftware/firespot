INSERT IGNORE INTO courtesy_portal_rollouts (partner_id,portal,mode,revision,updated_by,created_at,updated_at)
SELECT partner_id,'classic_signup',mode,revision,updated_by,created_at,updated_at
  FROM courtesy_portal_rollouts
 WHERE portal='classic';

DELETE FROM courtesy_portal_rollouts WHERE portal='classic';

INSERT IGNORE INTO courtesy_portal_rollouts (partner_id,portal,mode)
SELECT id,'classic_signup','shadow' FROM partners;

INSERT IGNORE INTO courtesy_portal_rollouts (partner_id,portal,mode)
SELECT id,'classic_login','legacy' FROM partners;
