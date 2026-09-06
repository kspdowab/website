<?php
/**
 * KSPDOWA — Audit Logger
 * ============================================================
 * Writes to the audit_logs table (created in migration 009).
 * Designed to NEVER break the application — all DB failures are
 * silently logged to the PHP error log.
 *
 * Every important administrative and data-change action must
 * call AuditLogger::log() per the project security specification.
 *
 * Usage:
 *   AuditLogger::log('CREATE', 'members', $newMemberId, null, $newData);
 *   AuditLogger::log('UPDATE', 'members', $id, $oldData, $newData);
 *   AuditLogger::log('DELETE', 'members', $id, $oldData);
 *   AuditLogger::log('LOGIN',  'users',   $userId);
 * ============================================================
 */

declare(strict_types=1);

class AuditLogger
{
    /**
     * Record an audit event.
     *
     * @param string    $action   Verb describing the event (LOGIN, LOGOUT, CREATE,
     *                            UPDATE, DELETE, VIEW, FORWARD, ESCALATE, …)
     * @param string    $module   The module/table affected (users, members, grievances, …)
     * @param int|null  $recordId Primary key of the affected record, if applicable
     * @param array|null $oldData  Previous state (for UPDATE / DELETE)
     * @param array|null $newData  New state (for CREATE / UPDATE)
     */
    public static function log(
        string  $action,
        string  $module,
        ?int    $recordId = null,
        ?array  $oldData  = null,
        ?array  $newData  = null
    ): void {
        try {
            // Resolve user from session (may be null for unauthenticated events)
            $userId = null;
            if (class_exists('Session', false) && Session::has('user_id')) {
                $userId = (int) Session::get('user_id');
            }

            $ipAddress = self::resolveClientIp();
            $userAgent = mb_substr(
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                0,
                255,
                'UTF-8'
            );

            $oldJson = ($oldData !== null)
                ? json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $newJson = ($newData !== null)
                ? json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            Database::execute(
                'INSERT INTO audit_logs
                 (user_id, action, module, record_id, old_data, new_data, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $userId,
                    mb_substr($action, 0, 100, 'UTF-8'),
                    mb_substr($module, 0, 100, 'UTF-8'),
                    $recordId,
                    $oldJson,
                    $newJson,
                    $ipAddress,
                    $userAgent,
                ]
            );
        } catch (Throwable $e) {
            // Audit logging must never crash the application
            error_log(
                '[KSPDOWA][AuditLogger] Failed to write audit log: '
                . $e->getMessage()
                . " | action={$action} module={$module}"
            );
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Best-effort client IP resolution (handles Hostinger/LiteSpeed proxies).
     * Never trusts client-supplied headers for security-critical decisions;
     * here they are used only for informational logging.
     */
    private static function resolveClientIp(): string
    {
        // Check forwarded headers (Hostinger may sit behind a proxy)
        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            // X-Forwarded-For may be a comma-separated list; first is the client
            $ip = trim(explode(',', $_SERVER[$key])[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }

            // Accept private-range IPs (local development)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}
