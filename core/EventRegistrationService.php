<?php

/**
 * Event registration period and its closing.
 *
 * The payout trigger is REGISTRATION CLOSED — never the event date or event
 * completion — so organizers receive their collected fees in time to prepare
 * the event. Registration closes when:
 *   - the organizer clicks "Close Registration" (main trigger), or
 *   - optionally, automatically after the event's registration_deadline. The
 *     deadline date itself is still open (the same rule event_register_save.php
 *     applies), so the event closes on the first day AFTER it. Events without
 *     a deadline are never closed automatically.
 *
 * Closing stamps events.registration_closed_at. That timestamp is what makes
 * the event's ledger entries eligible for a payout request.
 */
class EventRegistrationService
{
    /** Statuses in which registration is over (closed, or the event has moved on). */
    public const CLOSED_STATUSES = ['closed', 'ongoing', 'completed', 'archived'];

    /** The automatic closing date, or null when only the organizer closes registration. */
    public static function registrationEnd(array $event): ?string
    {
        return !empty($event['registration_deadline']) ? $event['registration_deadline'] : null;
    }

    /** Open events whose registration deadline has passed (the optional automatic closing). */
    public static function dueForClosing(PDO $db): array
    {
        return $db->query(
            "SELECT id, organizer_id, name, registration_deadline FROM events
             WHERE status = 'open' AND registration_deadline IS NOT NULL AND CURDATE() > registration_deadline
             ORDER BY id"
        )->fetchAll();
    }

    /**
     * Closes registration (open -> closed) and stamps registration_closed_at.
     * Safe to call repeatedly: returns false when the event is no longer open.
     * $actorUserId null = the scheduler.
     */
    public static function close(PDO $db, int $eventId, ?int $actorUserId): bool
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, name, status, organizer_id FROM events WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $eventId]);
            $event = $stmt->fetch();
            if (!$event || $event['status'] !== 'open') {
                $db->commit();

                return false;
            }

            EventStatusService::changeStatus($db, $eventId, 'closed', $actorUserId);
            $db->prepare('UPDATE events SET registration_closed_at = COALESCE(registration_closed_at, NOW()) WHERE id = :id')
                ->execute(['id' => $eventId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Logger::log($actorUserId, 'event_registration_closed', 'Event #' . $eventId . ' (' . $event['name'] . ') registration closed ' . ($actorUserId === null ? 'automatically after the deadline' : 'by user #' . $actorUserId));
        PayoutRequestService::notifyOrganizer($db, (int) $event['organizer_id'], 'Registration Closed',
            'Registration for "' . $event['name'] . '" is now closed' . ($actorUserId === null ? ' (its deadline passed)' : '') . '. Your payout request is being prepared.',
            ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId], 'registration_closed');

        return true;
    }

    /**
     * Stamps registration_closed_at for an event whose registration ended in
     * another way (organizer closed it, marked it ongoing, ...). No-op when
     * already stamped or when the event is still open.
     */
    public static function markClosed(PDO $db, int $eventId): void
    {
        $db->prepare(
            'UPDATE events SET registration_closed_at = NOW()
             WHERE id = :id AND registration_closed_at IS NULL AND status IN ("closed", "ongoing", "completed", "archived")'
        )->execute(['id' => $eventId]);
    }

    /** Same as markClosed() for every such event (scheduler catch-up). Returns how many were stamped. */
    public static function markAllClosed(PDO $db): int
    {
        $stmt = $db->prepare(
            'UPDATE events SET registration_closed_at = NOW()
             WHERE registration_closed_at IS NULL AND status IN ("closed", "ongoing", "completed", "archived")'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Taking a slot is done one request at a time per event (v47): two people paying for, or an
     * organizer approving, the last slot at the same moment can no longer both get it. A MySQL
     * named lock on this connection; it is also released automatically when the request ends.
     */
    public static function seatLock(PDO $db, int $eventId, int $waitSeconds = 20): bool
    {
        $stmt = $db->prepare('SELECT GET_LOCK(:n, :w)');
        $stmt->execute(['n' => 'evora_event_seats_' . $eventId, 'w' => $waitSeconds]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public static function seatUnlock(PDO $db, int $eventId): void
    {
        $db->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => 'evora_event_seats_' . $eventId]);
    }

    /** True when approving this (not yet approved) registration would go over the event's or its category's capacity. */
    public static function wouldOverfill(PDO $db, int $registrationId): bool
    {
        $stmt = $db->prepare('SELECT r.status, r.race_category_id, e.max_participants,
                (SELECT COUNT(*) FROM registrations x WHERE x.event_id = r.event_id AND x.status = "approved") AS taken,
                rc.max_participants AS cat_max,
                (SELECT COUNT(*) FROM registrations y WHERE y.race_category_id = r.race_category_id AND y.status = "approved") AS cat_taken
            FROM registrations r JOIN events e ON e.id = r.event_id LEFT JOIN race_categories rc ON rc.id = r.race_category_id WHERE r.id = :id');
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] === 'approved') {
            return false;
        }
        if ((int) $row['max_participants'] > 0 && (int) $row['taken'] >= (int) $row['max_participants']) {
            return true;
        }

        return $row['race_category_id'] && (int) $row['cat_max'] > 0 && (int) $row['cat_taken'] >= (int) $row['cat_max'];
    }
}
