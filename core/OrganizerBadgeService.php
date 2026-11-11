<?php

/**
 * Verified Organizer Badge (v78).
 *
 * Two levels of trust: an APPROVED organizer may create events; the separate Verified badge
 * needs the full requirements below, automatic file checks and an administrator's
 * document-by-document validation. The badge lasts VALID_MONTHS (or until a submitted
 * document expires, whichever is earlier), can be renewed in the last REMIND_DAYS days,
 * and an administrator can revoke it. A suspended organizer never shows the badge.
 */
class OrganizerBadgeService
{
    public const VALID_MONTHS = 12;
    public const REMIND_DAYS = 30;
    public const UPLOAD_FOLDER = 'verification';

    public const ORG_TYPES = [
        'business' => ['Registered business', 'DTI or SEC registration, or a Mayor\'s / Business Permit'],
        'association' => ['Sports club or association', 'SEC or CDA registration, or a Barangay Certification'],
        'school_org' => ['School or student organization', 'School accreditation or recognition letter'],
        'lgu_ngo' => ['LGU, barangay office or NGO', 'Office certification, endorsement letter or SEC registration'],
        'other' => ['Other group', 'Barangay Certification or an endorsement letter'],
    ];

    public const ID_KINDS = ['PhilSys National ID', 'Driver\'s License', 'Passport', 'UMID', 'PRC ID', 'Postal ID', 'Voter\'s ID', 'SSS ID', 'Other government ID'];

    public const ORG_PROOF_KINDS = ['DTI Business Name Registration', 'SEC Registration', 'CDA Registration', 'Mayor\'s / Business Permit',
        'Barangay Certification', 'School Accreditation / Recognition', 'LGU or Office Endorsement Letter', 'Other'];

    /** Requirement list: label, required, help text, allowed file extensions. */
    public const DOCS = [
        'government_id' => ['Government-issued ID', true, 'A valid ID of the organizer or authorized representative (front side, all corners visible).', ['jpg', 'jpeg', 'png', 'pdf']],
        'selfie_with_id' => ['Selfie holding the same ID', true, 'A photo of you holding the ID beside your face; both must be clear. Proves the ID is yours.', ['jpg', 'jpeg', 'png']],
        'organization_proof' => ['Proof of organization', true, 'Shows that the organization is real and recognized (see the list for your organization type).', ['jpg', 'jpeg', 'png', 'pdf']],
        'past_event_proof' => ['Proof of past events', false, 'Optional: a poster, photo or post of an event your organization ran before.', ['jpg', 'jpeg', 'png', 'pdf']],
    ];

    /** The administrator's checklist for every document. */
    public const CHECKS = [
        'check_readable' => 'Clear and readable (not blurred or cropped)',
        'check_name_match' => 'Name matches the organizer account or organization',
        'check_valid_date' => 'Valid date (not expired)',
        'check_authentic' => 'Looks genuine, no signs of editing; issued by a valid office',
    ];

    public const FLAG_LABELS = [
        'duplicate_file' => 'Same file was submitted by another organizer',
        'expires_soon' => 'Expires within ' . self::REMIND_DAYS . ' days',
    ];

    /** SQL condition (organizer alias) for "shows the Verified badge today". */
    public static function sqlVerified(string $alias = 'o'): string
    {
        return "($alias.status = 'approved' AND $alias.badge_verified_at IS NOT NULL AND $alias.badge_revoked_at IS NULL AND $alias.badge_expires_on >= CURDATE())";
    }

    public static function isVerified(array $organizer): bool
    {
        return ($organizer['status'] ?? '') === 'approved' && !empty($organizer['badge_verified_at']) && empty($organizer['badge_revoked_at'])
            && !empty($organizer['badge_expires_on']) && $organizer['badge_expires_on'] >= date('Y-m-d');
    }

    /** verified | expiring | expired | revoked | none — the badge itself, apart from any application. */
    public static function badgeState(array $organizer): string
    {
        if (empty($organizer['badge_verified_at'])) {
            return 'none';
        }
        if (!empty($organizer['badge_revoked_at'])) {
            return 'revoked';
        }
        if ($organizer['badge_expires_on'] < date('Y-m-d')) {
            return 'expired';
        }

        return $organizer['badge_expires_on'] <= date('Y-m-d', strtotime('+' . self::REMIND_DAYS . ' days')) ? 'expiring' : 'verified';
    }

    public static function latestApplication(PDO $db, int $organizerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_badge_applications WHERE organizer_id = :o ORDER BY id DESC LIMIT 1');
        $stmt->execute(['o' => $organizerId]);

        return $stmt->fetch() ?: null;
    }

    /** Current (not replaced) documents of an application, in requirement order. */
    public static function documents(PDO $db, int $applicationId): array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_badge_documents WHERE application_id = :a AND status <> "replaced"
                              ORDER BY FIELD(doc_type, "government_id", "selfie_with_id", "organization_proof", "past_event_proof"), id');
        $stmt->execute(['a' => $applicationId]);

        return $stmt->fetchAll();
    }

    /** May the organizer start a new application now? */
    public static function canApply(array $organizer, ?array $application): bool
    {
        if (($organizer['status'] ?? '') !== 'approved') {
            return false;
        }
        if ($application && in_array($application['status'], ['pending', 'needs_resubmission'], true)) {
            return false;
        }

        return in_array(self::badgeState($organizer), ['none', 'expiring', 'expired', 'revoked'], true);
    }

    /**
     * New application with all documents. Returns a list of error messages (empty on success).
     */
    public static function submit(PDO $db, array $organizer, int $userId, array $post, array $files): array
    {
        if (!self::canApply($organizer, self::latestApplication($db, (int) $organizer['id']))) {
            return ['You cannot submit a new application right now.'];
        }
        $errors = [];
        $orgType = (string) ($post['organization_type'] ?? '');
        if (!isset(self::ORG_TYPES[$orgType])) {
            $errors[] = 'Choose your organization type.';
        }
        $social = trim((string) ($post['social_link'] ?? ''));
        if ($social !== '' && (!filter_var($social, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $social) || strlen($social) > 255)) {
            $errors[] = 'The page or website link must be a full address starting with https://';
        }
        $pastNote = trim((string) ($post['past_events_note'] ?? ''));
        if (mb_strlen($pastNote) > 1000) {
            $errors[] = 'Keep the past events description under 1,000 characters.';
        }

        $docs = [];
        foreach (self::DOCS as $type => [$label, $required]) {
            $meta = self::documentMeta($type, $post, $errors);
            $hasFile = !empty($files[$type]['name']);
            if ($required && !$hasFile) {
                $errors[] = $label . ' is required.';
            }
            if ($hasFile) {
                $docs[$type] = $meta;
            }
        }
        if ($errors) {
            return $errors;
        }

        $stored = [];
        foreach ($docs as $type => $meta) {
            $path = Upload::handle($files[$type], self::UPLOAD_FOLDER, self::DOCS[$type][3]);
            if (!$path) {
                self::deleteFiles($stored);

                return [self::DOCS[$type][0] . ': upload a ' . self::allowedText($type) . ' file up to 5 MB.'];
            }
            $stored[$type] = $path;
        }

        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO organizer_badge_applications (organizer_id, organization_type, social_link, past_events_note) VALUES (:o, :t, :s, :p)')
                ->execute(['o' => $organizer['id'], 't' => $orgType, 's' => $social ?: null, 'p' => $pastNote ?: null]);
            $applicationId = (int) $db->lastInsertId();
            foreach ($stored as $type => $path) {
                self::insertDocument($db, $applicationId, (int) $organizer['id'], $type, $path, $docs[$type]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::deleteFiles($stored);
            throw $e;
        }

        Logger::log($userId, 'badge_application_submitted', 'Verified badge application #' . $applicationId . ' (' . $organizer['organization_name'] . ')');
        NotificationService::safely(fn () => NotificationService::toAdmins($db, 'Verified Badge Application',
            $organizer['organization_name'] . ' applied for the Verified Organizer badge. Validate the documents.', 'badge_application',
            ['category' => 'system', 'notice_type' => 'action_required', 'reference_type' => 'organizer_badge', 'reference_id' => $applicationId]));

        return [];
    }

    /**
     * Replaces the documents the administrator rejected (application status needs_resubmission).
     */
    public static function resubmit(PDO $db, array $organizer, int $userId, array $post, array $files): array
    {
        $application = self::latestApplication($db, (int) $organizer['id']);
        if (!$application || $application['status'] !== 'needs_resubmission') {
            return ['There is nothing to resubmit.'];
        }
        $rejected = array_filter(self::documents($db, (int) $application['id']), fn ($d) => $d['status'] === 'rejected');
        $errors = [];
        $metas = [];
        foreach ($rejected as $doc) {
            $type = $doc['doc_type'];
            $metas[$doc['id']] = self::documentMeta($type, $post, $errors);
            if (empty($files[$type]['name'])) {
                $errors[] = 'Upload a new file for: ' . self::DOCS[$type][0] . '.';
            }
        }
        if ($errors) {
            return $errors;
        }

        $stored = [];
        foreach ($rejected as $doc) {
            $path = Upload::handle($files[$doc['doc_type']], self::UPLOAD_FOLDER, self::DOCS[$doc['doc_type']][3]);
            if (!$path) {
                self::deleteFiles($stored);

                return [self::DOCS[$doc['doc_type']][0] . ': upload a ' . self::allowedText($doc['doc_type']) . ' file up to 5 MB.'];
            }
            $stored[$doc['id']] = $path;
        }

        try {
            $db->beginTransaction();
            foreach ($rejected as $doc) {
                $db->prepare('UPDATE organizer_badge_documents SET status = "replaced" WHERE id = :id')->execute(['id' => $doc['id']]);
                self::insertDocument($db, (int) $application['id'], (int) $organizer['id'], $doc['doc_type'], $stored[$doc['id']], $metas[$doc['id']]);
            }
            $db->prepare('UPDATE organizer_badge_applications SET status = "pending" WHERE id = :id')->execute(['id' => $application['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::deleteFiles($stored);
            throw $e;
        }

        Logger::log($userId, 'badge_documents_resubmitted', 'Verified badge application #' . $application['id'] . ': ' . count($rejected) . ' document(s) resubmitted');
        NotificationService::safely(fn () => NotificationService::toAdmins($db, 'Verified Badge Documents Resubmitted',
            $organizer['organization_name'] . ' uploaded new documents for the Verified badge.', 'badge_application',
            ['category' => 'system', 'notice_type' => 'action_required', 'reference_type' => 'organizer_badge', 'reference_id' => (int) $application['id']]));

        return [];
    }

    /** Administrator validates one document: checklist + accept/reject. Returns an error or null. */
    public static function reviewDocument(PDO $db, int $documentId, int $adminId, array $checks, string $decision, string $reason): ?string
    {
        $stmt = $db->prepare('SELECT d.*, a.status AS application_status FROM organizer_badge_documents d
                              JOIN organizer_badge_applications a ON a.id = d.application_id WHERE d.id = :id');
        $stmt->execute(['id' => $documentId]);
        $doc = $stmt->fetch();
        if (!$doc || $doc['status'] === 'replaced') {
            return 'Document not found.';
        }
        if ($doc['application_status'] !== 'pending') {
            return 'This application is not waiting for review.';
        }
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            return 'Choose Accept or Reject.';
        }
        $values = [];
        foreach (array_keys(self::CHECKS) as $key) {
            $values[$key] = !empty($checks[$key]) ? 1 : 0;
        }
        $reason = mb_substr(trim($reason), 0, 255);
        if ($decision === 'accepted' && in_array(0, $values, true)) {
            return 'Tick every checklist item before accepting the document, or reject it with a reason.';
        }
        if ($decision === 'rejected' && $reason === '') {
            return 'Write the reason for rejecting the document, so the organizer knows what to fix.';
        }
        $db->prepare('UPDATE organizer_badge_documents SET status = :s, check_readable = :c1, check_name_match = :c2, check_valid_date = :c3, check_authentic = :c4,
                      rejection_reason = :r, reviewed_by = :by, reviewed_at = NOW() WHERE id = :id')
            ->execute(['s' => $decision, 'c1' => $values['check_readable'], 'c2' => $values['check_name_match'], 'c3' => $values['check_valid_date'],
                'c4' => $values['check_authentic'], 'r' => $decision === 'rejected' ? $reason : null, 'by' => $adminId, 'id' => $documentId]);
        Logger::log($adminId, 'badge_document_' . $decision, 'Verified badge application #' . $doc['application_id'] . ': ' . self::DOCS[$doc['doc_type']][0] . ' ' . $decision);

        return null;
    }

    /**
     * Final decision on an application: approve | request_resubmission | reject.
     * Returns an error message or null.
     */
    public static function decide(PDO $db, int $applicationId, int $adminId, string $action, string $remarks): ?string
    {
        $stmt = $db->prepare('SELECT a.*, o.user_id, o.organization_name FROM organizer_badge_applications a
                              JOIN organizers o ON o.id = a.organizer_id WHERE a.id = :id');
        $stmt->execute(['id' => $applicationId]);
        $app = $stmt->fetch();
        if (!$app || $app['status'] !== 'pending') {
            return 'This application is not waiting for a decision.';
        }
        $remarks = mb_substr(trim($remarks), 0, 500);
        $docs = self::documents($db, $applicationId);
        $byType = [];
        foreach ($docs as $d) {
            $byType[$d['doc_type']] = $d;
        }

        if ($action === 'approve') {
            foreach ($docs as $d) {
                if ($d['status'] === 'pending') {
                    return 'Validate every document first (' . self::DOCS[$d['doc_type']][0] . ' is not reviewed yet).';
                }
            }
            foreach (self::DOCS as $type => [$label, $required]) {
                if ($required && (($byType[$type]['status'] ?? '') !== 'accepted')) {
                    return $label . ' must be accepted before the badge can be approved.';
                }
            }
            $expires = date('Y-m-d', strtotime('+' . self::VALID_MONTHS . ' months'));
            foreach ($docs as $d) {
                if ($d['status'] === 'accepted' && !empty($d['expires_on']) && $d['expires_on'] < $expires) {
                    $expires = $d['expires_on']; // the badge never outlives a submitted document
                }
            }
            $db->beginTransaction();
            $db->prepare('UPDATE organizer_badge_applications SET status = "approved", reviewed_by = :by, reviewed_at = NOW(), remarks = :r WHERE id = :id')
                ->execute(['by' => $adminId, 'r' => $remarks ?: null, 'id' => $applicationId]);
            $db->prepare('UPDATE organizers SET badge_verified_at = NOW(), badge_expires_on = :e, badge_application_id = :a, badge_revoked_at = NULL, badge_revoke_reason = NULL WHERE id = :id')
                ->execute(['e' => $expires, 'a' => $applicationId, 'id' => $app['organizer_id']]);
            $db->commit();
            $message = 'Congratulations! ' . $app['organization_name'] . ' is now a Verified Organizer. The badge shows on your events until ' . date('M d, Y', strtotime($expires)) . '.';
        } elseif ($action === 'request_resubmission') {
            $rejected = array_filter($docs, fn ($d) => $d['status'] === 'rejected');
            if (!$rejected) {
                return 'Reject at least one document (with a reason) before asking the organizer to resubmit.';
            }
            $db->prepare('UPDATE organizer_badge_applications SET status = "needs_resubmission", reviewed_by = :by, reviewed_at = NOW(), remarks = :r WHERE id = :id')
                ->execute(['by' => $adminId, 'r' => $remarks ?: null, 'id' => $applicationId]);
            $message = 'Please upload new files for: ' . implode('; ', array_map(fn ($d) => self::DOCS[$d['doc_type']][0] . ' (' . $d['rejection_reason'] . ')', $rejected)) . '.';
        } elseif ($action === 'reject') {
            if ($remarks === '') {
                return 'Write the reason for rejecting the application.';
            }
            $db->prepare('UPDATE organizer_badge_applications SET status = "rejected", reviewed_by = :by, reviewed_at = NOW(), remarks = :r WHERE id = :id')
                ->execute(['by' => $adminId, 'r' => $remarks, 'id' => $applicationId]);
            $message = 'Your Verified badge application was not approved. Reason: ' . $remarks;
        } else {
            return 'Invalid action.';
        }

        Logger::log($adminId, 'badge_application_' . $action, 'Verified badge application #' . $applicationId . ' (' . $app['organization_name'] . '): ' . $action);
        NotificationService::safely(fn () => notify((int) $app['user_id'], 'Verified Badge Update', $message, 'organizer_badge',
            ['category' => 'system', 'notice_type' => $action === 'request_resubmission' ? 'action_required' : 'status', 'reference_type' => 'organizer_badge', 'reference_id' => $applicationId]));
        NotificationService::toOtherAdmins($db, $adminId, 'Verified Badge Decision', $app['organization_name'] . ': ' . str_replace('_', ' ', $action) . ' by ' . ($_SESSION['full_name'] ?? 'an administrator') . '.',
            'badge_application', ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'organizer_badge', 'reference_id' => $applicationId]);

        return null;
    }

    public static function revoke(PDO $db, int $organizerId, int $adminId, string $reason): ?string
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            return 'Write the reason for revoking the badge.';
        }
        $stmt = $db->prepare('SELECT * FROM organizers WHERE id = :id');
        $stmt->execute(['id' => $organizerId]);
        $organizer = $stmt->fetch();
        if (!$organizer || !in_array(self::badgeState($organizer), ['verified', 'expiring'], true)) {
            return 'This organizer has no active Verified badge.';
        }
        $db->prepare('UPDATE organizers SET badge_revoked_at = NOW(), badge_revoke_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $organizerId]);
        Logger::log($adminId, 'badge_revoked', 'Verified badge of organizer #' . $organizerId . ' (' . $organizer['organization_name'] . ') revoked: ' . $reason);
        NotificationService::safely(fn () => notify((int) $organizer['user_id'], 'Verified Badge Revoked', 'Your Verified Organizer badge was removed. Reason: ' . $reason, 'organizer_badge',
            ['category' => 'system', 'notice_type' => 'status', 'reference_type' => 'organizer_badge', 'reference_id' => (int) $organizer['badge_application_id']]));

        return null;
    }

    /** Facts EVORA already knows about the organizer, shown to the administrator beside the documents. */
    public static function trustSummary(PDO $db, array $organizer): array
    {
        $oid = (int) $organizer['id'];
        $one = function (string $sql) use ($db, $oid) {
            $stmt = $db->prepare($sql);
            $stmt->execute(['o' => $oid]);

            return $stmt->fetchColumn();
        };
        $user = $db->prepare('SELECT created_at, email_verified_at FROM users WHERE id = :u');
        $user->execute(['u' => $organizer['user_id']]);
        $user = $user->fetch() ?: [];

        return [
            'account_since' => $user['created_at'] ?? $organizer['created_at'],
            'email_verified' => !empty($user['email_verified_at']),
            'organizer_approved_at' => $organizer['status'] === 'approved' ? $organizer['reviewed_at'] : null,
            'events_completed' => (int) $one('SELECT COUNT(*) FROM events WHERE organizer_id = :o AND status = "completed"'),
            'participants_served' => (int) $one('SELECT COUNT(*) FROM registrations r JOIN events e ON e.id = r.event_id WHERE e.organizer_id = :o AND r.status = "approved"'),
            'average_rating' => ($avg = $one('SELECT AVG(f.overall_rating) FROM event_feedback f JOIN events e ON e.id = f.event_id WHERE e.organizer_id = :o')) !== null ? round((float) $avg, 2) : null,
            'refunds_outstanding' => RefundTrackingService::outstandingCount($db, $oid),
            'cancelled_events' => (int) $one('SELECT COUNT(*) FROM events WHERE organizer_id = :o AND status IN ("cancelled", "removed")'),
            'previous_badges' => (int) $one('SELECT COUNT(*) FROM organizer_badge_applications WHERE organizer_id = :o AND status = "approved"'),
        ];
    }

    public static function pendingCount(PDO $db): int
    {
        try {
            return (int) $db->query('SELECT COUNT(*) FROM organizer_badge_applications WHERE status = "pending"')->fetchColumn();
        } catch (PDOException $e) {
            return 0; // v78 tables not installed on this copy
        }
    }

    /** Daily job: one reminder before the badge expires and one when it has expired. */
    public static function remindExpiring(PDO $db): int
    {
        $sent = 0;
        $rows = $db->query('SELECT id, user_id, organization_name, badge_expires_on, badge_application_id FROM organizers
                            WHERE badge_verified_at IS NOT NULL AND badge_revoked_at IS NULL AND status = "approved"
                              AND badge_expires_on <= CURDATE() + INTERVAL ' . self::REMIND_DAYS . ' DAY')->fetchAll();
        foreach ($rows as $o) {
            $expired = $o['badge_expires_on'] < date('Y-m-d');
            $sent += (int) NotificationService::once($db, (int) $o['user_id'],
                $expired ? 'Verified Badge Expired' : 'Verified Badge Expiring Soon',
                $expired ? 'Your Verified Organizer badge expired on ' . date('M d, Y', strtotime($o['badge_expires_on'])) . '. Renew it from the Verified Badge page.'
                         : 'Your Verified Organizer badge expires on ' . date('M d, Y', strtotime($o['badge_expires_on'])) . '. Renew it now so your events keep the badge.',
                'organizer_badge', ['category' => 'system', 'notice_type' => 'action_required', 'reference_type' => 'organizer_badge', 'reference_id' => (int) $o['badge_application_id']]);
        }

        return $sent;
    }

    // ------------------------------------------------------------------ internals

    /** Kind / number / expiry posted for one document type (inputs named <type>_kind etc.). */
    private static function documentMeta(string $type, array $post, array &$errors): array
    {
        $kind = trim((string) ($post[$type . '_kind'] ?? ''));
        $ref = mb_substr(trim((string) ($post[$type . '_ref'] ?? '')), 0, 60);
        $expires = trim((string) ($post[$type . '_expires'] ?? ''));
        $label = self::DOCS[$type][0];
        if ($type === 'government_id' && !in_array($kind, self::ID_KINDS, true)) {
            $errors[] = 'Choose the type of your government ID.';
        }
        if ($type === 'organization_proof' && !in_array($kind, self::ORG_PROOF_KINDS, true)) {
            $errors[] = 'Choose the type of your proof of organization.';
        }
        if ($expires !== '') {
            $ts = strtotime($expires);
            if ($ts === false) {
                $errors[] = $label . ': enter a valid expiry date.';
                $expires = '';
            } elseif (date('Y-m-d', $ts) <= date('Y-m-d')) {
                $errors[] = $label . ' is already expired. Upload a document that is still valid.';
            } else {
                $expires = date('Y-m-d', $ts);
            }
        }

        return ['kind' => in_array($type, ['government_id', 'organization_proof'], true) ? $kind : null, 'ref' => $ref ?: null, 'expires' => $expires ?: null];
    }

    private static function insertDocument(PDO $db, int $applicationId, int $organizerId, string $type, string $path, array $meta): void
    {
        $hash = hash_file('sha256', STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        $flags = [];
        $dup = $db->prepare('SELECT 1 FROM organizer_badge_documents d JOIN organizer_badge_applications a ON a.id = d.application_id
                             WHERE d.file_hash = :h AND a.organizer_id <> :o LIMIT 1');
        $dup->execute(['h' => $hash, 'o' => $organizerId]);
        if ($dup->fetchColumn()) {
            $flags[] = 'duplicate_file';
        }
        if ($meta['expires'] && $meta['expires'] <= date('Y-m-d', strtotime('+' . self::REMIND_DAYS . ' days'))) {
            $flags[] = 'expires_soon';
        }
        $db->prepare('INSERT INTO organizer_badge_documents (application_id, doc_type, document_kind, reference_no, expires_on, file_path, file_hash, auto_flags)
                      VALUES (:a, :t, :k, :r, :e, :p, :h, :f)')
            ->execute(['a' => $applicationId, 't' => $type, 'k' => $meta['kind'], 'r' => $meta['ref'], 'e' => $meta['expires'],
                'p' => $path, 'h' => $hash, 'f' => $flags ? implode(',', $flags) : null]);
    }

    private static function allowedText(string $type): string
    {
        return in_array('pdf', self::DOCS[$type][3], true) ? 'JPG, PNG or PDF' : 'JPG or PNG';
    }

    private static function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink(STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        }
    }
}
