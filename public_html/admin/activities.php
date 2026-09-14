<?php
/**
 * KSPDOWA — Admin: Activities Management
 * ============================================================
 * Section 7 Specification:
 * - Gated by RBAC permission 'activities.view' / 'activities.manage'
 * - Add
 * - Edit
 * - View
 * - Publish / manage (Active / Archived)
 * - Search / filter
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'activities', 'view');

$canManage = RBAC::hasPermission($currentUserId, 'activities', 'manage') ||
             RBAC::hasPermission($currentUserId, 'activities', 'create') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

// ─── POST Handlers ───────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if (!$canManage) {
        ErrorHandler::abort(403, 'You do not have permission to manage activities.');
    }

    // ── CREATE ACTIVITY ──
    if ($action === 'create') {
        $title        = Sanitize::string($_POST['title'] ?? '', 300);
        $activityDate = Sanitize::string($_POST['activity_date'] ?? '', 12);
        $location     = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel  = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description  = Sanitize::string($_POST['description'] ?? '', 5000);

        if ($title === '') {
            Session::flash('error', 'Activity title is required.');
        } else {
            if ($activityDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) {
                $activityDate = date('Y-m-d');
            }

            Database::execute(
                "INSERT INTO activities (title, description, activity_date, location, access_level, created_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')",
                [$title, $description, $activityDate, $location, $accessLevel, $currentUserId]
            );
            $actId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'activities', $actId, null, ['title' => $title]);
            Session::flash('success', "Activity '{$title}' created successfully.");
            header('Location: /admin/activities.php');
            exit;
        }
    }

    // ── UPDATE ACTIVITY ──
    if ($action === 'update') {
        $id           = Sanitize::positiveInt($_POST['id'] ?? null);
        $title        = Sanitize::string($_POST['title'] ?? '', 300);
        $activityDate = Sanitize::string($_POST['activity_date'] ?? '', 12);
        $location     = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel  = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description  = Sanitize::string($_POST['description'] ?? '', 5000);

        if (!$id || $title === '') {
            Session::flash('error', 'Valid activity ID and title required.');
        } else {
            if ($activityDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) {
                $activityDate = date('Y-m-d');
            }

            Database::execute(
                "UPDATE activities SET title = ?, description = ?, activity_date = ?, location = ?, access_level = ? WHERE id = ?",
                [$title, $description, $activityDate, $location, $accessLevel, $id]
            );
            AuditLogger::log('UPDATE', 'activities', $id, null, ['title' => $title]);
            Session::flash('success', "Activity '{$title}' updated successfully.");
            header('Location: /admin/activities.php');
            exit;
        }
    }

    // ── TOGGLE STATUS ──
    if ($action === 'toggle_archive') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $row = Database::fetchOne('SELECT status FROM activities WHERE id = ?', [$id]);
            if ($row) {
                $newStatus = ($row['status'] === 'archived') ? 'active' : 'archived';
                Database::execute('UPDATE activities SET status = ? WHERE id = ?', [$newStatus, $id]);
                AuditLogger::log('STATUS_CHANGE', 'activities', $id, null, ['new_status' => $newStatus]);
                Session::flash('success', "Activity status changed to " . ucfirst($newStatus) . ".");
            }
        }
        header('Location: /admin/activities.php');
        exit;
    }
}

// ─── Filters & Listing ───────────────────────────────────────────────────────
$qSearch  = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qStatus  = Sanitize::inArray($_GET['status'] ?? 'all', ['all', 'active', 'archived']) ?: 'all';
$editId   = Sanitize::positiveInt($_GET['edit'] ?? null);
$showForm = isset($_GET['add']) || $editId;

$hasActiveFilters = ($qSearch !== '' || $qStatus !== 'all');

$whereClause = ["1=1"];
$params      = [];

if ($qSearch !== '') {
    $whereClause[] = "(a.title LIKE ? OR a.description LIKE ? OR a.location LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

if ($qStatus !== 'all') {
    $whereClause[] = "a.status = ?";
    $params[]      = $qStatus;
}

$whereSql = implode(' AND ', $whereClause);

$sql = "
    SELECT a.*, u.username AS creator_name
    FROM activities a
    LEFT JOIN users u ON u.id = a.created_by
    WHERE {$whereSql}
    ORDER BY a.activity_date DESC, a.id DESC
";
$activitiesList = Database::fetchAll($sql, $params);

// Edit Row
$editRow = null;
if ($editId) {
    $editRow = Database::fetchOne('SELECT * FROM activities WHERE id = ?', [$editId]);
}

$pageTitle  = 'Activities Management';
$activeMenu = 'activities';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Activities', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Activities Management</h1>
        <p class="page-heading-subtitle">Track, publish, and manage association programs and initiatives</p>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="button" id="toggleFilterBtn" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:7px; font-size:0.84rem; padding:6px 14px; background:#ffffff; border:1px solid var(--border, #dce3ea); border-radius:6px; cursor:pointer; font-weight:600; color:var(--primary-navy, #173F67); box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span id="filterToggleText"><?= $hasActiveFilters ? 'Hide Filters' : 'Filter / Search' ?></span>
            <?php if ($hasActiveFilters): ?>
                <span class="badge badge-purple" style="font-size:0.72rem; padding:2px 7px;">Active</span>
            <?php endif; ?>
        </button>
        <?php if ($canManage): ?>
            <?php if ($showForm): ?>
                <a href="/admin/activities.php" class="btn btn-outline">← Back to List</a>
            <?php else: ?>
                <a href="/admin/activities.php?add=1" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Activity
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ADD / EDIT FORM
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($showForm && $canManage): ?>
<div class="table-card" style="margin-bottom:28px;">
    <div class="table-card-header" style="background:#f8fafc;">
        <span class="table-card-title"><?= $editRow ? 'Edit Activity: ' . Sanitize::html($editRow['title']) : 'Add New Activity' ?></span>
        <a href="/admin/activities.php" class="btn btn-outline btn-sm">✕ Cancel</a>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/activities.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:18px 24px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="act_title">Activity Title *</label>
                    <input type="text" name="title" id="act_title" class="form-control" required maxlength="300" placeholder="e.g. State Level PDO Welfare Conference 2026" value="<?= Sanitize::attr($editRow['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="activity_date">Activity Date *</label>
                    <input type="date" name="activity_date" id="activity_date" class="form-control" required value="<?= Sanitize::attr($editRow['activity_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="act_location">Location / Venue</label>
                    <input type="text" name="location" id="act_location" class="form-control" maxlength="300" placeholder="e.g. Bengaluru, Karnataka" value="<?= Sanitize::attr($editRow['location'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="access_level">Access Level *</label>
                    <select name="access_level" id="access_level" class="form-select" required>
                        <option value="member" <?= ($editRow['access_level'] ?? 'member') === 'member' ? 'selected' : '' ?>>Member Only (Standard)</option>
                        <option value="public" <?= ($editRow['access_level'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="officer" <?= ($editRow['access_level'] ?? '') === 'officer' ? 'selected' : '' ?>>Officer Level</option>
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="act_desc">Detailed Description &amp; Highlights</label>
                    <textarea name="description" id="act_desc" rows="4" class="form-control" placeholder="Details of the activity, attendees, resolutions..."><?= Sanitize::html($editRow['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary">
                    <?= $editRow ? 'Save Changes' : 'Publish Activity' ?>
                </button>
                <a href="/admin/activities.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card" id="filterCard" style="<?= $hasActiveFilters ? '' : 'display: none;' ?>">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Activities
        </div>
        <div class="filter-header-actions" style="display:flex; gap:8px; align-items:center;">
            <a href="/admin/activities.php" class="filter-header-btn">↺ Reset</a>
            <button type="button" id="hideFilterBtn" class="filter-header-btn" style="background:none; border:1px solid var(--border, #dce3ea); cursor:pointer; display:inline-flex; align-items:center; gap:4px;" title="Hide Filter Section">
                ✕ Hide
            </button>
        </div>
    </div>
    <form method="get" action="/admin/activities.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $qStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="active" <?= $qStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $qStatus === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Search by title, location, description..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/activities.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ACTIVITIES TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Activities</span>
            <span class="table-card-count">(<?= count($activitiesList) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Activity Title</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activitiesList)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No activities found matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($activitiesList as $act): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($act['title']) ?>
                        </td>
                        <td><?= $act['activity_date'] ? date('d-m-Y', strtotime($act['activity_date'])) : '—' ?></td>
                        <td><?= Sanitize::html($act['location'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($act['access_level'])) ?></span>
                        </td>
                        <td>
                            <?php if ($act['status'] === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Sanitize::html($act['creator_name'] ?? 'Officer') ?></td>
                        <?php if ($canManage): ?>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <a href="/admin/activities.php?edit=<?= (int)$act['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                <form method="post" action="/admin/activities.php" style="display:inline;" onsubmit="return confirm('Toggle status for this activity?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="toggle_archive">
                                    <input type="hidden" name="id" value="<?= (int)$act['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--text-muted);">
                                        <?= $act['status'] === 'archived' ? 'Unarchive' : 'Archive' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var filterCard = document.getElementById('filterCard');
    var toggleBtn  = document.getElementById('toggleFilterBtn');
    var toggleText = document.getElementById('filterToggleText');
    var hideBtn    = document.getElementById('hideFilterBtn');

    function setFilterVisibility(show) {
        if (!filterCard) return;
        if (show) {
            filterCard.style.display = 'block';
            if (toggleText) toggleText.textContent = 'Hide Filters';
            filterCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            filterCard.style.display = 'none';
            if (toggleText) toggleText.textContent = 'Filter / Search';
        }
    }

    if (toggleBtn && filterCard) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var isCurrentlyHidden = (filterCard.style.display === 'none' || window.getComputedStyle(filterCard).display === 'none');
            setFilterVisibility(isCurrentlyHidden);
        });
    }

    if (hideBtn && filterCard) {
        hideBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setFilterVisibility(false);
        });
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
