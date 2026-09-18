-- FireSpot: prazo explícito do Pix e liberação das janelas de pedidos vencidos.

UPDATE guest_orders
SET payment_expires_at=DATE_ADD(created_at,INTERVAL 24 HOUR),
    updated_at=NOW()
WHERE payment_method='pix'
  AND provider_payment_id IS NOT NULL
  AND payment_expires_at IS NULL;

UPDATE guest_payment_windows w
JOIN guest_orders o ON o.id=w.order_id
SET w.closed_at=COALESCE(w.closed_at,NOW()),
    w.close_reason='order_expired'
WHERE o.status IN ('payment_failed','cancelled')
  AND LOWER(COALESCE(o.payment_status_detail,''))='expired'
  AND COALESCE(w.close_reason,'')<>'paid';

UPDATE guest_orders
SET status='cancelled',updated_at=NOW()
WHERE status='payment_failed'
  AND LOWER(COALESCE(payment_status_detail,''))='expired';
