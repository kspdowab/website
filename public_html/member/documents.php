<?php
/**
 * KSPDOWA — Member Portal: Official Documents Library
 * ============================================================
 * Gated by logged-in authenticated member access only.
 * Private official repository.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$categories = Database::fetchAll("SELECT * FROM document_categories WHERE status = 'active' ORDER BY name");

// Filters
$qCategory = Sanitize::positiveInt($_GET['category'] ?? null);
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));

$whereClauses = ["d.status = 'active'", "d.access_level IN ('member', 'public')"];
$params       = [];

if ($qCategory) {
    $whereClauses[] = "d.category_id = ?";
    $params[]       = $qCategory;
}

if ($qSearch !== '') {
    $whereClauses[] = "(d.title LIKE ? OR d.description LIKE ?)";
    $like = '%' . $qSearch . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT d.*, dc.name AS category_name
    FROM documents d
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    WHERE {$whereSql}
    ORDER BY d.published_at DESC, d.id DESC
";
$documents = Database::fetchAll($sql, $params);

$pageTitle  = 'Documents Library';
$activeMenu = 'documents';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Documents', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Official Documents</h1>
        <p class="page-heading-subtitle">Verified association documents, bye-laws, orders, and official publications</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.82rem; padding:6px 14px;">
            <?= count($documents) ?> Available Files
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Documents
        </div>
        <div class="filter-header-actions">
            <a href="/member/documents.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/member/documents.php" class="filter-body">
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
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="Search by title, description..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/member/documents.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     DOCUMENTS TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Document Repository</span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Document Title</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Published Date</th>
                    <th style="width:120px; text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No documents available matching your search criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($documents as $doc): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($doc['title']) ?>
                        </td>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($doc['category_name'] ?? 'General') ?></span>
                        </td>
                        <td style="color:var(--text-muted); font-size:0.84rem;">
                            <?= Sanitize::html($doc['description'] ?? '—') ?>
                        </td>
                        <td><?= $doc['published_at'] ? date('d-m-Y', strtotime($doc['published_at'])) : '—' ?></td>
                        <td style="text-align:center;">
                            <a href="/document.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View File
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
