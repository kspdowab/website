<?php
/**
 * KSPDOWA — Admin: Meetings Management
 * ============================================================
 * Gated by RBAC permission 'meetings.view'.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'meetings', 'view');

$canManage = RBAC::hasPermission($currentUserId, 'meetings', 'manage') ||
             RBAC::hasPermission($currentUserId, 'meetings', 'create') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

// POST handler
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $meetingDate = Sanitize::string($_POST['meeting_date'] ?? '', 20);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'officer', ['member', 'public', 'officer', 'admin']) ?: 'officer';
        $agenda      = Sanitize::string($_POST['agenda'] ?? '', 5000);

        if ($title === '') {
            Session::flash('error', 'Meeting title is required.');
        } else {
            if ($meetingDate === '') {
                $meetingDate = date('Y-m-d H:i:s');
            }
            Database::execute(
                "INSERT INTO meetings (title, meeting_date, location, agenda, access_level, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$title, $meetingDate, $location, $agenda, $accessLevel, $currentUserId]
            );
            $meetId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'meetings', $meetId, null, ['title' => $title]);
            Session::flash('success', "Meeting '{$title}' recorded successfully.");
            header('Location: /admin/meetings.php');
            exit;
        }
    }
}

$meetings = Database::fetchAll("SELECT m.*, u.username AS creator_name FROM meetings m LEFT JOIN users u ON u.id = m.created_by ORDER BY m.meeting_date DESC");

$pageTitle  = 'Meetings Management';
$activeMenu = 'meetings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Meetings', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Meetings Management</h1>
        <p class="page-heading-subtitle">Executive committee, district council, and state board meeting records</p>
    </div>
    <div>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('addMeetingCard').style.display = 'block';">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Record Meeting
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="table-card" id="addMeetingCard" style="display:none; margin-bottom:24px;">
    <div class="table-card-header" style="background:#f8fafc;">
        <span class="table-card-title">Record Meeting</span>
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('addMeetingCard').style.display = 'none';">✕ Close</button>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/meetings.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:18px 24px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Meeting Title *</label>
                    <input type="text" name="title" class="form-control" required maxlength="300" placeholder="e.g. State Executive Committee Meeting">
                </div>
                <div class="form-group">
                    <label class="form-label">Date &amp; Time *</label>
                    <input type="datetime-local" name="meeting_date" class="form-control" required value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Location / Platform</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g. Association State HQ, Bengaluru">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Level</label>
                    <select name="access_level" class="form-select">
                        <option value="officer">Officer Level</option>
                        <option value="member">Member</option>
                        <option value="admin">Admin Only</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Agenda &amp; Resolutions</label>
                    <textarea name="agenda" rows="4" class="form-control" placeholder="Record agenda items discussed..."></textarea>
                </div>
            </div>
            <div style="margin-top:20px;">
                <button type="submit" class="btn btn-primary">Save Meeting Record</button>
            </div>
        </form>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Meetings Register</span>
            <span class="table-card-count">(<?= count($meetings) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Meeting Title</th>
                    <th>Date &amp; Time</th>
                    <th>Location</th>
                    <th>Access</th>
                    <th>Agenda / Notes</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($meetings)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No meetings recorded yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($meetings as $mt): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:700; color:var(--text-main);"><?= Sanitize::html($mt['title']) ?></td>
                        <td><?= $mt['meeting_date'] ? date('d M Y, h:i A', strtotime($mt['meeting_date'])) : '—' ?></td>
                        <td><?= Sanitize::html($mt['location'] ?? '—') ?></td>
                        <td><span class="badge badge-neutral"><?= ucfirst(Sanitize::html($mt['access_level'])) ?></span></td>
                        <td style="color:var(--text-muted); font-size:0.84rem; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= Sanitize::html($mt['agenda'] ?? '—') ?>
                        </td>
                        <td><?= Sanitize::html($mt['creator_name'] ?? 'Officer') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
