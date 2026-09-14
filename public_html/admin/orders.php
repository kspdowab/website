<?php
/**
 * KSPDOWA — Admin: Orders Management
 * ============================================================
 * Gated by RBAC permission 'orders.view' and 'orders.manage'.
 * Section 5 Specification:
 * - Add
 * - Edit
 * - View
 * - Publish / Unpublish / Archive
 * - Category (9 mandatory categories)
 * - Subject
 * - Order No.
 * - Date
 * - File upload (MIME verified, secure path)
 * - Secure View
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'orders', 'view');

$canManage = RBAC::hasPermission($currentUserId, 'orders', 'manage') ||
             RBAC::hasPermission($currentUserId, 'orders', 'create') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

// ─── Mandatory Categories ────────────────────────────────────────────────────
$mandatoryCategories = [
    '16th Finance',
    'VB-G RAM G',
    'eSwathu',
    'eGramSwaraj',
    'GP Staff',
    'Act/Rules',
    'OSR',
    'SC/ST',
    'PH'
];

// Ensure categories exist in document_categories table
function get_or_create_category_id(string $categoryName): ?int {
    $row = Database::fetchOne('SELECT id FROM document_categories WHERE name = ?', [$categoryName]);
    if ($row) {
        return (int)$row['id'];
    }
    Database::execute(
        "INSERT INTO document_categories (name, access_level, status) VALUES (?, 'member', 'active')",
        [$categoryName]
    );
    return (int)Database::lastInsertId();
}

// ─── POST Handler ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if (!$canManage) {
        ErrorHandler::abort(403, 'You do not have permission to manage orders.');
    }

    // ── CREATE ORDER ──
    if ($action === 'create') {
        $category    = Sanitize::string($_POST['category'] ?? '', 100);
        $subject     = Sanitize::string($_POST['subject'] ?? '', 300);
        $orderNo     = Sanitize::string($_POST['order_no'] ?? '', 100);
        $orderDate   = Sanitize::string($_POST['order_date'] ?? '', 12);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description = Sanitize::string($_POST['description'] ?? '', 2000);

        $errors = [];
        if (!in_array($category, $mandatoryCategories, true)) {
            $errors[] = 'Please select a valid category from the approved list.';
        }
        if ($subject === '') {
            $errors[] = 'Subject / Title is required.';
        }
        if ($orderNo === '') {
            $errors[] = 'Order Number is required.';
        } else {
            $existing = Database::fetchOne('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
            if ($existing) {
                $errors[] = 'An order with this Order Number already exists.';
            }
        }
        if ($orderDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            $orderDate = date('Y-m-d');
        }

        // File upload handling
        $docId = null;
        if (isset($_FILES['order_file']) && $_FILES['order_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['order_file'];
            $maxBytes = 10 * 1024 * 1024; // 10 MB

            if ($file['size'] > $maxBytes) {
                $errors[] = 'File size exceeds maximum allowed 10 MB.';
            }

            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc'];
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                $errors[] = 'Invalid file type. Allowed: PDF, JPG, PNG, DOCX.';
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $allowedMimes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword'
            ];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Invalid file MIME type (' . htmlspecialchars($mime) . ').';
            }

            if (empty($errors)) {
                $ordersDir = UPLOADS_DIR . '/orders';
                if (!is_dir($ordersDir)) {
                    mkdir($ordersDir, 0755, true);
                }

                $safeName = 'order_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destPath = $ordersDir . '/' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $catId = get_or_create_category_id($category);
                    Database::execute(
                        "INSERT INTO documents (title, category_id, description, file_path, access_level, published_at, uploaded_by, status)
                         VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active')",
                        [$subject, $catId, $description, 'orders/' . $safeName, $accessLevel, $currentUserId]
                    );
                    $docId = (int)Database::lastInsertId();
                    AuditLogger::log('UPLOAD', 'documents', $docId, null, ['filename' => $safeName]);
                } else {
                    $errors[] = 'Failed to store uploaded file on server.';
                }
            }
        }

        if (empty($errors)) {
            Database::execute(
                "INSERT INTO orders (title, order_no, order_date, department, description, document_id, access_level)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$subject, $orderNo, $orderDate, $category, $description, $docId, $accessLevel]
            );
            $orderId = (int)Database::lastInsertId();
            AuditLogger::log('CREATE', 'orders', $orderId, null, ['order_no' => $orderNo]);
            Session::flash('success', "Order '{$orderNo}' created successfully.");
            header('Location: /admin/orders.php');
            exit;
        } else {
            Session::flash('error', implode(' ', $errors));
        }
    }

    // ── UPDATE ORDER ──
    if ($action === 'update') {
        $id          = Sanitize::positiveInt($_POST['id'] ?? null);
        $category    = Sanitize::string($_POST['category'] ?? '', 100);
        $subject     = Sanitize::string($_POST['subject'] ?? '', 300);
        $orderNo     = Sanitize::string($_POST['order_no'] ?? '', 100);
        $orderDate   = Sanitize::string($_POST['order_date'] ?? '', 12);
        $accessLevel = Sanitize::inArray($_POST['access_level'] ?? 'member', ['member', 'public', 'officer', 'admin']) ?: 'member';
        $description = Sanitize::string($_POST['description'] ?? '', 2000);

        if (!$id) {
            Session::flash('error', 'Invalid order ID.');
            header('Location: /admin/orders.php');
            exit;
        }

        $existing = Database::fetchOne('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$existing) {
            Session::flash('error', 'Order not found.');
            header('Location: /admin/orders.php');
            exit;
        }

        $dup = Database::fetchOne('SELECT id FROM orders WHERE order_no = ? AND id != ?', [$orderNo, $id]);
        if ($dup) {
            Session::flash('error', 'Another order with this Order Number already exists.');
            header('Location: /admin/orders.php?edit=' . $id);
            exit;
        }

        $docId = $existing['document_id'];
        // Replacement file upload
        if (isset($_FILES['order_file']) && $_FILES['order_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['order_file'];
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc'];
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

            if (in_array($ext, $allowedExtensions, true) && $file['size'] <= 10 * 1024 * 1024) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];

                if (in_array($mime, $allowedMimes, true)) {
                    $ordersDir = UPLOADS_DIR . '/orders';
                    if (!is_dir($ordersDir)) { mkdir($ordersDir, 0755, true); }
                    $safeName = 'order_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $ordersDir . '/' . $safeName)) {
                        $catId = get_or_create_category_id($category);
                        if ($docId) {
                            Database::execute(
                                "UPDATE documents SET title = ?, category_id = ?, file_path = ?, access_level = ?, status = 'active' WHERE id = ?",
                                [$subject, $catId, 'orders/' . $safeName, $accessLevel, $docId]
                            );
                        } else {
                            Database::execute(
                                "INSERT INTO documents (title, category_id, description, file_path, access_level, published_at, uploaded_by, status)
                                 VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active')",
                                [$subject, $catId, $description, 'orders/' . $safeName, $accessLevel, $currentUserId]
                            );
                            $docId = (int)Database::lastInsertId();
                        }
                    }
                }
            }
        }

        Database::execute(
            "UPDATE orders SET title = ?, order_no = ?, order_date = ?, department = ?, description = ?, document_id = ?, access_level = ? WHERE id = ?",
            [$subject, $orderNo, $orderDate, $category, $description, $docId, $accessLevel, $id]
        );

        AuditLogger::log('UPDATE', 'orders', $id, null, ['order_no' => $orderNo]);
        Session::flash('success', "Order '{$orderNo}' updated successfully.");
        header('Location: /admin/orders.php');
        exit;
    }

    // ── ARCHIVE / UNARCHIVE ──
    if ($action === 'toggle_archive') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $row = Database::fetchOne('SELECT o.*, d.status AS doc_status FROM orders o LEFT JOIN documents d ON d.id = o.document_id WHERE o.id = ?', [$id]);
            if ($row && $row['document_id']) {
                $newStatus = ($row['doc_status'] === 'archived') ? 'active' : 'archived';
                Database::execute('UPDATE documents SET status = ? WHERE id = ?', [$newStatus, $row['document_id']]);
                AuditLogger::log('STATUS_CHANGE', 'orders', $id, null, ['new_status' => $newStatus]);
                Session::flash('success', "Order status changed to " . ucfirst($newStatus) . ".");
            }
        }
        header('Location: /admin/orders.php');
        exit;
    }
}

// ─── Filters & Listing ───────────────────────────────────────────────────────
$qCategory = trim((string)($_GET['category'] ?? ''));
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qFromDate = trim(Sanitize::string($_GET['from_date'] ?? '', 12));
$qToDate   = trim(Sanitize::string($_GET['to_date'] ?? '', 12));
$editId    = Sanitize::positiveInt($_GET['edit'] ?? null);
$showForm  = isset($_GET['add']) || $editId;

$whereClause = ["1=1"];
$params      = [];

if ($qCategory !== '' && in_array($qCategory, $mandatoryCategories, true)) {
    $whereClause[] = "(dc.name = ? OR o.department = ?)";
    $params[] = $qCategory;
    $params[] = $qCategory;
}

if ($qSearch !== '') {
    $whereClause[] = "(o.title LIKE ? OR o.order_no LIKE ? OR o.description LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

if ($qFromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qFromDate)) {
    $whereClause[] = "o.order_date >= ?";
    $params[] = $qFromDate;
}

if ($qToDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qToDate)) {
    $whereClause[] = "o.order_date <= ?";
    $params[] = $qToDate;
}

$whereSql = implode(' AND ', $whereClause);

// Total Count
$countSql = "
    SELECT COUNT(*) AS total
    FROM orders o
    LEFT JOIN documents d ON d.id = o.document_id
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    WHERE {$whereSql}
";
$totalOrders = (int)(Database::fetchOne($countSql, $params)['total'] ?? 0);

// Fetch orders list
$sql = "
    SELECT o.*, d.file_path, d.status AS doc_status, dc.name AS category_name
    FROM orders o
    LEFT JOIN documents d ON d.id = o.document_id
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    WHERE {$whereSql}
    ORDER BY o.order_date DESC, o.id DESC
";
$ordersList = Database::fetchAll($sql, $params);

// Edit Row
$editRow = null;
if ($editId) {
    $editRow = Database::fetchOne(
        "SELECT o.*, d.file_path, d.status AS doc_status, dc.name AS category_name
         FROM orders o
         LEFT JOIN documents d ON d.id = o.document_id
         LEFT JOIN document_categories dc ON dc.id = d.category_id
         WHERE o.id = ?",
        [$editId]
    );
}

$pageTitle  = 'Orders Management';
$activeMenu = 'orders';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Orders', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Orders Management</h1>
        <p class="page-heading-subtitle">Add, edit, publish, and manage official Government Orders</p>
    </div>
    <div>
        <?php if ($canManage): ?>
            <?php if ($showForm): ?>
                <a href="/admin/orders.php" class="btn btn-outline">← Back to List</a>
            <?php else: ?>
                <a href="/admin/orders.php?add=1" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Order
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ADD / EDIT ORDER FORM
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($showForm && $canManage): ?>
<div class="table-card" style="margin-bottom:28px;">
    <div class="table-card-header" style="background:#f8fafc;">
        <span class="table-card-title"><?= $editRow ? 'Edit Order: ' . Sanitize::html($editRow['order_no']) : 'Add New Order' ?></span>
        <a href="/admin/orders.php" class="btn btn-outline btn-sm">✕ Cancel</a>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/orders.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:18px 24px;">
                <div class="form-group">
                    <label class="form-label" for="category_sel">Category *</label>
                    <select name="category" id="category_sel" class="form-select" required>
                        <option value="">— Select Category —</option>
                        <?php
                        $currentCat = $editRow ? ($editRow['category_name'] ?? $editRow['department']) : '';
                        foreach ($mandatoryCategories as $mc): ?>
                            <option value="<?= Sanitize::attr($mc) ?>" <?= $currentCat === $mc ? 'selected' : '' ?>>
                                <?= Sanitize::html($mc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="order_no">Order Number *</label>
                    <input type="text" name="order_no" id="order_no" class="form-control" required maxlength="100" placeholder="e.g. RDP/124/PDO/2026" value="<?= Sanitize::attr($editRow['order_no'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="order_date">Order Date *</label>
                    <input type="date" name="order_date" id="order_date" class="form-control" required value="<?= Sanitize::attr($editRow['order_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="access_level">Access Level *</label>
                    <select name="access_level" id="access_level" class="form-select" required>
                        <option value="member" <?= ($editRow['access_level'] ?? 'member') === 'member' ? 'selected' : '' ?>>Member Only (Standard)</option>
                        <option value="public" <?= ($editRow['access_level'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="officer" <?= ($editRow['access_level'] ?? '') === 'officer' ? 'selected' : '' ?>>Officer Level</option>
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="subject">Subject / Title *</label>
                    <input type="text" name="subject" id="subject" class="form-control" required maxlength="300" placeholder="Subject description of the Government order" value="<?= Sanitize::attr($editRow['title'] ?? '') ?>">
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="description">Detailed Remarks / Description</label>
                    <textarea name="description" id="description" rows="3" class="form-control" placeholder="Additional notes or references..."><?= Sanitize::html($editRow['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" for="order_file">Order Document File (PDF, DOCX, JPG, PNG — Max 10MB)</label>
                    <input type="file" name="order_file" id="order_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx,.doc">
                    <?php if ($editRow && !empty($editRow['document_id'])): ?>
                        <div style="margin-top:8px; font-size:0.82rem; color:var(--text-muted);">
                            Current file: <a href="/document.php?id=<?= (int)$editRow['document_id'] ?>" target="_blank" style="font-weight:600;">View Existing File</a> (Upload new file above to replace)
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary">
                    <?= $editRow ? 'Save Changes' : 'Publish Order' ?>
                </button>
                <a href="/admin/orders.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Orders
        </div>
        <div class="filter-header-actions">
            <a href="/admin/orders.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/admin/orders.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($mandatoryCategories as $mc): ?>
                        <option value="<?= Sanitize::attr($mc) ?>" <?= $qCategory === $mc ? 'selected' : '' ?>>
                            <?= Sanitize::html($mc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= Sanitize::attr($qFromDate) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= Sanitize::attr($qToDate) ?>">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Order No., Subject..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/orders.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ORDERS LIST TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Government Orders</span>
            <span class="table-card-count">(<?= count($ordersList) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Order No.</th>
                    <th>Order Date</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Document</th>
                    <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ordersList)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No orders found matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($ordersList as $ord): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($ord['category_name'] ?? $ord['department'] ?? 'General') ?></span>
                        </td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($ord['title']) ?>
                        </td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:3px 7px; border-radius:4px;">
                                <?= Sanitize::html($ord['order_no']) ?>
                            </code>
                        </td>
                        <td><?= $ord['order_date'] ? date('d-m-Y', strtotime($ord['order_date'])) : '—' ?></td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($ord['access_level'])) ?></span>
                        </td>
                        <td>
                            <?php if (($ord['doc_status'] ?? 'active') === 'archived'): ?>
                                <span class="badge badge-neutral">Archived</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ord['document_id']): ?>
                                <a href="/document.php?id=<?= (int)$ord['document_id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:0.8rem;">None</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <a href="/admin/orders.php?edit=<?= (int)$ord['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                <?php if ($ord['document_id']): ?>
                                <form method="post" action="/admin/orders.php" style="display:inline;" onsubmit="return confirm('Change archive status of this order?');">
                                    <?= CSRF::htmlField() ?>
                                    <input type="hidden" name="action" value="toggle_archive">
                                    <input type="hidden" name="id" value="<?= (int)$ord['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--text-muted);">
                                        <?= ($ord['doc_status'] ?? 'active') === 'archived' ? 'Unarchive' : 'Archive' ?>
                                    </button>
                                </form>
                                <?php endif; ?>
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

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
