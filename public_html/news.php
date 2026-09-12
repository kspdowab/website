<?php
/**
 * KSPDOWA — Public News Listing (Phase 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$news = Database::fetchAll(
    "SELECT id, title, content, published_at FROM news
     WHERE status = 'published' AND published_at IS NOT NULL
     ORDER BY published_at DESC LIMIT 50"
);

$pageTitle = 'News';
require __DIR__ . '/includes/partials/header.php';
?>

<h1 class="page-title">News</h1>
<p class="page-subtitle">Updates and announcements from the Association.</p>

<?php if (!empty($news)): ?>
    <?php foreach ($news as $item): ?>
        <div class="card">
            <span class="badge badge-muted"><?= Sanitize::html(date('d M Y', strtotime((string) $item['published_at']))) ?></span>
            <h2 style="border-bottom:none; padding-bottom:0; margin:10px 0 8px; font-size:1.1rem;"><?= Sanitize::html($item['title']) ?></h2>
            <p style="margin:0; color:var(--ink-700);"><?= nl2br(Sanitize::html(mb_substr((string) $item['content'], 0, 400, 'UTF-8'))) ?></p>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="card">
        <p class="empty-state">No news has been published yet.</p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
