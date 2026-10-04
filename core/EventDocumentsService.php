<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Participant documents as PDF (v43): the e-ticket (with the check-in QR) and the payment receipt.
 * Used by the download pages and as email attachments, so both always look the same.
 * Each returns ['filename' => ..., 'pdf' => bytes] or null when the document is not available.
 * Pass $ownerUserId to require that the registration/payment belongs to that user.
 */
class EventDocumentsService
{
    public static function ticket(PDO $db, int $registrationId, ?int $ownerUserId = null): ?array
    {
        $stmt = $db->prepare(
            'SELECT q.qr_image_path, r.id AS registration_id, r.distance_category, r.bib_number, e.name AS event_name, e.event_date,
                    e.event_time, e.venue, e.contact_info, o.organization_name, u.full_name, u.id AS user_id
             FROM qr_codes q JOIN registrations r ON r.id = q.registration_id JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id JOIN events e ON e.id = r.event_id JOIN organizers o ON o.id = e.organizer_id
             WHERE r.id = :rid AND r.status = "approved" AND q.invalidated_at IS NULL ORDER BY q.id DESC LIMIT 1'
        );
        $stmt->execute(['rid' => $registrationId]);
        $t = $stmt->fetch();
        if (!$t || ($ownerUserId !== null && (int) $t['user_id'] !== $ownerUserId)) {
            return null;
        }
        $qrFile = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $t['qr_image_path']);
        if (!is_file($qrFile)) {
            return null;
        }
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $settings = systemSettings();
        $when = date('l, F d, Y', strtotime($t['event_date'])) . ($t['event_time'] ? ' · ' . date('g:i A', strtotime($t['event_time'])) : '');
        $qr = 'data:image/png;base64,' . base64_encode((string) file_get_contents($qrFile));
        $html = self::page('E-TICKET', $settings, '
            <h1>' . $h($t['event_name']) . '</h1>
            <div class="muted">Organized by ' . $h($t['organization_name']) . '</div>
            <table>
                <tr><td class="k">Participant</td><td><strong>' . $h($t['full_name']) . '</strong></td></tr>
                <tr><td class="k">When</td><td>' . $h($when) . '</td></tr>
                <tr><td class="k">Where</td><td>' . $h($t['venue']) . '</td></tr>'
            . ($t['distance_category'] ? '<tr><td class="k">Category</td><td>' . $h($t['distance_category']) . '</td></tr>' : '')
            . ($t['bib_number'] ? '<tr><td class="k">Bib number</td><td><strong>' . $h($t['bib_number']) . '</strong></td></tr>' : '')
            . '<tr><td class="k">Registration</td><td>#' . (int) $t['registration_id'] . '</td></tr>'
            . ($t['contact_info'] ? '<tr><td class="k">Organizer contact</td><td>' . $h($t['contact_info']) . '</td></tr>' : '') . '
            </table>
            <div class="qr"><img src="' . $qr . '" alt="QR code"></div>
            <div class="note">Show this QR code at the entrance for check-in. It is personal — do not share it.<br>
            If your registration is cancelled, this ticket stops working. Generated ' . $h(date('M d, Y g:i A')) . '.</div>');

        return ['filename' => 'EVORA-ticket-' . (int) $t['registration_id'] . '.pdf', 'pdf' => self::render($html), 'registration_id' => (int) $t['registration_id']];
    }

    public static function receipt(PDO $db, int $paymentId, ?int $ownerUserId = null): ?array
    {
        $stmt = $db->prepare(
            'SELECT pay.*, COALESCE(pay.paid_at, pay.reviewed_at, pay.submitted_at) AS pay_date, r.id AS reg_id, e.name AS event_name,
                    e.event_date, e.venue, o.organization_name, u.full_name, u.id AS user_id
             FROM payments pay JOIN registrations r ON r.id = pay.registration_id JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id JOIN events e ON e.id = r.event_id JOIN organizers o ON o.id = e.organizer_id
             WHERE pay.id = :id AND pay.status IN ("approved", "refunded")'
        );
        $stmt->execute(['id' => $paymentId]);
        $p = $stmt->fetch();
        if (!$p || ($ownerUserId !== null && (int) $p['user_id'] !== $ownerUserId)) {
            return null;
        }
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $paidTo = match ($p['payment_channel']) {
            'paypal' => $p['organization_name'] . ' (paid directly through PayPal)',
            'cash_walkin' => $p['organization_name'] . ' (cash, walk-in)',
            'paymongo' => 'EVORA (collected through PayMongo)',
            default => $p['organization_name'],
        };
        $ref = $p['reference_no'] ?: ('#' . $p['id']);
        $html = self::page('PAYMENT RECEIPT', systemSettings(), '
            <h1>' . ($p['status'] === 'refunded' ? 'Refunded' : 'Paid') . ' · ' . $h(currency($p['amount'])) . '</h1>
            <div class="muted">Reference ' . $h($ref) . '</div>
            <table>
                <tr><td class="k">Date paid</td><td>' . ($p['pay_date'] ? $h(date('F d, Y g:i A', strtotime($p['pay_date']))) : '—') . '</td></tr>
                <tr><td class="k">Paid by</td><td>' . $h($p['full_name']) . '</td></tr>
                <tr><td class="k">Paid to</td><td>' . $h($paidTo) . '</td></tr>
                <tr><td class="k">Payment method</td><td>' . $h(paymentChannelLabel($p['payment_channel'])) . '</td></tr>'
            . ($p['gateway_payment_id'] ? '<tr><td class="k">Transaction ID</td><td>' . $h($p['gateway_payment_id']) . '</td></tr>' : '') . '
                <tr><td class="k">Event</td><td>' . $h($p['event_name']) . ($p['event_date'] ? ' — ' . $h(date('F d, Y', strtotime($p['event_date']))) : '') . '</td></tr>
                <tr><td class="k">Registration</td><td>#' . (int) $p['reg_id'] . '</td></tr>
                <tr><td class="k"><strong>Amount</strong></td><td><strong>' . $h(currency($p['amount'])) . '</strong></td></tr>
            </table>
            <div class="note">Generated by EVORA from its payment records.' . ($p['payment_channel'] === 'paypal' ? ' The money was paid directly to the organizer through PayPal; EVORA did not receive or hold it.' : '') . '</div>');

        return ['filename' => 'EVORA-receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '', $ref) . '.pdf', 'pdf' => self::render($html), 'registration_id' => (int) $p['reg_id']];
    }

    private static function page(string $label, array $settings, string $body): string
    {
        $brand = htmlspecialchars(strtoupper($settings['system_name'] ?? 'EVORA'), ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="UTF-8"><style>
            body { font-family: DejaVu Sans, sans-serif; color: #1f2937; margin: 0; }
            .doc { border: 2px solid #4f46e5; border-radius: 14px; padding: 22px 26px; }
            .brand { font-size: 13px; color: #4f46e5; font-weight: bold; letter-spacing: 2px; }
            h1 { font-size: 22px; margin: 6px 0 4px; }
            .muted { color: #6b7280; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
            td { padding: 5px 0; vertical-align: top; } td.k { color: #6b7280; width: 32%; }
            .qr { text-align: center; margin-top: 16px; } .qr img { width: 230px; height: 230px; }
            .note { margin-top: 12px; font-size: 10px; color: #6b7280; text-align: center; }
        </style></head><body><div class="doc"><div class="brand">' . $brand . ' · ' . $label . '</div>' . $body . '</div></body></html>';
    }

    private static function render(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A5', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
