<?php
// Public page (no login): EVORA Privacy Policy. Plain information for users and for PayPal's
// "Log in with PayPal" consent screen. Not legal advice; not reviewed by legal counsel.
$pageTitle = 'Privacy Policy';
include EVORA_ROOT . '/modules/shared/partials/public_head.php';
?>
<article class="card-evora p-4 mx-auto" style="max-width:860px;" aria-labelledby="privacyHeading">
    <h1 class="h3 fw-bold mb-1" id="privacyHeading">EVORA Privacy Policy</h1>
    <p class="text-muted small">Version <?= e(EVORA_LEGAL_VERSION) ?> · Effective October 4, 2026 · Applies to the EVORA event management system (a capstone project).</p>
    <p>This page explains what information EVORA collects, why, and how it is used when you create an account, register for events, and pay for paid events through PayPal.</p>

    <h2 class="h5 fw-semibold mt-4">1. Information EVORA collects</h2>
    <ul>
        <li><strong>Account information:</strong> name, email address, phone number (if given), role (participant, organizer, staff or administrator) and your password stored only as a one-way hash.</li>
        <li><strong>Registration information:</strong> the events you register for, your registration status, and details the event asks for (for example a race category, organization, or requirement files you upload).</li>
        <li><strong>Attendance information:</strong> QR check-ins, attendance records, items released to you, and certificates.</li>
        <li><strong>Payment reference information:</strong> for paid events, the PayPal order ID, PayPal transaction (capture) ID, amount, currency, payment status and payment date. EVORA does <em>not</em> receive or store your card number, bank details or PayPal password.</li>
        <li><strong>Organizer PayPal link:</strong> when an organizer connects PayPal, EVORA stores only the PayPal account ID returned by PayPal, a masked email and whether PayPal reports the account as verified — never the organizer's PayPal password or keys.</li>
        <li><strong>Email records:</strong> the notification emails EVORA sends you (recipient, subject, message and whether it was delivered).</li>
        <li><strong>One-time codes and links:</strong> email-verification links (valid 24 hours), password-reset links (valid 60 minutes) and administrator login codes (valid 10 minutes). Only a one-way hash of each is stored, and each works once.</li>
        <li><strong>Your activity in EVORA:</strong> events you save, waitlists you join (and your place in line), registrations you cancel (with the reason you give), reminders already sent to you, and your race results, rankings and certificates.</li>
        <li><strong>Technical records:</strong> activity logs (such as log-ins and important actions) and error logs (the error message, the page and your user number — never what you typed, your password or payment details) used for security and troubleshooting.</li>
        <li><strong>Backups:</strong> a copy of the EVORA database is made automatically every day so data can be restored after a failure.</li>
        <li><strong>Your consent:</strong> the date and version of this policy and the User Agreement you accepted when you created your account.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">2. How payments work</h2>
    <p>For paid events, you pay <strong>directly to the event organizer's PayPal account</strong>. PayPal processes the payment; its own privacy statement and terms apply to what you enter on PayPal's pages. EVORA does not hold organizer funds and does not process payouts to organizers. After PayPal confirms the payment, EVORA records the transaction reference so it can confirm your registration, issue your QR code and show the payment in your history.</p>

    <h2 class="h5 fw-semibold mt-4">3. Why EVORA uses this information</h2>
    <ul>
        <li>to create and secure your account;</li>
        <li>to register you for events and confirm paid registrations with PayPal;</li>
        <li>to issue and verify QR codes, record attendance and issue certificates;</li>
        <li>to send notifications about your registrations, payments and events — in EVORA and, unless you turn it off, by email — including a reminder the day before your event and before a saved event's registration closes;</li>
        <li>to confirm your email address, let you reset a forgotten password, and confirm administrator sign-ins with an emailed code;</li>
        <li>to run waitlists: when a slot opens, offer it to the next person in line;</li>
        <li>to show your event history, results and e-tickets;</li>
        <li>to let organizers manage their own events and see payment records for those events;</li>
        <li>to investigate problems, prevent duplicate or fake payment confirmations, and keep audit records.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">4. Who can see your information</h2>
    <ul>
        <li><strong>The organizer of an event you join</strong> sees your registration, attendance, results and payment reference for that event only — and, if you cancel a paid registration, the refund they owe you.</li>
        <li><strong>Staff assigned to that event</strong> see what they need for check-in and event operations.</li>
        <li><strong>EVORA administrators</strong> can see records needed to run and monitor the system.</li>
        <li><strong>PayPal</strong> receives the order details EVORA sends to start a payment (amount, currency, a reference to your registration).</li>
        <li><strong>The email provider</strong> EVORA uses to send emails receives your email address and the message being sent.</li>
        <li><strong>Google Calendar</strong> receives an event's name, date and venue only if you click "Add to Google Calendar".</li>
    </ul>
    <p>EVORA does not sell personal information.</p>

    <h2 class="h5 fw-semibold mt-4">5. Keeping information</h2>
    <p>Registration, attendance and payment reference records are kept so that registrations, check-ins, refunds and disputes can be verified later. Some financial and audit records are designed so they cannot be edited or deleted through the system. Automatic daily backups are kept for 14 days; other records have no fixed retention period yet.</p>

    <h2 class="h5 fw-semibold mt-4">6. Security</h2>
    <p>Passwords are stored only as one-way hashes. Pages and actions are limited by role, forms are protected against cross-site request forgery, and payment confirmations are checked with PayPal on EVORA's server rather than trusted from the browser. Accounts lock for 15 minutes after 5 failed sign-ins, new accounts must verify their email, and administrators confirm each sign-in with a code sent to their email. No system is completely secure; please keep your password private.</p>

    <h2 class="h5 fw-semibold mt-4">7. Your rights and choices</h2>
    <p>EVORA handles personal data following the principles of transparency, legitimate purpose and proportionality of the Philippine <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>. As a data subject you have the right to:</p>
    <ul>
        <li><strong>be informed</strong> of how your data is collected and used (this page);</li>
        <li><strong>access</strong> your data — your profile, registrations, QR codes, certificates, results and payments are shown in your account (My Registrations, Payments &amp; Receipts, My Results, Activity Log);</li>
        <li><strong>correct</strong> inaccurate data — edit your profile, or ask the administrator;</li>
        <li><strong>object</strong> to processing and <strong>withdraw consent</strong> — for example, turn off email notifications in Notification Preferences;</li>
        <li><strong>request deletion or blocking</strong> of data that is no longer needed — ask the administrator; records required to verify payments, refunds and attendance may have to be kept;</li>
        <li><strong>data portability</strong> — ask the administrator for a copy of your data;</li>
        <li><strong>file a complaint</strong> with the National Privacy Commission (privacy.gov.ph) if you believe your data was mishandled.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">8. Contact</h2>
    <?php include EVORA_ROOT . '/modules/shared/partials/legal_contact.php'; ?>

    <p class="small text-muted mt-4 mb-0">This policy describes how the EVORA capstone system works. It is not legal advice and has not been reviewed by legal counsel. See also the <a href="<?= url('user-agreement') ?>">User Agreement</a>.</p>
</article>
<?php include EVORA_ROOT . '/modules/shared/partials/public_foot.php'; ?>
