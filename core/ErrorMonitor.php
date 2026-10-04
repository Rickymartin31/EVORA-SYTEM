<?php

/**
 * Error monitoring (v35).
 *
 * Every PHP warning/notice, uncaught exception and fatal error is written as one JSON line
 * to storage/logs/errors/errors-YYYY-MM.log (outside public/, so it is not web-readable).
 * Only the message, file:line, route, method, user id and a reference code are kept —
 * never request data, passwords, tokens or session contents.
 *
 * A crash (exception or fatal error) shows a friendly "Something went wrong" page with the
 * reference code, and each NEW crash (same message + place) alerts the administrators at
 * most once an hour. Admins read the log on the Error Log page.
 */
class ErrorMonitor
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $level)) {
                return false; // silenced with @
            }
            self::write(self::levelName($level), $message, $file, $line);

            return false; // PHP keeps its own handling (display_errors stays off)
        });

        set_exception_handler(function (Throwable $e): void {
            $ref = self::write('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
            self::alertAdmins($e->getMessage(), $e->getFile(), $e->getLine(), $ref);
            self::renderCrashPage($ref);
        });

        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $ref = self::write('fatal', $err['message'], $err['file'], $err['line']);
                self::alertAdmins($err['message'], $err['file'], $err['line'], $ref);
                self::renderCrashPage($ref);
            }
        });
    }

    public static function logDir(): string
    {
        return EVORA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'errors';
    }

    /** Writes one entry and returns its reference code. Never throws. */
    public static function write(string $level, string $message, string $file, int $line): string
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        try {
            $dir = self::logDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $entry = [
                'time' => date('Y-m-d H:i:s'),
                'ref' => $ref,
                'level' => $level,
                'message' => mb_substr(self::scrub($message), 0, 1000),
                'file' => self::relative($file),
                'line' => $line,
                'route' => mb_substr((string) ($_GET['route'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 80),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ];
            @file_put_contents($dir . DIRECTORY_SEPARATOR . 'errors-' . date('Y-m') . '.log', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // logging must never break the page
        }

        return $ref;
    }

    /** Newest entries first, from the last $months monthly files. */
    public static function recent(int $limit = 300, int $months = 2): array
    {
        $entries = [];
        for ($i = 0; $i < $months; $i++) {
            $file = self::logDir() . DIRECTORY_SEPARATOR . 'errors-' . date('Y-m', strtotime("first day of -$i month")) . '.log';
            if (!is_file($file)) {
                continue;
            }
            foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $lineText) {
                $row = json_decode($lineText, true);
                if (is_array($row)) {
                    $entries[] = $row;
                }
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    private static function alertAdmins(string $message, string $file, int $line, string $ref): void
    {
        try {
            $key = substr(hash('sha256', self::relative($file) . ':' . $line . ':' . preg_replace('/\d+/', '#', $message)), 0, 16);
            $marker = self::logDir() . DIRECTORY_SEPARATOR . 'alerted-' . $key;
            if (is_file($marker) && filemtime($marker) > time() - 3600) {
                return;
            }
            @touch($marker);
            if (!class_exists('Database', false) || !class_exists('NotificationService', false)) {
                return;
            }
            NotificationService::toAdmins(Database::connection(), 'System Error Detected',
                'A page crashed (reference ' . $ref . ') at ' . self::relative($file) . ':' . $line . '. Open Error Log for details.',
                'system_error', ['category' => 'system', 'notice_type' => 'action_required']);
        } catch (Throwable) {
            // the database itself may be what failed
        }
    }

    private static function renderCrashPage(string $ref): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Error (reference $ref). See storage/logs/errors.\n");

            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        $home = defined('BASE_URL') ? BASE_URL . '/index.php' : '/';
        // Branded crash page (v54): inline styles only, light/dark like the rest of EVORA.
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong — EVORA</title>'
            . '<script>try{if((localStorage.getItem("evora_theme")||(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"))==="dark"){document.documentElement.className="dark"}}catch(e){}</script>'
            . '<style>:root{--bg:#f5f7fb;--card:#fff;--text:#1f2937;--muted:#5b6474;--border:#e6e8f0;--soft:#fef3c7;--warn:#92400e}'
            . '.dark{--bg:#0f1117;--card:#171a23;--text:#e5e7eb;--muted:#9aa3b2;--border:#2a2f3c;--soft:rgba(245,158,11,.15);--warn:#fcd34d}'
            . '*{box-sizing:border-box}body{font-family:Inter,"Segoe UI",system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px}'
            . '.box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:40px 28px;max-width:500px;width:100%;text-align:center;box-shadow:0 4px 12px rgba(17,24,39,.06)}'
            . '.brand{font-weight:800;font-size:18px;margin-bottom:24px}.brand b{color:#06b6d4}.icon{width:72px;height:72px;margin:0 auto 12px;border-radius:50%;background:var(--soft);color:var(--warn);display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800}'
            . '.code{font-size:56px;font-weight:800;line-height:1;background:linear-gradient(135deg,#6366f1,#4f46e5,#4338ca);-webkit-background-clip:text;background-clip:text;color:transparent}'
            . 'h1{font-size:22px;margin:8px 0}p{color:var(--muted);font-size:15px;line-height:1.5}code{background:var(--bg);border:1px solid var(--border);padding:2px 8px;border-radius:6px;color:var(--text)}'
            . '.actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:10px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text);background:var(--card);cursor:pointer;font-size:15px}'
            . '.btn.primary{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-color:#4f46e5}</style></head>'
            . '<body><div class="box"><div class="brand">EVO<b>RA</b></div><div class="icon">!</div><div class="code">500</div><h1>Something went wrong</h1>'
            . '<p>EVORA could not finish this request. The problem was recorded and the administrators were notified. Please try again in a moment.</p>'
            . '<p>If you contact support, mention reference <code>' . htmlspecialchars($ref) . '</code>.</p>'
            . '<div class="actions"><button type="button" class="btn" onclick="history.back()">&larr; Go back</button><a class="btn primary" href="' . htmlspecialchars($home) . '">Go to EVORA</a></div></div></body></html>';
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            default => 'error',
        };
    }

    private static function relative(string $file): string
    {
        return ltrim(str_replace(['\\', str_replace('\\', '/', EVORA_ROOT)], ['/', ''], $file), '/');
    }

    /** Masks anything that looks like a secret before it is written. */
    private static function scrub(string $message): string
    {
        $message = preg_replace('/(password|passwd|secret|token|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2[hidden]', $message);

        return preg_replace('/\b(sk|pk)_(test|live)_[A-Za-z0-9]+/', '$1_$2_[hidden]', $message);
    }
}
