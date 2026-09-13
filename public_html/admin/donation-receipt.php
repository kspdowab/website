<?php
/**
 * KSPDOWA — Admin: Download Donation Receipt
 * ============================================================
 * Gated by RBAC permission 'donations.view'.
 * Allows an authorized admin to download any completed donation's
 * receipt PDF. Mirrors public donate-receipt.php's path-traversal
 * guard but uses an explicit ?id= query string parameter rather
 * than a session variable.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'donations', 'view');

$donationId = Sanitize::positiveInt($_GET['id'] ?? null);

if ($donationId === false) {
    ErrorHandler::abort(404, 'Receipt not found.');
}

$donation = Database::fetchOne(
    "SELECT * FROM donations WHERE id = ? AND status = 'completed'",
    [$donationId]
);

if ($donation === false || empty($donation['receipt_file_path'])) {
    ErrorHandler::abort(404, 'Receipt not found or not generated yet.');
}

$candidatePath  = UPLOADS_DIR . '/' . ltrim(str_replace('\\', '/', (string) $donation['receipt_file_path']), '/');
$realUploadsDir = realpath(UPLOADS_DIR);
$realFilePath   = realpath($candidatePath);

// Path-traversal guard
if (
    $realFilePath === false
    || $realUploadsDir === false
    || !str_starts_with($realFilePath, $realUploadsDir . DIRECTORY_SEPARATOR)
) {
    ErrorHandler::abort(404, 'Receipt file is missing from server.');
}

if (!is_file($realFilePath)) {
    ErrorHandler::abort(404, 'Receipt file is missing from server.');
}

AuditLogger::log('VIEW', 'donations', $donationId, null, [
    'action' => 'admin_download_receipt', 'receipt_no' => $donation['receipt_no']
]);

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($realFilePath));
    header('Content-Disposition: inline; filename="' . basename($realFilePath) . '"');
    header('X-Content-Type-Options: nosniff');
}

readfile($realFilePath);
exit;
