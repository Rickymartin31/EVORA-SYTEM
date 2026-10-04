-- Migration v13: Duplicate proof-of-payment detection (manual payment anti-fraud)

ALTER TABLE payments
  ADD COLUMN proof_hash CHAR(64) DEFAULT NULL AFTER proof_path,
  ADD INDEX idx_pay_proof_hash (proof_hash);
