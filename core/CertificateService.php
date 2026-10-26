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
            $signatoriesHtml .= '<td class="signatory">'
                . ($sigImg ? '<img src="' . $sigImg . '" class="sig-img">' : '<div class="sig-space"></div>')
                . '<div class="sig-name">' . htmlspecialchars($name) . '</div>'
                . ($sigTitle !== '' ? '<div class="sig-title">' . htmlspecialchars($sigTitle) . '</div>' : '')
                . '</td>';
        }

        $qrBlock = $qrDataUri ? '<img src="' . $qrDataUri . '" width="78" height="78">' : '';

        // One A4 landscape page (297 x 210 mm) laid out with fixed positions and tables:
        // Dompdf does not support flexbox, which used to push the QR footer onto a second page.
        return '<html><head><meta charset="utf-8"><style>
            @page { size: A4 landscape; margin: 0; }
            html, body { margin: 0; padding: 0; }
            body { font-family: "DejaVu Sans", sans-serif; color: #1f2937; }
            .page { position: relative; width: 297mm; height: 209mm; overflow: hidden; background: #fff; }
            .frame { position: absolute; top: 9mm; left: 9mm; width: 273mm; height: 185mm; border: 7px ' . $theme['border_style'] . ' ' . $theme['primary'] . '; }
            .inner { position: absolute; top: 3mm; left: 3mm; width: 265mm; height: 177mm; border: 1px solid #d1d5db; }
            .content { position: absolute; top: 9mm; left: 12mm; width: 241mm; text-align: center; }
            .brand { margin: 0 0 2mm; }
            .eyebrow { font-size: 9.5px; letter-spacing: 3px; color: ' . $theme['secondary'] . '; text-transform: uppercase; font-weight: bold; margin: 0 0 1.5mm; }
            h1 { font-size: 13px; letter-spacing: 4px; color: #6b7280; text-transform: uppercase; margin: 0 0 5mm; font-weight: bold; }
            .certifies { font-size: 12px; margin: 0; }
            .name { font-size: 30px; font-weight: bold; color: #111827; margin: 4mm 0 1mm; }
            .name-line { width: 120mm; margin: 0 auto 4mm; border-top: 2px solid ' . $theme['primary'] . '; }
            .verb { font-size: 12px; margin: 0 0 3mm; }
            .event-name { font-size: 17px; font-weight: bold; color: #1e1b4b; margin: 0 0 1.5mm; }
            .detail { font-size: 11px; color: #4b5563; margin: 0; line-height: 1.5; }
            .description { font-size: 10.5px; color: #555; margin: 3mm 30mm 0; font-style: italic; line-height: 1.4; }
            table.signatories { margin: 9mm auto 0; border-collapse: collapse; }
            td.signatory { text-align: center; width: 62mm; padding: 0 9mm; vertical-align: bottom; }
            .sig-img { max-height: 14mm; max-width: 50mm; }
            .sig-space { height: 12mm; }
            .sig-name { border-top: 1px solid #9ca3af; margin-top: 1mm; padding-top: 1mm; font-size: 11px; font-weight: bold; }
            .sig-title { font-size: 9px; color: #6b7280; }
            .footer { position: absolute; bottom: 5mm; left: 8mm; width: 249mm; }
            .footer table { width: 100%; border-collapse: collapse; }
            .cert-meta { font-size: 9.5px; color: #6b7280; text-align: left; line-height: 1.6; vertical-align: bottom; }
            .cert-meta strong { color: #374151; }
            .qr { text-align: right; vertical-align: bottom; }
        </style></head><body>
            <div class="page"><div class="frame"><div class="inner">
                <div class="content">
                    <div class="brand">' . $logoImg . '</div>
                    <p class="eyebrow">' . $theme['eyebrow'] . '</p>
                    <h1>' . htmlspecialchars($displayTitle) . '</h1>
                    <p class="certifies">This certifies that</p>
                    <div class="name">' . htmlspecialchars($reg['participant_name']) . '</div>
                    <div class="name-line"></div>
                    <p class="verb">' . htmlspecialchars($bodyVerb) . '</p>
                    <p class="event-name">' . htmlspecialchars($reg['event_name']) . '</p>
                    <p class="detail">held on ' . date('F d, Y', strtotime($reg['event_date'])) . ($venue !== '' ? ' at ' . htmlspecialchars($venue) : '') . '<br>
                    organized by ' . htmlspecialchars($organizerName) . ($orgName !== '' ? ' (' . htmlspecialchars($orgName) . ')' : '') . '</p>
                    ' . ($description !== '' ? '<p class="description">' . nl2br(htmlspecialchars(mb_substr($description, 0, 400))) . '</p>' : '') . '
                    ' . ($signatoriesHtml !== '' ? '<table class="signatories"><tr>' . $signatoriesHtml . '</tr></table>' : '') . '
                </div>
                <div class="footer"><table><tr>
                    <td class="cert-meta"><strong>Certificate ID:</strong> ' . htmlspecialchars($certificateNumber) . '<br>
                        <strong>Date Issued:</strong> ' . htmlspecialchars($issuedAt) . '</td>
                    <td class="qr">' . $qrBlock . '</td>
                </tr></table></div>
            </div></div></div>
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
     * Re-renders the PDF of an already issued certificate with the current template, keeping
     * its certificate number, verification token (so the QR code still verifies), type and
     * issue date. The file is rewritten in place; nothing else changes. Used to move
     * certificates issued with the old two-page layout to the one-page layout.
     */
    public static function regenerate(PDO $db, int $certificateId): bool
    {
        $stmt = $db->prepare('SELECT * FROM certificates WHERE id = :id');
        $stmt->execute(['id' => $certificateId]);
        $cert = $stmt->fetch();
        if (!$cert || empty($cert['certificate_path'])) {
            return false;
        }
        $ctx = $db->prepare(
            'SELECT r.id, r.event_id, u.full_name AS participant_name, e.name AS event_name, e.event_date, e.venue,
                c.slug AS category_slug, orgu.full_name AS organizer_name, o.organization_name
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             JOIN event_categories c ON c.id = e.category_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             JOIN organizers o ON o.id = e.organizer_id
             JOIN users orgu ON orgu.id = o.user_id
             WHERE r.id = :rid'
        );
        $ctx->execute(['rid' => $cert['registration_id']]);
        $reg = $ctx->fetch();
        if (!$reg) {
            return false;
        }
        $title = (string) ($cert['certificate_type'] ?: 'Certificate of Participation');
        $verb = match (true) {
            stripos($title, 'Completion') !== false => 'has successfully completed',
            stripos($title, 'Achievement') !== false => 'achieved a top finish in',
            stripos($title, 'Attendance') !== false => 'attended',
            default => 'has successfully participated in',
        };
        $verifyUrl = siteOrigin() . BASE_URL . '/index.php?route=certificate-verify&token=' . $cert['verification_token'];
        $qr = Builder::create()->writer(new PngWriter())->data($verifyUrl)->size(130)->margin(6)->build();
        $html = self::buildHtml($title, $verb, $reg, self::fetchSettings($db, (int) $reg['event_id']), (string) $cert['certificate_number'],
            date('F d, Y', strtotime((string) $cert['issued_at'])), $qr->getDataUri());

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $file = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $cert['certificate_path']);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        return file_put_contents($file, $dompdf->output()) !== false;
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
