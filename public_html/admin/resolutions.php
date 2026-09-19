<?php
/**
 * KSPDOWA — Admin: Resolutions Management
 * ============================================================
 * Official Association Resolutions repository, meeting linkages,
 * status tracking (Passed, Rejected, Deferred), and text archival.
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

// ─── POST Handler ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $meetingId    = (int)($_POST['meeting_id'] ?? 0);
        $resolutionNo = Sanitize::string($_POST['resolution_no'] ?? '', 100);
        $title        = Sanitize::string($_POST['title'] ?? '', 300);
        $content      = Sanitize::string($_POST['content'] ?? '', 20000);
        $status       = Sanitize::inArray($_POST['status'] ?? 'passed', ['passed', 'rejected', 'deferred']) ?: 'passed';

        if ($meetingId <= 0 || $title === '') {
            Session::flash('error', 'Meeting selection and resolution title are required.');
        } else {
            if ($resolutionNo === '') {
                $year = date('Y');
                $count = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions WHERE YEAR(created_at) = ?", [$year])['total'] ?? 0) + 1;
                $resolutionNo = sprintf("RES-%s/%02d", $year, $count);
            }

            Database::execute(
                "INSERT INTO resolutions (meeting_id, resolution_no, title, content, status)
                 VALUES (?, ?, ?, ?, ?)",
                [$meetingId, $resolutionNo, $title, $content, $status]
            );
            $resId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'resolutions', $resId, null, ['resolution_no' => $resolutionNo, 'title' => $title]);
            Session::flash('success', "Resolution '{$resolutionNo}' created successfully.");
            header('Location: /admin/resolutions.php');
            exit;
        }
    } elseif ($action === 'update') {
        $resId        = (int)($_POST['resolution_id'] ?? 0);
        $meetingId    = (int)($_POST['meeting_id'] ?? 0);
        $resolutionNo = Sanitize::string($_POST['resolution_no'] ?? '', 100);
        $title        = Sanitize::string($_POST['title'] ?? '', 300);
        $content      = Sanitize::string($_POST['content'] ?? '', 20000);
        $status       = Sanitize::inArray($_POST['status'] ?? 'passed', ['passed', 'rejected', 'deferred']) ?: 'passed';

        $existing = Database::fetchOne("SELECT * FROM resolutions WHERE id = ?", [$resId]);
        if (!$existing) {
            Session::flash('error', 'Resolution record not found.');
        } elseif ($meetingId <= 0 || $title === '') {
            Session::flash('error', 'Meeting selection and resolution title cannot be empty.');
        } else {
            Database::execute(
                "UPDATE resolutions 
                 SET meeting_id = ?, resolution_no = ?, title = ?, content = ?, status = ?
                 WHERE id = ?",
                [$meetingId, $resolutionNo, $title, $content, $status, $resId]
            );
            AuditLogger::log('UPDATE', 'resolutions', $resId, $existing, ['resolution_no' => $resolutionNo, 'status' => $status]);
            Session::flash('success', "Resolution '{$resolutionNo}' updated successfully.");
            header('Location: /admin/resolutions.php');
            exit;
        }
    } elseif ($action === 'set_status') {
        $resId     = (int)($_POST['resolution_id'] ?? 0);
        $newStatus = Sanitize::inArray($_POST['status'] ?? '', ['passed', 'rejected', 'deferred']);

        if ($resId > 0 && $newStatus) {
            $existing = Database::fetchOne("SELECT id, resolution_no, status FROM resolutions WHERE id = ?", [$resId]);
            if ($existing) {
                Database::execute("UPDATE resolutions SET status = ? WHERE id = ?", [$newStatus, $resId]);
                AuditLogger::log('STATUS_CHANGE', 'resolutions', $resId, ['status' => $existing['status']], ['status' => $newStatus]);
                Session::flash('success', "Resolution '{$existing['resolution_no']}' status updated to " . ucfirst($newStatus) . ".");
            }
        }
        header('Location: /admin/resolutions.php');
        exit;
    } elseif ($action === 'delete') {
        $resId = (int)($_POST['resolution_id'] ?? 0);
        $existing = Database::fetchOne("SELECT * FROM resolutions WHERE id = ?", [$resId]);
        if ($existing) {
            Database::execute("DELETE FROM resolutions WHERE id = ?", [$resId]);
            AuditLogger::log('DELETE', 'resolutions', $resId, $existing, null);
            Session::flash('success', "Resolution '{$existing['resolution_no']}' deleted successfully.");
        }
        header('Location: /admin/resolutions.php');
        exit;
    }
}

// ─── Filter & Search ─────────────────────────────────────────────────────────
$search        = Sanitize::string($_GET['search'] ?? '', 100);
$statusFilter  = Sanitize::inArray($_GET['status'] ?? '', ['passed', 'rejected', 'deferred']) ?: '';
$meetingFilter = !empty($_GET['meeting_id']) ? (int)$_GET['meeting_id'] : 0;

$where   = ["1=1"];
$params  = [];

if ($search !== '') {
    $where[] = "(r.resolution_no LIKE ? OR r.title LIKE ? OR r.content LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($statusFilter !== '') {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}
if ($meetingFilter > 0) {
    $where[] = "r.meeting_id = ?";
    $params[] = $meetingFilter;
}

$whereSql = implode(' AND ', $where);

$resolutions = Database::fetchAll(
    "SELECT r.*, m.title AS meeting_title, m.meeting_date, m.location AS meeting_location
     FROM resolutions r
     LEFT JOIN meetings m ON m.id = r.meeting_id
     WHERE {$whereSql}
     ORDER BY r.created_at DESC, r.id DESC",
    $params
);

// Meetings list for dropdown
$meetingsList = Database::fetchAll("SELECT id, title, meeting_date FROM meetings ORDER BY meeting_date DESC LIMIT 100");

// Status metrics
$totalResolutions = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions")['total'] ?? 0);
$passedCount      = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions WHERE status = 'passed'")['total'] ?? 0);
$deferredCount    = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions WHERE status = 'deferred'")['total'] ?? 0);
$rejectedCount    = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions WHERE status = 'rejected'")['total'] ?? 0);

$pageTitle   = 'Resolutions Register';
$activeMenu  = 'resolutions';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Meetings', 'url' => '/admin/meetings.php'],
    ['label' => 'Resolutions', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Association Resolutions Register</h1>
        <p class="page-heading-subtitle">Official policy decisions, member welfare mandates, and general body resolutions</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/meetings.php" class="btn btn-outline">
            ← Back to Meetings
        </a>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="openAddResolutionModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Adopt New Resolution
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
            <span class="stat-label">Total Resolutions</span>
            <span class="stat-number"><?= number_format($totalResolutions) ?></span>
            <span class="stat-subtext">Archived in official register</span>
        </div>
        <div class="stat-icon-box stat-icon-purple">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Passed / Adopted</span>
            <span class="stat-number" style="color:var(--success-dark);"><?= number_format($passedCount) ?></span>
            <span class="stat-subtext">Officially enacted</span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Deferred</span>
            <span class="stat-number" style="color:var(--warning-dark);"><?= number_format($deferredCount) ?></span>
            <span class="stat-subtext">Tabled for next meeting</span>
        </div>
        <div class="stat-icon-box stat-icon-orange">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Rejected</span>
            <span class="stat-number" style="color:var(--danger-dark);"><?= number_format($rejectedCount) ?></span>
            <span class="stat-subtext">Not approved</span>
        </div>
        <div class="stat-icon-box stat-icon-slate">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTER BAR
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="padding:16px 20px; margin-bottom:20px;">
    <form method="get" action="/admin/resolutions.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div style="flex:1; min-width:200px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Search</label>
            <input type="text" name="search" value="<?= Sanitize::html($search) ?>" class="form-control" placeholder="Search by resolution no, subject, clause...">
        </div>
        <div style="min-width:180px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Meeting Linkage</label>
            <select name="meeting_id" class="form-select">
                <option value="">All Meetings</option>
                <?php foreach ($meetingsList as $mt): ?>
                    <option value="<?= (int)$mt['id'] ?>" <?= $meetingFilter === (int)$mt['id'] ? 'selected' : '' ?>>
                        <?= Sanitize::html($mt['title']) ?> (<?= date('M Y', strtotime($mt['meeting_date'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:130px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="passed" <?= $statusFilter === 'passed' ? 'selected' : '' ?>>Passed</option>
                <option value="deferred" <?= $statusFilter === 'deferred' ? 'selected' : '' ?>>Deferred</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Filter</button>
            <?php if ($search !== '' || $statusFilter !== '' || $meetingFilter > 0): ?>
                <a href="/admin/resolutions.php" class="btn btn-outline" style="padding:9px 14px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RESOLUTIONS TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Adopted &amp; Tabled Resolutions</span>
            <span class="table-card-count">(<?= count($resolutions) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:50px;">Sl</th>
                    <th>Resolution No</th>
                    <th>Subject / Title</th>
                    <th>Meeting Linkage</th>
                    <th>Status</th>
                    <th>Enacted Date</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resolutions)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No resolutions recorded matching your search.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($resolutions as $res): 
                        $statusBadge = 'badge-success';
                        if ($res['status'] === 'deferred') $statusBadge = 'badge-warning';
                        elseif ($res['status'] === 'rejected') $statusBadge = 'badge-danger';
                    ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <code style="font-weight:700; font-size:0.85rem; color:var(--purple-700); background:#f5f3ff; padding:2px 6px; border-radius:4px;">
                                <?= Sanitize::html($res['resolution_no']) ?>
                            </code>
                        </td>
                        <td>
                            <div style="font-weight:700; color:var(--text-main); font-size:0.92rem;">
                                <?= Sanitize::html($res['title']) ?>
                            </div>
                            <?php if (!empty($res['content'])): ?>
                            <div style="color:var(--text-muted); font-size:0.78rem; margin-top:2px; max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= Sanitize::html($res['content']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:0.85rem; color:var(--text-main);">
                                <?= Sanitize::html($res['meeting_title'] ?? 'General Meeting') ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">
                                <?= $res['meeting_date'] ? date('d M Y', strtotime($res['meeting_date'])) : '—' ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge ?>" style="text-transform:capitalize;">
                                <?= Sanitize::html($res['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?= date('d M Y', strtotime($res['created_at'])) ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button type="button" class="btn btn-outline btn-sm" onclick='viewResolution(<?= json_encode($res, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    View
                                </button>
                                <?php if ($canManage): ?>
                                <button type="button" class="btn btn-outline btn-sm" onclick='editResolution(<?= json_encode($res, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    Edit
                                </button>
                                <form method="post" action="/admin/resolutions.php" style="display:inline;" onsubmit="return confirm('Delete this resolution record?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="resolution_id" value="<?= (int)$res['id'] ?>">
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
     MODALS (Add/Edit & View Resolution)
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($canManage): ?>
<div id="resFormModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:620px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="resModalTitle" style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main);">Adopt New Resolution</h3>
            <button type="button" onclick="closeModal('resFormModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <form method="post" action="/admin/resolutions.php" style="padding:24px; overflow-y:auto;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" id="rAction" value="create">
            <input type="hidden" name="resolution_id" id="rId" value="">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Linked Meeting *</label>
                <select name="meeting_id" id="rMeetingId" class="form-select" required>
                    <option value="">— Select Meeting —</option>
                    <?php foreach ($meetingsList as $mt): ?>
                        <option value="<?= (int)$mt['id'] ?>">
                            <?= Sanitize::html($mt['title']) ?> (<?= date('d M Y', strtotime($mt['meeting_date'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label">Resolution No.</label>
                    <input type="text" name="resolution_no" id="rNo" class="form-control" placeholder="e.g. RES-2026/01">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="rStatus" class="form-select">
                        <option value="passed">Passed (ಅಂಗೀಕರಿಸಲಾಗಿದೆ)</option>
                        <option value="deferred">Deferred (ಮುಂದೂಡಲಾಗಿದೆ)</option>
                        <option value="rejected">Rejected (ತಿರಸ್ಕರಿಸಲಾಗಿದೆ)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Resolution Subject / Title *</label>
                <input type="text" name="title" id="rTitle" class="form-control" required placeholder="Subject of the resolution...">
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Resolution Text / Clauses *</label>
                <textarea name="content" id="rContent" rows="6" class="form-control" required placeholder="Complete text of the resolution passed by the committee..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('resFormModal')">Cancel</button>
                <button type="submit" id="rSubmitBtn" class="btn btn-primary">Save Resolution</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- View Resolution Modal -->
<div id="viewResModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:12px; max-width:620px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="padding:16px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span id="vrStatusBadge" class="badge badge-success">Passed</span>
                <span id="vrNo" style="font-weight:700; color:var(--purple-700); margin-left:8px;"></span>
            </div>
            <button type="button" onclick="closeModal('viewResModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">✕</button>
        </div>
        <div style="padding:24px;">
            <h2 id="vrTitle" style="font-size:1.2rem; font-weight:700; color:var(--text-main); margin:0 0 12px 0;"></h2>
            
            <div style="background:#f8fafc; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-size:0.85rem; line-height:1.6;">
                <div><strong>🏛️ Enacted At:</strong> <span id="vrMeeting"></span></div>
                <div><strong>📅 Date Adopted:</strong> <span id="vrDate"></span></div>
            </div>

            <div>
                <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Resolution Clauses</label>
                <div id="vrContent" style="margin-top:6px; font-size:0.92rem; color:var(--text-main); white-space:pre-wrap; line-height:1.6; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:14px;"></div>
            </div>

            <div style="margin-top:20px; text-align:right;">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewResModal')">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openAddResolutionModal() {
    document.getElementById('rAction').value = 'create';
    document.getElementById('rId').value = '';
    document.getElementById('resModalTitle').textContent = 'Adopt New Resolution';
    document.getElementById('rSubmitBtn').textContent = 'Adopt Resolution';
    document.getElementById('rMeetingId').value = '';
    document.getElementById('rNo').value = '';
    document.getElementById('rTitle').value = '';
    document.getElementById('rContent').value = '';
    document.getElementById('rStatus').value = 'passed';
    document.getElementById('resFormModal').style.display = 'flex';
}

function editResolution(res) {
    document.getElementById('rAction').value = 'update';
    document.getElementById('rId').value = res.id;
    document.getElementById('resModalTitle').textContent = 'Edit Resolution Record';
    document.getElementById('rSubmitBtn').textContent = 'Update Resolution';
    document.getElementById('rMeetingId').value = res.meeting_id || '';
    document.getElementById('rNo').value = res.resolution_no || '';
    document.getElementById('rTitle').value = res.title || '';
    document.getElementById('rContent').value = res.content || '';
    document.getElementById('rStatus').value = res.status || 'passed';
    document.getElementById('resFormModal').style.display = 'flex';
}

function viewResolution(res) {
    document.getElementById('vrNo').textContent = res.resolution_no || '';
    document.getElementById('vrTitle').textContent = res.title || '';
    document.getElementById('vrMeeting').textContent = (res.meeting_title || 'General Meeting') + ' (' + (res.meeting_location || 'Karnataka') + ')';
    document.getElementById('vrDate').textContent = res.meeting_date ? res.meeting_date.substring(0,10) : res.created_at.substring(0,10);
    document.getElementById('vrContent').textContent = res.content || 'No text recorded.';

    const st = document.getElementById('vrStatusBadge');
    st.textContent = (res.status || 'passed').toUpperCase();
    st.className = 'badge ' + (res.status === 'passed' ? 'badge-success' : (res.status === 'deferred' ? 'badge-warning' : 'badge-danger'));

    document.getElementById('viewResModal').style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

window.onclick = function(event) {
    ['resFormModal', 'viewResModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && event.target === m) m.style.display = 'none';
    });
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
