<?php
/**
 * KSPDOWA — Admin: Photo Gallery Management
 * ============================================================
 * Allows Admins/Officers to upload and manage photos displayed in:
 * 1. Public Home Page Scrolling Gallery Carousel (/index.php)
 * 2. Public Association Photo Gallery (/gallery.php)
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

// Permission check: gallery.view or activities.view or Super Admin
if (!admin_can($currentUserId, 'gallery', 'view') && !admin_can($currentUserId, 'activities', 'view')) {
    RBAC::requirePermission($currentUserId, 'gallery', 'view');
}

$canManage = admin_can($currentUserId, 'gallery', 'manage') ||
             admin_can($currentUserId, 'activities', 'manage') ||
             RBAC::hasRole($currentUserId, 'State Super Admin');

$uploadDir = dirname(__DIR__) . '/assets/images/gallery';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// ─── POST Handlers ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    // ── Upload New Photo ──────────────────────────────────────────────────────
    if ($action === 'create') {
        $title       = Sanitize::string($_POST['title'] ?? '', 255);
        $category    = Sanitize::string($_POST['category'] ?? 'General', 100);
        $photoDate   = Sanitize::string($_POST['photo_date'] ?? '', 12);
        $description = Sanitize::string($_POST['description'] ?? '', 3000);
        $showOnHome  = !empty($_POST['show_on_home']) ? 1 : 0;
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        if ($title === '') {
            Session::flash('error', 'Photo title is required.');
            header('Location: /admin/gallery.php');
            exit;
        }

        if (!isset($_FILES['photo_file']) || $_FILES['photo_file']['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Please select a valid image file to upload.');
            header('Location: /admin/gallery.php');
            exit;
        }

        $fileTmp  = $_FILES['photo_file']['tmp_name'];
        $fileSize = (int)$_FILES['photo_file']['size'];
        $origName = $_FILES['photo_file']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Validation: file size max 8MB
        if ($fileSize > 8 * 1024 * 1024) {
            Session::flash('error', 'File size exceeds the 8MB limit.');
            header('Location: /admin/gallery.php');
            exit;
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExts, true)) {
            Session::flash('error', 'Only JPG, PNG, and WebP image formats are allowed.');
            header('Location: /admin/gallery.php');
            exit;
        }

        $mime = mime_content_type($fileTmp);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            Session::flash('error', 'Invalid image file content.');
            header('Location: /admin/gallery.php');
            exit;
        }

        // Generate safe unique filename
        $newFilename = 'gallery_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath    = $uploadDir . '/' . $newFilename;

        if (!move_uploaded_file($fileTmp, $destPath)) {
            Session::flash('error', 'Failed to save uploaded image. Please check directory permissions.');
            header('Location: /admin/gallery.php');
            exit;
        }

        // Mirror uploaded image to sibling preview or production directory if it exists
        $siblingDirs = [
            dirname(__DIR__, 2) . '/assets/images/gallery',
            dirname(__DIR__) . '/preview/assets/images/gallery',
        ];
        foreach ($siblingDirs as $sDir) {
            if (is_dir($sDir) && is_writable($sDir)) {
                @copy($destPath, $sDir . '/' . $newFilename);
            }
        }

        $webPath = '/assets/images/gallery/' . $newFilename;

        if ($photoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $photoDate)) {
            $photoDate = date('Y-m-d');
        }

        Database::execute(
            "INSERT INTO gallery_photos
                (title, category, photo_date, description, file_path, sort_order, show_on_home, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)",
            [$title, $category ?: 'General', $photoDate, $description ?: null, $webPath, $sortOrder, $showOnHome, $currentUserId]
        );

        $newId = (int)Database::lastInsertId();
        AuditLogger::log('CREATE', 'gallery_photos', $newId, null, ['title' => $title, 'file' => $webPath]);
        Session::flash('success', "Photo '{$title}' uploaded successfully and added to gallery.");
        header('Location: /admin/gallery.php');
        exit;
    }

    // ── Toggle Show on Home Page Carousel ────────────────────────────────────
    if ($action === 'toggle_home') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $current = Database::fetchOne("SELECT id, show_on_home, title FROM gallery_photos WHERE id = ?", [$id]);
            if ($current) {
                $newVal = $current['show_on_home'] ? 0 : 1;
                Database::execute("UPDATE gallery_photos SET show_on_home = ? WHERE id = ?", [$newVal, $id]);
                $msg = $newVal ? "Photo will now appear on the Public Home Page scrolling carousel." : "Photo removed from Home Page carousel.";
                Session::flash('success', $msg);
            }
        }
        header('Location: /admin/gallery.php');
        exit;
    }

    // ── Toggle Status (Active / Archived) ────────────────────────────────────
    if ($action === 'toggle_status') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $current = Database::fetchOne("SELECT id, status FROM gallery_photos WHERE id = ?", [$id]);
            if ($current) {
                $newStatus = ($current['status'] === 'active') ? 'archived' : 'active';
                Database::execute("UPDATE gallery_photos SET status = ? WHERE id = ?", [$newStatus, $id]);
                Session::flash('success', "Photo status updated to {$newStatus}.");
            }
        }
        header('Location: /admin/gallery.php');
        exit;
    }

    // ── Update Photo Metadata ────────────────────────────────────────────────
    if ($action === 'update') {
        $id          = Sanitize::positiveInt($_POST['id'] ?? null);
        $title       = Sanitize::string($_POST['title'] ?? '', 255);
        $category    = Sanitize::string($_POST['category'] ?? 'General', 100);
        $photoDate   = Sanitize::string($_POST['photo_date'] ?? '', 12);
        $description = Sanitize::string($_POST['description'] ?? '', 3000);
        $showOnHome  = !empty($_POST['show_on_home']) ? 1 : 0;
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        if ($id && $title !== '') {
            $existing = Database::fetchOne("SELECT * FROM gallery_photos WHERE id = ?", [$id]);
            if ($existing) {
                $filePath = $existing['file_path'];

                // Check if user uploaded a replacement image
                if (isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp  = $_FILES['photo_file']['tmp_name'];
                    $fileSize = (int)$_FILES['photo_file']['size'];
                    $origName = $_FILES['photo_file']['name'];
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    if ($fileSize <= 8 * 1024 * 1024 && in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                        $mime = mime_content_type($fileTmp);
                        if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                            $newFilename = 'gallery_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $destPath    = $uploadDir . '/' . $newFilename;
                            if (move_uploaded_file($fileTmp, $destPath)) {
                                // Delete old uploaded file if it was a user upload
                                if (str_contains($filePath, '/gallery_')) {
                                    $oldDiskPath = dirname(__DIR__) . $filePath;
                                    if (file_exists($oldDiskPath)) { @unlink($oldDiskPath); }
                                }
                                $filePath = '/assets/images/gallery/' . $newFilename;
                            }
                        }
                    }
                }

                if ($photoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $photoDate)) {
                    $photoDate = $existing['photo_date'] ?: date('Y-m-d');
                }

                Database::execute(
                    "UPDATE gallery_photos
                     SET title = ?, category = ?, photo_date = ?, description = ?, file_path = ?, sort_order = ?, show_on_home = ?
                     WHERE id = ?",
                    [$title, $category ?: 'General', $photoDate, $description ?: null, $filePath, $sortOrder, $showOnHome, $id]
                );

                AuditLogger::log('UPDATE', 'gallery_photos', $id, null, ['title' => $title]);
                Session::flash('success', "Photo '{$title}' updated successfully.");
            }
        }
        header('Location: /admin/gallery.php');
        exit;
    }

    // ── Delete Photo ─────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);
        if ($id) {
            $existing = Database::fetchOne("SELECT id, title, file_path FROM gallery_photos WHERE id = ?", [$id]);
            if ($existing) {
                // Delete physical file if it was a user upload (contains /gallery_)
                if (str_contains($existing['file_path'], '/gallery_')) {
                    $diskPath = dirname(__DIR__) . $existing['file_path'];
                    if (file_exists($diskPath)) { @unlink($diskPath); }
                }
                Database::execute("DELETE FROM gallery_photos WHERE id = ?", [$id]);
                AuditLogger::log('DELETE', 'gallery_photos', $id, null, ['title' => $existing['title']]);
                Session::flash('success', "Photo '{$existing['title']}' deleted successfully.");
            }
        }
        header('Location: /admin/gallery.php');
        exit;
    }
}

// ─── Query & Filters ──────────────────────────────────────────────────────────
$filterCategory = Sanitize::string($_GET['category'] ?? '', 100);
$filterStatus   = Sanitize::string($_GET['status'] ?? 'active', 20);
$filterHome     = Sanitize::string($_GET['home'] ?? '', 10);

$where = ["1=1"];
$params = [];

if ($filterCategory !== '') {
    $where[]  = "g.category = ?";
    $params[] = $filterCategory;
}

if ($filterStatus === 'active') {
    $where[]  = "g.status = 'active'";
} elseif ($filterStatus === 'archived') {
    $where[]  = "g.status = 'archived'";
}

if ($filterHome === '1') {
    $where[] = "g.show_on_home = 1";
} elseif ($filterHome === '0') {
    $where[] = "g.show_on_home = 0";
}

$whereSql = implode(' AND ', $where);
$photos = Database::fetchAll(
    "SELECT g.*, u.username AS uploader_name
     FROM gallery_photos g
     LEFT JOIN users u ON u.id = g.uploaded_by
     WHERE {$whereSql}
     ORDER BY g.sort_order ASC, g.photo_date DESC, g.id DESC",
    $params
);

$allCategories = Database::fetchAll("SELECT DISTINCT category FROM gallery_photos WHERE category IS NOT NULL AND category != '' ORDER BY category");

// Quick Stats
$totalCount = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM gallery_photos")['c'] ?? 0);
$homeCount  = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM gallery_photos WHERE status = 'active' AND show_on_home = 1")['c'] ?? 0);
$activeCount = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM gallery_photos WHERE status = 'active'")['c'] ?? 0);

$editPhoto = null;
if (isset($_GET['edit'])) {
    $editId = Sanitize::positiveInt($_GET['edit']);
    if ($editId) {
        $editPhoto = Database::fetchOne("SELECT * FROM gallery_photos WHERE id = ?", [$editId]);
    }
}

$pageTitle   = 'Photo Gallery Management';
$activeMenu  = 'gallery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Photo Gallery', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Photo Gallery Management</h1>
        <p class="page-heading-subtitle">Upload and manage photo archives for the <strong>Public Home Page Scrolling Gallery</strong> and <strong>Association Gallery</strong></p>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <a href="/gallery.php" target="_blank" class="btn btn-outline" style="font-size:0.84rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            View Public Gallery
        </a>
        <a href="/" target="_blank" class="btn btn-outline" style="font-size:0.84rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            View Home Page
        </a>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="toggleUploadCard()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Upload New Photo
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STAT CARDS (Official KSPDOWA Design System)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="stats-grid" style="margin-bottom:20px;">
    <!-- Total Photos -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Total Photos</span>
            <span class="stat-number"><?= number_format($totalCount) ?></span>
            <span class="stat-subtext">Across all categories</span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
    </div>

    <!-- Home Carousel -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">On Home Carousel</span>
            <span class="stat-number" style="color:var(--success-dark);"><?= number_format($homeCount) ?></span>
            <span class="stat-subtext" style="color:var(--success-dark);">Rotating on public home page</span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
        </div>
    </div>

    <!-- Active in Gallery -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Active in Gallery</span>
            <span class="stat-number" style="color:var(--blue-700);"><?= number_format($activeCount) ?></span>
            <span class="stat-subtext">Publicly visible on /gallery.php</span>
        </div>
        <div class="stat-icon-box stat-icon-purple">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <!-- Archived / Hidden -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Archived</span>
            <span class="stat-number" style="color:var(--text-muted);"><?= number_format(max(0, $totalCount - $activeCount)) ?></span>
            <span class="stat-subtext">Hidden from public view</span>
        </div>
        <div class="stat-icon-box" style="background:#f1f5f9; color:#64748b;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     UPLOAD / EDIT FORM CARD
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" id="uploadPhotoCard" style="display: <?= $editPhoto ? 'block' : 'none' ?>; margin-bottom:24px; border:1px solid var(--blue-500); box-shadow:var(--shadow-md);">
    <div class="table-card-header" style="background:linear-gradient(135deg, var(--blue-700) 0%, var(--blue-600) 100%); color:#ffffff;">
        <span class="table-card-title" style="color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <?= $editPhoto ? 'Edit Gallery Photo: ' . Sanitize::html($editPhoto['title']) : 'Upload New Photo to Gallery &amp; Home Page Carousel' ?>
        </span>
        <button type="button" class="btn btn-sm" onclick="closeUploadCard()" style="background:rgba(255,255,255,0.2); color:#ffffff; border:none; padding:4px 10px; border-radius:4px; font-weight:600;">✕ Cancel</button>
    </div>
    <div style="padding:24px;">
        <form method="post" action="/admin/gallery.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editPhoto ? 'update' : 'create' ?>">
            <?php if ($editPhoto): ?>
                <input type="hidden" name="id" value="<?= (int)$editPhoto['id'] ?>">
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Photo Title (Kannada / English) *</label>
                    <input type="text" name="title" class="form-control" required maxlength="255"
                           placeholder="e.g. ರಾಜ್ಯ ಮಟ್ಟದ ಮಹಾಸಭೆ, ಬೆಂಗಳೂರು / State Level General Meeting"
                           value="<?= Sanitize::attr($editPhoto['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Category *</label>
                    <input type="text" name="category" list="categoryList" class="form-control" maxlength="100"
                           placeholder="e.g. State Executive, Training, Coordination"
                           value="<?= Sanitize::attr($editPhoto['category'] ?? 'General') ?>" required>
                    <datalist id="categoryList">
                        <option value="State Executive">
                        <option value="Training">
                        <option value="Coordination">
                        <option value="Felicitation">
                        <option value="General Body">
                        <option value="Executive Consultation">
                        <option value="Welfare">
                        <option value="Conference">
                    </datalist>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Event / Photo Date</label>
                    <input type="date" name="photo_date" class="form-control"
                           value="<?= Sanitize::attr($editPhoto['photo_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Display Sort Order (Lower = first)</label>
                    <input type="number" name="sort_order" class="form-control" min="0" step="1"
                           value="<?= (int)($editPhoto['sort_order'] ?? 0) ?>">
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Select Image File (JPG, PNG, WebP — Max 8MB) <?= $editPhoto ? '(leave blank to keep existing image)' : '*' ?></label>
                    <input type="file" name="photo_file" accept=".jpg,.jpeg,.png,.webp" class="form-control" <?= $editPhoto ? '' : 'required' ?> onchange="previewSelectedImage(this)">
                    <?php if ($editPhoto && !empty($editPhoto['file_path'])): ?>
                        <div style="margin-top:12px; display:flex; align-items:center; gap:12px; padding:8px 12px; background:#f8fafc; border-radius:var(--radius-sm); border:1px solid var(--border-color);">
                            <img src="<?= Sanitize::attr($editPhoto['file_path']) ?>" style="height:60px; width:90px; border-radius:6px; border:1px solid #cbd5e1; object-fit:cover;">
                            <div>
                                <span style="font-size:0.84rem; font-weight:600; color:var(--text-main); display:block;">Current Photo</span>
                                <span style="font-size:0.75rem; color:var(--text-muted);"><?= Sanitize::html(basename($editPhoto['file_path'])) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div id="imagePreviewBox" style="margin-top:12px; display:none;">
                        <div style="font-size:0.8rem; font-weight:600; color:var(--blue-700); margin-bottom:6px;">New Photo Preview:</div>
                        <img id="imagePreviewImg" src="" style="height:120px; border-radius:6px; border:2px solid var(--blue-400); object-fit:cover; box-shadow:var(--shadow-sm);">
                    </div>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Description / Caption (Optional)</label>
                    <textarea name="description" rows="2" class="form-control" placeholder="Brief notes or description about the activity or photo event"><?= Sanitize::html($editPhoto['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <div style="background:var(--blue-50); border:1px solid var(--blue-100); border-radius:var(--radius-md); padding:14px 18px;">
                        <label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-weight:700; color:var(--blue-900); font-size:0.92rem;">
                            <input type="checkbox" name="show_on_home" value="1" <?= (!isset($editPhoto) || !empty($editPhoto['show_on_home'])) ? 'checked' : '' ?> style="width:19px; height:19px; accent-color:var(--blue-600);">
                            <span>Show this photo in the <strong>Public Home Page Scrolling Gallery Carousel</strong></span>
                        </label>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin:4px 0 0 29px; line-height:1.4;">
                            When enabled, this photo will be featured in the auto-scrolling visual carousel right below the header on the public homepage.
                        </p>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <?= $editPhoto ? 'Save Changes' : 'Upload &amp; Publish Photo' ?>
                </button>
                <button type="button" class="btn btn-outline" onclick="closeUploadCard()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTER & SEARCH BAR (KSPDOWA Filter Card Style)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar" style="background:linear-gradient(135deg, var(--blue-700), var(--blue-600));">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter &amp; Search Gallery
        </div>
        <span style="font-size:0.75rem; background:rgba(255,255,255,0.22); padding:2px 8px; border-radius:4px; font-weight:600;">
            Showing <?= count($photos) ?> of <?= $totalCount ?> Photos
        </span>
    </div>
    <div class="filter-body">
        <form method="get" action="/admin/gallery.php" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
            <div style="flex:1; min-width:180px;">
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Category</label>
                <select name="category" class="form-select" style="font-size:0.86rem;">
                    <option value="">All Categories</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= Sanitize::attr($cat['category']) ?>" <?= $filterCategory === $cat['category'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat['category']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex:1; min-width:170px;">
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Home Carousel</label>
                <select name="home" class="form-select" style="font-size:0.86rem;">
                    <option value="">All Visibility</option>
                    <option value="1" <?= $filterHome === '1' ? 'selected' : '' ?>>★ On Home Carousel Only</option>
                    <option value="0" <?= $filterHome === '0' ? 'selected' : '' ?>>Not on Home Carousel</option>
                </select>
            </div>

            <div style="flex:1; min-width:140px;">
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Status</label>
                <select name="status" class="form-select" style="font-size:0.86rem;">
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Archived Only</option>
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Records</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Apply Filter
                </button>
                <a href="/admin/gallery.php" class="btn btn-outline btn-sm" style="padding:8px 14px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     GALLERY PHOTOS GRID
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (empty($photos)): ?>
    <div style="background:#ffffff; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:48px 24px; text-align:center; color:var(--text-muted);">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px; color:var(--text-light); display:block;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <h3 style="font-size:1.1rem; color:var(--text-main); margin:0 0 6px;">No gallery photos match your filter</h3>
        <p style="font-size:0.88rem; margin:0 0 16px;">Try adjusting your filters or upload a new photo.</p>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="toggleUploadCard()">+ Upload Photo Now</button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:22px; margin-bottom:30px;">
        <?php foreach ($photos as $photo): ?>
            <div style="background:#ffffff; border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; transition:transform 0.18s ease, box-shadow 0.18s ease;">
                <!-- Thumbnail Image Area -->
                <div style="position:relative; height:200px; background:#0f172a; overflow:hidden;">
                    <img src="<?= Sanitize::attr($photo['file_path']) ?>" alt="<?= Sanitize::attr($photo['title']) ?>"
                         style="width:100%; height:100%; object-fit:cover; display:block;" loading="lazy">
                    
                    <!-- Dark Gradient Overlay for optimal badge contrast -->
                    <div style="position:absolute; inset:0; background:linear-gradient(180deg, rgba(15,23,42,0.55) 0%, transparent 40%, transparent 60%, rgba(15,23,42,0.7) 100%); pointer-events:none;"></div>

                    <!-- Category Badge -->
                    <span style="position:absolute; top:10px; left:10px; background:rgba(15,23,42,0.85); backdrop-filter:blur(6px); color:#f8fafc; font-size:0.72rem; padding:4px 10px; border-radius:6px; font-weight:600; border:1px solid rgba(255,255,255,0.18); letter-spacing:0.3px;">
                        <?= Sanitize::html($photo['category']) ?>
                    </span>

                    <!-- Home Carousel Indicator Badge -->
                    <?php if ($photo['show_on_home']): ?>
                        <span style="position:absolute; top:10px; right:10px; background:rgba(16,185,129,0.95); backdrop-filter:blur(6px); color:#ffffff; font-size:0.72rem; padding:4px 10px; border-radius:6px; font-weight:700; box-shadow:0 2px 8px rgba(16,185,129,0.35); display:inline-flex; align-items:center; gap:4px; border:1px solid rgba(255,255,255,0.25);">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            On Home Carousel
                        </span>
                    <?php else: ?>
                        <span style="position:absolute; top:10px; right:10px; background:rgba(15,23,42,0.7); backdrop-filter:blur(6px); color:#cbd5e1; font-size:0.72rem; padding:4px 10px; border-radius:6px; font-weight:500; border:1px solid rgba(255,255,255,0.1);">
                            Gallery Only
                        </span>
                    <?php endif; ?>

                    <?php if ($photo['status'] === 'archived'): ?>
                        <span style="position:absolute; bottom:10px; left:10px; background:rgba(239,68,68,0.9); backdrop-filter:blur(6px); color:#fff; font-size:0.7rem; padding:3px 8px; border-radius:4px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">
                            Archived
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Info Body -->
                <div style="padding:16px 18px; flex:1; display:flex; flex-direction:column;">
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:6px; display:flex; align-items:center; justify-content:space-between;">
                        <span style="display:inline-flex; align-items:center; gap:5px; font-weight:500;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= !empty($photo['photo_date']) ? date('d M Y', strtotime($photo['photo_date'])) : '—' ?>
                        </span>
                        <span class="badge badge-purple" style="font-size:0.7rem; padding:2px 8px;">Order: #<?= (int)$photo['sort_order'] ?></span>
                    </div>

                    <h3 style="font-size:0.96rem; font-weight:700; color:var(--text-main); margin:6px 0 6px; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.7em;">
                        <?= Sanitize::html($photo['title']) ?>
                    </h3>

                    <p style="font-size:0.81rem; color:var(--text-muted); line-height:1.45; margin:0 0 14px; flex:1; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.35em;">
                        <?= Sanitize::html($photo['description'] ?: 'No additional description provided.') ?>
                    </p>
                </div>

                <!-- Actions Footer -->
                <?php if ($canManage): ?>
                <div style="background:#f8fafc; border-top:1px solid var(--border-color); padding:10px 14px; display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:wrap;">
                    <!-- Toggle Home Button -->
                    <form method="post" action="/admin/gallery.php" style="display:inline;">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="toggle_home">
                        <input type="hidden" name="id" value="<?= (int)$photo['id'] ?>">
                        <?php if ($photo['show_on_home']): ?>
                            <button type="submit" class="btn btn-sm btn-outline"
                                    style="font-size:0.75rem; padding:5px 10px; color:#b45309; border-color:#fde68a; background:#fffbeb;"
                                    title="Remove this photo from the Home Page rotating carousel">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                Remove from Home
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-primary"
                                    style="font-size:0.75rem; padding:5px 10px;"
                                    title="Show this photo in the Public Home Page scrolling carousel">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                Add to Home
                            </button>
                        <?php endif; ?>
                    </form>

                    <div style="display:inline-flex; align-items:center; gap:6px;">
                        <!-- Edit Button -->
                        <a href="/admin/gallery.php?edit=<?= (int)$photo['id'] ?>" class="btn btn-outline btn-sm"
                           style="font-size:0.75rem; padding:5px 9px;" title="Edit details">
                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Edit
                        </a>

                        <!-- Toggle Status Button -->
                        <form method="post" action="/admin/gallery.php" style="display:inline;">
                            <?= CSRF::htmlField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int)$photo['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:5px 9px;">
                                <?= $photo['status'] === 'active' ? 'Archive' : 'Activate' ?>
                            </button>
                        </form>

                        <!-- Delete Button -->
                        <form method="post" action="/admin/gallery.php" style="display:inline;"
                              onsubmit="return confirm('Are you sure you want to permanently delete this photo?');">
                            <?= CSRF::htmlField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$photo['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="font-size:0.75rem; padding:5px 8px; color:var(--danger); background:var(--danger-bg); border:1px solid #fecaca;" title="Permanently Delete Photo">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function toggleUploadCard() {
    var card = document.getElementById('uploadPhotoCard');
    if (card.style.display === 'none' || card.style.display === '') {
        card.style.display = 'block';
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        card.style.display = 'none';
    }
}

function closeUploadCard() {
    document.getElementById('uploadPhotoCard').style.display = 'none';
    if (window.location.search.indexOf('edit=') !== -1) {
        window.location.href = '/admin/gallery.php';
    }
}

function previewSelectedImage(input) {
    var previewBox = document.getElementById('imagePreviewBox');
    var previewImg = document.getElementById('imagePreviewImg');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewBox.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        previewBox.style.display = 'none';
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
