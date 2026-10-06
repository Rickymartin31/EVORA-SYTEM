-- Rollback of v56. Refuses when runners were already tracked, so recorded GPS tracks are never lost.
DROP PROCEDURE IF EXISTS evora_v56_rollback_guard;
DELIMITER //
CREATE PROCEDURE evora_v56_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'race_tracks')
       AND (SELECT COUNT(*) FROM race_tracks) > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Runners were already tracked; not rolling back.';
    END IF;
END//
DELIMITER ;
CALL evora_v56_rollback_guard();
DROP PROCEDURE IF EXISTS evora_v56_rollback_guard;
DROP TABLE IF EXISTS race_track_points;
DROP TABLE IF EXISTS race_tracks;
DROP TABLE IF EXISTS event_route_paths;
ALTER TABLE event_checkpoints DROP COLUMN IF EXISTS radius_m, DROP COLUMN IF EXISTS lng, DROP COLUMN IF EXISTS lat;
