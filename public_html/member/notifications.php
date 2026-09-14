<?php
/**
 * KSPDOWA — Member Portal: Notifications
 * ============================================================
 * Section 8: Notifications
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId   = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$pageTitle  = 'Notifications';
$activeMenu = 'notifications';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Notifications', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

// Fetch notifications for the user
$notifications = Database::fetchAll(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
    [$currentUserId]
);
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Notifications</h1>
        <p class="page-heading-subtitle">Official alerts, circular publications, and grievance status updates</p>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Recent Alerts</span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Notification Title</th>
                    <th>Message</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($notifications)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No new notifications. You are completely up to date!
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($notifications as $n): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:600; color:var(--text-main);"><?= Sanitize::html($n['title']) ?></td>
                        <td style="color:var(--text-muted); font-size:0.86rem;"><?= Sanitize::html($n['message']) ?></td>
                        <td><?= date('d M Y, h:i A', strtotime((string)$n['created_at'])) ?></td>
                        <td>
                            <?php if ($n['read_at']): ?>
                                <span class="badge badge-neutral">Read</span>
                            <?php else: ?>
                                <span class="badge badge-info">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
