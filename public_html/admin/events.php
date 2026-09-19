<?php
/**
 * KSPDOWA — Admin: Events Management
 * ============================================================
 * Association general bodies, state summits, district rallies,
 * and official gatherings.
 * Gated by RBAC permission 'events.view'.
 * Server-side RBAC and prepared PDO queries.
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

// ─── Handle POST Actions ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $rawDate     = Sanitize::string($_POST['event_date'] ?? '', 30);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['public', 'member', 'officer', 'admin']) ?: 'member';
        $status      = Sanitize::inArray($_POST['status'] ?? 'upcoming', ['upcoming', 'ongoing', 'completed', 'cancelled']) ?: 'upcoming';
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        if ($title === '') {
            Session::flash('error', 'Event title is required.');
        } else {
            // Handle date format (Y-m-d or Y-m-d\TH:i or Y-m-d H:i:s)
            $eventDate = date('Y-m-d H:i:s');
            if ($rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false) {
                    $eventDate = date('Y-m-d H:i:s', $ts);
                }
            }

            Database::execute(
                "INSERT INTO events (title, description, event_date, location, access_level, created_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$title, $description, $eventDate, $location, $accessLevel, $currentUserId, $status]
            );
            $evId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'events', $evId, null, ['title' => $title, 'status' => $status]);
            Session::flash('success', "Event '{$title}' created successfully.");
            header('Location: /admin/events.php');
            exit;
        }
    } elseif ($action === 'update') {
        $eventId     = (int)($_POST['event_id'] ?? 0);
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $rawDate     = Sanitize::string($_POST['event_date'] ?? '', 30);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['public', 'member', 'officer', 'admin']) ?: 'member';
        $status      = Sanitize::inArray($_POST['status'] ?? 'upcoming', ['upcoming', 'ongoing', 'completed', 'cancelled']) ?: 'upcoming';
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        $existing = Database::fetchOne("SELECT * FROM events WHERE id = ?", [$eventId]);
        if (!$existing) {
            Session::flash('error', 'Event record not found.');
        } elseif ($title === '') {
            Session::flash('error', 'Event title cannot be empty.');
        } else {
            $eventDate = $existing['event_date'];
            if ($rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false) {
                    $eventDate = date('Y-m-d H:i:s', $ts);
                }
            }

            Database::execute(
                "UPDATE events 
                 SET title = ?, description = ?, event_date = ?, location = ?, access_level = ?, status = ?
                 WHERE id = ?",
                [$title, $description, $eventDate, $location, $accessLevel, $status, $eventId]
            );
            AuditLogger::log('UPDATE', 'events', $eventId, $existing, ['title' => $title, 'status' => $status]);
            Session::flash('success', "Event '{$title}' updated successfully.");
            header('Location: /admin/events.php');
            exit;
        }
    } elseif ($action === 'set_status') {
        $eventId   = (int)($_POST['event_id'] ?? 0);
        $newStatus = Sanitize::inArray($_POST['status'] ?? '', ['upcoming', 'ongoing', 'completed', 'cancelled']);
        
        if ($eventId > 0 && $newStatus) {
            $existing = Database::fetchOne("SELECT id, title, status FROM events WHERE id = ?", [$eventId]);
            if ($existing) {
                Database::execute("UPDATE events SET status = ? WHERE id = ?", [$newStatus, $eventId]);
                AuditLogger::log('STATUS_CHANGE', 'events', $eventId, ['status' => $existing['status']], ['status' => $newStatus]);
                Session::flash('success', "Event status changed to " . ucfirst($newStatus) . ".");
            }
        }
        header('Location: /admin/events.php');
        exit;
    } elseif ($action === 'delete') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $existing = Database::fetchOne("SELECT * FROM events WHERE id = ?", [$eventId]);
        if ($existing) {
            Database::execute("DELETE FROM events WHERE id = ?", [$eventId]);
            AuditLogger::log('DELETE', 'events', $eventId, $existing, null);
            Session::flash('success', "Event '{$existing['title']}' deleted successfully.");
        }
        header('Location: /admin/events.php');
        exit;
    }
}

// ─── Filter & Search ─────────────────────────────────────────────────────────
$search       = Sanitize::string($_GET['search'] ?? '', 100);
$statusFilter = Sanitize::inArray($_GET['status'] ?? '', ['upcoming', 'ongoing', 'completed', 'cancelled']) ?: '';
$accessFilter = Sanitize::inArray($_GET['access'] ?? '', ['public', 'member', 'officer', 'admin']) ?: '';

$where   = ["1=1"];
$params  = [];

if ($search !== '') {
    $where[] = "(e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($statusFilter !== '') {
    $where[] = "e.status = ?";
    $params[] = $statusFilter;
}
if ($accessFilter !== '') {
    $where[] = "e.access_level = ?";
    $params[] = $accessFilter;
}

$whereSql = implode(' AND ', $where);

$events = Database::fetchAll(
    "SELECT e.*, u.username AS creator_name 
     FROM events e 
     LEFT JOIN users u ON u.id = e.created_by 
     WHERE {$whereSql}
     ORDER BY e.event_date DESC",
    $params
);

// Statistics counts
$totalEvents     = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM events")['total'] ?? 0);
$upcomingCount   = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM events WHERE status = 'upcoming'")['total'] ?? 0);
$ongoingCount    = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM events WHERE status = 'ongoing'")['total'] ?? 0);
$completedCount  = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM events WHERE status = 'completed'")['total'] ?? 0);

$pageTitle   = 'Events Management';
$activeMenu  = 'events';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Events', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Events Management</h1>
        <p class="page-heading-subtitle">Association general bodies, summits, and official state gatherings</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/events.php" target="_blank" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            Public View
        </a>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="openAddModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Schedule Event
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     METRIC SUMMARY CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Total Events</span>
            <span class="stat-number"><?= number_format($totalEvents) ?></span>
            <span class="stat-subtext">All time scheduled</span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Upcoming</span>
            <span class="stat-number" style="color:var(--blue-600);"><?= number_format($upcomingCount) ?></span>
            <span class="stat-subtext">Scheduled ahead</span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Ongoing Today</span>
            <span class="stat-number" style="color:var(--success-dark);"><?= number_format($ongoingCount) ?></span>
            <span class="stat-subtext">In progress</span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Completed</span>
            <span class="stat-number" style="color:var(--text-muted);"><?= number_format($completedCount) ?></span>
            <span class="stat-subtext">Concluded assemblies</span>
        </div>
        <div class="stat-icon-box stat-icon-slate">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTER / SEARCH BAR
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="padding:16px 20px; margin-bottom:20px;">
    <form method="get" action="/admin/events.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div style="flex:1; min-width:200px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Search Event</label>
            <input type="text" name="search" value="<?= Sanitize::html($search) ?>" class="form-control" placeholder="Search by title, location, description...">
        </div>
        <div style="min-width:140px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="upcoming" <?= $statusFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="ongoing" <?= $statusFilter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div style="min-width:140px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Access</label>
            <select name="access" class="form-select">
                <option value="">All Access</option>
                <option value="public" <?= $accessFilter === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="member" <?= $accessFilter === 'member' ? 'selected' : '' ?>>Member</option>
                <option value="officer" <?= $accessFilter === 'officer' ? 'selected' : '' ?>>Officer</option>
                <option value="admin" <?= $accessFilter === 'admin' ? 'selected' : '' ?>>Admin Only</option>
            </select>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Filter</button>
            <?php if ($search !== '' || $statusFilter !== '' || $accessFilter !== ''): ?>
                <a href="/admin/events.php" class="btn btn-outline" style="padding:9px 14px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     EVENTS TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
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
                    <th style="width:50px;">Sl</th>
                    <th>Event Details</th>
                    <th>Date &amp; Time</th>
                    <th>Location</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No events found matching your criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($events as $ev): 
                        $status = $ev['status'] ?? 'upcoming';
                        $badgeStyle = 'badge-neutral';
                        if ($status === 'upcoming') $badgeStyle = 'badge-blue';
                        elseif ($status === 'ongoing') $badgeStyle = 'badge-success';
                        elseif ($status === 'completed') $badgeStyle = 'badge-neutral';
                        elseif ($status === 'cancelled') $badgeStyle = 'badge-danger';
                    ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <div style="font-weight:700; color:var(--text-main); font-size:0.95rem;">
                                <?= Sanitize::html($ev['title']) ?>
                            </div>
                            <?php if (!empty($ev['description'])): ?>
                            <div style="color:var(--text-muted); font-size:0.8rem; margin-top:2px; max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= Sanitize::html($ev['description']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:0.88rem;">
                                <?= $ev['event_date'] ? date('d M Y', strtotime($ev['event_date'])) : '—' ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">
                                <?= $ev['event_date'] ? date('h:i A', strtotime($ev['event_date'])) : '' ?>
                            </div>
                        </td>
                        <td>
                            <?= Sanitize::html($ev['location'] ?? '—') ?>
                        </td>
                        <td>
                            <span class="badge badge-neutral" style="text-transform:capitalize;">
                                <?= Sanitize::html($ev['access_level']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $badgeStyle ?>" style="text-transform:capitalize;">
                                <?= Sanitize::html($ev['status']) ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button type="button" class="btn btn-outline btn-sm" onclick='viewEventDetails(<?= json_encode($ev, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    View
                                </button>
                                <?php if ($canManage): ?>
                                <button type="button" class="btn btn-outline btn-sm" onclick='editEvent(<?= json_encode($ev, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    Edit
                                </button>
                                <form method="post" action="/admin/events.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger-dark); border-color:var(--danger-light);">
                                        ✕
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     EVENT MODALS (Add / Edit / View)
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($canManage): ?>
<!-- Add / Edit Modal -->
<div id="eventModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:620px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="modalTitle" style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Schedule New Event</h3>
            <button type="button" onclick="closeModal('eventModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <form method="post" action="/admin/events.php" style="padding:24px; overflow-y:auto;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="event_id" id="formEventId" value="">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Event Title *</label>
                    <input type="text" name="title" id="formTitle" class="form-control" required maxlength="300" placeholder="e.g. State Executive Committee Summit 2026">
                </div>
                <div class="form-group">
                    <label class="form-label">Event Date &amp; Time *</label>
                    <input type="datetime-local" name="event_date" id="formDate" class="form-control" required value="<?= date('Y-m-d\T10:00') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Location / Platform</label>
                    <input type="text" name="location" id="formLocation" class="form-control" placeholder="e.g. Town Hall, Bengaluru">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Level</label>
                    <select name="access_level" id="formAccess" class="form-select">
                        <option value="member">Member</option>
                        <option value="public">Public</option>
                        <option value="officer">Officer</option>
                        <option value="admin">Admin Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Event Status</label>
                    <select name="status" id="formStatus" class="form-select">
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Description &amp; Agenda</label>
                    <textarea name="description" id="formDescription" rows="4" class="form-control" placeholder="Details, guidelines, and schedule points..."></textarea>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('eventModal')">Cancel</button>
                <button type="submit" id="submitBtn" class="btn btn-primary">Save Event</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- View Event Modal -->
<div id="viewEventModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:580px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Event Details</h3>
            <button type="button" onclick="closeModal('viewEventModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <div style="padding:24px;">
            <div style="margin-bottom:16px;">
                <span id="viewStatusBadge" class="badge badge-blue" style="text-transform:capitalize;">Upcoming</span>
                <span id="viewAccessBadge" class="badge badge-neutral" style="text-transform:capitalize; margin-left:6px;">Member</span>
            </div>
            <h2 id="viewTitle" style="font-size:1.25rem; font-weight:700; color:var(--blue-900); margin:0 0 12px 0;"></h2>
            
            <div style="background:#f8fafc; border-radius:8px; padding:14px; margin-bottom:16px; font-size:0.9rem; line-height:1.6;">
                <div><strong>📅 Date &amp; Time:</strong> <span id="viewDate"></span></div>
                <div style="margin-top:4px;"><strong>📍 Location:</strong> <span id="viewLocation"></span></div>
                <div style="margin-top:4px;"><strong>👤 Scheduled By:</strong> <span id="viewCreator"></span></div>
            </div>

            <div>
                <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Description</label>
                <div id="viewDesc" style="margin-top:6px; font-size:0.9rem; color:var(--text-main); white-space:pre-wrap; line-height:1.5;"></div>
            </div>

            <div style="margin-top:24px; text-align:right;">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewEventModal')">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formEventId').value = '';
    document.getElementById('modalTitle').textContent = 'Schedule New Event';
    document.getElementById('submitBtn').textContent = 'Save Event';
    document.getElementById('formTitle').value = '';
    document.getElementById('formLocation').value = '';
    document.getElementById('formDescription').value = '';
    document.getElementById('formAccess').value = 'member';
    document.getElementById('formStatus').value = 'upcoming';
    const modal = document.getElementById('eventModal');
    modal.style.display = 'flex';
}

function editEvent(ev) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formEventId').value = ev.id;
    document.getElementById('modalTitle').textContent = 'Edit Event';
    document.getElementById('submitBtn').textContent = 'Update Event';
    document.getElementById('formTitle').value = ev.title || '';
    document.getElementById('formLocation').value = ev.location || '';
    document.getElementById('formDescription').value = ev.description || '';
    document.getElementById('formAccess').value = ev.access_level || 'member';
    document.getElementById('formStatus').value = ev.status || 'upcoming';
    
    if (ev.event_date) {
        const dt = new Date(ev.event_date.replace(' ', 'T'));
        if (!isNaN(dt.getTime())) {
            const year = dt.getFullYear();
            const month = String(dt.getMonth() + 1).padStart(2, '0');
            const day = String(dt.getDate()).padStart(2, '0');
            const hours = String(dt.getHours()).padStart(2, '0');
            const mins = String(dt.getMinutes()).padStart(2, '0');
            document.getElementById('formDate').value = `${year}-${month}-${day}T${hours}:${mins}`;
        }
    }
    
    const modal = document.getElementById('eventModal');
    modal.style.display = 'flex';
}

function viewEventDetails(ev) {
    document.getElementById('viewTitle').textContent = ev.title || 'Event Details';
    document.getElementById('viewLocation').textContent = ev.location || '—';
    document.getElementById('viewCreator').textContent = ev.creator_name || 'Officer';
    document.getElementById('viewDesc').textContent = ev.description || 'No detailed agenda provided.';
    
    if (ev.event_date) {
        const d = new Date(ev.event_date.replace(' ', 'T'));
        document.getElementById('viewDate').textContent = d.toLocaleDateString('en-IN', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true
        });
    } else {
        document.getElementById('viewDate').textContent = '—';
    }

    const stBadge = document.getElementById('viewStatusBadge');
    stBadge.textContent = (ev.status || 'upcoming').toUpperCase();
    stBadge.className = 'badge ' + (ev.status === 'upcoming' ? 'badge-blue' : (ev.status === 'ongoing' ? 'badge-success' : (ev.status === 'completed' ? 'badge-neutral' : 'badge-danger')));

    document.getElementById('viewAccessBadge').textContent = (ev.access_level || 'member').toUpperCase();

    const modal = document.getElementById('viewEventModal');
    modal.style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close on outside click
window.onclick = function(event) {
    const eModal = document.getElementById('eventModal');
    const vModal = document.getElementById('viewEventModal');
    if (eModal && event.target === eModal) eModal.style.display = 'none';
    if (vModal && event.target === vModal) vModal.style.display = 'none';
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
