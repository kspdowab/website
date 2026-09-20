<?php
/**
 * KSPDOWA — Scheduled Tasks & Automation Runner
 * ============================================================
 * CLI / Cron entry point for automated association workflows:
 *   1. Grievance Ageing & Escalation Check
 *   2. Membership Fee Renewal & Due Alerts
 *   3. Notification Log Rotation (retention: 90 days)
 *
 * Usage (Hostinger Cron / CLI):
 *   php scripts/run_scheduled_tasks.php
 *
 * Can also be triggered on-demand by authorized State Administrators
 * from the Admin Integrations portal.
 * ============================================================
 */

declare(strict_types=1);

// CLI or Internal Admin Call only
if (PHP_SAPI !== 'cli' && !defined('INTERNAL_ADMIN_TRIGGER')) {
    http_response_code(403);
    exit("Access denied.\n");
}

$root = dirname(__DIR__);
require_once $root . '/public_html/includes/bootstrap.php';

function task_log(string $msg): void {
    $formatted = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (PHP_SAPI === 'cli') {
        echo $formatted . "\n";
    } else {
        error_log($formatted);
    }
}

task_log('Starting KSPDOWA Scheduled Automation Tasks...');

// ------------------------------------------------------------------
// Task 1: Grievance Ageing & Escalation Check (§03_GRIEVANCE_WORKFLOW)
// ------------------------------------------------------------------
task_log('Task 1: Evaluating grievance ageing and SLA backlog...');

$openGrievances = Database::fetchAll(
    "SELECT g.id, g.grievance_no, g.subject, g.status, g.created_at, g.member_id,
            g.taluk_id, g.district_id, g.assigned_to,
            DATEDIFF(NOW(), g.created_at) AS days_open
     FROM grievances g
     WHERE g.status NOT IN ('Resolved', 'Rejected', 'Closed')
     ORDER BY g.created_at ASC"
);

$escalatedCount = 0;
foreach ($openGrievances as $grv) {
    $daysOpen = (int)$grv['days_open'];
    $grvId    = (int)$grv['id'];
    $grvNo    = $grv['grievance_no'];

    // If pending > 15 days, check if a reminder was dispatched in the last 7 days
    if ($daysOpen >= 15) {
        $recentReminder = Database::fetchOne(
            "SELECT id FROM notification_logs
             WHERE related_module = 'grievances' AND related_id = ?
               AND event_type LIKE 'GRIEVANCE_REMINDER_%'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 1",
            [$grvId]
        );

        if (!$recentReminder) {
            $urgency = ($daysOpen >= 30) ? 'URGENT ESCALATION' : 'AGEING REMINDER';
            $title   = "{$urgency}: Grievance {$grvNo} Pending ({$daysOpen} Days)";
            $message = "Grievance {$grvNo} ('{$grv['subject']}') has been pending resolution for {$daysOpen} days without completion. Please review and expedite.";

            // Notify assigned officer or taluk/district administrators
            if (!empty($grv['assigned_to'])) {
                NotificationService::sendToUser((int)$grv['assigned_to'], 'GRIEVANCE_REMINDER_AGEING', $title, $message, 'grievances', $grvId);
            } elseif (!empty($grv['taluk_id'])) {
                NotificationService::sendToRoleOfficers('taluk_admin', 'GRIEVANCE_REMINDER_AGEING', $title, $message, (int)$grv['district_id'], (int)$grv['taluk_id'], 'grievances', $grvId);
            }

            // If pending > 30 days, notify District Admin as well
            if ($daysOpen >= 30 && !empty($grv['district_id'])) {
                NotificationService::sendToRoleOfficers('district_admin', 'GRIEVANCE_REMINDER_OVERDUE', $title, $message, (int)$grv['district_id'], null, 'grievances', $grvId);
            }

            $escalatedCount++;
        }
    }
}
task_log("Grievance evaluation completed: {$escalatedCount} ageing alerts dispatched.");

// ------------------------------------------------------------------
// Task 2: Membership Fee Renewal Due Alerts
// ------------------------------------------------------------------
task_log('Task 2: Checking membership renewal reminders...');

$currentFy = Database::fetchOne("SELECT id, fy_name FROM financial_years WHERE is_current = 1 LIMIT 1");
$reminderCount = 0;

if ($currentFy) {
    $fyId = (int)$currentFy['id'];
    $fyName = $currentFy['fy_name'];

    // Active members who have not paid for the current financial year
    $unpaidMembers = Database::fetchAll(
        "SELECT m.id, m.full_name, m.email, m.phone, u.id AS user_id
         FROM members m
         LEFT JOIN users u ON m.id = u.member_id
         WHERE m.status = 'active'
           AND m.id NOT IN (
               SELECT member_id FROM membership_payments
               WHERE financial_year_id = ? AND payment_status = 'success'
           )
         LIMIT 25",
        [$fyId]
    );

    foreach ($unpaidMembers as $mem) {
        $memberId = (int)$mem['id'];
        $userId   = !empty($mem['user_id']) ? (int)$mem['user_id'] : null;

        // Ensure we don't spam reminders: only once every 30 days
        $recentNotif = Database::fetchOne(
            "SELECT id FROM notification_logs
             WHERE event_type = 'FEE_DUE_REMINDER'
               AND related_module = 'financial_years' AND related_id = ?
               AND (recipient = ? OR recipient = ?)
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 1",
            [$fyId, (string)$mem['email'], (string)$mem['phone']]
        );

        if (!$recentNotif) {
            $title = "Membership Fee Due for FY {$fyName}";
            $message = "Dear {$mem['full_name']}, this is a friendly reminder that your KSPDOWA annual membership fee for FY {$fyName} is pending. Please log in to your portal to complete the renewal.";

            if ($userId) {
                NotificationService::sendToUser($userId, 'FEE_DUE_REMINDER', $title, $message, 'financial_years', $fyId, [
                    'action_url' => (defined('APP_URL') ? APP_URL : '') . '/member/index.php',
                    'action_label' => 'Renew Membership Fee'
                ]);
            } else {
                NotificationService::sendToMember($memberId, 'FEE_DUE_REMINDER', $title, $message, 'financial_years', $fyId);
            }
            $reminderCount++;
        }
    }
}
task_log("Membership check completed: {$reminderCount} fee due reminders dispatched.");

// ------------------------------------------------------------------
// Task 3: Notification Log Maintenance (Purge logs > 90 days)
// ------------------------------------------------------------------
task_log('Task 3: Rotating notification logs (retention: 90 days)...');
$deleted = Database::execute("DELETE FROM notification_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
task_log("Notification log rotation complete. Purged old records.");

task_log('All scheduled automation tasks finished successfully.');
