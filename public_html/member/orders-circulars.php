<?php
/**
 * KSPDOWA — Member Portal: Orders & Circulars
 * ============================================================
 * Gated by logged-in authenticated member access only.
 * Private official repository.
 *
 * SPECIFICATION (Section 4):
 * Table columns MUST BE EXACTLY:
 * Sl No | Category | Subject | Order/Circular No. | Date | File Size | View
 * (No extra columns)
 *
 * Mandatory Categories:
 * 1. 16th Finance
 * 2. VB-G RAM G
 * 3. eSwathu
 * 4. eGramSwaraj
 * 5. GP Staff
 * 6. Act/Rules
 * 7. OSR
 * 8. SC/ST
 * 9. PH
 *
 * Dynamic server-side search/filter:
 * - Subject
 * - Order/Circular No.
 * - Category
 * - Date
 * - Search, Filter, Clear/reset, Pagination, Result count
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

// ─── 9 Mandatory Categories ──────────────────────────────────────────────────
$allowedCategories = [
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

// ─── Request Filters ─────────────────────────────────────────────────────────
$qCategory = trim((string)($_GET['category'] ?? ''));
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qFromDate = trim(Sanitize::string($_GET['from_date'] ?? '', 12));
$qToDate   = trim(Sanitize::string($_GET['to_date'] ?? '', 12));
$pageSize  = Sanitize::positiveInt($_GET['page_size'] ?? 10) ?: 10;
$page      = Sanitize::positiveInt($_GET['page'] ?? 1) ?: 1;

if (!in_array($pageSize, [10, 25, 50, 100], true)) {
    $pageSize = 10;
}

// ─── Build Query ─────────────────────────────────────────────────────────────
// Union of Orders and Circulars
$baseUnion = "
    SELECT 'order' AS item_type, o.id, o.title AS subject, o.order_no AS doc_no, o.order_date AS doc_date,
           COALESCE(dc.name, o.department, 'General') AS category_name,
           d.id AS document_id, d.file_path
    FROM orders o
    LEFT JOIN documents d ON d.id = o.document_id
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    WHERE o.access_level IN ('member', 'public') AND (d.status IS NULL OR d.status = 'active')

    UNION ALL

    SELECT 'circular' AS item_type, c.id, c.title AS subject, c.circular_no AS doc_no, c.circular_date AS doc_date,
           COALESCE(dc.name, c.department, 'General') AS category_name,
           d.id AS document_id, d.file_path
    FROM circulars c
    LEFT JOIN documents d ON d.id = c.document_id
    LEFT JOIN document_categories dc ON dc.id = d.category_id
    WHERE c.access_level IN ('member', 'public') AND (d.status IS NULL OR d.status = 'active')
";

$whereClauses = ["1=1"];
$params       = [];

if ($qCategory !== '' && in_array($qCategory, $allowedCategories, true)) {
    $whereClauses[] = "category_name = ?";
    $params[]       = $qCategory;
}

if ($qSearch !== '') {
    $whereClauses[] = "(subject LIKE ? OR doc_no LIKE ?)";
    $like = '%' . $qSearch . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($qFromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qFromDate)) {
    $whereClauses[] = "doc_date >= ?";
    $params[]       = $qFromDate;
}

if ($qToDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qToDate)) {
    $whereClauses[] = "doc_date <= ?";
    $params[]       = $qToDate;
}

$whereSql = implode(' AND ', $whereClauses);

// Count Total Matching Records
$countSql = "SELECT COUNT(*) AS total FROM ({$baseUnion}) AS items WHERE {$whereSql}";
$totalRow = Database::fetchOne($countSql, $params);
$totalRecords = (int)($totalRow['total'] ?? 0);

$totalPages = max(1, (int)ceil($totalRecords / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

// Fetch Page Records
$dataSql = "
    SELECT * FROM ({$baseUnion}) AS items
    WHERE {$whereSql}
    ORDER BY doc_date DESC, id DESC
    LIMIT {$pageSize} OFFSET {$offset}
";
$items = Database::fetchAll($dataSql, $params);

// Format file size helper
function format_doc_size(?string $relativePath): string {
    if (empty($relativePath)) {
        return '—';
    }
    $fullPath = UPLOADS_DIR . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!is_file($fullPath)) {
        return '—';
    }
    $bytes = (int)filesize($fullPath);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

$pageTitle  = 'Orders & Circulars';
$activeMenu = 'orders-circulars';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Orders & Circulars', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Orders &amp; Circulars</h1>
        <p class="page-heading-subtitle">Official Government Orders, Circulars, and Association Proceedings</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.82rem; padding:6px 14px;">
            <?= number_format($totalRecords) ?> Total Documents
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS & SEARCH (Formax Pay Visual Reference Style)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filters
        </div>
        <div class="filter-header-actions">
            <a href="/member/orders-circulars.php" class="filter-header-btn" title="Reset Filters">
                ↺ Reset
            </a>
        </div>
    </div>

    <form method="get" action="/member/orders-circulars.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label" for="page_size">Page Size</label>
                <select name="page_size" id="page_size" class="form-select">
                    <option value="10" <?= $pageSize === 10 ? 'selected' : '' ?>>10 Rows</option>
                    <option value="25" <?= $pageSize === 25 ? 'selected' : '' ?>>25 Rows</option>
                    <option value="50" <?= $pageSize === 50 ? 'selected' : '' ?>>50 Rows</option>
                    <option value="100" <?= $pageSize === 100 ? 'selected' : '' ?>>100 Rows</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="category">Category</label>
                <select name="category" id="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($allowedCategories as $cat): ?>
                        <option value="<?= Sanitize::attr($cat) ?>" <?= $qCategory === $cat ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="from_date">From Date</label>
                <input type="date" name="from_date" id="from_date" class="form-control" value="<?= Sanitize::attr($qFromDate) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="to_date">To Date</label>
                <input type="date" name="to_date" id="to_date" class="form-control" value="<?= Sanitize::attr($qToDate) ?>">
            </div>

            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label" for="search_input">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" id="search_input" class="form-control" placeholder="Subject, Order No, Circular No..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>

            <div class="form-group" style="display:flex; flex-direction:row; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Search
                </button>
                <a href="/member/orders-circulars.php" class="btn btn-outline" style="padding:9px 14px;" title="Clear filters">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ORDERS & CIRCULARS TABLE
     EXACT 7 COLUMNS: Sl No | Category | Subject | Order/Circular No. | Date | File Size | View
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Orders &amp; Circulars</span>
            <span class="table-card-count">(<?= number_format($totalRecords) ?> records found)</span>
        </div>
        <div style="font-size:0.82rem; color:var(--text-muted);">
            Showing <?= $totalRecords > 0 ? ($offset + 1) : 0 ?> to <?= min($offset + $pageSize, $totalRecords) ?> of <?= $totalRecords ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:70px;">Sl No</th>
                    <th style="width:160px;">Category</th>
                    <th>Subject</th>
                    <th style="width:200px;">Order/Circular No.</th>
                    <th style="width:120px;">Date</th>
                    <th style="width:110px;">File Size</th>
                    <th style="width:100px; text-align:center;">View</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
                        No orders or circulars matching your search criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $sl = $offset + 1; ?>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <!-- 1. Sl No -->
                        <td style="font-weight:600; color:var(--text-muted);"><?= $sl++ ?></td>

                        <!-- 2. Category -->
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($item['category_name'] ?? 'General') ?></span>
                        </td>

                        <!-- 3. Subject -->
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($item['subject']) ?>
                        </td>

                        <!-- 4. Order/Circular No. -->
                        <td>
                            <code style="font-size:0.86rem; color:var(--blue-700); background:var(--blue-50); padding:3px 7px; border-radius:4px;">
                                <?= Sanitize::html($item['doc_no']) ?>
                            </code>
                        </td>

                        <!-- 5. Date -->
                        <td>
                            <?= $item['doc_date'] ? date('d-m-Y', strtotime($item['doc_date'])) : '—' ?>
                        </td>

                        <!-- 6. File Size -->
                        <td>
                            <?= format_doc_size($item['file_path'] ?? null) ?>
                        </td>

                        <!-- 7. View -->
                        <td style="text-align:center;">
                            <?php if (!empty($item['document_id'])): ?>
                                <a href="/document.php?id=<?= (int)$item['document_id'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Secure View Document">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         PAGINATION & RESULT COUNT
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-wrap">
        <div class="pagination-info">
            Showing <?= $offset + 1 ?> to <?= min($offset + $pageSize, $totalRecords) ?> of <?= $totalRecords ?> records
        </div>
        <div class="pagination-links">
            <?php
            $queryBase = $_GET;
            unset($queryBase['page']);
            $pageUrl = fn(int $p) => '?' . http_build_query(array_merge($queryBase, ['page' => $p]));
            ?>
            <a href="<?= $page > 1 ? Sanitize::attr($pageUrl($page - 1)) : '#' ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">« Previous</a>

            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a href="<?= Sanitize::attr($pageUrl($p)) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>

            <a href="<?= $page < $totalPages ? Sanitize::attr($pageUrl($page + 1)) : '#' ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next »</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
