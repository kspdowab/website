<?php
/**
 * KSPDOWA — Admin: Documents Sample CSV Template Download
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
RBAC::requirePermission(Auth::getCurrentUserId(), 'documents', 'view');

ContentBulkImporter::downloadSample('documents');
