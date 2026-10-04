<?php

/**
 * Staff check-in tools (v40): find a participant without their QR, a live check-in counter with
 * the "not yet arrived" list, and undoing a mistaken check-in.
 *
 * Every method requires the staff member to hold the "scan_qr" permission for the event. The
 * actual check-in still goes through staff-scan-verify (same rules as a QR scan); manual
 * check-in only replaces the QR token with a registration id.
 */
class CheckInToolsService
{
    public const UNDO_MINUTES = 15;

    public static function canScan(PDO $db, int $staffUserId, int $eventId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM event_staff es JOIN staff_permissions sp ON sp.event_staff_id = es.id
            WHERE es.user_id = :uid AND es.event_id = :eid AND sp.permission_key = "scan_qr"');
        $stmt->execute(['uid' => $staffUserId, 'eid' => $eventId]);

        return (bool) $stmt->fetchColumn();
    }

    /** Registrations of the event matching a name, email, registration number or bib. */
    public static function search(PDO $db, int $eventId, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $stmt = $db->prepare('SELECT r.id, r.status, r.bib_number, r.distance_category, u.full_name, u.email,
                a.scanned_at AS checked_in_at
            FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
            LEFT JOIN attendance a ON a.registration_id = r.id AND a.event_id = r.event_id
            WHERE r.event_id = :eid AND (u.full_name LIKE :q1 OR u.email LIKE :q2 OR r.bib_number = :bib OR r.id = :rid)
            ORDER BY (r.status = "approved") DESC, u.full_name LIMIT 15');
        $stmt->execute(['eid' => $eventId, 'q1' => $like, 'q2' => $like, 'bib' => $q, 'rid' => ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0]);

        return array_map(fn($r) => [
            'registration_id' => (int) $r['id'],
            'name' => $r['full_name'],
            'email' => self::maskEmail((string) $r['email']),
            'bib' => $r['bib_number'],
            'category' => $r['distance_category'],
            'status' => $r['status'],
            'checked_in_at' => $r['checked_in_at'] ? date('g:i A', strtotime($r['checked_in_at'])) : null,
        ], $stmt->fetchAll());
    }

    /** Live numbers for the event, the people not checked in yet, and this staff member's undoable check-ins. */
    public static function stats(PDO $db, int $eventId, int $staffUserId): array
    {
        // Expected = approved registrations whose payment is settled (or that need no payment).
        $base = 'FROM registrations r JOIN events e ON e.id = r.event_id LEFT JOIN race_categories rc ON rc.id = r.race_category_id
            LEFT JOIN payments pay ON pay.registration_id = r.id
            WHERE r.event_id = :eid AND r.status = "approved" AND (COALESCE(rc.fee, e.fee) <= 0 OR pay.status = "approved")';
        $exp = $db->prepare('SELECT COUNT(*) ' . $base);
        $exp->execute(['eid' => $eventId]);
        $expected = (int) $exp->fetchColumn();

        $in = $db->prepare('SELECT COUNT(*) FROM attendance WHERE event_id = :eid');
        $in->execute(['eid' => $eventId]);
        $checkedIn = (int) $in->fetchColumn();

        $missing = $db->prepare('SELECT r.id, u.full_name, r.bib_number, r.distance_category ' . str_replace('FROM registrations r', 'FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id', $base)
            . ' AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.registration_id = r.id AND a.event_id = r.event_id) ORDER BY u.full_name LIMIT 500');
        $missing->execute(['eid' => $eventId]);

        $mine = $db->prepare('SELECT a.id, a.scanned_at, u.full_name FROM attendance a JOIN registrations r ON r.id = a.registration_id
            JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
            WHERE a.event_id = :eid AND a.staff_id = :sid AND a.scanned_at >= NOW() - INTERVAL ' . self::UNDO_MINUTES . ' MINUTE ORDER BY a.id DESC');
        $mine->execute(['eid' => $eventId, 'sid' => $staffUserId]);

        return [
            'checked_in' => $checkedIn,
            'expected' => $expected,
            'remaining' => max(0, $expected - $checkedIn),
            'percent' => $expected > 0 ? min(100, (int) round($checkedIn * 100 / $expected)) : 0,
            'not_arrived' => array_map(fn($r) => ['registration_id' => (int) $r['id'], 'name' => $r['full_name'], 'bib' => $r['bib_number'], 'category' => $r['distance_category']], $missing->fetchAll()),
            'undoable' => array_map(fn($r) => ['attendance_id' => (int) $r['id'], 'name' => $r['full_name'], 'time' => date('g:i A', strtotime($r['scanned_at'])),
                'until' => date('g:i A', strtotime($r['scanned_at']) + self::UNDO_MINUTES * 60)], $mine->fetchAll()),
            'updated_at' => date('g:i:s A'),
        ];
    }

    /** Removes a check-in this staff member recorded in the last 15 minutes. Returns the participant's name. */
    public static function undo(PDO $db, int $attendanceId, int $staffUserId, string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
            throw new DomainException('Give a short reason (3–200 characters) for undoing the check-in.');
        }
        $stmt = $db->prepare('SELECT a.*, e.name AS event_name, u.full_name, p.user_id AS participant_user_id, c.slug AS category_slug
            FROM attendance a JOIN events e ON e.id = a.event_id JOIN event_categories c ON c.id = e.category_id
            JOIN registrations r ON r.id = a.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
            WHERE a.id = :id');
        $stmt->execute(['id' => $attendanceId]);
        $att = $stmt->fetch();
        if (!$att || !self::canScan($db, $staffUserId, (int) $att['event_id'])) {
            throw new DomainException('Check-in not found.');
        }
        if ((int) $att['staff_id'] !== $staffUserId) {
            throw new DomainException('Only the staff member who recorded this check-in can undo it. Ask the organizer if it needs to change.');
        }
        if (strtotime($att['scanned_at']) < time() - self::UNDO_MINUTES * 60) {
            throw new DomainException('A check-in can be undone only within ' . self::UNDO_MINUTES . ' minutes. Ask the organizer if it needs to change.');
        }

        $db->beginTransaction();
        try {
            $del = $db->prepare('DELETE FROM attendance WHERE id = :id AND staff_id = :sid');
            $del->execute(['id' => $attendanceId, 'sid' => $staffUserId]);
            if ($del->rowCount() !== 1) {
                throw new DomainException('This check-in was already undone.');
            }
            // A seminar certificate issued by this check-in is revoked with it.
            $revoked = 0;
            if ($att['category_slug'] === 'seminar-workshop') {
                $rev = $db->prepare('UPDATE certificates SET status = "revoked" WHERE registration_id = :rid AND status = "issued" AND issued_at >= :since');
                $rev->execute(['rid' => $att['registration_id'], 'since' => $att['scanned_at']]);
                $revoked = $rev->rowCount();
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($staffUserId, 'attendance_undone', 'Undid check-in of registration #' . $att['registration_id'] . ' (' . $att['full_name'] . ', event #' . $att['event_id']
            . ', checked in ' . $att['scanned_at'] . '). Reason: ' . $reason . ($revoked ? '. Certificate revoked.' : ''));
        NotificationService::safely(fn() => notify((int) $att['participant_user_id'], 'Check-in Reversed',
            'Your check-in to "' . $att['event_name'] . '" was reversed by event staff (' . $reason . '). Please have your QR code scanned again when you arrive.',
            'check_in', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'registration', 'reference_id' => (int) $att['registration_id']]));

        return $att['full_name'];
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email) + [1 => ''];

        return $domain === '' ? '' : mb_substr($local, 0, 2) . '•••@' . $domain;
    }
}
