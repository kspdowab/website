<?php
/**
 * KSPDOWA — Admin: Reports & Analytics Hub (Phase 7)
 * ============================================================
 * Central analytical control center for authorized officers.
 * Gated by RBAC permission 'reports.view'.
 * Scoped to user's administrative jurisdiction (State / District / Taluk).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to view reports and analytics.');
}

$highestScope = RBAC::getHighestScopeType($currentUserId);
$isStateAdmin = ($highestScope === 'state');

// ─── Scope Resolution ─────────────────────────────────────────────────────────
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;
$scopeLabel        = 'All Karnataka Districts (Statewide Scope)';

if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') {
            $lockedDistrictId = (int)$unit['district_id'];
            $dRow = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$lockedDistrictId]);
            $scopeLabel = ($dRow['name'] ?? 'District') . ' District Scope';
        } elseif ($unit['unit_type'] === 'taluk') {
            $lockedDistrictId = (int)$unit['district_id'];
            $lockedTalukId    = (int)$unit['taluk_id'];
            $dRow = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$lockedDistrictId]);
            $tRow = Database::fetchOne("SELECT name FROM taluks WHERE id = ?", [$lockedTalukId]);
            $scopeLabel = ($tRow['name'] ?? 'Taluk') . ' Taluka, ' . ($dRow['name'] ?? '') . ' Scope';
        }
    }
}

// Build member scope WHERE
$scopeWhere  = ["1=1"];
$scopeParams = [];

if ($lockedTalukId) {
    $scopeWhere[]  = "m.taluk_id = ?";
    $scopeParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $scopeWhere[]  = "m.district_id = ?";
    $scopeParams[] = $lockedDistrictId;
}
$scopeSql = implode(' AND ', $scopeWhere);

// Current Financial Year
$currentYear = Membership::getCurrentYear();
$currentFyId = $currentYear ? (int)$currentYear['id'] : 0;
$fyLabel     = $currentYear['financial_year'] ?? 'Current FY';
$annualFee   = (float)($currentYear['fee_amount'] ?? 500);

// ─── Executive KPI Aggregations ───────────────────────────────────────────────
// 1. Membership
$mRow = Database::fetchOne(
    "SELECT COUNT(m.id) AS total_members,
            SUM(CASE WHEN m.membership_status = 'active' THEN 1 ELSE 0 END) AS active_members
     FROM members m
     WHERE $scopeSql",
    $scopeParams
);
$totalMembers  = (int)($mRow['total_members'] ?? 0);
$activeMembers = (int)($mRow['active_members'] ?? 0);

// 2. Finance
$pParams = array_merge([$currentFyId], $scopeParams);
$pRow = Database::fetchOne(
    "SELECT COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.member_id END) AS paid_members,
            COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS total_collected
     FROM members m
     LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ?
     WHERE $scopeSql",
    $pParams
);
$paidMembers    = (int)($pRow['paid_members'] ?? 0);
$totalCollected = (float)($pRow['total_collected'] ?? 0);
$unpaidMembers  = max(0, $totalMembers - $paidMembers);
$pendingDues    = $unpaidMembers * $annualFee;
$collectionRate = $totalMembers > 0 ? round(($paidMembers / $totalMembers) * 100, 1) : 0.0;

// 3. Grievances
$grvWhere  = ["1=1"];
$grvParams = [];
if ($lockedTalukId) {
    $grvWhere[]  = "m.taluk_id = ?";
    $grvParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $grvWhere[]  = "m.district_id = ?";
    $grvParams[] = $lockedDistrictId;
}
$grvSql = implode(' AND ', $grvWhere);

$gRow = Database::fetchOne(
    "SELECT COUNT(g.id) AS total_grievances,
            SUM(CASE WHEN g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Forwarded', 'Escalated', 'Pending') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count
     FROM grievances g
     INNER JOIN members m ON m.id = g.member_id
     WHERE $grvSql",
    $grvParams
);
$totalGrievances = (int)($gRow['total_grievances'] ?? 0);
$pendingGrv      = (int)($gRow['pending_count'] ?? 0);
$resolvedGrv     = (int)($gRow['resolved_count'] ?? 0);
$grvRate         = $totalGrievances > 0 ? round(($resolvedGrv / $totalGrievances) * 100, 1) : 0.0;

// 4. Governance & Activities
$totalActivities = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM activities")['total'] ?? 0);
$totalMeetings   = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM meetings")['total'] ?? 0);
$totalResolutions = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM resolutions")['total'] ?? 0);

$pageTitle   = 'Reports & Analytics Hub';
$activeMenu  = 'reports_hub';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Reports & Analytics', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .hub-header { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #3b82f6 100%); color: #fff; padding: 28px; border-radius: 10px; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15); }
    .hub-header h1 { margin: 0 0 8px; font-size: 1.6rem; font-weight: 700; }
    .hub-header p { margin: 0; opacity: 0.9; font-size: 0.92rem; max-width: 700px; }
    .hub-meta { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.18); padding: 4px 12px; border-radius: 9999px; font-size: 0.8rem; font-weight: 600; margin-top: 14px; }
    
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .kpi-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .kpi-card-title { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; }
    .kpi-card-value { font-size: 1.7rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .kpi-card-sub { font-size: 0.82rem; color: #64748b; margin-top: 6px; }
    .kpi-blue   { border-left: 4px solid #2563eb; }
    .kpi-green  { border-left: 4px solid #16a34a; }
    .kpi-amber  { border-left: 4px solid #d97706; }
    .kpi-purple { border-left: 4px solid #7c3aed; }

    .hub-modules-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 28px; }
    .hub-module-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s, box-shadow 0.2s; }
    .hub-module-card:hover { transform: translateY(-3px); box-shadow: 0 8px 16px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    .hub-module-icon { width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; }
    .hub-module-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin: 0 0 8px; }
    .hub-module-desc { font-size: 0.86rem; color: #64748b; margin: 0 0 18px; line-height: 1.5; flex-grow: 1; }
    .hub-module-features { list-style: none; padding: 0; margin: 0 0 20px; font-size: 0.82rem; color: #475569; }
    .hub-module-features li { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
    .hub-module-features li::before { content: "✓"; color: #16a34a; font-weight: bold; }
    .hub-btn { background: #1a3a6b; color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-size: 0.88rem; font-weight: 600; text-align: center; display: block; transition: background 0.2s; }
    .hub-btn:hover { background: #142c52; }
</style>

<div class="hub-header">
    <h1>Reports &amp; Analytics Center</h1>
    <p>Comprehensive statistical summaries, compliance reports, financial tracking, grievance velocity, and association governance analytics.</p>
    <div class="hub-meta">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
        <?= Sanitize::html($scopeLabel) ?> • <?= Sanitize::html($fyLabel) ?>
    </div>
</div>

<!-- ─── High-Level KPIs ────────────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-card-title">Membership Enrolment</div>
        <div class="kpi-card-value"><?= number_format($totalMembers) ?></div>
        <div class="kpi-card-sub">
            <strong><?= number_format($activeMembers) ?></strong> Active PDO Members
        </div>
    </div>

    <div class="kpi-card kpi-green">
        <div class="kpi-card-title">Fee Collections (FY)</div>
        <div class="kpi-card-value">₹<?= number_format($totalCollected, 2) ?></div>
        <div class="kpi-card-sub">
            <strong><?= $collectionRate ?>%</strong> Annual Dues Realized
        </div>
    </div>

    <div class="kpi-card kpi-amber">
        <div class="kpi-card-title">Pending Grievances</div>
        <div class="kpi-card-value"><?= number_format($pendingGrv) ?></div>
        <div class="kpi-card-sub">
            <strong><?= $grvRate ?>%</strong> Cases Resolved to Date
        </div>
    </div>

    <div class="kpi-card kpi-purple">
        <div class="kpi-card-title">Governance &amp; Actions</div>
        <div class="kpi-card-value"><?= $totalMeetings + $totalActivities ?></div>
        <div class="kpi-card-sub">
            <strong><?= $totalResolutions ?></strong> Resolutions Adopted
        </div>
    </div>
</div>

<!-- ─── Reporting Modules ──────────────────────────────────────────────────── -->
<div class="hub-modules-grid">
    <!-- 1. Membership Reports -->
    <div class="hub-module-card">
        <div>
            <div class="hub-module-icon" style="background:#eff6ff; color:#2563eb;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <h3 class="hub-module-title">Membership Reports</h3>
            <p class="hub-module-desc">District-wise and Taluk-wise membership abstract summaries, active/inactive verification, monthly enrollment growth trends, and detailed exports.</p>
            <ul class="hub-module-features">
                <li>District &amp; Taluk-wise Abstract Views</li>
                <li>Paid vs Unpaid Compliance Breakdown</li>
                <li>Growth &amp; Monthly Trends Analysis</li>
                <li>Direct PDF &amp; Excel Exports</li>
            </ul>
        </div>
        <a href="/admin/members-reports.php" class="hub-btn">Open Member Reports →</a>
    </div>

    <!-- 2. Finance Reports -->
    <div class="hub-module-card">
        <div>
            <div class="hub-module-icon" style="background:#f0fdf4; color:#16a34a;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h3 class="hub-module-title">Finance &amp; Collection Reports</h3>
            <p class="hub-module-desc">Financial-year fee collection statistics, pending annual dues analysis, Online Razorpay vs Offline manual mode breakdown, and donation auditing.</p>
            <ul class="hub-module-features">
                <li>Fee Collection &amp; Outstanding Dues</li>
                <li>Online vs Offline Mode Audit</li>
                <li>Monthly Collection Progression</li>
                <li>Donations &amp; Contributions Summary</li>
            </ul>
        </div>
        <a href="/admin/finance-reports.php" class="hub-btn">Open Finance Reports →</a>
    </div>

    <!-- 3. Grievance Analytics -->
    <div class="hub-module-card">
        <div>
            <div class="hub-module-icon" style="background:#fff7ed; color:#ea580c;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <h3 class="hub-module-title">Grievance Analytics</h3>
            <p class="hub-module-desc">Backlog tracking, government department &amp; authority performance, ageing distribution (&lt;7d to &gt;30d), and resolution turnaround velocity.</p>
            <ul class="hub-module-features">
                <li>Backlog Ageing (&lt;7d, 7-15d, 16-30d, &gt;30d)</li>
                <li>Authority &amp; Department Performance</li>
                <li>Service Category Breakdown</li>
                <li>Resolution Turnaround Tracking</li>
            </ul>
        </div>
        <a href="/admin/grievance-analytics.php" class="hub-btn">Open Grievance Analytics →</a>
    </div>

    <!-- 4. Activity & Governance Reports -->
    <div class="hub-module-card">
        <div>
            <div class="hub-module-icon" style="background:#faf5ff; color:#7c3aed;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="hub-module-title">Activity &amp; Governance Reports</h3>
            <p class="hub-module-desc">Formal record of State, District, and Taluk committee meetings, official resolutions passed, association events, and regulatory circulars issued.</p>
            <ul class="hub-module-features">
                <li>Executive &amp; Committee Meetings Summary</li>
                <li>Official Resolutions Register</li>
                <li>Association Events &amp; Program Status</li>
                <li>Quarterly Activity Performance</li>
            </ul>
        </div>
        <a href="/admin/activity-reports.php" class="hub-btn">Open Activity Reports →</a>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
