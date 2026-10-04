-- Migration v15: Walk-in registration support (cash walk-in payment channel + staff permission)

ALTER TABLE payments
  MODIFY COLUMN payment_channel ENUM('manual','paymongo','cash_walkin') NOT NULL DEFAULT 'manual';

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin') NOT NULL;
