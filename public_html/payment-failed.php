<?php
/**
 * KSPDOWA — Razorpay Standard Checkout Failure/Cancellation Handler
 * ============================================================
 * Posted to by the hidden #rzpFailForm on payment.php when
 * checkout.js reports a payment.failed event or the modal is
 * dismissed without completing payment. Marks the CURRENT attempt
 * 'failed' (approved spec: "Failed/cancelled retry creates a NEW
 * payment attempt ... Preserve previous failed attempts.") so the
 * member can start a fresh attempt from payment.php.
 *
 * The client-reported reason is informational only (stored for
 * support/troubleshooting) -- it never grants anything and is never
 * trusted to decide payment status; only PaymentGateway::confirmPayment()
 * (server-verified) can ever mark a row 'completed'.
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

$row = Database::fetchOne(
    'SELECT id FROM membership_payments WHERE id = ? AND member_id = ?',
    [(int) $paymentId, (int) $memberId]
);

if ($row !== false) {
    $reason = Sanitize::string($_POST['reason'] ?? '', 500);
    PaymentGateway::markFailed((int) $row['id'], $reason !== '' ? $reason : 'client_reported_failure');
}

Session::flash('notice', 'Your payment attempt did not complete. You can try again below.');
header('Location: /payment.php');
exit;
