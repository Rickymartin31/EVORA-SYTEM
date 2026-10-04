<?php

/**
 * Append-only record of every financial status change (ledger entries,
 * event earnings, payout requests, payout accounts; legacy settlements/payouts). The table itself refuses updates
 * and deletes, so this is the audit trail for money.
 */
class FinanceHistory
{
    public static function record(PDO $db, string $entityType, int $entityId, ?string $fromStatus, string $toStatus, ?int $actorUserId, ?string $reason = null): void
    {
        $stmt = $db->prepare(
            'INSERT INTO finance_status_history (entity_type, entity_id, from_status, to_status, actor_user_id, reason)
             VALUES (:type, :id, :from, :to, :actor, :reason)'
        );
        $stmt->execute([
            'type' => $entityType,
            'id' => $entityId,
            'from' => $fromStatus,
            'to' => $toStatus,
            'actor' => $actorUserId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ]);
    }

    public static function forEntity(PDO $db, string $entityType, int $entityId): array
    {
        $stmt = $db->prepare(
            'SELECT h.*, u.full_name AS actor_name FROM finance_status_history h
             LEFT JOIN users u ON u.id = h.actor_user_id
             WHERE h.entity_type = :type AND h.entity_id = :id ORDER BY h.id'
        );
        $stmt->execute(['type' => $entityType, 'id' => $entityId]);

        return $stmt->fetchAll();
    }
}
