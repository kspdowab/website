<?php
/**
 * KSPDOWA — Member Portal: Membership Fee
 * ============================================================
 * Section 8: Membership Fee
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

$pageTitle  = 'Membership Fee';
$activeMenu = 'fee';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Membership Fee', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$currentYear = Membership::getCurrentYear();
$fyId = $currentYear ? (int)$currentYear['id'] : 0;

$currentPayment = Database::fetchOne(
    "SELECT p.*, pr.receipt_no
     FROM membership_payments p
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ? AND p.membership_year_id = ? AND p.status = 'completed'
     ORDER BY p.id DESC LIMIT 1",
    [$currentMemberId, $fyId]
);

$isPaid = $currentPayment !== null;
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Annual Membership Fee</h1>
        <p class="page-heading-subtitle">Current financial year fee status and payment verification</p>
    </div>
    <div>
        <a href="/member/payments.php" class="btn btn-outline">View All Receipts →</a>
    </div>
</div>

<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Fee Summary for Financial Year <?= Sanitize::html($currentYear['financial_year'] ?? '') ?></span>
    </div>
    <div style="padding:28px 24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:24px; align-items:center;">
            <div>
                <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Annual Fee Amount</span>
                <div style="font-size:2.2rem; font-weight:800; color:var(--text-main); line-height:1.1; margin-top:4px;">
                    ₹<?= number_format((float)($currentYear['fee_amount'] ?? 0), 2) ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">
                    Annual subscription for welfare association services
                </div>
            </div>

            <div>
                <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Payment Status</span>
                <div style="margin-top:8px;">
                    <?php if ($isPaid): ?>
                        <span class="badge badge-success" style="font-size:1rem; padding:8px 18px;">
                            ✓ Fee Verified &amp; Cleared
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size:1rem; padding:8px 18px;">
                            ✕ Payment Pending
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <?php if ($isPaid): ?>
                    <a href="/member/receipt.php?id=<?= (int)$currentPayment['id'] ?>" target="_blank" class="btn btn-primary" style="padding:12px 24px; font-size:0.95rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Download Official Receipt (PDF)
                    </a>
                <?php else: ?>
                    <a href="/payment.php?year_id=<?= (int)$fyId ?>" class="btn btn-primary" style="padding:12px 24px; font-size:0.95rem;">
                        Pay Annual Fee Online
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isPaid && !empty($currentPayment)): ?>
        <div style="margin-top:28px; padding-top:20px; border-top:1px solid #e2e8f0; display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; font-size:0.86rem;">
            <div>
                <span style="color:var(--text-muted); display:block;">Receipt Number:</span>
                <strong style="color:var(--blue-700);"><?= Sanitize::html($currentPayment['receipt_no'] ?? 'Generated') ?></strong>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block;">Payment Mode:</span>
                <strong><?= ucfirst(Sanitize::html($currentPayment['payment_mode'] ?? 'online')) ?></strong>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block;">Transaction ID:</span>
                <code><?= Sanitize::html($currentPayment['gateway_payment_id'] ?? $currentPayment['offline_reference'] ?? '—') ?></code>
            </div>
            <div>
                <span style="color:var(--text-muted); display:block;">Payment Date:</span>
                <strong><?= $currentPayment['paid_at'] ? date('d M Y, h:i A', strtotime((string)$currentPayment['paid_at'])) : '—' ?></strong>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
