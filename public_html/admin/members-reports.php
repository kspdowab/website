<?php
/**
 * KSPDOWA — Admin: Members Abstract Reports
 * ============================================================
 * Gated by RBAC permission 'members.manage'.
 * Spec: docs/11_MEMBERS_MODULE_SPECIFICATION.md §18-§23
 *
 * Features:
 *  - District-wise and Taluk-wise views
 *  - District + Taluk filter dropdowns (dependent)
 *  - Sortable columns: all numeric + text columns, ASC/DESC
 *  - Default sort: Paid Members DESC
 *  - Sl.No regenerated after sort
 *  - Export links preserve all active filter/sort params
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

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
$view = Sanitize::inArray($_GET['view'] ?? '', ['district','taluk']) ?: 'district';

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
if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

// Sorting
$allowedSortDistrict = ['district_name','total_members','paid_members','unpaid_members'];
$allowedSortTaluk    = ['district_name','taluk_name','total_members','paid_members','unpaid_members'];
$allowedSort         = ($view === 'district') ? $allowedSortDistrict : $allowedSortTaluk;

$sortBy  = Sanitize::inArray($_GET['sort_by']  ?? '', $allowedSort)  ?: 'paid_members';
$sortDir = Sanitize::inArray($_GET['sort_dir'] ?? '', ['asc','desc']) ?: 'desc';
$sqlDir  = $sortDir === 'asc' ? 'ASC' : 'DESC';

// Geo data for JS cascading
$allDistricts = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$allTaluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY district_id, name");

// ─── Data queries ──────────────────────────────────────────────────────────────
$report = [];

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
    // Numeric sort columns need explicit numeric cast for correct ordering
    $orderExpr = in_array($sortBy, ['total_members','paid_members','unpaid_members'])
        ? "CAST($sortBy AS UNSIGNED) $sqlDir"
        : "$sortBy $sqlDir";

    $sql = "SELECT d.id AS district_id, d.name AS district_name,
                   COUNT(m.id) AS total_members,
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

    $orderExpr = in_array($sortBy, ['total_members','paid_members','unpaid_members'])
        ? "CAST($sortBy AS UNSIGNED) $sqlDir"
        : "$sortBy $sqlDir";

    $sql = "SELECT d.name AS district_name, t.name AS taluk_name,
                   COUNT(m.id) AS total_members,
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

$grandTotal  = 0;
$grandPaid   = 0;
$grandUnpaid = 0;
foreach ($report as $r) {
    $grandTotal  += (int)$r['total_members'];
    $grandPaid   += (int)$r['paid_members'];
    $grandUnpaid += (int)$r['unpaid_members'];
}

// Export params
$exportParams = http_build_query(array_filter([
    'fy_id'       => $fyId,
    'district_id' => $filterDistrictId,
    'taluk_id'    => $filterTalukId,
    'sort_by'     => $sortBy,
    'sort_dir'    => $sortDir,
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
?>
$pageTitle   = 'Members Abstract Reports';
$activeMenu  = 'reports';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Members', 'url' => '/admin/members.php'],
    ['label' => 'Reports', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .sub-nav { background: #fff; border-bottom: 1px solid #cbd5e1; padding: 0 24px; display: flex; gap: 20px; margin-bottom: 24px; border-radius: 6px; }
    .sub-nav a { display: inline-block; padding: 12px 4px; color: #556; text-decoration: none; font-weight: 600; font-size: 0.9rem; border-bottom: 3px solid transparent; }
    .sub-nav a:hover { color: #1a3a6b; }
    .sub-nav a.active { color: #1a3a6b; border-bottom-color: #1a3a6b; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
    .panel h2 { font-size: 1.1rem; color: #1a3a6b; margin: 0 0 16px; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
    select, input[type="text"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
    input[readonly] { background: #f0f3f7; color: #888; }
    .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
    .row > div { flex: 1; min-width: 160px; }
    .btn { background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn:hover { background: #142c52; }
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
</style>

<div class="sub-nav">
    <a href="/admin/members.php">Members List</a>
    <a href="/admin/members.php?add=1">+ Add Member</a>
    <a href="/admin/members-import.php">Bulk Import</a>
    <a href="/admin/members-reports.php" class="active">Abstract Reports</a>
</div>
    <div class="panel">
        <form method="get" action="/admin/members-reports.php" id="reportForm">
            <div class="row">
                <div>
                    <label>View By</label>
                    <select name="view" onchange="this.form.submit()">
                        <option value="district" <?= $view === 'district' ? 'selected' : '' ?>>District-wise</option>
                        <option value="taluk"    <?= $view === 'taluk'    ? 'selected' : '' ?>>Taluk-wise</option>
                    </select>
                </div>
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
                <?php if ($view === 'taluk'): ?>
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
                <!-- preserve sort params across filter submits -->
                <input type="hidden" name="sort_by"  value="<?= Sanitize::attr($sortBy) ?>">
                <input type="hidden" name="sort_dir" value="<?= Sanitize::attr($sortDir) ?>">
                <div style="flex:0;"><button type="submit" class="btn">Filter</button></div>
            </div>
            <div>
                <a href="/admin/members-export.php?format=pdf&report_type=abstract_<?= $view ?>&<?= $exportParams ?>" target="_blank" class="btn" style="background:#2C6B67;">Export PDF</a>
                <a href="/admin/members-export.php?format=excel&report_type=abstract_<?= $view ?>&<?= $exportParams ?>" class="btn" style="background:#245A57;">Export Excel</a>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2><?= $view === 'district' ? 'District-wise' : 'Taluk-wise' ?> Abstract (<?= Sanitize::html($selectedYear['financial_year'] ?? '') ?>)
            <span style="font-size:0.8rem;color:#888;font-weight:400;"> — Default sort: Paid Members ↓ &nbsp;|&nbsp; Click column to sort</span>
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
                        <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('paid_members', $sortBy, $sortDir)) ?>">Paid Members<?= sortIndicator('paid_members', $sortBy, $sortDir) ?></a></th>
                        <th style="text-align:right;"><a href="<?= Sanitize::attr(sortUrl('unpaid_members', $sortBy, $sortDir)) ?>">Unpaid Members<?= sortIndicator('unpaid_members', $sortBy, $sortDir) ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($report as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= Sanitize::html($r['district_name']) ?></td>
                        <?php if ($view === 'taluk'): ?>
                        <td><?= Sanitize::html($r['taluk_name']) ?></td>
                        <?php endif; ?>
                        <td class="num"><?= (int)$r['total_members'] ?></td>
                        <td class="num paid"><?= (int)$r['paid_members'] ?></td>
                        <td class="num unpaid"><?= (int)$r['unpaid_members'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($report)): ?>
                    <tr><td colspan="<?= $view === 'taluk' ? 6 : 5 ?>" style="text-align:center;color:#888;padding:24px;">No data for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($report)): ?>
                <tfoot>
                    <tr>
                        <td colspan="<?= $view === 'taluk' ? 3 : 2 ?>" style="text-align:right;">GRAND TOTAL</td>
                        <td class="num"><?= $grandTotal ?></td>
                        <td class="num paid"><?= $grandPaid ?></td>
                        <td class="num unpaid"><?= $grandUnpaid ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
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

