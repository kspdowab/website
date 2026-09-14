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

$hasActiveFilters = (!empty($qCategory) || $qSearch !== '');

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Official Documents</h1>
        <p class="page-heading-subtitle">Verified association documents, bye-laws, orders, and official publications</p>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="button" id="toggleFilterBtn" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:7px; font-size:0.84rem; padding:6px 14px; background:#ffffff; border:1px solid var(--border, #dce3ea); border-radius:6px; cursor:pointer; font-weight:600; color:var(--primary-navy, #173F67); box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span id="filterToggleText"><?= $hasActiveFilters ? 'Hide Filters' : 'Filter Documents' ?></span>
            <?php if ($hasActiveFilters): ?>
                <span class="badge badge-purple" style="font-size:0.72rem; padding:2px 7px;">Active</span>
            <?php endif; ?>
        </button>
        <span class="badge badge-purple" style="font-size:0.82rem; padding:7px 14px;">
            <?= count($documents) ?> Available Files
        </span>
    </div>
</div>

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
            <a href="/member/documents.php" class="filter-header-btn">↺ Reset</a>
            <button type="button" id="hideFilterBtn" class="filter-header-btn" style="background:none; border:1px solid var(--border, #dce3ea); cursor:pointer; display:inline-flex; align-items:center; gap:4px;" title="Hide Filter Section">
                ✕ Hide
            </button>
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

<script>
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
            if (toggleText) toggleText.textContent = 'Filter Documents';
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
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
