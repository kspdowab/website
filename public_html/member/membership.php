<?php
/**
 * KSPDOWA — Member Portal: My Membership
 * ============================================================
 * Unified Member Profile, Annual Dues Status & Payment Ledger
 * (Merged from membership.php, fee.php, and payments.php)
 * Zero duplicates, complete receipt & payment tracking.
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

$pageTitle   = 'My Membership';
$activeMenu  = 'membership';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Membership', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

// Fetch current financial year details
$currentYear = Membership::getCurrentYear();
$fyId = $currentYear ? (int)$currentYear['id'] : 0;

// Fetch current financial year payment record (if completed)
$currentPayment = Database::fetchOne(
    "SELECT p.*, pr.receipt_no
     FROM membership_payments p
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ? AND p.membership_year_id = ? AND p.status = 'completed'
     ORDER BY p.id DESC LIMIT 1",
    [$currentMemberId, $fyId]
);
$isCurrentPaid = ($currentPayment !== null);

// Fetch all membership years in descending order
$allYears = Database::fetchAll("SELECT * FROM membership_years ORDER BY start_date DESC");

// Fetch all payments for this member, indexed by membership_year_id
$completedPaymentsByYear = [];
$latestPaymentByYear = [];

$allMemberPayments = Database::fetchAll(
    "SELECT p.*, pr.receipt_no
     FROM membership_payments p
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ?
     ORDER BY p.id DESC",
    [$currentMemberId]
);

foreach ($allMemberPayments as $p) {
    $yId = (int)$p['membership_year_id'];
    if ($p['status'] === 'completed' && !isset($completedPaymentsByYear[$yId])) {
        $completedPaymentsByYear[$yId] = $p;
    }
    if (!isset($latestPaymentByYear[$yId])) {
        $latestPaymentByYear[$yId] = $p;
    }
}
$paymentsByYear = $completedPaymentsByYear;

$totalYearsCount = count($allYears);
$clearedYearsCount = count($paymentsByYear);
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">My Membership (ನನ್ನ ಸದಸ್ಯತ್ವ)</h1>
        <p class="page-heading-subtitle">Official membership verification, annual dues status, and complete payment ledger</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?php if ($isCurrentPaid && !empty($currentPayment['id'])): ?>
            <a href="/member/receipt.php?id=<?= (int)$currentPayment['id'] ?>" target="_blank" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Current Receipt (PDF)
            </a>
            <a href="/member/id-card.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                Digital ID Card
            </a>
        <?php else: ?>
            <a href="/member/pay.php?year_id=<?= (int)$fyId ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                Pay Annual Dues Online (₹<?= number_format((float)($currentYear['fee_amount'] ?? 0), 2) ?>)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     1. ACTIVE MEMBERSHIP & CURRENT FINANCIAL YEAR STATUS (UNIFIED OVERVIEW)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Active Membership &amp; Current FY <?= Sanitize::html($currentYear['financial_year'] ?? '') ?> Overview</span>
        <span class="badge <?= $isCurrentPaid ? 'badge-success' : 'badge-danger' ?>">
            <?= $isCurrentPaid ? '● Active & Cleared' : '○ Dues Pending' ?>
        </span>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; align-items:start;">
            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Membership Number</label>
                <div style="font-size:1.35rem; font-weight:800; color:var(--blue-700); line-height:1.2;">
                    <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">
                    Official KSPDOWA Member
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Current Financial Year</label>
                <div style="font-size:1.2rem; font-weight:700; color:var(--text-main); line-height:1.2;">
                    <?= Sanitize::html($currentYear['financial_year'] ?? '—') ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">
                    Annual Dues: ₹<?= number_format((float)($currentYear['fee_amount'] ?? 0), 2) ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Membership Status</label>
                <div style="margin-top:2px;">
                    <span class="badge badge-success" style="font-size:0.88rem; padding:6px 14px;">● Verified Active</span>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:6px;">
                    Enrolled: <?= !empty($portalMember['joining_date']) ? date('d M Y', strtotime((string)$portalMember['joining_date'])) : date('d M Y', strtotime((string)$portalMember['created_at'])) ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Annual Dues (FY <?= Sanitize::html($currentYear['financial_year'] ?? '') ?>)</label>
                <div style="margin-top:2px;">
                    <?php if ($isCurrentPaid): ?>
                        <span class="badge badge-success" style="font-size:0.88rem; padding:6px 14px;">
                            ✓ Fee Verified &amp; Cleared
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size:0.88rem; padding:6px 14px;">
                            ✕ Payment Pending
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:6px;">
                    <?= $isCurrentPaid ? 'Receipt: #' . Sanitize::html($currentPayment['receipt_no'] ?? 'Generated') : 'Access requires annual clearance' ?>
                </div>
            </div>
        </div>

        <?php if ($isCurrentPaid && !empty($currentPayment)): ?>
        <div style="margin-top:24px; padding:16px 20px; background:var(--blue-50); border:1px solid #c7d9ec; border-radius:8px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:16px; font-size:0.86rem; align-items:center;">
            <div>
                <span style="color:var(--text-muted); display:block; font-size:0.78rem; text-transform:uppercase; font-weight:600;">Official Receipt No</span>
                <strong style="color:var(--blue-700); font-size:1rem;"><?= Sanitize::html($currentPayment['receipt_no'] ?? 'Generated') ?></strong>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block; font-size:0.78rem; text-transform:uppercase; font-weight:600;">Payment Mode</span>
                <strong><?= ucfirst(Sanitize::html($currentPayment['payment_mode'] ?? 'online')) ?></strong>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block; font-size:0.78rem; text-transform:uppercase; font-weight:600;">Transaction Reference</span>
                <code style="background:#fff; padding:2px 6px; border-radius:4px; font-size:0.82rem; border:1px solid #dce3ea; color:var(--blue-700);">
                    <?= Sanitize::html($currentPayment['gateway_payment_id'] ?? $currentPayment['offline_reference'] ?? 'Direct') ?>
                </code>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block; font-size:0.78rem; text-transform:uppercase; font-weight:600;">Verified Date</span>
                <strong><?= $currentPayment['paid_at'] ? date('d M Y, h:i A', strtotime((string)$currentPayment['paid_at'])) : '—' ?></strong>
            </div>
            <div style="text-align:right;">
                <a href="/member/receipt.php?id=<?= (int)$currentPayment['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="background:#fff;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Download Receipt PDF
                </a>
            </div>
        </div>
        <?php elseif (!$isCurrentPaid): ?>
        <div style="margin-top:24px; padding:16px 20px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <strong style="color:#991b1b; display:block; font-size:0.95rem;">Annual Membership Fee Pending</strong>
                <span style="color:#7f1d1d; font-size:0.86rem;">Please pay your annual subscription of ₹<?= number_format((float)($currentYear['fee_amount'] ?? 0), 2) ?> for <?= Sanitize::html($currentYear['financial_year'] ?? '') ?> to maintain active portal and voting privileges.</span>
            </div>
            <div>
                <a href="/member/pay.php?year_id=<?= (int)$fyId ?>" class="btn btn-primary" style="padding:10px 20px;">
                    Pay Annual Dues Online →
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     2. ANNUAL MEMBERSHIP YEAR LEDGER & PAYMENT RECORDS (NO DUPLICATES)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Annual Subscription &amp; Payment Ledger</span>
            <span class="table-card-count">(<?= $clearedYearsCount ?> of <?= $totalYearsCount ?> Financial Years Cleared)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Financial Year</th>
                    <th>Coverage Period</th>
                    <th>Annual Fee</th>
                    <th>Dues Status</th>
                    <th>Payment Details</th>
                    <th>Paid Date</th>
                    <th style="text-align:center;">Action / Official Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allYears)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No financial years configured in the system.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($allYears as $yr): ?>
                        <?php
                        $yrId    = (int)$yr['id'];
                        $isPaid  = isset($paymentsByYear[$yrId]);
                        $pRow    = $paymentsByYear[$yrId] ?? null;
                        $latRow  = $latestPaymentByYear[$yrId] ?? null;
                        $isCur   = ($yrId === (int)($currentYear['id'] ?? 0));
                        $isPendingAttempt = (!$isPaid && $latRow && $latRow['status'] === 'pending');
                        ?>
                        <tr <?= $isCur ? 'style="background: #f8fafc;"' : '' ?>>
                            <td style="font-weight:700; color:var(--blue-700);">
                                <?= Sanitize::html($yr['financial_year']) ?>
                                <?php if ($isCur): ?>
                                    <span class="badge badge-purple" style="margin-left:6px; font-size:0.75rem;">Current FY</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime((string)$yr['start_date'])) ?> – <?= date('d M Y', strtotime((string)$yr['end_date'])) ?>
                            </td>
                            <td style="font-weight:700; color:var(--text-main);">
                                ₹<?= number_format((float)$yr['fee_amount'], 2) ?>
                            </td>
                            <td>
                                <?php if ($isPaid): ?>
                                    <span class="badge badge-success">● Cleared</span>
                                <?php elseif ($isPendingAttempt): ?>
                                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d;">⏳ Payment Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">○ Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPaid && $pRow): ?>
                                    <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($pRow['payment_mode'] ?? 'online')) ?></span>
                                    <code style="background:var(--blue-50); color:var(--blue-700); padding:2px 6px; border-radius:4px; font-size:0.8rem; margin-left:4px;">
                                        <?= Sanitize::html($pRow['receipt_no'] ?? $pRow['gateway_payment_id'] ?? $pRow['offline_reference'] ?? 'Verified') ?>
                                    </code>
                                <?php elseif ($isPendingAttempt): ?>
                                    <span style="color:#b45309; font-size:0.82rem; font-weight:600;">Attempt #<?= (int)$latRow['id'] ?></span> <span style="font-size:0.75rem; color:var(--text-muted);">(Pending Gateway)</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPaid && !empty($pRow['paid_at'])): ?>
                                    <?= date('d M Y, h:i A', strtotime((string)$pRow['paid_at'])) ?>
                                <?php elseif ($isPendingAttempt && !empty($latRow['created_at'])): ?>
                                    <span style="color:var(--text-muted); font-size:0.8rem;">Initiated <?= date('d M Y', strtotime((string)$latRow['created_at'])) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($isPaid && !empty($pRow['id'])): ?>
                                    <a href="/member/receipt.php?id=<?= (int)$pRow['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        Receipt PDF
                                    </a>
                                <?php else: ?>
                                    <a href="/member/pay.php?year_id=<?= (int)$yr['id'] ?>" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                        <?= $isPendingAttempt ? 'Pay Again / Retry' : 'Pay Online' ?>
                                    </a>
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
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
