-- Rollback of v68. Refuses when a GPX track was uploaded, so uploaded runs are never lost.
DROP PROCEDURE IF EXISTS evora_v68_rollback_guard;
DELIMITER //
CREATE PROCEDURE evora_v68_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'race_tracks' AND COLUMN_NAME = 'source')
       AND (SELECT COUNT(*) FROM race_tracks WHERE source = 'gpx') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'GPX tracks exist; not rolling back.';
    END IF;
END//
DELIMITER ;
CALL evora_v68_rollback_guard();
DROP PROCEDURE IF EXISTS evora_v68_rollback_guard;
ALTER TABLE race_tracks
  DROP COLUMN IF EXISTS last_checkpoint, DROP COLUMN IF EXISTS checkpoints_passed, DROP COLUMN IF EXISTS max_off_route_m,
  DROP COLUMN IF EXISTS off_route_m, DROP COLUMN IF EXISTS gpx_path, DROP COLUMN IF EXISTS source;
