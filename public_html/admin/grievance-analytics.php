<?php
/**
 * KSPDOWA — Admin: Grievance Analytics & Backlog Intelligence (Phase 7)
 * ============================================================
 * Gated by RBAC permission 'reports.view' or 'grievances.view'.
 * Scoped to user's administrative jurisdiction (State / District / Taluk).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'view') && !RBAC::can($currentUserId, 'grievances', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to view grievance analytics.');
}

$highestScope = RBAC::getHighestScopeType($currentUserId);
$isStateAdmin = ($highestScope === 'state');

// ─── Scope Locking ────────────────────────────────────────────────────────────
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

// ─── Input Filters ────────────────────────────────────────────────────────────
$filterDistrictId = Sanitize::positiveInt($_GET['district_id'] ?? null);
$filterTalukId    = Sanitize::positiveInt($_GET['taluk_id']    ?? null);
if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

$dateFrom     = Sanitize::date($_GET['date_from'] ?? null) ?: '';
$dateTo       = Sanitize::date($_GET['date_to']   ?? null) ?: '';
$statusFilter = Sanitize::string($_GET['status'] ?? 'all', 50) ?: 'all';
$authorityId  = Sanitize::positiveInt($_GET['authority_id'] ?? null);
$categoryId   = Sanitize::positiveInt($_GET['category_id'] ?? null);

// Master lookups
$allDistricts   = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$allTaluks      = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY district_id, name");
$allAuthorities = Database::fetchAll("SELECT id, name, code FROM grievance_authorities WHERE status='active' ORDER BY name");
$allCategories  = Database::fetchAll("SELECT id, name FROM grievance_categories WHERE status='active' ORDER BY sort_order, name");

// ─── WHERE Clause Builder ─────────────────────────────────────────────────────
$wClauses = ["1=1"];
$params   = [];

if ($filterTalukId) {
    $wClauses[] = "m.taluk_id = ?";
    $params[]   = $filterTalukId;
} elseif ($filterDistrictId) {
    $wClauses[] = "m.district_id = ?";
    $params[]   = $filterDistrictId;
}

if ($dateFrom) {
    $wClauses[] = "g.submitted_at >= ?";
    $params[]   = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $wClauses[] = "g.submitted_at <= ?";
    $params[]   = $dateTo . ' 23:59:59';
}
if ($statusFilter !== 'all') {
    $wClauses[] = "g.current_status = ?";
    $params[]   = $statusFilter;
}
if ($authorityId) {
    $wClauses[] = "g.current_authority = ?";
    $params[]   = $authorityId;
}
if ($categoryId) {
    $wClauses[] = "g.category_id = ?";
    $params[]   = $categoryId;
}

$wSql = implode(' AND ', $wClauses);

// ─── Overall KPIs ─────────────────────────────────────────────────────────────
$kpiSql = "SELECT COUNT(g.id) AS total_grievances,
                  SUM(CASE WHEN g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Forwarded', 'Escalated', 'Pending') THEN 1 ELSE 0 END) AS pending_count,
                  SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
                  SUM(CASE WHEN g.current_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
                  SUM(CASE WHEN g.current_status = 'Forwarded' THEN 1 ELSE 0 END) AS forwarded_count,
                  SUM(CASE WHEN g.current_status = 'Escalated' THEN 1 ELSE 0 END) AS escalated_count,
                  AVG(CASE WHEN g.closed_at IS NOT NULL THEN DATEDIFF(g.closed_at, g.submitted_at) END) AS avg_turnaround_days
           FROM grievances g
           INNER JOIN members m ON m.id = g.member_id
           WHERE $wSql";
$kpiRow = Database::fetchOne($kpiSql, $params);

$totalGrievances = (int)($kpiRow['total_grievances'] ?? 0);
$pendingCount    = (int)($kpiRow['pending_count'] ?? 0);
$resolvedCount   = (int)($kpiRow['resolved_count'] ?? 0);
$rejectedCount   = (int)($kpiRow['rejected_count'] ?? 0);
$forwardedCount  = (int)($kpiRow['forwarded_count'] ?? 0);
$escalatedCount  = (int)($kpiRow['escalated_count'] ?? 0);
$avgTurnaround   = round((float)($kpiRow['avg_turnaround_days'] ?? 0), 1);
$resolutionRate  = $totalGrievances > 0 ? round(($resolvedCount / $totalGrievances) * 100, 1) : 0.0;

// Reopened count
$reopenedSql = "SELECT COUNT(DISTINCT g.id) AS total_reopened
                FROM grievances g
                INNER JOIN members m ON m.id = g.member_id
                INNER JOIN grievance_events ge ON ge.grievance_id = g.id
                WHERE ge.event_type = 'reopened' AND $wSql";
$reopenedRow = Database::fetchOne($reopenedSql, $params);
$reopenedCount = (int)($reopenedRow['total_reopened'] ?? 0);

// ─── Table 1: Ageing Analysis (Pending Grievances) ─────────────────────────────
$ageingSql = "SELECT SUM(CASE WHEN DATEDIFF(NOW(), g.submitted_at) < 7 THEN 1 ELSE 0 END) AS under_7,
                     SUM(CASE WHEN DATEDIFF(NOW(), g.submitted_at) BETWEEN 7 AND 15 THEN 1 ELSE 0 END) AS days_7_15,
                     SUM(CASE WHEN DATEDIFF(NOW(), g.submitted_at) BETWEEN 16 AND 30 THEN 1 ELSE 0 END) AS days_16_30,
                     SUM(CASE WHEN DATEDIFF(NOW(), g.submitted_at) > 30 THEN 1 ELSE 0 END) AS over_30
              FROM grievances g
              INNER JOIN members m ON m.id = g.member_id
              WHERE g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Forwarded', 'Escalated', 'Pending') AND $wSql";
$ageingRow = Database::fetchOne($ageingSql, $params);
$ageUnder7  = (int)($ageingRow['under_7'] ?? 0);
$age7to15   = (int)($ageingRow['days_7_15'] ?? 0);
$age16to30  = (int)($ageingRow['days_16_30'] ?? 0);
$ageOver30  = (int)($ageingRow['over_30'] ?? 0);

// ─── Table 2: Authority-wise Backlog ──────────────────────────────────────────
$authorityStats = Database::fetchAll(
    "SELECT ga.id, ga.name AS authority_name, ga.code AS authority_code,
            COUNT(g.id) AS total_assigned,
            SUM(CASE WHEN g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Forwarded', 'Escalated', 'Pending') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN g.current_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count
     FROM grievance_authorities ga
     LEFT JOIN grievances g ON g.current_authority = ga.id
     LEFT JOIN members m    ON m.id = g.member_id AND $wSql
     WHERE ga.status = 'active'
     GROUP BY ga.id, ga.name, ga.code
     ORDER BY total_assigned DESC, ga.name ASC",
    $params
);

// ─── Table 3: Service Category Breakdown ──────────────────────────────────────
$categoryStats = Database::fetchAll(
    "SELECT gc.id, gc.name AS category_name,
            COUNT(g.id) AS total_count,
            SUM(CASE WHEN g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Forwarded', 'Escalated', 'Pending') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count
     FROM grievance_categories gc
     LEFT JOIN grievances g ON g.category_id = gc.id
     LEFT JOIN members m    ON m.id = g.member_id AND $wSql
     WHERE gc.status = 'active'
     GROUP BY gc.id, gc.name
     ORDER BY total_count DESC, gc.name ASC",
    $params
);

// ─── Table 4: Monthly Resolution Progression ──────────────────────────────────
$monthlyProgression = Database::fetchAll(
    "SELECT DATE_FORMAT(g.submitted_at, '%Y-%m') AS month_key,
            COUNT(g.id) AS submitted_count,
            SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN g.current_status = 'Forwarded' THEN 1 ELSE 0 END) AS forwarded_count
     FROM grievances g
     INNER JOIN members m ON m.id = g.member_id
     WHERE $wSql
     GROUP BY DATE_FORMAT(g.submitted_at, '%Y-%m')
     ORDER BY month_key ASC",
    $params
);

// Export Query
$exportQuery = http_build_query(array_filter([
    'report_type'  => 'grievance',
    'district_id'  => $filterDistrictId,
    'taluk_id'     => $filterTalukId,
    'date_from'    => $dateFrom,
    'date_to'      => $dateTo,
    'authority_id' => $authorityId,
    'category_id'  => $categoryId,
]));

$pageTitle   = 'Grievance Analytics & Backlog Intelligence';
$activeMenu  = 'reports_grievances';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Reports & Analytics', 'url' => '/admin/reports.php'],
    ['label' => 'Grievance Analytics', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .kpi-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .kpi-card-title { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; }
    .kpi-card-value { font-size: 1.65rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .kpi-card-sub { font-size: 0.82rem; color: #64748b; margin-top: 6px; }
    .kpi-blue   { border-left: 4px solid #2563eb; }
    .kpi-green  { border-left: 4px solid #16a34a; }
    .kpi-amber  { border-left: 4px solid #d97706; }
    .kpi-red    { border-left: 4px solid #dc2626; }
    .kpi-purple { border-left: 4px solid #7c3aed; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
    .panel h2 { font-size: 1.15rem; color: #1a3a6b; margin: 0 0 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
    select, input[type="text"], input[type="date"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
    input[readonly] { background: #f0f3f7; color: #888; }
    .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
    .row > div { flex: 1; min-width: 150px; }
    .btn { background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn:hover { background: #142c52; }
    .btn-secondary { background: #475569; }
    .btn-secondary:hover { background: #334155; }
    .btn-print { background: #0284c7; }
    .btn-print:hover { background: #0369a1; }
    table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
    th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; }
    th { color: #ffffff; font-weight: 600; background: var(--blue-800, #1e40af); font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px; border: none; white-space: nowrap; }
    td.num { text-align: right; font-weight: 600; }
    tfoot { font-weight: bold; background: #f8fafc; border-top: 2px solid #cbd5e1; }
    tfoot td.num { font-weight: 700; }
    tr:hover { background: #f8fafc; }
    .table-wrap { overflow-x: auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 14px; }
    .ageing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 16px; }
    .ageing-box { padding: 16px; border-radius: 6px; text-align: center; }
    .ageing-box .count { font-size: 1.8rem; font-weight: 700; margin-top: 4px; }

    @media print {
        aside, .admin-topbar, .filter-box, .btn, footer { display: none !important; }
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
    <div style="font-size:11pt;font-weight:bold;margin-top:4px;">GRIEVANCE RESOLUTION &amp; BACKLOG ANALYTICS REPORT</div>
    <div style="font-size:9pt;color:#555;margin-top:2px;">Generated on: <?= date('d-m-Y h:i A') ?></div>
</div>

<!-- ─── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-card-title">Total Registered</div>
        <div class="kpi-card-value"><?= number_format($totalGrievances) ?></div>
        <div class="kpi-card-sub">In current jurisdiction scope</div>
    </div>

    <div class="kpi-card kpi-amber">
        <div class="kpi-card-title">Pending / Backlog</div>
        <div class="kpi-card-value"><?= number_format($pendingCount) ?></div>
        <div class="kpi-card-sub">
            <?= $forwardedCount ?> Forwarded • <?= $escalatedCount ?> Escalated
        </div>
    </div>

    <div class="kpi-card kpi-green">
        <div class="kpi-card-title">Resolved &amp; Closed</div>
        <div class="kpi-card-value"><?= number_format($resolvedCount) ?></div>
        <div class="kpi-card-sub">
            <strong><?= $resolutionRate ?>%</strong> Overall Resolution Rate
        </div>
    </div>

    <div class="kpi-card kpi-purple">
        <div class="kpi-card-title">Avg. Turnaround</div>
        <div class="kpi-card-value"><?= $avgTurnaround ?> Days</div>
        <div class="kpi-card-sub">
            <?= $reopenedCount ?> Cases Reopened for Review
        </div>
    </div>
</div>

<!-- ─── Filters Panel ─────────────────────────────────────────────────────── -->
<div class="panel filter-box">
    <form method="get" action="/admin/grievance-analytics.php" id="grvFilterForm">
        <div class="row">
            <div>
                <label>District</label>
                <?php if ($lockedDistrictId): ?>
                    <input type="text" value="<?= Sanitize::attr(array_column($allDistricts,'name','id')[$lockedDistrictId] ?? 'Your District') ?>" readonly>
                    <input type="hidden" name="district_id" value="<?= $lockedDistrictId ?>">
                <?php else: ?>
                <select name="district_id" id="grvDistSel">
                    <option value="">All Districts</option>
                    <?php foreach ($allDistricts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $d['id'] == $filterDistrictId ? 'selected' : '' ?>><?= Sanitize::html($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div>
                <label>Taluk</label>
                <?php if ($lockedTalukId): ?>
                    <input type="text" value="<?= Sanitize::attr(array_column($allTaluks,'name','id')[$lockedTalukId] ?? 'Your Taluk') ?>" readonly>
                    <input type="hidden" name="taluk_id" value="<?= $lockedTalukId ?>">
                <?php else: ?>
                <select name="taluk_id" id="grvTalukSel">
                    <option value="">All Taluks</option>
                    <?php foreach ($allTaluks as $t): ?>
                        <?php if (!$filterDistrictId || $t['district_id'] == $filterDistrictId): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $filterTalukId ? 'selected' : '' ?>><?= Sanitize::html($t['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div>
                <label>Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Submitted" <?= $statusFilter === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="Under Review" <?= $statusFilter === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
                    <option value="Forwarded" <?= $statusFilter === 'Forwarded' ? 'selected' : '' ?>>Forwarded</option>
                    <option value="Escalated" <?= $statusFilter === 'Escalated' ? 'selected' : '' ?>>Escalated</option>
                    <option value="Resolved" <?= $statusFilter === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                    <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div>
                <label>Pursuing Authority</label>
                <select name="authority_id" onchange="this.form.submit()">
                    <option value="">All Authorities</option>
                    <?php foreach ($allAuthorities as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $a['id'] == $authorityId ? 'selected' : '' ?>><?= Sanitize::html($a['name']) ?> (<?= Sanitize::html($a['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Category</label>
                <select name="category_id" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($allCategories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $categoryId ? 'selected' : '' ?>><?= Sanitize::html($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>From Date</label>
                <input type="date" name="date_from" value="<?= Sanitize::attr($dateFrom) ?>">
            </div>

            <div>
                <label>To Date</label>
                <input type="date" name="date_to" value="<?= Sanitize::attr($dateTo) ?>">
            </div>

            <div style="flex:0; display:flex; gap:8px;">
                <button type="submit" class="btn">Filter</button>
                <a href="/admin/grievance-analytics.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
            <div style="font-size:0.85rem; color:#64748b;">
                Scope: <strong><?= Sanitize::html($highestScope === 'state' ? ($filterDistrictId ? 'District Filtered' : 'Karnataka Statewide') : ($lockedDistrictId ? 'District Scoped' : 'Taluk Scoped')) ?></strong>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a href="/admin/reports-export.php?format=pdf&<?= $exportQuery ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                <a href="/admin/reports-export.php?format=excel&<?= $exportQuery ?>" class="btn" style="background:#245A57;">Export Excel</a>
                <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Print Report</button>
            </div>
        </div>
    </form>
</div>

<!-- ─── Ageing Analysis Panel ──────────────────────────────────────────────── -->
<div class="panel">
    <h2>Pending Grievance Ageing Backlog</h2>
    <div class="ageing-grid">
        <div class="ageing-box" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;">
            <div style="font-size:0.85rem; font-weight:600;">&lt; 7 Days (Fresh)</div>
            <div class="count"><?= $ageUnder7 ?></div>
            <div style="font-size:0.75rem; color:#3b82f6;">Immediate Action</div>
        </div>

        <div class="ageing-box" style="background:#fefce8; border:1px solid #fef08a; color:#854d0e;">
            <div style="font-size:0.85rem; font-weight:600;">7 to 15 Days (Active)</div>
            <div class="count"><?= $age7to15 ?></div>
            <div style="font-size:0.75rem; color:#ca8a04;">Follow-up Stage</div>
        </div>

        <div class="ageing-box" style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
            <div style="font-size:0.85rem; font-weight:600;">16 to 30 Days (Warning)</div>
            <div class="count"><?= $age16to30 ?></div>
            <div style="font-size:0.75rem; color:#ea580c;">Attention Needed</div>
        </div>

        <div class="ageing-box" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b;">
            <div style="font-size:0.85rem; font-weight:600;">&gt; 30 Days (Critical Backlog)</div>
            <div class="count"><?= $ageOver30 ?></div>
            <div style="font-size:0.75rem; color:#dc2626;">Escalation Priority</div>
        </div>
    </div>
</div>

<!-- ─── Table: Authority Backlog Distribution ─────────────────────────────── -->
<div class="panel">
    <h2>Authority-wise Grievance Tracking &amp; Backlog</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th>Pursuing Authority</th>
                    <th>Code</th>
                    <th style="text-align:right;">Total Assigned</th>
                    <th style="text-align:right;">Active Pending</th>
                    <th style="text-align:right;">Resolved</th>
                    <th style="text-align:right;">Rejected</th>
                    <th style="text-align:right;">Resolution Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1; 
                $totA = 0; $totP = 0; $totR = 0; $totRej = 0;
                foreach ($authorityStats as $row): 
                    $rate = (int)$row['total_assigned'] > 0 ? round(((int)$row['resolved_count'] / (int)$row['total_assigned']) * 100, 1) : 0;
                    $totA += (int)$row['total_assigned'];
                    $totP += (int)$row['pending_count'];
                    $totR += (int)$row['resolved_count'];
                    $totRej += (int)$row['rejected_count'];
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($row['authority_name']) ?></strong></td>
                    <td><span style="font-size:0.8rem; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;"><?= Sanitize::html($row['authority_code']) ?></span></td>
                    <td class="num"><?= (int)$row['total_assigned'] ?></td>
                    <td class="num" style="color:#d97706;"><?= (int)$row['pending_count'] ?></td>
                    <td class="num" style="color:#15803d;"><?= (int)$row['resolved_count'] ?></td>
                    <td class="num" style="color:#94a3b8;"><?= (int)$row['rejected_count'] ?></td>
                    <td class="num" style="font-weight:700; color: <?= $rate >= 70 ? '#15803d' : ($rate >= 40 ? '#d97706' : '#b91c1c') ?>;"><?= $rate ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($authorityStats)): ?>
                <tr><td colspan="8" style="text-align:center;color:#888;padding:24px;">No authority records available.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($authorityStats)): 
                $grandRate = $totA > 0 ? round(($totR / $totA) * 100, 1) : 0;
            ?>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">GRAND TOTAL</td>
                    <td class="num"><?= $totA ?></td>
                    <td class="num" style="color:#d97706;"><?= $totP ?></td>
                    <td class="num" style="color:#15803d;"><?= $totR ?></td>
                    <td class="num" style="color:#94a3b8;"><?= $totRej ?></td>
                    <td class="num" style="font-weight:700;"><?= $grandRate ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ─── Table: Category Breakdown ─────────────────────────────────────────── -->
<div class="panel">
    <h2>Category-wise Performance Summary</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th style="text-align:right;">Total Cases</th>
                    <th style="text-align:right;">Pending</th>
                    <th style="text-align:right;">Resolved</th>
                    <th style="text-align:right;">Resolution Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categoryStats as $cs): 
                    $cRate = (int)$cs['total_count'] > 0 ? round(((int)$cs['resolved_count'] / (int)$cs['total_count']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><strong><?= Sanitize::html($cs['category_name']) ?></strong></td>
                    <td class="num"><?= (int)$cs['total_count'] ?></td>
                    <td class="num" style="color:#d97706;"><?= (int)$cs['pending_count'] ?></td>
                    <td class="num" style="color:#15803d;"><?= (int)$cs['resolved_count'] ?></td>
                    <td class="num" style="font-weight:700;"><?= $cRate ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="print-footer">
    Designed &amp; Developed by : KHUBAASING JADAV • Karnataka State Panchayat Development Officers Welfare Association (R.)
</div>

<script>
var geo = {
    taluks: <?= json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'district_id' => (int)$t['district_id']], $allTaluks), JSON_UNESCAPED_UNICODE) ?>
};

var grvDistSel  = document.getElementById('grvDistSel');
var grvTalukSel = document.getElementById('grvTalukSel');

if (grvDistSel && grvTalukSel) {
    grvDistSel.addEventListener('change', function() {
        var did = grvDistSel.value;
        var list = geo.taluks.filter(function(t) { return !did || String(t.district_id) === String(did); });
        grvTalukSel.innerHTML = '<option value="">All Taluks</option>' +
            list.map(function(t) { return '<option value="' + t.id + '">' + t.name.replace(/&/g,'&amp;') + '</option>'; }).join('');
        document.getElementById('grvFilterForm').submit();
    });
}
</script>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
