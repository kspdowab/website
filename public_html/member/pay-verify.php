<?php
/**
 * KSPDOWA — Member Portal: Razorpay Standard Checkout Success Verification
 * ============================================================
 * Posted to by #rzpVerifyForm on member/pay.php.
 * Re-derives and re-verifies signature & captured status server-side.
 * Updates payment status, generates official receipt, and redirects
 * to member/membership.php with success notification.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /member/membership.php');
    exit;
}

CSRF::requireValid();

$paymentId = Session::get('member_payment_id');
$yearId    = Session::get('member_payment_year_id');

if ($paymentId === null || $currentMemberId === null) {
    header('Location: /member/membership.php');
    exit;
}
$paymentId = (int) $paymentId;
$yearId    = (int) $yearId;

$orderId       = (string) ($_POST['razorpay_order_id'] ?? '');
$razorpayPayId = (string) ($_POST['razorpay_payment_id'] ?? '');
$signature     = (string) ($_POST['razorpay_signature'] ?? '');

$row = Database::fetchOne(
    'SELECT id, membership_year_id, amount FROM membership_payments WHERE id = ? AND member_id = ? AND gateway_order_id = ?',
    [$paymentId, $currentMemberId, $orderId]
);

if ($row === false || $orderId === '' || $razorpayPayId === '' || $signature === '') {
    Session::flash('error', 'Payment verification failed -- order mismatch. If an amount was debited, please contact the Association.');
    header('Location: /member/pay.php?year_id=' . $yearId);
    exit;
}

$result = PaymentGateway::confirmPayment($orderId, $razorpayPayId, $signature, 'callback');

if (!$result['success']) {
    Session::flash('error', $result['error'] ?? 'Payment could not be verified. If an amount was debited, please contact the Association.');
    header('Location: /member/pay.php?year_id=' . $yearId);
    exit;
}

// Generate official receipt PDF on-demand immediately
Receipt::forPayment($paymentId);

// Clean up payment session variables
Session::remove('member_payment_id');
Session::remove('member_payment_year_id');

Session::flash('success', 'Payment of ₹' . number_format((float)$row['amount'], 2) . ' received and verified successfully! Your official receipt is ready.');
header('Location: /member/membership.php');
exit;
