<?php
/**
 * KSPDOWA — Admin: Events Management
 * ============================================================
 * Gated by RBAC permission 'events.view'.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'events', 'view');

$canManage = RBAC::hasPermission($currentUserId, 'events', 'manage') ||
             RBAC::hasPermission($currentUserId, 'events', 'create') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

// POST handler
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $eventDate   = Sanitize::string($_POST['event_date'] ?? '', 12);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        if ($title === '') {
            Session::flash('error', 'Event title is required.');
        } else {
            if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                $eventDate = date('Y-m-d');
            }
            Database::execute(
                "INSERT INTO events (title, description, event_date, location, access_level, created_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')",
                [$title, $description, $eventDate, $location, $accessLevel, $currentUserId]
            );
            $evId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'events', $evId, null, ['title' => $title]);
            Session::flash('success', "Event '{$title}' created successfully.");
            header('Location: /admin/events.php');
            exit;
        }
    }
}

$events = Database::fetchAll("SELECT e.*, u.username AS creator_name FROM events e LEFT JOIN users u ON u.id = e.created_by ORDER BY e.event_date DESC");

$pageTitle  = 'Events Management';
$activeMenu = 'events';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Events', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Events Management</h1>
        <p class="page-heading-subtitle">Association general bodies, summits, and official state events</p>
    </div>
    <div>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('addEventCard').style.display = 'block';">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Event
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="table-card" id="addEventCard" style="display:none; margin-bottom:24px;">
    <div class="table-card-header" style="background:#f8fafc;">
        <span class="table-card-title">Schedule New Event</span>
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('addEventCard').style.display = 'none';">✕ Close</button>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/events.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:18px 24px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Event Title *</label>
                    <input type="text" name="title" class="form-control" required maxlength="300" placeholder="e.g. Annual State Delegate Conference 2026">
                </div>
                <div class="form-group">
                    <label class="form-label">Event Date *</label>
                    <input type="date" name="event_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g. Town Hall, Bengaluru">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Level</label>
                    <select name="access_level" class="form-select">
                        <option value="member">Member</option>
                        <option value="public">Public</option>
                        <option value="officer">Officer</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="3" class="form-control" placeholder="Agenda and details..."></textarea>
                </div>
            </div>
            <div style="margin-top:20px;">
                <button type="submit" class="btn btn-primary">Save Event</button>
            </div>
        </form>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Events Schedule</span>
            <span class="table-card-count">(<?= count($events) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Event Title</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Created By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No events currently scheduled.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($events as $ev): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:700; color:var(--text-main);"><?= Sanitize::html($ev['title']) ?></td>
                        <td><?= $ev['event_date'] ? date('d M Y', strtotime($ev['event_date'])) : '—' ?></td>
                        <td><?= Sanitize::html($ev['location'] ?? '—') ?></td>
                        <td><span class="badge badge-neutral"><?= ucfirst(Sanitize::html($ev['access_level'])) ?></span></td>
                        <td><span class="badge badge-success"><?= ucfirst(Sanitize::html($ev['status'])) ?></span></td>
                        <td><?= Sanitize::html($ev['creator_name'] ?? 'Officer') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
