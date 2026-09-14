<?php
/**
 * KSPDOWA — Secure Grievance Document Streaming Endpoint
 * ============================================================
 * Section 13, 20: Security & Document Handling
 * - Never expose private grievance files through unrestricted URLs.
 * - Authenticates request and validates RBAC / Member scope server-side.
 * - Prevents IDOR and path traversal.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId   = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

$docId = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$docId) {
    ErrorHandler::abort(404, 'Document not specified.');
}

$doc = Database::fetchOne(
    "SELECT gd.*, g.member_id, g.grievance_no
     FROM grievance_documents gd
     INNER JOIN grievances g ON g.id = gd.grievance_id
     WHERE gd.id = ?",
    [$docId]
);

if (!$doc) {
    ErrorHandler::abort(404, 'Document not found.');
}

// Authorization check: Member who submitted, or authorized Officer with scope
$isAuthorized = false;

if ($currentMemberId !== null && (int)$doc['member_id'] === $currentMemberId) {
    $isAuthorized = true;
} elseif (RBAC::hasPermission($currentUserId, 'grievances', 'view')) {
    $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [(int)$doc['grievance_id']]);
    if ($grv && Grievance::canOfficerAccess($currentUserId, $grv)) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    AuditLogger::log('ACCESS_DENIED', 'grievance_documents', $docId, null, [
        'user_id' => $currentUserId,
        'reason'  => 'Unauthorized document access attempt',
    ]);
    ErrorHandler::abort(403, 'Access denied. You do not have permission to view this document.');
}

// Path validation & traversal prevention
$relPath = ltrim((string)$doc['file_path'], '/\\');
$fullPath = UPLOADS_DIR . '/' . $relPath;
$realPath = realpath($fullPath);
$realUploads = realpath(UPLOADS_DIR);

if ($realPath === false || $realUploads === false || !str_starts_with($realPath, $realUploads) || !is_file($realPath)) {
    ErrorHandler::abort(404, 'Document file not found on server.');
}

// Determine MIME type
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

$downloadName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', (string)$doc['original_filename']);
if (empty($downloadName)) {
    $downloadName = basename($realPath);
}

// Send streaming response
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($realPath));
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($realPath);
exit;
