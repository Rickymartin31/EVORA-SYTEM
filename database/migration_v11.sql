-- Migration v11: Payment rejection reason

ALTER TABLE payments
  ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL AFTER status;
