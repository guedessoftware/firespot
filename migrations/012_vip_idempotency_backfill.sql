UPDATE vip_orders
   SET vip_applied_at = COALESCE(paid_at, updated_at, created_at, NOW()),
       updated_at = NOW()
 WHERE status = 'paid'
   AND vip_applied_at IS NULL
   AND username IS NOT NULL
   AND username <> '';
