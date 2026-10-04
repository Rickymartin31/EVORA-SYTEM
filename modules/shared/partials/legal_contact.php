<?php
// Contact block for the legal pages (v1.2). No personal details of any person are shown here:
// only the official EVORA contact email, if an administrator set one in System Settings.
$legalSettings = systemSettings();
$legalEmail = filter_var((string) ($legalSettings['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
?>
<ul class="mb-0">
    <li><strong>Your account or your personal data</strong> (questions, corrections, a copy of your data, or deletion requests): contact the EVORA administrator<?= $legalEmail ? ' at <a href="mailto:' . e($legalEmail) . '">' . e($legalEmail) . '</a>' : '' ?>.</li>
    <li><strong>A specific event</strong> (its schedule, rules, your registration or a refund): contact the event's organizer — their contact details are on the event page.</li>
    <li><strong>A PayPal transaction itself</strong>: contact PayPal through your PayPal account.</li>
</ul>
