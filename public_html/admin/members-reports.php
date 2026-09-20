<?php
/**
 * KSPDOWA — Admin: Members Abstract & Analytical Reports (Phase 7)
 * ============================================================
 * Gated by RBAC permission 'reports.view' or 'members.view'.
 * Supports:
 *  - District-wise and Taluk-wise summary views
 *  - Active members, paid, unpaid counts
 *  - Membership Growth & Registration Trends (Month-by-month for FY)
 *  - Detailed Member Listing with payment and profile status
 *  - PDF / Excel exports (via members-export.php) & Print-friendly view
 *  - Strict administrative scope locking (State / District / Taluk)
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'view') && !RBAC::can($currentUserId, 'members', 'manage') && !RBAC::can($currentUserId, 'members', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to view member reports.');
}

$canManageMembers = RBAC::can($currentUserId, 'members', 'manage');
$highestScope     = RBAC::getHighestScopeType($currentUserId);
$isStateAdmin     = ($highestScope === 'state');

// ─── Geographic scope locking ─────────────────────────────────────────────────
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

// ─── Input params ─────────────────────────────────────────────────────────────
$tab  = Sanitize::inArray($_GET['tab'] ?? 'abstract', ['abstract', 'detailed', 'trends']) ?: 'abstract';
$view = Sanitize::inArray($_GET['view'] ?? 'district', ['district', 'taluk']) ?: 'district';

$fyId = Sanitize::positiveInt($_GET['fy_id'] ?? null);
$years = Database::fetchAll("SELECT id, financial_year, status FROM membership_years ORDER BY start_date DESC");
if (!$fyId && !empty($years)) {
    $activeYears = array_filter($years, fn($y) => $y['status'] === 'active');
    $fyId = $activeYears ? (int)reset($activeYears)['id'] : (int)$years[0]['id'];
}
$selectedYear = null;
foreach ($years as $y) { if ((int)$y['id'] === $fyId) { $selectedYear = $y; break; } }

// Filter dropdowns
$filterDistrictId = Sanitize::positiveInt($_GET['district_id'] ?? null) ?: null;
$filterTalukId    = Sanitize::positiveInt($_GET['taluk_id']    ?? null) ?: null;
$paymentStatus    = Sanitize::inArray($_GET['payment_status']  ?? 'all', ['all', 'paid', 'unpaid']) ?: 'all';
$memberStatus     = Sanitize::inArray($_GET['member_status']   ?? 'all', ['all', 'active', 'inactive', 'pending']) ?: 'all';
$search           = trim(Sanitize::string($_GET['q'] ?? '', 100));

if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

// Sorting for abstract
$allowedSortDistrict = ['district_name', 'total_members', 'active_members', 'paid_members', 'unpaid_members'];
$allowedSortTaluk    = ['district_name', 'taluk_name', 'total_members', 'active_members', 'paid_members', 'unpaid_members'];
$allowedSort         = ($view === 'district') ? $allowedSortDistrict : $allowedSortTaluk;

$sortBy  = Sanitize::inArray($_GET['sort_by']  ?? '', $allowedSort)  ?: 'paid_members';
$sortDir = Sanitize::inArray($_GET['sort_dir'] ?? '', ['asc', 'desc']) ?: 'desc';
$sqlDir  = $sortDir === 'asc' ? 'ASC' : 'DESC';

// Geo data for JS cascading
$allDistricts = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$allTaluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY district_id, name");

// ─── ABSTRACT DATA QUERIES ────────────────────────────────────────────────────
$report = [];
$grandTotal  = 0;
$grandActive = 0;
$grandPaid   = 0;
$grandUnpaid = 0;

if ($tab === 'abstract') {
    if ($view === 'district') {
        $whereStr = '';
        $params   = [];
        if ($lockedDistrictId) {
            $whereStr .= " AND m.district_id = ?";
            $params[]  = $lockedDistrictId;
        } elseif ($filterDistrictId) {
            $whereStr .= " AND m.district_id = ?";
            $params[]  = $filterDistrictId;
        }
        $orderExpr = "$sortBy $sqlDir";

        $sql = "SELECT d.id AS district_id, d.name AS district_name,
                       COUNT(m.id) AS total_members,
                       SUM(CASE WHEN m.membership_status = 'active' THEN 1 ELSE 0 END) AS active_members,
                       SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS paid_members,
                       SUM(CASE WHEN p.id IS NULL     THEN 1 ELSE 0 END) AS unpaid_members
                FROM districts d
                JOIN members m ON m.district_id = d.id
                LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                WHERE 1=1 $whereStr
                GROUP BY d.id, d.name
                ORDER BY $orderExpr";
        $report = Database::fetchAll($sql, $params);

    } else {
        $whereStr = '';
        $params   = [];
        if ($lockedDistrictId) {
            $whereStr .= " AND m.district_id = " . (int)$lockedDistrictId;
        } elseif ($filterDistrictId) {
            $whereStr .= " AND m.district_id = " . (int)$filterDistrictId;
        }
        if ($lockedTalukId) {
            $whereStr .= " AND m.taluk_id = " . (int)$lockedTalukId;
        } elseif ($filterTalukId) {
            $whereStr .= " AND m.taluk_id = " . (int)$filterTalukId;
        }

        $orderExpr = "$sortBy $sqlDir";

        $sql = "SELECT d.name AS district_name, t.name AS taluk_name,
                       COUNT(m.id) AS total_members,
                       SUM(CASE WHEN m.membership_status = 'active' THEN 1 ELSE 0 END) AS active_members,
                       SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS paid_members,
                       SUM(CASE WHEN p.id IS NULL     THEN 1 ELSE 0 END) AS unpaid_members
                FROM taluks t
                JOIN districts d ON d.id = t.district_id
                JOIN members   m ON m.taluk_id = t.id
                LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                WHERE 1=1 $whereStr
                GROUP BY d.name, t.name
                ORDER BY $orderExpr";
        $report = Database::fetchAll($sql, $params);
    }

    foreach ($report as $r) {
        $grandTotal  += (int)$r['total_members'];
        $grandActive += (int)$r['active_members'];
        $grandPaid   += (int)$r['paid_members'];
        $grandUnpaid += (int)$r['unpaid_members'];
    }
}

// ─── DETAILED MEMBERS QUERY ───────────────────────────────────────────────────
$detailedMembers = [];
$totalDetailedCount = 0;
$page = max(1, Sanitize::positiveInt($_GET['page'] ?? 1) ?: 1);
$perPage = 50;

if ($tab === 'detailed') {
    $dWhere = ["1=1"];
    $dParams = [];

    if ($lockedTalukId) {
        $dWhere[] = "m.taluk_id = ?";
        $dParams[] = $lockedTalukId;
    } elseif ($filterTalukId) {
        $dWhere[] = "m.taluk_id = ?";
        $dParams[] = $filterTalukId;
    }

    if ($lockedDistrictId) {
        $dWhere[] = "m.district_id = ?";
        $dParams[] = $lockedDistrictId;
    } elseif ($filterDistrictId) {
        $dWhere[] = "m.district_id = ?";
        $dParams[] = $filterDistrictId;
    }

    if ($paymentStatus === 'paid') {
        $dWhere[] = "p.id IS NOT NULL";
    } elseif ($paymentStatus === 'unpaid') {
        $dWhere[] = "p.id IS NULL";
    }

    if ($memberStatus !== 'all') {
        $dWhere[] = "m.membership_status = ?";
        $dParams[] = $memberStatus;
    }

    if ($search !== '') {
        $dWhere[] = "(m.name LIKE ? OR m.member_no LIKE ? OR mp.kgid_no LIKE ? OR mp.personal_mobile LIKE ?)";
        $like = "%{$search}%";
        array_push($dParams, $like, $like, $like, $like);
    }

    $dWhereSql = implode(' AND ', $dWhere);

    $countSql = "SELECT COUNT(DISTINCT m.id) AS total
                 FROM members m
                 LEFT JOIN member_profiles mp ON mp.member_id = m.id
                 LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                 WHERE $dWhereSql";
    $countRow = Database::fetchOne($countSql, $dParams);
    $totalDetailedCount = (int)($countRow['total'] ?? 0);

    $offset = ($page - 1) * $perPage;
    $detailSql = "SELECT m.id, m.member_no, m.name, m.gender, m.membership_status, m.created_at,
                         mp.personal_mobile, mp.kgid_no,
                         d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name,
                         p.id AS payment_id, p.amount AS paid_amount, p.paid_at, p.payment_method
                  FROM members m
                  LEFT JOIN member_profiles mp ON mp.member_id = m.id
                  LEFT JOIN districts d ON d.id = m.district_id
                  LEFT JOIN taluks t    ON t.id = m.taluk_id
                  LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
                  LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                  WHERE $dWhereSql
                  ORDER BY m.id DESC
                  LIMIT $perPage OFFSET $offset";
    $detailedMembers = Database::fetchAll($detailSql, $dParams);
}

// ─── MEMBERSHIP GROWTH TRENDS QUERY ──────────────────────────────────────────
$trends = [];
if ($tab === 'trends') {
    $tWhere = ["1=1"];
    $tParams = [];

    if ($lockedTalukId) {
        $tWhere[] = "m.taluk_id = ?";
        $tParams[] = $lockedTalukId;
    } elseif ($filterTalukId) {
        $tWhere[] = "m.taluk_id = ?";
        $tParams[] = $filterTalukId;
    }

    if ($lockedDistrictId) {
        $tWhere[] = "m.district_id = ?";
        $tParams[] = $lockedDistrictId;
    } elseif ($filterDistrictId) {
        $tWhere[] = "m.district_id = ?";
        $tParams[] = $filterDistrictId;
    }

    $tWhereSql = implode(' AND ', $tWhere);

    $sqlTrends = "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS month_label,
                         COUNT(m.id) AS new_registrations,
                         SUM(CASE WHEN m.membership_status = 'active' THEN 1 ELSE 0 END) AS active_in_month,
                         COUNT(DISTINCT p.id) AS payments_count,
                         COALESCE(SUM(p.amount), 0) AS payments_amount
                  FROM members m
                  LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                  WHERE $tWhereSql
                  GROUP BY DATE_FORMAT(m.created_at, '%Y-%m')
                  ORDER BY month_label ASC";
    $trends = Database::fetchAll($sqlTrends, $tParams);
}

// Export params
$exportParams = http_build_query(array_filter([
    'fy_id'       => $fyId,
    'district_id' => $filterDistrictId,
    'taluk_id'    => $filterTalukId,
    'sort_by'     => $sortBy,
    'sort_dir'    => $sortDir,
    'payment_status' => $paymentStatus,
    'q'           => $search,
]));

// Helper: sort link URL
function sortUrl(string $col, string $currentSortBy, string $currentSortDir): string {
    $params = $_GET;
    $params['sort_by']  = $col;
    $params['sort_dir'] = ($currentSortBy === $col && $currentSortDir === 'desc') ? 'asc' : 'desc';
    return '/admin/members-reports.php?' . http_build_query($params);
}
function sortIndicator(string $col, string $currentSortBy, string $currentSortDir): string {
    if ($currentSortBy !== $col) { return ' <span style="color:#ccc;">⇅</span>'; }
    return $currentSortDir === 'asc' ? ' <span style="color:#1a3a6b;">↑</span>' : ' <span style="color:#1a3a6b;">↓</span>';
}

$pageTitle   = 'Membership Reports & Analytics';
$activeMenu  = 'reports';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Reports & Analytics', 'url' => '/admin/reports.php'],
    ['label' => 'Membership Reports', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .report-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; flex-wrap: wrap; }
    .report-tab-btn { padding: 9px 18px; font-size: 0.88rem; font-weight: 600; color: #475569; text-decoration: none; border-radius: 6px; background: #f8fafc; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .report-tab-btn:hover { background: #e2e8f0; color: #1e293b; }
    .report-tab-btn.active { background: #1a3a6b; color: #ffffff; border-color: #1a3a6b; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
    .panel h2 { font-size: 1.15rem; color: #1a3a6b; margin: 0 0 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
    select, input[type="text"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
    input[readonly] { background: #f0f3f7; color: #888; }
    .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
    .row > div { flex: 1; min-width: 160px; }
    .btn { background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn:hover { background: #142c52; }
    .btn-secondary { background: #475569; }
    .btn-secondary:hover { background: #334155; }
    .btn-print { background: #0284c7; }
    .btn-print:hover { background: #0369a1; }
    table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
    th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; }
    th { color: #ffffff; font-weight: 600; background: var(--blue-800, #1e40af); font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px; border: none; white-space: nowrap; }
    th a { color: #93c5fd; text-decoration: none; }
    th a:hover { color: #ffffff; text-decoration: underline; }
    td.num { text-align: right; font-weight: 600; }
    td.paid { color: #15803d; }
    td.unpaid { color: #b91c1c; }
    tfoot { font-weight: bold; background: #f8fafc; border-top: 2px solid #cbd5e1; }
    tfoot td.num { font-weight: 700; }
    tr:hover { background: #f8fafc; }
    .table-wrap { overflow-x: auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 14px; }
    .badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .badge-paid { background: #dcfce7; color: #15803d; }
    .badge-unpaid { background: #fee2e2; color: #b91c1c; }
    .badge-active { background: #e0f2fe; color: #0369a1; }
    .trend-bar-bg { background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; width: 100%; min-width: 80px; }
    .trend-bar-fill { background: #2563eb; height: 100%; border-radius: 5px; }

    /* Print styling */
    @media print {
        aside, .admin-topbar, .report-tabs, .filter-box, .btn, .sub-nav, footer { display: none !important; }
        body, .admin-viewport, .admin-main { background: #fff !important; margin: 0 !important; padding: 0 !important; }
        .panel { box-shadow: none !important; border: none !important; padding: 0 !important; }
        .print-header { display: block !important; margin-bottom: 20px; text-align: center; border-bottom: 2px solid #1a3a6b; padding-bottom: 10px; }
        .print-footer { display: block !important; position: fixed; bottom: 0; left: 0; right: 0; font-size: 8pt; text-align: center; border-top: 1px solid #ccc; padding-top: 4px; }
        th { background: #1a3a6b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    .print-header, .print-footer { display: none; }
</style>

<div class="print-header">
    <h2 style="margin:0;color:#1a3a6b;font-size:16pt;">KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICERS WELFARE ASSOCIATION (R.)</h2>
    <div style="font-size:11pt;font-weight:bold;margin-top:4px;">MEMBERSHIP REPORT (<?= Sanitize::html($selectedYear['financial_year'] ?? '') ?>)</div>
    <div style="font-size:9pt;color:#555;margin-top:2px;">Generated on: <?= date('d-m-Y h:i A') ?></div>
</div>

<div class="report-tabs">
    <a href="/admin/members-reports.php?tab=abstract&<?= http_build_query(array_filter(['fy_id' => $fyId, 'district_id' => $filterDistrictId, 'taluk_id' => $filterTalukId, 'view' => $view])) ?>" class="report-tab-btn <?= $tab === 'abstract' ? 'active' : '' ?>">📊 Abstract Summary</a>
    <a href="/admin/members-reports.php?tab=detailed&<?= http_build_query(array_filter(['fy_id' => $fyId, 'district_id' => $filterDistrictId, 'taluk_id' => $filterTalukId, 'payment_status' => $paymentStatus])) ?>" class="report-tab-btn <?= $tab === 'detailed' ? 'active' : '' ?>">📋 Detailed Members List</a>
    <a href="/admin/members-reports.php?tab=trends&<?= http_build_query(array_filter(['fy_id' => $fyId, 'district_id' => $filterDistrictId, 'taluk_id' => $filterTalukId])) ?>" class="report-tab-btn <?= $tab === 'trends' ? 'active' : '' ?>">📈 Growth &amp; Trends</a>
</div>

<div class="panel filter-box">
    <form method="get" action="/admin/members-reports.php" id="reportForm">
        <input type="hidden" name="tab" value="<?= Sanitize::attr($tab) ?>">
        <div class="row">
            <?php if ($tab === 'abstract'): ?>
            <div>
                <label>View Level</label>
                <select name="view" onchange="this.form.submit()">
                    <option value="district" <?= $view === 'district' ? 'selected' : '' ?>>District-wise</option>
                    <option value="taluk"    <?= $view === 'taluk'    ? 'selected' : '' ?>>Taluk-wise</option>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label>Financial Year</label>
                <select name="fy_id" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y['id'] ?>" <?= $y['id'] == $fyId ? 'selected' : '' ?>><?= Sanitize::html($y['financial_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>District</label>
                <?php if ($lockedDistrictId): ?>
                    <input type="text" value="<?= Sanitize::attr(array_column($allDistricts,'name','id')[$lockedDistrictId] ?? 'Your District') ?>" readonly>
                    <input type="hidden" name="district_id" value="<?= $lockedDistrictId ?>">
                <?php else: ?>
                <select name="district_id" id="reportDistrictSel">
                    <option value="">All Districts</option>
                    <?php foreach ($allDistricts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $d['id'] == $filterDistrictId ? 'selected' : '' ?>><?= Sanitize::html($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <?php if ($view === 'taluk' || $tab === 'detailed' || $tab === 'trends'): ?>
            <div>
                <label>Taluk</label>
                <?php if ($lockedTalukId): ?>
                    <input type="text" value="<?= Sanitize::attr(array_column($allTaluks,'name','id')[$lockedTalukId] ?? 'Your Taluk') ?>" readonly>
                    <input type="hidden" name="taluk_id" value="<?= $lockedTalukId ?>">
                <?php else: ?>
                <select name="taluk_id" id="reportTalukSel">
                    <option value="">All Taluks</option>
                    <?php foreach ($allTaluks as $t): ?>
                        <?php if (!$filterDistrictId || $t['district_id'] == $filterDistrictId): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $filterTalukId ? 'selected' : '' ?>><?= Sanitize::html($t['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'detailed'): ?>
            <div>
                <label>Payment Status</label>
                <select name="payment_status" onchange="this.form.submit()">
                    <option value="all" <?= $paymentStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Paid Only</option>
                    <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid Only</option>
                </select>
            </div>
            <div>
                <label>Search Keyword</label>
                <input type="text" name="q" value="<?= Sanitize::attr($search) ?>" placeholder="Name, KGID, Mobile...">
            </div>
            <?php endif; ?>

            <input type="hidden" name="sort_by"  value="<?= Sanitize::attr($sortBy) ?>">
            <input type="hidden" name="sort_dir" value="<?= Sanitize::attr($sortDir) ?>">

            <div style="flex:0; display:flex; gap:8px;">
                <button type="submit" class="btn">Filter</button>
                <a href="/admin/members-reports.php?tab=<?= Sanitize::attr($tab) ?>" class="btn btn-secondary">Reset</a>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing data for <strong><?= Sanitize::html($selectedYear['financial_year'] ?? '') ?></strong> • Scope: <strong><?= Sanitize::html($highestScope === 'state' ? ($filterDistrictId ? 'District Filtered' : 'Karnataka Statewide') : ($lockedDistrictId ? 'District Restricted' : 'Taluk Restricted')) ?></strong>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <?php if ($tab === 'abstract'): ?>
                    <a href="/admin/members-export.php?format=pdf&report_type=abstract_<?= $view ?>&<?= $exportParams ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                    <a href="/admin/members-export.php?format=excel&report_type=abstract_<?= $view ?>&<?= $exportParams ?>" class="btn" style="background:#245A57;">Export Excel</a>
                <?php elseif ($tab === 'detailed'): ?>
                    <a href="/admin/members-export.php?format=pdf&report_type=detailed&<?= $exportParams ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                    <a href="/admin/members-export.php?format=excel&report_type=detailed&<?= $exportParams ?>" class="btn" style="background:#245A57;">Export Excel</a>
                <?php endif; ?>
                <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Print Report</button>
            </div>
        </div>
    </form>
</div>

<?php if ($tab === 'abstract'): ?>
<div class="panel">
    <h2>
        <span><?= $view === 'district' ? 'District-wise' : 'Taluk-wise' ?> Summary (<?= Sanitize::html($selectedYear['financial_year'] ?? '') ?>)</span>
        <span style="font-size:0.8rem;color:#888;font-weight:400;">Default sort: Paid Members ↓ • Click column header to sort</span>
    </h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th><a href="<?= Sanitize::attr(sortUrl('district_name', $sortBy, $sortDir)) ?>">District<?= sortIndicator('district_name', $sortBy, $sortDir) ?></a></th>
                    <?php if ($view === 'taluk'): ?>
                    <th><a href="<?= Sanitize::attr(sortUrl('taluk_name', $sortBy, $sortDir)) ?>">Taluk<?= sortIndicator('taluk_name', $sortBy, $sortDir) ?></a></th>
                    <?php endif; ?>
                    <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('total_members', $sortBy, $sortDir)) ?>">Total Members<?= sortIndicator('total_members', $sortBy, $sortDir) ?></a></th>
                    <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('active_members', $sortBy, $sortDir)) ?>">Active Members<?= sortIndicator('active_members', $sortBy, $sortDir) ?></a></th>
                    <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('paid_members', $sortBy, $sortDir)) ?>">Paid Members<?= sortIndicator('paid_members', $sortBy, $sortDir) ?></a></th>
                    <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('unpaid_members', $sortBy, $sortDir)) ?>">Unpaid Members<?= sortIndicator('unpaid_members', $sortBy, $sortDir) ?></a></th>
                    <th style="text-align:right;">Compliance Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($report as $r): 
                    $rate = $r['total_members'] > 0 ? round(($r['paid_members'] / $r['total_members']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($r['district_name']) ?></strong></td>
                    <?php if ($view === 'taluk'): ?>
                    <td><?= Sanitize::html($r['taluk_name']) ?></td>
                    <?php endif; ?>
                    <td class="num"><?= (int)$r['total_members'] ?></td>
                    <td class="num" style="color:#0284c7;"><?= (int)$r['active_members'] ?></td>
                    <td class="num paid"><?= (int)$r['paid_members'] ?></td>
                    <td class="num unpaid"><?= (int)$r['unpaid_members'] ?></td>
                    <td class="num" style="color: <?= $rate >= 80 ? '#15803d' : ($rate >= 50 ? '#d97706' : '#b91c1c') ?>; font-weight:700;">
                        <?= $rate ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($report)): ?>
                <tr><td colspan="<?= $view === 'taluk' ? 8 : 7 ?>" style="text-align:center;color:#888;padding:24px;">No member records found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($report)): 
                $grandRate = $grandTotal > 0 ? round(($grandPaid / $grandTotal) * 100, 1) : 0;
            ?>
            <tfoot>
                <tr>
                    <td colspan="<?= $view === 'taluk' ? 3 : 2 ?>" style="text-align:right;">GRAND TOTAL</td>
                    <td class="num"><?= $grandTotal ?></td>
                    <td class="num" style="color:#0284c7;"><?= $grandActive ?></td>
                    <td class="num paid"><?= $grandPaid ?></td>
                    <td class="num unpaid"><?= $grandUnpaid ?></td>
                    <td class="num" style="font-weight:700; color:#1a3a6b;"><?= $grandRate ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php elseif ($tab === 'detailed'): ?>
<div class="panel">
    <h2>
        <span>Detailed Member Records (<?= number_format($totalDetailedCount) ?> total)</span>
        <span style="font-size:0.8rem;color:#888;font-weight:400;">Showing page <?= $page ?> (50 per page)</span>
    </h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th>Member ID</th>
                    <th>Name</th>
                    <th>KGID</th>
                    <th>Mobile</th>
                    <th>District</th>
                    <th>Taluk</th>
                    <th>Gram Panchayat</th>
                    <th>Status</th>
                    <th>FY <?= Sanitize::html($selectedYear['financial_year'] ?? '') ?> Dues</th>
                </tr>
            </thead>
            <tbody>
                <?php $sl = ($page - 1) * $perPage + 1; foreach ($detailedMembers as $dm): ?>
                <tr>
                    <td><?= $sl++ ?></td>
                    <td><strong><?= Sanitize::html($dm['member_no'] ?? 'Pending') ?></strong></td>
                    <td><?= Sanitize::html($dm['name']) ?></td>
                    <td><?= Sanitize::html($dm['kgid_no'] ?? '-') ?></td>
                    <td><?= Sanitize::html($dm['personal_mobile'] ?? '-') ?></td>
                    <td><?= Sanitize::html($dm['district_name'] ?? '-') ?></td>
                    <td><?= Sanitize::html($dm['taluk_name'] ?? '-') ?></td>
                    <td><?= Sanitize::html($dm['gp_name'] ?? '-') ?></td>
                    <td>
                        <span class="badge badge-active"><?= ucfirst(Sanitize::html($dm['membership_status'])) ?></span>
                    </td>
                    <td>
                        <?php if ($dm['payment_id']): ?>
                            <span class="badge badge-paid">Paid (₹<?= number_format((float)$dm['paid_amount'], 2) ?>)</span>
                        <?php else: ?>
                            <span class="badge badge-unpaid">Unpaid</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($detailedMembers)): ?>
                <tr><td colspan="10" style="text-align:center;color:#888;padding:24px;">No member records found matching current criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalDetailedCount > $perPage): 
        $totalPages = (int)ceil($totalDetailedCount / $perPage);
    ?>
    <div style="display:flex; justify-content:center; gap:6px; margin-top:18px;">
        <?php for ($p = 1; $p <= min(10, $totalPages); $p++): 
            $pgParams = $_GET;
            $pgParams['page'] = $p;
        ?>
        <a href="/admin/members-reports.php?<?= http_build_query($pgParams) ?>" class="report-tab-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'trends'): ?>
<div class="panel">
    <h2>Membership Growth &amp; Registrations by Month</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right;">New Registrations</th>
                    <th style="text-align:right;">Active Members</th>
                    <th style="text-align:right;">Payments Recorded</th>
                    <th style="text-align:right;">Collections (₹)</th>
                    <th style="width:250px;">Growth Velocity</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $maxReg = 1;
                foreach ($trends as $tr) { if ((int)$tr['new_registrations'] > $maxReg) $maxReg = (int)$tr['new_registrations']; }
                foreach ($trends as $tr): 
                    $pct = round(((int)$tr['new_registrations'] / $maxReg) * 100);
                ?>
                <tr>
                    <td><strong><?= date('F Y', strtotime($tr['month_label'] . '-01')) ?></strong></td>
                    <td class="num"><?= (int)$tr['new_registrations'] ?></td>
                    <td class="num" style="color:#0284c7;"><?= (int)$tr['active_in_month'] ?></td>
                    <td class="num paid"><?= (int)$tr['payments_count'] ?></td>
                    <td class="num" style="font-weight:700;">₹<?= number_format((float)$tr['payments_amount'], 2) ?></td>
                    <td>
                        <div class="trend-bar-bg">
                            <div class="trend-bar-fill" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($trends)): ?>
                <tr><td colspan="6" style="text-align:center;color:#888;padding:24px;">No registration history found for the selected scope.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="print-footer">
    Designed &amp; Developed by : KHUBAASING JADAV • Karnataka State Panchayat Development Officers Welfare Association (R.)
</div>

<script>
var geo = {
    taluks: <?= json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'district_id' => (int)$t['district_id']], $allTaluks), JSON_UNESCAPED_UNICODE) ?>
};

var reportDistrictSel = document.getElementById('reportDistrictSel');
var reportTalukSel    = document.getElementById('reportTalukSel');

if (reportDistrictSel && reportTalukSel) {
    reportDistrictSel.addEventListener('change', function() {
        var did = reportDistrictSel.value;
        var list = geo.taluks.filter(function(t) { return !did || String(t.district_id) === String(did); });
        reportTalukSel.innerHTML = '<option value="">All Taluks</option>' +
            list.map(function(t) { return '<option value="' + t.id + '">' + t.name.replace(/&/g,'&amp;') + '</option>'; }).join('');
        document.getElementById('reportForm').submit();
    });
}
</script>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
