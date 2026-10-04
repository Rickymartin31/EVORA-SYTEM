-- Rollback of v36. Refuses if any refund was already recorded, so recorded refunds are never lost.
DROP PROCEDURE IF EXISTS evora_v36_rollback_guard;
DELIMITER //
CREATE PROCEDURE evora_v36_rollback_guard()
BEGIN
    IF (SELECT COUNT(*) FROM paypal_refund_records) > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paypal_refund_records has recorded refunds; not rolling back.';
    END IF;
END//
DELIMITER ;
CALL evora_v36_rollback_guard();
DROP PROCEDURE IF EXISTS evora_v36_rollback_guard;
DROP TRIGGER IF EXISTS trg_pprefund_validate_insert;
DROP TRIGGER IF EXISTS trg_pprefund_guard_update;
DROP TRIGGER IF EXISTS trg_pprefund_no_delete;
DROP TABLE IF EXISTS paypal_refund_records;
ALTER TABLE events DROP COLUMN IF EXISTS is_test;
