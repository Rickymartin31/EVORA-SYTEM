<?php
/**
 * Creates organizer ledger entries for approved PayMongo payments that were
 * confirmed before the ledger existed (or whose fee was not known yet).
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe scripts\ledger_backfill.php                 dry run (default) — writes nothing
 *   C:\xampp\php\php.exe scripts\ledger_backfill.php --apply         create the missing entries
 *   C:\xampp\php\php.exe scripts\ledger_backfill.php --resolve-fees  dry run of fee verification for fee_unknown entries
 *   C:\xampp\php\php.exe scripts\ledger_backfill.php --resolve-fees --apply
 *   add --payment=47 to limit to one payment
 *
 * Safety:
 *   - Only payment_channel = 'paymongo' AND status = 'approved'. Manual and
 *     cash walk-in payments are never selected.
 *   - PayMongo is called READ-ONLY (GET /payments/{id}) to get the real fee
 *     and amount. A fee is never invented: if it can't be verified the entry
 *     is recorded with hold_reason = fee_unknown and no amounts.
 *   - An amount that differs from EVORA's payment amount is held as
 *     amount_mismatch. A payment PayMongo does not report as paid is skipped.
 *   - Re-runnable: source_key 'payment:<id>' is UNIQUE, so a payment never
 *     gets a second entry. Payment fee/net columns are only filled when empty.
 *   - Each payment is written in its own transaction.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/NotificationService.php';
require_once EVORA_ROOT . '/core/PaymentGatewayService.php';
require_once EVORA_ROOT . '/core/FinanceHistory.php';
require_once EVORA_ROOT . '/core/LedgerService.php';
require_once EVORA_ROOT . '/core/EventStatusService.php';
require_once EVORA_ROOT . '/core/PayoutService.php';
require_once EVORA_ROOT . '/core/EventRegistrationService.php';
require_once EVORA_ROOT . '/core/PayoutRequestService.php';
require_once EVORA_ROOT . '/core/helpers.php';

$options = getopt('', ['apply', 'resolve-fees', 'payment::']);
$apply = isset($options['apply']);
$resolveFees = isset($options['resolve-fees']);
$onlyPayment = isset($options['payment']) ? (int) $options['payment'] : 0;

$db = Database::connection();
echo ($apply ? 'APPLY' : 'DRY RUN — nothing will be written') . ($resolveFees ? ' — resolving unknown fees' : ' — creating missing ledger entries') . PHP_EOL . PHP_EOL;

/** Read-only PayMongo lookup. Returns [summary|null, note]. */
function lookupPayMongo(?string $gatewayPaymentId): array
{
    if (!$gatewayPaymentId) {
        return [null, 'no PayMongo payment id stored'];
    }
    if (!PAYMONGO_ENABLED) {
        return [null, 'PayMongo not configured'];
    }
    try {
        return [PaymentGatewayService::retrievePayment($gatewayPaymentId), 'PayMongo GET ok'];
    } catch (RuntimeException $e) {
        return [null, 'PayMongo lookup failed: ' . $e->getMessage()];
    }
}

if ($resolveFees) {
    $sql = 'SELECT l.id AS entry_id, l.gross_amount, l.payment_id, p.gateway_payment_id
            FROM organizer_ledger_entries l JOIN payments p ON p.id = l.payment_id
            WHERE l.entry_type = "payment" AND l.hold_reason = "fee_unknown" AND l.gateway_fee IS NULL AND l.settlement_id IS NULL AND l.payout_request_id IS NULL'
        . ($onlyPayment ? ' AND l.payment_id = ' . $onlyPayment : '') . ' ORDER BY l.id';
    $rows = $db->query($sql)->fetchAll();
    $resolved = 0;
    foreach ($rows as $row) {
        [$pm, $note] = lookupPayMongo($row['gateway_payment_id']);
        $canResolve = $pm && $pm['status'] === 'paid' && $pm['fee'] !== null;
        printf("Entry #%d (payment #%d, gross %s): %s%s\n", $row['entry_id'], $row['payment_id'], $row['gross_amount'], $note,
            $canResolve ? ' — fee ' . number_format($pm['fee'], 2) . ', net ' . number_format($pm['net_amount'] ?? 0, 2) : ' — still unknown');
        if ($apply && $canResolve) {
            $db->beginTransaction();
            try {
                $gateway = ['fee' => $pm['fee'], 'net_amount' => $pm['net_amount'], 'amount' => $pm['amount']];
                if (LedgerService::resolveFee($db, (int) $row['entry_id'], $gateway, null)) {
                    $db->prepare('UPDATE payments SET gateway_fee = :fee, net_amount = :net WHERE id = :id AND gateway_fee IS NULL')
                        ->execute(['fee' => $pm['fee'], 'net' => $pm['net_amount'], 'id' => $row['payment_id']]);
                    $resolved++;
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                echo '  ERROR: ' . $e->getMessage() . PHP_EOL;
            }
        }
    }
    $summary = sprintf('%d fee-unknown entr%s checked, %d resolved.', count($rows), count($rows) === 1 ? 'y' : 'ies', $resolved);
    echo PHP_EOL . $summary . PHP_EOL;
    if ($apply && $resolved > 0) {
        Logger::log(null, 'ledger_fees_resolved', $summary);
    }
    exit;
}

$sql = 'SELECT p.*, r.event_id, e.name AS event_name, e.organizer_id
        FROM payments p
        JOIN registrations r ON r.id = p.registration_id
        JOIN events e ON e.id = r.event_id
        LEFT JOIN organizer_ledger_entries l ON l.source_key = CONCAT("payment:", p.id)
        WHERE p.payment_channel = "paymongo" AND p.status = "approved" AND l.id IS NULL'
    . ($onlyPayment ? ' AND p.id = ' . $onlyPayment : '') . ' ORDER BY p.id';
$payments = $db->query($sql)->fetchAll();

$excluded = $db->query('SELECT payment_channel, COUNT(*) c FROM payments WHERE status = "approved" AND payment_channel <> "paymongo" GROUP BY payment_channel')->fetchAll();
foreach ($excluded as $row) {
    echo 'Excluded by rule: ' . $row['c'] . ' approved ' . $row['payment_channel'] . ' payment(s) — collected directly by the organizer, never in the ledger.' . PHP_EOL;
}
echo count($payments) . ' approved PayMongo payment(s) without a ledger entry:' . PHP_EOL . PHP_EOL;
printf("%-6s %-5s %-6s %-4s %10s %10s %8s %9s %9s  %-16s %s\n", 'Pay#', 'Reg#', 'Event', 'Org', 'Gross', 'PM amount', 'Fee', 'Net', 'Earnings', 'Hold', 'Source');

$created = 0;
$skipped = 0;
foreach ($payments as $payment) {
    [$pm, $note] = lookupPayMongo($payment['gateway_payment_id']);

    if ($pm && $pm['status'] !== 'paid') {
        printf("%-6d SKIPPED — PayMongo reports status '%s' for %s. Not recorded; investigate.\n", $payment['id'], $pm['status'], $payment['gateway_payment_id']);
        $skipped++;
        continue;
    }

    if ($pm && $pm['fee'] !== null) {
        $gateway = ['payment_id' => $pm['payment_id'], 'fee' => $pm['fee'], 'net_amount' => $pm['net_amount'], 'amount' => $pm['amount']];
        $source = $note;
    } elseif ($payment['gateway_fee'] !== null) {
        $gateway = ['fee' => $payment['gateway_fee'], 'net_amount' => $payment['net_amount'], 'amount' => $pm['amount'] ?? null];
        $source = 'stored fee (' . $note . ')';
    } else {
        $gateway = ['amount' => $pm['amount'] ?? null];
        $source = $note . ' — fee not verifiable';
    }
    $amounts = LedgerService::computeAmounts($payment['amount'], $gateway);

    printf("%-6d %-5d %-6d %-4d %10s %10s %8s %9s %9s  %-16s %s\n",
        $payment['id'], $payment['registration_id'], $payment['event_id'], $payment['organizer_id'], $amounts['gross'],
        isset($gateway['amount']) && $gateway['amount'] !== null ? number_format($gateway['amount'], 2, '.', '') : '?',
        $amounts['fee'] ?? '-', $amounts['net'] ?? '-', $amounts['earnings'] ?? '-', $amounts['hold_reason'] ?? '-', $source);

    if (!$apply) {
        continue;
    }

    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $payment['id']]);
        $locked = $lock->fetch();
        if (!$locked || $locked['status'] !== 'approved' || $locked['payment_channel'] !== 'paymongo') {
            throw new RuntimeException('payment changed since selection');
        }
        if (isset($gateway['fee']) && $locked['gateway_fee'] === null) {
            $db->prepare('UPDATE payments SET gateway_fee = :fee, net_amount = :net WHERE id = :id AND gateway_fee IS NULL')
                ->execute(['fee' => $amounts['fee'], 'net' => $amounts['net'], 'id' => $payment['id']]);
        }
        $before = (int) $db->query('SELECT COUNT(*) FROM organizer_ledger_entries')->fetchColumn();
        LedgerService::recordPayment($db, $locked, $gateway, null, 'backfill');
        $after = (int) $db->query('SELECT COUNT(*) FROM organizer_ledger_entries')->fetchColumn();
        $db->commit();
        $created += $after - $before;
    } catch (Throwable $e) {
        $db->rollBack();
        echo '  ERROR for payment #' . $payment['id'] . ': ' . $e->getMessage() . PHP_EOL;
    }
}

$summary = sprintf('%d payment(s) listed, %d ledger entr%s created, %d skipped.%s', count($payments), $created, $created === 1 ? 'y' : 'ies', $skipped,
    $apply ? '' : ' (dry run — re-run with --apply to write)');
echo PHP_EOL . $summary . PHP_EOL;
if ($apply && $created > 0) {
    Logger::log(null, 'ledger_backfill', $summary);
}
