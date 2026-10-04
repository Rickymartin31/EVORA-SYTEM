<?php

class Logger
{
    public static function log(?int $userId, string $action, string $description = ''): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (:user_id, :action, :description, :ip, :ua)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            // device/browser for the user's Security and Activity Log pages (v33)
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    }
}
