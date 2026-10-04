-- v36: tracking of refunds that organizers make from their own PayPal account (PayPal payments
-- go directly to the organizer, so EVORA cannot refund them itself). Additive only: one new
-- table + guard triggers. payments / paypal_transactions are NOT changed by this feature.
-- Rollback: v36_paypal_refund_tracking_rollback.sql
CREATE TABLE IF NOT EXISTS paypal_refund_records (
  id INT(11) NOT NULL AUTO_INCREMENT,
  payment_id INT(11) NOT NULL,
  registration_id INT(11) NOT NULL,
  event_id INT(11) NOT NULL,
  organizer_id INT(11) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  paypal_refund_id VARCHAR(40) NOT NULL COMMENT 'refund transaction ID shown in the organizer''s PayPal account',
  note VARCHAR(500) NULL,
  recorded_by INT(11) NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  participant_response ENUM('none','received','not_received') NOT NULL DEFAULT 'none',
  participant_note VARCHAR(500) NULL,
  participant_responded_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pprefund_payment (payment_id),
  UNIQUE KEY uq_pprefund_refund_id (paypal_refund_id),
  KEY idx_pprefund_event (event_id),
  KEY idx_pprefund_organizer (organizer_id),
  KEY fk_pprefund_registration (registration_id),
  KEY fk_pprefund_recorded_by (recorded_by),
  CONSTRAINT fk_pprefund_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
  CONSTRAINT fk_pprefund_registration FOREIGN KEY (registration_id) REFERENCES registrations (id),
  CONSTRAINT fk_pprefund_event FOREIGN KEY (event_id) REFERENCES events (id),
  CONSTRAINT fk_pprefund_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (id),
  CONSTRAINT fk_pprefund_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_pprefund_validate_insert;
DROP TRIGGER IF EXISTS trg_pprefund_guard_update;
DROP TRIGGER IF EXISTS trg_pprefund_no_delete;
DELIMITER //
CREATE TRIGGER trg_pprefund_validate_insert BEFORE INSERT ON paypal_refund_records FOR EACH ROW
BEGIN
    DECLARE v_reg INT DEFAULT NULL;
    DECLARE v_amount DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_channel VARCHAR(20) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_event INT DEFAULT NULL;
    DECLARE v_org INT DEFAULT NULL;
    SELECT p.registration_id, p.amount, p.payment_channel, p.status, r.event_id, e.organizer_id
      INTO v_reg, v_amount, v_channel, v_status, v_event, v_org
      FROM payments p JOIN registrations r ON r.id = p.registration_id JOIN events e ON e.id = r.event_id
     WHERE p.id = NEW.payment_id;
    IF v_reg IS NULL OR v_reg <> NEW.registration_id OR v_event <> NEW.event_id OR v_org <> NEW.organizer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund record does not match the payment.';
    END IF;
    IF v_channel <> 'paypal' OR v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only paid PayPal payments can get a PayPal refund record.';
    END IF;
    IF NEW.amount <> v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund amount must equal the payment amount.';
    END IF;
    IF NEW.participant_response <> 'none' OR NEW.participant_responded_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new refund record cannot already have a participant response.';
    END IF;
END//
CREATE TRIGGER trg_pprefund_guard_update BEFORE UPDATE ON paypal_refund_records FOR EACH ROW
BEGIN
    IF NEW.payment_id <> OLD.payment_id OR NEW.registration_id <> OLD.registration_id OR NEW.event_id <> OLD.event_id
       OR NEW.organizer_id <> OLD.organizer_id OR NEW.amount <> OLD.amount OR NEW.paypal_refund_id <> OLD.paypal_refund_id
       OR NOT (NEW.note <=> OLD.note) OR NEW.recorded_by <> OLD.recorded_by OR NEW.recorded_at <> OLD.recorded_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded refund cannot be changed.';
    END IF;
    IF OLD.participant_response <> 'none' AND (NEW.participant_response <> OLD.participant_response
       OR NOT (NEW.participant_note <=> OLD.participant_note) OR NOT (NEW.participant_responded_at <=> OLD.participant_responded_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The participant already answered for this refund.';
    END IF;
END//
CREATE TRIGGER trg_pprefund_no_delete BEFORE DELETE ON paypal_refund_records FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund records cannot be deleted.';
END//
DELIMITER ;

-- Test events (sandbox/deployment test data) never owe refunds and can be cancelled quietly.
ALTER TABLE events ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = test event (sandbox test data); never owes refunds';
