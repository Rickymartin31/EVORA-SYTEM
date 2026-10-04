<?php

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Issues a certificate PDF (with a certificate number and a scannable
 * verification QR) for an approved registration. Used by the organizer's
 * manual "Issue Certificate" button, the bulk "Generate Certificates" action,
 * the preview screen, and automatically when a Seminar/Workshop attendance
 * is confirmed.
 */
class CertificateService
{
    /**
     * Per-category visual identity. Typographic/color differentiation only —
     * no icon fonts or emoji, since Dompdf's glyph support for those is
     * unreliable; a colored border + eyebrow label renders consistently.
     */
    private static function themeFor(?string $categorySlug): array
    {
        $themes = [
            'fun-run' => ['primary' => '#16a34a', 'secondary' => '#f97316', 'eyebrow' => 'Fun Run &bull; Race Achievement', 'border_style' => 'solid'],
            'sports-tournament' => ['primary' => '#1e3a8a', 'secondary' => '#d4af37', 'eyebrow' => 'Sports Tournament &bull; Recognition', 'border_style' => 'double'],
            'seminar-workshop' => ['primary' => '#1e293b', 'secondary' => '#64748b', 'eyebrow' => 'Seminar / Workshop &bull; Professional Development', 'border_style' => 'solid'],
        ];

        return $themes[$categorySlug] ?? ['primary' => '#4f46e5', 'secondary' => '#4f46e5', 'eyebrow' => 'Certificate of Recognition', 'border_style' => 'solid'];
    }

    private static function dataUri(?string $relativePath): ?string
    {
        if (empty($relativePath)) {
            return null;
        }
        $fullPath = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($fullPath)) {
            return null;
        }
        $mime = mime_content_type($fullPath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
    }

    /**
     * Builds the certificate HTML shared by real generation and the preview
     * screen — one template, so what an organizer previews is exactly what
     * gets rendered to PDF.
     *
     * @param array $reg      participant_name, event_name, event_date, venue, category_slug, organizer_name, organization_name
     * @param array $settings event_certificate_settings row, or [] if the organizer hasn't customized this event
     */
    public static function buildHtml(string $title, string $bodyVerb, array $reg, array $settings, string $certificateNumber, string $issuedAt, ?string $qrDataUri): string
    {
        $theme = self::themeFor($reg['category_slug'] ?? null);

        $displayTitle = $settings['certificate_title'] ?? null;
        $displayTitle = ($displayTitle !== null && $displayTitle !== '') ? $displayTitle : $title;

        $venue = $settings['venue_override'] ?? null;
        $venue = ($venue !== null && $venue !== '') ? $venue : ($reg['venue'] ?? '');

        $orgName = $settings['organization_name_override'] ?? null;
        $orgName = ($orgName !== null && $orgName !== '') ? $orgName : ($reg['organization_name'] ?? '');

        $organizerName = $settings['organizer_name_override'] ?? null;
        $organizerName = ($organizerName !== null && $organizerName !== '') ? $organizerName : ($reg['organizer_name'] ?? '');

        $description = $settings['description'] ?? '';

        // Logo: event-level override first, then the system-wide logo, then a text wordmark.
        $logoImg = '<span style="font-size:28px;font-weight:800;letter-spacing:2px;color:#1e1b4b;">EVO<span style="color:' . $theme['primary'] . ';">RA</span></span>';
        $logoDataUri = self::dataUri($settings['logo_path'] ?? null);
        if ($logoDataUri === null) {
            $sysSettings = function_exists('systemSettings') ? systemSettings() : [];
            $logoDataUri = self::dataUri($sysSettings['logo_path'] ?? null);
        }
        if ($logoDataUri !== null) {
            $logoImg = '<img src="' . $logoDataUri . '" style="max-height:50px;max-width:220px;">';
        }

        $signatoriesHtml = '';
        foreach ([1, 2] as $n) {
            $name = trim((string) ($settings['signatory' . $n . '_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $sigTitle = trim((string) ($settings['signatory' . $n . '_title'] ?? ''));
            $sigImg = self::dataUri($settings['signatory' . $n . '_signature_path'] ?? null);
            $signatoriesHtml .= '<div class="signatory">'
                . ($sigImg ? '<img src="' . $sigImg . '" class="sig-img">' : '<div class="sig-space"></div>')
                . '<div class="sig-name">' . htmlspecialchars($name) . '</div>'
                . ($sigTitle !== '' ? '<div class="sig-title">' . htmlspecialchars($sigTitle) . '</div>' : '')
                . '</div>';
        }

        $qrBlock = $qrDataUri ? '<img src="' . $qrDataUri . '" width="70" height="70">' : '';

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: sans-serif; text-align: center; padding-top: 30px; }
            .border-frame { border: 10px ' . $theme['border_style'] . ' ' . $theme['primary'] . '; padding: 40px; position: relative; }
            .brand { margin-bottom: 8px; }
            .eyebrow { font-size: 11px; letter-spacing: 3px; color: ' . $theme['secondary'] . '; text-transform: uppercase; font-weight: 700; margin: 0 0 6px; }
            h1 { font-size: 13px; letter-spacing: 4px; color: #888; text-transform: uppercase; margin: 4px 0 0; }
            h2 { font-size: 30px; margin: 8px 0 16px; color: #1e1b4b; }
            .name { font-size: 28px; font-weight: bold; margin: 20px 0; border-bottom: 2px solid ' . $theme['primary'] . '; display: inline-block; padding-bottom: 8px; }
            .detail { font-size: 13px; color: #444; margin-top: 20px; }
            .description { font-size: 12px; color: #555; margin-top: 14px; font-style: italic; }
            .signatories { display: flex; justify-content: center; gap: 60px; margin-top: 40px; }
            .signatory { text-align: center; width: 180px; }
            .sig-img { max-height: 45px; max-width: 160px; margin-bottom: 2px; }
            .sig-space { height: 45px; }
            .sig-name { border-top: 1px solid #999; margin-top: 4px; padding-top: 4px; font-size: 12px; font-weight: 600; }
            .sig-title { font-size: 10px; color: #777; }
            .footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; text-align: left; }
            .cert-meta { font-size: 11px; color: #888; text-align: left; line-height: 1.6; }
            .cert-meta strong { color: #444; }
        </style></head><body>
            <div class="border-frame">
                <div class="brand">' . $logoImg . '</div>
                <p class="eyebrow">' . $theme['eyebrow'] . '</p>
                <h1>' . htmlspecialchars($displayTitle) . '</h1>
                <p>This certifies that</p>
                <div class="name">' . htmlspecialchars($reg['participant_name']) . '</div>
                <p>' . htmlspecialchars($bodyVerb) . '</p>
                <p class="detail"><strong>' . htmlspecialchars($reg['event_name']) . '</strong><br>
                held on ' . date('F d, Y', strtotime($reg['event_date'])) . ($venue !== '' ? ' at ' . htmlspecialchars($venue) : '') . '<br>
                organized by ' . htmlspecialchars($organizerName) . ($orgName !== '' ? ' (' . htmlspecialchars($orgName) . ')' : '') . '</p>
                ' . ($description !== '' ? '<p class="description">' . nl2br(htmlspecialchars($description)) . '</p>' : '') . '
                ' . ($signatoriesHtml !== '' ? '<div class="signatories">' . $signatoriesHtml . '</div>' : '') . '
                <div class="footer">
                    <div class="cert-meta">
                        <strong>Certificate ID:</strong> ' . htmlspecialchars($certificateNumber) . '<br>
                        <strong>Date Issued:</strong> ' . htmlspecialchars($issuedAt) . '
                    </div>
                    ' . $qrBlock . '
                </div>
            </div>
        </body></html>';
    }

    /** @return array{participant_name:string,event_name:string,event_date:string,venue:string,category_slug:?string,organizer_name:string,organization_name:string}|null */
    private static function fetchRegistrationContext(PDO $db, int $registrationId): ?array
    {
        $stmt = $db->prepare(
            'SELECT r.id, r.event_id, u.full_name AS participant_name, u.id AS user_id,
                e.name AS event_name, e.event_date, e.venue, c.slug AS category_slug,
                orgu.full_name AS organizer_name, o.organization_name
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             JOIN event_categories c ON c.id = e.category_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             JOIN organizers o ON o.id = e.organizer_id
             JOIN users orgu ON orgu.id = o.user_id
             WHERE r.id = :rid AND r.status = "approved"'
        );
        $stmt->execute(['rid' => $registrationId]);
        $reg = $stmt->fetch();

        return $reg ?: null;
    }

    private static function fetchSettings(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT * FROM event_certificate_settings WHERE event_id = :eid');
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetch() ?: [];
    }

    /**
     * @param string $title    Certificate heading, e.g. "Certificate of Participation", "Certificate of Completion", "Certificate of Achievement"
     * @param string $bodyVerb The phrase between the participant's name and the event name, e.g. "has successfully participated in", "has successfully completed", "achieved 1st place in"
     * @param ?int   $generatedBy user id of whoever triggered this issuance (organizer, or null for an automated trigger)
     * @return bool true if a new certificate was issued, false if one already exists or the registration isn't eligible
     */
    public static function issue(PDO $db, int $registrationId, string $title = 'Certificate of Participation', string $bodyVerb = 'has successfully participated in', ?int $generatedBy = null): bool
    {
        $existing = $db->prepare('SELECT id FROM certificates WHERE registration_id = :rid');
        $existing->execute(['rid' => $registrationId]);
        if ($existing->fetch()) {
            return false;
        }

        $reg = self::fetchRegistrationContext($db, $registrationId);
        if (!$reg) {
            return false;
        }
        $settings = self::fetchSettings($db, (int) $reg['event_id']);

        $certificateNumber = 'EVORA-' . date('Y') . '-' . str_pad((string) $registrationId, 6, '0', STR_PAD_LEFT);
        $verificationToken = bin2hex(random_bytes(16));
        $issuedAt = date('F d, Y');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $verifyUrl = $scheme . '://' . $host . BASE_URL . '/index.php?route=certificate-verify&token=' . $verificationToken;

        $qrResult = Builder::create()->writer(new PngWriter())->data($verifyUrl)->size(130)->margin(6)->build();
        $qrDataUri = $qrResult->getDataUri();

        $html = self::buildHtml($title, $bodyVerb, $reg, $settings, $certificateNumber, $issuedAt, $qrDataUri);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $targetDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'certificates';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'cert_' . $registrationId . '_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . $filename, $dompdf->output());
        $relativePath = 'certificates/' . $filename;

        $insert = $db->prepare(
            'INSERT INTO certificates (registration_id, certificate_path, certificate_number, certificate_type, verification_token, issued_at, generated_by, status)
             VALUES (:rid, :path, :number, :type, :token, NOW(), :generated_by, "issued")'
        );
        $insert->execute([
            'rid' => $registrationId, 'path' => $relativePath, 'number' => $certificateNumber, 'type' => $title,
            'token' => $verificationToken, 'generated_by' => $generatedBy,
        ]);

        notify((int) $reg['user_id'], 'Certificate Issued', 'Your certificate for "' . $reg['event_name'] . '" is ready to download.', 'certificate_issued');

        return true;
    }

    /**
     * Renders the same template as issue() but writes nothing — no PDF file,
     * no database row, no notification. Used by the Certificate Management
     * preview screen so what an organizer sees is guaranteed to match the
     * real output.
     */
    public static function previewHtml(PDO $db, int $eventId, string $title, string $bodyVerb): ?string
    {
        $eventStmt = $db->prepare(
            'SELECT e.name AS event_name, e.event_date, e.venue, c.slug AS category_slug,
                orgu.full_name AS organizer_name, o.organization_name
             FROM events e
             JOIN event_categories c ON c.id = e.category_id
             JOIN organizers o ON o.id = e.organizer_id
             JOIN users orgu ON orgu.id = o.user_id
             WHERE e.id = :id'
        );
        $eventStmt->execute(['id' => $eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return null;
        }

        // Prefer a real approved registrant's name so the preview feels
        // concrete; fall back to a placeholder if none exist yet.
        $sampleStmt = $db->prepare(
            'SELECT u.full_name FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             WHERE r.event_id = :id AND r.status = "approved" ORDER BY r.registered_at LIMIT 1'
        );
        $sampleStmt->execute(['id' => $eventId]);
        $sampleName = $sampleStmt->fetch()['full_name'] ?? 'Juan Dela Cruz';

        $reg = array_merge($event, ['participant_name' => $sampleName]);
        $settings = self::fetchSettings($db, $eventId);

        // A harmless placeholder QR — never tied to a real certificate row,
        // so scanning it correctly resolves to "Certificate Not Found".
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $previewUrl = $scheme . '://' . $host . BASE_URL . '/index.php?route=certificate-verify&token=preview';
        $qrResult = Builder::create()->writer(new PngWriter())->data($previewUrl)->size(130)->margin(6)->build();

        return self::buildHtml($title, $bodyVerb, $reg, $settings, 'PREVIEW-0000', date('F d, Y'), $qrResult->getDataUri());
    }

    /**
     * Auto-issues certificates for one event right after it's marked
     * completed, if the organizer has turned this on for that event. Reuses
     * issue() exactly like the manual bulk "Generate All" button does —
     * generatedBy is left null so these show as system-issued, same as the
     * existing scan-triggered seminar auto-issue.
     *
     * @return int number of certificates issued
     */
    public static function autoIssueForEvent(PDO $db, int $eventId): int
    {
        $settings = self::fetchSettings($db, $eventId);
        if (empty($settings['auto_issue_enabled'])) {
            return 0;
        }

        $eventStmt = $db->prepare('SELECT c.slug AS category_slug FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = :id');
        $eventStmt->execute(['id' => $eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return 0;
        }

        // Same default title/verb per category as the manual bulk generator.
        $defaultTitlesByCategory = [
            'fun-run' => ['Certificate of Participation', 'has successfully participated in'],
            'seminar-workshop' => ['Certificate of Participation', 'has successfully participated in'],
        ];
        [$defaultTitle, $defaultVerb] = $defaultTitlesByCategory[$event['category_slug']] ?? ['Certificate of Participation', 'has successfully participated in'];
        $title = !empty($settings['certificate_title']) ? $settings['certificate_title'] : $defaultTitle;

        $attendanceRequired = !empty($settings['attendance_required']);

        $eligibleStmt = $db->prepare(
            'SELECT r.id FROM registrations r
             JOIN events e ON e.id = r.event_id
             LEFT JOIN race_categories rc ON rc.id = r.race_category_id
             LEFT JOIN certificates cert ON cert.registration_id = r.id
             LEFT JOIN payments pay ON pay.registration_id = r.id AND pay.status = "approved"
             LEFT JOIN attendance att ON att.registration_id = r.id
             WHERE r.event_id = :eid AND r.status = "approved" AND cert.id IS NULL
               AND (COALESCE(rc.fee, e.fee) <= 0 OR pay.id IS NOT NULL)
               AND (:attendance_required = 0 OR att.id IS NOT NULL)'
        );
        $eligibleStmt->execute(['eid' => $eventId, 'attendance_required' => $attendanceRequired ? 1 : 0]);
        $eligible = $eligibleStmt->fetchAll();

        $issuedCount = 0;
        foreach ($eligible as $row) {
            if (self::issue($db, (int) $row['id'], $title, $defaultVerb, null)) {
                $issuedCount++;
            }
        }

        return $issuedCount;
    }
}
