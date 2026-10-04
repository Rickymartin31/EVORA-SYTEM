<?php

/**
 * Backend enforcement that an event-type-specific tool is only used against
 * an event of the matching category. Ownership/staff-permission checks
 * already happen independently in each file (via events.organizer_id or
 * staff_permissions) — this is purely the category layer on top, called
 * once per file immediately after that existing ownership check confirms
 * the event exists and the caller is allowed to act on it.
 */
class EventAccess
{
    /** @return bool true if the event's category matches $expectedCategorySlug */
    public static function isCorrectCategory(PDO $db, int $eventId, string $expectedCategorySlug): bool
    {
        $stmt = $db->prepare(
            'SELECT c.slug FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();

        return $row !== false && $row['slug'] === $expectedCategorySlug;
    }

    /**
     * Call immediately after an existing ownership/permission check has
     * confirmed the event exists and the caller may act on it. If the
     * event's category doesn't match, flashes a clear message and redirects
     * — same fail-closed shape as every other access check in the app.
     * Does not distinguish "wrong category" from any other failure in what
     * it exposes: the caller already owns/is-permitted on this event, so
     * there is nothing new being disclosed here.
     */
    public static function requireEventType(PDO $db, int $eventId, string $expectedCategorySlug, string $redirectRoute, array $redirectParams = []): void
    {
        if (!self::isCorrectCategory($db, $eventId, $expectedCategorySlug)) {
            flash('error', 'This tool is not available for this event type.');
            redirect($redirectRoute, $redirectParams);
        }
    }
}
