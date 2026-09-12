<?php
/**
 * KSPDOWA — Razorpay Standard Checkout Success Callback
 * ============================================================
 * Posted to by the hidden #rzpVerifyForm on payment.php once
 * checkout.js's success handler fires client-side. A client-side
 * "success" is never trusted on its own -- everything here is
 * re-derived and re-verified server-side via
 * PaymentGateway::confirmPayment() (signature check, authoritative
 * status fetch from Razorpay, order/amount match, row locking,
 * exactly-once login activation). This file is only the thin
 * controller + confirmation view.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /payment.php');
    exit;
}

CSRF::requireValid();

$paymentId = Session::get('registration_payment_id');
$memberId  = Session::get('registration_member_id');

if ($paymentId === null || $memberId === null) {
    header('Location: /register.php');
    exit;
}
$paymentId = (int) $paymentId;
$memberId  = (int) $memberId;

$orderId       = (string) ($_POST['razorpay_order_id'] ?? '');
$razorpayPayId = (string) ($_POST['razorpay_payment_id'] ?? '');
$signature     = (string) ($_POST['razorpay_signature'] ?? '');

// Defense in depth: the order_id posted back must be the one already
// on record for THIS session's own payment row -- the session (never
// the POST body) is the only source of truth for which row this is.
$row = Database::fetchOne(
    'SELECT id FROM membership_payments WHERE id = ? AND member_id = ? AND gateway_order_id = ?',
    [$paymentId, $memberId, $orderId]
);

if ($row === false || $orderId === '' || $razorpayPayId === '' || $signature === '') {
    Session::flash('error', 'Payment verification failed -- order mismatch. If an amount was debited, please contact the Association.');
    header('Location: /payment.php');
    exit;
}

$result = PaymentGateway::confirmPayment($orderId, $razorpayPayId, $signature, 'callback');

if (!$result['success']) {
    Session::flash('error', $result['error'] ?? 'Payment could not be verified. If an amount was debited, please contact the Association.');
    header('Location: /payment.php');
    exit;
}

// Confirmed (or safely-ignored duplicate of an already-confirmed
// payment) -- this registration's session role is finished either way.
Session::remove('registration_payment_id');
Session::remove('registration_member_id');

$member = Database::fetchOne('SELECT mp.personal_email FROM member_profiles mp WHERE mp.member_id = ?', [$memberId]);
$email  = $member !== false ? $member['personal_email'] : '';

$pageTitle = 'Payment Successful';
require __DIR__ . '/includes/partials/header.php';
?>

<div class="card">
    <span class="badge badge-green">Payment received</span>
    <h1 class="page-title" style="margin-top:12px;">Thank you -- your payment has been verified.</h1>
    <p style="color:var(--ink-700);">
        A system-generated temporary login password has been emailed to
        <strong><?= Sanitize::html($email) ?></strong>. Please check your inbox (and spam folder).
    </p>
    <p style="margin-top:20px;">
        <a class="btn btn-teal" href="/login.php">Go to Sign In</a>
    </p>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
