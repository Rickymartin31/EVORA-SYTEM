<?php
/**
 * Race kit / item claiming schedule (v71).
 *
 * The organizer sets one or more claiming days (date, time, place) for an event's items, usually
 * before event day. "Remind Participants to Claim" notifies (bell + email) every participant who
 * can claim and still has an item to collect (sizes of one item count as one, v73); on the last claiming day EVORA does the same once,
 * automatically (scripts/participant_jobs.php). Claiming itself is never blocked by the schedule:
 * staff can still release items outside it, e.g. on event day.
 *
 * A participant can claim when the registration is approved and, for a paid event, the payment is
 * approved — the same rule staff-claim-action uses.
 */
class ClaimScheduleService
{
    public const MAX_SLOTS = 10;
    /** A manual reminder cannot be sent again within this many minutes (double clicks, spam). */
    public const RESEND_MINUTES = 10;
    /** Automatic reminder on the last claiming day, from this time on. */
    public const AUTO_REMINDER_FROM = '07:00:00';
    /** Events that can still send reminders. */
    public const ACTIVE_EVENT_STATUSES = ['approved', 'open', 'closed', 'ongoing'];

    /** Claiming days of one event, earliest first. */
    public static function slots(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT * FROM event_claim_schedules WHERE event_id = :e ORDER BY claim_date, start_time');
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetchAll();
    }

    /** event_id => claiming days, for several events at once (participant and staff pages). */
    public static function slotsForEvents(PDO $db, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if (!$eventIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $db->prepare("SELECT * FROM event_claim_schedules WHERE event_id IN ($in) ORDER BY claim_date, start_time");
        $stmt->execute($eventIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['event_id']][] = $row;
        }

        return $out;
    }

    /**
     * Adds a claiming day. Throws DomainException with a user-facing message.
     * Days may be set from today up to the event date (claiming on event day is allowed).
     */
    public static function addSlot(PDO $db, array $event, int $userId, string $date, string $start, string $end, string $location, string $note): void
    {
        $location = trim($location);
        $note = trim($note);
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        $s = DateTime::createFromFormat('H:i', substr($start, 0, 5));
        $e = DateTime::createFromFormat('H:i', substr($end, 0, 5));
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new DomainException('Please choose a valid claiming date.');
        }
        if (!$s || !$e) {
            throw new DomainException('Please enter a valid start and end time.');
        }
        if ($e <= $s) {
            throw new DomainException('The end time must be later than the start time.');
        }
        if ($date < date('Y-m-d')) {
            throw new DomainException('The claiming date cannot be in the past.');
        }
        if ($date > $event['event_date']) {
            throw new DomainException('The claiming date cannot be after the event date (' . date('M d, Y', strtotime($event['event_date'])) . ').');
        }
        if ($location === '' || mb_strlen($location) > 255) {
            throw new DomainException('Please enter where the items will be claimed (up to 255 characters).');
        }
        if (mb_strlen($note) > 300) {
            throw new DomainException('The note can be at most 300 characters.');
        }
        if (count(self::slots($db, (int) $event['id'])) >= self::MAX_SLOTS) {
            throw new DomainException('An event can have at most ' . self::MAX_SLOTS . ' claiming schedules.');
        }
        $db->prepare('INSERT INTO event_claim_schedules (event_id, claim_date, start_time, end_time, location, note, created_by)
                      VALUES (:e, :d, :s, :t, :l, :n, :u)')
            ->execute(['e' => $event['id'], 'd' => $date, 's' => $s->format('H:i:00'), 't' => $e->format('H:i:00'),
                'l' => $location, 'n' => $note !== '' ? $note : null, 'u' => $userId]);
    }

    public static function deleteSlot(PDO $db, int $eventId, int $slotId): bool
    {
        $stmt = $db->prepare('DELETE FROM event_claim_schedules WHERE id = :id AND event_id = :e');
        $stmt->execute(['id' => $slotId, 'e' => $eventId]);

        return $stmt->rowCount() === 1;
    }

    /** Claiming days that have not ended yet (today's counts until its end time). */
    public static function upcoming(array $slots): array
    {
        $today = date('Y-m-d');
        $now = date('H:i:s');

        return array_values(array_filter($slots, fn($s) => $s['claim_date'] > $today || ($s['claim_date'] === $today && $s['end_time'] > $now)));
    }

    /** "Sat, Oct 25, 1:00 PM – 6:00 PM" */
    public static function slotWhen(array $slot): string
    {
        return date('D, M j', strtotime($slot['claim_date'])) . ', '
            . date('g:i A', strtotime($slot['start_time'])) . ' – ' . date('g:i A', strtotime($slot['end_time']));
    }

    /**
     * Participants who can claim and still have at least one item of the event to collect:
     * list of [user_id, registration_id].
     */
    public static function recipients(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT p.user_id, r.id AS registration_id
             FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN events e ON e.id = r.event_id
             LEFT JOIN race_categories rc ON rc.id = r.race_category_id
             WHERE r.event_id = :e AND r.status = "approved"
               AND (COALESCE(rc.fee, e.fee) <= 0
                    OR EXISTS (SELECT 1 FROM payments pay WHERE pay.registration_id = r.id AND pay.status = "approved"))
               AND EXISTS (SELECT 1 FROM event_items i WHERE i.event_id = r.event_id
                AND NOT EXISTS (SELECT 1 FROM item_claims c JOIN event_items i2 ON i2.id = c.event_item_id
                                WHERE c.registration_id = r.id AND i2.event_id = i.event_id AND i2.item_name = i.item_name))'
        );
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetchAll();
    }

    /** registration_id => true when that registration still has an item to claim (participant page). */
    public static function unclaimedRegistrations(PDO $db, array $registrationIds): array
    {
        $registrationIds = array_values(array_unique(array_map('intval', $registrationIds)));
        if (!$registrationIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($registrationIds), '?'));
        $stmt = $db->prepare("SELECT r.id FROM registrations r WHERE r.id IN ($in)
            AND EXISTS (SELECT 1 FROM event_items i WHERE i.event_id = r.event_id
                AND NOT EXISTS (SELECT 1 FROM item_claims c JOIN event_items i2 ON i2.id = c.event_item_id
                                WHERE c.registration_id = r.id AND i2.event_id = i.event_id AND i2.item_name = i.item_name))");
        $stmt->execute($registrationIds);

        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** The latest reminder sent for an event (manual or automatic), or null. */
    public static function lastReminder(PDO $db, int $eventId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM event_claim_reminders WHERE event_id = :e ORDER BY sent_at DESC, id DESC LIMIT 1');
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetch() ?: null;
    }

    /** The notification text (notifications.message holds at most 500 characters). */
    public static function message(array $event, array $slots, array $itemNames, bool $lastDay = false): string
    {
        $items = $itemNames ? implode(', ', array_slice($itemNames, 0, 4)) . (count($itemNames) > 4 ? ' and more' : '') : 'your event items';
        $head = ($lastDay ? 'Last day to claim ' : 'You can now claim ') . $items . ' for "' . $event['name'] . '". ';
        $tail = ' Bring your QR code (QR Codes page or e-ticket).';
        $parts = [];
        foreach ($slots as $i => $slot) {
            $line = self::slotWhen($slot) . ' at ' . $slot['location'];
            $candidate = $head . 'Claiming: ' . implode('; ', array_merge($parts, [$line])) . '.' . $tail;
            if (mb_strlen($candidate) > 480) {
                $parts[] = 'and ' . (count($slots) - $i) . ' more (see My Registrations)';
                break;
            }
            $parts[] = $line;
        }
        $message = $head . 'Claiming: ' . implode('; ', $parts) . '.' . $tail;

        return mb_strlen($message) > 500 ? mb_substr($message, 0, 497) . '...' : $message;
    }

    /**
     * The organizer's "Remind Participants to Claim". Returns how many participants were notified.
     * Throws DomainException with a user-facing message.
     */
    public static function sendManualReminder(PDO $db, array $event, int $userId): int
    {
        $eventId = (int) $event['id'];
        if (!in_array($event['status'], self::ACTIVE_EVENT_STATUSES, true)) {
            throw new DomainException('Reminders can only be sent for an approved event that has not ended.');
        }
        $all = self::slots($db, $eventId);
        if (!$all) {
            throw new DomainException('Add at least one claiming schedule first, so participants know when and where to claim.');
        }
        $slots = self::upcoming($all);
        if (!$slots) {
            throw new DomainException('All claiming schedules have already passed. Add a new claiming schedule first.');
        }
        $last = $db->prepare('SELECT sent_at FROM event_claim_reminders WHERE event_id = :e AND kind = "manual" AND sent_at > NOW() - INTERVAL ' . self::RESEND_MINUTES . ' MINUTE LIMIT 1');
        $last->execute(['e' => $eventId]);
        if ($last->fetch()) {
            throw new DomainException('A reminder was just sent. Please wait a few minutes before sending another one.');
        }
        $recipients = self::recipients($db, $eventId);
        if (!$recipients) {
            throw new DomainException('There is no one to remind: every participant who can claim has already claimed all items.');
        }
        $count = self::notifyAll($db, $event, $slots, $recipients, false);
        $db->prepare('INSERT INTO event_claim_reminders (event_id, kind, sent_by, recipients) VALUES (:e, "manual", :u, :n)')
            ->execute(['e' => $eventId, 'u' => $userId, 'n' => $count]);
        Logger::log($userId, 'claim_reminder_sent', 'Sent an item claiming reminder to ' . $count . ' participant(s) of event #' . $eventId);

        return $count;
    }

    /**
     * Automatic reminder (cron): on the last claiming day of an event, from 7:00 AM until that
     * day's claiming ends, once per participant who still has an item to claim.
     * Returns how many participants were notified in this run.
     */
    public static function sendAutomaticReminders(PDO $db): int
    {
        $now = date('H:i:s');
        if ($now < self::AUTO_REMINDER_FROM) {
            return 0;
        }
        $in = implode(',', array_fill(0, count(self::ACTIVE_EVENT_STATUSES), '?'));
        // Events whose last claiming day is today and whose last claiming window has not ended.
        $stmt = $db->prepare(
            "SELECT e.* FROM events e
             JOIN (SELECT event_id, MAX(claim_date) AS last_day FROM event_claim_schedules GROUP BY event_id) s ON s.event_id = e.id
             WHERE s.last_day = CURDATE() AND e.status IN ($in)
               AND EXISTS (SELECT 1 FROM event_claim_schedules x WHERE x.event_id = e.id AND x.claim_date = CURDATE() AND x.end_time > ?)"
        );
        $stmt->execute(array_merge(self::ACTIVE_EVENT_STATUSES, [$now]));
        $total = 0;
        $mark = $db->prepare('INSERT IGNORE INTO participant_reminders_sent (user_id, event_id, kind) VALUES (:u, :e, "kit_claim_last_day")');
        foreach ($stmt->fetchAll() as $event) {
            $due = [];
            foreach (self::recipients($db, (int) $event['id']) as $r) {
                $mark->execute(['u' => $r['user_id'], 'e' => $event['id']]);
                if ($mark->rowCount() === 1) {
                    $due[] = $r;
                }
            }
            if (!$due) {
                continue;
            }
            $count = self::notifyAll($db, $event, self::upcoming(self::slots($db, (int) $event['id'])), $due, true);
            $db->prepare('INSERT INTO event_claim_reminders (event_id, kind, sent_by, recipients) VALUES (:e, "automatic", NULL, :n)')
                ->execute(['e' => $event['id'], 'n' => $count]);
            Logger::log(null, 'claim_reminder_auto', 'Automatic last-day claiming reminder sent to ' . $count . ' participant(s) of event #' . $event['id']);
            $total += $count;
        }

        return $total;
    }

    private static function notifyAll(PDO $db, array $event, array $slots, array $recipients, bool $lastDay): int
    {
        // v74: "Race Kit (T-shirt and race bib)" when the organizer listed what is inside
        $items = $db->prepare('SELECT item_name, MAX(contents) AS contents FROM event_items WHERE event_id = :e GROUP BY item_name ORDER BY item_name');
        $items->execute(['e' => $event['id']]);
        $names = array_map(fn($i) => $i['item_name'] . (trim((string) $i['contents']) !== '' ? ' (' . trim((string) $i['contents']) . ')' : ''), $items->fetchAll());
        $message = self::message($event, $slots, $names, $lastDay);
        $title = $lastDay ? 'Last Day to Claim Your Event Items' : 'Claim Your Event Items';
        $sentTo = [];
        foreach ($recipients as $r) {
            $uid = (int) $r['user_id'];
            if (isset($sentTo[$uid])) {
                continue; // one message per person
            }
            $sentTo[$uid] = true;
            NotificationService::safely(fn() => notify($uid, $title, $message, 'kit_claim_reminder',
                ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => (int) $r['registration_id']]));
        }

        return count($sentTo);
    }
}
