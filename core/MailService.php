<?php

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outgoing email (v35).
 *
 * Settings come from an ini file OUTSIDE the project (never from source, the DB or the
 * request): C:\xampp\evora_secrets\mail.ini by default, or MAIL_SECRETS_FILE when a
 * config defines it. Missing file / MAIL_ENABLED=0 => email is simply off (fail closed);
 * notifications still appear in EVORA.
 *
 *   MAIL_ENABLED=1
 *   MAIL_TRANSPORT=smtp            ; or "log" (writes .eml files to storage/logs/mail — testing)
 *   SMTP_HOST=smtp.gmail.com
 *   SMTP_PORT=587
 *   SMTP_ENCRYPTION=tls            ; tls (587) or ssl (465)
 *   SMTP_USERNAME=you@gmail.com
 *   SMTP_PASSWORD=<Gmail App Password>
 *   MAIL_FROM_EMAIL=you@gmail.com
 *   MAIL_FROM_NAME=EVORA
 *   APP_BASE_URL=http://127.0.0.1/EVORA%20SYSTEM/public   ; used for links inside emails
 *
 * Notification emails go through the email_queue table and are sent in small batches
 * (after the bell's live refresh, or by scripts/send_email_queue.php) so no page waits
 * on the mail server. Password-reset emails are sent immediately.
 */
class MailService
{
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $secretsDir = defined('EVORA_SECRETS_DIR') ? EVORA_SECRETS_DIR : dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_secrets';
        $file = defined('MAIL_SECRETS_FILE') ? MAIL_SECRETS_FILE
            : (getenv('EVORA_MAIL_SECRETS_FILE') ?: $secretsDir . DIRECTORY_SEPARATOR . 'mail.ini');
        $ini = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
        $get = fn(string $key, string $default = ''): string => trim((string) ($ini[$key] ?? $default));

        $transport = $get('MAIL_TRANSPORT', 'smtp') === 'log' ? 'log' : 'smtp';
        $from = $get('MAIL_FROM_EMAIL', $get('SMTP_USERNAME'));
        $enabled = $get('MAIL_ENABLED', '0') === '1' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && ($transport === 'log' || ($get('SMTP_HOST') !== '' && $get('SMTP_USERNAME') !== '' && $get('SMTP_PASSWORD') !== ''));

        return self::$config = [
            'enabled' => $enabled,
            'transport' => $transport,
            'host' => $get('SMTP_HOST'),
            'port' => (int) $get('SMTP_PORT', '587'),
            'encryption' => strtolower($get('SMTP_ENCRYPTION', 'tls')) === 'ssl' ? 'ssl' : 'tls',
            'username' => $get('SMTP_USERNAME'),
            'password' => $get('SMTP_PASSWORD'),
            'from_email' => $from,
            'from_name' => $get('MAIL_FROM_NAME', 'EVORA'),
            // links inside emails: APP_BASE_URL if set, else the configured APP_URL (v42)
            'base_url' => rtrim($get('APP_BASE_URL', defined('APP_URL') ? APP_URL : 'http://127.0.0.1' . BASE_URL), '/'),
        ];
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    /** Absolute link for emails, built from APP_BASE_URL (never from the request's Host header). */
    public static function link(string $route, array $params = []): string
    {
        return self::config()['base_url'] . '/index.php?' . http_build_query(array_merge(['route' => $route], $params));
    }

    /**
     * Sends one email now: a branded HTML version plus the plain-text version for old mail apps.
     * $attachments: list of ['name' => 'file.pdf', 'data' => bytes, 'mime' => 'application/pdf'].
     * Returns null on success, or a short safe error.
     */
    /**
     * False for addresses that can never receive mail (v66): not an email at all, or a reserved domain such as
     * .invalid / .test / .local / example.com (used for placeholder and test accounts). Sending to them only
     * bounces back to EVORA's own mailbox as "Address not found".
     */
    public static function deliverable(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = strtolower(substr((string) strrchr($email, '@'), 1));

        return !preg_match('/(^|\.)(invalid|test|example|localhost|local)$/', $domain) && !preg_match('/^example\.(com|net|org)$/', $domain);
    }

    public static function send(string $toEmail, string $toName, string $subject, string $bodyText, array $attachments = []): ?string
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return 'Email is not configured.';
        }
        if (!self::deliverable($toEmail)) {
            return 'Undeliverable recipient address.';
        }
        $body = $bodyText . "\n\n— " . $cfg['from_name'] . "\nThis is an automated message from EVORA. Please do not reply.";

        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = self::html($subject, $bodyText, $toName);
            $mail->AltBody = $body;
            foreach ($attachments as $a) {
                $mail->addStringAttachment($a['data'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime'] ?? 'application/octet-stream');
            }

            if ($cfg['transport'] === 'log') {
                $mail->preSend();
                $dir = EVORA_ROOT . '/storage/logs/mail';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                file_put_contents($dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.eml', $mail->getSentMIMEMessage());

                return null;
            }

            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['username'];
            $mail->Password = $cfg['password'];
            $mail->SMTPSecure = $cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 15;
            $mail->send();

            return null;
        } catch (MailerException $e) {
            // ErrorInfo never contains the password; keep it short anyway.
            return mb_substr('Send failed: ' . ($mail->ErrorInfo ?: $e->getMessage()), 0, 250);
        }
    }

    /** Puts a notification email in the queue (no-op when email is off or the address is not deliverable). */
    public static function queue(PDO $db, int $userId, string $toEmail, string $subject, string $bodyText, ?int $notificationId = null, ?array $attachments = null): void
    {
        if (!self::enabled() || !self::deliverable($toEmail)) {
            return; // the in-app notification still reaches the user
        }
        $db->prepare('INSERT INTO email_queue (user_id, to_email, subject, body_text, attachments, notification_id) VALUES (:uid, :email, :subject, :body, :att, :nid)')
            ->execute(['uid' => $userId, 'email' => $toEmail, 'subject' => mb_substr($subject, 0, 200), 'body' => $bodyText, 'att' => $attachments ? json_encode($attachments) : null, 'nid' => $notificationId]);
    }

    /**
     * Sends up to $limit queued emails. Each row is claimed atomically, so two requests
     * running this at the same time never send the same email twice. Returns [sent, failed].
     */
    public static function processQueue(PDO $db, int $limit = 5): array
    {
        if (!self::enabled()) {
            return [0, 0];
        }
        // Rows stuck in "sending" (request died mid-send) go back to the queue after 10 minutes.
        $db->exec("UPDATE email_queue SET status = 'pending' WHERE status = 'sending' AND locked_at < NOW() - INTERVAL 10 MINUTE AND attempts < 3");
        $rows = $db->query("SELECT q.id, q.to_email, q.subject, q.body_text, q.attachments, u.full_name FROM email_queue q LEFT JOIN users u ON u.id = q.user_id
            WHERE q.status = 'pending' ORDER BY q.id LIMIT " . max(1, min(20, $limit)))->fetchAll();
        $claim = $db->prepare("UPDATE email_queue SET status = 'sending', locked_at = NOW(), attempts = attempts + 1 WHERE id = :id AND status = 'pending'");
        $done = $db->prepare('UPDATE email_queue SET status = :status, last_error = :err, sent_at = IF(:sent = 1, NOW(), sent_at) WHERE id = :id');
        $sent = $failed = 0;
        foreach ($rows as $row) {
            $claim->execute(['id' => $row['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $error = self::send($row['to_email'], (string) ($row['full_name'] ?? ''), $row['subject'], $row['body_text'], self::buildAttachments($db, $row['attachments'] ?? null));
            if ($error === null) {
                $done->execute(['status' => 'sent', 'err' => null, 'sent' => 1, 'id' => $row['id']]);
                $sent++;
            } else {
                $attempts = (int) $db->query('SELECT attempts FROM email_queue WHERE id = ' . (int) $row['id'])->fetchColumn();
                $done->execute(['status' => $attempts >= 3 ? 'failed' : 'pending', 'err' => $error, 'sent' => 0, 'id' => $row['id']]);
                $failed++;
            }
        }

        return [$sent, $failed];
    }

    /**
     * Branded HTML version of a plain-text message: paragraphs are kept, a line that is only a link
     * becomes a button (the first) or a plain link, and links inside text are clickable.
     */
    public static function html(string $subject, string $text, string $toName = ''): string
    {
        $cfg = self::config();
        $esc = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $linkify = fn(string $s) => preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#4f46e5;">$1</a>', $esc($s));
        $blocks = preg_split("/\n\s*\n/", trim(str_replace("\r", '', $text)));
        $html = '';
        $buttonDone = false;
        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            $out = [];
            foreach ($lines as $line) {
                $t = trim($line);
                if (preg_match('~^https?://\S+$~', $t)) {
                    if (!$buttonDone) {
                        $buttonDone = true;
                        $label = str_contains($t, 'reset-password') ? 'Choose a New Password' : (str_contains($t, 'verify-email') ? 'Verify My Email' : 'Open EVORA');
                        $out[] = '<a href="' . $esc($t) . '" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:8px;margin:6px 0;">' . $label . '</a>'
                            . '<div style="font-size:12px;color:#6b7280;margin-top:6px;word-break:break-all;">Or open: <a href="' . $esc($t) . '" style="color:#6b7280;">' . $esc($t) . '</a></div>';
                    } else {
                        $out[] = '<a href="' . $esc($t) . '" style="color:#4f46e5;word-break:break-all;">' . $esc($t) . '</a>';
                    }
                } elseif (preg_match('/^\s{2,}(\d{6})$/', $line, $m)) {
                    $out[] = '<div style="font-size:30px;letter-spacing:8px;font-weight:700;color:#111827;margin:6px 0;">' . $m[1] . '</div>'; // login code
                } else {
                    $out[] = $linkify($t);
                }
            }
            $html .= '<p style="margin:0 0 16px;line-height:1.55;">' . implode('<br>', $out) . '</p>';
        }
        $prefs = $esc(self::link('notification-preferences'));

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $esc($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f5f9;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f9;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="background:#1e1b4b;padding:18px 26px;color:#ffffff;font-size:20px;font-weight:800;letter-spacing:.5px;">EVO<span style="color:#22d3ee;">RA</span></td></tr>'
            . '<tr><td style="padding:26px;font-size:15px;">'
            . '<h1 style="font-size:19px;margin:0 0 16px;color:#111827;">' . $esc(preg_replace('/^EVORA:\s*/', '', $subject)) . '</h1>'
            . $html
            . '</td></tr>'
            . '<tr><td style="padding:16px 26px;background:#f9fafb;font-size:12px;color:#6b7280;line-height:1.5;">'
            . 'This is an automated message from ' . $esc($cfg['from_name']) . '. Please do not reply.<br>'
            . '<a href="' . $prefs . '" style="color:#6b7280;">Manage email notifications</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** Builds queued attachments: [{"type":"ticket","registration_id":1}, {"type":"receipt","payment_id":2}, {"type":"ics","event_id":3}]. */
    private static function buildAttachments(PDO $db, ?string $spec): array
    {
        $files = [];
        foreach ((array) json_decode((string) $spec, true) as $item) {
            try {
                if (($item['type'] ?? '') === 'ticket' && ($doc = EventDocumentsService::ticket($db, (int) $item['registration_id']))) {
                    $files[] = ['name' => $doc['filename'], 'data' => $doc['pdf'], 'mime' => 'application/pdf'];
                } elseif (($item['type'] ?? '') === 'receipt' && ($doc = EventDocumentsService::receipt($db, (int) $item['payment_id']))) {
                    $files[] = ['name' => $doc['filename'], 'data' => $doc['pdf'], 'mime' => 'application/pdf'];
                } elseif (($item['type'] ?? '') === 'ics') {
                    $ev = $db->prepare('SELECT * FROM events WHERE id = :id');
                    $ev->execute(['id' => (int) $item['event_id']]);
                    if ($event = $ev->fetch()) {
                        $files[] = ['name' => 'evora-event-' . (int) $event['id'] . '.ics', 'data' => ParticipantToolsService::ics($event), 'mime' => 'text/calendar'];
                    }
                }
            } catch (Throwable $e) {
                // an attachment that cannot be built never blocks the email itself
                if (class_exists('Logger', false)) {
                    Logger::log(null, 'email_attachment_error', mb_substr($e->getMessage(), 0, 200));
                }
            }
        }

        return $files;
    }
}
