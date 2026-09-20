<?php
/**
 * KSPDOWA — Member Portal: Razorpay Standard Checkout Failure/Cancel Handler
 * ============================================================
 * Posted to by #rzpFailForm on member/pay.php.
 * Marks the current attempt 'failed' and redirects back to member/pay.php
 * so the member can retry cleanly.
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

if ($paymentId !== null && $currentMemberId !== null) {
    $row = Database::fetchOne(
        'SELECT id FROM membership_payments WHERE id = ? AND member_id = ?',
        [(int) $paymentId, (int) $currentMemberId]
    );
    if ($row !== false) {
        $reason = Sanitize::string($_POST['reason'] ?? '', 500);
        PaymentGateway::markFailed((int) $row['id'], $reason !== '' ? $reason : 'member_cancelled_or_dismissed');
    }
}

Session::flash('error', 'Your payment attempt did not complete. You can try again below.');
$redirectUrl = '/member/pay.php' . ($yearId ? '?year_id=' . (int)$yearId : '');
header('Location: ' . $redirectUrl);
exit;
