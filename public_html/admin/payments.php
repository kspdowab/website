<?php
/**
 * KSPDOWA — Admin: Payments Management
 * ============================================================
 * Gated by RBAC permission 'payments.view'.
 * Role and Scope aware listing of membership payments.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'payments', 'view');

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

$years = Database::fetchAll("SELECT * FROM membership_years ORDER BY start_date DESC");

// Filters
$qYear   = Sanitize::positiveInt($_GET['year_id'] ?? null);
$qStatus = Sanitize::inArray($_GET['status'] ?? 'all', ['all', 'completed', 'pending', 'failed']) ?: 'all';
$qSearch = trim(Sanitize::string($_GET['q'] ?? '', 100));

$whereClauses = ["1=1"];
$params       = [];

if ($lockedTalukId) {
    $whereClauses[] = "m.taluk_id = ?";
    $params[]       = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $whereClauses[] = "m.district_id = ?";
    $params[]       = $lockedDistrictId;
}

if ($qYear) {
    $whereClauses[] = "p.membership_year_id = ?";
    $params[]       = $qYear;
}

if ($qStatus !== 'all') {
    $whereClauses[] = "p.status = ?";
    $params[]       = $qStatus;
}

if ($qSearch !== '') {
    $whereClauses[] = "(m.name LIKE ? OR m.member_no LIKE ? OR p.gateway_payment_id LIKE ? OR p.offline_reference LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT p.*, m.name AS member_name, m.member_no, d.name AS district_name, t.name AS taluk_name,
           my.financial_year, pr.receipt_no
    FROM membership_payments p
    INNER JOIN members m ON m.id = p.member_id
    LEFT JOIN districts d ON d.id = m.district_id
    LEFT JOIN taluks t    ON t.id = m.taluk_id
    LEFT JOIN membership_years my ON my.id = p.membership_year_id
    LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
    WHERE {$whereSql}
    ORDER BY p.paid_at DESC, p.id DESC
";
$payments = Database::fetchAll($sql, $params);

// Total Amount
$totalClearedAmount = 0.0;
foreach ($payments as $p) {
    if ($p['status'] === 'completed') {
        $totalClearedAmount += (float)$p['amount'];
    }
}

$pageTitle  = 'Payments Management';
$activeMenu = 'payments';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Payments', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Payments Management</h1>
        <p class="page-heading-subtitle">Audit membership payments, transaction references, and payment receipts</p>
    </div>
    <div>
        <span class="badge badge-success" style="font-size:0.9rem; padding:8px 16px;">
            Total Cleared: ₹<?= number_format($totalClearedAmount, 2) ?>
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
            Filter Payments
        </div>
        <div class="filter-header-actions">
            <a href="/admin/payments.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/admin/payments.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Financial Year</label>
                <select name="year_id" class="form-select">
                    <option value="">All Financial Years</option>
                    <?php foreach ($years as $yr): ?>
                        <option value="<?= (int)$yr['id'] ?>" <?= $qYear === (int)$yr['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($yr['financial_year']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $qStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="completed" <?= $qStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="pending" <?= $qStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="failed" <?= $qStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Member Name, Number, Payment ID..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/payments.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PAYMENTS TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Payment Transactions</span>
            <span class="table-card-count">(<?= count($payments) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Member No.</th>
                    <th>Member Name</th>
                    <th>District</th>
                    <th>Financial Year</th>
                    <th>Amount</th>
                    <th>Payment Reference</th>
                    <th>Status</th>
                    <th>Paid Date</th>
                    <th style="text-align:center;">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="10" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No payment records found matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($payments as $p): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:700; color:var(--blue-700);">
                            <a href="/admin/member-view.php?id=<?= (int)$p['member_id'] ?>">
                                <?= Sanitize::html($p['member_no']) ?>
                            </a>
                        </td>
                        <td style="font-weight:600;"><?= Sanitize::html($p['member_name']) ?></td>
                        <td><?= Sanitize::html($p['district_name'] ?? '—') ?></td>
                        <td><?= Sanitize::html($p['financial_year'] ?? '—') ?></td>
                        <td style="font-weight:700; color:var(--text-main);">₹<?= number_format((float)$p['amount'], 2) ?></td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:2px 6px; border-radius:4px; font-size:0.8rem;">
                                <?= Sanitize::html($p['gateway_payment_id'] ?? $p['offline_reference'] ?? '—') ?>
                            </code>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'completed'): ?>
                                <span class="badge badge-success">Completed</span>
                            <?php elseif ($p['status'] === 'pending'): ?>
                                <span class="badge badge-warning">Pending</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><?= ucfirst(Sanitize::html($p['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $p['paid_at'] ? date('d M Y, h:i A', strtotime((string)$p['paid_at'])) : '—' ?></td>
                        <td style="text-align:center;">
                            <?php if ($p['status'] === 'completed'): ?>
                                <a href="/admin/receipt.php?payment_id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-outline btn-sm">Receipt</a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
