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

$pageTitle = 'Home';
require __DIR__ . '/includes/partials/header.php';
?>

<h1 class="page-title"><?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?></h1>
<p class="page-subtitle"><?= Sanitize::html(Settings::get('site_tagline', '')) ?></p>

<div class="card">
    <h2 style="margin-top:0; color:#1a3a6b; font-size:1.1rem;">Government Recognition</h2>
    <?php if ($recognitionDoc): ?>
        <p>This Association is a government-recognized service association under the Karnataka Civil Services
           (Recognition of Service Associations) Rules, 2015.</p>
        <a class="btn btn-outline" href="/recognition.php">View Recognition Order &rarr;</a>
    <?php else: ?>
        <p class="empty-state">Recognition details will appear here shortly.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0; color:#1a3a6b; font-size:1.1rem;">Latest News</h2>
    <?php if (!empty($latestNews)): ?>
        <table class="plain">
            <?php foreach ($latestNews as $item): ?>
            <tr>
                <td><?= Sanitize::html($item['title']) ?></td>
                <td style="white-space:nowrap; color:#9aa4b2;"><?= Sanitize::html(date('d M Y', strtotime((string) $item['published_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:14px;"><a href="/news.php">View all news &rarr;</a></p>
    <?php else: ?>
        <p class="empty-state">No news has been published yet.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0; color:#1a3a6b; font-size:1.1rem;">State Office Bearers</h2>
    <?php if (!empty($officeBearerHighlights)): ?>
        <table class="plain">
            <?php foreach ($officeBearerHighlights as $ob): ?>
            <tr>
                <td><?= Sanitize::html($ob['name']) ?></td>
                <td style="color:#5a6472;"><?= Sanitize::html($ob['association_designation']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:14px;"><a href="/office-bearers.php">View full office bearer list &rarr;</a></p>
    <?php else: ?>
        <p class="empty-state">Office bearer details will appear here shortly.</p>
    <?php endif; ?>
</div>

<div class="card" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="margin-top:0; color:#1a3a6b; font-size:1.1rem;">Get in Touch</h2>
        <p style="margin:0;">Reach the association or sign in to the member/officer portal.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn btn-outline" href="/contact.php">Contact</a>
        <a class="btn" href="/login.php">Login</a>
    </div>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
