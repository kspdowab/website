<?php
/**
 * KSPDOWA — Admin: Documents Library Management
 * ============================================================
 * Section 6 Specification:
 * - Logged-in authorized users only
 * - Upload
 * - Edit metadata
 * - Category
 * - Description
 * - Publish / manage (Active / Archived)
 * - Secure View/Download (via /document.php?id=...)
 *
 * Security:
 * - No unrestricted direct file URLs
 * - RBAC required
 * - Path traversal protection
 * - MIME & extension validation
 * - Audit logging
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'documents', 'view');

if (isset($_GET['download_sample']) && $_GET['download_sample'] === '1') {
    ContentBulkImporter::downloadSample('documents');
}

$canManage = RBAC::hasPermission($currentUserId, 'documents', 'manage') ||
             RBAC::hasPermission($currentUserId, 'documents', 'upload') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

// Fetch document categories
$categories = Database::fetchAll("SELECT * FROM document_categories WHERE status = 'active' ORDER BY name");

// ─── POST Handler ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if (!$canManage) {
        ErrorHandler::abort(403, 'You do not have permission to manage documents.');
    }

    // ── BULK UPLOAD ──
    if ($action === 'bulk_upload') {
        $defaultCatId = Sanitize::positiveInt($_POST['default_category_id'] ?? null);
        $defaultAccess= Sanitize::inArray($_POST['default_access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';

        $hasBulkFile = isset($_FILES['bulk_file']) && $_FILES['bulk_file']['error'] === UPLOAD_ERR_OK;
        $hasAttached = isset($_FILES['attached_files']) && !empty($_FILES['attached_files']['name']) && is_array($_FILES['attached_files']['name']) && count($_FILES['attached_files']['name']) > 0 && $_FILES['attached_files']['error'][0] === UPLOAD_ERR_OK;

        if (!$hasBulkFile && !$hasAttached) {
            Session::flash('error', 'Please select a CSV/ZIP file, or choose document files to upload.');
            header('Location: /admin/documents.php');
            exit;
        }

        $extracted = [
            'rows'      => [],
            'files_map' => [],
            'temp_dir'  => null,
            'error'     => null,
        ];

        if ($hasBulkFile) {
            $extracted = ContentBulkImporter::extractUpload($_FILES['bulk_file'], $_FILES['attached_files'] ?? null);
        } else {
            // Direct batch files upload without CSV
            $att = $_FILES['attached_files'];
            $numFiles = count($att['name']);
            for ($i = 0; $i < $numFiles; $i++) {
                if (($att['error'][$i] ?? 1) === UPLOAD_ERR_OK) {
                    $fn = (string)$att['name'][$i];
                    $extracted['files_map'][strtolower($fn)] = (string)$att['tmp_name'][$i];
                }
            }
        }

        if (!empty($extracted['error'])) {
            Session::flash('error', $extracted['error']);
            header('Location: /admin/documents.php');
            exit;
        }

        $stats = ContentBulkImporter::importDocuments($extracted, $currentUserId, $defaultCatId, $defaultAccess);
        $msg = "Bulk import completed: {$stats['imported']} documents imported.";
        if ($stats['skipped'] > 0) {
            $msg .= " {$stats['skipped']} skipped.";
        }
        if (!empty($stats['errors'])) {
            $sampleErrors = array_slice($stats['errors'], 0, 4);
            $msg .= ' Details: ' . implode('; ', $sampleErrors);
            if (count($stats['errors']) > 4) {
                $msg .= ' (and ' . (count($stats['errors']) - 4) . ' more)';
            }
        }

        if ($stats['imported'] > 0) {
            Session::flash('success', $msg);
        } else {
            Session::flash('error', $msg);
        }

        header('Location: /admin/documents.php');
        exit;
    }

    // ── UPLOAD DOCUMENT ──
    if ($action === 'upload') {
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $categoryId  = Sanitize::positiveInt($_POST['category_id'] ?? null);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description = Sanitize::string($_POST['description'] ?? '', 2000);

        $errors = [];
        if ($title === '') {
            $errors[] = 'Document title is required.';
        }

        if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please select a document file to upload.';
        } else {
            $file = $_FILES['doc_file'];
            $maxBytes = 25 * 1024 * 1024; // 25 MB max

            if ($file['size'] > $maxBytes) {
                $errors[] = 'File size exceeds 25 MB limit.';
            }

            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowedExtensions, true)) {
                $errors[] = 'Invalid file extension. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG.';
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $allowedMimes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg',
                'image/png'
            ];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Invalid file MIME type (' . htmlspecialchars($mime) . ').';
            }

            if (empty($errors)) {
                $docsDir = UPLOADS_DIR . '/documents';
                if (!is_dir($docsDir)) {
                    mkdir($docsDir, 0755, true);
                }

                $safeName = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destPath = $docsDir . '/' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    Database::execute(
                        "INSERT INTO documents (title, category_id, description, file_path, access_level, published_at, uploaded_by, status)
                         VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active')",
                        [$title, $categoryId ?: null, $description, 'documents/' . $safeName, $accessLevel, $currentUserId]
                    );
                    $docId = (int)Database::lastInsertId();
                    AuditLogger::log('UPLOAD', 'documents', $docId, null, ['title' => $title, 'filename' => $safeName]);
                    Session::flash('success', "Document '{$title}' uploaded successfully.");
                    header('Location: /admin/documents.php');
                    exit;
                } else {
                    $errors[] = 'Server error storing file on disk.';
                }
            }
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
        }
    }

    // ── EDIT METADATA ──
    if ($action === 'update') {
        $id          = Sanitize::positiveInt($_POST['id'] ?? null);
        $title       = Sanitize::string($_POST['title'] ?? '', 300);
        $categoryId  = Sanitize::positiveInt($_POST['category_id'] ?? null);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description = Sanitize::string($_POST['description'] ?? '', 2000);

        if (!$id || $title === '') {
            Session::flash('error', 'Valid document ID and title required.');
            header('Location: /admin/documents.php');
            exit;
        }

        Database::execute(
            "UPDATE documents SET title = ?, category_id = ?, description = ?, access_level = ? WHERE id = ?",
            [$title, $categoryId ?: null, $description, $accessLevel, $id]
        );
        AuditLogger::log('UPDATE', 'documents', $id, null, ['title' => $title]);
        Session::flash('success', "Document '{$title}' updated successfully.");
        header('Location: /admin/documents.php');
        exit;
    }

    // ── TOGGLE ARCHIVE ──
    if ($action === 'toggle_archive') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $row = Database::fetchOne('SELECT status FROM documents WHERE id = ?', [$id]);
            if ($row) {
                $newStatus = ($row['status'] === 'archived') ? 'active' : 'archived';
                Database::execute('UPDATE documents SET status = ? WHERE id = ?', [$newStatus, $id]);
                AuditLogger::log('STATUS_CHANGE', 'documents', $id, null, ['new_status' => $newStatus]);
                Session::flash('success', "Document status changed to " . ucfirst($newStatus) . ".");
            }
        }
        header('Location: /admin/documents.php');
        exit;
    }
}

// ─── Filters & List Query ────────────────────────────────────────────────────
$qCategory = Sanitize::positiveInt($_GET['category'] ?? null);
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qStatus   = Sanitize::inArray($_GET['status'] ?? 'all', ['all', 'active', 'archived']) ?: 'all';
$editId    = Sanitize::positiveInt($_GET['edit'] ?? null);
$showForm  = isset($_GET['upload']) || $editId;

$hasActiveFilters = (!empty($qCategory) || $qSearch !== '' || ($qStatus !== 'all' && $qStatus !== ''));

$whereClause = ["1=1"];
$params      = [];

if ($qCategory) {
    $whereClause[] = "d.category_id = ?";
    $params[]      = $qCategory;
}

if ($qSearch !== '') {
    $whereClause[] = "(d.title LIKE ? OR d.description LIKE ?)";
    $like = '%' . $qSearch . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($qStatus !== 'all') {
    $whereClause[] = "d.status = ?";
    $params[]      = $qStatus;
}

$whereSql = implode(' AND ', $whereClause);

$sql = "
    SELECT d.*, dc.name AS category_name, u.username AS uploader_name
    FROM documents d
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    LEFT JOIN users u ON u.id = d.uploaded_by
    WHERE {$whereSql}
    ORDER BY d.published_at DESC, d.id DESC
";
$documentsList = Database::fetchAll($sql, $params);

// Edit Row
$editRow = null;
if ($editId) {
    $editRow = Database::fetchOne('SELECT * FROM documents WHERE id = ?', [$editId]);
}

$pageTitle  = 'Documents Library';
$activeMenu = 'documents';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Documents', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Documents Library</h1>
        <p class="page-heading-subtitle">Upload, categorize, and securely publish official documents</p>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="button" id="toggleFilterBtn" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:7px; font-size:0.84rem; padding:6px 14px; background:#ffffff; border:1px solid var(--border, #dce3ea); border-radius:6px; cursor:pointer; font-weight:600; color:var(--primary-navy, #173F67); box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span id="filterToggleText"><?= $hasActiveFilters ? 'Hide Filters' : 'Filter / Search' ?></span>
            <?php if ($hasActiveFilters): ?>
                <span class="badge badge-purple" style="font-size:0.72rem; padding:2px 7px;">Active</span>
            <?php endif; ?>
        </button>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-outline" onclick="openBulkModal()" style="display:inline-flex; align-items:center; gap:7px; font-size:0.84rem; padding:6px 14px; background:#ffffff; border:1px solid var(--border, #dce3ea); border-radius:6px; cursor:pointer; font-weight:600; color:var(--primary-navy, #173F67); box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Bulk Upload
            </button>
            <?php if ($showForm): ?>
                <a href="/admin/documents.php" class="btn btn-outline">← Back to List</a>
            <?php else: ?>
                <a href="/admin/documents.php?upload=1" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    Upload Document
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     UPLOAD / EDIT DOCUMENT FORM
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($showForm && $canManage): ?>
<div class="table-card" style="margin-bottom:28px;">
    <div class="table-card-header" style="background:#f8fafc;">
        <span class="table-card-title"><?= $editRow ? 'Edit Document: ' . Sanitize::html($editRow['title']) : 'Upload Official Document' ?></span>
        <a href="/admin/documents.php" class="btn btn-outline btn-sm">✕ Cancel</a>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/documents.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'upload' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:18px 24px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="doc_title">Document Title *</label>
                    <input type="text" name="title" id="doc_title" class="form-control" required maxlength="300" placeholder="e.g. Gram Panchayat Service Regulations 2026" value="<?= Sanitize::attr($editRow['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="category_id">Category</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <option value="">— General / Uncategorized —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= ($editRow['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                                <?= Sanitize::html($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="access_level">Access Level *</label>
                    <select name="access_level" id="access_level" class="form-select" required>
                        <option value="member" <?= ($editRow['access_level'] ?? 'member') === 'member' ? 'selected' : '' ?>>Member Only</option>
                        <option value="public" <?= ($editRow['access_level'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="officer" <?= ($editRow['access_level'] ?? '') === 'officer' ? 'selected' : '' ?>>Officer Level</option>
                        <option value="admin" <?= ($editRow['access_level'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin Only</option>
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="doc_desc">Description / Summary</label>
                    <textarea name="description" id="doc_desc" rows="3" class="form-control" placeholder="Summary of the document contents..."><?= Sanitize::html($editRow['description'] ?? '') ?></textarea>
                </div>

                <?php if (!$editRow): ?>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="doc_file">File * (PDF, DOC, DOCX, XLS, XLSX, JPG, PNG — Max 25MB)</label>
                    <input type="file" name="doc_file" id="doc_file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                </div>
                <?php else: ?>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <span style="font-size:0.84rem; color:var(--text-muted);">
                        Current file: <a href="/document.php?id=<?= (int)$editRow['id'] ?>" target="_blank" style="font-weight:600;">View Document</a>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary">
                    <?= $editRow ? 'Save Metadata' : 'Upload & Publish Document' ?>
                </button>
                <a href="/admin/documents.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card" id="filterCard" style="<?= $hasActiveFilters ? '' : 'display: none;' ?>">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Documents
        </div>
        <div class="filter-header-actions" style="display:flex; gap:8px; align-items:center;">
            <a href="/admin/documents.php" class="filter-header-btn">↺ Reset</a>
            <button type="button" id="hideFilterBtn" class="filter-header-btn" style="background:none; border:1px solid var(--border, #dce3ea); cursor:pointer; display:inline-flex; align-items:center; gap:4px;" title="Hide Filter Section">
                ✕ Hide
            </button>
        </div>
    </div>
    <form method="get" action="/admin/documents.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $qCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $qStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="active" <?= $qStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $qStatus === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Document title, description..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/documents.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     DOCUMENTS TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Document Repository</span>
            <span class="table-card-count">(<?= count($documentsList) ?> files)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Document Title</th>
                    <th>Category</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th>Secure View</th>
                    <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documentsList)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No documents found matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($documentsList as $doc): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($doc['title']) ?>
                        </td>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($doc['category_name'] ?? 'General') ?></span>
                        </td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($doc['access_level'])) ?></span>
                        </td>
                        <td>
                            <?php if ($doc['status'] === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Sanitize::html($doc['uploader_name'] ?? 'System') ?></td>
                        <td><?= $doc['published_at'] ? date('d-m-Y', strtotime($doc['published_at'])) : '—' ?></td>
                        <td>
                            <a href="/document.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View File
                            </a>
                        </td>
                        <?php if ($canManage): ?>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <a href="/admin/documents.php?edit=<?= (int)$doc['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                <form method="post" action="/admin/documents.php" style="display:inline;" onsubmit="return confirm('Toggle status for this document?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="toggle_archive">
                                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--text-muted);">
                                        <?= $doc['status'] === 'archived' ? 'Unarchive' : 'Archive' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     BULK UPLOAD MODAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="bulkModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="modal-box" style="background:#fff; border-radius:10px; max-width:620px; width:100%; box-shadow:0 20px 45px rgba(0,0,0,0.25); overflow:hidden;">
        <div style="padding:18px 24px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:36px; height:36px; border-radius:8px; background:#eff6ff; color:#1d4ed8; display:flex; align-items:center; justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; color:#0f172a; font-weight:700;">Bulk Upload Documents</h3>
                    <p style="margin:0; font-size:0.78rem; color:#64748b;">Upload metadata spreadsheet or batch document files at once</p>
                </div>
            </div>
            <button type="button" onclick="closeBulkModal()" style="background:transparent; border:none; font-size:1.3rem; color:#64748b; cursor:pointer; padding:4px 8px; border-radius:4px; line-height:1;">✕</button>
        </div>

        <form method="post" action="/admin/documents.php" enctype="multipart/form-data" style="padding:22px 24px 24px;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="bulk_upload">

            <!-- Step 1: Download Sample -->
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:14px 16px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div>
                    <div style="font-weight:700; font-size:0.88rem; color:#0369a1; margin-bottom:2px;">Step 1: Download Sample Template</div>
                    <div style="font-size:0.78rem; color:#0c4a6e;">Get the verified CSV template with sample titles, categories, and access levels.</div>
                </div>
                <a href="/admin/documents.php?download_sample=1" class="btn btn-sm" style="background:#0284c7; color:#ffffff; font-weight:600; text-decoration:none; padding:7px 14px; border-radius:6px; font-size:0.82rem; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download Sample CSV
                </a>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:18px;">
                <div>
                    <label class="form-label" style="font-size:0.84rem; font-weight:600; margin-bottom:4px; display:block;">Default Category</label>
                    <select name="default_category_id" class="form-select" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.86rem;">
                        <option value="">— Select Default Category —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= Sanitize::html($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:0.84rem; font-weight:600; margin-bottom:4px; display:block;">Default Access Level</label>
                    <select name="default_access_level" class="form-select" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.86rem;">
                        <option value="member" selected>Member Only</option>
                        <option value="public">Public</option>
                        <option value="officer">Officer Only</option>
                        <option value="admin">Admin Only</option>
                    </select>
                </div>
            </div>

            <!-- Upload option 1: CSV or ZIP -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-weight:600; font-size:0.86rem; color:#1e293b; margin-bottom:6px;">
                    Option A: Metadata File (CSV or ZIP)
                </label>
                <input type="file" name="bulk_file" id="bulk_file" accept=".csv,.zip,text/csv,application/zip" class="form-control" style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
                <p style="font-size:0.75rem; color:#64748b; margin:4px 0 0;">Upload a <code>.csv</code> spreadsheet, OR a <code>.zip</code> package containing the CSV and files.</p>
            </div>

            <!-- Upload option 2: Direct Document Files -->
            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:600; font-size:0.86rem; color:#1e293b; margin-bottom:6px;">
                    Option B: Document Files (Multiple PDFs / Office Files)
                </label>
                <input type="file" name="attached_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="form-control" style="width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
                <p style="font-size:0.75rem; color:#64748b; margin:4px 0 0;">Select multiple files to upload together. If no CSV is provided, file names will be converted into document titles automatically.</p>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeBulkModal()" class="btn btn-outline" style="padding:9px 18px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-weight:600;">
                    Start Bulk Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openBulkModal() {
    var m = document.getElementById('bulkModal');
    if (m) m.style.display = 'flex';
}
function closeBulkModal() {
    var m = document.getElementById('bulkModal');
    if (m) m.style.display = 'none';
}
document.getElementById('bulkModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeBulkModal();
});

document.addEventListener('DOMContentLoaded', function() {
    var filterCard = document.getElementById('filterCard');
    var toggleBtn  = document.getElementById('toggleFilterBtn');
    var toggleText = document.getElementById('filterToggleText');
    var hideBtn    = document.getElementById('hideFilterBtn');

    function setFilterVisibility(show) {
        if (!filterCard) return;
        if (show) {
            filterCard.style.display = 'block';
            if (toggleText) toggleText.textContent = 'Hide Filters';
            filterCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            filterCard.style.display = 'none';
            if (toggleText) toggleText.textContent = 'Filter / Search';
        }
    }

    if (toggleBtn && filterCard) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var isCurrentlyHidden = (filterCard.style.display === 'none' || window.getComputedStyle(filterCard).display === 'none');
            setFilterVisibility(isCurrentlyHidden);
        });
    }

    if (hideBtn && filterCard) {
        hideBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setFilterVisibility(false);
        });
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';

