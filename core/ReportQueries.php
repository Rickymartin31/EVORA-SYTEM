<?php

/**
 * Builds tabular {columns, rows} data for organizer/admin reports.
 * Row cells are plain scalars so the same data can feed HTML, PDF, and Excel output.
 */
class ReportQueries
{
    public static function run(PDO $db, string $type, int $eventId, array $filters = []): array
    {
        return match ($type) {
            'event_summary' => self::eventSummary($db, $eventId),
            'participant_list' => self::participantList($db, $eventId, $filters),
            'payment_summary' => self::paymentSummary($db, $eventId, $filters),
            'attendance_report' => self::attendanceReport($db, $eventId, $filters),
            'staff_activity' => self::staffActivity($db, $eventId),
            'completion_report' => self::completionReport($db, $eventId),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private static function eventSummary(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT e.name, c.name AS category, e.event_date, e.venue, e.fee, e.max_participants, e.status,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id) AS total_reg,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id AND status = "approved") AS approved,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id AND status = "pending") AS pending,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id AND status = "rejected") AS rejected,
                (SELECT COUNT(*) FROM attendance WHERE event_id = e.id) AS attendance_count
             FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $e = $stmt->fetch();
        if (!$e) {
            return ['columns' => [], 'rows' => []];
        }

        return [
            'columns' => ['Field', 'Value'],
            'rows' => [
                ['Event Name', $e['name']],
                ['Category', $e['category']],
                ['Date', date('F d, Y', strtotime($e['event_date']))],
                ['Venue', $e['venue']],
                ['Fee', $e['fee'] > 0 ? number_format($e['fee'], 2) : 'Free'],
                ['Max Participants', $e['max_participants']],
                ['Status', ucfirst($e['status'])],
                ['Total Registrations', $e['total_reg']],
                ['Approved', $e['approved']],
                ['Pending', $e['pending']],
                ['Rejected', $e['rejected']],
                ['Attendance Recorded', $e['attendance_count']],
            ],
        ];
    }

    /**
     * @param array{status?: string, date_from?: string, date_to?: string} $filters
     */
    private static function participantList(PDO $db, int $eventId, array $filters = []): array
    {
        $where = ['r.event_id = :id'];
        $params = ['id' => $eventId];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where[] = 'r.registered_at BETWEEN :date_from AND :date_to';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $stmt = $db->prepare(
            'SELECT u.full_name, u.email, u.phone, r.distance_category, r.status, r.registered_at
             FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY r.registered_at'
        );
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            $r['full_name'], $r['email'], $r['phone'] ?? '—', $r['distance_category'] ?? '—',
            ucfirst($r['status']), date('M d, Y', strtotime($r['registered_at'])),
        ], $stmt->fetchAll());

        return ['columns' => ['Name', 'Email', 'Phone', 'Category', 'Status', 'Registered On'], 'rows' => $rows];
    }

    /**
     * @param array{date_from?: string, date_to?: string} $filters
     */
    private static function paymentSummary(PDO $db, int $eventId, array $filters = []): array
    {
        $where = ['r.event_id = :id'];
        $params = ['id' => $eventId];

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where[] = 'pay.submitted_at BETWEEN :date_from AND :date_to';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $stmt = $db->prepare(
            'SELECT u.full_name, pay.amount, pay.status, pay.submitted_at, pay.reviewed_at
             FROM payments pay
             JOIN registrations r ON r.id = pay.registration_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY pay.submitted_at'
        );
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            $r['full_name'], number_format($r['amount'], 2), ucfirst($r['status']),
            date('M d, Y', strtotime($r['submitted_at'])), $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : '—',
        ], $stmt->fetchAll());

        return ['columns' => ['Participant', 'Amount', 'Status', 'Submitted', 'Reviewed'], 'rows' => $rows];
    }

    /**
     * @param array{date_from?: string, date_to?: string} $filters
     */
    private static function attendanceReport(PDO $db, int $eventId, array $filters = []): array
    {
        $where = ['a.event_id = :id'];
        $params = ['id' => $eventId];

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where[] = 'a.scanned_at BETWEEN :date_from AND :date_to';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $stmt = $db->prepare(
            'SELECT u.full_name AS participant, s.full_name AS staff, a.scanned_at
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             JOIN users s ON s.id = a.staff_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY a.scanned_at'
        );
        $stmt->execute($params);
        $rows = array_map(fn($r) => [$r['participant'], $r['staff'], date('M d, Y H:i:s', strtotime($r['scanned_at']))], $stmt->fetchAll());

        return ['columns' => ['Participant', 'Checked In By', 'Date/Time'], 'rows' => $rows];
    }

    private static function staffActivity(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT u.full_name, u.email, es.id AS event_staff_id,
                (SELECT COUNT(*) FROM attendance WHERE staff_id = u.id AND event_id = :eid1) AS attendance_count,
                (SELECT COUNT(*) FROM item_claims WHERE staff_id = u.id) AS claims_count
             FROM event_staff es JOIN users u ON u.id = es.user_id
             WHERE es.event_id = :eid2'
        );
        $stmt->execute(['eid1' => $eventId, 'eid2' => $eventId]);
        $permStmt = $db->prepare('SELECT permission_key FROM staff_permissions WHERE event_staff_id = :id');

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $permStmt->execute(['id' => $r['event_staff_id']]);
            $perms = implode(', ', array_column($permStmt->fetchAll(), 'permission_key'));
            $rows[] = [$r['full_name'], $r['email'], $perms, $r['attendance_count'], $r['claims_count']];
        }

        return ['columns' => ['Staff Name', 'Email', 'Permissions', 'Check-ins Recorded', 'Items Released'], 'rows' => $rows];
    }

    /**
     * Approved payment totals split by who actually received the money:
     * PayMongo is collected by EVORA (and later paid out to the organizer);
     * PayPal goes from the participant straight to the organizer's PayPal
     * account, so EVORA never holds it; cash walk-in / manual payments were
     * received by the organizer's team. 'total' keeps its pre-PayPal meaning
     * (every channel except PayPal), so historical totals do not change.
     */
    public static function revenueSplit(PDO $db, string $dateFrom = '', string $dateTo = ''): array
    {
        $where = "status = 'approved'";
        $params = [];
        if ($dateFrom !== '' && $dateTo !== '') {
            $where .= ' AND submitted_at BETWEEN :date_from AND :date_to';
            $params = ['date_from' => $dateFrom . ' 00:00:00', 'date_to' => $dateTo . ' 23:59:59'];
        }
        $stmt = $db->prepare("SELECT payment_channel, COALESCE(SUM(amount), 0) FROM payments WHERE $where GROUP BY payment_channel");
        $stmt->execute($params);
        $byChannel = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $paypal = $byChannel['paypal'] ?? 0.0;

        return [
            'evora_collected' => $byChannel['paymongo'] ?? 0.0,
            'direct_to_organizers' => $paypal,
            'organizer_cash_manual' => ($byChannel['cash_walkin'] ?? 0.0) + ($byChannel['manual'] ?? 0.0),
            'total' => array_sum($byChannel) - $paypal,
        ];
    }

    /**
     * Payment Summary report (admin "Payment Reports"): approved payments per channel with
     * who received the money. PayPal is never counted as money collected by EVORA.
     *
     * @param array{date_from?: string, date_to?: string} $filters
     */
    public static function paymentProviderSummary(PDO $db, array $filters = []): array
    {
        $where = "status = 'approved'";
        $params = [];
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where .= ' AND submitted_at BETWEEN :date_from AND :date_to';
            $params = ['date_from' => $filters['date_from'] . ' 00:00:00', 'date_to' => $filters['date_to'] . ' 23:59:59'];
        }
        $stmt = $db->prepare("SELECT payment_channel, COUNT(*) c, COALESCE(SUM(amount), 0) total FROM payments WHERE $where GROUP BY payment_channel");
        $stmt->execute($params);
        $by = $stmt->fetchAll(PDO::FETCH_UNIQUE);
        $row = fn (string $channel, string $label, string $who) => [$label, $who, (int) ($by[$channel]['c'] ?? 0), number_format((float) ($by[$channel]['total'] ?? 0), 2)];

        return [
            'columns' => ['Payment Method', 'Who Received the Money', 'Approved Payments', 'Amount'],
            'rows' => [
                $row('paypal', 'PayPal (active)', 'Paid directly to organizers — EVORA does not collect or hold PayPal funds'),
                $row('paymongo', 'PayMongo (legacy)', 'Collected by EVORA (historical; settled through legacy payouts)'),
                $row('cash_walkin', 'Cash walk-in', 'Received on site by the organizer\'s team'),
                $row('manual', 'Manual (historical)', 'Historical manual payments'),
                ['Total collected by EVORA', 'Legacy PayMongo only', (int) ($by['paymongo']['c'] ?? 0), number_format((float) ($by['paymongo']['total'] ?? 0), 2)],
            ],
        ];
    }

    /**
     * @param array{date_from?: string, date_to?: string} $filters Optional date
     *   range — applied only to metrics with a meaningful "when" (events by
     *   event_date, registrations by registered_at, payments by submitted_at).
     *   Total Users / Organizer counts / Total Participants stay unfiltered:
     *   they describe current platform size, not activity in a window, and
     *   filtering them by date would misrepresent what the number means.
     */
    public static function platformStatistics(PDO $db, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? '';
        $dateTo = $filters['date_to'] ?? '';
        $hasDateRange = $dateFrom !== '' && $dateTo !== '';

        $eventDateWhere = $hasDateRange ? ' AND event_date BETWEEN :date_from1 AND :date_to1' : '';
        $activeEventDateWhere = $hasDateRange ? ' AND event_date BETWEEN :date_from2 AND :date_to2' : '';
        $regDateWhere = $hasDateRange ? ' AND registered_at BETWEEN :date_from3 AND :date_to3' : '';
        $paymentDateWhere = $hasDateRange ? ' AND submitted_at BETWEEN :date_from4 AND :date_to4' : '';

        $stmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM users) AS total_users,
                (SELECT COUNT(*) FROM organizers WHERE status = 'approved') AS approved_organizers,
                (SELECT COUNT(*) FROM organizers WHERE status = 'pending') AS pending_organizers,
                (SELECT COUNT(*) FROM events WHERE 1=1$eventDateWhere) AS total_events,
                (SELECT COUNT(*) FROM events WHERE status IN ('open','ongoing')$activeEventDateWhere) AS active_events,
                (SELECT COUNT(*) FROM participants) AS total_participants,
                (SELECT COUNT(*) FROM registrations WHERE status = 'approved'$regDateWhere) AS total_registrations,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved' AND payment_channel <> 'paypal'$paymentDateWhere) AS total_collected"
        );
        if ($hasDateRange) {
            $stmt->execute([
                'date_from1' => $dateFrom, 'date_to1' => $dateTo,
                'date_from2' => $dateFrom, 'date_to2' => $dateTo,
                'date_from3' => $dateFrom . ' 00:00:00', 'date_to3' => $dateTo . ' 23:59:59',
                'date_from4' => $dateFrom . ' 00:00:00', 'date_to4' => $dateTo . ' 23:59:59',
            ]);
        } else {
            $stmt->execute();
        }
        $r = $stmt->fetch();
        $split = self::revenueSplit($db, $hasDateRange ? $dateFrom : '', $hasDateRange ? $dateTo : '');

        // Labels only change when a filter is actually applied. "Total Payments
        // Collected" excludes PayPal (paid directly to organizers), so it keeps
        // its historical value; the two split rows say who received the money.
        $inRange = $hasDateRange ? ' (in date range)' : '';
        $eventLabel = $hasDateRange ? 'Total Events (in date range)' : 'Total Events';
        $activeLabel = $hasDateRange ? 'Active Events (in date range)' : 'Active Events';
        $regLabel = $hasDateRange ? 'Approved Registrations (in date range)' : 'Approved Registrations';
        $payLabel = $hasDateRange ? 'Total Payments Collected (in date range)' : 'Total Payments Collected';

        return [
            'columns' => ['Metric', 'Value'],
            'rows' => [
                ['Total Users', $r['total_users']],
                ['Approved Organizers', $r['approved_organizers']],
                ['Pending Organizers', $r['pending_organizers']],
                [$eventLabel, $r['total_events']],
                [$activeLabel, $r['active_events']],
                ['Total Participants', $r['total_participants']],
                [$regLabel, $r['total_registrations']],
                [$payLabel, number_format($r['total_collected'], 2)],
                ['Collected by EVORA (legacy PayMongo)' . $inRange, number_format($split['evora_collected'], 2)],
                ['Paid Directly to Organizers (PayPal, not collected by EVORA)' . $inRange, number_format($split['direct_to_organizers'], 2)],
            ],
        ];
    }

    /**
     * @param array{date_from?: string, date_to?: string} $filters Optional date
     *   range, applied to each organizer's event count (by event_date) and
     *   participant count (by registration's event's event_date) — same
     *   "only metrics with a meaningful when" rule as platformStatistics().
     */
    public static function organizerStatistics(PDO $db, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? '';
        $dateTo = $filters['date_to'] ?? '';
        $hasDateRange = $dateFrom !== '' && $dateTo !== '';

        $eventDateWhere = $hasDateRange ? ' AND e.event_date BETWEEN :date_from1 AND :date_to1' : '';
        $participantDateWhere = $hasDateRange ? ' AND e.event_date BETWEEN :date_from2 AND :date_to2' : '';

        $stmt = $db->prepare(
            "SELECT o.organization_name, u.email, o.status,
                (SELECT COUNT(*) FROM events e WHERE e.organizer_id = o.id$eventDateWhere) AS total_events,
                (SELECT COUNT(*) FROM registrations r JOIN events e ON e.id = r.event_id WHERE e.organizer_id = o.id AND r.status = 'approved'$participantDateWhere) AS total_participants
             FROM organizers o JOIN users u ON u.id = o.user_id
             ORDER BY total_events DESC"
        );
        if ($hasDateRange) {
            $stmt->execute(['date_from1' => $dateFrom, 'date_to1' => $dateTo, 'date_from2' => $dateFrom, 'date_to2' => $dateTo]);
        } else {
            $stmt->execute();
        }

        $eventsCol = $hasDateRange ? 'Total Events (in date range)' : 'Total Events';
        $participantsCol = $hasDateRange ? 'Total Participants (in date range)' : 'Total Participants';

        $rows = array_map(fn($r) => [
            $r['organization_name'], $r['email'], ucfirst($r['status']), $r['total_events'], $r['total_participants'],
        ], $stmt->fetchAll());

        return ['columns' => ['Organization', 'Email', 'Status', $eventsCol, $participantsCol], 'rows' => $rows];
    }

    /**
     * @param array{category_id?: int, date_from?: string, date_to?: string, status?: string} $filters
     */
    public static function eventStatistics(PDO $db, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'e.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where[] = 'e.event_date BETWEEN :date_from AND :date_to';
            $params['date_from'] = $filters['date_from'];
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = :status';
            $params['status'] = $filters['status'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare(
            "SELECT e.name, o.organization_name, c.name AS category, e.event_date, e.status,
                (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'approved') AS approved,
                (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) AS attendance
             FROM events e
             JOIN organizers o ON o.id = e.organizer_id
             JOIN event_categories c ON c.id = e.category_id
             $whereSql
             ORDER BY e.event_date DESC"
        );
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            $r['name'], $r['organization_name'], $r['category'], date('M d, Y', strtotime($r['event_date'])),
            ucfirst($r['status']), $r['approved'], $r['attendance'],
        ], $stmt->fetchAll());

        return ['columns' => ['Event', 'Organizer', 'Category', 'Date', 'Status', 'Approved', 'Attendance'], 'rows' => $rows];
    }

    /**
     * Platform-wide certificate activity for the admin's Certificate Activity
     * report — event, participant, organizer, type, status, who generated
     * it, and when. Read-only, reuses the same certificates/registrations
     * relationships every other certificate feature already relies on.
     */
    public static function certificateActivity(PDO $db): array
    {
        $stmt = $db->query(
            "SELECT e.name AS event_name, o.organization_name, u.full_name AS participant_name,
                c.certificate_type, c.certificate_number, c.status, gu.full_name AS generated_by_name, c.issued_at
             FROM certificates c
             JOIN registrations r ON r.id = c.registration_id
             JOIN events e ON e.id = r.event_id
             JOIN organizers o ON o.id = e.organizer_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             LEFT JOIN users gu ON gu.id = c.generated_by
             ORDER BY c.issued_at DESC"
        );
        $rows = array_map(fn ($r) => [
            $r['event_name'], $r['organization_name'], $r['participant_name'], $r['certificate_type'],
            $r['certificate_number'] ?? '—', ucfirst($r['status']), $r['generated_by_name'] ?? 'System (automatic)',
            $r['issued_at'] ? date('M d, Y H:i', strtotime($r['issued_at'])) : '—',
        ], $stmt->fetchAll());

        return ['columns' => ['Event', 'Organizer', 'Participant', 'Certificate Type', 'Certificate ID', 'Status', 'Generated By', 'Issued On'], 'rows' => $rows];
    }

    /**
     * Sectioned (not flat columns/rows) data for the per-event financial
     * report export — Event Info, Registration Summary, Financial Summary,
     * and a full Transaction Details table. Consumed by ReportExporter's
     * sectionedPdf()/sectionedExcel(), not the plain run() contract above.
     */
    public static function eventFinancialReportSections(PDO $db, int $eventId): array
    {
        $eventStmt = $db->prepare(
            'SELECT e.name, e.event_date, e.venue, o.organization_name
             FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id'
        );
        $eventStmt->execute(['id' => $eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return [];
        }

        $regStmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM registrations WHERE event_id = :id1) AS total_reg,
                (SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id2 AND pay.status = 'approved') AS paid,
                (SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id3 AND pay.status = 'pending') AS pending,
                (SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id4 AND pay.status = 'failed') AS failed,
                (SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id5 AND pay.status = 'cancelled') AS cancelled"
        );
        $regStmt->execute(['id1' => $eventId, 'id2' => $eventId, 'id3' => $eventId, 'id4' => $eventId, 'id5' => $eventId]);
        $reg = $regStmt->fetch();

        $revStmt = $db->prepare(
            "SELECT pay.payment_channel, COALESCE(SUM(pay.amount), 0) total
             FROM payments pay JOIN registrations r ON r.id = pay.registration_id
             WHERE r.event_id = :id AND pay.status = 'approved'
             GROUP BY pay.payment_channel"
        );
        $revStmt->execute(['id' => $eventId]);
        $revByChannel = [];
        foreach ($revStmt->fetchAll() as $row) {
            $revByChannel[$row['payment_channel']] = (float) $row['total'];
        }
        $paymongoRevenue = $revByChannel['paymongo'] ?? 0.0;
        $cashRevenue = $revByChannel['cash_walkin'] ?? 0.0;
        $manualRevenue = $revByChannel['manual'] ?? 0.0;
        $paypalRevenue = $revByChannel['paypal'] ?? 0.0; // paid directly to the organizer's PayPal account
        $totalRevenue = $paymongoRevenue + $cashRevenue + $manualRevenue + $paypalRevenue;

        $completionBase = (int) $reg['paid'] + (int) $reg['pending'] + (int) $reg['failed'] + (int) $reg['cancelled'];
        $completionPct = $completionBase > 0 ? round(((int) $reg['paid']) / $completionBase * 100, 1) : 0.0;

        $txnStmt = $db->prepare(
            "SELECT u.full_name AS participant_name, r.distance_category, pay.payment_channel, pay.amount, pay.status,
                pay.gateway_payment_id, pay.paid_at, pay.reviewed_at, rv.full_name AS processed_by_name
             FROM payments pay
             JOIN registrations r ON r.id = pay.registration_id
             JOIN participants p ON p.id = r.participant_id
             JOIN users u ON u.id = p.user_id
             LEFT JOIN users rv ON rv.id = pay.reviewed_by
             WHERE r.event_id = :id
             ORDER BY pay.submitted_at DESC"
        );
        $txnStmt->execute(['id' => $eventId]);

        $channelLabel = fn ($c) => $c === 'paymongo' ? 'PayMongo' : ($c === 'cash_walkin' ? 'Cash Walk-in' : ($c === 'paypal' ? 'PayPal' : 'Manual (historical)'));
        $txnRows = array_map(function ($t) use ($channelLabel) {
            $paymentDate = $t['paid_at'] ?? $t['reviewed_at'];

            return [
                $t['participant_name'],
                $t['distance_category'] ?? '—',
                $channelLabel($t['payment_channel']),
                number_format((float) $t['amount'], 2),
                ucfirst($t['status']),
                $t['gateway_payment_id'] ?? '—',
                $paymentDate ? date('M d, Y H:i', strtotime($paymentDate)) : '—',
                $t['processed_by_name'] ?? '—',
            ];
        }, $txnStmt->fetchAll());

        $financialRows = [
            ['PayMongo Revenue', number_format($paymongoRevenue, 2)],
            ['Cash Walk-in Revenue', number_format($cashRevenue, 2)],
        ];
        if ($manualRevenue > 0) {
            $financialRows[] = ['Manual Revenue (historical)', number_format($manualRevenue, 2)];
        }
        if ($paypalRevenue > 0) {
            $financialRows[] = ['PayPal Revenue (paid directly to your PayPal account)', number_format($paypalRevenue, 2)];
        }
        $financialRows[] = ['Total Revenue', number_format($totalRevenue, 2)];
        $financialRows[] = ['Payment Completion', $completionPct . '%'];

        return [
            [
                'title' => 'Event Information',
                'type' => 'keyvalue',
                'rows' => [
                    ['Event Name', $event['name']],
                    ['Date', date('F d, Y', strtotime($event['event_date']))],
                    ['Venue', $event['venue']],
                    ['Organizer', $event['organization_name']],
                ],
            ],
            [
                'title' => 'Registration Summary',
                'type' => 'keyvalue',
                'rows' => [
                    ['Total Registered Participants', (int) $reg['total_reg']],
                    ['Paid Participants', (int) $reg['paid']],
                    ['Pending Payments', (int) $reg['pending']],
                    ['Failed Payments', (int) $reg['failed']],
                    ['Cancelled Payments', (int) $reg['cancelled']],
                ],
            ],
            [
                'title' => 'Financial Summary',
                'type' => 'keyvalue',
                'rows' => $financialRows,
            ],
            [
                'title' => 'Transaction Details',
                'type' => 'table',
                'columns' => ['Participant', 'Category', 'Channel', 'Amount', 'Status', 'Transaction ID', 'Payment Date', 'Processed By'],
                'rows' => $txnRows,
            ],
        ];
    }

    /**
     * Aggregate-only analytics for the Event Analytics Dashboard — every
     * query here is COUNT()/SUM()/GROUP BY, nothing fetches per-participant
     * rows, so this stays fast regardless of how many people registered.
     */
    public static function eventAnalytics(PDO $db, int $eventId): array
    {
        $eventStmt = $db->prepare(
            'SELECT e.*, c.slug AS category_slug, c.name AS category_name
             FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = :id'
        );
        $eventStmt->execute(['id' => $eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return [];
        }

        // --- Feature 1: Event Performance Summary ---
        $regCountStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id');
        $regCountStmt->execute(['id' => $eventId]);
        $registeredCount = (int) $regCountStmt->fetch()['c'];

        $paidCountStmt = $db->prepare(
            'SELECT COUNT(*) c FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = :id AND p.status = "approved"'
        );
        $paidCountStmt->execute(['id' => $eventId]);
        $paidCount = (int) $paidCountStmt->fetch()['c'];

        $pendingRegStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "pending"');
        $pendingRegStmt->execute(['id' => $eventId]);
        $pendingRegCount = (int) $pendingRegStmt->fetch()['c'];

        $maxParticipants = (int) $event['max_participants'];
        $capacityUsagePct = $maxParticipants > 0 ? round($registeredCount / $maxParticipants * 100, 1) : 0.0;
        $completionRatePct = $registeredCount > 0 ? round($paidCount / $registeredCount * 100, 1) : 0.0;

        // --- Feature 2: Registration Analytics ---
        $trendStmt = $db->prepare(
            'SELECT DATE(registered_at) d, COUNT(*) c FROM registrations WHERE event_id = :id GROUP BY DATE(registered_at) ORDER BY d'
        );
        $trendStmt->execute(['id' => $eventId]);
        $registrationTrend = array_map(fn ($r) => ['date' => $r['d'], 'count' => (int) $r['c']], $trendStmt->fetchAll());

        $peakDate = null;
        $peakCount = 0;
        foreach ($registrationTrend as $point) {
            if ($point['count'] > $peakCount) {
                $peakCount = $point['count'];
                $peakDate = $point['date'];
            }
        }

        $deadlineRushPct = null;
        if (!empty($event['registration_deadline'])) {
            $rushStmt = $db->prepare(
                'SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND registered_at >= DATE_SUB(:deadline, INTERVAL 3 DAY)'
            );
            $rushStmt->execute(['id' => $eventId, 'deadline' => $event['registration_deadline']]);
            $rushCount = (int) $rushStmt->fetch()['c'];
            $deadlineRushPct = $registeredCount > 0 ? round($rushCount / $registeredCount * 100, 1) : 0.0;
        }

        // --- Feature 3: Category Analytics (fun-run only — race_categories
        // is the only table representing genuinely selectable sub-categories) ---
        $categories = [];
        $mostPopularCategory = null;
        if ($event['category_slug'] === 'fun-run') {
            $catStmt = $db->prepare(
                "SELECT rc.id, rc.name, rc.max_participants,
                    COUNT(r.id) participant_count,
                    COALESCE(SUM(CASE WHEN pay.status = 'approved' THEN pay.amount ELSE 0 END), 0) revenue
                 FROM race_categories rc
                 LEFT JOIN registrations r ON r.race_category_id = rc.id
                 LEFT JOIN payments pay ON pay.registration_id = r.id
                 WHERE rc.event_id = :id
                 GROUP BY rc.id, rc.name, rc.max_participants
                 ORDER BY participant_count DESC"
            );
            $catStmt->execute(['id' => $eventId]);
            foreach ($catStmt->fetchAll() as $row) {
                $cap = (int) $row['max_participants'];
                $categories[] = [
                    'name' => $row['name'],
                    'participant_count' => (int) $row['participant_count'],
                    'max_participants' => $cap,
                    'capacity_pct' => $cap > 0 ? round((int) $row['participant_count'] / $cap * 100, 1) : 0.0,
                    'revenue' => (float) $row['revenue'],
                ];
            }
            if (!empty($categories)) {
                $mostPopularCategory = $categories[0]['name'];
            }
        }

        // --- Feature 5: Attendance Analytics ---
        $checkedInStmt = $db->prepare('SELECT COUNT(*) c FROM attendance WHERE event_id = :id');
        $checkedInStmt->execute(['id' => $eventId]);
        $checkedInCount = (int) $checkedInStmt->fetch()['c'];

        $approvedCountStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
        $approvedCountStmt->execute(['id' => $eventId]);
        $approvedCount = (int) $approvedCountStmt->fetch()['c'];

        $noShowCount = max(0, $approvedCount - $checkedInCount);
        $attendanceRatePct = $approvedCount > 0 ? round($checkedInCount / $approvedCount * 100, 1) : 0.0;

        // Finish rate (fun-run only — race_results only has meaning for race events)
        $finishRatePct = null;
        if ($event['category_slug'] === 'fun-run') {
            $finisherStmt = $db->prepare(
                'SELECT COUNT(*) c FROM race_results rr JOIN registrations r ON r.id = rr.registration_id
                 WHERE r.event_id = :id AND r.status = "approved"'
            );
            $finisherStmt->execute(['id' => $eventId]);
            $finisherCount = (int) $finisherStmt->fetch()['c'];
            $finishRatePct = $approvedCount > 0 ? round($finisherCount / $approvedCount * 100, 1) : 0.0;
        }

        $checkinTimelineStmt = $db->prepare(
            "SELECT DATE_FORMAT(scanned_at, '%H:00') hr, COUNT(*) c FROM attendance WHERE event_id = :id GROUP BY hr ORDER BY hr"
        );
        $checkinTimelineStmt->execute(['id' => $eventId]);
        $checkinTimeline = array_map(fn ($r) => ['hour' => $r['hr'], 'count' => (int) $r['c']], $checkinTimelineStmt->fetchAll());

        // --- Revenue (same channel-aware pattern proven in Phase 1/2) ---
        $revStmt = $db->prepare(
            "SELECT payment_channel, COALESCE(SUM(amount), 0) total FROM payments pay
             JOIN registrations r ON r.id = pay.registration_id
             WHERE r.event_id = :id AND pay.status = 'approved' GROUP BY payment_channel"
        );
        $revStmt->execute(['id' => $eventId]);
        $revByChannel = [];
        foreach ($revStmt->fetchAll() as $row) {
            $revByChannel[$row['payment_channel']] = (float) $row['total'];
        }
        $totalRevenue = array_sum($revByChannel);

        return [
            'event' => $event,
            'registered_count' => $registeredCount,
            'paid_count' => $paidCount,
            'pending_reg_count' => $pendingRegCount,
            'max_participants' => $maxParticipants,
            'capacity_usage_pct' => $capacityUsagePct,
            'completion_rate_pct' => $completionRatePct,
            'registration_trend' => $registrationTrend,
            'peak_date' => $peakDate,
            'peak_count' => $peakCount,
            'deadline_rush_pct' => $deadlineRushPct,
            'categories' => $categories,
            'most_popular_category' => $mostPopularCategory,
            'checked_in_count' => $checkedInCount,
            'approved_count' => $approvedCount,
            'no_show_count' => $noShowCount,
            'attendance_rate_pct' => $attendanceRatePct,
            'finish_rate_pct' => $finishRatePct,
            'checkin_timeline' => $checkinTimeline,
            'total_revenue' => $totalRevenue,
        ];
    }

    private static function completionReport(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM registrations WHERE event_id = :eid1) AS total_reg,
                (SELECT COUNT(*) FROM registrations WHERE event_id = :eid2 AND status = "approved") AS approved,
                (SELECT COUNT(*) FROM attendance WHERE event_id = :eid3) AS attended,
                (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :eid4 AND pay.status = "approved") AS collected,
                (SELECT COALESCE(SUM(total_quantity), 0) FROM event_items WHERE event_id = :eid5) AS total_items,
                (SELECT COALESCE(SUM(claimed_quantity), 0) FROM event_items WHERE event_id = :eid6) AS claimed_items'
        );
        $stmt->execute([
            'eid1' => $eventId,
            'eid2' => $eventId,
            'eid3' => $eventId,
            'eid4' => $eventId,
            'eid5' => $eventId,
            'eid6' => $eventId,
        ]);
        $r = $stmt->fetch();

        $attendanceRate = $r['approved'] > 0 ? round(($r['attended'] / $r['approved']) * 100, 1) : 0;

        return [
            'columns' => ['Metric', 'Value'],
            'rows' => [
                ['Total Registrations', $r['total_reg']],
                ['Approved Registrations', $r['approved']],
                ['Attendance Recorded', $r['attended']],
                ['Attendance Rate', $attendanceRate . '%'],
                ['Payments Collected', number_format($r['collected'], 2)],
                ['Items Claimed', $r['claimed_items'] . ' / ' . $r['total_items']],
            ],
        ];
    }
}
