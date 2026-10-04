<?php

/**
 * Event evaluations and EVORA platform evaluations (v32).
 *
 *   Event completed -> participants notified -> floating prompt
 *   -> participant rates 1-5 (+ optional comment) -> event_feedback row
 *   -> organizer notified, sees it on the dashboard "Event Performance" card
 *
 *   Meaningful activity (participant: approved registration; organizer: a
 *   published event) -> floating "How was your experience using EVORA?"
 *   -> system_evaluations row (quick rating; the ISO/IEC 25010 survey stays)
 *   -> admins notified, see it under Dashboard -> System Feedback Analytics
 *
 * Existing tables are reused: event_feedback (one row per registration) and
 * system_evaluations (one row per user). Database triggers enforce the same
 * rules (completed events only, no edits, no duplicates).
 */
class EvaluationService
{
    public const COMPLETED_EVENT_STATUSES = ['completed', 'archived'];

    /** Platform rating per row: the quick overall rating, else the ISO survey average. */
    public const PLATFORM_RATING_SQL = 'COALESCE(se.overall_rating, (se.functionality + se.usability + se.reliability + se.performance_efficiency
        + se.security + se.compatibility + se.maintainability + se.portability) / 8)';

    /** How long "Later" hides a prompt. */
    public const SNOOZE_SECONDS = 86400;

    /** Roles that rate EVORA (admins only view the analytics). */
    public const PLATFORM_ROLES = ['participant', 'organizer', 'staff'];

    /** Optional category questions per role (v32.1, analytics only): column => question. */
    public const CATEGORY_QUESTIONS = [
        'participant' => [
            'registration_rating' => 'How easy was event registration?',
            'payment_rating' => 'How was the payment experience?',
        ],
        'organizer' => [
            'event_management_rating' => 'How easy is event management?',
            'payment_rating' => 'How useful are payment and participant tools?',
        ],
        'staff' => [
            'checkin_rating' => 'How easy is attendance/check-in management?',
            'event_management_rating' => 'How useful are event operation tools?',
        ],
    ];

    public const CATEGORY_LABELS = [
        'registration_rating' => 'Registration Experience',
        'payment_rating' => 'Payment Experience',
        'event_management_rating' => 'Event Management',
        'checkin_rating' => 'QR/Check-in Experience',
    ];

    // ---------------------------------------------------------------------
    // Event evaluation
    // ---------------------------------------------------------------------

    /**
     * The participant's registration for the event if they may evaluate it.
     * Throws DomainException with the reason otherwise.
     */
    public static function eventContext(PDO $db, int $participantUserId, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT r.id AS registration_id, r.status AS registration_status, e.id AS event_id, e.name AS event_name,
                    e.status AS event_status, e.event_date, e.organizer_id, c.slug AS category_slug
             FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN events e ON e.id = r.event_id
             JOIN event_categories c ON c.id = e.category_id
             WHERE r.event_id = :eid AND p.user_id = :uid
             ORDER BY (r.status = "approved") DESC, r.id DESC LIMIT 1' // a re-registration after a cancel (v47)
        );
        $stmt->execute(['eid' => $eventId, 'uid' => $participantUserId]);
        $row = $stmt->fetch();

        if (!$row || $row['registration_status'] !== 'approved') {
            throw new DomainException('Only participants with an approved registration can evaluate this event.');
        }
        if (in_array($row['event_status'], ['cancelled', 'removed'], true)) {
            throw new DomainException('Cancelled events cannot be evaluated.');
        }
        if (!in_array($row['event_status'], self::COMPLETED_EVENT_STATUSES, true)) {
            throw new DomainException('You can evaluate this event once it is completed.');
        }

        return $row;
    }

    public static function feedbackFor(PDO $db, int $eventId, int $registrationId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM event_feedback WHERE event_id = :eid AND registration_id = :rid');
        $stmt->execute(['eid' => $eventId, 'rid' => $registrationId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Saves the participant's evaluation (ratings 1-5, speaker/session/venue
     * optional) and notifies the organizer. Returns the feedback id.
     */
    public static function submitEvent(PDO $db, int $participantUserId, int $eventId, array $ratings, string $comment): int
    {
        $ctx = self::eventContext($db, $participantUserId, $eventId);
        $overall = self::rating($ratings['overall_rating'] ?? null);
        if ($overall === null) {
            throw new DomainException('Please choose a rating from 1 to 5 stars.');
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > 1000) {
            throw new DomainException('The comment can be at most 1000 characters.');
        }
        if (self::feedbackFor($db, $eventId, (int) $ctx['registration_id'])) {
            throw new DomainException('You have already evaluated this event.');
        }

        try {
            $db->prepare(
                'INSERT INTO event_feedback (event_id, registration_id, overall_rating, speaker_rating, session_rating, venue_rating, suggestions)
                 VALUES (:eid, :rid, :overall, :speaker, :session, :venue, :comment)'
            )->execute([
                'eid' => $eventId, 'rid' => $ctx['registration_id'], 'overall' => $overall,
                'speaker' => self::rating($ratings['speaker_rating'] ?? null),
                'session' => self::rating($ratings['session_rating'] ?? null),
                'venue' => self::rating($ratings['venue_rating'] ?? null),
                'comment' => $comment !== '' ? $comment : null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('You have already evaluated this event.');
            }
            throw $e;
        }
        $feedbackId = (int) $db->lastInsertId();

        Logger::log($participantUserId, 'feedback_submitted', 'Evaluated event #' . $eventId . ' (' . $overall . '/5)');
        PayoutRequestService::notifyOrganizer($db, (int) $ctx['organizer_id'], 'New Event Feedback',
            'Your event "' . $ctx['event_name'] . '" received new feedback (' . $overall . '/5).',
            ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId], 'event_feedback_received');
        NotificationService::safely(fn () => NotificationService::toAdmins($db, 'New Event Feedback',
            '"' . $ctx['event_name'] . '" received new participant feedback (' . $overall . '/5).', 'platform_feedback',
            ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]));
        NotificationService::safely(fn () => notify($participantUserId, 'Feedback Submitted',
            'Thank you — your feedback for "' . $ctx['event_name'] . '" was recorded.', 'registration_status',
            ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]));

        return $feedbackId;
    }

    /** Most recent completed event the participant has not evaluated yet, or null. */
    public static function pendingEventEvaluation(PDO $db, int $participantUserId): ?array
    {
        $stmt = $db->prepare(
            'SELECT e.id AS event_id, e.name AS event_name, e.event_date
             FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN events e ON e.id = r.event_id
             LEFT JOIN event_feedback f ON f.event_id = e.id AND f.registration_id = r.id
             WHERE p.user_id = :uid AND r.status = "approved" AND e.status IN ("completed", "archived") AND f.id IS NULL
             ORDER BY e.event_date DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $participantUserId]);

        return $stmt->fetch() ?: null;
    }

    /** Tells every approved participant that the event ended (called when it is marked completed). */
    public static function notifyEventCompleted(PDO $db, int $eventId): int
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT p.user_id, e.name FROM registrations r
             JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
             WHERE r.event_id = :eid AND r.status = "approved"'
        );
        $stmt->execute(['eid' => $eventId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            notify((int) $row['user_id'], 'Share Your Experience', 'Your event "' . $row['name'] . '" has ended. Share your experience.', 'event_evaluation_request',
                ['category' => 'event', 'notice_type' => 'action_required', 'reference_type' => 'event', 'reference_id' => $eventId]);
        }

        return count($rows);
    }

    // ---------------------------------------------------------------------
    // Organizer view (own events only)
    // ---------------------------------------------------------------------

    /** Completed events and events with feedback, with average rating and count. */
    public static function organizerSummary(PDO $db, int $organizerId, int $limit = 5): array
    {
        $stmt = $db->prepare(
            'SELECT e.id, e.name, e.event_date, e.status, COUNT(f.id) AS reviews, ROUND(AVG(f.overall_rating), 1) AS average
             FROM events e
             LEFT JOIN event_feedback f ON f.event_id = e.id
             WHERE e.organizer_id = :oid AND (e.status IN ("completed", "archived") OR f.id IS NOT NULL)
             GROUP BY e.id, e.name, e.event_date, e.status
             ORDER BY e.event_date DESC LIMIT ' . max(1, min(50, $limit))
        );
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetchAll();
    }

    public static function eventFeedback(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT f.*, u.full_name AS participant_name FROM event_feedback f
             JOIN registrations r ON r.id = f.registration_id
             JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
             WHERE f.event_id = :eid ORDER BY f.id DESC'
        );
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------------
    // Platform (EVORA) evaluation
    // ---------------------------------------------------------------------

    public static function hasPlatformEvaluation(PDO $db, int $userId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM system_evaluations WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /** The user's own EVORA evaluation (for the ⭐ / ⭐✓ button), or null. */
    public static function platformEvaluation(PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM system_evaluations WHERE user_id = :uid ORDER BY id LIMIT 1');
        $stmt->execute(['uid' => $userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Whether the user has used EVORA enough to rate it. Returns null when
     * eligible, else the reason shown in the rating dialog.
     *   participant: registered for an event
     *   organizer:   approved organizer who created an event
     *   staff:       active account assigned to an event
     */
    public static function platformIneligibleReason(PDO $db, int $userId, string $role): ?string
    {
        if (!in_array($role, self::PLATFORM_ROLES, true)) {
            return 'Only participants, organizers and staff rate EVORA. Administrators view the analytics.';
        }
        $sql = match ($role) {
            'participant' => 'SELECT 1 FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE p.user_id = :uid LIMIT 1',
            'organizer' => 'SELECT 1 FROM organizers o JOIN events e ON e.organizer_id = o.id WHERE o.user_id = :uid AND o.status = "approved" LIMIT 1',
            'staff' => 'SELECT 1 FROM event_staff es JOIN users u ON u.id = es.user_id WHERE es.user_id = :uid AND u.status = "active" LIMIT 1',
        };
        $stmt = $db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return null;
        }

        return match ($role) {
            'participant' => 'You can rate EVORA after you register for an event.',
            'organizer' => 'You can rate EVORA once your organizer account is approved and you have created an event.',
            'staff' => 'You can rate EVORA after you are assigned to an event.',
        };
    }

    /** Whether the floating "How was your experience using EVORA?" card should show. */
    public static function platformPromptDue(PDO $db, int $userId, string $role): bool
    {
        return in_array($role, self::PLATFORM_ROLES, true)
            && !self::hasPlatformEvaluation($db, $userId)
            && self::platformIneligibleReason($db, $userId, $role) === null;
    }

    /**
     * One "Share your experience using EVORA." notification per user, created
     * the first time they reach a milestone and have not rated EVORA yet:
     *   participant: an approved (completed/paid) registration
     *   organizer:   a completed event
     *   staff:       assigned to a completed event (operations finished)
     * Returns true when a reminder was created now.
     */
    public static function ensurePlatformReminder(PDO $db, int $userId, string $role): bool
    {
        if (!in_array($role, self::PLATFORM_ROLES, true) || self::hasPlatformEvaluation($db, $userId)) {
            return false;
        }
        $sent = $db->prepare('SELECT 1 FROM notifications WHERE user_id = :uid AND type = "platform_feedback_request" LIMIT 1');
        $sent->execute(['uid' => $userId]);
        if ($sent->fetchColumn()) {
            return false;
        }
        $milestone = $db->prepare(match ($role) {
            'participant' => 'SELECT 1 FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE p.user_id = :uid AND r.status = "approved" LIMIT 1',
            'organizer' => 'SELECT 1 FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE o.user_id = :uid AND e.status IN ("completed", "archived") LIMIT 1',
            'staff' => 'SELECT 1 FROM event_staff es JOIN events e ON e.id = es.event_id WHERE es.user_id = :uid AND e.status IN ("completed", "archived") LIMIT 1',
        });
        $milestone->execute(['uid' => $userId]);
        if (!$milestone->fetchColumn()) {
            return false;
        }
        $message = match ($role) {
            'participant' => 'Share your experience using EVORA.',
            'organizer' => 'Tell us how EVORA helps you manage events.',
            'staff' => 'Rate your experience using EVORA tools.',
        };
        notify($userId, 'Rate EVORA', $message, 'platform_feedback_request', ['category' => 'system', 'notice_type' => 'action_required']);

        return true;
    }

    /** Quick platform rating (floating prompt). Kept for v32 callers. */
    public static function submitPlatformQuick(PDO $db, int $userId, string $role, $rating, string $comment): int
    {
        return self::submitPlatform($db, $userId, $role, $rating, $comment, [], 'quick_prompt');
    }

    /**
     * Saves the user's EVORA rating (1-5, optional comment, optional category
     * ratings for the user's role only). One per user; never editable.
     * Returns the evaluation id.
     */
    public static function submitPlatform(PDO $db, int $userId, string $role, $rating, string $comment, array $categoryRatings = [], string $source = 'star_button'): int
    {
        if (!in_array($role, self::PLATFORM_ROLES, true)) {
            throw new DomainException('Only EVORA users (participants, organizers, staff) can rate the platform here.');
        }
        if (!in_array($source, ['quick_prompt', 'star_button'], true)) {
            $source = 'star_button';
        }
        if ($reason = self::platformIneligibleReason($db, $userId, $role)) {
            throw new DomainException($reason);
        }
        $categories = [];
        foreach (array_keys(self::CATEGORY_QUESTIONS[$role]) as $column) {
            $categories[$column] = self::rating($categoryRatings[$column] ?? null); // other roles' questions are ignored
        }
        $rating = self::rating($rating);
        if ($rating === null) {
            throw new DomainException('Please choose a rating from 1 to 5 stars.');
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > 1000) {
            throw new DomainException('The comment can be at most 1000 characters.');
        }
        if (self::hasPlatformEvaluation($db, $userId)) {
            throw new DomainException('You have already evaluated EVORA. Thank you!');
        }
        try {
            $db->prepare(
                'INSERT INTO system_evaluations (user_id, role, overall_rating, source, registration_rating, payment_rating, event_management_rating, checkin_rating, comment)
                 VALUES (:uid, :role, :rating, :source, :registration, :payment, :management, :checkin, :comment)'
            )->execute([
                'uid' => $userId, 'role' => $role, 'rating' => $rating, 'source' => $source,
                'registration' => $categories['registration_rating'] ?? null, 'payment' => $categories['payment_rating'] ?? null,
                'management' => $categories['event_management_rating'] ?? null, 'checkin' => $categories['checkin_rating'] ?? null,
                'comment' => $comment !== '' ? $comment : null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '45000' || $e->getCode() === '23000') {
                throw new DomainException('You have already evaluated EVORA. Thank you!');
            }
            throw $e;
        }
        $evaluationId = (int) $db->lastInsertId();

        Logger::log($userId, 'evaluation_submitted', 'Rated EVORA ' . $rating . '/5 (' . str_replace('_', ' ', $source) . ')');
        PayoutRequestService::notifyAdmins($db, 'New System Feedback', 'New system feedback submitted by a' . ($role === 'organizer' ? 'n ' : ' ') . $role . ' (' . $rating . '/5).',
            ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'system_evaluation', 'reference_id' => $evaluationId], 'platform_feedback');

        return $evaluationId;
    }

    /** Admin analytics: overall rating, responses, satisfaction (rating >= 4), per role, recent comments. */
    public static function platformAnalytics(PDO $db, int $recent = 5): array
    {
        $totals = $db->query(
            'SELECT COUNT(*) AS responses, ROUND(AVG(' . self::PLATFORM_RATING_SQL . '), 2) AS average,
                    ROUND(100 * SUM(' . self::PLATFORM_RATING_SQL . ' >= 4) / NULLIF(COUNT(*), 0)) AS satisfaction
             FROM system_evaluations se'
        )->fetch();
        // Role at the time of rating (se.role); rows without one fall back to the account's role.
        $roleSql = 'COALESCE(se.role, r.name)';
        $byRole = array_fill_keys(self::PLATFORM_ROLES, ['responses' => 0, 'average' => null, 'satisfaction' => null]);
        foreach ($db->query(
            'SELECT ' . $roleSql . ' AS role, COUNT(*) AS responses, ROUND(AVG(' . self::PLATFORM_RATING_SQL . '), 2) AS average,
                    ROUND(100 * SUM(' . self::PLATFORM_RATING_SQL . ' >= 4) / COUNT(*)) AS satisfaction
             FROM system_evaluations se JOIN users u ON u.id = se.user_id JOIN roles r ON r.id = u.role_id
             GROUP BY ' . $roleSql
        )->fetchAll() as $row) {
            if (!isset($byRole[$row['role']])) {
                continue; // legacy row of a role that no longer rates (e.g. admin)
            }
            $byRole[$row['role']] = [
                'responses' => (int) $row['responses'],
                'average' => $row['average'] !== null ? (float) $row['average'] : null,
                'satisfaction' => $row['satisfaction'] !== null ? (int) $row['satisfaction'] : null,
            ];
        }
        $distribution = array_fill(1, 5, 0);
        foreach ($db->query('SELECT ROUND(' . self::PLATFORM_RATING_SQL . ') AS stars, COUNT(*) AS c FROM system_evaluations se GROUP BY stars')->fetchAll() as $row) {
            $distribution[max(1, min(5, (int) $row['stars']))] += (int) $row['c'];
        }
        $categoryAverages = [];
        $catRow = $db->query('SELECT ' . implode(', ', array_map(fn($c) => "ROUND(AVG($c), 2) AS $c, COUNT($c) AS {$c}_n", array_keys(self::CATEGORY_LABELS))) . ' FROM system_evaluations')->fetch();
        foreach (self::CATEGORY_LABELS as $column => $label) {
            $categoryAverages[$column] = ['label' => $label, 'average' => $catRow[$column] !== null ? (float) $catRow[$column] : null, 'responses' => (int) $catRow[$column . '_n']];
        }
        $comments = $db->query(
            'SELECT se.comment, se.created_at, se.source, ' . $roleSql . ' AS role, ROUND(' . self::PLATFORM_RATING_SQL . ', 1) AS rating
             FROM system_evaluations se JOIN users u ON u.id = se.user_id JOIN roles r ON r.id = u.role_id
             WHERE se.comment IS NOT NULL AND se.comment <> "" ORDER BY se.id DESC LIMIT ' . max(1, min(50, $recent))
        )->fetchAll();

        return [
            'responses' => (int) $totals['responses'],
            'average' => $totals['average'] !== null ? (float) $totals['average'] : 0.0,
            'satisfaction' => $totals['satisfaction'] !== null ? (int) $totals['satisfaction'] : 0,
            'by_role' => $byRole,
            'distribution' => $distribution,
            'categories' => $categoryAverages,
            'recent_comments' => $comments,
        ];
    }

    /**
     * Normalizes the admin System Feedback filters (anything invalid is dropped):
     * role (participant|organizer|staff), rating (1-5 stars), date_from/date_to (Y-m-d).
     */
    public static function feedbackFilters(array $input): array
    {
        $filters = [];
        if (in_array($input['role'] ?? '', self::PLATFORM_ROLES, true)) {
            $filters['role'] = $input['role'];
        }
        $rating = (int) ($input['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $filters['rating'] = $rating;
        }
        foreach (['date_from', 'date_to'] as $key) {
            $d = DateTime::createFromFormat('!Y-m-d', (string) ($input[$key] ?? ''));
            if ($d && $d->format('Y-m-d') === ($input[$key] ?? '')) {
                $filters[$key] = $input[$key];
            }
        }

        return $filters;
    }

    /**
     * Platform feedback rows for the admin (role, rating, comment, date), newest
     * first, with the filters of feedbackFilters(). Returns [rows, total].
     */
    public static function feedbackList(PDO $db, array $filters, int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if (isset($filters['role'])) {
            $where[] = 'COALESCE(se.role, r.name) = :role';
            $params['role'] = $filters['role'];
        }
        if (isset($filters['rating'])) {
            $where[] = 'ROUND(' . self::PLATFORM_RATING_SQL . ') = :rating';
            $params['rating'] = $filters['rating'];
        }
        if (isset($filters['date_from'])) {
            $where[] = 'se.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (isset($filters['date_to'])) {
            $where[] = 'se.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $from = ' FROM system_evaluations se JOIN users u ON u.id = se.user_id JOIN roles r ON r.id = u.role_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $db->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $stmt = $db->prepare(
            'SELECT se.*, u.full_name, COALESCE(se.role, r.name) AS rater_role, ROUND(' . self::PLATFORM_RATING_SQL . ', 1) AS rating'
            . $from . ' ORDER BY se.created_at DESC, se.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    // ---------------------------------------------------------------------
    // Prompts
    // ---------------------------------------------------------------------

    public static function isSnoozed(string $key): bool
    {
        return ($_SESSION['evaluation_snooze'][$key] ?? 0) > time();
    }

    public static function snooze(string $key): void
    {
        if (preg_match('/^(event:\d+|platform)$/', $key)) {
            $_SESSION['evaluation_snooze'][$key] = time() + self::SNOOZE_SECONDS;
        }
    }

    /** Stars for display: "★★★★☆". */
    public static function stars($rating): string
    {
        $full = (int) round((float) $rating);

        return str_repeat('★', max(0, min(5, $full))) . str_repeat('☆', 5 - max(0, min(5, $full)));
    }

    private static function rating($value): ?int
    {
        $v = (int) $value;

        return ($v >= 1 && $v <= 5) ? $v : null;
    }
}
