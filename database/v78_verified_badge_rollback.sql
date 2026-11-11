-- Rollback of v78_verified_badge.sql (removes the Verified Organizer Badge data).
ALTER TABLE organizers
    DROP COLUMN IF EXISTS badge_revoke_reason,
    DROP COLUMN IF EXISTS badge_revoked_at,
    DROP COLUMN IF EXISTS badge_application_id,
    DROP COLUMN IF EXISTS badge_expires_on,
    DROP COLUMN IF EXISTS badge_verified_at;
DROP TABLE IF EXISTS organizer_badge_documents;
DROP TABLE IF EXISTS organizer_badge_applications;
