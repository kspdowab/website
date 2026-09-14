<?php
/**
 * KSPDOWA — Member Portal: Payment History
 * ============================================================
 * Section 8: Payments is KEPT in Member Navigation.
 * Displays member's official payment receipts and records.
 * Styled with the UI theme.
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

$pageTitle  = 'Payment History';
$activeMenu = 'payments';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Payments', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$payments = Database::fetchAll(
    'SELECT mp.*, my.financial_year, pr.receipt_no
     FROM membership_payments mp
     JOIN membership_years my ON my.id = mp.membership_year_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = mp.id
     WHERE mp.member_id = ?
     ORDER BY mp.created_at DESC',
    [$currentMemberId]
);
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Payment History &amp; Receipts</h1>
        <p class="page-heading-subtitle">Your annual membership subscriptions, verified payments, and downloadable receipts</p>
    </div>
    <div>
        <a href="/member/fee.php" class="btn btn-primary">Membership Fee Status</a>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Membership Payments</span>
            <span class="table-card-count">(<?= count($payments) ?> transactions)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Membership Year</th>
                    <th>Amount Paid</th>
                    <th>Status</th>
                    <th>Payment Mode</th>
                    <th>Transaction Reference</th>
                    <th>Paid Date</th>
                    <th style="text-align:center;">Official Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No payment records found.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($payments as $p): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:700; color:var(--blue-700);">
                            <?= Sanitize::html($p['financial_year']) ?>
                        </td>
                        <td style="font-weight:700; color:var(--text-main);">
                            ₹<?= number_format((float)$p['amount'], 2) ?>
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
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($p['payment_mode'] ?? 'online')) ?></span>
                        </td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:2px 6px; border-radius:4px; font-size:0.82rem;">
                                <?= Sanitize::html($p['gateway_payment_id'] ?? $p['offline_reference'] ?? '—') ?>
                            </code>
                        </td>
                        <td>
                            <?= $p['paid_at'] ? date('d M Y, h:i A', strtotime((string)$p['paid_at'])) : '—' ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($p['status'] === 'completed'): ?>
                                <a href="/member/receipt.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm" target="_blank">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Receipt PDF
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:0.8rem;">—</span>
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
