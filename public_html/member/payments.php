<?php
/**
 * KSPDOWA — Member Portal: Payment History
 * ============================================================
 * Phase 3 unit (build order approved by user: Payment History ->
 * Receipts -> Donations). Read-only listing of the logged-in
 * member's own membership_payments rows, joined to membership_years
 * for the financial-year label. Deliberately excludes any
 * "View Receipt" action -- that is the next unit, not yet built.
 *
 * Same auth/eligibility gate pattern as member/index.php (defense
 * in depth: re-checks must_change_password and current-year
 * eligibility independently of what login.php already checked).
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$userId   = Auth::getCurrentUserId();
$memberId = Auth::getCurrentMemberId();

if ($memberId === null) {
    // Not a member account -- this area is not for admin/officer logins.
    header('Location: /admin/office-bearers.php');
    exit;
}

$userRow = Database::fetchOne('SELECT must_change_password FROM users WHERE id = ?', [$userId]);
if ($userRow && (int) $userRow['must_change_password'] === 1) {
    header('Location: /member/change-password.php');
    exit;
}

$currentYear = Membership::getCurrentYear();
if ($currentYear === null || !Membership::isEligibleForYear($memberId, (int) $currentYear['id'])) {
    AuditLogger::log('LOGIN_DENIED_INELIGIBLE', 'users', $userId);
    Session::destroy();
    Session::flash('error', 'Your current-year annual membership fee has not been verified yet. Please complete payment to access the member portal.');
    header('Location: /member-login.php');
    exit;
}

$payments = Database::fetchAll(
    'SELECT mp.id, mp.amount, mp.status, mp.gateway_payment_id, mp.paid_at, mp.created_at,
            my.financial_year
     FROM membership_payments mp
     JOIN membership_years my ON my.id = mp.membership_year_id
     WHERE mp.member_id = ?
     ORDER BY mp.created_at DESC',
    [$memberId]
);

$statusBadge = static function (string $status): string {
    return match ($status) {
        'completed' => 'badge-green',
        'pending'   => 'badge-blue',
        default     => 'badge-muted', // failed, refunded
    };
};

$pageTitle = 'Payment History';
require dirname(__DIR__) . '/includes/partials/header.php';
?>

<h1 class="page-title">Payment History</h1>
<p class="page-subtitle">Member portal — <?= Sanitize::html(APP_SHORT_NAME) ?></p>

<div class="card">
    <h2 class="card-title">Your Membership Payments</h2>

    <?php if (empty($payments)): ?>
        <p>No payment records found yet.</p>
    <?php else: ?>
        <table class="plain">
            <thead>
                <tr>
                    <th>Membership Year</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Paid On</th>
                    <th>Payment Reference</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td data-label="Membership Year"><?= Sanitize::html($p['financial_year']) ?></td>
                        <td data-label="Amount">&#8377;<?= Sanitize::html(number_format((float) $p['amount'], 2)) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= $statusBadge((string) $p['status']) ?>"><?= Sanitize::html(ucfirst((string) $p['status'])) ?></span>
                        </td>
                        <td data-label="Paid On"><?= $p['paid_at'] !== null ? Sanitize::html(date('d M Y', strtotime((string) $p['paid_at']))) : '&#8212;' ?></td>
                        <td data-label="Payment Reference"><?= $p['gateway_payment_id'] !== null ? Sanitize::html($p['gateway_payment_id']) : '&#8212;' ?></td>
                        <td data-label="Receipt">
                            <?php if ($p['status'] === 'completed'): ?>
                                <a href="/member/receipt.php?payment_id=<?= (int) $p['id'] ?>" target="_blank" rel="noopener">View Receipt</a>
                            <?php else: ?>
                                &#8212;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<p><a href="/member/index.php" class="btn btn-outline">Back to Member Portal</a></p>

<?php require dirname(__DIR__) . '/includes/partials/footer.php'; ?>
