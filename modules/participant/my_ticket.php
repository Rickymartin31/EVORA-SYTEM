<?php
// E-ticket PDF (v39; built by EventDocumentsService since v43, the same file that is emailed).
// Only the participant who owns the registration can download it, and only while the QR is valid.
Auth::requireRole('participant');
$doc = EventDocumentsService::ticket(Database::connection(), (int) ($_GET['registration_id'] ?? 0), (int) Auth::id());
if (!$doc) {
    flash('error', 'No valid ticket for this registration. A ticket is available once your registration is approved and its QR code is issued.');
    redirect('my-qr');
}
Logger::log((int) Auth::id(), 'ticket_downloaded', 'E-ticket for registration #' . $doc['registration_id']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $doc['filename'] . '"');
header('Content-Length: ' . strlen($doc['pdf']));
echo $doc['pdf'];
exit;
