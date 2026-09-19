<?php
/**
 * KSPDOWA — Admin: Meetings Management
 * ============================================================
 * State Executive Committee, District Councils, and General
 * Assemblies meeting register, minutes approval workflow,
 * document attachments, and resolution linkages.
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

// ─── POST Action Handler ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    // 1. Create Meeting
    if ($action === 'create') {
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $rawDate     = Sanitize::string($_POST['meeting_date'] ?? '', 30);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'officer', ['public', 'member', 'officer', 'admin']) ?: 'officer';
        $agenda      = Sanitize::string($_POST['agenda'] ?? '', 5000);

        if ($title === '') {
            Session::flash('error', 'Meeting title is required.');
        } else {
            $meetingDate = date('Y-m-d H:i:s');
            if ($rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false) {
                    $meetingDate = date('Y-m-d H:i:s', $ts);
                }
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

    // 2. Update Meeting
    elseif ($action === 'update') {
        $meetId      = (int)($_POST['meeting_id'] ?? 0);
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $rawDate     = Sanitize::string($_POST['meeting_date'] ?? '', 30);
        $location    = Sanitize::string($_POST['location'] ?? '', 300);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'officer', ['public', 'member', 'officer', 'admin']) ?: 'officer';
        $agenda      = Sanitize::string($_POST['agenda'] ?? '', 5000);

        $existing = Database::fetchOne("SELECT * FROM meetings WHERE id = ?", [$meetId]);
        if (!$existing) {
            Session::flash('error', 'Meeting record not found.');
        } elseif ($title === '') {
            Session::flash('error', 'Meeting title cannot be empty.');
        } else {
            $meetingDate = $existing['meeting_date'];
            if ($rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false) {
                    $meetingDate = date('Y-m-d H:i:s', $ts);
                }
            }

            Database::execute(
                "UPDATE meetings 
                 SET title = ?, meeting_date = ?, location = ?, agenda = ?, access_level = ?
                 WHERE id = ?",
                [$title, $meetingDate, $location, $agenda, $accessLevel, $meetId]
            );
            AuditLogger::log('UPDATE', 'meetings', $meetId, $existing, ['title' => $title]);
            Session::flash('success', "Meeting '{$title}' updated successfully.");
            header('Location: /admin/meetings.php');
            exit;
        }
    }

    // 3. Delete Meeting
    elseif ($action === 'delete') {
        $meetId = (int)($_POST['meeting_id'] ?? 0);
        $existing = Database::fetchOne("SELECT * FROM meetings WHERE id = ?", [$meetId]);
        if ($existing) {
            // Delete associated resolutions and minutes
            Database::execute("DELETE FROM resolutions WHERE meeting_id = ?", [$meetId]);
            Database::execute("DELETE FROM meeting_minutes WHERE meeting_id = ?", [$meetId]);
            Database::execute("DELETE FROM meetings WHERE id = ?", [$meetId]);
            AuditLogger::log('DELETE', 'meetings', $meetId, $existing, null);
            Session::flash('success', "Meeting '{$existing['title']}' deleted successfully.");
        }
        header('Location: /admin/meetings.php');
        exit;
    }

    // 4. Save / Update Meeting Minutes
    elseif ($action === 'save_minutes') {
        $meetId     = (int)($_POST['meeting_id'] ?? 0);
        $content    = Sanitize::string($_POST['content'] ?? '', 50000);
        $documentId = !empty($_POST['document_id']) ? (int)$_POST['document_id'] : null;
        $approveNow = !empty($_POST['approve_now']);

        $meeting = Database::fetchOne("SELECT id, title FROM meetings WHERE id = ?", [$meetId]);
        if (!$meeting) {
            Session::flash('error', 'Meeting not found.');
        } elseif ($content === '') {
            Session::flash('error', 'Meeting minutes text is required.');
        } else {
            $existingMinutes = Database::fetchOne("SELECT * FROM meeting_minutes WHERE meeting_id = ?", [$meetId]);
            $approvedBy = null;
            $approvedAt = null;

            if ($approveNow) {
                $approvedBy = $currentUserId;
                $approvedAt = date('Y-m-d H:i:s');
            } elseif ($existingMinutes && $existingMinutes['approved_at']) {
                $approvedBy = $existingMinutes['approved_by'];
                $approvedAt = $existingMinutes['approved_at'];
            }

            if ($existingMinutes) {
                Database::execute(
                    "UPDATE meeting_minutes 
                     SET content = ?, document_id = ?, approved_by = ?, approved_at = ?
                     WHERE id = ?",
                    [$content, $documentId, $approvedBy, $approvedAt, (int)$existingMinutes['id']]
                );
                AuditLogger::log('UPDATE', 'meeting_minutes', (int)$existingMinutes['id'], $existingMinutes, ['content' => substr($content, 0, 100)]);
            } else {
                Database::execute(
                    "INSERT INTO meeting_minutes (meeting_id, content, document_id, approved_by, approved_at)
                     VALUES (?, ?, ?, ?, ?)",
                    [$meetId, $content, $documentId, $approvedBy, $approvedAt]
                );
                $minId = (int)Database::lastInsertId();
                AuditLogger::log('CREATE', 'meeting_minutes', $minId, null, ['meeting_id' => $meetId]);
            }

            $msg = $approveNow ? "Meeting minutes recorded and approved successfully." : "Meeting minutes saved successfully.";
            Session::flash('success', $msg);
            header('Location: /admin/meetings.php');
            exit;
        }
    }

    // 5. Add Resolution Linked to Meeting
    elseif ($action === 'add_resolution') {
        $meetId       = (int)($_POST['meeting_id'] ?? 0);
        $resolutionNo = Sanitize::string($_POST['resolution_no'] ?? '', 100);
        $title        = Sanitize::string($_POST['title'] ?? '', 300);
        $content      = Sanitize::string($_POST['content'] ?? '', 10000);
        $status       = Sanitize::inArray($_POST['status'] ?? 'passed', ['passed', 'rejected', 'deferred']) ?: 'passed';

        if ($meetId <= 0 || $title === '') {
            Session::flash('error', 'Meeting linkage and resolution title are required.');
        } else {
            if ($resolutionNo === '') {
                $year = date('Y');
                $count = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions WHERE YEAR(created_at) = ?", [$year])['total'] ?? 0) + 1;
                $resolutionNo = sprintf("RES-%s/%02d", $year, $count);
            }

            Database::execute(
                "INSERT INTO resolutions (meeting_id, resolution_no, title, content, status)
                 VALUES (?, ?, ?, ?, ?)",
                [$meetId, $resolutionNo, $title, $content, $status]
            );
            $resId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'resolutions', $resId, null, ['resolution_no' => $resolutionNo, 'title' => $title]);
            Session::flash('success', "Resolution '{$resolutionNo}' recorded successfully.");
            header('Location: /admin/meetings.php');
            exit;
        }
    }
}

// ─── Filter & Search ─────────────────────────────────────────────────────────
$search       = Sanitize::string($_GET['search'] ?? '', 100);
$accessFilter = Sanitize::inArray($_GET['access'] ?? '', ['public', 'member', 'officer', 'admin']) ?: '';

$where   = ["1=1"];
$params  = [];

if ($search !== '') {
    $where[] = "(m.title LIKE ? OR m.location LIKE ? OR m.agenda LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($accessFilter !== '') {
    $where[] = "m.access_level = ?";
    $params[] = $accessFilter;
}

$whereSql = implode(' AND ', $where);

// Fetch meetings with minutes status and resolution count
$meetings = Database::fetchAll(
    "SELECT m.*, u.username AS creator_name,
            mm.id AS minutes_id, mm.content AS minutes_content, mm.document_id AS minutes_doc_id,
            mm.approved_by, mm.approved_at,
            au.username AS approver_name,
            doc.title AS doc_title, doc.file_path AS doc_file_path,
            COUNT(r.id) AS resolution_count
     FROM meetings m 
     LEFT JOIN users u ON u.id = m.created_by 
     LEFT JOIN meeting_minutes mm ON mm.meeting_id = m.id
     LEFT JOIN users au ON au.id = mm.approved_by
     LEFT JOIN documents doc ON doc.id = mm.document_id
     LEFT JOIN resolutions r ON r.meeting_id = m.id
     WHERE {$whereSql}
     GROUP BY m.id, mm.id, au.id, doc.id
     ORDER BY m.meeting_date DESC",
    $params
);

// Active official documents for dropdown attachment
$availableDocs = Database::fetchAll(
    "SELECT id, title, document_no FROM documents WHERE status = 'active' ORDER BY title ASC LIMIT 50"
);

// Metrics
$totalMeetings     = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM meetings")['total'] ?? 0);
$approvedMinutes   = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM meeting_minutes WHERE approved_at IS NOT NULL")['total'] ?? 0);
$pendingMinutes    = max(0, $totalMeetings - $approvedMinutes);
$totalResolutions  = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions")['total'] ?? 0);

$pageTitle   = 'Meetings Management';
$activeMenu  = 'meetings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Meetings', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Meetings Management</h1>
        <p class="page-heading-subtitle">State Executive Committee, District Councils, official minutes &amp; resolutions</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/resolutions.php" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            All Resolutions
        </a>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="openAddMeetingModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Record Meeting
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STAT CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Total Meetings</span>
            <span class="stat-number"><?= number_format($totalMeetings) ?></span>
            <span class="stat-subtext">Official conventions recorded</span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Approved Minutes</span>
            <span class="stat-number" style="color:var(--success-dark);"><?= number_format($approvedMinutes) ?></span>
            <span class="stat-subtext">Formally sanctioned</span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Minutes Pending</span>
            <span class="stat-number" style="color:var(--warning-dark);"><?= number_format($pendingMinutes) ?></span>
            <span class="stat-subtext">Drafts / awaiting approval</span>
        </div>
        <div class="stat-icon-box stat-icon-orange">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Adopted Resolutions</span>
            <span class="stat-number" style="color:var(--purple-700);"><?= number_format($totalResolutions) ?></span>
            <span class="stat-subtext">Official resolutions passed</span>
        </div>
        <div class="stat-icon-box stat-icon-purple">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTER BAR
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="padding:16px 20px; margin-bottom:20px;">
    <form method="get" action="/admin/meetings.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div style="flex:1; min-width:220px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Search Meeting</label>
            <input type="text" name="search" value="<?= Sanitize::html($search) ?>" class="form-control" placeholder="Search by title, location, agenda...">
        </div>
        <div style="min-width:150px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Access Level</label>
            <select name="access" class="form-select">
                <option value="">All Access</option>
                <option value="officer" <?= $accessFilter === 'officer' ? 'selected' : '' ?>>Officer Level</option>
                <option value="member" <?= $accessFilter === 'member' ? 'selected' : '' ?>>Member</option>
                <option value="public" <?= $accessFilter === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="admin" <?= $accessFilter === 'admin' ? 'selected' : '' ?>>Admin Only</option>
            </select>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Filter</button>
            <?php if ($search !== '' || $accessFilter !== ''): ?>
                <a href="/admin/meetings.php" class="btn btn-outline" style="padding:9px 14px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MEETINGS REGISTER TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
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
                    <th style="width:50px;">Sl</th>
                    <th>Meeting Details</th>
                    <th>Date &amp; Time</th>
                    <th>Location</th>
                    <th>Access</th>
                    <th>Minutes Status</th>
                    <th>Resolutions</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($meetings)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No meetings recorded yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($meetings as $mt): 
                        $hasMinutes = !empty($mt['minutes_id']);
                        $isApproved = !empty($mt['approved_at']);
                    ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <div style="font-weight:700; color:var(--text-main); font-size:0.95rem;">
                                <?= Sanitize::html($mt['title']) ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                Recorded by <?= Sanitize::html($mt['creator_name'] ?? 'Officer') ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:0.88rem;">
                                <?= $mt['meeting_date'] ? date('d M Y', strtotime($mt['meeting_date'])) : '—' ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">
                                <?= $mt['meeting_date'] ? date('h:i A', strtotime($mt['meeting_date'])) : '' ?>
                            </div>
                        </td>
                        <td><?= Sanitize::html($mt['location'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-neutral" style="text-transform:capitalize;">
                                <?= Sanitize::html($mt['access_level']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isApproved): ?>
                                <span class="badge badge-success" title="Approved by <?= Sanitize::html($mt['approver_name'] ?? 'Officer') ?> on <?= date('d M Y', strtotime($mt['approved_at'])) ?>">
                                    ✓ Approved
                                </span>
                            <?php elseif ($hasMinutes): ?>
                                <span class="badge badge-blue">● Draft Minutes</span>
                            <?php else: ?>
                                <span class="badge badge-warning">○ No Minutes</span>
                            <?php endif; ?>
                            
                            <?php if (!empty($mt['minutes_doc_id'])): ?>
                                <span title="Signed Document Attached" style="color:var(--blue-600); vertical-align:-2px; margin-left:4px;">📎</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$mt['resolution_count'] > 0): ?>
                                <a href="/admin/resolutions.php?meeting_id=<?= (int)$mt['id'] ?>" class="badge badge-purple" style="text-decoration:none;">
                                    <?= (int)$mt['resolution_count'] ?> Passed
                                </a>
                            <?php else: ?>
                                <span class="badge badge-neutral">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button type="button" class="btn btn-outline btn-sm" onclick='viewMeetingDetails(<?= json_encode($mt, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    View
                                </button>
                                <?php if ($canManage): ?>
                                <button type="button" class="btn btn-outline btn-sm" style="color:var(--blue-700);" onclick='openMinutesModal(<?= json_encode($mt, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    Minutes
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" style="color:var(--purple-700);" onclick='openAddResolutionModal(<?= (int)$mt['id'] ?>, <?= json_encode($mt['title']) ?>)'>
                                    + Res
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" onclick='editMeeting(<?= json_encode($mt, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    Edit
                                </button>
                                <form method="post" action="/admin/meetings.php" style="display:inline;" onsubmit="return confirm('Delete this meeting and associated minutes/resolutions?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="meeting_id" value="<?= (int)$mt['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger-dark); border-color:var(--danger-light);">✕</button>
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
     MODALS (Create/Edit Meeting, Minutes, Resolution, View Details)
     ═══════════════════════════════════════════════════════════════════════════ -->

<?php if ($canManage): ?>
<!-- Add / Edit Meeting Modal -->
<div id="meetingModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:620px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="meetingModalTitle" style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Record New Meeting</h3>
            <button type="button" onclick="closeModal('meetingModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <form method="post" action="/admin/meetings.php" style="padding:24px; overflow-y:auto;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" id="mFormAction" value="create">
            <input type="hidden" name="meeting_id" id="mFormId" value="">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Meeting Title *</label>
                    <input type="text" name="title" id="mFormTitle" class="form-control" required maxlength="300" placeholder="e.g. State Executive Committee Meeting">
                </div>
                <div class="form-group">
                    <label class="form-label">Date &amp; Time *</label>
                    <input type="datetime-local" name="meeting_date" id="mFormDate" class="form-control" required value="<?= date('Y-m-d\T11:00') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Location / Platform</label>
                    <input type="text" name="location" id="mFormLocation" class="form-control" placeholder="e.g. Association State HQ, Bengaluru">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Level</label>
                    <select name="access_level" id="mFormAccess" class="form-select">
                        <option value="officer">Officer Level</option>
                        <option value="member">Member Level</option>
                        <option value="public">Public</option>
                        <option value="admin">Admin Only</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Agenda &amp; Discussion Points</label>
                    <textarea name="agenda" id="mFormAgenda" rows="4" class="form-control" placeholder="Outline agenda items, resolutions to be tabled..."></textarea>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('meetingModal')">Cancel</button>
                <button type="submit" id="mSubmitBtn" class="btn btn-primary">Save Meeting</button>
            </div>
        </form>
    </div>
</div>

<!-- Meeting Minutes Modal -->
<div id="minutesModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:680px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Meeting Minutes Workflow</h3>
                <div id="minMeetingTitle" style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;"></div>
            </div>
            <button type="button" onclick="closeModal('minutesModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <form method="post" action="/admin/meetings.php" style="padding:24px; overflow-y:auto;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="save_minutes">
            <input type="hidden" name="meeting_id" id="minMeetingId" value="">

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Official Minutes of Proceedings *</label>
                <textarea name="content" id="minContent" rows="8" class="form-control" required placeholder="Type or paste the official minutes, attendee notes, and decisions adopted during this meeting..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Attach Official Document / Signed PDF (Optional)</label>
                <select name="document_id" id="minDocId" class="form-select">
                    <option value="">— No Document Linked —</option>
                    <?php foreach ($availableDocs as $doc): ?>
                        <option value="<?= (int)$doc['id'] ?>">
                            <?= Sanitize::html($doc['title']) ?> (<?= Sanitize::html($doc['document_no'] ?? 'Doc #' . $doc['id']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:0.75rem; color:var(--text-muted); margin-top:4px; display:block;">
                    Link signed and scanned proceedings uploaded in Documents module.
                </span>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:20px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0;">
                    <input type="checkbox" name="approve_now" value="1" id="minApproveCheck" style="width:18px; height:18px; accent-color:var(--success-dark);">
                    <span style="font-size:0.88rem; font-weight:600; color:var(--text-main);">
                        Formally Sanction &amp; Approve Minutes (Affix Officer Stamp)
                    </span>
                </label>
                <div id="minCurrentApprovalNote" style="font-size:0.78rem; color:var(--success-dark); margin-top:6px; display:none;"></div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('minutesModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Minutes</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Resolution Modal -->
<div id="addResModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:600px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Add Meeting Resolution</h3>
                <div id="resMeetingTitle" style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;"></div>
            </div>
            <button type="button" onclick="closeModal('addResModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <form method="post" action="/admin/meetings.php" style="padding:24px;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="add_resolution">
            <input type="hidden" name="meeting_id" id="resMeetingId" value="">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label">Resolution No. (e.g. RES-2026/01)</label>
                    <input type="text" name="resolution_no" class="form-control" placeholder="Auto-generated if blank">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="passed">Passed (ಅಂಗೀಕರಿಸಲಾಗಿದೆ)</option>
                        <option value="deferred">Deferred (ಮುಂದೂಡಲಾಗಿದೆ)</option>
                        <option value="rejected">Rejected (ತಿರಸ್ಕರಿಸಲಾಗಿದೆ)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Resolution Subject / Title *</label>
                <input type="text" name="title" class="form-control" required placeholder="e.g. Enhancement of PDO Grade-1 Promotion Quota">
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Resolution Content / Clauses</label>
                <textarea name="content" rows="4" class="form-control" placeholder="Full resolution text, moved by, seconded by, and terms agreed upon..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('addResModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Adopt Resolution</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- View Meeting Full Details Modal -->
<div id="viewMeetingModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:680px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Meeting Details &amp; Proceedings</h3>
            <button type="button" onclick="closeModal('viewMeetingModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <div style="padding:24px; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <h2 id="vmTitle" style="font-size:1.25rem; font-weight:700; color:var(--blue-900); margin:0;"></h2>
                <span id="vmAccessBadge" class="badge badge-neutral" style="text-transform:capitalize;"></span>
            </div>

            <div style="background:#f8fafc; border-radius:8px; padding:14px; margin-bottom:20px; font-size:0.88rem; line-height:1.6;">
                <div><strong>📅 Date &amp; Time:</strong> <span id="vmDate"></span></div>
                <div><strong>📍 Location:</strong> <span id="vmLocation"></span></div>
                <div><strong>👤 Recorded By:</strong> <span id="vmCreator"></span></div>
            </div>

            <div style="margin-bottom:20px;">
                <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Agenda</label>
                <div id="vmAgenda" style="margin-top:6px; font-size:0.9rem; color:var(--text-main); white-space:pre-wrap; line-height:1.5; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:12px;"></div>
            </div>

            <div style="margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin:0;">Official Minutes</label>
                    <span id="vmMinutesBadge" class="badge badge-neutral"></span>
                </div>
                <div id="vmMinutes" style="font-size:0.9rem; color:var(--text-main); white-space:pre-wrap; line-height:1.5; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:12px;"></div>
                <div id="vmMinutesDocLink" style="margin-top:8px; font-size:0.85rem;"></div>
            </div>

            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewMeetingModal')">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openAddMeetingModal() {
    document.getElementById('mFormAction').value = 'create';
    document.getElementById('mFormId').value = '';
    document.getElementById('meetingModalTitle').textContent = 'Record New Meeting';
    document.getElementById('mSubmitBtn').textContent = 'Save Meeting';
    document.getElementById('mFormTitle').value = '';
    document.getElementById('mFormLocation').value = '';
    document.getElementById('mFormAgenda').value = '';
    document.getElementById('mFormAccess').value = 'officer';
    document.getElementById('meetingModal').style.display = 'flex';
}

function editMeeting(mt) {
    document.getElementById('mFormAction').value = 'update';
    document.getElementById('mFormId').value = mt.id;
    document.getElementById('meetingModalTitle').textContent = 'Edit Meeting Record';
    document.getElementById('mSubmitBtn').textContent = 'Update Meeting';
    document.getElementById('mFormTitle').value = mt.title || '';
    document.getElementById('mFormLocation').value = mt.location || '';
    document.getElementById('mFormAgenda').value = mt.agenda || '';
    document.getElementById('mFormAccess').value = mt.access_level || 'officer';

    if (mt.meeting_date) {
        const dt = new Date(mt.meeting_date.replace(' ', 'T'));
        if (!isNaN(dt.getTime())) {
            const year = dt.getFullYear();
            const month = String(dt.getMonth() + 1).padStart(2, '0');
            const day = String(dt.getDate()).padStart(2, '0');
            const hours = String(dt.getHours()).padStart(2, '0');
            const mins = String(dt.getMinutes()).padStart(2, '0');
            document.getElementById('mFormDate').value = `${year}-${month}-${day}T${hours}:${mins}`;
        }
    }
    document.getElementById('meetingModal').style.display = 'flex';
}

function openMinutesModal(mt) {
    document.getElementById('minMeetingId').value = mt.id;
    document.getElementById('minMeetingTitle').textContent = mt.title + ' (' + (mt.meeting_date ? mt.meeting_date.substring(0,10) : '') + ')';
    document.getElementById('minContent').value = mt.minutes_content || '';
    document.getElementById('minDocId').value = mt.minutes_doc_id || '';
    
    const approveCheck = document.getElementById('minApproveCheck');
    const note = document.getElementById('minCurrentApprovalNote');
    if (mt.approved_at) {
        approveCheck.checked = true;
        note.style.display = 'block';
        note.textContent = '✓ Currently approved by ' + (mt.approver_name || 'Officer') + ' on ' + mt.approved_at;
    } else {
        approveCheck.checked = false;
        note.style.display = 'none';
    }
    document.getElementById('minutesModal').style.display = 'flex';
}

function openAddResolutionModal(meetId, meetTitle) {
    document.getElementById('resMeetingId').value = meetId;
    document.getElementById('resMeetingTitle').textContent = 'Linked to: ' + meetTitle;
    document.getElementById('addResModal').style.display = 'flex';
}

function viewMeetingDetails(mt) {
    document.getElementById('vmTitle').textContent = mt.title || 'Meeting Details';
    document.getElementById('vmLocation').textContent = mt.location || '—';
    document.getElementById('vmCreator').textContent = mt.creator_name || 'Officer';
    document.getElementById('vmAgenda').textContent = mt.agenda || 'No detailed agenda points recorded.';
    document.getElementById('vmAccessBadge').textContent = (mt.access_level || 'officer').toUpperCase();

    if (mt.meeting_date) {
        const d = new Date(mt.meeting_date.replace(' ', 'T'));
        document.getElementById('vmDate').textContent = d.toLocaleDateString('en-IN', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true
        });
    } else {
        document.getElementById('vmDate').textContent = '—';
    }

    const minBadge = document.getElementById('vmMinutesBadge');
    const minContent = document.getElementById('vmMinutes');
    const docLink = document.getElementById('vmMinutesDocLink');

    if (mt.minutes_content) {
        minContent.textContent = mt.minutes_content;
        if (mt.approved_at) {
            minBadge.className = 'badge badge-success';
            minBadge.textContent = '✓ Approved by ' + (mt.approver_name || 'Officer') + ' (' + mt.approved_at.substring(0,10) + ')';
        } else {
            minBadge.className = 'badge badge-blue';
            minBadge.textContent = 'Draft (Unapproved)';
        }
    } else {
        minContent.textContent = 'No minutes recorded for this meeting yet.';
        minBadge.className = 'badge badge-neutral';
        minBadge.textContent = 'No Minutes';
    }

    if (mt.minutes_doc_id && mt.doc_file_path) {
        docLink.innerHTML = '📎 <strong>Attached Document:</strong> <a href="/document.php?id=' + mt.minutes_doc_id + '" target="_blank" style="color:var(--blue-600); font-weight:600;">' + (mt.doc_title || 'View Attached PDF') + ' →</a>';
    } else {
        docLink.innerHTML = '';
    }

    document.getElementById('viewMeetingModal').style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close on background click
window.onclick = function(event) {
    ['meetingModal', 'minutesModal', 'addResModal', 'viewMeetingModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && event.target === m) m.style.display = 'none';
    });
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
