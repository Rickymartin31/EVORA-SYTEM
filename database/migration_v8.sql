-- Migration v8: PayMongo payment gateway support (extends payments, no new table)

ALTER TABLE payments
  MODIFY COLUMN status ENUM('pending','approved','rejected','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  ADD COLUMN payment_channel ENUM('manual','paymongo') NOT NULL DEFAULT 'manual' AFTER payment_method,
  ADD COLUMN checkout_session_id VARCHAR(100) NULL AFTER payment_channel,
  ADD COLUMN gateway_payment_id VARCHAR(100) NULL AFTER checkout_session_id,
  ADD COLUMN gateway_payment_method VARCHAR(30) NULL AFTER gateway_payment_id,
  ADD COLUMN paid_at DATETIME NULL AFTER gateway_payment_method,
  ADD COLUMN webhook_event_id VARCHAR(100) NULL AFTER paid_at,
  ADD UNIQUE KEY uq_checkout_session (checkout_session_id),
  ADD UNIQUE KEY uq_webhook_event (webhook_event_id);
