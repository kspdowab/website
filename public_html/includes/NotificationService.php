<?php
/**
 * KSPDOWA — Central Notification Service
 * ============================================================
 * Dispatches transactional notifications across multiple channels:
 *   1. In-App: records into `notifications` table (member/admin portal)
 *   2. Email: styled HTML via Mailer & EmailTemplates
 *   3. WhatsApp: transactional text alerts via WhatsApp client
 *
 * Enforces admin event toggles (`notif_event_*`), records delivery
 * audit logs in `notification_logs`, and ensures notification failures
 * never crash the calling transaction.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/EmailTemplates.php';
require_once __DIR__ . '/WhatsApp.php';

class NotificationService
{
    /**
     * Send notification to a specific user ID.
     */
    public static function sendToUser(
        int $userId,
        string $eventType,
        string $title,
        string $message,
        ?string $module = null,
        ?int $recordId = null,
        array $extra = []
    ): array {
        $result = ['in_app' => false, 'email' => false, 'whatsapp' => false];

        try {
            // Check if this event category is enabled in settings
            if (!self::isEventEnabled($eventType)) {
                return $result;
            }

            // Fetch user info (email, member link)
            $user = Database::fetchOne(
                "SELECT u.id, u.email, u.member_id, m.phone, m.full_name, m.kgid
                 FROM users u
                 LEFT JOIN members m ON u.member_id = m.id
                 WHERE u.id = ? LIMIT 1",
                [$userId]
            );

            if (!$user) {
                return $result;
            }

            // 1. In-App Notification (Always recorded when event is enabled)
            try {
                Database::execute(
                    "INSERT INTO notifications (user_id, type, title, message, related_module, related_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$userId, $eventType, $title, $message, $module, $recordId]
                );
                $result['in_app'] = true;
                self::logDispatch($userId, 'in_app', (string)$userId, $eventType, $title, $message, 'sent', null, $module, $recordId);
            } catch (Throwable $e) {
                self::logDispatch($userId, 'in_app', (string)$userId, $eventType, $title, $message, 'failed', $e->getMessage(), $module, $recordId);
            }

            // 2. Email Notification
            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && self::isEmailEnabled()) {
                $recipientName = !empty($user['full_name']) ? (string)$user['full_name'] : 'Member / Officer';
                $htmlBody = '<p>Dear <strong>' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
                          . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';

                if (!empty($extra['summary_table']) && is_array($extra['summary_table'])) {
                    $htmlBody .= EmailTemplates::renderSummaryTable($extra['summary_table']);
                }

                $actionUrl   = $extra['action_url'] ?? null;
                $actionLabel = $extra['action_label'] ?? null;

                $fullHtml = EmailTemplates::wrap($title, $htmlBody, $actionUrl, $actionLabel);
                $plainText = "Dear {$recipientName},\n\n{$message}\n\n";
                if ($actionUrl) {
                    $plainText .= "Link: {$actionUrl}\n\n";
                }
                $plainText .= "—\nKarnataka State Panchayat Development Officer Welfare Association (R)\nDesigned & Developed by : KHUBAASING JADAV";

                $mailSent = Mailer::send($email, $title, $plainText, $fullHtml);
                $result['email'] = $mailSent;
                $status = $mailSent ? (Mailer::getConfig()['driver'] === 'log' ? 'logged' : 'sent') : 'failed';
                self::logDispatch($userId, 'email', $email, $eventType, $title, $message, $status, null, $module, $recordId);
            }

            // 3. WhatsApp Notification
            $phone = trim((string)($user['phone'] ?? ''));
            if ($phone !== '' && self::isWhatsAppEnabled()) {
                $shortName = class_exists('Settings') ? Settings::get('site_short_name', APP_SHORT_NAME) : APP_SHORT_NAME;
                $waMessage = "📢 *{$shortName} Notification*\n\n"
                           . "*{$title}*\n\n"
                           . "{$message}\n\n";

                if (!empty($extra['action_url'])) {
                    $waMessage .= "Portal Link: " . $extra['action_url'] . "\n\n";
                }
                $waMessage .= "Designed & Developed by : KHUBAASING JADAV";

                $waErr = null;
                $waSent = WhatsApp::send($phone, $waMessage, $waErr);
                $result['whatsapp'] = $waSent;
                $status = $waSent ? (WhatsApp::getConfig()['driver'] === 'log' ? 'logged' : 'sent') : 'failed';
                self::logDispatch($userId, 'whatsapp', $phone, $eventType, $title, $message, $status, $waErr, $module, $recordId);
            }
        } catch (Throwable $e) {
            error_log('[KSPDOWA][NotificationService] sendToUser error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Send notification to a member by their member ID.
     */
    public static function sendToMember(
        int $memberId,
        string $eventType,
        string $title,
        string $message,
        ?string $module = null,
        ?int $recordId = null,
        array $extra = []
    ): array {
        $u = Database::fetchOne("SELECT id FROM users WHERE member_id = ? LIMIT 1", [$memberId]);
        if ($u && !empty($u['id'])) {
            return self::sendToUser((int)$u['id'], $eventType, $title, $message, $module, $recordId, $extra);
        }

        // If no user account is linked, try direct phone/email from members table
        $m = Database::fetchOne("SELECT email, phone, full_name FROM members WHERE id = ? LIMIT 1", [$memberId]);
        if (!$m) {
            return ['in_app' => false, 'email' => false, 'whatsapp' => false];
        }

        $result = ['in_app' => false, 'email' => false, 'whatsapp' => false];
        $email = trim((string)($m['email'] ?? ''));
        $phone = trim((string)($m['phone'] ?? ''));

        if ($email !== '' && self::isEmailEnabled()) {
            $htmlBody = '<p>Dear <strong>' . htmlspecialchars($m['full_name'] ?? 'Member', ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
                      . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
            if (!empty($extra['summary_table'])) {
                $htmlBody .= EmailTemplates::renderSummaryTable($extra['summary_table']);
            }
            $fullHtml = EmailTemplates::wrap($title, $htmlBody, $extra['action_url'] ?? null, $extra['action_label'] ?? null);
            $plainText = "Dear {$m['full_name']},\n\n{$message}\n\n—\nKSPDOWA";
            $mailSent = Mailer::send($email, $title, $plainText, $fullHtml);
            $result['email'] = $mailSent;
            self::logDispatch(null, 'email', $email, $eventType, $title, $message, $mailSent ? 'sent' : 'failed', null, $module, $recordId);
        }

        if ($phone !== '' && self::isWhatsAppEnabled()) {
            $waMessage = "*KSPDOWA Alert*\n\n*{$title}*\n\n{$message}\n\nDesigned & Developed by : KHUBAASING JADAV";
            $waErr = null;
            $waSent = WhatsApp::send($phone, $waMessage, $waErr);
            $result['whatsapp'] = $waSent;
            self::logDispatch(null, 'whatsapp', $phone, $eventType, $title, $message, $waSent ? 'sent' : 'failed', $waErr, $module, $recordId);
        }

        return $result;
    }

    /**
     * Send notification to officers holding a specific role within administrative scope.
     */
    public static function sendToRoleOfficers(
        string $roleName,
        string $eventType,
        string $title,
        string $message,
        ?int $districtId = null,
        ?int $talukId = null,
        ?string $module = null,
        ?int $recordId = null,
        array $extra = []
    ): int {
        $sql = "SELECT DISTINCT u.id
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.name = ? AND u.status = 'active'";
        $params = [$roleName];

        if ($districtId !== null) {
            $sql .= " AND (u.district_id = ? OR u.district_id IS NULL)";
            $params[] = $districtId;
        }
        if ($talukId !== null) {
            $sql .= " AND (u.taluk_id = ? OR u.taluk_id IS NULL)";
            $params[] = $talukId;
        }

        $officers = Database::fetchAll($sql, $params);
        $count = 0;
        foreach ($officers as $officer) {
            self::sendToUser((int)$officer['id'], $eventType, $title, $message, $module, $recordId, $extra);
            $count++;
        }

        return $count;
    }

    /**
     * Log delivery attempt in notification_logs.
     */
    public static function logDispatch(
        ?int $userId,
        string $channel,
        string $recipient,
        string $eventType,
        string $title,
        ?string $bodyPreview,
        string $status,
        ?string $error = null,
        ?string $module = null,
        ?int $recordId = null
    ): void {
        try {
            $preview = $bodyPreview !== null ? mb_substr(strip_tags($bodyPreview), 0, 300) : null;
            Database::execute(
                "INSERT INTO notification_logs
                 (user_id, channel, recipient, event_type, title, body_preview, status, error_message, related_module, related_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$userId, $channel, $recipient, $eventType, $title, $preview, $status, $error, $module, $recordId]
            );
        } catch (Throwable $e) {
            error_log('[KSPDOWA][NotificationService] logDispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify if event type category is enabled.
     */
    private static function isEventEnabled(string $eventType): bool
    {
        if (!class_exists('Settings')) {
            return true;
        }

        if (str_starts_with($eventType, 'GRIEVANCE_')) {
            return Settings::get('notif_event_grievance', 'true') === 'true';
        }
        if (str_starts_with($eventType, 'PAYMENT_') || str_starts_with($eventType, 'FEE_')) {
            return Settings::get('notif_event_payment', 'true') === 'true';
        }
        if (str_starts_with($eventType, 'MEMBER_') || str_starts_with($eventType, 'MEMBERSHIP_')) {
            return Settings::get('notif_event_membership', 'true') === 'true';
        }
        if (str_starts_with($eventType, 'SUGGESTION_')) {
            return Settings::get('notif_event_suggestion', 'true') === 'true';
        }

        return true;
    }

    private static function isEmailEnabled(): bool
    {
        if (!class_exists('Settings')) {
            return false;
        }
        return Settings::get('email_notifications_enabled', 'false') === 'true';
    }

    private static function isWhatsAppEnabled(): bool
    {
        return WhatsApp::getConfig()['enabled'] || WhatsApp::getConfig()['driver'] === 'log';
    }
}
