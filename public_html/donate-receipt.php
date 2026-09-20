<?php
/**
 * KSPDOWA — Public: Download Donation Receipt
 * ============================================================
 * Usage: /donate-receipt.php (no query string -- donation id comes
 * only from the session, exactly like donate-payment.php).
 *
 * There is no login for an anonymous donor, so ownership cannot be
 * checked against a member/user id the way member/receipt.php does.
 * Instead the ONLY way to reach a given donation's receipt is to still
 * hold that donation's id in THIS session (set once by donate.php and
 * kept -- not cleared -- by donate-verify.php after success). Closing
 * the browser/session means this page can no longer serve that
 * donation's receipt; a donor who needs it again must contact the
 * Association office. This mirrors the same query-string-free,
 * session-only ownership pattern already used by donate-payment.php
 * and donate-verify.php.
 *
 * The receipt PDF is generated eagerly by donate-verify.php right
 * after payment confirmation (not lazily here) -- see
 * DonationReceipt.php's doc block. If, for any reason, it is not yet
 * on record when this page is reached, it is generated now as a
 * fallback via the same idempotent DonationReceipt::forDonation().
 *
 * Same path-traversal guard pattern as member/receipt.php and
 * document.php.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$queryDonationId = Sanitize::positiveInt($_GET['id'] ?? null);
if ($queryDonationId !== false) {
    Auth::requireLogin();
    $currentMemberId = Auth::getCurrentMemberId();
    $currentUserId   = Auth::getCurrentUserId();
    $donation = Database::fetchOne(
        "SELECT * FROM donations WHERE id = ? AND status = 'completed'",
        [$queryDonationId]
    );
    if (!$donation) {
        ErrorHandler::abort(404, 'Receipt not found.');
    }
    $canView = ($currentMemberId !== null && (int)($donation['member_id'] ?? 0) === $currentMemberId)
               || ($currentUserId !== null && RBAC::can($currentUserId, 'donations', 'view'));
    if (!$canView) {
        ErrorHandler::abort(403, 'Unauthorized access to this receipt.');
    }
    $donationId = $queryDonationId;
} else {
    $donationId = Session::get('donation_id');
    if ($donationId === null) {
        ErrorHandler::abort(404, 'Receipt not found.');
    }
    $donationId = (int) $donationId;

    $donation = Database::fetchOne(
        "SELECT id FROM donations WHERE id = ? AND status = 'completed'",
        [$donationId]
    );
    if ($donation === false) {
        ErrorHandler::abort(404, 'Receipt not found.');
    }
}

$receipt = DonationReceipt::forDonation($donationId);
if ($receipt === false || empty($receipt['receipt_file_path'])) {
    ErrorHandler::abort(500, 'Could not generate receipt. Please try again shortly.');
}

$candidatePath  = UPLOADS_DIR . '/' . ltrim(str_replace('\\', '/', (string) $receipt['receipt_file_path']), '/');
$realUploadsDir = realpath(UPLOADS_DIR);
$realFilePath   = realpath($candidatePath);

// Path-traversal guard: the resolved file must live inside UPLOADS_DIR.
if (
    $realFilePath === false
    || $realUploadsDir === false
    || !str_starts_with($realFilePath, $realUploadsDir . DIRECTORY_SEPARATOR)
) {
    ErrorHandler::abort(404, 'Receipt file is missing.');
}

if (!is_file($realFilePath)) {
    ErrorHandler::abort(404, 'Receipt file is missing.');
}

AuditLogger::log('VIEW', 'donations', $donationId);

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($realFilePath));
    header('Content-Disposition: inline; filename="' . basename($realFilePath) . '"');
    header('X-Content-Type-Options: nosniff');
}

readfile($realFilePath);
exit;
