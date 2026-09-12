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

<div class="card">
    <?php if (!empty($news)): ?>
        <?php foreach ($news as $item): ?>
            <div style="padding:14px 0; border-bottom:1px solid #e2e6ec;">
                <h2 style="font-size:1rem; margin:0 0 4px; color:#1a3a6b;"><?= Sanitize::html($item['title']) ?></h2>
                <p style="font-size:0.75rem; color:#9aa4b2; margin:0 0 8px;">
                    <?= Sanitize::html(date('d M Y', strtotime((string) $item['published_at']))) ?>
                </p>
                <p style="margin:0;"><?= nl2br(Sanitize::html(mb_substr((string) $item['content'], 0, 400, 'UTF-8'))) ?></p>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="empty-state">No news has been published yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
