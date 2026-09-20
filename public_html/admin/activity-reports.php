<?php
/**
 * KSPDOWA — Admin: Association Activities, Meetings & Governance Reports (Phase 7)
 * ============================================================
 * Gated by RBAC permission 'reports.view' or 'activities.view' or 'meetings.view'.
 * Scoped to user's administrative jurisdiction (State / District / Taluk).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'view') && !RBAC::can($currentUserId, 'activities', 'view') && !RBAC::can($currentUserId, 'meetings', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to view activity reports.');
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
$yearFilter = Sanitize::inArray($_GET['year'] ?? (string)date('Y'), array_map('strval', range((int)date('Y') - 4, (int)date('Y') + 1))) ?: (string)date('Y');
$dateFrom   = Sanitize::date($_GET['date_from'] ?? null) ?: '';
$dateTo     = Sanitize::date($_GET['date_to']   ?? null) ?: '';
$unitLevel  = Sanitize::inArray($_GET['unit_level'] ?? 'all', ['all', 'state', 'district', 'taluk']) ?: 'all';
$status     = Sanitize::inArray($_GET['status'] ?? 'all', ['all', 'upcoming', 'completed', 'cancelled']) ?: 'all';

if ($lockedTalukId)    { $unitLevel = 'taluk'; }
elseif ($lockedDistrictId && $unitLevel === 'state') { $unitLevel = 'district'; }

// ─── Querying Activities & Events ─────────────────────────────────────────────
$actWhere  = ["1=1"];
$actParams = [];

if ($dateFrom) {
    $actWhere[]  = "a.activity_date >= ?";
    $actParams[] = $dateFrom;
}
if ($dateTo) {
    $actWhere[]  = "a.activity_date <= ?";
    $actParams[] = $dateTo;
}
if ($status !== 'all') {
    $actWhere[]  = "a.status = ?";
    $actParams[] = $status;
}
$actSql = implode(' AND ', $actWhere);

$activities = Database::fetchAll(
    "SELECT a.id, a.title, a.activity_date, a.location, a.status, a.created_at
     FROM activities a
     WHERE $actSql
     ORDER BY a.activity_date DESC
     LIMIT 50",
    $actParams
);

// ─── Querying Meetings & Governance ───────────────────────────────────────────
$meetWhere  = ["1=1"];
$meetParams = [];

if ($unitLevel !== 'all') {
    $meetWhere[]  = "m.unit_type = ?";
    $meetParams[] = $unitLevel;
}
if ($lockedTalukId) {
    $meetWhere[]  = "m.unit_id = ?";
    $meetParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $meetWhere[]  = "(m.unit_type = 'state' OR (m.unit_type = 'district' AND m.unit_id = ?))";
    $meetParams[] = $lockedDistrictId;
}

if ($dateFrom) {
    $meetWhere[]  = "m.meeting_date >= ?";
    $meetParams[] = $dateFrom;
}
if ($dateTo) {
    $meetWhere[]  = "m.meeting_date <= ?";
    $meetParams[] = $dateTo;
}
if ($status !== 'all') {
    $meetWhere[]  = "m.status = ?";
    $meetParams[] = $status;
}
$meetSql = implode(' AND ', $meetWhere);

$meetings = Database::fetchAll(
    "SELECT m.id, m.title, m.meeting_type, m.unit_type, m.meeting_date, m.venue, m.status,
            COUNT(r.id) AS resolutions_count
     FROM meetings m
     LEFT JOIN resolutions r ON r.meeting_id = m.id
     WHERE $meetSql
     GROUP BY m.id, m.title, m.meeting_type, m.unit_type, m.meeting_date, m.venue, m.status
     ORDER BY m.meeting_date DESC
     LIMIT 50",
    $meetParams
);

// ─── Administrative Summary Indicators ────────────────────────────────────────
$totalActs     = count($activities);
$totalMeets    = count($meetings);
$totalRes      = array_sum(array_column($meetings, 'resolutions_count'));
$completedActs = count(array_filter($activities, fn($a) => ($a['status'] ?? '') === 'completed'));

// Recent Official Circulars & Orders
$ordersCount = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM orders")['total'] ?? 0);
$circularsCount = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM circulars")['total'] ?? 0);

$exportQuery = http_build_query(array_filter([
    'report_type' => 'activity',
    'unit_level'  => $unitLevel,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
]));

$pageTitle   = 'Association Activities & Governance Reports';
$activeMenu  = 'reports_activities';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Reports & Analytics', 'url' => '/admin/reports.php'],
    ['label' => 'Activity Reports', 'url' => '']
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
    .kpi-purple { border-left: 4px solid #7c3aed; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
    .panel h2 { font-size: 1.15rem; color: #1a3a6b; margin: 0 0 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
    select, input[type="text"], input[type="date"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
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
    tr:hover { background: #f8fafc; }
    .table-wrap { overflow-x: auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 14px; }
    .badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .badge-comp { background: #dcfce7; color: #15803d; }
    .badge-up   { background: #eff6ff; color: #1d4ed8; }

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
    <div style="font-size:11pt;font-weight:bold;margin-top:4px;">ACTIVITIES &amp; GOVERNANCE REPORT</div>
    <div style="font-size:9pt;color:#555;margin-top:2px;">Generated on: <?= date('d-m-Y h:i A') ?></div>
</div>

<!-- ─── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-card-title">Activities &amp; Programs</div>
        <div class="kpi-card-value"><?= $totalActs ?></div>
        <div class="kpi-card-sub"><?= $completedActs ?> Successfully Completed</div>
    </div>

    <div class="kpi-card kpi-purple">
        <div class="kpi-card-title">Meetings Convened</div>
        <div class="kpi-card-value"><?= $totalMeets ?></div>
        <div class="kpi-card-sub">State / District / Taluk Committees</div>
    </div>

    <div class="kpi-card kpi-green">
        <div class="kpi-card-title">Resolutions Passed</div>
        <div class="kpi-card-value"><?= $totalRes ?></div>
        <div class="kpi-card-sub">Formal policy decisions recorded</div>
    </div>

    <div class="kpi-card kpi-amber">
        <div class="kpi-card-title">Orders &amp; Circulars</div>
        <div class="kpi-card-value"><?= $ordersCount + $circularsCount ?></div>
        <div class="kpi-card-sub"><?= $ordersCount ?> Govt Orders • <?= $circularsCount ?> Circulars</div>
    </div>
</div>

<!-- ─── Filters Panel ─────────────────────────────────────────────────────── -->
<div class="panel filter-box">
    <form method="get" action="/admin/activity-reports.php">
        <div class="row">
            <div>
                <label>Unit Level</label>
                <select name="unit_level" onchange="this.form.submit()">
                    <option value="all" <?= $unitLevel === 'all' ? 'selected' : '' ?>>All Levels</option>
                    <option value="state" <?= $unitLevel === 'state' ? 'selected' : '' ?>>State Body</option>
                    <option value="district" <?= $unitLevel === 'district' ? 'selected' : '' ?>>District Committee</option>
                    <option value="taluk" <?= $unitLevel === 'taluk' ? 'selected' : '' ?>>Taluk Committee</option>
                </select>
            </div>

            <div>
                <label>Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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
                <a href="/admin/activity-reports.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
            <div style="font-size:0.85rem; color:#64748b;">
                Showing Activities &amp; Meetings for <strong><?= Sanitize::html(ucfirst($unitLevel)) ?> Level</strong>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a href="/admin/reports-export.php?format=pdf&<?= $exportQuery ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                <a href="/admin/reports-export.php?format=excel&<?= $exportQuery ?>" class="btn" style="background:#245A57;">Export Excel</a>
                <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Print Report</button>
            </div>
        </div>
    </form>
</div>

<!-- ─── Table 1: Meetings & Governance ────────────────────────────────────── -->
<div class="panel">
    <h2>Official Meetings &amp; Resolutions Summary</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th>Meeting Title</th>
                    <th>Meeting Type</th>
                    <th>Unit Level</th>
                    <th>Date</th>
                    <th>Venue</th>
                    <th style="text-align:right;">Resolutions Passed</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($meetings as $m): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($m['title']) ?></strong></td>
                    <td><?= Sanitize::html(ucwords(str_replace('_', ' ', $m['meeting_type'] ?? 'General'))) ?></td>
                    <td><span style="font-size:0.8rem; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;"><?= ucfirst(Sanitize::html($m['unit_type'] ?? 'State')) ?></span></td>
                    <td><?= date('d-m-Y', strtotime((string)$m['meeting_date'])) ?></td>
                    <td><?= Sanitize::html($m['venue'] ?? '-') ?></td>
                    <td class="num" style="font-weight:700; color:#1a3a6b;"><?= (int)$m['resolutions_count'] ?></td>
                    <td>
                        <span class="badge <?= ($m['status'] ?? '') === 'completed' ? 'badge-comp' : 'badge-up' ?>">
                            <?= ucfirst(Sanitize::html($m['status'] ?? 'Scheduled')) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($meetings)): ?>
                <tr><td colspan="8" style="text-align:center;color:#888;padding:24px;">No meeting records found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ─── Table 2: Activities & Programs ────────────────────────────────────── -->
<div class="panel">
    <h2>Association Activities &amp; Events Register</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sl.No</th>
                    <th>Activity Title</th>
                    <th>Date</th>
                    <th>Venue / Location</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($activities as $a): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($a['title']) ?></strong></td>
                    <td><?= date('d-m-Y', strtotime((string)$a['activity_date'])) ?></td>
                    <td><?= Sanitize::html($a['location'] ?? '-') ?></td>
                    <td>
                        <span class="badge <?= ($a['status'] ?? '') === 'completed' ? 'badge-comp' : 'badge-up' ?>">
                            <?= ucfirst(Sanitize::html($a['status'] ?? 'Scheduled')) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($activities)): ?>
                <tr><td colspan="5" style="text-align:center;color:#888;padding:24px;">No activity records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="print-footer">
    Designed &amp; Developed by : KHUBAASING JADAV • Karnataka State Panchayat Development Officers Welfare Association (R.)
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
