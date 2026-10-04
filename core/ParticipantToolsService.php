<?php

/**
 * Participant self-service tools (v39): cancel a registration, add an event to a calendar,
 * saved events, event history with results, and the automatic reminders.
 */
class ParticipantToolsService
{
    /** Event statuses in which a participant may still cancel (before the event day). */
    private const CANCELLABLE_EVENT_STATUSES = ['approved', 'open', 'closed'];

    // ---------------------------------------------------------------------
    // Cancel my registration
    // ---------------------------------------------------------------------

    /** The registration if this participant may cancel it, with the reason it cannot otherwise. */
    public static function cancelCheck(PDO $db, int $registrationId, int $userId): array
    {
        $stmt = $db->prepare('SELECT r.*, e.name AS event_name, e.event_date, e.status AS event_status, e.organizer_id, o.user_id AS organizer_user_id,
                pay.id AS payment_id, pay.status AS payment_status, pay.payment_channel, pay.amount
            FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
            JOIN organizers o ON o.id = e.organizer_id LEFT JOIN payments pay ON pay.registration_id = r.id
            WHERE r.id = :id AND p.user_id = :uid');
        $stmt->execute(['id' => $registrationId, 'uid' => $userId]);
        $reg = $stmt->fetch();
        $reason = match (true) {
            !$reg => 'Registration not found.',
            !in_array($reg['status'], ['pending', 'approved'], true) => 'This registration is already ' . $reg['status'] . '.',
            !in_array($reg['event_status'], self::CANCELLABLE_EVENT_STATUSES, true) => 'This event can no longer be cancelled from here.',
            $reg['event_date'] <= date('Y-m-d') => 'Registrations can be cancelled only until the day before the event. Please contact the organizer.',
            !empty($reg['team_id']) => 'This is a team registration. Please ask the organizer to change your team.',
            self::paypalCaptureInFlight($db, $registrationId) => 'A PayPal payment for this registration is still being confirmed. Please wait a few minutes and try again.',
            default => null,
        };

        return [$reg ?: null, $reason];
    }

    /** A PayPal order the buyer already approved but EVORA has not captured yet. */
    private static function paypalCaptureInFlight(PDO $db, int $registrationId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM paypal_transactions WHERE registration_id = :id AND paypal_status = "approved"');
        $stmt->execute(['id' => $registrationId]);

        return (bool) $stmt->fetchColumn();
    }

    public static function cancelRegistration(PDO $db, int $registrationId, int $userId, string $reason): string
    {
        [$reg, $refusal] = self::cancelCheck($db, $registrationId, $userId);
        if ($refusal) {
            throw new DomainException($refusal);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 300) {
            throw new DomainException('The reason can be at most 300 characters.');
        }
        $paid = $reg['payment_status'] === 'approved';

        $db->beginTransaction();
        try {
            $upd = $db->prepare('UPDATE registrations SET status = "cancelled" WHERE id = :id AND status IN ("pending", "approved")');
            $upd->execute(['id' => $registrationId]);
            if ($upd->rowCount() !== 1) {
                throw new DomainException('This registration was already changed. Please refresh the page.');
            }
            $db->prepare('UPDATE qr_codes SET invalidated_at = NOW(), invalidated_reason = :why WHERE registration_id = :id AND invalidated_at IS NULL')
                ->execute(['why' => 'Cancelled by the participant', 'id' => $registrationId]);
            // An unfinished checkout must not be completed any more: close open PayPal orders
            // (a later capture attempt then sees a closed checkout) and the pending payment row.
            $db->prepare('UPDATE paypal_transactions SET paypal_status = "cancelled", failure_reason = "Registration cancelled by the participant" WHERE registration_id = :id AND paypal_status = "created"')->execute(['id' => $registrationId]);
            $db->prepare('UPDATE payments SET status = "cancelled" WHERE registration_id = :id AND status = "pending"')->execute(['id' => $registrationId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($userId, 'registration_self_cancelled', 'Cancelled own registration #' . $registrationId . ' (event #' . $reg['event_id'] . ')' . ($reason !== '' ? ': ' . $reason : ''));
        $refundNote = '';
        if ($paid && $reg['payment_channel'] === 'paypal') {
            $refundNote = ' They paid ' . currency($reg['amount']) . ' through PayPal — it now appears on your Refunds page.';
        } elseif ($paid) {
            $refundNote = ' They had paid ' . currency($reg['amount']) . ' (' . paymentChannelLabel($reg['payment_channel']) . ').';
        }
        NotificationService::safely(fn() => notify((int) $reg['organizer_user_id'], 'Registration Cancelled by Participant',
            'A participant cancelled their registration for "' . $reg['event_name'] . '".' . ($reason !== '' ? ' Reason: ' . $reason . '.' : '') . $refundNote,
            'registration_status', ['category' => 'event', 'notice_type' => $paid ? 'action_required' : 'informational', 'reference_type' => 'registration', 'reference_id' => $registrationId]));
        WaitlistService::process($db, (int) $reg['event_id']);

        if ($paid && $reg['payment_channel'] === 'paypal') {
            return 'Registration cancelled. Your PayPal payment of ' . currency($reg['amount']) . ' is now listed for a refund from the organizer — follow it on Payments & Receipts.';
        }
        if ($paid) {
            return 'Registration cancelled. You paid ' . currency($reg['amount']) . ' by ' . paymentChannelLabel($reg['payment_channel']) . ' — please contact the organizer about a refund.';
        }

        return 'Registration cancelled.';
    }

    // ---------------------------------------------------------------------
    // Add to calendar
    // ---------------------------------------------------------------------

    /** [start, end] timestamps of an event (3 hours long when only a start time is known). */
    private static function eventTimes(array $event): array
    {
        $start = strtotime($event['event_date'] . ' ' . ($event['event_time'] ?: '08:00:00'));

        return [$start, $start + 3 * 3600];
    }

    public static function googleCalendarUrl(array $event): string
    {
        [$start, $end] = self::eventTimes($event);
        $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);

        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event['name'],
            'dates' => $fmt($start) . '/' . $fmt($end),
            'location' => (string) $event['venue'],
            'details' => mb_substr(trim(strip_tags((string) $event['description'])), 0, 500) . "\n\nEVORA event page: " . MailService::link('event-view', ['id' => $event['id']]),
        ]);
    }

    /** iCalendar (.ics) file content for phones and Outlook. */
    public static function ics(array $event): string
    {
        [$start, $end] = self::eventTimes($event);
        $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
        $esc = fn(string $s) => str_replace(["\\", "\r\n", "\n", ',', ';'], ["\\\\", '\n', '\n', '\,', '\;'], $s);
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EVORA//Event Management//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:evora-event-' . (int) $event['id'] . '@evora',
            'DTSTAMP:' . $fmt(time()),
            'DTSTART:' . $fmt($start),
            'DTEND:' . $fmt($end),
            'SUMMARY:' . $esc($event['name']),
            'LOCATION:' . $esc((string) $event['venue']),
            'DESCRIPTION:' . $esc(mb_substr(trim(strip_tags((string) $event['description'])), 0, 500)),
            'BEGIN:VALARM', 'TRIGGER:-P1D', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($event['name'] . ' is tomorrow'), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    // ---------------------------------------------------------------------
    // Saved events
    // ---------------------------------------------------------------------

    public static function isSaved(PDO $db, int $userId, int $eventId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM event_favorites WHERE user_id = :u AND event_id = :e');
        $stmt->execute(['u' => $userId, 'e' => $eventId]);

        return (bool) $stmt->fetchColumn();
    }

    /** Saves or un-saves; returns the new state. */
    public static function toggleSaved(PDO $db, int $userId, int $eventId): bool
    {
        if (self::isSaved($db, $userId, $eventId)) {
            $db->prepare('DELETE FROM event_favorites WHERE user_id = :u AND event_id = :e')->execute(['u' => $userId, 'e' => $eventId]);

            return false;
        }
        $ok = $db->prepare('SELECT 1 FROM events WHERE id = :id AND status IN ("approved", "open", "closed", "ongoing")');
        $ok->execute(['id' => $eventId]);
        if (!$ok->fetchColumn()) {
            throw new DomainException('This event cannot be saved.');
        }
        $db->prepare('INSERT IGNORE INTO event_favorites (user_id, event_id) VALUES (:u, :e)')->execute(['u' => $userId, 'e' => $eventId]);

        return true;
    }

    public static function savedEvents(PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT e.*, f.created_at AS saved_at, c.name AS category_name,
                (SELECT r.status FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE r.event_id = e.id AND p.user_id = :u1) AS my_status
            FROM event_favorites f JOIN events e ON e.id = f.event_id JOIN event_categories c ON c.id = e.category_id
            WHERE f.user_id = :u2 ORDER BY e.event_date >= CURDATE() DESC, e.event_date');
        $stmt->execute(['u1' => $userId, 'u2' => $userId]);

        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------------
    // My event history / results
    // ---------------------------------------------------------------------

    public static function history(PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT r.id AS registration_id, r.status, r.distance_category, r.bib_number, r.race_category_id, e.id AS event_id, e.name AS event_name,
                e.event_date, e.event_time, e.venue, e.status AS event_status, c.slug AS category_slug, c.name AS category_name,
                (SELECT MIN(a.scanned_at) FROM attendance a WHERE a.registration_id = r.id) AS checked_in_at,
                rr.finish_time, cert.id AS certificate_id
            FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
            JOIN event_categories c ON c.id = e.category_id
            LEFT JOIN race_results rr ON rr.registration_id = r.id
            LEFT JOIN certificates cert ON cert.registration_id = r.id
            WHERE p.user_id = :uid AND r.status = "approved" AND (e.event_date < CURDATE() OR e.status IN ("ongoing", "completed"))
            ORDER BY e.event_date DESC');
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['duration_seconds'] = null;
            $row['rank_category'] = $row['rank_overall'] = $row['finishers_category'] = $row['finishers_overall'] = null;
            if ($row['finish_time']) {
                // Same rule as the organizer's results page: finish time minus the single gun start.
                $gun = strtotime($row['event_date'] . ' ' . $row['event_time']);
                $fin = strtotime($row['finish_time']);
                $row['duration_seconds'] = $fin >= $gun ? $fin - $gun : null;
                $rank = $db->prepare('SELECT
                        (SELECT COUNT(*) FROM race_results x JOIN registrations y ON y.id = x.registration_id WHERE y.event_id = :e1 AND x.finish_time < :f1) + 1 AS overall,
                        (SELECT COUNT(*) FROM race_results x JOIN registrations y ON y.id = x.registration_id WHERE y.event_id = :e2) AS overall_total,
                        (SELECT COUNT(*) FROM race_results x JOIN registrations y ON y.id = x.registration_id WHERE y.event_id = :e3 AND y.distance_category <=> :d1 AND x.finish_time < :f2) + 1 AS cat,
                        (SELECT COUNT(*) FROM race_results x JOIN registrations y ON y.id = x.registration_id WHERE y.event_id = :e4 AND y.distance_category <=> :d2) AS cat_total');
                $rank->execute(['e1' => $row['event_id'], 'f1' => $row['finish_time'], 'e2' => $row['event_id'], 'e3' => $row['event_id'], 'd1' => $row['distance_category'],
                    'f2' => $row['finish_time'], 'e4' => $row['event_id'], 'd2' => $row['distance_category']]);
                $rk = $rank->fetch();
                $row['rank_overall'] = (int) $rk['overall'];
                $row['finishers_overall'] = (int) $rk['overall_total'];
                $row['rank_category'] = (int) $rk['cat'];
                $row['finishers_category'] = (int) $rk['cat_total'];
            }
        }

        return $rows;
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    // ---------------------------------------------------------------------
    // Automatic reminders (scripts/participant_jobs.php)
    // ---------------------------------------------------------------------

    /** "Your event is tomorrow" for approved registrations, and "registration closes soon" for saved events. */
    public static function sendReminders(PDO $db): array
    {
        $sent = ['event_tomorrow' => 0, 'saved_deadline_soon' => 0];
        $log = $db->prepare('INSERT IGNORE INTO participant_reminders_sent (user_id, event_id, kind) VALUES (:u, :e, :k)');

        $tomorrow = $db->query('SELECT p.user_id, e.id, e.name, e.event_date, e.event_time, e.venue FROM registrations r
            JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
            WHERE r.status = "approved" AND e.event_date = CURDATE() + INTERVAL 1 DAY AND e.status IN ("approved", "open", "closed", "ongoing")')->fetchAll();
        foreach ($tomorrow as $r) {
            $log->execute(['u' => $r['user_id'], 'e' => $r['id'], 'k' => 'event_tomorrow']);
            if ($log->rowCount() === 1) {
                $sent['event_tomorrow']++;
                NotificationService::safely(fn() => notify((int) $r['user_id'], 'Reminder: Your Event Is Tomorrow',
                    '"' . $r['name'] . '" is tomorrow, ' . date('M d', strtotime($r['event_date'])) . ($r['event_time'] ? ' at ' . date('g:i A', strtotime($r['event_time'])) : '')
                    . ', at ' . $r['venue'] . '. Bring your QR code (QR Codes page, or download your e-ticket).',
                    'event_reminder', ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => (int) $r['id']]));
            }
        }

        $closing = $db->query('SELECT f.user_id, e.id, e.name, e.registration_deadline FROM event_favorites f JOIN events e ON e.id = f.event_id
            WHERE e.status = "open" AND e.registration_deadline BETWEEN CURDATE() AND CURDATE() + INTERVAL 2 DAY
              AND NOT EXISTS (SELECT 1 FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE r.event_id = e.id AND p.user_id = f.user_id)')->fetchAll();
        foreach ($closing as $r) {
            $log->execute(['u' => $r['user_id'], 'e' => $r['id'], 'k' => 'saved_deadline_soon']);
            if ($log->rowCount() === 1) {
                $sent['saved_deadline_soon']++;
                NotificationService::safely(fn() => notify((int) $r['user_id'], 'Registration Closes Soon',
                    'Registration for "' . $r['name'] . '", which you saved, closes on ' . date('M d', strtotime($r['registration_deadline'])) . '. Register before then if you want to join.',
                    'event_reminder', ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => (int) $r['id']]));
            }
        }

        return $sent;
    }
}
