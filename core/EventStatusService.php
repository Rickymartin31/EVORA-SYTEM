<?php

/**
 * Central place to change an event's lifecycle status so every transition is
 * consistently written to event_status_logs for audit purposes.
 */
class EventStatusService
{
    /** @return string the status the event had before this change */
    public static function changeStatus(PDO $db, int $eventId, string $toStatus, ?int $changedBy, ?string $remarks = null): string
    {
        $current = $db->prepare('SELECT status FROM events WHERE id = :id');
        $current->execute(['id' => $eventId]);
        $currentRow = $current->fetch();

        if (!$currentRow) {
            throw new InvalidArgumentException('Cannot change status of event #' . $eventId . ' — it does not exist.');
        }

        $fromStatus = $currentRow['status'];

        if ($remarks !== null) {
            $update = $db->prepare('UPDATE events SET status = :status, admin_remarks = :remarks WHERE id = :id');
            $update->execute(['status' => $toStatus, 'remarks' => $remarks, 'id' => $eventId]);
        } else {
            $update = $db->prepare('UPDATE events SET status = :status WHERE id = :id');
            $update->execute(['status' => $toStatus, 'id' => $eventId]);
        }

        $log = $db->prepare(
            'INSERT INTO event_status_logs (event_id, from_status, to_status, changed_by, remarks) VALUES (:eid, :from, :to, :by, :remarks)'
        );
        $log->execute([
            'eid' => $eventId, 'from' => $fromStatus, 'to' => $toStatus, 'by' => $changedBy, 'remarks' => $remarks,
        ]);

        return $fromStatus;
    }
}
