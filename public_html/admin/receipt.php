<?php
/**
 * KSPDOWA — Admin: Download Membership Payment Receipt
 * ============================================================
 * Gated by RBAC permission 'members.manage'.
 * Allows an authorized admin to download any completed payment's
 * receipt PDF. Mirrors public member/receipt.php's path-traversal
 * guard but uses an explicit ?id= query string parameter rather
 * than enforcing member ownership.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

$paymentId = Sanitize::positiveInt($_GET['id'] ?? null);

if ($paymentId === false) {
    ErrorHandler::abort(404, 'Receipt not found.');
}

$payment = Database::fetchOne(
    "SELECT m.district_id, m.taluk_id FROM membership_payments p JOIN members m ON m.id = p.member_id WHERE p.id = ? AND p.status = 'completed'",
    [$paymentId]
);

if ($payment === false) {
    ErrorHandler::abort(404, 'Receipt not found or payment not completed.');
}

// Enforce geographic scope
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district' && $payment['district_id'] != $unit['district_id']) {
            ErrorHandler::abort(403, 'Member is outside your authorized district.');
        }
        if ($unit['unit_type'] === 'taluk' && $payment['taluk_id'] != $unit['taluk_id']) {
            ErrorHandler::abort(403, 'Member is outside your authorized taluk.');
        }
    }
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

AuditLogger::log('VIEW', 'payment_receipts', (int) $receipt['id'], null, [
    'action' => 'admin_download_receipt', 'receipt_no' => $receipt['receipt_no']
]);

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($realFilePath));
    header('Content-Disposition: inline; filename="' . basename($realFilePath) . '"');
    header('X-Content-Type-Options: nosniff');
}

readfile($realFilePath);
exit;
