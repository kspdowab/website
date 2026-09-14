<?php
/**
 * KSPDOWA — Member Portal: Download Payment Receipt
 * ============================================================
 * Usage: /member/receipt.php?payment_id=123
 *
 * Ownership is enforced in the SQL itself (member_id = current member
 * AND status = 'completed'), the same defense-in-depth style used by
 * document.php for other member-only files: a payment_id belonging to
 * someone else, or one that is not completed, is treated identically
 * to a non-existent one (404) -- no enumeration signal either way.
 *
 * The receipt PDF is generated on first request (Receipt::forPayment())
 * and reused after that; the file itself lives under uploads/receipts/,
 * which is blocked from direct HTTP access by uploads/.htaccess, so
 * this controller is the only way to reach it -- same pattern as
 * document.php for public_html/uploads/documents/.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$memberId = Auth::getCurrentMemberId();
if ($memberId === null) {
    header('Location: /admin/office-bearers.php');
    exit;
}

$paymentId = Sanitize::positiveInt($_GET['id'] ?? $_GET['payment_id'] ?? null);
if ($paymentId === false) {
    ErrorHandler::abort(404, 'Receipt not found.');
}

$payment = Database::fetchOne(
    "SELECT id FROM membership_payments WHERE id = ? AND member_id = ? AND status = 'completed'",
    [$paymentId, $memberId]
);
if ($payment === false) {
    ErrorHandler::abort(404, 'Receipt not found.');
}

$receipt = Receipt::forPayment($paymentId);
if ($receipt === false) {
    ErrorHandler::abort(500, 'Could not generate receipt. Please try again shortly.');
}

$candidatePath  = UPLOADS_DIR . '/' . ltrim(str_replace('\\', '/', (string) $receipt['file_path']), '/');
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

AuditLogger::log('VIEW', 'payment_receipts', (int) $receipt['id']);

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($realFilePath));
    header('Content-Disposition: inline; filename="' . basename($realFilePath) . '"');
    header('X-Content-Type-Options: nosniff');
}

readfile($realFilePath);
exit;
