<?php

/**
 * Event waitlist (v39).
 *
 * When an event (or a fun-run race category) is full, a participant can join its waitlist.
 * When a slot frees up (a cancellation, a rejection), the next person in line gets an OFFER:
 * the slot is held for them for 24 hours (never past the registration deadline) and they are
 * notified. While an offer is open, that slot counts as taken for everyone else. An offer that
 * is not used expires and moves on to the next person.
 *
 * process() is safe to run any time: it runs after a cancellation, when the event page is
 * opened, and from scripts/participant_jobs.php (Task Scheduler).
 */
class WaitlistService
{
    public const OFFER_HOURS = 24;

    /** Slots taken = approved registrations + open offers held for someone else. */
    public static function isFull(PDO $db, array $event, ?int $raceCategoryId = null, ?int $exceptParticipantId = null): bool
    {
        $held = 'SELECT COUNT(*) FROM event_waitlist w WHERE w.event_id = :eid AND w.status = "offered" AND w.offer_expires_at > NOW() AND w.participant_id <> :pid';
        $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM registrations WHERE event_id = :eid1 AND status = "approved") + (' . str_replace(':eid', ':eid2', $held) . ')');
        $stmt->execute(['eid1' => $event['id'], 'eid2' => $event['id'], 'pid' => (int) $exceptParticipantId]);
        if ((int) $stmt->fetchColumn() >= (int) $event['max_participants']) {
            return true;
        }
        if ($raceCategoryId) {
            $cat = $db->prepare('SELECT max_participants,
                    (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = "approved")
                    + (SELECT COUNT(*) FROM event_waitlist w WHERE w.race_category_id = rc.id AND w.status = "offered" AND w.offer_expires_at > NOW() AND w.participant_id <> :pid) AS taken
                FROM race_categories rc WHERE rc.id = :id');
            $cat->execute(['id' => $raceCategoryId, 'pid' => (int) $exceptParticipantId]);
            $c = $cat->fetch();
            if ($c && (int) $c['max_participants'] > 0 && (int) $c['taken'] >= (int) $c['max_participants']) {
                return true;
            }
        }

        return false;
    }

    /** The participant's row for this event (or null). */
    public static function entry(PDO $db, int $eventId, int $participantId): ?array
    {
        $stmt = $db->prepare('SELECT w.*, (SELECT COUNT(*) FROM event_waitlist x WHERE x.event_id = w.event_id AND x.status = "waiting"
                AND (x.joined_at < w.joined_at OR (x.joined_at = w.joined_at AND x.id < w.id))) + 1 AS position
            FROM event_waitlist w WHERE w.event_id = :eid AND w.participant_id = :pid');
        $stmt->execute(['eid' => $eventId, 'pid' => $participantId]);

        return $stmt->fetch() ?: null;
    }

    public static function join(PDO $db, array $event, int $participantId, int $userId, ?int $raceCategoryId): void
    {
        if ($event['status'] !== 'open' || $event['event_date'] < date('Y-m-d')) {
            throw new DomainException('This event is not open for registration.');
        }
        if (!empty($event['registration_deadline']) && $event['registration_deadline'] < date('Y-m-d')) {
            throw new DomainException('The registration deadline for this event has passed.');
        }
        $reg = $db->prepare('SELECT 1 FROM registrations WHERE event_id = :eid AND participant_id = :pid AND status <> "cancelled"');
        $reg->execute(['eid' => $event['id'], 'pid' => $participantId]);
        if ($reg->fetchColumn()) {
            throw new DomainException('You already have a registration for this event.');
        }
        if (!self::isFull($db, $event, $raceCategoryId, $participantId)) {
            throw new DomainException('There is still a free slot — you can register now instead of waiting.');
        }
        $existing = self::entry($db, (int) $event['id'], $participantId);
        if ($existing && in_array($existing['status'], ['waiting', 'offered'], true)) {
            throw new DomainException('You are already on the waitlist for this event.');
        }
        if ($existing) {
            // re-joining after leaving / an expired offer: back of the line
            $db->prepare('UPDATE event_waitlist SET status = "waiting", race_category_id = :cat, joined_at = NOW(), offered_at = NULL, offer_expires_at = NULL, closed_at = NULL WHERE id = :id')
                ->execute(['cat' => $raceCategoryId, 'id' => $existing['id']]);
        } else {
            $db->prepare('INSERT INTO event_waitlist (event_id, participant_id, race_category_id) VALUES (:eid, :pid, :cat)')
                ->execute(['eid' => $event['id'], 'pid' => $participantId, 'cat' => $raceCategoryId]);
        }
        Logger::log($userId, 'waitlist_joined', 'Joined the waitlist of event #' . $event['id']);
    }

    public static function leave(PDO $db, int $eventId, int $participantId, int $userId): void
    {
        $stmt = $db->prepare('UPDATE event_waitlist SET status = "left", closed_at = NOW() WHERE event_id = :eid AND participant_id = :pid AND status IN ("waiting", "offered")');
        $stmt->execute(['eid' => $eventId, 'pid' => $participantId]);
        if ($stmt->rowCount()) {
            Logger::log($userId, 'waitlist_left', 'Left the waitlist of event #' . $eventId);
            self::process($db, $eventId); // a held slot goes to the next person
        }
    }

    /** Called after a successful registration. */
    public static function markRegistered(PDO $db, int $eventId, int $participantId): void
    {
        $db->prepare('UPDATE event_waitlist SET status = "registered", closed_at = NOW() WHERE event_id = :eid AND participant_id = :pid AND status IN ("waiting", "offered")')
            ->execute(['eid' => $eventId, 'pid' => $participantId]);
    }

    /** Expires unused offers and offers freed slots to the next people in line. Returns offers made. */
    public static function process(PDO $db, ?int $eventId = null): int
    {
        $scope = $eventId ? ' AND w.event_id = ' . (int) $eventId : '';
        foreach ($db->query('SELECT w.id, w.event_id, p.user_id, e.name FROM event_waitlist w JOIN participants p ON p.id = w.participant_id JOIN events e ON e.id = w.event_id
                WHERE w.status = "offered" AND w.offer_expires_at <= NOW()' . $scope)->fetchAll() as $old) {
            $db->prepare('UPDATE event_waitlist SET status = "expired", closed_at = NOW() WHERE id = :id AND status = "offered"')->execute(['id' => $old['id']]);
            NotificationService::safely(fn() => notify((int) $old['user_id'], 'Waitlist Offer Expired',
                'The slot held for you in "' . $old['name'] . '" was not used in time and was offered to the next person. You can join the waitlist again.',
                'waitlist', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => (int) $old['event_id']]));
        }

        $offers = 0;
        $events = $db->query('SELECT DISTINCT e.* FROM events e JOIN event_waitlist w ON w.event_id = e.id AND w.status = "waiting"
            WHERE e.status = "open" AND e.event_date >= CURDATE() AND (e.registration_deadline IS NULL OR e.registration_deadline >= CURDATE())' . str_replace('w.event_id', 'e.id', $scope))->fetchAll();
        foreach ($events as $event) {
            $queue = $db->prepare('SELECT w.*, p.user_id FROM event_waitlist w JOIN participants p ON p.id = w.participant_id
                WHERE w.event_id = :eid AND w.status = "waiting" ORDER BY w.joined_at, w.id');
            $queue->execute(['eid' => $event['id']]);
            foreach ($queue->fetchAll() as $next) {
                if (self::isFull($db, $event, null, (int) $next['participant_id'])) {
                    break; // no free slot in the event at all
                }
                if ($next['race_category_id'] && self::isFull($db, $event, (int) $next['race_category_id'], (int) $next['participant_id'])) {
                    continue; // their category is still full; someone waiting for another category may fit
                }
                $expires = date('Y-m-d H:i:s', time() + self::OFFER_HOURS * 3600);
                if (!empty($event['registration_deadline']) && $expires > $event['registration_deadline'] . ' 23:59:59') {
                    $expires = $event['registration_deadline'] . ' 23:59:59';
                }
                $claim = $db->prepare('UPDATE event_waitlist SET status = "offered", offered_at = NOW(), offer_expires_at = :exp WHERE id = :id AND status = "waiting"');
                $claim->execute(['exp' => $expires, 'id' => $next['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $offers++;
                NotificationService::safely(fn() => notify((int) $next['user_id'], 'A Slot Opened for You',
                    'A slot opened in "' . $event['name'] . '" and it is held for you until ' . date('M d, g:i A', strtotime($expires)) . '. Register before then to keep it.',
                    'waitlist', ['category' => 'event', 'notice_type' => 'action_required', 'reference_type' => 'event', 'reference_id' => (int) $event['id']]));
            }
        }

        return $offers;
    }

    /** Participant's active waitlist entries (for My Registrations). */
    public static function forParticipant(PDO $db, int $participantId): array
    {
        $stmt = $db->prepare('SELECT w.*, e.name AS event_name, e.event_date, rc.name AS category_name,
                (SELECT COUNT(*) FROM event_waitlist x WHERE x.event_id = w.event_id AND x.status = "waiting"
                   AND (x.joined_at < w.joined_at OR (x.joined_at = w.joined_at AND x.id < w.id))) + 1 AS position
            FROM event_waitlist w JOIN events e ON e.id = w.event_id LEFT JOIN race_categories rc ON rc.id = w.race_category_id
            WHERE w.participant_id = :pid AND w.status IN ("waiting", "offered") ORDER BY e.event_date');
        $stmt->execute(['pid' => $participantId]);

        return $stmt->fetchAll();
    }
}
