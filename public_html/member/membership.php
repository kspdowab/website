<?php
/**
 * KSPDOWA — Member Portal: My Membership
 * ============================================================
 * Section 8: My Membership
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$pageTitle  = 'My Membership';
$activeMenu = 'membership';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Membership', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

// Fetch all membership years with member's payment status
$allYears = Database::fetchAll("SELECT * FROM membership_years ORDER BY start_date DESC");
$paymentsByYear = [];
$paymentRows = Database::fetchAll("SELECT * FROM membership_payments WHERE member_id = ? AND status = 'completed'", [$currentMemberId]);
foreach ($paymentRows as $pr) {
    $paymentsByYear[(int)$pr['membership_year_id']] = $pr;
}
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">My Membership Status</h1>
        <p class="page-heading-subtitle">Financial year eligibility, annual dues verification, and membership ledger</p>
    </div>
    <div>
        <a href="/member/fee.php" class="btn btn-primary">Pay Annual Dues</a>
    </div>
</div>

<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Active Membership Overview</span>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
            <div>
                <label class="form-label" style="color:var(--text-muted);">Membership Number</label>
                <div style="font-size:1.3rem; font-weight:800; color:var(--blue-700);">
                    <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Current Financial Year</label>
                <div style="font-size:1.2rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($currentYear['financial_year'] ?? '') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Current Status</label>
                <div style="margin-top:4px;">
                    <span class="badge badge-success" style="font-size:0.88rem; padding:6px 14px;">● Verified Active</span>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Enrolled On</label>
                <div style="font-size:1.05rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($portalMember['joining_date']) ? date('d M Y', strtotime((string)$portalMember['joining_date'])) : date('d M Y', strtotime((string)$portalMember['created_at'])) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Annual Membership Year Ledger</span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Financial Year</th>
                    <th>Period</th>
                    <th>Annual Fee</th>
                    <th>Payment Status</th>
                    <th>Verified Date</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allYears as $yr): ?>
                    <?php
                    $isPaid = isset($paymentsByYear[(int)$yr['id']]);
                    $pRow = $paymentsByYear[(int)$yr['id']] ?? null;
                    ?>
                    <tr>
                        <td style="font-weight:700; color:var(--blue-700);">
                            <?= Sanitize::html($yr['financial_year']) ?>
                            <?php if ((int)$yr['id'] === (int)$currentYear['id']): ?>
                                <span class="badge badge-purple" style="margin-left:6px;">Current</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= date('d M Y', strtotime((string)$yr['start_date'])) ?> – <?= date('d M Y', strtotime((string)$yr['end_date'])) ?>
                        </td>
                        <td style="font-weight:600;">
                            ₹<?= number_format((float)$yr['fee_amount'], 2) ?>
                        </td>
                        <td>
                            <?php if ($isPaid): ?>
                                <span class="badge badge-success">● Cleared</span>
                            <?php else: ?>
                                <span class="badge badge-danger">○ Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $pRow && $pRow['paid_at'] ? date('d M Y', strtotime((string)$pRow['paid_at'])) : '—' ?>
                        </td>
                        <td>
                            <?php if ($pRow): ?>
                                <a href="/member/receipt.php?id=<?= (int)$pRow['id'] ?>" target="_blank" class="btn btn-outline btn-sm">Receipt</a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
