<?php
/**
 * KSPDOWA — Admin: Finance & Fee Collection Reports (Phase 7)
 * ============================================================
 * Gated by RBAC permission 'reports.view' or 'payments.view'.
 * Scoped to user's administrative jurisdiction (State / District / Taluk).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'view') && !RBAC::can($currentUserId, 'payments', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to view finance reports.');
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
$fyId = Sanitize::positiveInt($_GET['fy_id'] ?? null);
$years = Database::fetchAll("SELECT id, financial_year, fee_amount, status FROM membership_years ORDER BY start_date DESC");
if (!$fyId && !empty($years)) {
    $activeYears = array_filter($years, fn($y) => $y['status'] === 'active');
    $fyId = $activeYears ? (int)reset($activeYears)['id'] : (int)$years[0]['id'];
}
$selectedYear = null;
foreach ($years as $y) { if ((int)$y['id'] === $fyId) { $selectedYear = $y; break; } }
$annualFee = (float)($selectedYear['fee_amount'] ?? 500);

$filterDistrictId = Sanitize::positiveInt($_GET['district_id'] ?? null);
$filterTalukId    = Sanitize::positiveInt($_GET['taluk_id']    ?? null);
if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

$dateFrom     = Sanitize::date($_GET['date_from'] ?? null) ?: '';
$dateTo       = Sanitize::date($_GET['date_to']   ?? null) ?: '';
$paymentMode  = Sanitize::inArray($_GET['payment_mode'] ?? 'all', ['all', 'online', 'offline']) ?: 'all';
$statusFilter = Sanitize::inArray($_GET['status'] ?? 'all', ['all', 'completed', 'pending', 'failed']) ?: 'all';

// Cascading data
$allDistricts = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$allTaluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY district_id, name");

// ─── WHERE SQL Builder ────────────────────────────────────────────────────────
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
    $wClauses[] = "p.paid_at >= ?";
    $params[]   = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $wClauses[] = "p.paid_at <= ?";
    $params[]   = $dateTo . ' 23:59:59';
}
if ($paymentMode !== 'all') {
    $wClauses[] = "p.payment_method = ?";
    $params[]   = $paymentMode;
}
if ($statusFilter !== 'all') {
    $wClauses[] = "p.status = ?";
    $params[]   = $statusFilter;
}

$wSql = implode(' AND ', $wClauses);

// ─── KPI Queries ──────────────────────────────────────────────────────────────
// Total Members in current scope
$mScopeW = ["1=1"];
$mScopeP = [];
if ($filterTalukId) {
    $mScopeW[] = "m.taluk_id = ?";
    $mScopeP[] = $filterTalukId;
} elseif ($filterDistrictId) {
    $mScopeW[] = "m.district_id = ?";
    $mScopeP[] = $filterDistrictId;
}
$mScopeSql = implode(' AND ', $mScopeW);

$totalMembersInScope = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM members m WHERE $mScopeSql", $mScopeP)['total'] ?? 0);

// Fee Collections for FY
$kpiParams = array_merge([$fyId ?: 0], $params);
$kpiRow = Database::fetchOne(
    "SELECT COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.member_id END) AS paid_members,
            COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS total_collected,
            COUNT(CASE WHEN p.status = 'completed' THEN p.id END) AS completed_txns,
            COUNT(CASE WHEN p.status = 'pending' THEN p.id END) AS pending_txns,
            COUNT(CASE WHEN p.status = 'failed' THEN p.id END) AS failed_txns,
            COALESCE(SUM(CASE WHEN p.payment_method = 'online' AND p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS online_collected,
            COALESCE(SUM(CASE WHEN p.payment_method = 'offline' AND p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS offline_collected
     FROM members m
     LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ?
     WHERE $wSql",
    $kpiParams
);

$paidMembersCount = (int)($kpiRow['paid_members'] ?? 0);
$totalCollected   = (float)($kpiRow['total_collected'] ?? 0);
$unpaidMembers    = max(0, $totalMembersInScope - $paidMembersCount);
$expectedDues     = $totalMembersInScope * $annualFee;
$pendingDues      = $unpaidMembers * $annualFee;
$collectionRate   = $totalMembersInScope > 0 ? round(($paidMembersCount / $totalMembersInScope) * 100, 1) : 0.0;

// Donations Summary (Statewide or general)
$donationsRow = Database::fetchOne(
    "SELECT COUNT(*) AS total_donations,
            COALESCE(SUM(amount), 0) AS total_amount
     FROM donations
     WHERE status = 'completed'"
);
$totalDonationsCount  = (int)($donationsRow['total_donations'] ?? 0);
$totalDonationsAmount = (float)($donationsRow['total_amount'] ?? 0);

// ─── Table 1: Geographic Collection Breakdown ─────────────────────────────────
$geoBreakdown = Database::fetchAll(
    "SELECT d.name AS district_name, t.name AS taluk_name,
            COUNT(m.id) AS total_members,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.member_id END) AS paid_members,
            COUNT(m.id) - COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.member_id END) AS unpaid_members,
            COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS total_collected,
            (COUNT(m.id) - COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.member_id END)) * {$annualFee} AS total_pending
     FROM taluks t
     JOIN districts d ON d.id = t.district_id
     JOIN members   m ON m.taluk_id = t.id
     LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
     WHERE $wSql
     GROUP BY d.name, t.name
     ORDER BY total_collected DESC, d.name ASC, t.name ASC",
    $params
);

// ─── Table 2: Monthly Collection Progression ──────────────────────────────────
$monthlyTrend = Database::fetchAll(
    "SELECT DATE_FORMAT(p.paid_at, '%Y-%m') AS month_key,
            COUNT(p.id) AS txn_count,
            COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount ELSE 0 END), 0) AS online_amt,
            COALESCE(SUM(CASE WHEN p.payment_method = 'offline' THEN p.amount ELSE 0 END), 0) AS offline_amt,
            COALESCE(SUM(p.amount), 0) AS month_total
     FROM membership_payments p
     JOIN members m ON m.id = p.member_id
     WHERE p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed' AND $wSql
     GROUP BY DATE_FORMAT(p.paid_at, '%Y-%m')
     ORDER BY month_key ASC",
    $params
);

// Export query string
$exportQuery = http_build_query(array_filter([
    'report_type' => 'finance',
    'fy_id'       => $fyId,
    'district_id' => $filterDistrictId,
    'taluk_id'    => $filterTalukId,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
]));

$pageTitle   = 'Finance & Fee Collection Reports';
$activeMenu  = 'reports_finance';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Reports & Analytics', 'url' => '/admin/reports.php'],
    ['label' => 'Finance Reports', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .kpi-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .kpi-card-title { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; }
    .kpi-card-value { font-size: 1.65rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .kpi-card-sub { font-size: 0.82rem; color: #64748b; margin-top: 6px; }
    .kpi-green { border-left: 4px solid #16a34a; }
    .kpi-blue  { border-left: 4px solid #2563eb; }
    .kpi-amber { border-left: 4px solid #d97706; }
    .kpi-purple{ border-left: 4px solid #7c3aed; }
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
    td.paid { color: #15803d; }
    td.unpaid { color: #b91c1c; }
    tfoot { font-weight: bold; background: #f8fafc; border-top: 2px solid #cbd5e1; }
    tfoot td.num { font-weight: 700; }
    tr:hover { background: #f8fafc; }
    .table-wrap { overflow-x: auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 14px; }
    .progress-wrap { background: #e2e8f0; border-radius: 9999px; height: 10px; width: 100%; overflow: hidden; margin-top: 8px; }
    .progress-bar { height: 100%; border-radius: 9999px; background: #16a34a; }

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
    <div style="font-size:11pt;font-weight:bold;margin-top:4px;">FINANCIAL COLLECTIONS &amp; DUES REPORT (<?= Sanitize::html($selectedYear['financial_year'] ?? '') ?>)</div>
    <div style="font-size:9pt;color:#555;margin-top:2px;">Generated on: <?= date('d-m-Y h:i A') ?></div>
</div>

<!-- ─── KPI Executive Cards ────────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card kpi-green">
        <div class="kpi-card-title">Total Collected (FY)</div>
        <div class="kpi-card-value">₹<?= number_format($totalCollected, 2) ?></div>
        <div class="kpi-card-sub">
            <strong><?= number_format($paidMembersCount) ?></strong> Paid Members (₹<?= number_format($annualFee, 2) ?>/yr)
        </div>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?= min(100, $collectionRate) ?>%;"></div>
        </div>
    </div>

    <div class="kpi-card kpi-amber">
        <div class="kpi-card-title">Pending Annual Dues</div>
        <div class="kpi-card-value">₹<?= number_format($pendingDues, 2) ?></div>
        <div class="kpi-card-sub">
            <strong><?= number_format($unpaidMembers) ?></strong> Members Pending Renewal
        </div>
    </div>

    <div class="kpi-card kpi-blue">
        <div class="kpi-card-title">Collection Rate</div>
        <div class="kpi-card-value"><?= $collectionRate ?>%</div>
        <div class="kpi-card-sub">
            Online: ₹<?= number_format((float)($kpiRow['online_collected'] ?? 0), 2) ?> | Offline: ₹<?= number_format((float)($kpiRow['offline_collected'] ?? 0), 2) ?>
        </div>
    </div>

    <div class="kpi-card kpi-purple">
        <div class="kpi-card-title">Total Association Donations</div>
        <div class="kpi-card-value">₹<?= number_format($totalDonationsAmount, 2) ?></div>
        <div class="kpi-card-sub">
            <strong><?= number_format($totalDonationsCount) ?></strong> Contributions Recorded
        </div>
    </div>
</div>

<!-- ─── Filters Panel ─────────────────────────────────────────────────────── -->
<div class="panel filter-box">
    <form method="get" action="/admin/finance-reports.php" id="financeFilterForm">
        <div class="row">
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
                <select name="district_id" id="repDistrictSel">
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
                <select name="taluk_id" id="repTalukSel">
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
                <label>Payment Mode</label>
                <select name="payment_mode" onchange="this.form.submit()">
                    <option value="all" <?= $paymentMode === 'all' ? 'selected' : '' ?>>All Modes</option>
                    <option value="online" <?= $paymentMode === 'online' ? 'selected' : '' ?>>Online (Razorpay)</option>
                    <option value="offline" <?= $paymentMode === 'offline' ? 'selected' : '' ?>>Offline (Cash/DD/NEFT)</option>
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
                <a href="/admin/finance-reports.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing Finance Records for <strong><?= Sanitize::html($selectedYear['financial_year'] ?? '') ?></strong> • Scope: <strong><?= Sanitize::html($highestScope === 'state' ? ($filterDistrictId ? 'District Filtered' : 'Karnataka Statewide') : ($lockedDistrictId ? 'District Scoped' : 'Taluk Scoped')) ?></strong>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a href="/admin/reports-export.php?format=pdf&<?= $exportQuery ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                <a href="/admin/reports-export.php?format=excel&<?= $exportQuery ?>" class="btn" style="background:#245A57;">Export Excel</a>
                <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Print Report</button>
            </div>
        </div>
    </form>
</div>

<!-- ─── Table: Geographic Collection Breakdown ─────────────────────────────── -->
<div class="panel">
    <h2>Fee Collections &amp; Pending Dues by Jurisdiction</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th>District</th>
                    <th>Taluk</th>
                    <th style="text-align:right;">Total Members</th>
                    <th style="text-align:right;">Paid Members</th>
                    <th style="text-align:right;">Unpaid Members</th>
                    <th style="text-align:right;">Collection %</th>
                    <th style="text-align:right;">Collected (₹)</th>
                    <th style="text-align:right;">Pending Dues (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1; 
                $sumM = 0; $sumP = 0; $sumU = 0; $sumCol = 0.0; $sumDue = 0.0;
                foreach ($geoBreakdown as $row): 
                    $rate = (int)$row['total_members'] > 0 ? round(((int)$row['paid_members'] / (int)$row['total_members']) * 100, 1) : 0;
                    $sumM += (int)$row['total_members'];
                    $sumP += (int)$row['paid_members'];
                    $sumU += (int)$row['unpaid_members'];
                    $sumCol += (float)$row['total_collected'];
                    $sumDue += (float)$row['total_pending'];
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($row['district_name']) ?></strong></td>
                    <td><?= Sanitize::html($row['taluk_name']) ?></td>
                    <td class="num"><?= (int)$row['total_members'] ?></td>
                    <td class="num paid"><?= (int)$row['paid_members'] ?></td>
                    <td class="num unpaid"><?= (int)$row['unpaid_members'] ?></td>
                    <td class="num" style="color: <?= $rate >= 80 ? '#15803d' : ($rate >= 50 ? '#d97706' : '#b91c1c') ?>; font-weight:700;"><?= $rate ?>%</td>
                    <td class="num" style="font-weight:700; color:#15803d;">₹<?= number_format((float)$row['total_collected'], 2) ?></td>
                    <td class="num" style="font-weight:700; color:#b91c1c;">₹<?= number_format((float)$row['total_pending'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($geoBreakdown)): ?>
                <tr><td colspan="9" style="text-align:center;color:#888;padding:24px;">No collections recorded for the selected criteria.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($geoBreakdown)): 
                $overallRate = $sumM > 0 ? round(($sumP / $sumM) * 100, 1) : 0;
            ?>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">GRAND TOTAL</td>
                    <td class="num"><?= $sumM ?></td>
                    <td class="num paid"><?= $sumP ?></td>
                    <td class="num unpaid"><?= $sumU ?></td>
                    <td class="num"><?= $overallRate ?>%</td>
                    <td class="num" style="color:#15803d;">₹<?= number_format($sumCol, 2) ?></td>
                    <td class="num" style="color:#b91c1c;">₹<?= number_format($sumDue, 2) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ─── Table: Monthly Revenue Trend ──────────────────────────────────────── -->
<?php if (!empty($monthlyTrend)): ?>
<div class="panel">
    <h2>Monthly Collection Progression (<?= Sanitize::html($selectedYear['financial_year'] ?? '') ?>)</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right;">Transactions</th>
                    <th style="text-align:right;">Online Collections (Razorpay)</th>
                    <th style="text-align:right;">Offline Collections (Manual)</th>
                    <th style="text-align:right;">Month Total (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyTrend as $mt): ?>
                <tr>
                    <td><strong><?= date('F Y', strtotime($mt['month_key'] . '-01')) ?></strong></td>
                    <td class="num"><?= (int)$mt['txn_count'] ?></td>
                    <td class="num" style="color:#2563eb;">₹<?= number_format((float)$mt['online_amt'], 2) ?></td>
                    <td class="num" style="color:#7c3aed;">₹<?= number_format((float)$mt['offline_amt'], 2) ?></td>
                    <td class="num" style="font-weight:700; color:#15803d;">₹<?= number_format((float)$mt['month_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
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

var repDistrictSel = document.getElementById('repDistrictSel');
var repTalukSel    = document.getElementById('repTalukSel');

if (repDistrictSel && repTalukSel) {
    repDistrictSel.addEventListener('change', function() {
        var did = repDistrictSel.value;
        var list = geo.taluks.filter(function(t) { return !did || String(t.district_id) === String(did); });
        repTalukSel.innerHTML = '<option value="">All Taluks</option>' +
            list.map(function(t) { return '<option value="' + t.id + '">' + t.name.replace(/&/g,'&amp;') + '</option>'; }).join('');
        document.getElementById('financeFilterForm').submit();
    });
}
</script>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
