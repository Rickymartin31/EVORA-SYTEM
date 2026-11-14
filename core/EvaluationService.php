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

    /** v80 post-event survey: satisfaction aspects (column => question), common + per event category slug. */
    public const REVIEW_ASPECTS = [
        'common' => [
            'organization_rating' => 'Organization and schedule',
            'checkin_rating' => 'Registration and check-in',
            'venue_rating' => 'Venue and facilities',
        ],
        'fun-run' => ['route_rating' => 'Route, marshals and safety', 'kit_rating' => 'Race kit and items'],
        'sports-tournament' => ['officiating_rating' => 'Officiating and match schedule'],
        'seminar-workshop' => ['speaker_rating' => 'Speaker effectiveness', 'session_rating' => 'Session content and relevance'],
    ];

    /** v80 "areas for improvement" quick tags: key => [label, category slug or null for every event]. */
    public const IMPROVEMENT_TAGS = [
        'late_start' => ['Started late', null],
        'long_lines' => ['Long lines at check-in', null],
        'unclear_info' => ['Unclear announcements', null],
        'venue' => ['Venue or facilities', null],
        'sound' => ['Sound or visuals', null],
        'water_food' => ['Not enough water or food', null],
        'safety' => ['Safety or first aid', null],
        'route' => ['Route or marshals', 'fun-run'],
        'kit' => ['Race kit or items', 'fun-run'],
        'officiating' => ['Officiating or match schedule', 'sports-tournament'],
        'speaker' => ['Speaker or content', 'seminar-workshop'],
        'value' => ['Not worth the fee', null],
    ];

    public const EXPECTATION_LABELS = ['yes' => 'Yes, fully', 'partly' => 'Partly', 'no' => 'No'];
    public const JOIN_AGAIN_LABELS = ['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'];

    /** SQL: the registration (alias r) checked in at the event or at one of its sessions. */
    public const ATTENDED_SQL = '(EXISTS (SELECT 1 FROM attendance att WHERE att.registration_id = r.id)
        OR EXISTS (SELECT 1 FROM session_attendance sat WHERE sat.registration_id = r.id))';

    /** Aspect questions for an event category. */
    public static function aspectsFor(?string $categorySlug): array
    {
        return self::REVIEW_ASPECTS['common'] + (self::REVIEW_ASPECTS[$categorySlug] ?? []);
    }

    /** Improvement tags for an event category (and "Not worth the fee" only for paid events). */
    public static function tagsFor(?string $categorySlug, bool $paid): array
    {
        $tags = [];
        foreach (self::IMPROVEMENT_TAGS as $key => [$label, $only]) {
            if (($only === null || $only === $categorySlug) && ($key !== 'value' || $paid)) {
                $tags[$key] = $label;
            }
        }

        return $tags;
    }

    /** "Maria Santos" -> "Maria S." for public reviews. */
    public static function publicName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) < 2) {
            return $parts[0] ?? 'Participant';
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

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
                    e.status AS event_status, e.event_date, e.organizer_id, c.slug AS category_slug, ' . self::ATTENDED_SQL . ' AS attended,
                    (e.fee > 0 OR EXISTS (SELECT 1 FROM race_categories rc WHERE rc.event_id = e.id AND rc.fee > 0)) AS paid
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
        // v80: only people who actually came may review (an older review stays viewable).
        if (!$row['attended'] && !self::feedbackFor($db, $eventId, (int) $row['registration_id'])) {
            throw new DomainException('Only participants who attended (checked in) can review this event.');
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
    public const REVIEW_PHOTO_MAX = 3;

    public static function submitEvent(PDO $db, int $participantUserId, int $eventId, array $ratings, string $comment, ?array $photoFiles = null): int
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
        // v80 survey: effectiveness and "join again" are required; aspects, what went well and tags are optional.
        $expectations = (string) ($ratings['expectations_met'] ?? '');
        $joinAgain = (string) ($ratings['join_again'] ?? '');
        if (!isset(self::EXPECTATION_LABELS[$expectations])) {
            throw new DomainException('Please answer whether the event met your expectations.');
        }
        if (!isset(self::JOIN_AGAIN_LABELS[$joinAgain])) {
            throw new DomainException('Please answer whether you would join this organizer\'s events again.');
        }
        $liked = trim((string) ($ratings['liked'] ?? ''));
        if (mb_strlen($liked) > 500) {
            throw new DomainException('"What went well" can be at most 500 characters.');
        }
        $allowedTags = self::tagsFor($ctx['category_slug'], (bool) $ctx['paid']);
        $tags = array_values(array_intersect(array_keys($allowedTags), (array) ($ratings['improvement_tags'] ?? [])));
        // v81: up to 3 photos (JPG/PNG, 5 MB each), stored before the review row so a bad file stops everything.
        $photos = [];
        foreach (self::uploadedList($photoFiles) as $file) {
            if (count($photos) >= self::REVIEW_PHOTO_MAX) {
                self::deletePhotos($photos);
                throw new DomainException('You can add at most ' . self::REVIEW_PHOTO_MAX . ' photos.');
            }
            $path = Upload::handle($file, 'reviews', UPLOAD_ALLOWED_IMAGE);
            if (!$path) {
                self::deletePhotos($photos);
                throw new DomainException('Each photo must be a JPG or PNG image up to 5 MB.');
            }
            $photos[] = $path;
        }
        $aspects = [];
        foreach (array_keys(self::REVIEW_ASPECTS['common'] + self::REVIEW_ASPECTS['fun-run'] + self::REVIEW_ASPECTS['sports-tournament'] + self::REVIEW_ASPECTS['seminar-workshop']) as $column) {
            $aspects[$column] = isset(self::aspectsFor($ctx['category_slug'])[$column]) ? self::rating($ratings[$column] ?? null) : null;
        }

        try {
            $db->prepare(
                'INSERT INTO event_feedback (event_id, registration_id, overall_rating, organization_rating, checkin_rating, speaker_rating, session_rating, venue_rating,
                    route_rating, kit_rating, officiating_rating, expectations_met, join_again, liked, improvement_tags, suggestions)
                 VALUES (:eid, :rid, :overall, :organization, :checkin, :speaker, :session, :venue, :route, :kit, :officiating, :expectations, :again, :liked, :tags, :comment)'
            )->execute([
                'eid' => $eventId, 'rid' => $ctx['registration_id'], 'overall' => $overall,
                'organization' => $aspects['organization_rating'], 'checkin' => $aspects['checkin_rating'],
                'speaker' => $aspects['speaker_rating'], 'session' => $aspects['session_rating'], 'venue' => $aspects['venue_rating'],
                'route' => $aspects['route_rating'], 'kit' => $aspects['kit_rating'], 'officiating' => $aspects['officiating_rating'],
                'expectations' => $expectations, 'again' => $joinAgain, 'liked' => $liked !== '' ? $liked : null,
                'tags' => $tags ? implode(',', $tags) : null,
                'comment' => $comment !== '' ? $comment : null,
            ]);
        } catch (PDOException $e) {
            self::deletePhotos($photos);
            if ($e->getCode() === '23000') {
                throw new DomainException('You have already evaluated this event.');
            }
            throw $e;
        }
        $feedbackId = (int) $db->lastInsertId();
        foreach ($photos as $path) {
            $db->prepare('INSERT INTO event_feedback_photos (feedback_id, photo_path) VALUES (:f, :p)')->execute(['f' => $feedbackId, 'p' => $path]);
        }

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
               AND ' . self::ATTENDED_SQL . '
             ORDER BY e.event_date DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $participantUserId]);

        return $stmt->fetch() ?: null;
    }

    /** Asks every participant who attended to review the event (called when it is marked completed). */
    public static function notifyEventCompleted(PDO $db, int $eventId): int
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT p.user_id, e.name FROM registrations r
             JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
             WHERE r.event_id = :eid AND r.status = "approved" AND ' . self::ATTENDED_SQL
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
    // v80 Post-event evaluation results and public reviews
    // ---------------------------------------------------------------------

    /**
     * Summary of reviews for one event (event_id) or all events of one organizer (organizer_id):
     * average, count, star distribution, satisfaction/effectiveness/return percentages,
     * aspect averages and the most common areas for improvement.
     */
    public static function reviewStats(PDO $db, string $scope, int $id): array
    {
        $where = $scope === 'organizer' ? 'e.organizer_id = :id' : 'f.event_id = :id';
        $stmt = $db->prepare('SELECT f.* FROM event_feedback f JOIN events e ON e.id = f.event_id WHERE ' . $where);
        $stmt->execute(['id' => $id]);
        $rows = $stmt->fetchAll();
        $count = count($rows);
        $stats = ['count' => $count, 'average' => 0.0, 'distribution' => array_fill(1, 5, 0), 'satisfied_pct' => null,
            'expectations' => [], 'join_again' => [], 'aspects' => [], 'tags' => []];
        if (!$count) {
            return $stats;
        }
        $aspectLabels = array_merge(...array_values(self::REVIEW_ASPECTS));
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int) $r['overall_rating'];
            $stats['distribution'][(int) $r['overall_rating']]++;
            foreach (['expectations_met' => 'expectations', 'join_again' => 'join_again'] as $col => $key) {
                if ($r[$col] !== null) {
                    $stats[$key][$r[$col]] = ($stats[$key][$r[$col]] ?? 0) + 1;
                }
            }
            foreach ($aspectLabels as $col => $label) {
                if ($r[$col] !== null) {
                    $stats['aspects'][$col][] = (int) $r[$col];
                }
            }
            foreach (array_filter(explode(',', (string) $r['improvement_tags'])) as $tag) {
                $stats['tags'][$tag] = ($stats['tags'][$tag] ?? 0) + 1;
            }
        }
        $stats['average'] = round($sum / $count, 1);
        $stats['satisfied_pct'] = (int) round(100 * ($stats['distribution'][4] + $stats['distribution'][5]) / $count);
        foreach ($stats['aspects'] as $col => $values) {
            $stats['aspects'][$col] = ['label' => $aspectLabels[$col], 'average' => round(array_sum($values) / count($values), 1), 'count' => count($values)];
        }
        arsort($stats['tags']);

        return $stats;
    }

    /** Share (0-100) of answered surveys with that answer, or null when nobody answered. */
    public static function pct(array $answers, string $key): ?int
    {
        $total = array_sum($answers);

        return $total ? (int) round(100 * ($answers[$key] ?? 0) / $total) : null;
    }

    /** Reviews of one event (or all events of an organizer), newest first, with public name and "attended". */
    public static function publicReviews(PDO $db, string $scope, int $id, int $limit = 20): array
    {
        $where = $scope === 'organizer' ? 'e.organizer_id = :id' : 'f.event_id = :id';
        $stmt = $db->prepare(
            'SELECT f.*, e.name AS event_name, c.slug AS category_slug, u.full_name, ' . self::ATTENDED_SQL . ' AS attended
             FROM event_feedback f JOIN events e ON e.id = f.event_id JOIN event_categories c ON c.id = e.category_id
             JOIN registrations r ON r.id = f.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
             WHERE ' . $where . ' ORDER BY f.created_at DESC, f.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['id' => $id]);
        $rows = $stmt->fetchAll();
        $photos = self::reviewPhotos($db, array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['public_name'] = self::publicName($r['full_name']);
            $r['photos'] = $photos[(int) $r['id']] ?? [];
        }

        return $rows;
    }

    /** Photos of the given reviews: feedback_id => [photo paths]. */
    public static function reviewPhotos(PDO $db, array $feedbackIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $feedbackIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($db->query('SELECT feedback_id, photo_path FROM event_feedback_photos WHERE feedback_id IN (' . implode(',', $ids) . ') ORDER BY id') as $row) {
            $out[(int) $row['feedback_id']][] = $row['photo_path'];
        }

        return $out;
    }

    /** $_FILES['photos'] (multiple) as a list of single-file arrays, skipping empty slots. */
    private static function uploadedList(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $list = [];
        foreach (array_keys($files['name']) as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        }

        return $list;
    }

    private static function deletePhotos(array $paths): void
    {
        foreach ($paths as $p) {
            @unlink(STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p));
        }
    }

    /** Organizer reputation shown beside the organizer name: [average, count] (count 0 = no reviews yet). */
    public static function organizerRating(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare('SELECT ROUND(AVG(f.overall_rating), 1), COUNT(*) FROM event_feedback f JOIN events e ON e.id = f.event_id WHERE e.organizer_id = :o');
        $stmt->execute(['o' => $organizerId]);
        [$avg, $count] = $stmt->fetch(PDO::FETCH_NUM);

        return [(float) $avg, (int) $count];
    }

    /** SQL columns (organizer alias o) with the organizer's average rating and review count, for event cards. */
    public const ORGANIZER_RATING_COLUMNS = '(SELECT ROUND(AVG(orf.overall_rating), 1) FROM event_feedback orf JOIN events ore ON ore.id = orf.event_id WHERE ore.organizer_id = o.id) AS organizer_rating,
        (SELECT COUNT(*) FROM event_feedback orf JOIN events ore ON ore.id = orf.event_id WHERE ore.organizer_id = o.id) AS organizer_reviews';

    /** "★ 4.7 (32)" next to an organizer name; empty when there are no reviews yet. */
    public static function ratingBadge($average, $count, ?string $link = null): string
    {
        if ((int) $count < 1) {
            return '';
        }
        $text = '<i class="bi bi-star-fill" aria-hidden="true"></i> ' . number_format((float) $average, 1) . ' <span class="text-muted">(' . (int) $count . ')</span>';
        $title = number_format((float) $average, 1) . ' out of 5 from ' . (int) $count . ' review' . ((int) $count === 1 ? '' : 's');

        return ' <' . ($link ? 'a href="' . e($link) . '"' : 'span') . ' class="organizer-rating" title="' . e($title) . '">' . $text . '</' . ($link ? 'a' : 'span') . '>';
    }

    /** The organizer's public reply to a review of their own event (set, edit or clear). */
    public static function reply(PDO $db, int $feedbackId, int $organizerUserId, string $reply): void
    {
        $reply = trim($reply);
        if (mb_strlen($reply) > 1000) {
            throw new DomainException('The reply can be at most 1000 characters.');
        }
        $stmt = $db->prepare('SELECT f.id, f.event_id, e.name, p.user_id AS participant_user_id FROM event_feedback f
                              JOIN events e ON e.id = f.event_id JOIN organizers o ON o.id = e.organizer_id
                              JOIN registrations r ON r.id = f.registration_id JOIN participants p ON p.id = r.participant_id
                              WHERE f.id = :id AND o.user_id = :uid');
        $stmt->execute(['id' => $feedbackId, 'uid' => $organizerUserId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('You can only reply to reviews of your own events.');
        }
        $db->prepare('UPDATE event_feedback SET organizer_reply = :r, organizer_replied_at = :at WHERE id = :id')
            ->execute(['r' => $reply !== '' ? $reply : null, 'at' => $reply !== '' ? date('Y-m-d H:i:s') : null, 'id' => $feedbackId]);
        Logger::log($organizerUserId, 'review_reply', ($reply !== '' ? 'Replied to' : 'Removed the reply to') . ' review #' . $feedbackId . ' of event #' . $row['event_id']);
        if ($reply !== '') {
            NotificationService::safely(fn () => notify((int) $row['participant_user_id'], 'Organizer Replied to Your Review',
                'The organizer of "' . $row['name'] . '" replied to your review.', 'review_reply',
                ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => (int) $row['event_id']]));
        }
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
