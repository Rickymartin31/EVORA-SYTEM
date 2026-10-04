-- Migration v9: add certificate_type to certificates (additive, backward compatible)
-- Historical rows all used the default title before this column existed, so
-- the DEFAULT accurately backfills existing certificates.
ALTER TABLE certificates
  ADD COLUMN certificate_type VARCHAR(100) NOT NULL DEFAULT 'Certificate of Participation' AFTER certificate_number;
