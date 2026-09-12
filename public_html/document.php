<?php
/**
 * KSPDOWA — Secure Document Delivery
 * ============================================================
 * public_html/uploads/ is blocked from direct HTTP access by
 * .htaccess (RewriteRule ^uploads(/.*)?$ - [F,L]) precisely so
 * that every file — public or member-only — is served through
 * this access-controlled path instead of a raw static URL.
 *
 * Usage: /document.php?id=123
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if ($id === false) {
    ErrorHandler::abort(404, 'Document not found.');
}

$doc = Database::fetchOne(
    "SELECT * FROM documents WHERE id = ? AND status = 'active'",
    [$id]
);

if (!$doc) {
    ErrorHandler::abort(404, 'Document not found.');
}

if ($doc['access_level'] !== 'public') {
    Auth::requireLogin();
    $userId = Auth::getCurrentUserId();
    if ($userId === null || !RBAC::hasPermission($userId, 'documents', 'view')) {
        ErrorHandler::abort(403, 'You do not have permission to view this document.');
    }
}

$candidatePath  = UPLOADS_DIR . '/' . ltrim(str_replace('\\', '/', (string) $doc['file_path']), '/');
$realUploadsDir = realpath(UPLOADS_DIR);
$realFilePath   = realpath($candidatePath);

// Path-traversal guard: the resolved file must live inside UPLOADS_DIR.
if (
    $realFilePath === false
    || $realUploadsDir === false
    || !str_starts_with($realFilePath, $realUploadsDir . DIRECTORY_SEPARATOR)
) {
    ErrorHandler::abort(404, 'Document not found.');
}

if (!is_file($realFilePath)) {
    ErrorHandler::abort(404, 'Document file is missing.');
}

AuditLogger::log('VIEW', 'documents', (int) $doc['id']);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($realFilePath) ?: 'application/octet-stream';

if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($realFilePath));
    header('Content-Disposition: inline; filename="' . basename($realFilePath) . '"');
    header('X-Content-Type-Options: nosniff');
}

readfile($realFilePath);
exit;
