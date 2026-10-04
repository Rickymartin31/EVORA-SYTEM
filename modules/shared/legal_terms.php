<?php
// Public page (no login): EVORA User Agreement / Terms of Use. Not legal advice; not reviewed by legal counsel.
$pageTitle = 'User Agreement';
include EVORA_ROOT . '/modules/shared/partials/public_head.php';
?>
<article class="card-evora p-4 mx-auto" style="max-width:860px;" aria-labelledby="termsHeading">
    <h1 class="h3 fw-bold mb-1" id="termsHeading">EVORA User Agreement</h1>
    <p class="text-muted small">Version <?= e(EVORA_LEGAL_VERSION) ?> · Effective October 4, 2026 · Applies to the EVORA event management system (a capstone project).</p>
    <p>By creating an account or using EVORA you agree to these terms. Please also read the <a href="<?= url('privacy-policy') ?>">Privacy Policy</a>.</p>

    <h2 class="h5 fw-semibold mt-4">1. What EVORA is</h2>
    <p>EVORA is an event management and registration system. Organizers create and run events; participants register for them; staff help with check-in and event operations; administrators verify organizers, approve events and monitor the system. EVORA is not a bank, payment processor or money transmitter.</p>

    <h2 class="h5 fw-semibold mt-4">2. Accounts</h2>
    <ul>
        <li>Give accurate information and keep your password private. You are responsible for activity on your account.</li>
        <li>Use an email address you can receive mail at. New accounts must open the verification link EVORA emails before they can log in. EVORA also sends notifications and reminders there (you can turn them off) and uses it for password resets.</li>
        <li>Administrator accounts confirm each sign-in with a one-time code sent to their email.</li>
        <li>Organizer accounts are reviewed by an administrator before they can publish events.</li>
        <li>Accounts may be deactivated for misuse, false information or security reasons.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">3. Event registration</h2>
    <ul>
        <li>Each event's details, rules, requirements and capacity are set by its organizer.</li>
        <li>A registration for a paid event is confirmed only after the payment is confirmed with PayPal. Your QR code is issued after confirmation and is used for check-in.</li>
        <li>QR codes and e-tickets are personal. They stop working if the registration is cancelled or refunded.</li>
        <li>Registration closes on the event's registration deadline and is never possible after the event date has passed.</li>
        <li><strong>Cancelling:</strong> you may cancel your own registration from My Registrations until the day before the event (team registrations are changed through the organizer). A cancelled registration cannot be restored.</li>
        <li><strong>Waitlist:</strong> when an event or category is full you may join its waitlist. If a slot opens, it is held for the next person in line for 24 hours (never past the registration deadline) and they are notified; an unused slot moves on to the next person. Joining a waitlist does not guarantee a slot.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">4. Payments through PayPal</h2>
    <ul>
        <li>For paid events, you pay <strong>directly to the organizer's PayPal account</strong>. PayPal processes the transaction, and PayPal's own user agreement applies to it.</li>
        <li>EVORA starts the payment, then confirms it with PayPal on its server and records the transaction reference (order ID, transaction ID, amount, status and date) to confirm your registration.</li>
        <li><strong>EVORA does not hold organizer funds and does not process payouts.</strong> The money goes to the organizer's PayPal account; PayPal's fees are deducted by PayPal from what the organizer receives.</li>
        <li>If a payment is cancelled or declined, you are not registered as paid and can try again.</li>
        <li>Events created before PayPal direct payment may use EVORA's previous payment method; those payments follow the terms shown when they were made.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">5. Organizers</h2>
    <ul>
        <li>Organizers connect their own PayPal Business account and are responsible for keeping it able to receive payments.</li>
        <li>Organizers are responsible for their events, for honoring paid registrations, and for refunds of payments made directly to their PayPal account.</li>
        <li>Organizers see participant information only for their own events and must use it only to run those events.</li>
    </ul>

    <h2 class="h5 fw-semibold mt-4">6. Refunds</h2>
    <p>Because PayPal payments go directly to the organizer, refunds of those payments are made from the <strong>organizer's PayPal account</strong>. When an event is cancelled — or you cancel a paid registration — the payment is listed for a refund from the organizer, who refunds it from PayPal and records it in EVORA with its PayPal refund transaction ID. You are asked to confirm whether you received it. You are notified and can see the refund status in Payments &amp; Receipts. Administrators can see which refunds are still outstanding. EVORA records the outcome but does not itself hold or return the money.</p>

    <h2 class="h5 fw-semibold mt-4">7. Acceptable use</h2>
    <p>Do not misuse EVORA: no false registrations, fake payment confirmations, attempts to access other users' data, or interference with the system's security.</p>

    <h2 class="h5 fw-semibold mt-4">8. Limitations</h2>
    <p>EVORA records what happens in the system; its records do not by themselves decide disputes between participants and organizers. EVORA depends on PayPal and other services and may be unavailable at times. EVORA is provided as a capstone system without guarantees of uninterrupted operation.</p>

    <h2 class="h5 fw-semibold mt-4">9. Changes</h2>
    <p>These terms may be updated. The version and effective date at the top of this page show which version applies.</p>

    <h2 class="h5 fw-semibold mt-4">10. Contact</h2>
    <?php include EVORA_ROOT . '/modules/shared/partials/legal_contact.php'; ?>

    <p class="small text-muted mt-4 mb-0">These terms describe how the EVORA capstone system works. They are not legal advice and have not been reviewed by legal counsel.</p>
</article>
<?php include EVORA_ROOT . '/modules/shared/partials/public_foot.php'; ?>
