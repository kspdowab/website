<?php
/**
 * KSPDOWA — Public Home Page (Phase 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$recognitionDoc = Database::fetchOne(
    "SELECT id, title FROM documents WHERE access_level = 'public' AND status = 'active'
     AND file_path LIKE 'documents/kspdowa-recognition-order%' LIMIT 1"
);

$latestNews = Database::fetchAll(
    "SELECT id, title, slug, published_at FROM news
     WHERE status = 'published' AND published_at IS NOT NULL
     ORDER BY published_at DESC LIMIT 3"
);

$officeBearerHighlights = Database::fetchAll(
    "SELECT name, association_designation FROM office_bearers
     WHERE status = 'active' AND district_id IS NULL AND taluk_id IS NULL
     ORDER BY sort_order LIMIT 4"
);

// Real, dynamic counts for the "at a glance" stats strip -- no invented figures.
$officeBearerCount = Database::fetchOne("SELECT COUNT(*) c FROM office_bearers WHERE status = 'active'");
$publishedNewsCount = Database::fetchOne(
    "SELECT COUNT(*) c FROM news WHERE status = 'published' AND published_at IS NOT NULL"
);

$pageTitle = 'Home';
require __DIR__ . '/includes/partials/header.php';
?>

<section class="hero">
    <span class="eyebrow">Government-Recognized Service Association</span>
    <h1 class="page-title"><?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?></h1>
    <p class="page-subtitle"><?= Sanitize::html(Settings::get('site_tagline', '')) ?></p>
    <div class="hero-actions">
        <a class="btn" href="/office-bearers.php">Office Bearers</a>
        <a class="btn btn-outline" href="/recognition.php">View Recognition Order</a>
    </div>
</section>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-value"><?= (int) ($officeBearerCount['c'] ?? 0) ?></span>
        <span class="stat-label">Active Office Bearers</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= (int) ($publishedNewsCount['c'] ?? 0) ?></span>
        <span class="stat-label">News Updates Published</span>
    </div>
    <div class="stat-card">
        <span class="stat-value">Recognized</span>
        <span class="stat-label">Govt. of Karnataka, RDPR Dept.</span>
    </div>
</div>

<div class="card">
    <h2>Government Recognition</h2>
    <?php if ($recognitionDoc): ?>
        <p>This Association is a government-recognized service association under the Karnataka Civil Services
           (Recognition of Service Associations) Rules, 2015.</p>
        <a class="btn btn-outline" href="/recognition.php">View Recognition Order &rarr;</a>
    <?php else: ?>
        <p class="empty-state">Recognition details will appear here shortly.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Latest News</h2>
    <?php if (!empty($latestNews)): ?>
        <div class="table-wrap">
        <table class="plain">
            <?php foreach ($latestNews as $item): ?>
            <tr>
                <td><?= Sanitize::html($item['title']) ?></td>
                <td style="white-space:nowrap; color:var(--ink-300);"><?= Sanitize::html(date('d M Y', strtotime((string) $item['published_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p style="margin-top:16px; margin-bottom:0;"><a href="/news.php">View all news &rarr;</a></p>
    <?php else: ?>
        <p class="empty-state">No news has been published yet.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>State Office Bearers</h2>
    <?php if (!empty($officeBearerHighlights)): ?>
        <div class="table-wrap">
        <table class="plain">
            <?php foreach ($officeBearerHighlights as $ob): ?>
            <tr>
                <td><?= Sanitize::html($ob['name']) ?></td>
                <td style="color:var(--ink-500);"><?= Sanitize::html($ob['association_designation']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p style="margin-top:16px; margin-bottom:0;"><a href="/office-bearers.php">View full office bearer list &rarr;</a></p>
    <?php else: ?>
        <p class="empty-state">Office bearer details will appear here shortly.</p>
    <?php endif; ?>
</div>

<div class="card" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="border-bottom:none; padding-bottom:0; margin-bottom:4px;">Get in Touch</h2>
        <p style="margin:0; color:var(--ink-500);">Reach the association or sign in to the member/officer portal.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn btn-outline" href="/contact.php">Contact</a>
        <a class="btn" href="/login.php">Login</a>
    </div>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
