<?php
/**
 * KSPDOWA — Donation Razorpay Standard Checkout Success Callback
 * ============================================================
 * Posted to by the hidden #rzpVerifyForm on donate-payment.php once
 * checkout.js's success handler fires client-side. A client-side
 * "success" is never trusted on its own -- everything here is
 * re-derived and re-verified server-side via
 * DonationGateway::confirmPayment() (signature check, authoritative
 * status fetch from Razorpay, order/amount match, row locking).
 *
 * Unlike payment-verify.php, donation_id is deliberately KEPT in the
 * session after success rather than removed -- a donor has no login
 * to come back to later, so donate-receipt.php's ownership check (see
 * that file) depends on this session still holding donation_id for as
 * long as the browser session lasts.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /donate-payment.php');
    exit;
}

CSRF::requireValid();

$donationId = Session::get('donation_id');
if ($donationId === null) {
    header('Location: /donate.php');
    exit;
}
$donationId = (int) $donationId;

$orderId       = (string) ($_POST['razorpay_order_id'] ?? '');
$razorpayPayId = (string) ($_POST['razorpay_payment_id'] ?? '');
$signature     = (string) ($_POST['razorpay_signature'] ?? '');

// Defense in depth: the order_id posted back must be the one already
// on record for THIS session's own donation row.
$row = Database::fetchOne(
    'SELECT id FROM donations WHERE id = ? AND gateway_order_id = ?',
    [$donationId, $orderId]
);

if ($row === false || $orderId === '' || $razorpayPayId === '' || $signature === '') {
    Session::flash('error', 'Payment verification failed -- order mismatch. If an amount was debited, please contact the Association.');
    header('Location: /donate-payment.php');
    exit;
}

$result = DonationGateway::confirmPayment($orderId, $razorpayPayId, $signature, 'callback');

if (!$result['success']) {
    Session::flash('error', $result['error'] ?? 'Payment could not be verified. If an amount was debited, please contact the Association.');
    header('Location: /donate-payment.php');
    exit;
}

// Generate the receipt eagerly (donation confirmed -- there is no
// login/portal for a donor to come back and generate it lazily later).
DonationReceipt::forDonation($donationId);

$donation = Database::fetchOne('SELECT donor_name, amount FROM donations WHERE id = ?', [$donationId]);

$pageTitle = 'Donation Successful';
require __DIR__ . '/includes/partials/header.php';
?>

<div class="card">
    <span class="badge badge-green">Payment received</span>
    <h1 class="page-title" style="margin-top:12px;">Thank you<?= $donation !== false ? ', ' . Sanitize::html($donation['donor_name']) : '' ?> -- your donation has been received.</h1>
    <p style="color:var(--ink-700);">
        <?php if ($donation !== false): ?>
            Your donation of <strong>&#8377;<?= Sanitize::html(number_format((float) $donation['amount'], 2)) ?></strong> has been verified.
        <?php endif; ?>
        Your receipt is ready to download below. Please save it now -- this link is only available in this browser session.
    </p>
    <p style="margin-top:20px;">
        <a class="btn btn-teal" href="/donate-receipt.php">Download Receipt</a>
        <a class="btn btn-outline" href="/">Back to Home</a>
    </p>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
