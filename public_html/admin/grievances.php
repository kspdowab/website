<?php
/**
 * KSPDOWA — Admin: Grievances Management
 * ============================================================
 * Gated by RBAC permission 'grievances.view'.
 * Role- and Scope-aware listing of member grievances.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'grievances', 'view');

// Scope check
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;

if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') {
            $lockedDistrictId = (int)$unit['district_id'];
        } elseif ($unit['unit_type'] === 'taluk') {
            $lockedDistrictId = (int)$unit['district_id'];
            $lockedTalukId    = (int)$unit['taluk_id'];
        }
    }
}

$categories = Database::fetchAll("SELECT * FROM grievance_categories WHERE status = 'active' ORDER BY sort_order, name");

// Filters
$qCategory = Sanitize::positiveInt($_GET['category_id'] ?? null);
$qStatus   = trim(Sanitize::string($_GET['status'] ?? 'all', 50));
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));

$whereClauses = ["1=1"];
$params       = [];

if ($lockedTalukId) {
    $whereClauses[] = "m.taluk_id = ?";
    $params[]       = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $whereClauses[] = "m.district_id = ?";
    $params[]       = $lockedDistrictId;
}

if ($qCategory) {
    $whereClauses[] = "g.category_id = ?";
    $params[]       = $qCategory;
}

if ($qStatus !== 'all' && $qStatus !== '') {
    $whereClauses[] = "g.current_status = ?";
    $params[]       = $qStatus;
}

if ($qSearch !== '') {
    $whereClauses[] = "(g.grievance_no LIKE ? OR g.subject LIKE ? OR m.name LIKE ? OR m.member_no LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT g.*, m.name AS member_name, m.member_no, d.name AS district_name, t.name AS taluk_name,
           gc.name AS category_name, gs.name AS service_name
    FROM grievances g
    INNER JOIN members m ON m.id = g.member_id
    LEFT JOIN districts d ON d.id = m.district_id
    LEFT JOIN taluks t    ON t.id = m.taluk_id
    LEFT JOIN grievance_categories gc ON gc.id = g.category_id
    LEFT JOIN grievance_services gs   ON gs.id = g.service_id
    WHERE {$whereSql}
    ORDER BY g.submitted_at DESC, g.id DESC
";
$grievances = Database::fetchAll($sql, $params);

$pageTitle  = 'Grievances Management';
$activeMenu = 'grievances';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Grievances', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Grievances Management</h1>
        <p class="page-heading-subtitle">Scope-aware tracking of PDO grievances, complaints, and service issues</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.86rem; padding:8px 16px;">
            <?= count($grievances) ?> Grievances in Scope
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Grievances
        </div>
        <div class="filter-header-actions">
            <a href="/admin/grievances.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/admin/grievances.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $qCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $qStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Submitted" <?= $qStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="Under Verification" <?= $qStatus === 'Under Verification' ? 'selected' : '' ?>>Under Verification</option>
                    <option value="Pending" <?= $qStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Action Taken" <?= $qStatus === 'Action Taken' ? 'selected' : '' ?>>Action Taken</option>
                    <option value="Resolved" <?= $qStatus === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="Closed" <?= $qStatus === 'Closed' ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Grievance No, Member Name, Subject..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/grievances.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     GRIEVANCES TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Grievances Register</span>
            <span class="table-card-count">(<?= count($grievances) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Grievance No.</th>
                    <th>Member</th>
                    <th>District</th>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Submitted Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grievances)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No grievances found matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($grievances as $g): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:3px 8px; border-radius:4px; font-weight:700;">
                                <?= Sanitize::html($g['grievance_no']) ?>
                            </code>
                        </td>
                        <td style="font-weight:600;">
                            <a href="/admin/member-view.php?id=<?= (int)$g['member_id'] ?>">
                                <?= Sanitize::html($g['member_name']) ?>
                            </a>
                        </td>
                        <td><?= Sanitize::html($g['district_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($g['category_name'] ?? 'General') ?></span>
                        </td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($g['subject']) ?>
                        </td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($g['current_association_level'])) ?></span>
                        </td>
                        <td>
                            <?php
                            $st = strtolower($g['current_status']);
                            if (in_array($st, ['resolved', 'action taken', 'closed'], true)): ?>
                                <span class="badge badge-success"><?= Sanitize::html($g['current_status']) ?></span>
                            <?php elseif (in_array($st, ['rejected'], true)): ?>
                                <span class="badge badge-danger"><?= Sanitize::html($g['current_status']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning"><?= Sanitize::html($g['current_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime((string)$g['submitted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
