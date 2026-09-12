<?php
/**
 * KSPDOWA — Donation Razorpay Standard Checkout Failure/Cancellation
 * Handler
 * ============================================================
 * Posted to by the hidden #rzpFailForm on donate-payment.php. Marks
 * the CURRENT attempt 'failed' so the donor can start a fresh attempt
 * from donate-payment.php. The client-reported reason is informational
 * only (stored for support/troubleshooting) -- it never grants
 * anything and is never trusted to decide payment status; only
 * DonationGateway::confirmPayment() (server-verified) can ever mark a
 * row 'completed'.
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

$row = Database::fetchOne('SELECT id FROM donations WHERE id = ?', [(int) $donationId]);

if ($row !== false) {
    $reason = Sanitize::string($_POST['reason'] ?? '', 500);
    DonationGateway::markFailed((int) $row['id'], $reason !== '' ? $reason : 'client_reported_failure');
}

Session::flash('notice', 'Your donation attempt did not complete. You can try again below.');
header('Location: /donate-payment.php');
exit;
