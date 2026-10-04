<?php

/**
 * Scheduled event housekeeping (v45), run by scripts/participant_jobs.php:
 *
 * - autoCompletePastEvents(): an open/closed/ongoing event whose date has passed becomes
 *   "completed" with the same follow-ups as the organizer's "Mark Completed" button
 *   (registration closed + payout request, evaluation requests, auto-issued certificates).
 *   An event still having upcoming or unfinished matches/sessions is left alone.
 * - remindUnpaid(): one "you have not paid yet" reminder per registration after 24 hours.
 * - expireUnpaidAfterEvent(): an unpaid registration of an event that is over is cancelled.
 *
 * Unpaid registrations are never cancelled while the event is upcoming: they do not hold a
 * slot (only approved ones count), and a cancelled registration cannot be made again.
 */
class EventLifecycleService
{
    /** Days after the event date before it is completed automatically (time for results/check-in). */
    public const COMPLETE_AFTER_DAYS = 1;
    public const UNPAID_REMINDER_HOURS = 24;

    /** Paid registration with no payment made or under review (SQL condition on alias r / e). */
    private const UNPAID_SQL = 'r.status = "pending"
        AND COALESCE((SELECT rc.fee FROM race_categories rc WHERE rc.id = r.race_category_id), e.fee) > 0
        AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.registration_id = r.id AND pay.status IN ("pending", "approved", "refunded"))';

    /** @return int[] ids of the events completed */
    public static function autoCompletePastEvents(PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT e.id, e.name, e.status, o.user_id AS organizer_user_id FROM events e
             JOIN organizers o ON o.id = e.organizer_id
             WHERE e.status IN ("open", "closed", "ongoing")
               AND e.event_date < CURDATE() - INTERVAL ' . self::COMPLETE_AFTER_DAYS . ' DAY
               AND NOT EXISTS (SELECT 1 FROM matches m WHERE m.event_id = e.id AND m.status IN ("scheduled", "ongoing", "paused")
                               AND m.scheduled_at >= CURDATE() - INTERVAL 1 DAY)
               AND NOT EXISTS (SELECT 1 FROM event_schedule s WHERE s.event_id = e.id AND s.status IN ("scheduled", "ongoing")
                               AND s.session_date >= CURDATE() - INTERVAL 1 DAY)'
        );
        $stmt->execute();
        $done = [];
        foreach ($stmt->fetchAll() as $event) {
            $eventId = (int) $event['id'];
            try {
                $check = $db->prepare('SELECT status FROM events WHERE id = :id');
                $check->execute(['id' => $eventId]);
                if ($check->fetchColumn() !== $event['status']) {
                    continue; // changed by the organizer meanwhile
                }
                EventStatusService::changeStatus($db, $eventId, 'completed', null);
                Logger::log(null, 'event_auto_completed', 'Event #' . $eventId . ' (' . $event['name'] . ') ' . $event['status'] . ' -> completed: event date has passed');
                $done[] = $eventId;
            } catch (Throwable $e) {
                Logger::log(null, 'event_auto_complete_error', 'Event #' . $eventId . ': ' . $e->getMessage());
                continue;
            }

            // Same follow-ups as close_registration / mark_completed in organizer/event_status_action.php.
            EventRegistrationService::markClosed($db, $eventId);
            try {
                PayoutRequestService::createForEvent($db, $eventId, null);
            } catch (Throwable $e) {
                Logger::log(null, 'payout_request_error', 'Event #' . $eventId . ': ' . $e->getMessage());
            }
            NotificationService::safely(fn () => EvaluationService::notifyEventCompleted($db, $eventId));
            try {
                $issued = CertificateService::autoIssueForEvent($db, $eventId);
                if ($issued > 0) {
                    Logger::log(null, 'certificates_auto_issued', 'Auto-issued ' . $issued . ' certificate(s) for event #' . $eventId . ' on automatic completion.');
                }
            } catch (Throwable $e) {
                Logger::log(null, 'certificate_auto_issue_error', 'Event #' . $eventId . ': ' . $e->getMessage());
            }
            NotificationService::safely(fn () => notify((int) $event['organizer_user_id'], 'Event Completed',
                '"' . $event['name'] . '" was marked as completed automatically because its date has passed. Participants were asked for feedback.',
                'event_update', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]));
        }

        return $done;
    }

    /** One reminder per unpaid registration of an upcoming PayPal event. Returns how many were sent. */
    public static function remindUnpaid(PDO $db): int
    {
        if (!defined('PAYPAL_ENABLED') || !PAYPAL_ENABLED) {
            return 0; // nobody can pay right now
        }
        $rows = $db->query(
            'SELECT r.id AS registration_id, p.user_id, e.id AS event_id, e.name, e.registration_deadline FROM registrations r
             JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
             WHERE ' . self::UNPAID_SQL . '
               AND r.registered_at <= NOW() - INTERVAL ' . self::UNPAID_REMINDER_HOURS . ' HOUR
               AND e.status = "open" AND e.payment_provider = "paypal" AND e.event_date >= CURDATE()
               AND (e.registration_deadline IS NULL OR e.registration_deadline >= CURDATE())'
        )->fetchAll();
        $log = $db->prepare('INSERT IGNORE INTO participant_reminders_sent (user_id, event_id, kind) VALUES (:u, :e, "unpaid_registration")');
        $sent = 0;
        foreach ($rows as $r) {
            $log->execute(['u' => $r['user_id'], 'e' => $r['event_id']]);
            if ($log->rowCount() !== 1) {
                continue;
            }
            $sent++;
            NotificationService::safely(fn () => notify((int) $r['user_id'], 'Payment Not Completed Yet',
                'You registered for "' . $r['name'] . '" but have not paid yet. Your slot is confirmed only after payment, so other participants can still fill the event. '
                . 'Pay from My Registrations' . ($r['registration_deadline'] ? ' before ' . date('M d', strtotime($r['registration_deadline'])) : '') . '.',
                'payment_issue', ['category' => 'payment', 'notice_type' => 'action_required', 'reference_type' => 'registration', 'reference_id' => (int) $r['registration_id']]));
        }

        return $sent;
    }

    /** Cancels unpaid registrations of events that are over. Returns how many were cancelled. */
    public static function expireUnpaidAfterEvent(PDO $db): int
    {
        $rows = $db->query(
            'SELECT r.id, r.event_id, p.user_id, e.name FROM registrations r
             JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
             WHERE ' . self::UNPAID_SQL . '
               AND (e.status IN ("completed", "archived") OR e.event_date < CURDATE())
               AND NOT EXISTS (SELECT 1 FROM paypal_transactions t WHERE t.registration_id = r.id AND t.paypal_status = "approved")'
        )->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            $db->beginTransaction();
            try {
                $upd = $db->prepare('UPDATE registrations SET status = "cancelled" WHERE id = :id AND status = "pending"');
                $upd->execute(['id' => $r['id']]);
                if ($upd->rowCount() !== 1) {
                    $db->rollBack();
                    continue;
                }
                // Close any unfinished checkout so it can no longer be completed (as on a participant cancel).
                $db->prepare('UPDATE paypal_transactions SET paypal_status = "cancelled", failure_reason = "Registration cancelled: event ended before payment" WHERE registration_id = :id AND paypal_status = "created"')
                    ->execute(['id' => $r['id']]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                Logger::log(null, 'unpaid_expire_error', 'Registration #' . $r['id'] . ': ' . $e->getMessage());
                continue;
            }
            $count++;
            Logger::log(null, 'registration_unpaid_expired', 'Registration #' . $r['id'] . ' (event #' . $r['event_id'] . ') cancelled: event ended before payment');
            NotificationService::safely(fn () => notify((int) $r['user_id'], 'Registration Closed',
                'Your registration for "' . $r['name'] . '" was closed because the event has ended and the payment was never completed. Nothing was charged.',
                'registration_status', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'registration', 'reference_id' => (int) $r['id']]));
        }

        return $count;
    }
}
