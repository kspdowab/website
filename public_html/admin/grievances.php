<?php
/**
 * KSPDOWA — Admin: Grievances Management Dashboard
 * ============================================================
 * Section 15 & Phase 5 Specification:
 * - Gated by RBAC permission 'grievances.view'.
 * - Scope-controlled (Taluk / District / State / Super Admin).
 * - Master columns: Sl No, Grievance ID, Member, Category, Service,
 *   District, Taluk, Authority, Status, Level, Assigned Officer,
 *   Submitted Date, Pending Since, Action.
 * - Dynamic Search & Dependent Filters (Category -> Service, District -> Taluk).
 * - Status filter with all 12 approved statuses.
 * - Pagination & filter reset.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'grievances', 'view');

// Officer scope enforcement
$scope = Grievance::getOfficerScope($currentUserId);
$lockedDistrictId = $scope['district_id'];
$lockedTalukId    = $scope['taluk_id'];

// Master data for filters
$categories  = Database::fetchAll("SELECT * FROM grievance_categories WHERE status = 'active' ORDER BY sort_order, name");
$services    = Database::fetchAll("SELECT * FROM grievance_services WHERE status = 'active' ORDER BY category_id, sort_order, name");
$authorities = Database::fetchAll("SELECT * FROM grievance_authorities WHERE status = 'active' ORDER BY id ASC");
$districts   = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks      = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status = 'active' ORDER BY name");

// Map services by category for dependent filter
$servicesByCategory = [];
foreach ($services as $srv) {
    $cId = (int)$srv['category_id'];
    if (!isset($servicesByCategory[$cId])) {
        $servicesByCategory[$cId] = [];
    }
    $servicesByCategory[$cId][] = [
        'id'   => (int)$srv['id'],
        'name' => $srv['name'],
    ];
}

// Map taluks by district for dependent filter
$taluksByDistrict = [];
foreach ($taluks as $tlk) {
    $dId = (int)$tlk['district_id'];
    if (!isset($taluksByDistrict[$dId])) {
        $taluksByDistrict[$dId] = [];
    }
    $taluksByDistrict[$dId][] = [
        'id'   => (int)$tlk['id'],
        'name' => $tlk['name'],
    ];
}

// ─── Filter parameters ───────────────────────────────────────────────────────
$qSearch    = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qCategory  = Sanitize::positiveInt($_GET['category_id'] ?? null);
$qService   = Sanitize::positiveInt($_GET['service_id'] ?? null);
$qAuthority = Sanitize::positiveInt($_GET['authority_id'] ?? null);
$qStatus    = trim(Sanitize::string($_GET['status'] ?? '', 50));
$qDistrict  = $lockedDistrictId ?: Sanitize::positiveInt($_GET['district_id'] ?? null);
$qTaluk     = $lockedTalukId    ?: Sanitize::positiveInt($_GET['taluk_id'] ?? null);
$qDateFrom  = trim(Sanitize::string($_GET['date_from'] ?? '', 20));
$qDateTo    = trim(Sanitize::string($_GET['date_to'] ?? '', 20));

// Check if any active filter is applied
$hasActiveFilter = ($qSearch !== '' || $qCategory || $qService || $qAuthority ||
    ($qStatus !== '' && $qStatus !== 'all') ||
    (!$lockedDistrictId && $qDistrict) ||
    (!$lockedTalukId && $qTaluk) ||
    $qDateFrom !== '' || $qDateTo !== '');

$whereClauses = ["1=1"];
$params       = [];

// Geographic scope lock
if ($lockedTalukId) {
    $whereClauses[] = "m.taluk_id = ?";
    $params[]       = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $whereClauses[] = "m.district_id = ?";
    $params[]       = $lockedDistrictId;
} else {
    // Open scope — respect user filter
    if ($qDistrict) {
        $whereClauses[] = "m.district_id = ?";
        $params[]       = $qDistrict;
    }
    if ($qTaluk) {
        $whereClauses[] = "m.taluk_id = ?";
        $params[]       = $qTaluk;
    }
}

if ($qCategory) {
    $whereClauses[] = "g.category_id = ?";
    $params[]       = $qCategory;
}

if ($qService) {
    $whereClauses[] = "g.service_id = ?";
    $params[]       = $qService;
}

if ($qAuthority) {
    $whereClauses[] = "g.current_authority = ?";
    $params[]       = $qAuthority;
}

if ($qStatus !== '' && $qStatus !== 'all') {
    $whereClauses[] = "g.current_status = ?";
    $params[]       = $qStatus;
}

if ($qDateFrom !== '') {
    $whereClauses[] = "DATE(g.submitted_at) >= ?";
    $params[]       = $qDateFrom;
}

if ($qDateTo !== '') {
    $whereClauses[] = "DATE(g.submitted_at) <= ?";
    $params[]       = $qDateTo;
}

if ($qSearch !== '') {
    $whereClauses[] = "(g.grievance_no LIKE ? OR g.subject LIKE ? OR m.name LIKE ? OR m.member_no LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $whereClauses);

// Pagination
$page     = max(1, Sanitize::positiveInt($_GET['page'] ?? 1) ?: 1);
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$countSql = "
    SELECT COUNT(*) as total
    FROM grievances g
    INNER JOIN members m ON m.id = g.member_id
    WHERE {$whereSql}
";
$totalRecords = (int)(Database::fetchOne($countSql, $params)['total'] ?? 0);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));

$sql = "
    SELECT g.*, m.name AS member_name, m.member_no, d.name AS district_name, t.name AS taluk_name,
           gc.name AS category_name, gs.name AS service_name, ga.name AS authority_name,
           u_assignee.username AS assignee_username,
           u_assignee.email AS assignee_email
    FROM grievances g
    INNER JOIN members m ON m.id = g.member_id
    LEFT JOIN districts d ON d.id = m.district_id
    LEFT JOIN taluks t    ON t.id = m.taluk_id
    LEFT JOIN grievance_categories gc ON gc.id = g.category_id
    LEFT JOIN grievance_services gs   ON gs.id = g.service_id
    LEFT JOIN grievance_authorities ga ON ga.id = g.current_authority
    LEFT JOIN users u_assignee ON u_assignee.id = g.current_assignee
    WHERE {$whereSql}
    ORDER BY g.submitted_at DESC, g.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$grievances = Database::fetchAll($sql, $params);

// Scope-wide metrics
$scopeMetricsWhere = "1=1";
$scopeMetricsParams = [];
if ($lockedTalukId) {
    $scopeMetricsWhere = "m.taluk_id = ?";
    $scopeMetricsParams = [$lockedTalukId];
} elseif ($lockedDistrictId) {
    $scopeMetricsWhere = "m.district_id = ?";
    $scopeMetricsParams = [$lockedDistrictId];
}

$allScopeGrvs = Database::fetchAll(
    "SELECT g.current_status
     FROM grievances g
     INNER JOIN members m ON m.id = g.member_id
     WHERE {$scopeMetricsWhere}",
    $scopeMetricsParams
);

$statTotal = count($allScopeGrvs);
$statSubmitted = 0;
$statReview = 0;
$statClarification = 0;
$statResolved = 0;

foreach ($allScopeGrvs as $row) {
    $s = $row['current_status'];
    if ($s === Grievance::STATUS_SUBMITTED || $s === Grievance::STATUS_UNDER_VERIFICATION) {
        $statSubmitted++;
    } elseif ($s === Grievance::STATUS_CLARIFICATION_REQUIRED) {
        $statClarification++;
    } elseif (in_array($s, [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)) {
        $statResolved++;
    } else {
        $statReview++;
    }
}

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
        <h1 class="page-heading-title">Grievances &amp; Service Tracking Management</h1>
        <p class="page-heading-subtitle">
            Scope: <strong><?= ucfirst($scope['type']) ?> Scope</strong>
            <?php if ($lockedDistrictId): ?> (District Restricted)<?php endif; ?>
            <?php if ($lockedTalukId): ?> (Taluk Restricted)<?php endif; ?>
        </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <span class="badge badge-purple" style="font-size:0.88rem; padding:8px 16px;">
            <?= $statTotal ?> Total in Scope
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STATISTICS CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="table-card" style="padding:18px 20px;">
        <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Total in Scope</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--text-main); margin-top:4px;"><?= $statTotal ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">All registered cases</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--blue-600);">
        <span style="font-size:0.78rem; font-weight:600; color:var(--blue-700); text-transform:uppercase;">New / Verification</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--blue-700); margin-top:4px;"><?= $statSubmitted ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Awaiting acceptance</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--purple-accent, #665aa8);">
        <span style="font-size:0.78rem; font-weight:600; color:#5b21b6; text-transform:uppercase;">In Review / Forwarded</span>
        <div style="font-size:1.8rem; font-weight:800; color:#5b21b6; margin-top:4px;"><?= $statReview ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Active committee handling</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid #f59e0b;">
        <span style="font-size:0.78rem; font-weight:600; color:#b45309; text-transform:uppercase;">Clarification Needed</span>
        <div style="font-size:1.8rem; font-weight:800; color:#b45309; margin-top:4px;"><?= $statClarification ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Pending member response</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid #10b981;">
        <span style="font-size:0.78rem; font-weight:600; color:#047857; text-transform:uppercase;">Resolved / Closed</span>
        <div style="font-size:1.8rem; font-weight:800; color:#047857; margin-top:4px;"><?= $statResolved ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Completed matters</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS (WITH INTERACTIVE HIDE/UNHIDE TOGGLE)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-action-header" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <button type="button" id="toggleFilterBtn" class="btn btn-outline btn-sm" onclick="toggleFilterCard();" style="display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span id="filterBtnText"><?= $hasActiveFilter ? 'Hide Filters' : 'Filter / Search Grievances' ?></span>
            <?php if ($hasActiveFilter): ?>
                <span class="badge badge-purple" style="font-size:0.72rem; padding:2px 6px;">Active</span>
            <?php endif; ?>
        </button>
    </div>
    <?php if ($hasActiveFilter): ?>
        <a href="/admin/grievances.php" class="btn btn-outline btn-sm" style="color:var(--text-muted);">✕ Clear All Filters</a>
    <?php endif; ?>
</div>

<div class="filter-card" id="filterCard" style="margin-bottom:24px; <?= $hasActiveFilter ? '' : 'display:none;' ?>">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter &amp; Search Grievance Records
        </div>
        <div class="filter-header-actions">
            <button type="button" class="filter-header-btn" onclick="toggleFilterCard();" style="background:none; border:none; cursor:pointer; font-size:0.85rem;">✕ Hide</button>
            <a href="/admin/grievances.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/admin/grievances.php" class="filter-body" style="padding:20px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:14px;">
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label" style="font-size:0.78rem;">Search (Grievance No, Member Name, KGID, Subject)</label>
                <input type="text" name="q" class="form-control" placeholder="Search grievance no, member name..." value="<?= Sanitize::attr($qSearch) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Category</label>
                <select name="category_id" id="filter_category_id" class="form-select" onchange="onFilterCategoryChange();">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $qCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Service Type</label>
                <select name="service_id" id="filter_service_id" class="form-select">
                    <option value="">All Services</option>
                    <?php if ($qCategory && !empty($servicesByCategory[$qCategory])): ?>
                        <?php foreach ($servicesByCategory[$qCategory] as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $qService === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= Sanitize::html($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:14px;">
            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Government Authority</label>
                <select name="authority_id" class="form-select">
                    <option value="">All Authorities</option>
                    <?php foreach ($authorities as $auth): ?>
                        <option value="<?= (int)$auth['id'] ?>" <?= $qAuthority === (int)$auth['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($auth['name']) ?> (<?= Sanitize::html($auth['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Status (All 12 States)</label>
                <select name="status" class="form-select">
                    <option value="all">All Statuses</option>
                    <?php foreach (Grievance::ALL_STATUSES as $st): ?>
                        <option value="<?= Sanitize::attr($st) ?>" <?= $qStatus === $st ? 'selected' : '' ?>>
                            <?= Sanitize::html($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">District</label>
                <?php if ($lockedDistrictId): ?>
                    <select name="district_id" class="form-select" disabled>
                        <?php foreach ($districts as $d): ?>
                            <?php if ((int)$d['id'] === $lockedDistrictId): ?>
                                <option value="<?= (int)$d['id'] ?>" selected><?= Sanitize::html($d['name']) ?> (Locked)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="district_id" value="<?= $lockedDistrictId ?>">
                <?php else: ?>
                    <select name="district_id" id="filter_district_id" class="form-select" onchange="onFilterDistrictChange();">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $qDistrict === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= Sanitize::html($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Taluk</label>
                <?php if ($lockedTalukId): ?>
                    <select name="taluk_id" class="form-select" disabled>
                        <?php foreach ($taluks as $t): ?>
                            <?php if ((int)$t['id'] === $lockedTalukId): ?>
                                <option value="<?= (int)$t['id'] ?>" selected><?= Sanitize::html($t['name']) ?> (Locked)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="taluk_id" value="<?= $lockedTalukId ?>">
                <?php else: ?>
                    <select name="taluk_id" id="filter_taluk_id" class="form-select">
                        <option value="">All Taluks</option>
                        <?php if ($qDistrict && !empty($taluksByDistrict[$qDistrict])): ?>
                            <?php foreach ($taluksByDistrict[$qDistrict] as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $qTaluk === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= Sanitize::html($t['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= Sanitize::attr($qDateFrom) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= Sanitize::attr($qDateTo) ?>">
            </div>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button type="submit" class="btn btn-primary" style="padding:8px 24px;">Apply Filters</button>
            <a href="/admin/grievances.php" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     GRIEVANCE REGISTER TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Grievance Records</span>
            <span class="table-card-count">(Showing <?= count($grievances) ?> of <?= $totalRecords ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40px;">Sl</th>
                    <th>Grievance ID</th>
                    <th>Member</th>
                    <th>District / Taluk</th>
                    <th>Category &amp; Service</th>
                    <th>Authority</th>
                    <th>Status</th>
                    <th>Level</th>
                    <th>Assigned Officer</th>
                    <th>Submitted</th>
                    <th>Pending</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grievances)): ?>
                <tr>
                    <td colspan="12" style="text-align:center; padding:40px; color:var(--text-muted);">
                        No grievances found matching the criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = $offset + 1; foreach ($grievances as $g): ?>
                        <?php
                        $st = $g['current_status'];
                        $badgeClass = 'badge-neutral';
                        if ($st === Grievance::STATUS_RESOLVED || $st === Grievance::STATUS_ACTION_TAKEN) {
                            $badgeClass = 'badge-success';
                        } elseif ($st === Grievance::STATUS_CLARIFICATION_REQUIRED) {
                            $badgeClass = 'badge-warning';
                        } elseif ($st === Grievance::STATUS_REJECTED) {
                            $badgeClass = 'badge-danger';
                        } elseif ($st === Grievance::STATUS_SUBMITTED) {
                            $badgeClass = 'badge-neutral';
                        } else {
                            $badgeClass = 'badge-purple';
                        }

                        $subTs = strtotime((string)$g['submitted_at']);
                        $daysPending = max(0, (int)floor((time() - $subTs) / 86400));
                        ?>
                        <tr>
                            <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                            <td>
                                <a href="/admin/grievance-view.php?id=<?= (int)$g['id'] ?>" style="font-weight:700; color:var(--blue-700); text-decoration:none;">
                                    <?= Sanitize::html($g['grievance_no']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="/admin/member-view.php?id=<?= (int)$g['member_id'] ?>" style="font-weight:600; color:var(--text-main); text-decoration:none; display:block;">
                                    <?= Sanitize::html($g['member_name']) ?>
                                </a>
                                <span style="font-size:0.76rem; color:var(--text-muted);"><?= Sanitize::html($g['member_no']) ?></span>
                            </td>
                            <td>
                                <span style="display:block; font-weight:600; font-size:0.86rem;"><?= Sanitize::html($g['district_name'] ?? '—') ?></span>
                                <span style="font-size:0.76rem; color:var(--text-muted);"><?= Sanitize::html($g['taluk_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <span style="display:block; font-weight:600; font-size:0.86rem; color:var(--text-main);"><?= Sanitize::html($g['category_name'] ?? 'General') ?></span>
                                <span style="font-size:0.76rem; color:var(--text-muted);"><?= Sanitize::html($g['service_name'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= !empty($g['authority_name']) ? Sanitize::html($g['authority_name']) : '<span style="color:var(--text-muted);">Association</span>' ?>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= Sanitize::html($st) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-neutral" style="text-transform:capitalize;">
                                    <?= Sanitize::html($g['current_association_level']) ?>
                                </span>
                            </td>
                            <td>
                                <?= !empty($g['assignee_username']) ? Sanitize::html($g['assignee_username']) : '<span style="color:var(--text-muted);">Unassigned</span>' ?>
                            </td>
                            <td><?= date('d M Y', $subTs) ?></td>
                            <td>
                                <?php if (in_array($st, [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)): ?>
                                    <span style="color:var(--text-muted);">Done</span>
                                <?php else: ?>
                                    <strong style="color:<?= $daysPending > 15 ? '#b91c1c' : ($daysPending > 7 ? '#b45309' : 'var(--text-main)') ?>;">
                                        <?= $daysPending ?> d
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="/admin/grievance-view.php?id=<?= (int)$g['id'] ?>" class="btn btn-outline btn-sm">
                                    View &amp; Manage &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
            <div style="font-size:0.86rem; color:var(--text-muted);">
                Page <?= $page ?> of <?= $totalPages ?> (Total <?= $totalRecords ?> grievances)
            </div>
            <div style="display:flex; gap:6px;">
                <?php
                $queryParams = $_GET;
                ?>
                <?php if ($page > 1): ?>
                    <?php $queryParams['page'] = $page - 1; ?>
                    <a href="/admin/grievances.php?<?= http_build_query($queryParams) ?>" class="btn btn-outline btn-sm">&larr; Previous</a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <?php $queryParams['page'] = $page + 1; ?>
                    <a href="/admin/grievances.php?<?= http_build_query($queryParams) ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const filterServicesMap = <?= json_encode($servicesByCategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const filterTaluksMap   = <?= json_encode($taluksByDistrict, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function onFilterCategoryChange() {
    const catSelect = document.getElementById('filter_category_id');
    const srvSelect = document.getElementById('filter_service_id');
    const selectedCat = parseInt(catSelect.value, 10);

    srvSelect.innerHTML = '<option value="">All Services</option>';

    if (selectedCat && filterServicesMap[selectedCat]) {
        filterServicesMap[selectedCat].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            srvSelect.appendChild(opt);
        });
    }
}

function onFilterDistrictChange() {
    const distSelect = document.getElementById('filter_district_id');
    const tlkSelect  = document.getElementById('filter_taluk_id');
    if (!distSelect || !tlkSelect) return;

    const selectedDist = parseInt(distSelect.value, 10);
    tlkSelect.innerHTML = '<option value="">All Taluks</option>';

    if (selectedDist && filterTaluksMap[selectedDist]) {
        filterTaluksMap[selectedDist].forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.name;
            tlkSelect.appendChild(opt);
        });
    }
}

function toggleFilterCard() {
    const card = document.getElementById('filterCard');
    const btnText = document.getElementById('filterBtnText');
    if (!card) return;

    if (card.style.display === 'none' || card.style.display === '') {
        card.style.display = 'block';
        if (btnText) btnText.textContent = 'Hide Filters';
    } else {
        card.style.display = 'none';
        if (btnText) btnText.textContent = 'Filter / Search Grievances';
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
