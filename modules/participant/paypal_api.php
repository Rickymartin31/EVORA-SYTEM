<?php
// JSON endpoint for the PayPal button (participant only, POST + CSRF).
//   action=create  -> EVORA creates the order (server-side amount + payee)
//   action=capture -> EVORA captures and verifies with PayPal
//   action=cancel  -> buyer closed PayPal: nothing approved
header('Content-Type: application/json');
header('Cache-Control: no-store');

$respond = function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};
if (!Auth::check() || Auth::role() !== 'participant') {
    $respond(403, ['ok' => false, 'message' => 'Please log in as a participant.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    $respond(419, ['ok' => false, 'message' => 'Your session expired. Please refresh the page.']);
}

$db = Database::connection();
$userId = (int) Auth::id();
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$orderId = (string) ($_POST['order_id'] ?? '');
if ($orderId !== '' && !preg_match('/^[A-Z0-9]{8,40}$/', $orderId)) {
    $respond(422, ['ok' => false, 'message' => 'Invalid PayPal order.']);
}

try {
    switch ($_POST['action'] ?? '') {
        case 'create':
            $respond(200, ['ok' => true, 'order_id' => PayPalPaymentService::startCheckout($db, $registrationId, $userId)]);
        case 'capture':
            $result = PayPalPaymentService::capture($db, $orderId, $registrationId, $userId);
            $respond(200, ['ok' => $result['state'] !== 'failed', 'state' => $result['state'], 'message' => $result['message']]);
        case 'cancel':
            PayPalPaymentService::cancel($db, $orderId, $registrationId, $userId);
            $respond(200, ['ok' => true, 'state' => 'cancelled']);
        default:
            $respond(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
} catch (DomainException $e) {
    $respond(422, ['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    Logger::log($userId, 'paypal_api_error', mb_substr($e->getMessage(), 0, 250));
    $respond(500, ['ok' => false, 'message' => 'Something went wrong. You were not charged twice — please check My Registrations.']);
}
