<?php

/**
 * Organizer payout accounts (where EVORA sends the organizer's earnings).
 *
 * An organizer can keep several payout accounts (GCash, Maya, bank) as
 * backups. Each one is verified separately by an admin; one verified account
 * is the PRIMARY (default) destination. Rows are never edited or deleted:
 * "removing" an account makes it inactive, so every payout keeps pointing at
 * the exact account it was sent to.
 *
 * Payouts themselves are organizer payout requests (PayoutRequestService):
 * EVORA has no automated payout API, so an admin sends the money through
 * GCash, Maya or the bank OUTSIDE EVORA and records the transfer reference.
 */
class PayoutService
{
    public const ACCOUNT_BADGES = [
        'pending' => 'warning',
        'verified' => 'success',
        'rejected' => 'danger',
        'inactive' => 'secondary',
    ];

    public const ACCOUNT_LABELS = [
        'pending' => 'Pending Verification',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'inactive' => 'Removed',
    ];

    public const METHOD_LABELS = [
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'bank' => 'Bank Transfer',
    ];

    /** E-wallets are identified by a mobile number; banks by an account number. */
    public const WALLET_METHODS = ['gcash', 'maya'];

    /** Normal display everywhere: only the last 4 digits. Full details are admin-only (PayoutRequestService::revealDetails). */
    public static function mask(string $accountNumber): string
    {
        $digits = preg_replace('/\D/', '', $accountNumber);

        return '•••• ' . substr($digits, -4);
    }

    public static function methodLabel(?string $method): string
    {
        return self::METHOD_LABELS[$method] ?? strtoupper((string) $method);
    }

    /** Transfer reference as shown to organizers: last 4 characters only. */
    public static function maskReference(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '—';
        }

        return '•••• ' . substr($reference, -4);
    }

    public static function accountBadge(string $status): string
    {
        return '<span class="badge bg-' . (self::ACCOUNT_BADGES[$status] ?? 'secondary') . '">' . e(self::ACCOUNT_LABELS[$status] ?? ucfirst($status)) . '</span>';
    }

    /** "GCash •••• 8661" / "Bank Transfer (BDO) •••• 7788" */
    public static function describe(array $account): string
    {
        return self::methodLabel($account['method']) . (!empty($account['bank_name']) ? ' (' . $account['bank_name'] . ')' : '') . ' ' . self::mask($account['account_number']);
    }

    // ---------------------------------------------------------------------
    // Reading (always scoped to one organizer)
    // ---------------------------------------------------------------------

    /** All of an organizer's accounts: primary first, then verified, pending, rejected, removed. */
    public static function accounts(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare(
            'SELECT a.*, u.full_name AS reviewed_by_name,
                    (SELECT GROUP_CONCAT(r.request_no ORDER BY r.id SEPARATOR ", ") FROM organizer_payout_requests r
                     WHERE r.payout_account_id = a.id) AS used_for
             FROM organizer_payout_accounts a LEFT JOIN users u ON u.id = a.reviewed_by
             WHERE a.organizer_id = :oid
             ORDER BY a.is_primary DESC, FIELD(a.status, "verified", "pending", "rejected", "inactive"), a.id DESC'
        );
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetchAll();
    }

    /** Verified accounts only — the only possible payout destinations. Primary first. */
    public static function verifiedAccounts(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE organizer_id = :oid AND status = "verified" ORDER BY is_primary DESC, id');
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetchAll();
    }

    public static function primaryAccount(PDO $db, int $organizerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE organizer_id = :oid AND is_primary = 1 AND status = "verified"');
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The account to show as "the" payout account: the primary, otherwise the
     * newest pending/verified one (null when the organizer has none active).
     */
    public static function currentAccount(PDO $db, int $organizerId): ?array
    {
        $stmt = $db->prepare(
            'SELECT a.*, u.full_name AS reviewed_by_name FROM organizer_payout_accounts a
             LEFT JOIN users u ON u.id = a.reviewed_by
             WHERE a.organizer_id = :oid AND a.status IN ("pending", "verified")
             ORDER BY a.is_primary DESC, a.status = "verified" DESC, a.id DESC LIMIT 1'
        );
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetch() ?: null;
    }

    /** One account, only if it belongs to $organizerId (null otherwise). */
    public static function findForOrganizer(PDO $db, int $accountId, int $organizerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE id = :id AND organizer_id = :oid');
        $stmt->execute(['id' => $accountId, 'oid' => $organizerId]);

        return $stmt->fetch() ?: null;
    }

    // ---------------------------------------------------------------------
    // Organizer actions
    // ---------------------------------------------------------------------

    /** Validates and normalizes organizer input. Returns [data, errors]. */
    public static function validateAccountInput(array $input): array
    {
        $method = $input['method'] ?? '';
        $name = trim($input['account_name'] ?? '');
        $number = preg_replace('/[\s-]/', '', $input['account_number'] ?? '');
        $bank = trim(preg_replace('/\s+/', ' ', $input['bank_name'] ?? ''));
        $errors = [];

        if (!isset(self::METHOD_LABELS[$method])) {
            $errors[] = 'Choose GCash, Maya or Bank Transfer.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors[] = 'Enter the account name exactly as registered (2–150 characters).';
        }
        if (in_array($method, self::WALLET_METHODS, true) && !preg_match('/^09\d{9}$/', $number)) {
            $errors[] = self::METHOD_LABELS[$method] . ' mobile number must be 11 digits starting with 09.';
        }
        if ($method === 'bank') {
            if (!preg_match('/^\d{6,30}$/', $number)) {
                $errors[] = 'Bank account number must be 6–30 digits.';
            }
            if (mb_strlen($bank) < 2 || mb_strlen($bank) > 100) {
                $errors[] = 'Enter the bank name.';
            }
        }

        return [[
            'method' => $method,
            'account_name' => $name,
            'account_number' => $number,
            'bank_name' => $method === 'bank' ? $bank : null,
        ], $errors];
    }

    /**
     * Adds another payout account (existing accounts are kept). It starts as
     * pending and cannot be used until an admin verifies it. The same
     * method + number (+ bank) cannot be active twice (also a DB unique key).
     */
    public static function addAccount(PDO $db, int $organizerId, int $userId, array $data): int
    {
        $db->beginTransaction();
        try {
            $org = $db->prepare('SELECT status FROM organizers WHERE id = :id FOR UPDATE');
            $org->execute(['id' => $organizerId]);
            if ($org->fetchColumn() !== 'approved') {
                throw new DomainException('Only approved organizers can add payout accounts.');
            }

            $dup = $db->prepare(
                'SELECT id FROM organizer_payout_accounts WHERE organizer_id = :oid AND method = :method AND account_number = :number
                   AND LOWER(COALESCE(bank_name, "")) = LOWER(:bank) AND status IN ("pending", "verified")'
            );
            $dup->execute(['oid' => $organizerId, 'method' => $data['method'], 'number' => $data['account_number'], 'bank' => (string) $data['bank_name']]);
            if ($dup->fetchColumn()) {
                throw new DomainException('You already have this ' . self::methodLabel($data['method']) . ' account (' . self::mask($data['account_number']) . ').');
            }

            $db->prepare(
                'INSERT INTO organizer_payout_accounts (organizer_id, method, account_name, account_number, bank_name, status, submitted_by)
                 VALUES (:oid, :method, :name, :number, :bank, "pending", :by)'
            )->execute([
                'oid' => $organizerId, 'method' => $data['method'], 'name' => $data['account_name'],
                'number' => $data['account_number'], 'bank' => $data['bank_name'], 'by' => $userId,
            ]);
            $accountId = (int) $db->lastInsertId();
            FinanceHistory::record($db, 'payout_account', $accountId, null, 'pending', $userId,
                self::describe($data) . ' added, waiting for verification');
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() === '23000') {
                throw new DomainException('You already have this payout account.');
            }
            throw $e;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($userId, 'payout_account_added', 'Organizer #' . $organizerId . ' added payout account #' . $accountId . ' (' . self::describe($data) . ')');
        PayoutRequestService::notifyAdmins($db, 'Payout Account To Verify', 'An organizer added a payout account (' . self::methodLabel($data['method']) . ') for verification.', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_account', 'reference_id' => $accountId]);

        return $accountId;
    }

    /** Makes a VERIFIED account the organizer's primary payout account. */
    public static function setPrimary(PDO $db, int $organizerId, int $userId, int $accountId): void
    {
        $db->beginTransaction();
        try {
            $all = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE organizer_id = :oid FOR UPDATE');
            $all->execute(['oid' => $organizerId]);
            $target = null;
            $previous = null;
            foreach ($all->fetchAll() as $row) {
                if ((int) $row['id'] === $accountId) {
                    $target = $row;
                }
                if ((int) $row['is_primary'] === 1) {
                    $previous = $row;
                }
            }
            if (!$target) {
                throw new DomainException('Payout account not found.');
            }
            if ($target['status'] !== 'verified') {
                throw new DomainException('Only a verified account can be your primary payout account. This one is ' . strtolower(self::ACCOUNT_LABELS[$target['status']]) . '.');
            }
            if ((int) $target['is_primary'] === 1) {
                $db->commit();

                return;
            }
            $db->prepare('UPDATE organizer_payout_accounts SET is_primary = 0 WHERE organizer_id = :oid AND is_primary = 1')->execute(['oid' => $organizerId]);
            $db->prepare('UPDATE organizer_payout_accounts SET is_primary = 1 WHERE id = :id')->execute(['id' => $accountId]);
            FinanceHistory::record($db, 'payout_account', $accountId, 'verified', 'primary', $userId,
                'Set as primary' . ($previous ? ' (was ' . self::describe($previous) . ')' : ''));
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($userId, 'payout_account_primary', 'Organizer #' . $organizerId . ' set payout account #' . $accountId . ' (' . self::describe($target) . ') as primary');
    }

    /**
     * Removes (deactivates) an account; the row stays for history.
     * Not allowed while an approved/processing payout is being sent to it,
     * and a primary account can only be removed after another verified
     * account is made primary (when one exists).
     */
    public static function removeAccount(PDO $db, int $organizerId, int $userId, int $accountId): void
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE id = :id AND organizer_id = :oid FOR UPDATE');
            $stmt->execute(['id' => $accountId, 'oid' => $organizerId]);
            $account = $stmt->fetch();
            if (!$account) {
                throw new DomainException('Payout account not found.');
            }
            if (!in_array($account['status'], ['pending', 'verified'], true)) {
                throw new DomainException('This account is already ' . strtolower(self::ACCOUNT_LABELS[$account['status']]) . '.');
            }
            $inUse = $db->prepare('SELECT request_no FROM organizer_payout_requests WHERE payout_account_id = :aid AND status IN ("approved", "processing") LIMIT 1');
            $inUse->execute(['aid' => $accountId]);
            if ($requestNo = $inUse->fetchColumn()) {
                throw new DomainException('Payout ' . $requestNo . ' is being sent to this account. You can remove it after that payout is completed.');
            }
            if ((int) $account['is_primary'] === 1) {
                $others = $db->prepare('SELECT COUNT(*) FROM organizer_payout_accounts WHERE organizer_id = :oid AND status = "verified" AND id <> :id');
                $others->execute(['oid' => $organizerId, 'id' => $accountId]);
                if ((int) $others->fetchColumn() > 0) {
                    throw new DomainException('This is your primary payout account. Set another verified account as primary first, then remove this one.');
                }
            }

            $db->prepare('UPDATE organizer_payout_accounts SET status = "inactive", is_primary = 0 WHERE id = :id')->execute(['id' => $accountId]);
            FinanceHistory::record($db, 'payout_account', $accountId, $account['status'], 'inactive', $userId, 'Removed by the organizer');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($userId, 'payout_account_removed', 'Organizer #' . $organizerId . ' removed payout account #' . $accountId . ' (' . self::describe($account) . ')');
    }

    // ---------------------------------------------------------------------
    // Admin verification
    // ---------------------------------------------------------------------

    /**
     * Verifies or rejects ONE pending account (reviewed_by / reviewed_at /
     * finance history record who and when). The first verified account of an
     * organizer without a primary automatically becomes the primary.
     */
    public static function reviewAccount(PDO $db, int $accountId, int $adminId, string $decision, string $remarks): void
    {
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new DomainException('Invalid decision.');
        }
        $madePrimary = false;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $accountId]);
            $account = $stmt->fetch();
            if (!$account || $account['status'] !== 'pending') {
                throw new DomainException('Only pending payout accounts can be reviewed.');
            }
            $db->prepare('UPDATE organizer_payout_accounts SET status = :status, reviewed_by = :by, reviewed_at = NOW(), review_remarks = :remarks WHERE id = :id')
                ->execute(['status' => $decision, 'by' => $adminId, 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 500) : null, 'id' => $accountId]);
            FinanceHistory::record($db, 'payout_account', $accountId, 'pending', $decision, $adminId, $remarks !== '' ? $remarks : null);

            if ($decision === 'verified') {
                $hasPrimary = $db->prepare('SELECT COUNT(*) FROM organizer_payout_accounts WHERE organizer_id = :oid AND is_primary = 1 FOR UPDATE');
                $hasPrimary->execute(['oid' => $account['organizer_id']]);
                if ((int) $hasPrimary->fetchColumn() === 0) {
                    $db->prepare('UPDATE organizer_payout_accounts SET is_primary = 1 WHERE id = :id')->execute(['id' => $accountId]);
                    FinanceHistory::record($db, 'payout_account', $accountId, 'verified', 'primary', $adminId, 'First verified account: set as primary automatically');
                    $madePrimary = true;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log($adminId, 'payout_account_' . $decision, 'Payout account #' . $accountId . ' (' . self::describe($account) . ') ' . $decision . ($remarks !== '' ? ': ' . $remarks : ''));
        PayoutRequestService::notifyOrganizer($db, (int) $account['organizer_id'], 'Payout Account ' . ucfirst($decision),
            'Your payout account ' . self::describe($account) . ' was ' . $decision . ($madePrimary ? ' and is now your primary payout account' : '') . ($remarks !== '' ? '. Remarks: ' . $remarks : '.'), ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_account', 'reference_id' => $accountId]);
    }
}
